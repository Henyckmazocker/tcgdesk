<?php

declare(strict_types=1);

namespace App\Application\UseCase;

use App\Domain\Collection\Condition;
use App\Domain\Repository\CollectionRepositoryInterface;
use InvalidArgumentException;

/**
 * Cambiar el estado físico de una línea de la colección.
 *
 * Tiene use case propio y no es un parámetro más de `UpdateQuantity` por una
 * razón de esquema: `condition_grade` está **dentro** del `UNIQUE KEY uq_item`,
 * así que cambiarla no modifica una fila —**la mueve** a otra combinación de la
 * clave, que puede estar ya ocupada—. Cuando lo está, las dos filas son la misma
 * carta en el mismo estado y hay que **fundirlas sumando cantidades**.
 *
 * La alternativa que parecía más simple —resolverlo en la interfaz con un
 * `UpdateQuantity(0)` seguido de un `AddToCollection`— se descartó: son dos
 * peticiones HTTP y, si la segunda no llega, el usuario ha perdido cartas. Aquí
 * las dos escrituras van en la misma transacción del repositorio.
 *
 * `UpdateQuantity` sigue sirviendo solo la cantidad, que es la única de las ocho
 * columnas que se puede reescribir sin tocar la clave.
 */
class ChangeItemGrade
{
    public function __construct(
        private readonly CollectionRepositoryInterface $coleccion
    ) {
    }

    /**
     * @param  array<string, mixed> $peticion Payload del cliente
     * @return array{item: array<string, mixed>|null, merged: bool, origen: array<string, mixed>|null}|null
     *         null si la línea no existe o no es de este usuario. `origen` llega
     *         desde `moveLine()`, del que `changeGrade()` es un caso particular:
     *         aquí es siempre null salvo en el no-op, porque mover la línea
     *         entera deja vacía la combinación de partida
     */
    public function __invoke(int $userId, array $peticion): ?array
    {
        $itemId = isset($peticion['item_id']) && is_numeric($peticion['item_id'])
            ? (int) $peticion['item_id']
            : throw new InvalidArgumentException('Falta el item_id de la línea.');

        if (!isset($peticion['condition']) || trim((string) $peticion['condition']) === '') {
            throw new InvalidArgumentException('Falta el estado de la carta.');
        }

        // `desde()` y no `intentar()`: aquí es una ESCRITURA, no un filtro. Un
        // estado que no se entiende debe dar 422, jamás guardarse como el valor
        // por defecto — eso falsearía el inventario en silencio.
        $condicion = Condition::desde($peticion['condition']);

        return $this->coleccion->changeGrade($userId, $itemId, $condicion);
    }
}
