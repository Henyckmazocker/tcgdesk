<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use App\Domain\Import\NameNormalizer;
use App\Domain\Repository\CardResolutionRepositoryInterface;
use App\Infrastructure\Persistence\Search\BooleanExpressionBuilder;
use PDO;

/**
 * Las consultas del resolvedor de importación contra el catálogo local.
 *
 * Cuatro decisiones de esta clase, ninguna evidente:
 *
 *  - **El paso 4 reutiliza `BooleanExpressionBuilder`**, la misma tokenización
 *    que la búsqueda del catálogo. Escribir aquí un segundo tokenizador sería
 *    volver a tropezar con lo que aquel ya resolvió: las stopwords de InnoDB
 *    ('the' mide 3 caracteres y no está indexada) y los tokens más cortos que
 *    `innodb_ft_min_token_size`, que exigidos con '+' devuelven CERO filas sin
 *    ningún error.
 *  - **El `MATCH` se resuelve una sola vez, en un CTE**, y lo demás se une por
 *    `oracle_id`. Es la trampa medida del repositorio de búsqueda: con un
 *    `EXISTS (SELECT … MATCH …)` correlacionado MySQL lo evalúa una vez por fila
 *    y 'bosque' pasó de 14 ms a 125 segundos.
 *  - **El paso 3b escapa el `_` antes del LIKE.** La clave normalizada conserva
 *    los blancos de las Un-sets, y `_` es un comodín de LIKE: sin escapar,
 *    `'_____ goblin // %'` casaría con cualquier cosa de cinco caracteres y el
 *    fallo silencioso que M0 midió volvería por la puerta de atrás.
 *  - **Las impresiones traen `nameNormalized`, y no es decorativo.** Es lo que
 *    permite al paso 2 comprobar que el nombre de la línea y el `(set, número)`
 *    hablan de la misma carta sin una segunda consulta: `ICE 96` es *Shyft*, no
 *    *Lim-Dûl's Vault*, y sin ese contraste la línea entraba en la colección
 *    convertida en otra carta.
 *  - **Un candidato de carta solo trae `printingUuid` si la carta tiene UNA
 *    impresión.** `MIN(p.uuid)` no es «la impresión buena»: es la única que hay,
 *    y por eso solo se copia cuando el COUNT dice 1. Elegir edición entre varias
 *    no es cosa del resolvedor.
 */
class MySqlCardResolutionRepository implements CardResolutionRepositoryInterface
{
    /**
     * Claves por sentencia.
     *
     * Un CSV de ManaBox de 20.000 líneas no cabe en un solo `IN (...)`: el techo
     * real son los 65.535 marcadores de un prepared statement y el
     * `max_allowed_packet`. Con 500 por lote son 40 sentencias, tres órdenes de
     * magnitud menos que una consulta por fila.
     */
    private const TAMANO_LOTE = 500;

    /** Carácter de escape del LIKE del paso 3b. No aparece en ninguna clave. */
    private const ESCAPE_LIKE = '!';

    /**
     * Columnas de los pasos por nombre: identifican una CARTA y cuentan sus
     * impresiones, que es lo que dice si la edición queda determinada o no.
     */
    private const COLUMNAS_CARTA = '
        c.oracle_id       AS oracleId,
        c.name,
        c.name_normalized AS clave,
        COUNT(p.uuid)     AS impresiones,
        MIN(p.uuid)       AS uuidUnico,
        MIN(p.set_code)   AS setUnico
    ';

    public function __construct(
        private readonly PDO $db,
        private readonly BooleanExpressionBuilder $expresion
    ) {
    }

    public function impresionesPorScryfallId(array $scryfallIds): array
    {
        $encontradas = [];

        foreach ($this->lotes($scryfallIds) as $lote) {
            $marcadores = implode(', ', array_fill(0, count($lote), '?'));

            $stmt = $this->db->prepare(
                'SELECT p.scryfall_id AS scryfallId, p.uuid, p.oracle_id AS oracleId,
                        c.name, c.name_normalized AS nameNormalized,
                        p.set_code AS setCode, p.collector_number AS collectorNumber
                   FROM mtg_printing p
                   JOIN mtg_card c ON c.oracle_id = p.oracle_id
                  WHERE p.scryfall_id IN (' . $marcadores . ')'
            );
            $stmt->execute($lote);

            // scryfall_id es UNIQUE, así que cada clave trae como mucho una fila.
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $fila) {
                $encontradas[mb_strtolower((string) $fila['scryfallId'])] = $this->aImpresion($fila);
            }
        }

        return $encontradas;
    }

    public function impresionesPorSetYNumero(array $pares): array
    {
        $porClave = [];

        foreach (array_chunk(array_values($pares), self::TAMANO_LOTE) as $lote) {
            // Constructor de filas: `(set_code, collector_number) IN ((?,?), …)`.
            // MySQL 8 lo resuelve por idx_set_number en vez de degradar a scan.
            $tuplas  = implode(', ', array_fill(0, count($lote), '(?, ?)'));
            $valores = [];

            foreach ($lote as $par) {
                $valores[] = strtoupper((string) $par['setCode']);
                $valores[] = (string) $par['collectorNumber'];
            }

            $stmt = $this->db->prepare(
                'SELECT p.uuid, p.oracle_id AS oracleId, c.name,
                        c.name_normalized AS nameNormalized,
                        p.set_code AS setCode, p.collector_number AS collectorNumber
                   FROM mtg_printing p
                   JOIN mtg_card c ON c.oracle_id = p.oracle_id
                  WHERE (p.set_code, p.collector_number) IN (' . $tuplas . ')'
            );
            $stmt->execute($valores);

            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $fila) {
                $clave = strtoupper((string) $fila['setCode']) . '|' . $fila['collectorNumber'];

                // Medido: (set_code, collector_number) no tiene un duplicado en
                // las 110.384 impresiones. Si algún día lo tuviera, el par deja de
                // ser exacto y no puede resolver: se marca y se descarta abajo.
                $porClave[$clave] = array_key_exists($clave, $porClave)
                    ? null
                    : $this->aImpresion($fila);
            }
        }

        return array_filter($porClave, static fn (?array $i): bool => $i !== null);
    }

    public function cartasPorNombreNormalizado(array $claves): array
    {
        $porClave = [];

        foreach ($this->lotes($claves) as $lote) {
            $marcadores = implode(', ', array_fill(0, count($lote), '?'));

            $stmt = $this->db->prepare(
                'SELECT ' . self::COLUMNAS_CARTA . '
                   FROM mtg_card c
                   LEFT JOIN mtg_printing p ON p.oracle_id = c.oracle_id
                  WHERE c.name_normalized IN (' . $marcadores . ')
                  GROUP BY c.oracle_id, c.name, c.name_normalized'
            );
            $stmt->execute($lote);

            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $fila) {
                $porClave[mb_strtolower((string) $fila['clave'])][] = $this->aCarta($fila);
            }
        }

        return $this->indexarPorClavePedida($claves, $porClave);
    }

    public function cartasPorCaraFrontal(array $caras): array
    {
        $porCara = [];

        foreach ($this->lotes($caras) as $lote) {
            $condiciones = implode(
                ' OR ',
                array_fill(0, count($lote), "c.name_normalized LIKE ? ESCAPE '" . self::ESCAPE_LIKE . "'")
            );

            $patrones = array_map(
                fn (string $cara): string => $this->escaparLike($cara) . NameNormalizer::SEPARADOR . '%',
                $lote
            );

            $stmt = $this->db->prepare(
                'SELECT ' . self::COLUMNAS_CARTA . '
                   FROM mtg_card c
                   LEFT JOIN mtg_printing p ON p.oracle_id = c.oracle_id
                  WHERE ' . $condiciones . '
                  GROUP BY c.oracle_id, c.name, c.name_normalized'
            );
            $stmt->execute($patrones);

            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $fila) {
                $clave = (string) $fila['clave'];
                $corte = mb_strpos($clave, NameNormalizer::SEPARADOR);

                if ($corte === false) {
                    continue;
                }

                $porCara[mb_strtolower(mb_substr($clave, 0, $corte))][] = $this->aCarta($fila);
            }
        }

        return $this->indexarPorClavePedida($caras, $porCara);
    }

    public function cartasPorTexto(string $texto, int $limite = 25): array
    {
        $expresion = $this->expresion->construir($texto);

        if ($expresion === '') {
            return [];
        }

        $limite = max(1, $limite);

        // El MATCH vive SOLO aquí dentro y se resuelve una vez; el JOIN con las
        // impresiones cuelga del resultado, no al revés.
        $stmt = $this->db->prepare(
            'WITH coincidencias AS (
                 SELECT c2.oracle_id
                   FROM mtg_card c2
                  WHERE MATCH(c2.name) AGAINST (:expr IN BOOLEAN MODE)
                  LIMIT ' . $limite . '
             )
             SELECT ' . self::COLUMNAS_CARTA . '
               FROM coincidencias m
               JOIN mtg_card c ON c.oracle_id = m.oracle_id
               LEFT JOIN mtg_printing p ON p.oracle_id = c.oracle_id
              GROUP BY c.oracle_id, c.name, c.name_normalized'
        );
        $stmt->execute([':expr' => $expresion]);

        return array_map([$this, 'aCarta'], $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * Devuelve el resultado indexado por la clave TAL COMO se pidió.
     *
     * La comparación de MySQL es `utf8mb4_unicode_ci`, así que lo que vuelve puede
     * no ser byte a byte lo que se preguntó. Quien llama busca por su propia
     * clave, y aquí se le devuelve con ella.
     *
     * @param  list<string>                              $pedidas
     * @param  array<string, list<array<string, mixed>>> $porClaveMinuscula
     * @return array<string, list<array<string, mixed>>>
     */
    private function indexarPorClavePedida(array $pedidas, array $porClaveMinuscula): array
    {
        $salida = [];

        foreach ($pedidas as $clave) {
            $salida[$clave] = $porClaveMinuscula[mb_strtolower($clave)] ?? [];
        }

        return $salida;
    }

    /**
     * @param  list<string> $claves
     * @return list<list<string>>
     */
    private function lotes(array $claves): array
    {
        $unicas = array_values(array_unique(array_filter($claves, static fn (string $c): bool => $c !== '')));

        return $unicas === [] ? [] : array_chunk($unicas, self::TAMANO_LOTE);
    }

    /** `_` y `%` son comodines de LIKE, y las claves de las Un-sets llevan `_`. */
    private function escaparLike(string $valor): string
    {
        $e = self::ESCAPE_LIKE;

        return str_replace([$e, '%', '_'], [$e . $e, $e . '%', $e . '_'], $valor);
    }

    /**
     * @param  array<string, mixed> $fila
     * @return array<string, mixed>
     */
    private function aImpresion(array $fila): array
    {
        return [
            'printingUuid'    => (string) $fila['uuid'],
            'oracleId'        => (string) $fila['oracleId'],
            'name'            => (string) $fila['name'],
            // La MISMA columna por la que busca el paso 3. El paso 2 contrasta
            // contra ella el nombre que trae la línea; comparar contra `name`
            // recalculado dejaría a los dos pasos midiendo con distinta vara.
            'nameNormalized'  => $fila['nameNormalized'] !== null ? (string) $fila['nameNormalized'] : '',
            'setCode'         => (string) $fila['setCode'],
            'collectorNumber' => (string) $fila['collectorNumber'],
            'impresiones'     => 1,
        ];
    }

    /**
     * @param  array<string, mixed> $fila
     * @return array<string, mixed>
     */
    private function aCarta(array $fila): array
    {
        $impresiones = (int) $fila['impresiones'];

        return [
            'oracleId'     => (string) $fila['oracleId'],
            'name'         => (string) $fila['name'],
            'impresiones'  => $impresiones,
            // Solo cuando no hay nada que elegir.
            'printingUuid' => $impresiones === 1 && $fila['uuidUnico'] !== null ? (string) $fila['uuidUnico'] : null,
            'setCode'      => $impresiones === 1 && $fila['setUnico'] !== null ? (string) $fila['setUnico'] : null,
        ];
    }
}
