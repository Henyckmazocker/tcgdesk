<?php

declare(strict_types=1);

namespace App\Application\UseCase;

use App\Domain\Repository\FriendshipRepositoryInterface;
use App\Domain\Social\ResultadoAmistad;
use InvalidArgumentException;

/**
 * Deshacer una amistad aceptada. **La puede deshacer cualquiera de los dos**, y
 * ahí está la única diferencia con aceptar y rechazar: una amistad se pide de un
 * lado y se concede del otro, pero se rompe desde los dos. Exigir el permiso del
 * que no quiere romperla sería exigir permiso para dejar de enseñarle tus cosas.
 *
 * **Quitar la amistad quita el acceso retroactivamente y de golpe.** No hay nada
 * que «deshacer» del pasado: quien ya vio el valor de tu colección lo vio, y lo
 * que se corta es la próxima lectura —la siguiente vez que `Visibilidad`
 * pregunte por `sonAmigos()`, la fila ya no estará—. Conviene tenerlo claro
 * antes de que alguien pida un «deshacer el deshacer».
 *
 * **Sobre `pending` TAMBIÉN, y no solo sobre `accepted`** (enmienda del
 * 2026-09-14, ver el Log del plan). La versión anterior devolvía 409 sobre una
 * solicitud pendiente, y eso dejaba un agujero de producto: **quien enviaba una
 * solicitud no tenía ninguna forma de retirarla**. `RechazarAmistad` es solo del
 * destinatario, así que el solicitante se quedaba atado a su propia petición
 * hasta que el otro contestara. Los dos verbos siguen separados porque **no son
 * el mismo acto** —rechazar es decir que no a algo que te han pedido; esto es
 * retirar lo tuyo o romper lo que había— pero el criterio de este use case ya no
 * es el estado de la fila, sino **de qué lado estás**: si participas, puedes
 * deshacerla.
 *
 * Y el `DELETE` es el mismo de rechazar por el mismo motivo: no hay `rejected`
 * ni «ex-amigos», así que después de deshacer se puede volver a pedir. Con un
 * estado terminal en la tabla, el `UNIQUE` simétrico lo impediría para siempre.
 */
class DeshacerAmistad
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
            : throw new InvalidArgumentException('Falta el friendship_id de la amistad.');

        $amistad = $this->amistades->buscar($friendshipId);

        if ($amistad === null || !$amistad->participa($userId)) {
            return ResultadoAmistad::NoExiste;
        }

        // Sin comprobación de estado: `pending` y `accepted` se borran igual.
        // Lo único que decide es `participa()`, de arriba. Ver la cabecera.
        return $this->amistades->eliminar($friendshipId)
            ? ResultadoAmistad::Hecho
            : ResultadoAmistad::NoExiste;
    }
}
