<?php

declare(strict_types=1);

namespace App\Application\UseCase;

use App\Domain\Catalog\SearchCriteria;
use App\Domain\Repository\CardRepositoryInterface;

/**
 * Buscar cartas en el catálogo local.
 *
 * Existe como use case y no como método de un controller porque lo van a llamar
 * dos entradas distintas: los endpoints `GET /api/catalog/*` del M5 y el
 * resolvedor de nombres del plan de importación de colecciones, que necesita
 * exactamente esta búsqueda para el texto plano. Duplicar la consulta en dos
 * sitios es lo que garantiza que se desincronicen.
 */
class SearchCards
{
    public function __construct(
        private readonly CardRepositoryInterface $cartas
    ) {
    }

    /**
     * @param  array<string, mixed> $peticion Filtros tal como llegan del cliente
     * @return array{items: list<array<string, mixed>>, nextCursor: string|null, count: int}
     */
    public function __invoke(array $peticion): array
    {
        $criterios = SearchCriteria::desdePeticion($peticion);
        $resultado = $this->cartas->search($criterios);

        return [
            'items'      => $resultado['items'],
            'nextCursor' => $resultado['nextCursor'],
            'count'      => count($resultado['items']),
        ];
    }
}
