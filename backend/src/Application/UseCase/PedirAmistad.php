<?php

declare(strict_types=1);

namespace App\Application\UseCase;

use App\Domain\Repository\FriendshipRepositoryInterface;
use App\Domain\Repository\UserRepositoryInterface;
use InvalidArgumentException;

/**
 * Pedirle amistad a alguien **por su `username`**, que es la mitad del diseño de
 * esta acción.
 *
 * **Por qué el nombre y no el id.** `users.username` es `NOT NULL UNIQUE` y su
 * comentario en `init.sql` dice lo que es: la URL pública de la persona. Un id
 * numérico en el cuerpo de la petición invitaría a recorrerlos —`friend_request`
 * con `user_id: 1, 2, 3…` es un censo de quién existe— mientras que el nombre
 * hay que conocerlo para escribirlo. La colación es `utf8mb4_unicode_ci`, así
 * que `HENYCKMA` y `henyckma` son la misma persona y eso lo resuelve
 * `findByUsername()` sin que aquí haya que normalizar nada.
 *
 * Las tres respuestas que no son un 200, y por qué cada una:
 *
 *  - **Pedírtela a ti mismo es un 422, no un `1062`.** Y esto hay que escribirlo
 *    a mano, porque el esquema **no** lo impide: el `UNIQUE (user_low,
 *    user_high)` sobre la pareja (A,A) daría `user_low = user_high` y la fila se
 *    insertaría tan campante la primera vez. Sin este `if`, cualquiera se crea
 *    una «amistad» consigo mismo que luego aparece en su propio `/friends`.
 *  - **Un nombre que no existe es un 404** (`null` de este use case). No se
 *    distingue de «existe pero no quiere»: no hay tal cosa, porque bloquear está
 *    fuera del alcance del plan.
 *  - **Ya hay fila entre los dos: 409, y lo dice MySQL.** No se comprueba antes
 *    de insertar, a propósito: eso es una carrera —dos personas que se piden
 *    amistad a la vez pasan las dos comprobaciones y acaban con dos solicitudes
 *    cruzadas— y es exactamente el fallo que el `UNIQUE` simétrico por columnas
 *    generadas existe para hacer imposible. El `PDOException` con `1062` sube
 *    hasta `FriendController`, que lo traduce.
 *
 * Y una cosa que este use case **no** hace: comprobar la privacidad del otro.
 * Pedir amistad no lee nada de nadie; lo que se ve o no se ve lo decide
 * `Visibilidad` en la próxima lectura, y solo si la solicitud llega a aceptarse.
 */
class PedirAmistad
{
    public function __construct(
        private readonly FriendshipRepositoryInterface $amistades,
        private readonly UserRepositoryInterface $usuarios
    ) {
    }

    /**
     * @param  array<string, mixed> $peticion Payload del cliente
     * @return array{friendshipId: int, status: string, user: array{username: string, displayName: ?string, avatarUrl: ?string}}|null
     *         null si no hay nadie con ese `username`
     * @throws InvalidArgumentException si falta el nombre o es el propio usuario
     * @throws \PDOException `1062` si ya hay fila entre los dos
     */
    public function __invoke(int $userId, array $peticion): ?array
    {
        $username = isset($peticion['username']) && is_string($peticion['username'])
            ? trim($peticion['username'])
            : throw new InvalidArgumentException('Falta el username de la persona.');

        if ($username === '') {
            throw new InvalidArgumentException('Falta el username de la persona.');
        }

        $destinatario = $this->usuarios->findByUsername($username);

        if ($destinatario === null || $destinatario->id === null) {
            return null;
        }

        // El `if` que el esquema no puede dar. Ver la cabecera: la pareja (A,A)
        // pasa el UNIQUE simétrico sin rechistar.
        if ($destinatario->id === $userId) {
            throw new InvalidArgumentException('No puedes pedirte amistad a ti mismo.');
        }

        $friendshipId = $this->amistades->crearSolicitud($userId, $destinatario->id);

        // Se devuelve la persona con la MISMA lista blanca que el listado
        // —`username`, `displayName`, `avatarUrl`— y sin el `email`, que
        // `findByUsername()` sí trae dentro del `User`: quien llama decide qué
        // publica, y aquí se publica lo justo para pintar la tarjeta de la
        // solicitud recién enviada sin tener que recargar `/friends`.
        return [
            'friendshipId' => $friendshipId,
            'status'       => 'pending',
            'user'         => [
                'username'    => $destinatario->username,
                'displayName' => $destinatario->displayName,
                'avatarUrl'   => $destinatario->avatarUrl,
            ],
        ];
    }
}
