<?php

declare(strict_types=1);

namespace App\Application\UseCase;

use App\Domain\Deck\Deck;
use App\Domain\Repository\DeckRepositoryInterface;
use InvalidArgumentException;

/**
 * Editar el nombre, el estado, el formato o las notas de un mazo.
 *
 * **La edición es parcial a propósito.** El botón «desmontar» del aviso de
 * sobreasignación de M5 es un `deck_update` que manda solo
 * `status: 'dismantled'`, y si esto construyera un mazo completo con los
 * defectos que faltan, ese clic borraría el nombre, el formato y las notas.
 * `Deck::camposDesdePeticion()` devuelve exactamente las claves que vinieron, ya
 * validadas.
 *
 * Cambiar el estado **no mueve ni una carta**: pasar a `built` no descuenta nada
 * de la colección y pasar a `dismantled` no devuelve nada. Lo único que cambia
 * es si el mazo entra o no en el cruce de M3, que se calcula en cada consulta.
 */
class UpdateDeck
{
    public function __construct(
        private readonly DeckRepositoryInterface $mazos
    ) {
    }

    /**
     * @param  array<string, mixed> $peticion Payload del cliente
     * @return array{deck: array<string, mixed>}|null null si el mazo no existe
     *         o no es de este usuario
     */
    public function __invoke(int $userId, array $peticion): ?array
    {
        $deckId = isset($peticion['deck_id']) && is_numeric($peticion['deck_id'])
            ? (int) $peticion['deck_id']
            : throw new InvalidArgumentException('Falta el deck_id del mazo.');

        // Se valida ANTES de comprobar que el mazo existe: un estado inventado
        // es un fallo del cliente (422) lo tenga quien lo tenga, y responder 404
        // a una petición mal formada mandaría a buscar el bug al sitio
        // equivocado.
        $campos = Deck::camposDesdePeticion($peticion);

        $mazo = $this->mazos->update($userId, $deckId, $campos);

        return $mazo === null ? null : ['deck' => $mazo];
    }
}
