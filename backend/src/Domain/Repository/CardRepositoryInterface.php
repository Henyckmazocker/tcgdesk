<?php

declare(strict_types=1);

namespace App\Domain\Repository;

use App\Domain\Catalog\SearchCriteria;

/**
 * Lectura del catálogo.
 *
 * Separada de CatalogRepositoryInterface a propósito: aquella la usa la ingesta
 * por CLI y escribe cientos de miles de filas; esta la usan las peticiones de
 * usuario y solo lee. Que un controller no pueda ni nombrar un método de
 * escritura del catálogo es la garantía de que el mirror es de solo lectura desde
 * el punto de vista de la app.
 */
interface CardRepositoryInterface
{
    /**
     * Busca printings por nombre en cualquier idioma, con filtros.
     *
     * `nextCursor` es null cuando no hay más páginas, y es lo único que el
     * cliente necesita guardar para seguir el scroll: nunca se pagina por
     * offset visible.
     *
     * @return array{items: list<array<string, mixed>>, nextCursor: string|null}
     */
    public function search(SearchCriteria $criterios): array;

    /**
     * Un printing por su uuid, con legalidades y nombres localizados.
     *
     * @return array<string, mixed>|null null si no existe
     */
    public function findByUuid(string $uuid): ?array;

    /** @return list<array<string, mixed>> Todas las ediciones, para el filtro */
    public function allSets(): array;
}
