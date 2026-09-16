<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use App\Domain\Repository\CardNameIndexRepositoryInterface;
use PDO;

/**
 * Lectura y escritura de `mtg_card.name_normalized` para el backfill.
 *
 * Dos detalles que la hacen segura sobre 34.992 filas:
 *
 *  - **Escribe con un `CASE` por lote**, no con un UPDATE por fila. 34.992
 *    sentencias sueltas son 34.992 viajes de ida y vuelta; en lotes de 500 son 70.
 *  - **Todos los marcadores son posicionales.** Con
 *    `ATTR_EMULATE_PREPARES = false` MySQL no admite reutilizar un marcador
 *    NOMBRADO en dos puntos de la misma sentencia, y aquí cada oracle_id aparece
 *    dos veces (en el CASE y en el WHERE).
 */
class MySqlCardNameIndexRepository implements CardNameIndexRepositoryInterface
{
    public function __construct(
        private readonly PDO $db
    ) {
    }

    public function contarSinNormalizar(): int
    {
        return (int) $this->db
            ->query('SELECT COUNT(*) FROM mtg_card WHERE name_normalized IS NULL')
            ->fetchColumn();
    }

    public function lotePorNormalizar(string $desdeOracleId, int $limite, bool $todas): array
    {
        $limite = max(1, $limite);

        $sql = 'SELECT oracle_id AS oracleId, name
                  FROM mtg_card
                 WHERE oracle_id > ?'
             . ($todas ? '' : ' AND name_normalized IS NULL')
             . ' ORDER BY oracle_id
                 LIMIT ' . $limite;

        $stmt = $this->db->prepare($sql);
        $stmt->execute([$desdeOracleId]);

        /** @var list<array{oracleId: string, name: string}> */
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function escribirClaves(array $porOracleId): int
    {
        if ($porOracleId === []) {
            return 0;
        }

        $casos      = str_repeat('WHEN ? THEN ? ', count($porOracleId));
        $marcadores = implode(', ', array_fill(0, count($porOracleId), '?'));

        $valores = [];
        foreach ($porOracleId as $oracleId => $clave) {
            $valores[] = (string) $oracleId;
            $valores[] = $clave;
        }
        foreach (array_keys($porOracleId) as $oracleId) {
            $valores[] = (string) $oracleId;
        }

        $stmt = $this->db->prepare(
            'UPDATE mtg_card
                SET name_normalized = CASE oracle_id ' . $casos . 'END
              WHERE oracle_id IN (' . $marcadores . ')'
        );
        $stmt->execute($valores);

        return count($porOracleId);
    }

    public function contarLocalizadosSinNormalizar(): int
    {
        return (int) $this->db
            ->query('SELECT COUNT(*) FROM mtg_printing_localized WHERE name_normalized IS NULL')
            ->fetchColumn();
    }

    public function loteLocalizadoPorNormalizar(
        string $desdeUuid,
        string $desdeIdioma,
        int $limite,
        bool $todas
    ): array {
        $limite = max(1, $limite);

        // La comparación de tuplas es la que usa el índice de la PK compuesta.
        // `(a, b) > (?, ?)` no es lo mismo que `a > ? AND b > ?`: lo segundo se
        // salta el resto de idiomas de la impresión en la que se cortó el lote.
        $sql = 'SELECT printing_uuid AS printingUuid, language, name
                  FROM mtg_printing_localized
                 WHERE (printing_uuid, language) > (?, ?)'
             . ($todas ? '' : ' AND name_normalized IS NULL')
             . ' ORDER BY printing_uuid, language
                 LIMIT ' . $limite;

        $stmt = $this->db->prepare($sql);
        $stmt->execute([$desdeUuid, $desdeIdioma]);

        /** @var list<array{printingUuid: string, language: string, name: string}> */
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function escribirClavesLocalizadas(array $filas): int
    {
        if ($filas === []) {
            return 0;
        }

        // Un `CASE` sobre una PK compuesta no vale: hay que casar las DOS
        // columnas a la vez o una impresión con diez idiomas se llevaría la
        // clave del primero en los diez. Con `CONCAT` la comparación dejaría de
        // usar el índice, así que se va a `INSERT ... ON DUPLICATE KEY UPDATE`,
        // que es el mismo patrón con el que la ingesta escribe esta tabla.
        //
        // Las filas EXISTEN todas: este comando solo normaliza lo ingerido, así
        // que el `INSERT` nunca crea nada y siempre cae por la rama del
        // duplicado. `name` se repite en el VALUES porque la columna es NOT NULL
        // y el `INSERT` tiene que traer algo aunque nunca llegue a insertarse.
        $tuplas = implode(', ', array_fill(0, count($filas), '(?, ?, ?, ?)'));

        $valores = [];
        foreach ($filas as $fila) {
            $valores[] = $fila['printingUuid'];
            $valores[] = $fila['language'];
            $valores[] = $fila['name'];
            $valores[] = $fila['clave'];
        }

        $stmt = $this->db->prepare(
            'INSERT INTO mtg_printing_localized (printing_uuid, language, name, name_normalized)
             VALUES ' . $tuplas . '
             ON DUPLICATE KEY UPDATE name_normalized = VALUES(name_normalized)'
        );
        $stmt->execute($valores);

        return count($filas);
    }
}
