<?php

declare(strict_types=1);

namespace App\Application\UseCase;

use App\Domain\Repository\CollectionRepositoryInterface;
use InvalidArgumentException;

/**
 * Quitar una línea entera de la colección.
 *
 * Borrado real, no una marca: la fila desaparece. Es lo coherente con que bajar
 * la cantidad a cero también borre —si no, habría dos maneras de "no tener" una
 * carta y solo una de ellas dejaría de contar en los agregados.
 *
 * El `userId` va al `WHERE` del `DELETE`, no solo a una comprobación previa: el
 * `id` de la línea es un autoincremental global y sin ese filtro bastaría con
 * probar números para borrar la colección de otro.
 */
class RemoveFromCollection
{
    public function __construct(
        private readonly CollectionRepositoryInterface $coleccion
    ) {
    }

    /**
     * @param  array<string, mixed> $peticion Payload del cliente
     * @return bool false si la línea no existe o no es de este usuario
     */
    public function __invoke(int $userId, array $peticion): bool
    {
        $itemId = isset($peticion['item_id']) && is_numeric($peticion['item_id'])
            ? (int) $peticion['item_id']
            : throw new InvalidArgumentException('Falta el item_id de la línea.');

        return $this->coleccion->remove($userId, $itemId);
    }
}
