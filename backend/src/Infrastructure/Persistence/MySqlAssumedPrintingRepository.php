<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use App\Domain\Repository\AssumedPrintingRepositoryInterface;
use PDO;

/**
 * La impresión más barata de cada carta, resuelta **en MySQL y no en PHP**.
 *
 * Tres decisiones de esta clase, ninguna evidente:
 *
 *  - **La elección la hace una función de ventana, no un bucle.** Traerse todas
 *    las impresiones de cada carta para ordenarlas aquí parece más simple hasta
 *    que se cuentan: *Lightning Bolt* tiene decenas, y un fichero de texto plano
 *    con miles de líneas sin edición traería cientos de miles de filas a un
 *    proceso con `memory_limit` de 128M. `ROW_NUMBER()` deja que MySQL descarte
 *    y devuelva **una fila por carta**.
 *  - **El precio se une por `(printing_uuid, finish)`**, igual que en la
 *    valoración de la colección. Unir solo por printing haría que la «más
 *    barata» de una línea foil se eligiera con precios de no-foil, que es el bug
 *    que ya costó 500 € de más en una colección de prueba.
 *  - **Se prefiere una impresión que exista en ese acabado.** Una carta puede no
 *    haberse impreso nunca en foil; asumirla igual sería inventarse un cartón.
 *    Es una preferencia y no un filtro: si ninguna lo ofrece se elige de todas
 *    formas, porque la alternativa —no resolver— manda la línea a conflicto por
 *    un detalle que el usuario ya decidió al escribir su fichero.
 *
 * El desempate final es `p.uuid`, para que dos previsualizaciones del mismo
 * fichero elijan siempre la misma impresión: un empate resuelto al azar haría
 * que reimportar creara una fila nueva en vez de sumar.
 */
class MySqlAssumedPrintingRepository implements AssumedPrintingRepositoryInterface
{
    /** Mismo criterio que el resolvedor: 500 claves por sentencia. */
    private const TAMANO_LOTE = 500;

    /**
     * Acabado → columna de `mtg_printing` que dice si la impresión existe así.
     *
     * Es una lista blanca y por eso puede interpolarse en el SQL: el acabado
     * llega del `ENUM` del dominio (`Finish`), pero un mapa explícito es lo que
     * garantiza que nada que no esté aquí toque la consulta.
     */
    private const COLUMNA_ACABADO = [
        'normal' => 'p.has_nonfoil',
        'foil'   => 'p.has_foil',
        'etched' => 'p.has_etched',
    ];

    public function __construct(
        private readonly PDO $db
    ) {
    }

    public function masBaratasPorCarta(array $peticiones): array
    {
        // Se agrupa por acabado porque el acabado entra en el JOIN de precios y
        // en el orden: son tres consultas como mucho (normal, foil, etched),
        // nunca una por carta.
        $porAcabado = [];

        foreach ($peticiones as $peticion) {
            $oracleId = (string) $peticion['oracleId'];
            $acabado  = (string) $peticion['finish'];

            if ($oracleId === '' || !isset(self::COLUMNA_ACABADO[$acabado])) {
                continue;
            }

            $porAcabado[$acabado][$oracleId] = true;
        }

        $elegidas = [];

        foreach ($porAcabado as $acabado => $oracleIds) {
            foreach (array_chunk(array_keys($oracleIds), self::TAMANO_LOTE) as $lote) {
                foreach ($this->masBaratasDelLote($acabado, $lote) as $fila) {
                    $elegidas[$fila['oracleId'] . '|' . $acabado] = [
                        'printingUuid'    => (string) $fila['printingUuid'],
                        'setCode'         => (string) $fila['setCode'],
                        'collectorNumber' => (string) $fila['collectorNumber'],
                        'priceEur'        => $fila['priceEur'] !== null ? (float) $fila['priceEur'] : null,
                        'printingCount'   => (int) $fila['printingCount'],
                    ];
                }
            }
        }

        return $elegidas;
    }

    /**
     * @param  list<string> $oracleIds
     * @return list<array<string, mixed>>
     */
    private function masBaratasDelLote(string $acabado, array $oracleIds): array
    {
        $soporta    = self::COLUMNA_ACABADO[$acabado];
        $marcadores = implode(', ', array_fill(0, count($oracleIds), '?'));

        // Todo el SQL va con marcadores posicionales: con
        // ATTR_EMULATE_PREPARES = false, MySQL no admite reutilizar un marcador
        // nombrado en dos puntos de la misma sentencia, y aquí el acabado
        // aparecería dos veces.
        $stmt = $this->db->prepare(
            'WITH candidatas AS (
                 SELECT p.oracle_id,
                        p.uuid,
                        p.set_code,
                        p.collector_number,
                        pc.price_eur,
                        COUNT(*)     OVER (PARTITION BY p.oracle_id) AS impresiones,
                        ROW_NUMBER() OVER (
                            PARTITION BY p.oracle_id
                            ORDER BY ' . $soporta . ' = 0 ASC,
                                     pc.price_eur IS NULL ASC,
                                     pc.price_eur ASC,
                                     s.release_date IS NULL ASC,
                                     s.release_date ASC,
                                     p.uuid ASC
                        ) AS pos
                   FROM mtg_printing p
                   JOIN mtg_set s ON s.code = p.set_code
              LEFT JOIN mtg_price_current pc
                     ON pc.printing_uuid = p.uuid
                    AND pc.finish        = ?
                  WHERE p.oracle_id IN (' . $marcadores . ')
             )
             SELECT oracle_id        AS oracleId,
                    uuid             AS printingUuid,
                    set_code         AS setCode,
                    collector_number AS collectorNumber,
                    price_eur        AS priceEur,
                    impresiones      AS printingCount
               FROM candidatas
              WHERE pos = 1'
        );

        $stmt->execute(array_merge([$acabado], $oracleIds));

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
