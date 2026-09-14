<?php

declare(strict_types=1);

namespace App\Domain\Repository;

use App\Domain\Social\Amistad;

/**
 * Quién es amigo de quién.
 *
 * De este puerto cuelga la seguridad entera del Plan - Amigos y Seguimiento:
 * `App\Domain\Social\Visibilidad` resuelve el nivel `friends` preguntando aquí y
 * en ningún otro sitio, igual que resuelve los otros dos preguntando a
 * `UserPrivacyRepositoryInterface`. `sonAmigos()` se escribió **primero**, en el
 * M0, porque es el único método cuyo fallo no se ve: publica datos y la
 * respuesta parece correcta. Los cinco use cases del M2 —pedir, aceptar,
 * rechazar, deshacer y listar— se sumaron después, y **ninguno de ellos puede
 * responder la pregunta de `sonAmigos()` por su cuenta**.
 *
 * **`user_follow` no entra aquí, ni en `Visibilidad`, jamás.** Son dos tablas a
 * propósito: la amistad es recíproca, hay que aceptarla y da acceso al nivel
 * `friends`; seguir es unilateral, no se le pide permiso a nadie y **no da
 * ninguno**. El día que una consulta de este repositorio una las dos tablas con
 * un `OR`, el nivel `friends` pasa a significar «cualquiera que pulse seguir» y
 * deja de significar nada.
 *
 * **Dónde NO está la autorización: aquí.** Los métodos que escriben reciben el
 * `id` de la fila y hacen lo que se les pide. Quién puede aceptar, quién puede
 * rechazar y quién puede deshacer lo deciden los use cases sobre la `Amistad`
 * que devuelve `buscar()`, y lo deciden **una sola vez cada uno**. Repartir esa
 * decisión entre el `WHERE` de la consulta y el `if` del use case es cómo se
 * acaba con dos comprobaciones que discrepan y una de las dos gana en silencio.
 *
 * Desde el M2 este puerto **sí** está registrado en `config/container.php`
 * (`MySqlFriendshipRepository`), y de eso depende que `Visibilidad` reciba algo
 * distinto de `null` en producción.
 */
interface FriendshipRepositoryInterface
{
    /**
     * ¿Estos dos son amigos ACEPTADOS? Una solicitud `pending` NO cuenta, y esa
     * es la única pregunta que esta función responde. Consulta por (user_low,
     * user_high), que es el UNIQUE: da igual quién pidiera.
     */
    public function sonAmigos(int $a, int $b): bool;

    /**
     * Deja una solicitud `pending` y devuelve su id.
     *
     * **Ni comprueba si ya existe, ni debe.** El `UNIQUE (user_low, user_high)`
     * por columnas generadas lo resuelve el esquema con un `1062`, y esa es la
     * mejora deliberada sobre LibraryVue: comprobar antes de insertar es una
     * carrera —dos personas que se piden amistad a la vez pasan las dos
     * comprobaciones y acaban con dos solicitudes cruzadas, y aceptar una deja
     * la otra huérfana—. El `PDOException` sube tal cual hasta el controller,
     * que lo traduce a un 409.
     *
     * **`user_low` y `user_high` no se insertan**: son generadas y un `INSERT`
     * que las mencione falla.
     *
     * @throws \PDOException `1062` (SQLSTATE 23000) si ya hay fila entre esos dos
     */
    public function crearSolicitud(int $solicitante, int $destinatario): int;

    /**
     * La fila por su id, sin mirar quién pregunta.
     *
     * Devuelve la amistad **entera, con los dos lados**, porque es lo que los
     * use cases necesitan para decidir: sin `requester_id` no se puede
     * distinguir al que puede aceptar del que no. Quien llama comprueba;
     * este método no.
     */
    public function buscar(int $friendshipId): ?Amistad;

    /**
     * `pending` → `accepted`. **Solo si seguía pendiente.**
     *
     * El `status = 'pending'` del `WHERE` no es la comprobación de permisos
     * —esa la hace el use case— sino la de concurrencia: dos aceptaciones a la
     * vez tienen que dejar una sola transición, y `updated_at` es lo que dice
     * cuándo empezó la amistad.
     *
     * @return bool false si la fila no existe o ya estaba aceptada
     */
    public function aceptar(int $friendshipId): bool;

    /**
     * Borra la fila. Es lo que hacen **rechazar** y **deshacer**, y es la misma
     * operación a propósito: no hay estado `rejected`, así que rechazar no deja
     * historial y quien pidió puede volver a pedir. Ver `EstadoAmistad`.
     *
     * @return bool false si no había nada que borrar
     */
    public function eliminar(int $friendshipId): bool;

    /**
     * Todas las amistades de alguien —aceptadas y pendientes, pedidas y
     * recibidas— con la persona del otro lado ya resuelta.
     *
     * Va en **una sola consulta con `JOIN users`** y no en tres: son tres
     * filtros sobre el mismo conjunto, y partirlo obligaría a repetir la lista
     * blanca de columnas tres veces. Quien las separa en `friends`, `pending` y
     * `sent` es `ListarAmistades`, que es donde se puede leer el criterio.
     *
     * **`persona` es lista blanca y el `email` NO está en ella**, igual que en
     * el router público: `findByUsername()` devuelve el usuario entero, con su
     * correo dentro, y quien llama decide qué publica. Aquí se publica lo que
     * hace falta para pintar una fila de `/friends` y nada más.
     *
     * @return list<array{amistad: Amistad, persona: array{id: int, username: string, displayName: ?string, avatarUrl: ?string}}>
     */
    public function listarDe(int $userId): array;
}
