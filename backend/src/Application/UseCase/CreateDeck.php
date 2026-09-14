<?php

declare(strict_types=1);

namespace App\Application\UseCase;

use App\Domain\Deck\Deck;
use App\Domain\Repository\DeckRepositoryInterface;

/**
 * Crear un mazo vacío.
 *
 * Solo el nombre es obligatorio: el estado nace en `building` —un mazo recién
 * creado está vacío, luego no puede estar montado—, el formato en NULL y las
 * notas también. Es lo que permite empezar un mazo escribiendo una sola cosa,
 * que es exactamente lo que hará la rejilla de `/decks` en M5.
 *
 * El `userId` llega aparte del payload, siempre: lo pone `AuthMiddleware` en el
 * request y el controller lo lee de ahí. Si viniera en el cuerpo, cualquiera
 * crearía mazos en la cuenta de otro.
 */
class CreateDeck
{
    public function __construct(
        private readonly DeckRepositoryInterface $mazos
    ) {
    }

    /**
     * @param  array<string, mixed> $peticion Payload del cliente
     * @return array{deck: array<string, mixed>|null}
     */
    public function __invoke(int $userId, array $peticion): array
    {
        $mazo = Deck::desdePeticion($userId, $peticion);

        $id = $this->mazos->create($mazo);

        // Se relee para devolver el mazo con sus contadores ya calculados
        // (`cards`, `valueEur`), que son los que pinta la rejilla: el cliente no
        // debería tener que sumarlos por su cuenta.
        return ['deck' => $this->mazos->findById($userId, $id)];
    }
}
