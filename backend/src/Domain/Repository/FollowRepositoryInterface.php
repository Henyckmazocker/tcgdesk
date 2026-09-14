<?php

declare(strict_types=1);

namespace App\Domain\Repository;

/**
 * Quién sigue a quién: los marcadores de `user_follow`.
 *
 * **Este puerto no decide ningún permiso, y esa es su única característica
 * importante.** Es la mitad del Plan - Amigos y Seguimiento que existe para
 * NO dar acceso: seguir a alguien es guardarse su perfil para volver a él, como
 * un marcador del navegador, y lo que ese perfil enseña después es exactamente
 * lo mismo que enseñaba antes. Quien decide qué se ve es
 * `App\Domain\Social\Visibilidad`, preguntando a `UserPrivacyRepositoryInterface`
 * —qué tiene puesto— y a `FriendshipRepositoryInterface` —si sois amigos—, y a
 * nadie más.
 *
 * **Por eso este puerto no aparece en `Visibilidad`, ni en el constructor de
 * `PublicHttpRouter`, ni en ninguna consulta que decida si algo se enseña.** Si
 * algún día aparece, el nivel `friends` habrá pasado a significar «cualquiera
 * que pulse seguir», o sea, `everyone`, y los dos datos que el plan protege
 * —cuánto vale tu colección y qué te falta— se habrán abierto a todo el mundo
 * sin que nadie haya decidido abrirlos. Es el fallo que el plan nombra dos
 * veces y el motivo entero de que seguir sea un hito aparte.
 *
 * **Y es un puerto distinto del de amistad, no un método más allí.** Las dos
 * tablas responden preguntas que no se parecen en nada de lo que importa:
 *
 * |            | Amistad             | Seguir                               |
 * |------------|---------------------|--------------------------------------|
 * | Dirección  | Recíproca           | Unilateral                           |
 * | Permiso    | Hay que aceptar     | No se pide                           |
 * | Acceso     | Nivel `friends`     | **Ninguno**                          |
 * | Clave      | `UNIQUE` simétrico  | `PK(follower, followed)`, asimétrica |
 * | Estados    | `pending`/`accepted`| No tiene                             |
 *
 * Un solo puerto con los dos conjuntos de métodos sería un sitio donde escribir
 * el `OR` que los une sin darse cuenta. Dos puertos hacen falta cambiar dos
 * ficheros y un registro del contenedor para conseguir el mismo daño, que es
 * suficiente fricción para que alguien se pregunte por qué.
 *
 * **Cuatro métodos y ni uno más, también a propósito.** Todo lo que este puerto
 * no sepa responder es una consulta que no se puede escribir: no hay un
 * `seguidoresDe()` que devuelva NOMBRES —quién te sigue no se publica, porque
 * seguir es un acto privado del que sigue y el seguido no lo autoriza— sino un
 * `contarSeguidoresDe()` que devuelve un entero, y no hay ningún método que
 * cruce esta tabla con `friendships`.
 */
interface FollowRepositoryInterface
{
    /**
     * Deja el marcador. **Es idempotente: seguir dos veces no es un error.**
     *
     * La `PRIMARY KEY (follower_id, followed_id)` haría `1062` en el segundo
     * `INSERT`, y traducir eso a un 409 —como sí hace `friend_request`— estaría
     * mal aquí: un 409 dice «hay un conflicto que alguien tiene que resolver», y
     * en una amistad lo hay (una solicitud pendiente esperando a que la acepten
     * o la rechacen). Aquí no hay nada que resolver. El estado que el usuario
     * pidió —«quiero seguir a esta persona»— ya es el estado actual, así que la
     * petición **se cumplió**: dos toques en el botón de un móvil con mala
     * cobertura no pueden dar un error rojo por haber acertado dos veces.
     *
     * Lo resuelve el esquema con un `ON DUPLICATE KEY UPDATE` que no cambia
     * nada, y **no un `INSERT IGNORE`**: `IGNORE` se tragaría también el `1452`
     * de una clave foránea rota —seguir a alguien que acaba de borrar su
     * cuenta— y lo convertiría en un 200 silencioso sobre una fila que no
     * existe.
     *
     * @return bool true si el marcador es nuevo, false si ya estaba puesto
     * @throws \PDOException `1452` si el seguido dejó de existir entre que se
     *         resolvió su `username` y este `INSERT`. Es una carrera rarísima y
     *         un 500 honesto; disfrazarla de 404 escondería un fallo real.
     */
    public function seguir(int $seguidor, int $seguido): bool;

    /**
     * Quita el marcador. **También es idempotente**, por lo mismo: dejar de
     * seguir a quien no sigues deja las cosas como el usuario quería que
     * estuvieran.
     *
     * @return bool false si no había marcador que quitar
     */
    public function dejarDeSeguir(int $seguidor, int $seguido): bool;

    /**
     * A quién sigues, con la persona ya resuelta por el `JOIN users`.
     *
     * **`email` no viaja, y el `id` numérico tampoco**, igual que en el listado
     * de amistades y en el router público: `username` es la clave pública del
     * proyecto —es con lo que se llega al perfil— y publicar además el entero de
     * `users.id` daría un diccionario de ids reales por el que empezar a
     * recorrer. La consulta ni siquiera los selecciona: lo que no se trae no se
     * puede publicar por descuido.
     *
     * El `JOIN` es interno a propósito: las dos FK van con `ON DELETE CASCADE`,
     * así que un marcador sin persona al otro lado no puede existir.
     *
     * @return list<array{username: string, displayName: ?string, avatarUrl: ?string, since: ?string}>
     */
    public function seguidosDe(int $userId): array;

    /**
     * Cuánta gente te sigue. **Un entero, nunca una lista.**
     *
     * El plan lo decide explícitamente: el seguido ve el número y no puede
     * impedir que suba —la herramienta para no ser seguido es bajar las
     * secciones a `friends` o `nobody`—, pero los nombres no se publican. Seguir
     * es unilateral y no se pide permiso; devolver la lista convertiría un acto
     * privado de quien sigue en algo que el seguido audita, que es otra relación
     * distinta de la que este plan diseñó.
     */
    public function contarSeguidoresDe(int $userId): int;
}
