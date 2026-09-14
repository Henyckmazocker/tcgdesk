<?php

declare(strict_types=1);

namespace App\Application\UseCase;

use App\Domain\Repository\DeckRepositoryInterface;
use InvalidArgumentException;

/**
 * **Dejar de compartir un mazo**: el `share_token` vuelve a `NULL` y el enlace
 * muere.
 *
 * Esta acción es la razón de ser de la otra. La ruta `/shared/deck/:token` lleva
 * el token **en la URL**, así que acaba en el historial del navegador de quien
 * la abrió, en el `Referer` de lo que pinche desde ahí y en los registros de
 * cualquier proxy por el que pase. Eso es inherente a compartir por enlace y no
 * tiene arreglo; lo que sí se puede es **poder retirarlo**, y esto es ese botón.
 * Por eso «revocar» aquí no es marcar una fila como inactiva ni mover el token a
 * ninguna tabla de historial: es borrarlo, de modo que el enlace filtrado ya no
 * resuelva nada.
 *
 * **Es idempotente**: revocar un mazo que no estaba compartido escribe `NULL`
 * sobre `NULL` y responde que sí. Un 404 ahí sería mentira —el mazo existe y es
 * tuyo— y además volvería frágil el botón del M6, que no puede saber si otra
 * pestaña ya lo revocó.
 *
 * El `null` que devuelve significa lo de siempre en los mazos: **no existe o no
 * es tuyo**, y las dos cosas se responden igual.
 */
class UnshareDeck
{
    public function __construct(
        private readonly DeckRepositoryInterface $mazos
    ) {
    }

    /**
     * @param  array<string, mixed> $peticion Payload del cliente
     * @return array{shareToken: null}|null null si el mazo no existe o no es de
     *         este usuario
     */
    public function __invoke(int $userId, array $peticion): ?array
    {
        $deckId = isset($peticion['deck_id']) && is_numeric($peticion['deck_id'])
            ? (int) $peticion['deck_id']
            : throw new InvalidArgumentException('Falta el deck_id del mazo.');

        if (!$this->mazos->fijarShareToken($userId, $deckId, null)) {
            return null;
        }

        // `shareToken: null` y no un `{revoked: true}`: la respuesta tiene la
        // misma forma que la de `ShareDeck`, así que el M6 pinta el estado del
        // botón leyendo siempre la misma clave.
        return ['shareToken' => null];
    }
}
