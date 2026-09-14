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
}
