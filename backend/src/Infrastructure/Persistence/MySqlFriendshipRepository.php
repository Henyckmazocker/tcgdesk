<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use App\Domain\Repository\FriendshipRepositoryInterface;
use App\Domain\Social\Amistad;
use App\Domain\Social\EstadoAmistad;
use PDO;

/**
 * Las amistades en MySQL, sobre la tabla que creó la migración
 * `20260914_120000_friendships.sql`.
 *
 * Cuatro cosas que no son obvias y que, si se tocan, rompen algo que no se ve:
 *
 *  1. **`sonAmigos()` pregunta por `status = 'accepted'` y por nada más.** Una
 *     solicitud `pending` no es una amistad. Quitar esa condición —o cambiarla
 *     por un `IN ('pending','accepted')` «por si acaso»— hace que *pedir*
 *     amistad a alguien baste para ver lo que tenga en el nivel `friends`, sin
 *     que esa persona haya aceptado nada. Es el fallo que el Plan - Amigos y
 *     Seguimiento señala como el más fácil de escribir y el más difícil de ver,
 *     porque funciona perfectamente en toda prueba manual: quien prueba acepta
 *     la solicitud.
 *  2. **La pareja se ordena en PHP con `min`/`max`, no con `LEAST`/`GREATEST`
 *     en el `WHERE`.** El resultado es el mismo —`user_low` y `user_high` son
 *     exactamente esas dos funciones, calculadas por MySQL— pero así la consulta
 *     compara la clave contra dos constantes, que es lo que el índice
 *     `uq_pareja` sabe resolver, y de paso no hay que repetir cada id en dos
 *     marcadores: con `ATTR_EMULATE_PREPARES = false` **MySQL no admite
 *     reutilizar un marcador nombrado** en dos puntos de la misma sentencia.
 *     Donde ese problema sí aparece es en `listarDe()`, y allí está resuelto
 *     como manda el repo: el mismo valor con tres nombres distintos.
 *  3. **`user_low` y `user_high` NO se insertan.** Son columnas generadas
 *     `VIRTUAL` y un `INSERT` que las mencione falla. Se calculan solas.
 *  4. **Aquí no se decide quién puede hacer qué.** `aceptar()` y `eliminar()`
 *     reciben un id y obedecen; el permiso lo miran los use cases sobre la
 *     `Amistad` que devuelve `buscar()`. El `status = 'pending'` que sí lleva
 *     `aceptar()` no es un permiso sino una guarda de concurrencia: dos
 *     aceptaciones simultáneas tienen que dejar una sola transición.
 *
 * Y lo que **no** hay en este fichero, en ninguna consulta: la tabla
 * `user_follow`. Seguir no da acceso a nada, y unir las dos tablas con un `OR`
 * convertiría el nivel `friends` en `everyone`.
 */
class MySqlFriendshipRepository implements FriendshipRepositoryInterface
{
    public function __construct(
        private readonly PDO $db
    ) {
    }

    /**
     * @inheritDoc
     */
    public function sonAmigos(int $a, int $b): bool
    {
        // Nadie es amigo de sí mismo, y la fila (A,A) es teóricamente posible
        // —el UNIQUE simétrico no la impide, por eso `PedirAmistad` la rechaza
        // con un 422—. Cortar aquí evita que una fila así, si alguna vez
        // entrara a mano, se convirtiera en un permiso.
        if ($a === $b) {
            return false;
        }

        $stmt = $this->db->prepare(
            'SELECT 1
               FROM friendships
              WHERE user_low  = :bajo
                AND user_high = :alto
                AND status    = :aceptada
              LIMIT 1'
        );

        $stmt->execute([
            'bajo'     => min($a, $b),
            'alto'     => max($a, $b),
            'aceptada' => EstadoAmistad::Aceptada->value,
        ]);

        return $stmt->fetchColumn() !== false;
    }

    /**
     * @inheritDoc
     */
    public function crearSolicitud(int $solicitante, int $destinatario): int
    {
        // `status` no se menciona: el DEFAULT de la tabla es 'pending' y es el
        // único estado con el que puede nacer una fila. Escribirlo aquí sería
        // dar a entender que hay elección.
        $stmt = $this->db->prepare(
            'INSERT INTO friendships (requester_id, addressee_id)
             VALUES (:solicitante, :destinatario)'
        );

        $stmt->execute([
            'solicitante'  => $solicitante,
            'destinatario' => $destinatario,
        ]);

        return (int) $this->db->lastInsertId();
    }

    /**
     * @inheritDoc
     */
    public function buscar(int $friendshipId): ?Amistad
    {
        $stmt = $this->db->prepare(
            'SELECT id, requester_id, addressee_id, status, created_at
               FROM friendships
              WHERE id = :id
              LIMIT 1'
        );

        $stmt->execute(['id' => $friendshipId]);

        $fila = $stmt->fetch();

        return $fila === false ? null : Amistad::fromRow($fila);
    }

    /**
     * @inheritDoc
     */
    public function aceptar(int $friendshipId): bool
    {
        $stmt = $this->db->prepare(
            'UPDATE friendships
                SET status = :aceptada
              WHERE id     = :id
                AND status = :pendiente'
        );

        $stmt->execute([
            'aceptada'  => EstadoAmistad::Aceptada->value,
            'id'        => $friendshipId,
            'pendiente' => EstadoAmistad::Pendiente->value,
        ]);

        return $stmt->rowCount() === 1;
    }

    /**
     * @inheritDoc
     */
    public function eliminar(int $friendshipId): bool
    {
        $stmt = $this->db->prepare('DELETE FROM friendships WHERE id = :id');

        $stmt->execute(['id' => $friendshipId]);

        return $stmt->rowCount() > 0;
    }

    /**
     * @inheritDoc
     */
    public function listarDe(int $userId): array
    {
        // El MISMO valor con TRES nombres de marcador. Con
        // `ATTR_EMULATE_PREPARES = false` MySQL no admite reutilizar un marcador
        // nombrado en dos puntos de la misma sentencia, y aquí el usuario
        // aparece en tres: el lado del JOIN que decide quién es «el otro», y las
        // dos ramas del WHERE.
        //
        // El `IF` del JOIN es lo que hace que esto sea una consulta y no dos:
        // trae siempre a la persona del otro lado, la haya pedido ella o yo. Y
        // el JOIN es interno a propósito —no `LEFT`—: una fila de `friendships`
        // sin usuario al otro lado no puede existir, porque las dos FK van con
        // `ON DELETE CASCADE`. Si algún día apareciera, esconderla es mejor que
        // pintar una tarjeta sin nombre.
        //
        // Las columnas de `users` son LISTA BLANCA y el `email` no está: la
        // tabla lo tiene y este listado no lo publica, igual que el router
        // público. Que no se pueda «añadir un campo» sin escribirlo aquí es
        // justamente el punto.
        $stmt = $this->db->prepare(
            'SELECT f.id, f.requester_id, f.addressee_id, f.status, f.created_at,
                    u.id AS otro_id, u.username, u.display_name, u.avatar_url
               FROM friendships f
               JOIN users u
                 ON u.id = IF(f.requester_id = :yo_lado, f.addressee_id, f.requester_id)
              WHERE f.requester_id = :yo_solicitante
                 OR f.addressee_id = :yo_destinatario
              ORDER BY f.created_at DESC, f.id DESC'
        );

        $stmt->execute([
            'yo_lado'         => $userId,
            'yo_solicitante'  => $userId,
            'yo_destinatario' => $userId,
        ]);

        $amistades = [];

        foreach ($stmt->fetchAll() as $fila) {
            $amistades[] = [
                'amistad' => Amistad::fromRow($fila),
                'persona' => [
                    'id'          => (int) $fila['otro_id'],
                    'username'    => (string) $fila['username'],
                    'displayName' => $fila['display_name'] ?? null,
                    'avatarUrl'   => $fila['avatar_url'] ?? null,
                ],
            ];
        }

        return $amistades;
    }
}
