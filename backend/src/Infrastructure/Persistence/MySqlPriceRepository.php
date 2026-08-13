<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use App\Domain\Repository\PriceRepositoryInterface;
use PDO;

/**
 * Precios en MySQL: histórico que solo crece y una tabla de vigentes que se
 * reescribe cada día.
 */
class MySqlPriceRepository implements PriceRepositoryInterface
{
    private const TAMANO_LOTE = 1000;

    public function __construct(
        private readonly PDO $db
    ) {
    }

    /**
     * `INSERT IGNORE` y no `ON DUPLICATE KEY UPDATE`: si ya hay precio para ese
     * printing, acabado y día, se respeta el que se guardó. Reescribirlo con el
     * de una reejecución convertiría el histórico en algo que cambia bajo los
     * pies, y todo el valor de esta tabla es que no cambia.
     */
    public function insertarHistorico(array $filas): int
    {
        if ($filas === []) {
            return 0;
        }

        $insertadas = 0;

        foreach (array_chunk($filas, self::TAMANO_LOTE) as $lote) {
            $tuplas = implode(', ', array_fill(0, count($lote), '(?, ?, ?, ?)'));

            $valores = [];
            foreach ($lote as $fila) {
                $valores[] = $fila['printing_uuid'];
                $valores[] = $fila['finish'];
                $valores[] = $fila['price_date'];
                $valores[] = $fila['price_eur'];
            }

            $stmt = $this->db->prepare(
                "INSERT IGNORE INTO mtg_price_daily
                     (printing_uuid, finish, price_date, price_eur)
                 VALUES {$tuplas}"
            );
            $stmt->execute($valores);

            $insertadas += $stmt->rowCount();
        }

        return $insertadas;
    }

    /**
     * `REPLACE` reescribe la fila entera, que es justo lo que se quiere: el
     * precio de hoy sustituye al de ayer sin dejar rastro. El histórico ya está
     * en la otra tabla.
     */
    public function reemplazarVigentes(array $filas): int
    {
        if ($filas === []) {
            return 0;
        }

        $enviadas = 0;

        foreach (array_chunk($filas, self::TAMANO_LOTE) as $lote) {
            $tuplas = implode(', ', array_fill(0, count($lote), '(?, ?, ?, ?)'));

            $valores = [];
            foreach ($lote as $fila) {
                $valores[] = $fila['printing_uuid'];
                $valores[] = $fila['finish'];
                $valores[] = $fila['price_eur'];
                $valores[] = $fila['price_date'];
            }

            $this->db->prepare(
                "REPLACE INTO mtg_price_current
                     (printing_uuid, finish, price_eur, updated_at)
                 VALUES {$tuplas}"
            )->execute($valores);

            $enviadas += count($lote);
        }

        return $enviadas;
    }

    public function uuidsConocidos(): array
    {
        return array_fill_keys(
            $this->db->query('SELECT uuid FROM mtg_printing')->fetchAll(PDO::FETCH_COLUMN),
            true
        );
    }

    public function contadores(): array
    {
        return [
            'mtg_price_daily'   => (int) $this->db->query('SELECT COUNT(*) FROM mtg_price_daily')->fetchColumn(),
            'mtg_price_current' => (int) $this->db->query('SELECT COUNT(*) FROM mtg_price_current')->fetchColumn(),
        ];
    }
}
