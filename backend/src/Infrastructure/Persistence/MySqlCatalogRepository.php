<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use App\Domain\Repository\CatalogRepositoryInterface;
use PDO;

/**
 * Escritura del catálogo con `INSERT ... ON DUPLICATE KEY UPDATE` multi-fila.
 *
 * El upsert por lotes es lo que hace viable la ingesta: 105.788 printings y
 * ~600.000 nombres localizados con una sentencia por fila son otros tantos viajes
 * a MySQL. Aquí cada lote es UNA sentencia con N tuplas de VALUES.
 *
 * El `ON DUPLICATE KEY UPDATE` no es un detalle de rendimiento sino el mecanismo
 * de idempotencia: una carta reimpresa en 40 sets llega 40 veces a upsertCards()
 * y tiene que actualizarse, no reventar por clave duplicada.
 */
class MySqlCatalogRepository implements CatalogRepositoryInterface
{
    /**
     * Filas por sentencia.
     *
     * El límite real no es el número de filas sino `max_allowed_packet` y los
     * marcadores de posición del prepared statement. Con 1.000 filas × 16
     * columnas son 16.000 marcadores, holgado frente al máximo de 65.535 de
     * MySQL, y el lote más ancho de esta clase (mtg_printing) es el que fija el
     * techo.
     */
    private const TAMANO_LOTE = 1000;

    public function __construct(
        private readonly PDO $db
    ) {
    }

    public function upsertSet(array $set): void
    {
        $this->upsert('mtg_set', [$set], ['code']);
    }

    public function upsertCards(array $filas): int
    {
        return $this->upsert('mtg_card', $filas, ['oracle_id']);
    }

    public function upsertPrintings(array $filas): int
    {
        return $this->upsert('mtg_printing', $filas, ['uuid']);
    }

    public function upsertLocalized(array $filas): int
    {
        return $this->upsert('mtg_printing_localized', $filas, ['printing_uuid', 'language']);
    }

    public function upsertLegalities(array $filas): int
    {
        return $this->upsert('mtg_legality', $filas, ['oracle_id', 'format']);
    }

    public function escribirIdsLocalizados(array $filas): int
    {
        if ($filas === []) {
            return 0;
        }

        $enviadas = 0;

        foreach (array_chunk($filas, self::TAMANO_LOTE) as $lote) {
            // Un `CASE columna WHEN ?` no vale sobre una PK compuesta: hay que
            // casar las DOS columnas a la vez o una impresión con diez idiomas
            // se llevaría el id del primero en los diez. Es el mismo motivo por
            // el que `escribirClavesLocalizadas()` se fue a un ODKU; aquí no
            // puede irse ahí, porque insertar filas que no existen es justo lo
            // que este backfill no puede hacer (ver la interfaz), así que el
            // `CASE` va **buscado**, con las dos columnas en cada `WHEN`.
            //
            // Todos los marcadores son POSICIONALES: cada par (uuid, idioma)
            // aparece dos veces —en el CASE y en el WHERE— y con
            // ATTR_EMULATE_PREPARES = false reutilizar un marcador NOMBRADO da
            // SQLSTATE[HY093].
            $casos  = str_repeat('WHEN printing_uuid = ? AND language = ? THEN ? ', count($lote));
            $tuplas = implode(', ', array_fill(0, count($lote), '(?, ?)'));

            $valores = [];
            foreach ($lote as $fila) {
                $valores[] = $fila['printingUuid'];
                $valores[] = $fila['language'];
                $valores[] = $fila['scryfallId'];
            }
            foreach ($lote as $fila) {
                $valores[] = $fila['printingUuid'];
                $valores[] = $fila['language'];
            }

            $stmt = $this->db->prepare(
                'UPDATE mtg_printing_localized
                    SET scryfall_id = CASE ' . $casos . 'ELSE scryfall_id END
                  WHERE (printing_uuid, language) IN (' . $tuplas . ')'
            );
            $stmt->execute($valores);

            $enviadas += count($lote);
        }

        return $enviadas;
    }

    public function contarLocalizadosSinId(array $claves): int
    {
        if ($claves === []) {
            return 0;
        }

        $pendientes = 0;

        foreach (array_chunk($claves, self::TAMANO_LOTE) as $lote) {
            $tuplas = implode(', ', array_fill(0, count($lote), '(?, ?)'));

            $valores = [];
            foreach ($lote as $clave) {
                $valores[] = $clave['printingUuid'];
                $valores[] = $clave['language'];
            }

            // La comparación por tuplas es la que usa el índice de la PK. Las
            // claves que no existan en la tabla simplemente no cuentan: una
            // traducción que MTGJSON publica y que nuestro catálogo no tiene
            // ingerida no es una fila sin id, es una fila que no está.
            $stmt = $this->db->prepare(
                'SELECT COUNT(*)
                   FROM mtg_printing_localized
                  WHERE (printing_uuid, language) IN (' . $tuplas . ')
                    AND scryfall_id IS NULL'
            );
            $stmt->execute($valores);

            $pendientes += (int) $stmt->fetchColumn();
        }

        return $pendientes;
    }

    public function refrescarFormatos(): int
    {
        // `REPLACE INTO` y no `TRUNCATE` + `INSERT`: el `TRUNCATE` deja la tabla
        // vacía durante un instante y cualquier ficha de mazo que se cargue
        // justo ahí perdería su aviso de legalidad. Con `REPLACE` la tabla nunca
        // está vacía; a cambio, un formato que MTGJSON deje de publicar se queda
        // como fila huérfana, que es el error inocuo de los dos.
        $this->db->exec(
            'REPLACE INTO mtg_format (format) SELECT DISTINCT format FROM mtg_legality'
        );

        return (int) $this->db->query('SELECT COUNT(*) FROM mtg_format')->fetchColumn();
    }

    public function contadores(): array
    {
        $tablas = [
            'mtg_set',
            'mtg_card',
            'mtg_printing',
            'mtg_printing_localized',
            'mtg_legality',
        ];

        $out = [];

        foreach ($tablas as $tabla) {
            $out[$tabla] = (int) $this->db->query("SELECT COUNT(*) FROM {$tabla}")->fetchColumn();
        }

        return $out;
    }

    /**
     * Upsert multi-fila, troceado en lotes.
     *
     * @param  list<array<string, mixed>> $filas
     * @param  list<string>               $clave Columnas de la clave; se excluyen del UPDATE
     * @return int Filas enviadas
     */
    private function upsert(string $tabla, array $filas, array $clave): int
    {
        if ($filas === []) {
            return 0;
        }

        $columnas = array_keys($filas[0]);
        $enviadas = 0;

        foreach (array_chunk($filas, self::TAMANO_LOTE) as $lote) {
            $this->ejecutarLote($tabla, $columnas, $clave, $lote);
            $enviadas += count($lote);
        }

        return $enviadas;
    }

    /**
     * @param list<string>               $columnas
     * @param list<string>               $clave
     * @param list<array<string, mixed>> $lote
     */
    private function ejecutarLote(string $tabla, array $columnas, array $clave, array $lote): void
    {
        $tupla  = '(' . implode(', ', array_fill(0, count($columnas), '?')) . ')';
        $tuplas = implode(', ', array_fill(0, count($lote), $tupla));

        // Las columnas de la clave no se actualizan: son por lo que casó la fila.
        // El resto se sobreescribe con lo que traiga MTGJSON, que es la fuente.
        $asignaciones = [];
        foreach ($columnas as $columna) {
            if (!in_array($columna, $clave, true)) {
                $asignaciones[] = "`{$columna}` = VALUES(`{$columna}`)";
            }
        }

        $sql = "INSERT INTO `{$tabla}` (`" . implode('`, `', $columnas) . "`) VALUES {$tuplas}";

        // Una tabla cuyas columnas son TODAS clave (mtg_legality lo era antes de
        // tener `status`) no tiene nada que actualizar; el upsert se degrada a
        // un no-op explícito sobre la primera columna en vez de a SQL inválido.
        $sql .= $asignaciones !== []
            ? ' ON DUPLICATE KEY UPDATE ' . implode(', ', $asignaciones)
            : " ON DUPLICATE KEY UPDATE `{$columnas[0]}` = `{$columnas[0]}`";

        $valores = [];
        foreach ($lote as $fila) {
            foreach ($columnas as $columna) {
                $valores[] = $fila[$columna] ?? null;
            }
        }

        $this->db->prepare($sql)->execute($valores);
    }
}
