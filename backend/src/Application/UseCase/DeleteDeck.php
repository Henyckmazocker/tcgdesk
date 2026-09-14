<?php

declare(strict_types=1);

namespace App\Application\UseCase;

use App\Domain\Repository\DeckRepositoryInterface;
use InvalidArgumentException;

/**
 * Borrar un mazo, con o sin sus cartas.
 *
 * **Es el use case delicado de este hito**, y merece el mismo trato que tuvo
 * `ChangeItemGrade`. `with_cards` decide entre dos operaciones muy distintas:
 *
 *  - `false` — se borra el mazo y sus líneas (`ON DELETE CASCADE`) y **la
 *    colección no se toca**. Deshacer un mazo no es vender sus cartas.
 *  - `true` — además se **descuenta** cada línea de la colección, todo dentro de
 *    una transacción del repositorio. Es «he vendido el mazo entero».
 *
 * Tres reglas del descuento que no son obvias, y que están donde tienen que
 * estar —en el repositorio, porque son una transacción— pero que se explican
 * aquí porque son decisiones de producto:
 *
 *  1. **Si la cantidad llega a 0 se borra la fila de la colección**, igual que
 *     hace `changeQuantity(0)`: una fila a cero seguiría contando como «carta
 *     única» en el dashboard.
 *  2. **Si la colección tiene MENOS de lo que dice el mazo, se resta hasta 0 y
 *     NO se falla.** La discrepancia es el caso esperado, no el error: vendiste
 *     la carta y nunca actualizaste el mazo. Lo que falta sale en `shortfall`
 *     para que la UI lo enseñe.
 *  3. **Los tokens no se descuentan.** Un token no es una carta que se posea, y
 *     por tanto nunca estuvo en la colección de la que restar. Misma regla que
 *     en el valor y en el tamaño del mazo (`Board::esPoseible()`).
 *
 * `with_cards` **no** tiene defecto peligroso: si no viene, es `false`. Borrar
 * un mazo es reversible a mano; vaciar la colección del usuario porque el
 * cliente olvidó un campo, no.
 */
class DeleteDeck
{
    public function __construct(
        private readonly DeckRepositoryInterface $mazos
    ) {
    }

    /**
     * @param  array<string, mixed> $peticion Payload del cliente
     * @return array{deleted: bool, removedFromCollection: int, shortfall: list<array<string, mixed>>}|null
     *         null si el mazo no existe o no es de este usuario
     */
    public function __invoke(int $userId, array $peticion): ?array
    {
        $deckId = isset($peticion['deck_id']) && is_numeric($peticion['deck_id'])
            ? (int) $peticion['deck_id']
            : throw new InvalidArgumentException('Falta el deck_id del mazo.');

        $conCartas = filter_var($peticion['with_cards'] ?? false, FILTER_VALIDATE_BOOL);

        if (!$conCartas) {
            return $this->mazos->delete($userId, $deckId)
                ? ['deleted' => true, 'removedFromCollection' => 0, 'shortfall' => []]
                : null;
        }

        $resultado = $this->mazos->deleteConCartas($userId, $deckId);

        if ($resultado === null) {
            return null;
        }

        return [
            'deleted'               => true,
            'removedFromCollection' => $resultado['removedFromCollection'],
            'shortfall'             => $resultado['shortfall'],
        ];
    }
}
