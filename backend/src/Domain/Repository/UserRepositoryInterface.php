<?php

declare(strict_types=1);

namespace App\Domain\Repository;

use App\Domain\Model\User;

interface UserRepositoryInterface
{
    public function findByGoogleId(string $googleId): ?User;

    public function findById(int $id): ?User;

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
}
