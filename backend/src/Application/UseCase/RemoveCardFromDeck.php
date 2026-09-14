<?php

declare(strict_types=1);

namespace App\Application\UseCase;

use App\Domain\Repository\DeckRepositoryInterface;
use InvalidArgumentException;

/**
 * Sacar una línea entera del mazo.
 *
 * Borrado real, no una marca: la fila desaparece. Es lo coherente con que bajar
 * la cantidad a cero también borre —si no, habría dos maneras de «no llevar» una
 * carta y solo una de ellas dejaría de contar para el tamaño del mazo—.
 *
 * **No toca la colección**: la carta sigue siendo tuya, solo deja de estar en
 * esta lista.
 *
 * El `deck_id` viaja además del `card_id` y los dos van al `WHERE`: la línea es
 * un autoincremental global y `mtg_deck_card` no guarda `user_id`, así que la
 * propiedad se comprueba por el `JOIN` con `mtg_deck`.
 */
class RemoveCardFromDeck
{
    public function __construct(
        private readonly DeckRepositoryInterface $mazos
    ) {
    }

    /**
     * @param  array<string, mixed> $peticion Payload del cliente
     * @return bool false si la línea no existe o no es de este mazo/usuario
     */
    public function __invoke(int $userId, array $peticion): bool
    {
        $deckId = isset($peticion['deck_id']) && is_numeric($peticion['deck_id'])
            ? (int) $peticion['deck_id']
            : throw new InvalidArgumentException('Falta el deck_id del mazo.');

        $cardId = isset($peticion['card_id']) && is_numeric($peticion['card_id'])
            ? (int) $peticion['card_id']
            : throw new InvalidArgumentException('Falta el card_id de la línea.');

        return $this->mazos->removeCard($userId, $deckId, $cardId);
    }
}
