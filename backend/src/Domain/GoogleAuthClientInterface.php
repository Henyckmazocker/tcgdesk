<?php

declare(strict_types=1);

namespace App\Domain;

interface GoogleAuthClientInterface
{
    /**
     * Verifica un ID token de Google y devuelve la identidad, o null si el token
     * no es válido (firma, caducidad, emisor o audiencia).
     */
    public function verifyIdToken(string $idToken): ?GoogleIdentity;
}
