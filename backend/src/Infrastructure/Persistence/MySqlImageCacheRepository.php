<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use App\Domain\Repository\ImageCacheRepositoryInterface;
use PDO;

/**
 * `mtg_image_cache` en MySQL.
 *
 * La consulta que importa es la de la cola: un `LEFT JOIN ... IS NULL` entre lo
 * que hay en las colecciones y lo que ya está en disco. No hay columna
 * «pendiente» que mantener, así que la cola no puede mentir.
 *
 * La cola es de **todos los usuarios**, no de uno: la imagen de una carta es la
 * misma para todo el mundo y la tabla está indexada solo por `scryfall_id`.
 */
class MySqlImageCacheRepository implements ImageCacheRepositoryInterface
{
    public function __construct(
        private readonly PDO $db
    ) {
    }

    public function pendientes(int $limite = 0): array
    {
        // DISTINCT porque la misma carta puede estar en varias líneas (foil y
        // normal, dos idiomas, dos usuarios) y la imagen es una sola.
        $sql = '
            SELECT DISTINCT p.scryfall_id
              FROM mtg_collection_item ci
              JOIN mtg_printing p ON p.uuid = ci.printing_uuid
         LEFT JOIN mtg_image_cache ic ON ic.scryfall_id = p.scryfall_id
             WHERE p.scryfall_id IS NOT NULL
               AND ic.scryfall_id IS NULL
          ORDER BY p.scryfall_id
        ';

        if ($limite > 0) {
            // Entero ya casteado: no hay marcador porque MySQL no admite
            // parámetros en LIMIT con ATTR_EMULATE_PREPARES = false.
            $sql .= ' LIMIT ' . $limite;
        }

        return array_map('strval', $this->db->query($sql)->fetchAll(PDO::FETCH_COLUMN));
    }

    public function registrar(string $scryfallId, string $size, string $rutaRelativa): void
    {
        $stmt = $this->db->prepare(
            'INSERT INTO mtg_image_cache (scryfall_id, size, local_path)
             VALUES (:scryfall_id, :size, :local_path)
             ON DUPLICATE KEY UPDATE
                    size          = VALUES(size),
                    local_path    = VALUES(local_path),
                    downloaded_at = CURRENT_TIMESTAMP'
        );

        $stmt->execute([
            'scryfall_id' => $scryfallId,
            'size'        => $size,
            'local_path'  => $rutaRelativa,
        ]);
    }

    public function buscar(string $scryfallId): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT size, local_path FROM mtg_image_cache WHERE scryfall_id = :id'
        );

        $stmt->execute(['id' => $scryfallId]);

        $fila = $stmt->fetch();

        if ($fila === false) {
            return null;
        }

        return [
            'size'      => (string) $fila['size'],
            'localPath' => (string) $fila['local_path'],
        ];
    }

    public function contadores(): array
    {
        $cacheadas = (int) $this->db->query('SELECT COUNT(*) FROM mtg_image_cache')->fetchColumn();

        $pendientes = (int) $this->db->query('
            SELECT COUNT(DISTINCT p.scryfall_id)
              FROM mtg_collection_item ci
              JOIN mtg_printing p ON p.uuid = ci.printing_uuid
         LEFT JOIN mtg_image_cache ic ON ic.scryfall_id = p.scryfall_id
             WHERE p.scryfall_id IS NOT NULL
               AND ic.scryfall_id IS NULL
        ')->fetchColumn();

        // Sin `scryfall_id` no hay URL que componer. Se cuenta para que el
        // comando pueda decirlo en voz alta en vez de dejar un hueco mudo.
        $sinId = (int) $this->db->query('
            SELECT COUNT(DISTINCT ci.printing_uuid)
              FROM mtg_collection_item ci
              JOIN mtg_printing p ON p.uuid = ci.printing_uuid
             WHERE p.scryfall_id IS NULL
        ')->fetchColumn();

        return [
            'cacheadas'     => $cacheadas,
            'pendientes'    => $pendientes,
            'sinScryfallId' => $sinId,
        ];
    }
}
