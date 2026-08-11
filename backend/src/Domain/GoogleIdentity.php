<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * Lo que Google afirma de una persona, ya verificado.
 *
 * Existe para que `AuthController` no dependa de la forma del ID token de Google
 * y para que el doble de test del cliente sea trivial de escribir.
 */
final class GoogleIdentity
{
    public function __construct(
        public readonly string $googleId,
        public readonly string $email,
        public readonly ?string $displayName = null,
        public readonly ?string $avatarUrl = null,
    ) {
    }
}
