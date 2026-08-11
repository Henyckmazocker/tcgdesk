<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use App\Domain\Model\User;
use App\Domain\Repository\UserRepositoryInterface;
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
}
