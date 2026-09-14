<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Application\UseCase\BuscarUsuarios;
use InvalidArgumentException;
use Psr\Log\LoggerInterface;

/**
 * La única acción del buscador de usuarios: `user_search`.
 *
 * ## Por qué es un controller NUEVO y no un sexto método en `FriendController`
 *
 * Por el mismo motivo exacto por el que `FollowController` nació aparte en el
 * M3, y aquí pesa aún más. `FriendController` gestiona lo único de esta app que
 * **concede acceso** —aceptar una solicitud es lo que abre el nivel `friends` de
 * la privacidad de alguien— y tiene a mano `FriendshipRepositoryInterface`. Un
 * método de búsqueda escrito ahí lo tendría a mano también, y la tentación
 * inmediata sería «marcar los resultados que ya son amigos» o «filtrar por
 * amistad»: o sea, una consulta de amistad **por cada resultado**, que es
 * literalmente lo que el M6 decidió no hacer al darle DOS valores a
 * `show_in_search` en vez de los tres de `Nivel`. La distancia entre dos
 * ficheros es la forma más barata de que eso no ocurra por inercia: **este
 * controller no conoce el repositorio de amistades ni el de seguimientos, y no
 * tiene por dónde llegar a ellos**.
 *
 * Y el argumento de forma lo confirma: buscar no es una relación. No escribe una
 * fila, no crea nada que nadie tenga que resolver y no cambia en un byte lo que
 * el perfil de nadie enseña. Es una lectura del directorio, filtrada por la
 * privacidad de cada persona.
 *
 * ## Las traducciones, que se leen una sola vez
 *
 *  - **`InvalidArgumentException` = 422.** Tres motivos, los tres del cliente:
 *    falta `q`, mide menos de tres caracteres, o se pasa de 32. El del mínimo es
 *    el *Hecho cuando:* del hito —«buscar dos letras devuelve 422 y no
 *    resultados»— y **no lo puede dar `ValidationMiddleware`**, que solo mira si
 *    el campo está; por eso `routes.php` declara `required: ['q']` (400 si falta
 *    del todo) y el mínimo vive en `BuscarUsuarios`.
 *  - **Cero resultados es un 200 con la lista vacía, no un 404.** No es que no
 *    exista nada: es que no hay nadie que empiece por ahí **y quiera salir**. Un
 *    404 haría distinguible «no hay nadie» de «hay alguien que no sale», que es
 *    justo lo que la sexta columna existe para que no se pueda saber.
 *
 * ## Lo que no sale de aquí
 *
 * **Ni un `email` ni un `id`.** La lista blanca la compone el `SELECT` de
 * `MySqlUserRepository::buscarPorPrefijo()`, así que el correo no llega ni a
 * salir de la base de datos; a un perfil se navega por `username`, que es la
 * clave pública del proyecto.
 *
 * **Y el `user_id` sale de `BaseController::usuario()` y jamás del cuerpo**,
 * como en los cinco métodos de `FriendController` y los tres de
 * `FollowController`. Aquí sirve para una sola cosa —excluirte de tu propio
 * resultado— y las consecuencias de fallar son menores que en la amistad, pero
 * un id que viniera del payload dejaría a cualquiera buscar «como si fuera
 * otro», que en un buscador con rate limit es una forma de no gastar el suyo.
 */
class UserSearchController extends BaseController
{
    public function __construct(
        private readonly BuscarUsuarios $buscar,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * `user_search`: gente cuyo `username` empieza por lo que se escribió.
     *
     * Es **lectura y va sin `Csrf`**, por el mismo criterio que `privacy_get`,
     * `friend_list` y `collection_list`: no escribe una fila, así que no hay
     * estado que falsificar. Lo que sí lleva es `AuthMiddleware`, y eso no es
     * negociable: un buscador de personas abierto a internet sería el directorio
     * que este hito existe para no publicar.
     */
    public function search(array $request): array
    {
        $userId = $this->usuario($request);

        try {
            $resultado = ($this->buscar)($userId, $request['data'] ?? []);
        } catch (InvalidArgumentException $e) {
            return $this->errorResponse($e->getMessage(), 422);
        }

        // Se registra CUÁNTOS resultados y **nunca el texto buscado ni los
        // nombres encontrados**. El log tiene que poder decir si alguien está
        // recorriendo el alfabeto —es lo que el rate limit acota y lo que se
        // querría revisar después— sin acumular en disco a quién busca quién,
        // que es información sobre las dos partes y ninguna la ha publicado.
        $this->logger->info('Búsqueda de usuarios', [
            'user_id'     => $userId,
            'resultados'  => count($resultado['users']),
        ]);

        return $this->successResponse('Resultados de la búsqueda.', $resultado);
    }
}
