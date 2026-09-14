<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Application\UseCase\GuardarPrivacidad;
use App\Application\UseCase\ObtenerPrivacidad;
use InvalidArgumentException;
use Psr\Log\LoggerInterface;

/**
 * Las dos acciones de la privacidad: leerla y escribirla.
 *
 * **Las dos son privadas y sobre uno mismo.** Llevan `AuthMiddleware` en su pila
 * de `routes.php` y el `user_id` se lee de `$request['user_id']` —donde lo deja
 * ese middleware— y **jamás** del cuerpo de la petición. Aquí eso importa más
 * que en ningún otro controller: si el usuario viniera del payload, cambiar un
 * número abriría la colección de otro de par en par, que es exactamente lo que
 * este plan existe para evitar.
 *
 * Lo que **no** hay aquí es una lectura de la privacidad de otra persona. No es
 * un olvido: qué se ve de un tercero no se pregunta, se resuelve — lo hace
 * `App\Domain\Social\Visibilidad` desde el router público del M3, y un endpoint
 * que devolviera los niveles ajenos sería un mapa de qué secciones vale la pena
 * volver a intentar.
 *
 * El controller es fino a propósito, como los demás: saca el usuario, delega en
 * el use case y traduce el resultado a un código HTTP. La única traducción que
 * hace, y por eso se lee una sola vez:
 *
 *  - **`InvalidArgumentException` = 422.** Un nivel que no existe o una petición
 *    sin ninguna sección son fallos del cliente, no del servidor. El use case
 *    usa `Nivel::desde()` y no `intentar()` justamente para que revienten aquí
 *    en lugar de caer al valor por defecto — que para `collection` es `everyone`
 *    y publicaría lo contrario de lo que se pedía.
 *
 * No hay 404: un usuario autenticado siempre tiene privacidad, aunque no tenga
 * fila. La ausencia de fila **son** los cinco defectos, y eso lo resuelve el
 * repositorio sin que ni el use case ni este controller tengan que saberlo.
 */
class PrivacyController extends BaseController
{
    public function __construct(
        private readonly ObtenerPrivacidad $obtener,
        private readonly GuardarPrivacidad $guardar,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Los cinco niveles del usuario, siempre los cinco.
     *
     * Es lectura y no lleva Csrf, igual que `collection_list`: no hay estado que
     * falsificar. Tampoco lleva `ValidationMiddleware`, porque no tiene ni un
     * campo —ni siquiera opcional—: la única entrada es el `user_id` que deja
     * `AuthMiddleware`.
     */
    public function get(array $request): array
    {
        $userId = $this->usuario($request);

        return $this->successResponse('Privacidad.', ($this->obtener)($userId));
    }

    /**
     * Cambiar **solo las secciones que vengan**; el resto se queda como está.
     *
     * La respuesta trae las cinco tal como quedaron, no solo las tocadas: es lo
     * que permite al panel repintar sin recomponer nada y, de paso, lo que hace
     * visible que cambiar una no ha movido las otras cuatro.
     */
    public function set(array $request): array
    {
        $userId = $this->usuario($request);

        try {
            $resultado = ($this->guardar)($userId, $request['data'] ?? []);
        } catch (InvalidArgumentException $e) {
            return $this->errorResponse($e->getMessage(), 422);
        }

        // Se registra qué secciones quedaron y no qué se pidió cambiar: un
        // cambio de privacidad es lo que decide si un dato personal se publica,
        // y el log tiene que poder responder «qué tenía puesto» sin reconstruir
        // la secuencia entera de peticiones. Nunca lleva más que los niveles.
        $this->logger->info('Privacidad actualizada', [
            'user_id' => $userId,
            'privacy' => $resultado['privacy'],
        ]);

        return $this->successResponse('Privacidad actualizada.', $resultado);
    }
}
