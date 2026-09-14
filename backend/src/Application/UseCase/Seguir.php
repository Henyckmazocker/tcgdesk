<?php

declare(strict_types=1);

namespace App\Application\UseCase;

use App\Domain\Repository\FollowRepositoryInterface;
use App\Domain\Repository\UserPrivacyRepositoryInterface;
use App\Domain\Repository\UserRepositoryInterface;
use App\Domain\Social\Nivel;
use InvalidArgumentException;

/**
 * Seguir a alguien: poner un marcador sobre su perfil público.
 *
 * **Lo que este use case NO hace, y es lo único que hay que recordar de él:
 * dar acceso a nada.** Seguir no le pide permiso a nadie, no crea ningún estado
 * que la otra persona tenga que resolver y **no cambia ni un campo de lo que su
 * perfil te enseña**. Antes y después de esta llamada, `/api/public/user/X`
 * devuelve exactamente lo mismo, byte a byte, porque quien compone esa respuesta
 * —`PublicHttpRouter` preguntando a `Visibilidad`— no conoce la tabla
 * `user_follow` ni puede conocerla. Es el *Hecho cuando:* del M3 del
 * Plan - Amigos y Seguimiento y el motivo de que seguir sea un hito aparte del
 * de la amistad.
 *
 * Va por `username` y no por id, por lo mismo que `PedirAmistad`: es la clave
 * pública del proyecto —la URL del perfil— y mandar enteros por el cuerpo
 * invitaría a recorrerlos. La colación es `utf8mb4_unicode_ci`, así que las
 * mayúsculas dan igual y eso lo resuelve `findByUsername()`.
 *
 * ## Los tres noes
 *
 *  - **Un nombre que no existe: `null`, que el controller traduce a 404.**
 *  - **Seguirte a ti mismo: 422.** La `PRIMARY KEY (follower_id, followed_id)`
 *    no lo impide —(A,A) es una pareja perfectamente válida para ella, al
 *    contrario que para el `UNIQUE` simétrico de `friendships`, que tampoco lo
 *    impide por otro motivo— así que es este `if` o nada. Sin él, cualquiera se
 *    sigue a sí mismo, se suma uno a su propio contador de seguidores y aparece
 *    en su propia lista de seguidos.
 *  - **Un perfil sin ninguna sección en `everyone`: 422.** Es la condición
 *    literal del hito, y va abajo del todo porque es la que hay que explicar.
 *
 * ## Por qué el 422 del perfil cerrado se mira sobre el NIVEL y no sobre `puedeVer()`
 *
 * La pregunta que hay que responder es «¿este perfil tiene cara pública?», y esa
 * pregunta **no depende de quién la haga**: la respuesta es la misma para todo
 * el mundo, porque lo que decide es lo que el dueño tiene configurado. Por eso
 * se leen los cinco niveles de `UserPrivacyRepositoryInterface` y se busca un
 * `Nivel::Todos`.
 *
 * Preguntárselo a `Visibilidad::puedeVer()` habría sido lo aparentemente
 * limpio y **es incorrecto**, porque `puedeVer()` responde otra pregunta —«¿ve
 * ESTE espectador ESTA sección?»— y para contestarla hay que elegir un
 * espectador. Las dos opciones fallan:
 *
 *  - **Con el seguidor como espectador**, `puedeVer()` diría que sí a las
 *    secciones que ese usuario ve *por ser amigo* del dueño. Un perfil entero en
 *    `friends` —que es un perfil cerrado al público, y por tanto un marcador a
 *    una página que nadie más puede abrir— se podría seguir por el hecho de ser
 *    su amigo, que no es lo que el plan pide. Y peor: el resultado de una acción
 *    dependería de una relación distinta, que es exactamente la mezcla de las
 *    dos tablas que este hito existe para no hacer.
 *  - **Con `null` como espectador** el resultado de hoy sería el correcto —un
 *    anónimo solo ve `everyone`— pero dejaría escrita una llamada al motor de
 *    permisos con el hueco del espectador vacío, esperando a que alguien «lo
 *    arregle» pasándole el usuario real. Y de paso ataría seguir a `Visibilidad`
 *    para siempre, cuando la gracia de este hito es justo lo contrario.
 *
 * Así que se leen los niveles. **Esto no rompe la regla de oro del perfil
 * público** —«ningún use case consulta `user_privacy_settings` por su cuenta;
 * todos pasan por `Visibilidad`»— porque esa regla protege las decisiones de
 * *qué se enseña*, y aquí no se enseña nada: no se lee ni una carta, ni un mazo,
 * ni un precio. Lo único que se decide es si tiene sentido guardar un marcador,
 * y la respuesta no publica ningún dato del dueño más allá de lo que ya publica
 * su propio perfil.
 */
class Seguir
{
    public function __construct(
        private readonly FollowRepositoryInterface $seguimientos,
        private readonly UserRepositoryInterface $usuarios,
        private readonly UserPrivacyRepositoryInterface $privacidad
    ) {
    }

    /**
     * @param  array<string, mixed> $peticion Payload del cliente
     * @return array{following: bool, user: array{username: string, displayName: ?string, avatarUrl: ?string}}|null
     *         null si no hay nadie con ese `username`
     * @throws InvalidArgumentException si falta el nombre, eres tú mismo, o ese
     *         perfil no tiene ninguna sección en `everyone`
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

        if ($seguido->id === $userId) {
            throw new InvalidArgumentException('No puedes seguirte a ti mismo.');
        }

        if (!$this->tieneCaraPublica($seguido->id)) {
            throw new InvalidArgumentException(
                'Ese perfil no enseña nada públicamente: seguirlo sería un marcador a una página vacía.'
            );
        }

        $this->seguimientos->seguir($userId, $seguido->id);

        // Se devuelve la persona con la MISMA lista blanca de siempre
        // —`username`, `displayName`, `avatarUrl`— y sin el `email`, que
        // `findByUsername()` sí trae dentro del `User`: quien llama decide qué
        // publica, y aquí se publica lo justo para pintar la tarjeta del perfil
        // recién seguido sin recargar la lista entera.
        return [
            'following' => true,
            'user'      => [
                'username'    => $seguido->username,
                'displayName' => $seguido->displayName,
                'avatarUrl'   => $seguido->avatarUrl,
            ],
        ];
    }

    /**
     * ¿Tiene este perfil **alguna** de las cinco secciones en `everyone`?
     *
     * Alguna, no todas: con la colección abierta y el resto cerrado hay una
     * página que visitar, y el marcador sirve para lo que existe. Exigir las
     * cinco convertiría una condición de sentido común —«que haya algo que
     * ver»— en una política sobre cuánto tiene que enseñar alguien para merecer
     * seguidores, que nadie ha decidido.
     *
     * `nivelesDe()` promete **siempre las cinco secciones**, también para quien
     * no tiene fila en `user_privacy_settings` —que hoy es todo el mundo—, así
     * que el recorrido es sobre lo que devuelve y no sobre `Seccion::cases()`.
     * El defecto de `Coleccion`, `Mazos` y `Sets` es `Todos`: un usuario recién
     * creado se puede seguir, y eso es lo correcto.
     */
    private function tieneCaraPublica(int $duenyoId): bool
    {
        foreach ($this->privacidad->nivelesDe($duenyoId) as $nivel) {
            if ($nivel === Nivel::Todos) {
                return true;
            }
        }

        return false;
    }
}
