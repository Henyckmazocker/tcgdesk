<?php

declare(strict_types=1);

namespace App\Application\UseCase;

use App\Domain\Collection\CollectionItem;
use App\Domain\Repository\CollectionRepositoryInterface;
use InvalidArgumentException;

/**
 * Fijar la cantidad de una línea de la colección.
 *
 * A diferencia de `AddToCollection`, aquí la cantidad es **absoluta**: es la
 * edición en línea de la vista de colección, donde el usuario escribe "3" y
 * espera tener tres, no tres más.
 *
 * **Cero borra la fila.** El plan lo pide explícitamente y la razón es
 * aritmética: una fila con `quantity = 0` seguiría contando como carta única en
 * el dashboard, y la colección se llenaría de fantasmas que inflan el recuento
 * sin aportar valor.
 */
class UpdateQuantity
{
    public function __construct(
        private readonly CollectionRepositoryInterface $coleccion
    ) {
    }

    /**
     * @param  array<string, mixed> $peticion Payload del cliente
     * @return array{removed: bool, item: array<string, mixed>|null}|null
     *         null si la línea no existe o no es de este usuario
     */
    public function __invoke(int $userId, array $peticion): ?array
    {
        $itemId   = isset($peticion['item_id']) && is_numeric($peticion['item_id'])
            ? (int) $peticion['item_id']
            : throw new InvalidArgumentException('Falta el item_id de la línea.');

        $cantidad = isset($peticion['quantity']) && is_numeric($peticion['quantity'])
            ? (int) $peticion['quantity']
            : throw new InvalidArgumentException('Falta la cantidad.');

        if ($cantidad < 0 || $cantidad > CollectionItem::CANTIDAD_MAXIMA) {
            throw new InvalidArgumentException(
                'La cantidad debe estar entre 0 y ' . CollectionItem::CANTIDAD_MAXIMA . '.'
            );
        }

        // Se comprueba ANTES de tocar nada para poder distinguir "la he borrado
        // porque me pediste 0" de "esa línea no existe": la primera es un 200 y
        // la segunda un 404, y el repositorio devuelve null en ambos casos.
        if ($this->coleccion->findById($userId, $itemId) === null) {
            return null;
        }

        $item = $this->coleccion->changeQuantity($userId, $itemId, $cantidad);

        return [
            'removed' => $cantidad === 0,
            'item'    => $item,
        ];
    }
}
