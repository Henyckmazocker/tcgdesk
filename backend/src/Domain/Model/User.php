<?php

declare(strict_types=1);

namespace App\Domain\Model;

/**
 * Usuario de TCGDesk.
 *
 * `username` es público y permanente: es la URL de perfil que habilitará la capa
 * social (`/user/:username`). Por eso se pide en el primer login y no se deriva
 * del email — ver [[TCGDesk/Base de Datos]] zona 3.
 */
final class User
{
    public function __construct(
        public readonly ?int $id,
        public readonly string $googleId,
        public readonly string $email,
        public readonly string $username,
        public readonly ?string $displayName = null,
        public readonly ?string $avatarUrl = null,
        public readonly ?string $createdAt = null,
    ) {
    }

    public static function fromRow(array $row): self
    {
        return new self(
            id:          (int) $row['id'],
            googleId:    (string) $row['google_id'],
            email:       (string) $row['email'],
            username:    (string) $row['username'],
            displayName: $row['display_name'] ?? null,
            avatarUrl:   $row['avatar_url'] ?? null,
            createdAt:   $row['created_at'] ?? null,
        );
    }

    /** Forma pública, la que sale al cliente. Nunca incluye `google_id`. */
    public function toArray(): array
    {
        return [
            'id'           => $this->id,
            'email'        => $this->email,
            'username'     => $this->username,
            'display_name' => $this->displayName,
            'avatar_url'   => $this->avatarUrl,
            'created_at'   => $this->createdAt,
        ];
    }
}
