<?php

declare(strict_types=1);

namespace App\Application\UseCase;

use App\Domain\Repository\FriendshipRepositoryInterface;
use App\Domain\Social\ResultadoAmistad;
use InvalidArgumentException;

/**
 * Aceptar una solicitud recibida: `pending` → `accepted`.
 *
 * **Este use case es toda la seguridad del M2 del Plan - Amigos y Seguimiento, y
 * cabe en una línea:** solo el `addressee` acepta. Sin ella, cualquiera acepta
 * sus propias solicitudes —pedir y aceptar se convierten en el mismo gesto—, la
 * amistad deja de ser recíproca y el nivel `friends` de la privacidad pasa a
 * significar «cualquiera que se moleste en mandar dos peticiones». No es una
 * validación de formulario: es la única puerta que hay.
 *
 * Los tres noes se distinguen **a propósito**, y no son intercambiables:
 *
 *  - **404 si no existe o no es tuya.** `friendships.id` es un autoincremental
 *    global; responder 403 a quien no está en la fila le confirmaría que ahí hay
 *    una amistad y le diría, probando números, quién se lleva con quién.
 *  - **403 si eres el `requester`.** Aquí sí, y con todas las letras: esa
 *    persona ya sabe que la solicitud existe —la mandó ella— así que el 403 no
 *    revela nada, y decirle «no existe» la mandaría a buscar un fallo que no
 *    hay. Es la condición literal del *Hecho cuando:* del hito.
 *  - **409 si ya estaba aceptada.** No se trata como un éxito silencioso porque
 *    un cliente que acepta dos veces está mirando una lista rancia, y el 409 es
 *    lo que le dice que la recargue.
 *
 * Lo que **no** hace: mirar la privacidad de nadie. Aceptar no lee datos; lo que
 * cambia es lo que `Visibilidad` responderá en la **próxima** lectura. Y por eso
 * mismo, aceptar da acceso retroactivo a todo lo que esa persona tenga en
 * `friends`: no hay «desde cuándo», hay «ahora sí».
 */
class AceptarAmistad
{
    public function __construct(
        private readonly FriendshipRepositoryInterface $amistades
    ) {
    }

    /**
     * @param  array<string, mixed> $peticion Payload del cliente
     * @throws InvalidArgumentException si falta el `friendship_id`
     */
    public function __invoke(int $userId, array $peticion): ResultadoAmistad
    {
        $friendshipId = isset($peticion['friendship_id']) && is_numeric($peticion['friendship_id'])
            ? (int) $peticion['friendship_id']
            : throw new InvalidArgumentException('Falta el friendship_id de la solicitud.');

        $amistad = $this->amistades->buscar($friendshipId);

        if ($amistad === null || !$amistad->participa($userId)) {
            return ResultadoAmistad::NoExiste;
        }

        // LA línea. `$userId` viene de `AuthMiddleware` y jamás del cuerpo de la
        // petición (ver `BaseController::usuario()`): si viniera del payload,
        // cambiar un número dejaría a cualquiera aceptar amistades ajenas y esta
        // comprobación no protegería absolutamente nada.
        if (!$amistad->laRecibio($userId)) {
            return ResultadoAmistad::NoTeCorresponde;
        }

        if ($amistad->estaAceptada()) {
            return ResultadoAmistad::EstadoQueNoToca;
        }

        // El repositorio vuelve a exigir `status = 'pending'` en el UPDATE, y
        // un false aquí no es un permiso denegado sino una carrera perdida: otra
        // petición aceptó la misma solicitud entre el SELECT y este UPDATE. Se
        // responde igual que el caso de arriba porque para el cliente es lo
        // mismo —su lista está rancia— y el estado final es el correcto.
        return $this->amistades->aceptar($friendshipId)
            ? ResultadoAmistad::Hecho
            : ResultadoAmistad::EstadoQueNoToca;
    }
}
