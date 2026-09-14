<?php

declare(strict_types=1);

namespace App\Application\UseCase;

use App\Domain\Repository\FriendshipRepositoryInterface;
use App\Domain\Social\ResultadoAmistad;
use InvalidArgumentException;

/**
 * Rechazar una solicitud recibida. **Borra la fila**, y eso es una decisión del
 * plan, no un atajo.
 *
 * No hay estado `rejected` en el `ENUM` y no puede haberlo: con el
 * `UNIQUE (user_low, user_high)` simétrico del esquema, una fila rechazada
 * **impediría volver a pedir amistad para siempre**. Nadie habría decidido que
 * un rechazo bloquea, pero bloquearía — y bloquear está explícitamente fuera del
 * alcance del plan. Borrando, quien pidió puede volver a pedir más adelante y
 * nadie acumula un historial de desaires. Ver `EstadoAmistad`.
 *
 * Quién puede: **solo el `addressee`**, exactamente igual que `AceptarAmistad`,
 * y por el mismo motivo. Rechazar no es menos delicado que aceptar: si lo
 * pudiera hacer el solicitante, tendría una forma de retirar su petición
 * borrándole la notificación al otro — que es una operación legítima, pero es
 * *otra* («cancelar»), y el plan no la abre en este hito.
 *
 * Una solicitud **ya aceptada no se rechaza**: eso es deshacer una amistad, lo
 * hace `DeshacerAmistad` y lo puede hacer cualquiera de los dos. Se responde 409
 * y no se borra, porque las dos operaciones acaban en el mismo `DELETE` y
 * confundirlas dejaría que un 409 mal leído pareciera un no-op cuando en
 * realidad habría deshecho una amistad de verdad.
 */
class RechazarAmistad
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

        if (!$amistad->laRecibio($userId)) {
            return ResultadoAmistad::NoTeCorresponde;
        }

        if ($amistad->estaAceptada()) {
            return ResultadoAmistad::EstadoQueNoToca;
        }

        return $this->amistades->eliminar($friendshipId)
            ? ResultadoAmistad::Hecho
            : ResultadoAmistad::NoExiste;
    }
}
