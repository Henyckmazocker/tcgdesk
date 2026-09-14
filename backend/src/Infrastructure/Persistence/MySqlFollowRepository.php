<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use App\Domain\Repository\FollowRepositoryInterface;
use PDO;

/**
 * Los marcadores en MySQL, sobre la tabla `user_follow` que creó la migración
 * `20260914_120000_friendships.sql` en el M1.
 *
 * Cuatro cosas que no son obvias y que, si se tocan, rompen algo que no se ve:
 *
 *  1. **Aquí no se une esta tabla con `friendships`, y no se puede.** Ni con un
 *     `OR`, ni con un `UNION`, ni «para el listado». El comentario de la propia
 *     tabla lo dice a todas letras —«Marcador unilateral sobre un perfil
 *     público. NO da ningún acceso»— y el motivo es que `Visibilidad` resuelve
 *     el nivel `friends` preguntando por `friendships` y solo por
 *     `friendships`: el día que estas dos filas se sumen en algún sitio, pulsar
 *     «seguir» pasará a dar acceso al nivel `friends` de alguien que no ha
 *     aceptado nada.
 *  2. **La clave es asimétrica A PROPÓSITO.** `PRIMARY KEY (follower_id,
 *     followed_id)`, sin `LEAST`/`GREATEST` y sin nada simétrico: A→B y B→A son
 *     dos hechos legítimos que conviven, al revés que en `friendships`, donde la
 *     fila cruzada es justo lo que el `UNIQUE` por columnas generadas hace
 *     imposible. Copiar aquí el `min`/`max` de `MySqlFriendshipRepository`
 *     haría que dejar de seguir a alguien te borrara a ti de sus seguidos.
 *  3. **`seguir()` no comprueba antes de insertar**, igual que
 *     `crearSolicitud()`: comprobar y luego escribir es una carrera. Lo que
 *     cambia respecto a la amistad es el desenlace —allí el `1062` sube hasta el
 *     controller y se traduce a un 409; aquí lo absorbe el `ON DUPLICATE KEY
 *     UPDATE` y la operación es idempotente—, y el porqué está escrito en
 *     `FollowRepositoryInterface::seguir()`.
 *  4. **El `JOIN users` es lista blanca y no trae ni el `email` ni el `id`.**
 *     La tabla tiene el correo de todo el mundo y esta consulta no lo
 *     selecciona: lo que no se trae no se publica por descuido. Es la misma
 *     disciplina del router público y del listado de amistades.
 *
 * Y una que sí es obvia pero cuesta una tarde: con `ATTR_EMULATE_PREPARES =
 * false` MySQL **no admite reutilizar un marcador nombrado** en dos puntos de
 * la misma sentencia. Ninguna consulta de este fichero lo necesita —cada id
 * aparece una sola vez—, y si alguna llega a necesitarlo, se repite el valor con
 * nombres distintos como hace `MySqlFriendshipRepository::listarDe()`.
 */
class MySqlFollowRepository implements FollowRepositoryInterface
{
    public function __construct(
        private readonly PDO $db
    ) {
    }

    /**
     * @inheritDoc
     */
    public function seguir(int $seguidor, int $seguido): bool
    {
        // `ON DUPLICATE KEY UPDATE created_at = created_at` es un no-op
        // deliberado: absorbe el `1062` de la PRIMARY KEY sin tocar la fila, así
        // que la fecha del marcador sigue siendo la del día que se puso y no la
        // del último doble clic. Y se elige sobre `INSERT IGNORE` porque
        // `IGNORE` degradaría a aviso TODOS los errores —el `1452` de una
        // foránea rota incluido— y devolvería un 200 sobre una fila inexistente.
        $stmt = $this->db->prepare(
            'INSERT INTO user_follow (follower_id, followed_id)
             VALUES (:seguidor, :seguido)
             ON DUPLICATE KEY UPDATE created_at = created_at'
        );

        $stmt->execute([
            'seguidor' => $seguidor,
            'seguido'  => $seguido,
        ]);

        // 1 = fila nueva. 0 = ya estaba y no se ha tocado nada. No hay un tercer
        // caso: el `UPDATE` no cambia ninguna columna, así que MySQL nunca
        // devuelve el 2 de «actualizada».
        return $stmt->rowCount() === 1;
    }

    /**
     * @inheritDoc
     */
    public function dejarDeSeguir(int $seguidor, int $seguido): bool
    {
        // Los dos ids en el WHERE, en este orden y sin ordenarlos: quitar el
        // marcador que YO puse sobre TI no puede tocar el que TÚ pusiste sobre
        // mí. Es la misma asimetría de la PRIMARY KEY.
        $stmt = $this->db->prepare(
            'DELETE FROM user_follow
              WHERE follower_id = :seguidor
                AND followed_id = :seguido'
        );

        $stmt->execute([
            'seguidor' => $seguidor,
            'seguido'  => $seguido,
        ]);

        return $stmt->rowCount() > 0;
    }

    /**
     * @inheritDoc
     */
    public function seguidosDe(int $userId): array
    {
        // LISTA BLANCA de columnas de `users`, y el `email` no está. El `id`
        // tampoco: el `username` es la clave pública con la que se navega al
        // perfil, y el entero de `users.id` solo serviría para recorrer.
        //
        // `JOIN` interno y no `LEFT`: las dos FK van con `ON DELETE CASCADE`,
        // así que un marcador huérfano no puede existir. Si alguna vez
        // apareciera, esconderlo es mejor que pintar una tarjeta sin nombre.
        $stmt = $this->db->prepare(
            'SELECT u.username, u.display_name, u.avatar_url, f.created_at
               FROM user_follow f
               JOIN users u
                 ON u.id = f.followed_id
              WHERE f.follower_id = :yo
              ORDER BY f.created_at DESC, u.username ASC'
        );

        $stmt->execute(['yo' => $userId]);

        $seguidos = [];

        foreach ($stmt->fetchAll() as $fila) {
            $seguidos[] = [
                'username'    => (string) $fila['username'],
                'displayName' => $fila['display_name'] ?? null,
                'avatarUrl'   => $fila['avatar_url'] ?? null,
                'since'       => isset($fila['created_at']) ? (string) $fila['created_at'] : null,
            ];
        }

        return $seguidos;
    }

    /**
     * @inheritDoc
     */
    public function contarSeguidoresDe(int $userId): int
    {
        // Un `COUNT(*)` y ningún `JOIN`: lo que sale de aquí es un número, y el
        // índice `idx_followed (followed_id)` existe exactamente para esto. Que
        // la consulta no toque `users` es lo que hace imposible devolver nombres
        // por descuido — ver el porqué en el puerto.
        $stmt = $this->db->prepare(
            'SELECT COUNT(*) FROM user_follow WHERE followed_id = :yo'
        );

        $stmt->execute(['yo' => $userId]);

        return (int) $stmt->fetchColumn();
    }
}
