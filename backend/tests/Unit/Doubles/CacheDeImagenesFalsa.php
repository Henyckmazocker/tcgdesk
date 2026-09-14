<?php

declare(strict_types=1);

namespace Tests\Unit\Doubles;

use App\Domain\Repository\ImageCacheRepositoryInterface;

/**
 * `mtg_image_cache` de mentira, en memoria.
 *
 * Reproduce lo único que hace falta reproducir: **que la cola de pendientes es
 * la colección MENOS lo ya registrado**. Es lo que hace comprobable la
 * idempotencia del comando sin base de datos — relanzarlo no debe volver a
 * pedirle nada a Scryfall.
 */
class CacheDeImagenesFalsa implements ImageCacheRepositoryInterface
{
    /** @var list<string> Lo que hay en la colección de alguien, con scryfall_id */
    public array $enColeccion = [];

    /** Impresiones de la colección sin `scryfall_id`: no se pueden cachear. */
    public int $sinScryfallId = 0;

    /** @var array<string, array{size: string, localPath: string}> */
    public array $filas = [];

    /** @param list<string> $enColeccion */
    public function __construct(array $enColeccion = [], int $sinScryfallId = 0)
    {
        $this->enColeccion   = $enColeccion;
        $this->sinScryfallId = $sinScryfallId;
    }

    public function pendientes(int $limite = 0): array
    {
        $cola = array_values(array_filter(
            array_unique($this->enColeccion),
            fn (string $id): bool => !isset($this->filas[$id])
        ));

        sort($cola);

        return $limite > 0 ? array_slice($cola, 0, $limite) : $cola;
    }

    public function registrar(string $scryfallId, string $size, string $rutaRelativa): void
    {
        $this->filas[$scryfallId] = ['size' => $size, 'localPath' => $rutaRelativa];
    }

    public function buscar(string $scryfallId): ?array
    {
        return $this->filas[$scryfallId] ?? null;
    }

    public function contadores(): array
    {
        return [
            'cacheadas'     => count($this->filas),
            'pendientes'    => count($this->pendientes()),
            'sinScryfallId' => $this->sinScryfallId,
        ];
    }
}
