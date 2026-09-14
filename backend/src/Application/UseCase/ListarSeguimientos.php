<?php

declare(strict_types=1);

namespace App\Application\UseCase;

use App\Domain\Repository\FollowRepositoryInterface;

/**
 * Lo que devuelve `follow_list`: **a quién sigues** y **cuánta gente te sigue**.
 *
 * Las dos mitades no son simétricas, y la asimetría es toda la decisión de este
 * fichero:
 *
 *  - **`following` es una lista de personas.** Son tus marcadores, los pusiste
 *    tú, y el M5 del Plan - Amigos y Seguimiento los pinta como una lista de
 *    perfiles a los que volver — que es exactamente para lo que existe la tabla
 *    y lo que el #8 del Roadmap necesita para cruzar colecciones.
 *  - **`followerCount` es un número, y nunca una lista.** El plan lo decide sin
 *    ambigüedad: el número lo ve su dueño y **nadie puede impedir que suba** —la
 *    herramienta para no ser seguido es bajar las secciones a `friends` o
 *    `nobody`—, pero los nombres de quienes te siguen no se publican. Seguir es
 *    unilateral y no se pide permiso: dar la lista convertiría un acto privado
 *    de quien sigue en algo que el seguido audita, y eso ya es otra relación
 *    —la amistad— que tiene su propia tabla y su propio «hay que aceptar».
 *
 * **Este listado no comprueba la privacidad de nadie.** `Seguir` exige que el
 * perfil tenga alguna sección en `everyone` para dejar poner el marcador, pero
 * un perfil puede cerrarse después y su marcador sigue ahí: filtrarlo aquí
 * escondería filas que el usuario tiene y no podría quitar, y además convertiría
 * este listado en algo que consulta permisos, que es justo lo que el hito
 * separa. Lo que esa persona enseñe ya se decide donde se ha decidido siempre,
 * en `Visibilidad`, cuando se abra su perfil.
 *
 * Es una lectura pura y va sin `CsrfMiddleware`, por el mismo criterio que
 * `friend_list`, `collection_list` y `privacy_get`: no hay estado que
 * falsificar. Y **no viaja el `email` de nadie ni el `id` numérico de nadie** —
 * la consulta ni siquiera los selecciona.
 */
class ListarSeguimientos
{
    public function __construct(
        private readonly FollowRepositoryInterface $seguimientos
    ) {
    }

    /**
     * @return array{
     *     following: list<array{username: string, displayName: ?string, avatarUrl: ?string, since: ?string}>,
     *     followerCount: int
     * }
     */
    public function __invoke(int $userId): array
    {
        $seguidos = $this->seguimientos->seguidosDe($userId);

        return [
            'following' => $seguidos,
            // El contador de seguidos NO se devuelve: es `count(following)` y
            // quien lo quiera lo tiene delante. El de seguidores sí, porque es
            // lo único de esta respuesta que no se puede calcular desde el
            // cliente — son filas de OTRA gente.
            'followerCount' => $this->seguimientos->contarSeguidoresDe($userId),
        ];
    }
}
