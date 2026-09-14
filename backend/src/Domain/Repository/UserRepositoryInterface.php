<?php

declare(strict_types=1);

namespace App\Domain\Repository;

use App\Domain\Model\User;

interface UserRepositoryInterface
{
    public function findByGoogleId(string $googleId): ?User;

    public function findById(int $id): ?User;

    /**
     * Busca por el nombre público, que es lo que resuelve `/user/:username`.
     *
     * Existe desde el Plan - Perfil Público y Mazos Compartibles: hasta él, el
     * `username` solo se comprobaba (`usernameExists()`) pero nunca se usaba para
     * llegar a nadie. La columna es `UNIQUE`, así que o hay uno o no hay ninguno;
     * y su colación es `utf8mb4_unicode_ci`, así que la búsqueda **no distingue
     * mayúsculas** — que es lo que hay que querer en una URL que la gente teclea.
     *
     * Devuelve el usuario ENTERO, con su correo dentro: quien lo llama decide qué
     * publica. En la ruta pública eso es `display_name` y `avatar_url`, y el
     * `email` **jamás**.
     */
    public function findByUsername(string $username): ?User;

    public function usernameExists(string $username): bool;

    /**
     * Da de alta al usuario y devuelve la fila ya con su id.
     */
    public function create(User $user): User;

    /**
     * Refresca los datos que Google puede haber cambiado desde el último login.
     * NO toca `username`: es del usuario, no de Google.
     */
    public function updateProfileFromGoogle(int $id, ?string $displayName, ?string $avatarUrl): void;

    /**
     * Los usuarios cuyo `username` **empieza por** este prefijo, filtrados por
     * su propia privacidad. Es el buscador de `/friends` (M6 del
     * Plan - Amigos y Seguimiento).
     *
     * Devuelve **lista blanca y no objetos `User`**, y esa es la diferencia con
     * `findByUsername()`: aquel devuelve la fila entera —con el `email` dentro—
     * y deja que quien llama decida qué publica, porque resuelve UNA persona que
     * ya se conocía por su nombre. Esto resuelve N personas que el que busca
     * **no** conocía, así que el email no llega ni a salir de la consulta: las
     * columnas que se seleccionan son `username`, `display_name` y `avatar_url`,
     * y que no se pueda «añadir un campo» sin escribirlo en el SQL es el punto.
     *
     * Tres cosas del contrato que la implementación tiene que cumplir y que un
     * test no puede adivinar leyendo la firma:
     *
     *  - **Prefijo, no subcadena.** `LIKE 'juan%'` usa el índice del `UNIQUE` de
     *    `users.username`; `LIKE '%juan%'` es un full scan y además haría el
     *    directorio enumerable desde cualquier letra interior. El prefijo llega
     *    ya escapado (`%`, `_` y `\`) por `BuscarUsuarios`.
     *  - **`$excluyendoId` se excluye siempre.** Es quien busca: verse a uno
     *    mismo en la lista de «gente a la que pedir amistad» ofrece un botón que
     *    el backend contesta con un 422.
     *  - **Y quien tenga `show_in_search` en `nobody` no sale.** El filtro va en
     *    el `WHERE` de esta misma consulta y no en un bucle de PHP, porque un
     *    buscador resuelve N candidatos: preguntar por cada uno sería una
     *    consulta por resultado. **Con `LEFT JOIN` y `COALESCE`**, porque la
     *    ausencia de fila en `user_privacy_settings` significa el defecto y hoy
     *    no hay ni una fila — con un `INNER JOIN` esto devolvería cero
     *    resultados siempre, y sin ningún error.
     *
     * La colación es `utf8mb4_unicode_ci`, así que buscar `JUAN` encuentra a
     * `juanita` sin que haya que normalizar nada, que es lo que hay que querer
     * en un buscador.
     *
     * @param  string $prefijo      ya recortado, validado y escapado para LIKE
     * @param  int    $excluyendoId quien busca, que nunca sale en su resultado
     * @param  int    $limite       tope de filas; lo fija `BuscarUsuarios`
     * @return list<array{username: string, displayName: ?string, avatarUrl: ?string}>
     */
    public function buscarPorPrefijo(string $prefijo, int $excluyendoId, int $limite): array;
}
