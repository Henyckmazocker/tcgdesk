<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Application\UseCase\DejarDeSeguir;
use App\Application\UseCase\ListarSeguimientos;
use App\Application\UseCase\Seguir;
use InvalidArgumentException;
use Psr\Log\LoggerInterface;

/**
 * Las tres acciones de seguir: `follow_add`, `follow_remove` y `follow_list`.
 *
 * ## Por qué es un controller NUEVO y no tres métodos más en `FriendController`
 *
 * Porque el `FriendController` existe para gestionar la única cosa de esta app
 * que **concede acceso** —aceptar una solicitud es lo que abre el nivel
 * `friends` de la privacidad de alguien— y este no concede nada en absoluto.
 * Ponerlos juntos costaría lo mismo hoy y mañana sería un fichero donde las dos
 * tablas se leen de corrido, con un método que ya tiene a mano el repositorio de
 * amistades cuando le toca decidir algo de seguimiento. El plan nombra ese fallo
 * dos veces —«si `user_follow` aparece en `Visibilidad`, el nivel `friends` ha
 * dejado de significar nada»— y la distancia entre dos ficheros es la forma más
 * barata de que no ocurra por inercia: **este controller no conoce
 * `FriendshipRepositoryInterface` y no tiene por dónde llegar a él**.
 *
 * Y el argumento de simetría lo confirma: son dos relaciones distintas en todo
 * lo que importa —dirección, permiso, acceso, clave y estados— y el esquema ya
 * las separó en dos tablas por eso mismo en el M1. Un controller por tabla deja
 * el código con la misma forma que la base de datos.
 *
 * ## Las traducciones, que se leen una sola vez
 *
 *  - **`null` del use case = 404.** No hay nadie con ese `username`. No existe
 *    un caso «existe pero no se deja seguir»: bloquear está fuera del alcance
 *    del plan, y la herramienta que sí hay para no ser seguido es bajar las
 *    secciones a `friends` o `nobody` — entonces el perfil deja de tener cara
 *    pública y el 422 de abajo lo impide.
 *  - **`InvalidArgumentException` = 422.** Tres motivos: falta el `username`,
 *    te estás siguiendo a ti mismo, o ese perfil **no tiene ninguna sección en
 *    `everyone`**. El último es la condición literal del M3 del
 *    Plan - Amigos y Seguimiento, y el razonamiento de por qué se mira el nivel
 *    configurado y no `Visibilidad::puedeVer()` está escrito en `Seguir`.
 *  - **No hay 409, y no es un olvido.** `friend_request` sí lo tiene, porque
 *    allí una segunda solicitud es un conflicto que alguien tiene que resolver.
 *    Aquí seguir dos veces no es un conflicto: el estado pedido ya es el estado
 *    actual, así que el repositorio absorbe el `1062` de la `PRIMARY KEY` con un
 *    `ON DUPLICATE KEY UPDATE` y la respuesta es 200. Por eso este fichero
 *    **no llama a `traducirErrorDeBaseDeDatos()`**: no tiene ningún `23000` que
 *    traducir, y pasarle un mensaje de duplicado que nunca va a ocurrir sería
 *    dejar escrito que aquí hay un conflicto posible.
 *  - **200 y nunca 201**, tampoco en el `follow_add` que sí crea la fila. Un 201
 *    la primera vez y un 200 la segunda obligaría al cliente a distinguir dos
 *    respuestas que significan lo mismo —«ya sigues a esta persona»— y a
 *    dibujar dos botones distintos para el mismo estado.
 *
 * Como los cinco métodos de `FriendController`, los tres de aquí son **sobre uno
 * mismo**: el `user_id` sale de `BaseController::usuario()` —donde lo dejó
 * `AuthMiddleware`— y **jamás** del cuerpo. Aquí las consecuencias de fallar son
 * menores que en la amistad (nadie ve nada de nadie por un marcador mal puesto),
 * pero un `follower_id` que viniera del payload dejaría a cualquiera inflar el
 * contador de seguidores de otro y llenarle la lista de seguidos.
 *
 * **Lo que no hay aquí, en ninguna línea: `friendships`, `Visibilidad` y
 * `user_privacy_settings`.** La privacidad se consulta una sola vez, dentro de
 * `Seguir`, y solo para responder «¿este perfil tiene cara pública?» — una
 * pregunta que no depende de quién la haga y que no publica ni un dato.
 */
class FollowController extends BaseController
{
    public function __construct(
        private readonly Seguir $seguir,
        private readonly DejarDeSeguir $dejarDeSeguir,
        private readonly ListarSeguimientos $listar,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * `follow_add`: seguir a alguien **por su `username`**.
     *
     * Idempotente: si ya lo seguías, la respuesta es la misma. Ver la cabecera.
     */
    public function add(array $request): array
    {
        return $this->marcar(
            $request,
            fn (int $userId, array $datos): ?array => ($this->seguir)($userId, $datos),
            'Ahora sigues a esa persona.',
            'Perfil seguido'
        );
    }

    /**
     * `follow_remove`: quitar el marcador.
     *
     * **No comprueba la privacidad del seguido**, a diferencia de `add`: si esa
     * persona cerró su perfil después de que la siguieras, exigir la misma
     * condición para salir te dejaría con un marcador imposible de quitar.
     */
    public function remove(array $request): array
    {
        return $this->marcar(
            $request,
            fn (int $userId, array $datos): ?array => ($this->dejarDeSeguir)($userId, $datos),
            'Has dejado de seguir a esa persona.',
            'Perfil dejado de seguir'
        );
    }

    /**
     * `follow_list`: a quién sigues y cuánta gente te sigue.
     *
     * Es lectura y va sin Csrf, por lo mismo que `friend_list` y
     * `collection_list`: no hay estado que falsificar. **No lleva el `email` de
     * nadie ni el `id` numérico de nadie**, y `followerCount` es un número
     * —quién te sigue no se publica: ver `ListarSeguimientos`—.
     */
    public function list(array $request): array
    {
        $userId = $this->usuario($request);

        return $this->successResponse('Seguimientos.', ($this->listar)($userId));
    }

    /**
     * Las dos acciones que mueven un marcador comparten forma exacta, y esta es.
     *
     * Va factorizado y no copiado dos veces por lo mismo que el `mover()` de
     * `FriendController`: lo que hay dentro es la traducción de los noes a
     * códigos HTTP, y dos copias de eso son dos sitios donde el 422 puede acabar
     * siendo un 404 sin que nadie lo note. Lo único que cambia entre las dos es
     * el texto, y por eso el texto viaja por parámetro.
     *
     * @param callable(int, array<string, mixed>): ?array $useCase
     * @return array<string, mixed>
     */
    private function marcar(
        array $request,
        callable $useCase,
        string $mensajeDeExito,
        string $mensajeDeLog
    ): array {
        $userId = $this->usuario($request);

        try {
            $resultado = $useCase($userId, $request['data'] ?? []);
        } catch (InvalidArgumentException $e) {
            return $this->errorResponse($e->getMessage(), 422);
        }

        if ($resultado === null) {
            return $this->errorResponse('No hay nadie con ese nombre de usuario.', 404);
        }

        // Se registra el `username` porque es lo único que identifica la acción
        // y ya es público por diseño —es la URL del perfil—. En la amistad se
        // registra el id de la fila justamente para NO acumular en disco quién
        // le pide amistad a quién; aquí no hay id de fila que registrar, y un
        // marcador no es una relación entre dos personas: es una preferencia de
        // una sola, la misma que ya está en la tabla.
        $this->logger->info($mensajeDeLog, [
            'user_id'  => $userId,
            'username' => $resultado['user']['username'],
        ]);

        return $this->successResponse($mensajeDeExito, $resultado);
    }
}
