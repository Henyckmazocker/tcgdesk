<?php

declare(strict_types=1);

namespace App\Application\UseCase;

use App\Domain\Repository\FriendshipRepositoryInterface;
use App\Domain\Social\Amistad;

/**
 * Lo que pinta `/friends`: amigos, solicitudes **recibidas** y solicitudes
 * **enviadas**, más los tres contadores.
 *
 * **Las tres listas salen de una sola consulta y se separan aquí**, no en SQL.
 * Son tres filtros sobre el mismo conjunto —todas las filas en las que estás—, y
 * partirlas en tres consultas obligaría a repetir tres veces la lista blanca de
 * columnas de `users`: tres sitios donde añadir el `email` sin querer en vez de
 * uno. El criterio de la separación se lee aquí, en cuatro líneas, y es el que
 * importa:
 *
 *  - `friends`: `status = 'accepted'`. **Y solo eso es una amistad.**
 *  - `pending`: `status = 'pending'` y **la recibiste tú** — son las que tienen
 *    botón de aceptar, y el contador que va a la barra del M4.
 *  - `sent`: `status = 'pending'` y **la pediste tú**. Están esperando al otro y
 *    lo único que se puede hacer con ellas es mirarlas.
 *
 * Que `pending` y `sent` sean dos listas y no una con una bandera es lo que
 * impide el fallo simétrico del de `AceptarAmistad`: una vista que pintara el
 * botón de aceptar sobre una solicitud propia pediría al servidor algo que
 * siempre le va a dar 403, y el usuario no entendería por qué.
 *
 * **Aquí no viaja ningún id de usuario, y no es un olvido.** De cada persona
 * salen `username`, `displayName` y `avatarUrl`: el nombre es la clave pública
 * del proyecto —es con lo que se navega a su perfil y con lo que se le pide
 * amistad— y publicar además el entero de `users.id` daría un diccionario de
 * ids reales por el que empezar a recorrer. El `email` tampoco sale, por
 * supuesto: `listarDe()` ni siquiera lo selecciona.
 *
 * El único id que sí viaja es el de la **amistad**, porque es lo que hay que
 * mandar de vuelta en `friend_accept`, `friend_reject` y `friend_remove`. No
 * sirve para recorrer nada: quien lo tenga y no esté en la fila recibe un 404.
 *
 * Es una lectura pura: no lleva `CsrfMiddleware` —no hay estado que falsificar—
 * por el mismo criterio que `collection_list` y `privacy_get`.
 */
class ListarAmistades
{
    public function __construct(
        private readonly FriendshipRepositoryInterface $amistades
    ) {
    }

    /**
     * @return array{
     *     friends: list<array<string, mixed>>,
     *     pending: list<array<string, mixed>>,
     *     sent: list<array<string, mixed>>,
     *     counts: array{friends: int, pending: int, sent: int}
     * }
     */
    public function __invoke(int $userId): array
    {
        $amigos     = [];
        $recibidas  = [];
        $enviadas   = [];

        foreach ($this->amistades->listarDe($userId) as $fila) {
            /** @var Amistad $amistad */
            $amistad = $fila['amistad'];
            $entrada = $this->entrada($amistad, $fila['persona']);

            if ($amistad->estaAceptada()) {
                $amigos[] = $entrada;
                continue;
            }

            // Pendiente. Quién la pidió es lo único que decide en cuál de las
            // dos listas cae, y es también lo único que decide quién puede
            // aceptarla: las dos preguntas tienen la misma respuesta a
            // propósito, para que la vista no pueda ofrecer un botón que el
            // servidor va a rechazar con un 403.
            if ($amistad->laRecibio($userId)) {
                $recibidas[] = $entrada;
            } else {
                $enviadas[] = $entrada;
            }
        }

        return [
            'friends' => $amigos,
            'pending' => $recibidas,
            'sent'    => $enviadas,
            // Se cuentan las listas que se acaban de construir y no se piden a
            // la base de datos: tres `COUNT(*)` serían tres consultas más para
            // decir lo que ya se tiene delante, y podrían discrepar de lo que se
            // está devolviendo si alguien escribe entre medias. El contador de
            // la barra del M4 es `counts.pending`.
            'counts'  => [
                'friends' => count($amigos),
                'pending' => count($recibidas),
                'sent'    => count($enviadas),
            ],
        ];
    }

    /**
     * Una fila del listado. La lista blanca de campos de la persona vive aquí y
     * en `MySqlFriendshipRepository::listarDe()`, y en ningún otro sitio.
     *
     * @param  array{id: int, username: string, displayName: ?string, avatarUrl: ?string} $persona
     * @return array<string, mixed>
     */
    private function entrada(Amistad $amistad, array $persona): array
    {
        return [
            'friendshipId' => $amistad->id,
            'since'        => $amistad->creadaEl,
            'user'         => [
                'username'    => $persona['username'],
                'displayName' => $persona['displayName'],
                'avatarUrl'   => $persona['avatarUrl'],
            ],
        ];
    }
}
