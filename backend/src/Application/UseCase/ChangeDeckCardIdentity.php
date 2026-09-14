<?php

declare(strict_types=1);

namespace App\Application\UseCase;

use App\Domain\Collection\CardLanguage;
use App\Domain\Collection\Condition;
use App\Domain\Collection\Finish;
use App\Domain\Deck\Board;
use App\Domain\Repository\DeckRepositoryInterface;
use InvalidArgumentException;

/**
 * Cambiar **qué versión** de una carta pide el mazo.
 *
 * El caso real es este: *«este Sol Ring lo tengo normal y foil, mete la foil»*.
 * Cambia `finish`, `language`, `condition_grade` o `board` de una línea que ya
 * está en el mazo.
 *
 * **Es el gemelo de `ChangeItemGrade`, y por el mismo motivo de esquema.** Las
 * cuatro columnas están **dentro** de `uq_deck_card`, así que el cambio no
 * modifica la fila: **la mueve** a otra combinación de la clave, que puede estar
 * ya ocupada por otra línea del mismo mazo. Cuando lo está, las dos son la misma
 * carta en el mismo estado y hay que **fundirlas sumando `count`**, dentro de
 * una transacción con `SELECT ... FOR UPDATE`.
 *
 * Resolverlo desde la interfaz con un `RemoveCardFromDeck` + un `AddCardToDeck`
 * se descarta por lo mismo que allí: son dos peticiones HTTP y, si la segunda no
 * llega, la línea desaparece del mazo.
 *
 * **No toca la colección.** Cambiar qué versión pide el mazo no mueve ni una
 * carta de `mtg_collection_item`; solo cambia lo que el mazo reclama, y por
 * tanto lo que el cruce de M3 calcula. Si pides la foil y no la tienes, lo que
 * cambia es que la app empiece a decírtelo, no tu colección.
 *
 * `ChangeDeckCardCount` sigue sirviendo solo la cantidad, que es la única de las
 * siete columnas que se puede reescribir sin tocar la clave.
 */
class ChangeDeckCardIdentity
{
    public function __construct(
        private readonly DeckRepositoryInterface $mazos
    ) {
    }

    /**
     * @param  array<string, mixed> $peticion Payload del cliente
     * @return array{card: array<string, mixed>|null, merged: bool}|null
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

        // `desde()` y no `intentar()` en las cuatro: esto es una ESCRITURA. Un
        // acabado que no se entiende debe dar 422, jamás guardarse como el valor
        // por defecto — el mazo pediría una carta distinta de la que el usuario
        // señaló y el cruce con la colección mentiría en silencio.
        $acabado   = isset($peticion['finish'])   ? Finish::desde($peticion['finish'])         : null;
        $idioma    = isset($peticion['language']) ? CardLanguage::desde($peticion['language']) : null;
        $condicion = $this->condicion($peticion);
        $zona      = isset($peticion['board'])    ? Board::desde($peticion['board'])           : null;

        if ($acabado === null && $idioma === null && $condicion === null && $zona === null) {
            throw new InvalidArgumentException('No hay nada que cambiar: manda finish, language, condition_grade o board.');
        }

        return $this->mazos->changeCardIdentity($userId, $deckId, $cardId, $acabado, $idioma, $condicion, $zona);
    }

    /**
     * El estado físico llega con dos nombres según quién pregunte:
     * `condition_grade` en los contratos de mazo —igual que la columna— y
     * `condition` en los de colección. El frontend comparte el selector entre
     * las dos pantallas, así que se aceptan los dos.
     *
     * @param array<string, mixed> $peticion
     */
    private function condicion(array $peticion): ?Condition
    {
        $valor = $peticion['condition_grade'] ?? $peticion['condition'] ?? null;

        return $valor !== null ? Condition::desde($valor) : null;
    }
}
