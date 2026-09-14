<?php

declare(strict_types=1);

namespace App\Application\UseCase;

use App\Domain\Deck\DeckCard;
use App\Domain\Repository\DeckRepositoryInterface;
use InvalidArgumentException;

/**
 * Meter una carta en un mazo.
 *
 * Sumar, no duplicar: el repositorio escribe con
 * `INSERT ... ON DUPLICATE KEY UPDATE count = count + VALUES(count)` sobre el
 * `UNIQUE KEY` de seis columnas, así que añadir dos veces la misma carta en la
 * misma zona deja **una línea con `count = 2`**. Es la misma propiedad del
 * `upsert()` de la colección, y la que hará idempotente la importación de
 * decklists de M7.
 *
 * **Añadir no toca la colección.** Meter un Sol Ring en un mazo no crea ni mueve
 * ninguna carta en `mtg_collection_item`: solo dice qué reclama el mazo. Si no
 * lo tienes, el cruce de M3 te lo dirá; nadie te va a comprar la carta por
 * escribirla en una lista.
 */
class AddCardToDeck
{
    public function __construct(
        private readonly DeckRepositoryInterface $mazos
    ) {
    }

    /**
     * @param  array<string, mixed> $peticion Payload del cliente
     * @return array{id: int, card: array<string, mixed>|null}|null
     *         null si el mazo no existe o no es de este usuario
     */
    public function __invoke(int $userId, array $peticion): ?array
    {
        $deckId = isset($peticion['deck_id']) && is_numeric($peticion['deck_id'])
            ? (int) $peticion['deck_id']
            : throw new InvalidArgumentException('Falta el deck_id del mazo.');

        $carta = DeckCard::desdePeticion($deckId, $peticion);

        $id = $this->mazos->addCard($userId, $carta);

        if ($id === null) {
            return null;
        }

        // Se relee para devolver la cantidad YA sumada y la carta con su precio:
        // el cliente necesita saber que ahora lleva 2, no que ha añadido 1.
        return ['id' => $id, 'card' => $this->mazos->findCardById($userId, $deckId, $id)];
    }
}
