<?php

declare(strict_types=1);

namespace Tests\Unit\Doubles;

use App\Domain\Model\User;
use App\Domain\Repository\UserRepositoryInterface;
use App\Domain\Social\Descubrimiento;
use RuntimeException;

/**
 * Usuarios de mentira, en memoria.
 *
 * Reproduce lo único que el perfil público le pide a la tabla `users` y que un
 * mock que solo devolviera lo que se le pone no probaría: que **`username` es
 * `UNIQUE` y su colación no distingue mayúsculas** (`utf8mb4_unicode_ci`), así
 * que `/user/HENYCKMA` y `/user/henyckma` son la misma persona. Si el doble
 * comparara literalmente, un fallo de mayúsculas en la ruta pública pasaría en
 * verde aquí y daría 404 contra MySQL.
 *
 * Y trae el `email` puesto siempre, a propósito: es lo que permite comprobar que
 * la respuesta pública **no** lo lleva.
 *
 * Desde el M6 reproduce además las dos cosas que el buscador de `/friends` le
 * pide a MySQL y que un mock corriente daría por buenas sin probar nada:
 *
 *  - **La ausencia de fila en `user_privacy_settings` significa `everyone`.** Es
 *    el `LEFT JOIN` con `COALESCE` de `MySqlUserRepository::buscarPorPrefijo()`,
 *    y es el caso de todo el mundo hoy. Un doble que exigiera tener el valor
 *    puesto haría pasar en verde exactamente el fallo que el plan avisa que
 *    rompe el hito en silencio: con un `INNER JOIN`, cero resultados siempre.
 *  - **`%` y `_` son comodines de `LIKE`.** El patrón se interpreta de verdad
 *    (ver `casaPrefijo()`), así que si alguien le quitara el escapado a
 *    `BuscarUsuarios`, `q = '%%%'` devolvería aquí el censo entero igual que
 *    contra MySQL, y el test lo cantaría.
 */
class UsuariosFalsos implements UserRepositoryInterface
{
    /** @var array<int, User> id → usuario */
    public array $usuarios = [];

    private int $siguienteId = 1;

    /** Da de alta a alguien y devuelve su id. */
    public function alta(string $username, ?string $displayName = null, ?string $avatarUrl = null): int
    {
        $id = $this->siguienteId++;

        $this->usuarios[$id] = new User(
            id:          $id,
            googleId:    'g-' . $id,
            email:       $username . '@example.test',
            username:    $username,
            displayName: $displayName,
            avatarUrl:   $avatarUrl,
            createdAt:   '2026-09-13 12:00:00',
        );

        return $id;
    }

    public function findByGoogleId(string $googleId): ?User
    {
        foreach ($this->usuarios as $usuario) {
            if ($usuario->googleId === $googleId) {
                return $usuario;
            }
        }

        return null;
    }

    public function findById(int $id): ?User
    {
        return $this->usuarios[$id] ?? null;
    }

    public function findByUsername(string $username): ?User
    {
        foreach ($this->usuarios as $usuario) {
            // `utf8mb4_unicode_ci`: la comparación de MySQL no distingue
            // mayúsculas y esta tampoco.
            if (mb_strtolower($usuario->username) === mb_strtolower($username)) {
                return $usuario;
            }
        }

        return null;
    }

    public function usernameExists(string $username): bool
    {
        return $this->findByUsername($username) !== null;
    }

    public function create(User $user): User
    {
        $id = $this->siguienteId++;

        $this->usuarios[$id] = new User(
            id:          $id,
            googleId:    $user->googleId,
            email:       $user->email,
            username:    $user->username,
            displayName: $user->displayName,
            avatarUrl:   $user->avatarUrl,
            createdAt:   '2026-09-13 12:00:00',
        );

        return $this->usuarios[$id];
    }

    public function updateProfileFromGoogle(int $id, ?string $displayName, ?string $avatarUrl): void
    {
        $usuario = $this->usuarios[$id] ?? throw new RuntimeException('No existe ese usuario.');

        $this->usuarios[$id] = new User(
            id:          $usuario->id,
            googleId:    $usuario->googleId,
            email:       $usuario->email,
            username:    $usuario->username,
            displayName: $displayName,
            avatarUrl:   $avatarUrl,
            createdAt:   $usuario->createdAt,
        );
    }

    /**
     * `user_id` → si sale en el buscador. **Vacío por defecto y eso significa
     * `everyone`**, no «no configurado»: ver la cabecera.
     *
     * @var array<int, Descubrimiento>
     */
    public array $busqueda = [];

    /** Saca a alguien del buscador, como haría su panel de privacidad. */
    public function escondeDelBuscador(int $id): self
    {
        $this->busqueda[$id] = Descubrimiento::Nadie;

        return $this;
    }

    /**
     * @inheritDoc
     */
    public function buscarPorPrefijo(string $prefijo, int $excluyendoId, int $limite): array
    {
        $encontrados = [];

        foreach ($this->usuarios as $usuario) {
            if ($usuario->id === $excluyendoId) {
                continue;
            }

            if (!$this->casaPrefijo($usuario->username, $prefijo)) {
                continue;
            }

            // El `COALESCE` del `WHERE`, en PHP: sin valor puesto, el defecto.
            $visible = $this->busqueda[$usuario->id] ?? Descubrimiento::porDefecto();

            if ($visible !== Descubrimiento::Todos) {
                continue;
            }

            $encontrados[] = [
                'username'    => $usuario->username,
                'displayName' => $usuario->displayName,
                'avatarUrl'   => $usuario->avatarUrl,
            ];
        }

        // `ORDER BY u.username`, y la colación no distingue mayúsculas.
        usort(
            $encontrados,
            static fn (array $a, array $b): int => strcmp(
                mb_strtolower($a['username']),
                mb_strtolower($b['username'])
            )
        );

        return array_slice($encontrados, 0, max(1, $limite));
    }

    /**
     * `username LIKE 'patron%'` como lo entiende MySQL, y **con sus comodines**.
     *
     * Se interpreta el patrón en vez de comparar con `str_starts_with()` a
     * propósito: lo que llega es lo que `BuscarUsuarios::escaparComodines()`
     * haya dejado, y la pregunta que este doble tiene que poder contestar es
     * qué pasaría contra MySQL si ese escapado no estuviera. Con una comparación
     * literal, `q = '%%%'` no encontraría a nadie aquí y devolvería la tabla
     * entera allí.
     *
     * `\` escapa al siguiente carácter (es el escape por defecto de `LIKE` en
     * MySQL, sin necesidad de cláusula `ESCAPE`); `%` casa cualquier cosa; `_`
     * casa un carácter. Todo lo demás es literal, y la comparación no distingue
     * mayúsculas porque la colación es `utf8mb4_unicode_ci`.
     */
    private function casaPrefijo(string $username, string $patron): bool
    {
        // `mb_str_split` y no indexar bytes: un nombre con acentos ocupa dos
        // bytes por letra y partirlo por la mitad dejaría medio carácter dentro
        // de `preg_quote()` y un patrón roto.
        $caracteres = mb_str_split($patron);
        $largo      = count($caracteres);
        $regex      = '';

        for ($i = 0; $i < $largo; $i++) {
            $c = $caracteres[$i];

            if ($c === '\\' && $i + 1 < $largo) {
                $regex .= preg_quote($caracteres[++$i], '/');
                continue;
            }

            $regex .= match ($c) {
                '%'     => '.*',
                '_'     => '.',
                default => preg_quote($c, '/'),
            };
        }

        // Anclado solo al principio: el `LIKE` real lleva un `%` al final que se
        // lo pone el repositorio, no quien llama.
        return (bool) preg_match('/^' . $regex . '/iu', $username);
    }
}
