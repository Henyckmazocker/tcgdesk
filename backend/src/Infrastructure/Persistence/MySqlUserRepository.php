<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use App\Domain\Model\User;
use App\Domain\Repository\UserRepositoryInterface;
use App\Domain\Social\Descubrimiento;
use PDO;

class MySqlUserRepository implements UserRepositoryInterface
{
    public function __construct(
        private readonly PDO $db
    ) {
    }

    public function findByGoogleId(string $googleId): ?User
    {
        $stmt = $this->db->prepare('SELECT * FROM users WHERE google_id = :google_id LIMIT 1');
        $stmt->execute(['google_id' => $googleId]);

        $row = $stmt->fetch();

        return $row ? User::fromRow($row) : null;
    }

    public function findById(int $id): ?User
    {
        $stmt = $this->db->prepare('SELECT * FROM users WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $id]);

        $row = $stmt->fetch();

        return $row ? User::fromRow($row) : null;
    }

    public function findByUsername(string $username): ?User
    {
        $stmt = $this->db->prepare('SELECT * FROM users WHERE username = :username LIMIT 1');
        $stmt->execute(['username' => $username]);

        $row = $stmt->fetch();

        return $row ? User::fromRow($row) : null;
    }

    public function usernameExists(string $username): bool
    {
        $stmt = $this->db->prepare('SELECT 1 FROM users WHERE username = :username LIMIT 1');
        $stmt->execute(['username' => $username]);

        return $stmt->fetchColumn() !== false;
    }

    public function create(User $user): User
    {
        $stmt = $this->db->prepare(
            'INSERT INTO users (google_id, email, username, display_name, avatar_url)
             VALUES (:google_id, :email, :username, :display_name, :avatar_url)'
        );

        $stmt->execute([
            'google_id'    => $user->googleId,
            'email'        => $user->email,
            'username'     => $user->username,
            'display_name' => $user->displayName,
            'avatar_url'   => $user->avatarUrl,
        ]);

        return $this->findById((int) $this->db->lastInsertId())
            ?? throw new \RuntimeException('El usuario recién creado no se pudo releer.');
    }

    public function updateProfileFromGoogle(int $id, ?string $displayName, ?string $avatarUrl): void
    {
        $stmt = $this->db->prepare(
            'UPDATE users SET display_name = :display_name, avatar_url = :avatar_url WHERE id = :id'
        );

        $stmt->execute([
            'id'           => $id,
            'display_name' => $displayName,
            'avatar_url'   => $avatarUrl,
        ]);
    }

    /**
     * @inheritDoc
     */
    public function buscarPorPrefijo(string $prefijo, int $excluyendoId, int $limite): array
    {
        // ====================================================================
        // EL `LEFT JOIN` ES LO QUE HACE QUE ESTE BUSCADOR DEVUELVA ALGO
        // ====================================================================
        // **La ausencia de fila en `user_privacy_settings` SIGNIFICA «los
        // defectos»** —lo dice `MySqlUserPrivacyRepository::nivelesDe()`, lo
        // repite la cabecera de `20260913_120000_public_profile.sql` y lo vuelve
        // a repetir la de `20260914_180000_show_in_search.sql`— y **hoy no hay
        // ni una sola fila en esa tabla**: la escribe `privacy_set` la primera
        // vez que alguien toca el panel de privacidad.
        //
        // Con un `INNER JOIN`, esta consulta devolvería CERO RESULTADOS SIEMPRE,
        // que es exactamente lo contrario del defecto `everyone` que el M6
        // eligió, y lo haría **sin un solo error**: la consulta es válida, no
        // avisa nadie y el buscador simplemente no encuentra a nadie nunca.
        // Si alguien «limpia» este `LEFT`, eso es lo que pasa.
        //
        // El `COALESCE` es la otra mitad de lo mismo, y su defecto está
        // DUPLICADO respecto al `DEFAULT` de la columna y respecto a
        // `Descubrimiento::porDefecto()`. No hay forma de evitarlo: quien no
        // tiene fila nunca llega a leer el `DEFAULT`, y quien no pasa por PHP
        // —este `WHERE`— nunca llega a leer el enum. **Si se cambia uno, se
        // cambian los tres.**
        //
        // ====================================================================
        // Y las otras tres reglas de esta consulta
        // ====================================================================
        //  - **`LIKE :prefijo` con el comodín solo al final**: prefijo y no
        //    subcadena. Así usa el índice del `UNIQUE (username)`, y así el
        //    directorio no es enumerable desde cualquier letra interior. El
        //    `%` se concatena AQUÍ y no viaja dentro del parámetro por
        //    accidente: lo que llega de `BuscarUsuarios` ya viene con `%`, `_`
        //    y `\` escapados, o `q = '%%%'` devolvería el censo entero.
        //  - **Lista blanca de columnas, y el `email` NO está.** Tampoco el
        //    `id`: a un perfil se llega por `username`, que es la clave pública
        //    del proyecto.
        //  - **El `ORDER BY` es por `username`** y no por `created_at` ni por
        //    id: es determinista, es el orden que el índice ya tiene, y no
        //    filtra en qué orden se dio de alta la gente.
        //
        // El `LIMIT` va interpolado como entero —`(int)`— y no como marcador,
        // porque con `ATTR_EMULATE_PREPARES = false` PDO manda los parámetros
        // como cadenas y MySQL rechaza `LIMIT '20'`. El casting es lo que lo
        // hace seguro, y el valor lo fija `BuscarUsuarios`, nunca el cliente.
        $stmt = $this->db->prepare(
            'SELECT u.username, u.display_name, u.avatar_url
               FROM users u
               LEFT JOIN user_privacy_settings p ON p.user_id = u.id
              WHERE u.username LIKE :prefijo
                AND u.id <> :yo
                AND COALESCE(p.' . Descubrimiento::COLUMNA . ', :defecto) = :visible
              ORDER BY u.username
              LIMIT ' . max(1, (int) $limite)
        );

        $stmt->execute([
            'prefijo' => $prefijo . '%',
            'yo'      => $excluyendoId,
            // El MISMO valor con DOS nombres de marcador: con
            // `ATTR_EMULATE_PREPARES = false` MySQL no admite reutilizar un
            // marcador nombrado en dos puntos de la misma sentencia, y
            // `everyone` aparece en los dos lados de la igualdad.
            'defecto' => Descubrimiento::porDefecto()->value,
            'visible' => Descubrimiento::Todos->value,
        ]);

        $encontrados = [];

        foreach ($stmt->fetchAll() as $fila) {
            $encontrados[] = [
                'username'    => (string) $fila['username'],
                'displayName' => $fila['display_name'] ?? null,
                'avatarUrl'   => $fila['avatar_url'] ?? null,
            ];
        }

        return $encontrados;
    }
}
