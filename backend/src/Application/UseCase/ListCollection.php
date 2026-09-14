<?php

declare(strict_types=1);

namespace App\Application\UseCase;

use App\Domain\Collection\CollectionCriteria;
use App\Domain\Repository\CollectionRepositoryInterface;

/**
 * Listar la colección con filtros, orden y paginación.
 *
 * Fino a propósito, igual que `SearchCards`: traduce la petición a criterios ya
 * validados y delega. Lo que protege es que **nada crudo del cliente llegue al
 * repositorio** —el `ORDER BY` y el `LIMIT` se interpolan en el SQL porque PDO
 * no admite marcador ahí, así que la lista blanca de `CollectionCriteria` es la
 * única barrera.
 */
class ListCollection
{
    public function __construct(
        private readonly CollectionRepositoryInterface $coleccion
    ) {
    }

    /**
     * @param  array<string, mixed> $peticion
     * @return array{items: list<array<string, mixed>>, nextCursor: string|null, count: int}
     */
    public function __invoke(int $userId, array $peticion): array
    {
        $criterios = CollectionCriteria::desdePeticion($peticion);
        $resultado = $this->coleccion->search($userId, $criterios);

        return [
            'items'      => $resultado['items'],
            'nextCursor' => $resultado['nextCursor'],
            'count'      => count($resultado['items']),
        ];
    }
}
