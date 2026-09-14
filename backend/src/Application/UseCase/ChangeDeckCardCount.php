<?php

declare(strict_types=1);

namespace App\Application\UseCase;

use App\Domain\Deck\DeckCard;
use App\Domain\Repository\DeckRepositoryInterface;
use InvalidArgumentException;

/**
 * Fijar cuántas copias de una carta lleva el mazo.
 *
 * A diferencia de `AddCardToDeck`, aquí la cantidad es **absoluta**: es la
 * edición en línea de la tabla del mazo, donde el usuario escribe «3» y espera
 * llevar tres, no tres más.
 *
 * **Cero borra la línea**, igual que `quantity = 0` borra la de la colección. Y
 * el 0 es una petición legítima, no una ausencia: por eso `count` **no** irá en
 * la lista de `required` de `deck_card_set` —`ValidationMiddleware` trata el 0
 * como campo vacío y lo rechazaría con un 400—, y por eso lo valida este use
 * case. Lo mismo que pasa con `quantity` en `collection_update_quantity`.
 *
 * El motivo de que el 0 borre es aritmético: una línea con `count = 0` seguiría
 * contando en «cartas del mazo» y falsearía el tamaño, que es justo lo que M6
 * mira para avisar del mínimo de 60 o 100 cartas.
 */
class ChangeDeckCardCount
{
    public function __construct(
        private readonly DeckRepositoryInterface $mazos
    ) {
    }

    /**
     * @param  array<string, mixed> $peticion Payload del cliente
     * @return array{removed: bool, card: array<string, mixed>|null}|null
     *         null si la línea no existe o no es de este mazo/usuario
     */
    public function __invoke(int $userId, array $peticion): ?array
    {
        $deckId = isset($peticion['deck_id']) && is_numeric($peticion['deck_id'])
            ? (int) $peticion['deck_id']
            : throw new InvalidArgumentException('Falta el deck_id del mazo.');

        $cardId = isset($peticion['card_id']) && is_numeric($peticion['card_id'])
            ? (int) $peticion['card_id']
            : throw new InvalidArgumentException('Falta el card_id de la línea.');

        $cantidad = isset($peticion['count']) && is_numeric($peticion['count'])
            ? (int) $peticion['count']
            : throw new InvalidArgumentException('Falta la cantidad.');

        if ($cantidad < 0 || $cantidad > DeckCard::CANTIDAD_MAXIMA) {
            throw new InvalidArgumentException(
                'La cantidad debe estar entre 0 y ' . DeckCard::CANTIDAD_MAXIMA . '.'
            );
        }

        // Se comprueba ANTES de tocar nada para poder distinguir "la he borrado
        // porque me pediste 0" de "esa línea no existe": la primera es un 200 y
        // la segunda un 404, y el repositorio devuelve null en los dos casos.
        if ($this->mazos->findCardById($userId, $deckId, $cardId) === null) {
            return null;
        }

        $carta = $this->mazos->changeCardCount($userId, $deckId, $cardId, $cantidad);

        return ['removed' => $cantidad === 0, 'card' => $carta];
    }
}
