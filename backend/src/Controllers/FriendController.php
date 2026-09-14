<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Application\UseCase\AceptarAmistad;
use App\Application\UseCase\DeshacerAmistad;
use App\Application\UseCase\ListarAmistades;
use App\Application\UseCase\PedirAmistad;
use App\Application\UseCase\RechazarAmistad;
use App\Domain\Social\ResultadoAmistad;
use InvalidArgumentException;
use PDOException;
use Psr\Log\LoggerInterface;

/**
 * Las cinco acciones de la amistad: pedir, aceptar, rechazar, deshacer y listar.
 *
 * **Las cinco son sobre uno mismo y llevan `AuthMiddleware`.** El `user_id` se
 * lee de `$request['user_id']` —donde lo deja ese middleware, ver
 * `BaseController::usuario()`— y **jamás** del cuerpo de la petición. Aquí eso
 * importa más que en ningún otro controller del proyecto: `friendships.id` es un
 * autoincremental global, así que si el usuario viniera del payload bastaría con
 * cambiar un número para **aceptar la amistad de otra persona**, y aceptar es lo
 * que abre el nivel `friends` de su privacidad. No hay ninguna otra puerta.
 *
 * El controller es fino como los demás: saca el usuario, delega y traduce. Las
 * traducciones, que se leen una sola vez:
 *
 *  - **`ResultadoAmistad` → código.** Los tres use cases que mueven una fila
 *    devuelven ese enum y no un booleano, porque sus tres noes son 404, 403 y
 *    409 y confundirlos tiene consecuencias: ver el porqué escrito en el propio
 *    enum. El mensaje lo pone cada método, porque «ya estaba aceptada» y
 *    «todavía está pendiente» son el mismo `EstadoQueNoToca` visto desde dos
 *    acciones distintas.
 *  - **`InvalidArgumentException` = 422.** Falta el `username`, falta el
 *    `friendship_id`, o te estás pidiendo amistad a ti mismo. Este último no lo
 *    puede cazar el esquema: el `UNIQUE` simétrico sobre la pareja (A,A) daría
 *    `user_low = user_high` y la fila entraría tan campante la primera vez.
 *  - **`PDOException` con `1062` = 409.** Ya hay fila entre esos dos, la haya
 *    pedido quien la haya pedido: el `UNIQUE (user_low, user_high)` es simétrico
 *    a propósito. Se traduce con `traducirErrorDeBaseDeDatos()`, pasándole el
 *    mensaje del duplicado — que es lo que hace que ese helper distinga el
 *    `1062` del `1452` en lugar de responder lo del catálogo de cartas.
 *
 * **`null` de `PedirAmistad` = 404**, que es «no hay nadie con ese nombre». No
 * hay un caso «existe pero no quiere recibir solicitudes»: bloquear está fuera
 * del alcance del plan, y la herramienta que sí existe para no ser visto es
 * bajar las secciones a `friends` o `nobody`.
 *
 * Lo que **no** hay aquí: nada de `user_follow`. Seguir es otra tabla, otro
 * controller y otro hito (M3), y **no da ningún acceso**. El día que una acción
 * de este fichero consulte esa tabla, el nivel `friends` habrá dejado de
 * significar algo.
 */
class FriendController extends BaseController
{
    public function __construct(
        private readonly PedirAmistad $pedir,
        private readonly AceptarAmistad $aceptar,
        private readonly RechazarAmistad $rechazar,
        private readonly DeshacerAmistad $deshacer,
        private readonly ListarAmistades $listar,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * `friend_request`: pedir amistad **por `username`**.
     *
     * Va por el nombre y no por un id porque `users.username` es la clave
     * pública del proyecto —es la URL del perfil— y mandar enteros por el cuerpo
     * invitaría a recorrerlos. El 409 del duplicado no lo decide este método
     * sino el `UNIQUE` de la tabla: comprobar antes de insertar sería una
     * carrera, y evitarla es la mejora deliberada del plan sobre LibraryVue.
     */
    public function request(array $request): array
    {
        $userId = $this->usuario($request);

        try {
            $resultado = ($this->pedir)($userId, $request['data'] ?? []);
        } catch (InvalidArgumentException $e) {
            return $this->errorResponse($e->getMessage(), 422);
        } catch (PDOException $e) {
            return $this->traducirErrorDeBaseDeDatos(
                $e,
                $userId,
                $this->logger,
                'Solicitud de amistad rechazada: ya hay fila entre esos dos',
                'Ya existe una solicitud con esa persona.'
            );
        }

        if ($resultado === null) {
            return $this->errorResponse('No hay nadie con ese nombre de usuario.', 404);
        }

        // Se registra el id de la amistad y no el nombre del destinatario: el
        // log dice que la acción ocurrió y deja la fila por la que buscarla, sin
        // acumular en disco quién le pide amistad a quién.
        $this->logger->info('Solicitud de amistad enviada', [
            'user_id'       => $userId,
            'friendship_id' => $resultado['friendshipId'],
        ]);

        return $this->successResponse('Solicitud enviada.', $resultado, 201);
    }

    /**
     * `friend_accept`: `pending` → `accepted`. **Solo el destinatario.**
     *
     * El 403 al solicitante es la condición literal del *Hecho cuando:* del M2 y
     * la única línea de seguridad del hito. Va aquí abajo, en el use case, y no
     * en el `WHERE` de una consulta: ver `AceptarAmistad`.
     */
    public function accept(array $request): array
    {
        return $this->mover(
            $request,
            fn (int $userId, array $datos): ResultadoAmistad => ($this->aceptar)($userId, $datos),
            'Amistad aceptada.',
            'Esa solicitud no es tuya: solo puede aceptarla quien la recibió.',
            'Esa solicitud ya estaba aceptada.',
            'Amistad aceptada'
        );
    }

    /**
     * `friend_reject`: borra la solicitud. **Solo el destinatario.**
     *
     * Borra y no marca, porque no hay estado `rejected`: con el `UNIQUE`
     * simétrico, una fila rechazada impediría volver a pedir amistad para
     * siempre — un bloqueo permanente que nadie habría decidido.
     */
    public function reject(array $request): array
    {
        return $this->mover(
            $request,
            fn (int $userId, array $datos): ResultadoAmistad => ($this->rechazar)($userId, $datos),
            'Solicitud rechazada.',
            'Esa solicitud no es tuya: solo puede rechazarla quien la recibió.',
            'Esa amistad ya está aceptada: lo que quieres es deshacerla.',
            'Solicitud de amistad rechazada'
        );
    }

    /**
     * `friend_remove`: deshacer una amistad aceptada. **Cualquiera de los dos.**
     *
     * Y el efecto es inmediato y retroactivo: lo que se corta es la próxima
     * lectura de `Visibilidad`, no lo que la otra persona ya vio.
     */
    public function remove(array $request): array
    {
        return $this->mover(
            $request,
            fn (int $userId, array $datos): ResultadoAmistad => ($this->deshacer)($userId, $datos),
            'Amistad deshecha.',
            'Esa amistad no es tuya.',
            'Esa solicitud todavía está pendiente: lo que quieres es rechazarla.',
            'Amistad deshecha'
        );
    }

    /**
     * `friend_list`: amigos, recibidas, enviadas y los tres contadores.
     *
     * Es lectura y va sin Csrf, por lo mismo que `collection_list` y
     * `privacy_get`: no hay estado que falsificar. **No lleva el `email` de
     * nadie**, ni el `id` numérico de las personas: solo `username`,
     * `displayName` y `avatarUrl`, que es la misma lista blanca del router
     * público.
     */
    public function list(array $request): array
    {
        $userId = $this->usuario($request);

        return $this->successResponse('Amistades.', ($this->listar)($userId));
    }

    /**
     * Las tres acciones que mueven una fila comparten forma exacta, y esta es.
     *
     * Está factorizado y no copiado tres veces porque lo que hay dentro es la
     * traducción de `ResultadoAmistad` a HTTP, y tres copias de eso son tres
     * sitios donde el 403 puede acabar siendo un 404 —o al revés— sin que nadie
     * lo note. Lo único que cambia entre las tres es el texto, y por eso el
     * texto viaja por parámetro.
     *
     * @param callable(int, array<string, mixed>): ResultadoAmistad $useCase
     * @return array<string, mixed>
     */
    private function mover(
        array $request,
        callable $useCase,
        string $mensajeDeExito,
        string $mensajeDe403,
        string $mensajeDe409,
        string $mensajeDeLog
    ): array {
        $userId = $this->usuario($request);

        try {
            $resultado = $useCase($userId, $request['data'] ?? []);
        } catch (InvalidArgumentException $e) {
            return $this->errorResponse($e->getMessage(), 422);
        }

        if ($resultado !== ResultadoAmistad::Hecho) {
            return $this->errorResponse(
                match ($resultado) {
                    ResultadoAmistad::NoTeCorresponde => $mensajeDe403,
                    ResultadoAmistad::EstadoQueNoToca => $mensajeDe409,
                    default                           => 'Esa amistad no existe.',
                },
                $resultado->codigoHttp()
            );
        }

        $this->logger->info($mensajeDeLog, [
            'user_id'       => $userId,
            'friendship_id' => $request['data']['friendship_id'] ?? null,
        ]);

        return $this->successResponse($mensajeDeExito);
    }
}
