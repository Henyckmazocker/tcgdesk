<?php

declare(strict_types=1);

namespace App\Application\UseCase;

use App\Domain\Collection\Condition;
use App\Domain\Repository\CollectionRepositoryInterface;
use InvalidArgumentException;

/**
 * Cumplir un deseo: la carta que querías ya la tienes.
 *
 * No es un `UPDATE` de `is_wishlist`, porque esa columna está **dentro** del
 * `UNIQUE KEY uq_item`: cruzarla mueve la fila a una combinación que puede estar
 * ya ocupada —querer cuatro Sol Ring teniendo dos es perfectamente normal— y
 * entonces las dos filas son la misma carta y hay que fundirlas sumando. Todo
 * eso lo resuelve `moveLine()` dentro de una transacción; aquí solo vive lo que
 * el repositorio **no puede** decidir.
 *
 * Y lo que no puede decidir son dos cosas:
 *
 * 1. **Que la línea SEA un deseo.** `moveLine()` devuelve null solo por «no
 *    existe o no es tuya», así que la comprobación de `is_wishlist` es de aquí.
 *    Cumplir algo que ya tienes es un **error del cliente (422)**, no una
 *    operación sin efecto: un 200 silencioso escondería un bug de la vista, que
 *    estaría ofreciendo el botón «ya la tengo» sobre una línea de colección.
 * 2. **Que `quantity` no supere lo deseado.** El repositorio también lo valida
 *    —`quantity` es `SMALLINT UNSIGNED` y restar por debajo de cero revienta la
 *    sentencia—, pero esa es la última defensa del esquema. Esta es la que
 *    produce el mensaje que lee el usuario.
 *
 * El movimiento es **parcial por defecto**: quien pulsa «ya la tengo» sobre un
 * deseo de cuatro ha comprado una, no las cuatro. `quantity: null` explícito es
 * la forma de pedir la fila entera.
 */
class FulfillWish
{
    public function __construct(
        private readonly CollectionRepositoryInterface $coleccion
    ) {
    }

    /**
     * @param  array<string, mixed> $peticion Payload del cliente
     * @return array{item: array<string, mixed>|null, merged: bool, origen: array<string, mixed>|null}|null
     *         null si la línea no existe o no es de este usuario. `origen` es el
     *         deseo tal como quedó —o null si se agotó—, y es lo que la vista
     *         necesita para decidir si quita la línea de la lista o solo le baja
     *         el número: el `id` de `mtg_collection_item` es inestable
     * @throws InvalidArgumentException si la línea no es un deseo, si la
     *         cantidad no es positiva o si supera lo deseado
     */
    public function __invoke(int $userId, array $peticion): ?array
    {
        $itemId = isset($peticion['item_id']) && is_numeric($peticion['item_id'])
            ? (int) $peticion['item_id']
            : throw new InvalidArgumentException('Falta el item_id de la línea.');

        // Hay que leer la fila ANTES de moverla: es el único momento en el que
        // se puede distinguir "esa línea no existe" (404) de "existe pero no es
        // un deseo" (422). Después del movimiento ya no sería un deseo.
        $deseo = $this->coleccion->findById($userId, $itemId);

        if ($deseo === null) {
            return null;
        }

        if ($deseo['isWishlist'] !== true) {
            throw new InvalidArgumentException('Esa línea ya está en tu colección: no hay ningún deseo que cumplir.');
        }

        $aCumplir = $this->cantidad($peticion, (int) $deseo['quantity']);

        // `desde()` y no `intentar()`: aquí es una ESCRITURA, no un filtro. Un
        // estado que no se entiende debe dar 422, jamás guardarse como el valor
        // por defecto — eso falsearía el inventario en silencio. Mismo criterio
        // que `ChangeItemGrade`, y por el mismo motivo.
        $destino = ['isWishlist' => false];

        if (isset($peticion['condition']) && trim((string) $peticion['condition']) !== '') {
            $destino['condition'] = Condition::desde($peticion['condition']);
        }

        return $this->coleccion->moveLine($userId, $itemId, $destino, $aCumplir);
    }

    /**
     * Cuántos ejemplares se cumplen.
     *
     * `quantity` ausente es **uno**, que es el gesto normal: se compra una carta
     * y se pulsa el botón. `quantity: null` **explícito** es la fila entera, y
     * por eso se mira con `array_key_exists()` y no con `isset()`, que no sabe
     * distinguir el null enviado del campo que no vino.
     *
     * @param array<string, mixed> $peticion
     */
    private function cantidad(array $peticion, int $deseados): ?int
    {
        if (array_key_exists('quantity', $peticion) && $peticion['quantity'] === null) {
            return null;
        }

        if (!isset($peticion['quantity'])) {
            return 1;
        }

        if (!is_numeric($peticion['quantity'])) {
            throw new InvalidArgumentException('La cantidad debe ser un número.');
        }

        $cantidad = (int) $peticion['quantity'];

        if ($cantidad <= 0) {
            throw new InvalidArgumentException('Para cumplir un deseo hay que cumplir al menos un ejemplar.');
        }

        if ($cantidad > $deseados) {
            throw new InvalidArgumentException(
                "No puedes cumplir {$cantidad} ejemplares de un deseo de {$deseados}."
            );
        }

        return $cantidad;
    }
}
