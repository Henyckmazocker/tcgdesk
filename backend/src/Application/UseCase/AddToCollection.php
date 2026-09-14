<?php

declare(strict_types=1);

namespace App\Application\UseCase;

use App\Domain\Collection\CollectionItem;
use App\Domain\Repository\CollectionRepositoryInterface;

/**
 * Añadir ejemplares a la colección (o a la lista de deseos).
 *
 * Sumar, no duplicar: el repositorio escribe con
 * `INSERT ... ON DUPLICATE KEY UPDATE quantity = quantity + VALUES(quantity)`
 * sobre el `UNIQUE KEY` de seis columnas, así que pulsar "Añadir" dos veces
 * sobre la misma carta deja **una fila con cantidad 2**. Esa misma propiedad es
 * la que hará idempotente la importación desde fichero del plan siguiente.
 *
 * El `userId` llega aparte del payload, siempre: lo pone `AuthMiddleware` en el
 * request y el controller lo lee de ahí. Si viniera en el cuerpo, cualquiera
 * escribiría en la colección de otro.
 */
class AddToCollection
{
    public function __construct(
        private readonly CollectionRepositoryInterface $coleccion
    ) {
    }

    /**
     * @param  array<string, mixed> $peticion Payload del cliente
     * @return array{id: int, item: array<string, mixed>|null}
     */
    public function __invoke(int $userId, array $peticion): array
    {
        $item = CollectionItem::desdePeticion($userId, $peticion);

        $id = $this->coleccion->upsert($item);

        // Se relee para devolver la cantidad YA sumada y la carta con su precio:
        // el cliente necesita saber que ahora tiene 2, no que ha añadido 1.
        return [
            'id'   => $id,
            'item' => $this->coleccion->findById($userId, $id),
        ];
    }
}
