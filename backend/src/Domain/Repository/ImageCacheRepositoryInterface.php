<?php

declare(strict_types=1);

namespace App\Domain\Repository;

/**
 * El índice de imágenes ya bajadas a disco (`mtg_image_cache`).
 *
 * Dos ideas mandan aquí:
 *
 *  - **La cola de pendientes no es una columna, es una consulta.** El plan dice
 *    que al insertar en la colección la carta «se marca como pendiente»; marcarla
 *    de verdad obligaría a escribir en una segunda tabla dentro de la petición de
 *    `collection_add` y a mantenerla sincronizada al borrar. Pendiente es,
 *    exactamente, *estar en la colección de alguien y no estar en esta tabla*, y
 *    eso ya lo sabe la base de datos. Así la cola no puede desincronizarse ni
 *    quedarse con fantasmas.
 *  - **La tabla es reconstruible.** Es un índice de ficheros en disco; borrarla
 *    solo obliga a volver a descargar. Por eso no tiene FK al catálogo.
 */
interface ImageCacheRepositoryInterface
{
    /**
     * Los `scryfall_id` de la colección que todavía no tienen copia local.
     *
     * Se ignoran los printings con `scryfall_id` NULL: no todos lo traen, y una
     * carta sin id no tiene URL de imagen que componer. No es un error, es un
     * dato que falta — el comando lo cuenta aparte y sigue.
     *
     * @param  int $limite 0 = sin límite
     * @return list<string>
     */
    public function pendientes(int $limite = 0): array;

    /**
     * Anota que `$scryfallId` ya está en disco, en `$rutaRelativa` bajo `storage/`.
     *
     * Es un upsert: relanzar el comando sobre una imagen ya bajada debe poder
     * reescribir la fila sin fallar (p. ej. si se cambia de tamaño).
     */
    public function registrar(string $scryfallId, string $size, string $rutaRelativa): void;

    /**
     * La copia local de una carta, o null si no se ha bajado.
     *
     * **La ruta sale de aquí, nunca de la URL.** Es lo que impide que un `../`
     * en la petición componga una ruta fuera de `storage/`.
     *
     * @return array{size: string, localPath: string}|null
     */
    public function buscar(string $scryfallId): ?array;

    /**
     * Cuántas filas hay y cuánto queda por bajar.
     *
     * @return array{cacheadas: int, pendientes: int, sinScryfallId: int}
     */
    public function contadores(): array;
}
