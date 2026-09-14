<?php

declare(strict_types=1);

namespace App\Application\UseCase;

use App\Domain\Repository\FollowRepositoryInterface;
use App\Domain\Repository\UserRepositoryInterface;
use InvalidArgumentException;

/**
 * Quitar el marcador. El gemelo de `Seguir`, con **una diferencia deliberada**.
 *
 * **Aquí NO se comprueba la privacidad del otro, y eso es lo importante de este
 * fichero.** `Seguir` exige que el perfil tenga alguna sección en `everyone`
 * —seguir uno cerrado sería un marcador a una página vacía—, pero repetir esa
 * comprobación al quitarlo dejaría atrapado al que ya sigue a alguien que
 * después cerró su perfil: la acción de deshacer fallaría con un 422 y no
 * habría ninguna forma de soltar ese marcador. Una condición que se exige para
 * entrar no se puede exigir para salir.
 *
 * Por lo demás es la misma forma: va por `username` —la clave pública— y un
 * nombre que no existe devuelve `null`, que el controller traduce a 404.
 *
 * **Y es idempotente**, como `Seguir`: dejar de seguir a quien no sigues
 * devuelve 200, porque el estado que el usuario pidió ya es el estado actual.
 * Un 404 ahí obligaría al frontend a distinguir dos situaciones que para quien
 * pulsa el botón son la misma —«no lo sigo»— y convertiría el doble toque de un
 * móvil con mala cobertura en un error rojo. El 404 se reserva para lo único
 * que de verdad no existe: la persona.
 *
 * Seguirse y dejar de seguirse a uno mismo: el segundo no hace falta
 * prohibirlo, porque `Seguir` impide que esa fila llegue a existir y borrar lo
 * que no hay es un no-op. Se deja pasar a propósito en vez de duplicar el `if`,
 * que sería una segunda copia de una regla que ya vive en un solo sitio.
 */
class DejarDeSeguir
{
    public function __construct(
        private readonly FollowRepositoryInterface $seguimientos,
        private readonly UserRepositoryInterface $usuarios
    ) {
    }

    /**
     * @param  array<string, mixed> $peticion Payload del cliente
     * @return array{following: bool, user: array{username: string, displayName: ?string, avatarUrl: ?string}}|null
     *         null si no hay nadie con ese `username`
     * @throws InvalidArgumentException si falta el nombre
     */
    public function __invoke(int $userId, array $peticion): ?array
    {
        $username = isset($peticion['username']) && is_string($peticion['username'])
            ? trim($peticion['username'])
            : throw new InvalidArgumentException('Falta el username de la persona.');

        if ($username === '') {
            throw new InvalidArgumentException('Falta el username de la persona.');
        }

        $seguido = $this->usuarios->findByUsername($username);

        if ($seguido === null || $seguido->id === null) {
            return null;
        }

        $this->seguimientos->dejarDeSeguir($userId, $seguido->id);

        // La misma lista blanca de siempre, y sin el `email` que
        // `findByUsername()` trae dentro del `User`.
        return [
            'following' => false,
            'user'      => [
                'username'    => $seguido->username,
                'displayName' => $seguido->displayName,
                'avatarUrl'   => $seguido->avatarUrl,
            ],
        ];
    }
}
