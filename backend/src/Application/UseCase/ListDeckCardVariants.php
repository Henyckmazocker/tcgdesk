<?php

declare(strict_types=1);

namespace App\Application\UseCase;

use App\Domain\Repository\DeckRepositoryInterface;
use InvalidArgumentException;

/**
 * **Qué versiones de esta carta tienes de verdad**, para la línea de un mazo.
 *
 * Es la mitad de lectura de `ChangeDeckCardIdentity`, y existe para que cambiar
 * de versión sea **un clic y no un formulario de cuatro campos**: la vista de M5
 * puebla un desplegable con lo que este use case devuelve —solo las
 * combinaciones que hay en `mtg_collection_item` con `is_wishlist = 0`, con
 * cuántas tienes y cuántas te quedan libres— y elegir una dispara
 * `deck_card_change`. El usuario nunca teclea `finish`, `language` y
 * `condition_grade` a mano, que era el diseño descartado en M0.
 *
 * Se entra por la **línea del mazo** (`deck_id` + `card_id`) y no por el
 * `printing_uuid` por dos motivos: es lo que la vista tiene a mano cuando el
 * usuario pulsa sobre una fila, y así la propiedad del mazo se comprueba antes
 * de mirar la colección. Un `card_id` que no es de este usuario devuelve null y
 * el controller responde 404, en vez de contestar una lista vacía —que
 * significaría «no tienes ninguna versión», una respuesta muy distinta—.
 *
 * **No escribe nada**: es una lectura, y por eso su ruta no lleva
 * `CsrfMiddleware`.
 */
class ListDeckCardVariants
{
    public function __construct(
        private readonly DeckRepositoryInterface $mazos
    ) {
    }

    /**
     * @param  array<string, mixed> $peticion Payload del cliente
     * @return array{variants: list<array<string, mixed>>}|null
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

        $carta = $this->mazos->findCardById($userId, $deckId, $cardId);

        if ($carta === null) {
            return null;
        }

        return ['variants' => $this->mazos->variantesEnColeccion($userId, (string) $carta['printingUuid'])];
    }
}
