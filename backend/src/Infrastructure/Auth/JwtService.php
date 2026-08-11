<?php

declare(strict_types=1);

namespace App\Infrastructure\Auth;

use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

/**
 * Emisión y verificación de los JWT propios de TCGDesk.
 *
 * Los usa el cliente móvil (Capacitor), que no tiene cookie de sesión: manda
 * `Authorization: Bearer <jwt>` y AuthMiddleware lo valida aquí.
 *
 * Nace en M2 —no en M4— porque AuthMiddleware no se puede construir sin él.
 * M4 añade encima el login de Google que emite estos tokens.
 */
class JwtService
{
    private const ALGORITHM = 'HS256';

    private string $secret;
    private int $ttl;

    public function __construct(
        private readonly LoggerInterface $logger
    ) {
        $secret = $_ENV['JWT_SECRET'] ?? '';

        if ($secret === '' || str_starts_with($secret, 'CAMBIAME')) {
            throw new RuntimeException(
                'JWT_SECRET no está configurado. Genera uno con: openssl rand -base64 32'
            );
        }

        $this->secret = $secret;
        $this->ttl    = (int) ($_ENV['JWT_EXPIRATION'] ?? 3600);
    }

    /**
     * Emite un token para un usuario ya autenticado.
     */
    public function issue(int $userId, array $claims = []): string
    {
        $now = time();

        $payload = $claims + [
            'iss'     => 'tcgdesk',
            'iat'     => $now,
            'exp'     => $now + $this->ttl,
            'user_id' => $userId,
        ];

        return JWT::encode($payload, $this->secret, self::ALGORITHM);
    }

    /**
     * Valida un token y devuelve su payload, o null si no es válido.
     *
     * Devolver null en vez de lanzar es deliberado: un token caducado es un caso
     * normal de funcionamiento, no una excepción.
     */
    public function validate(string $token): ?array
    {
        try {
            $decoded = JWT::decode($token, new Key($this->secret, self::ALGORITHM));
            return (array) $decoded;
        } catch (Throwable $e) {
            $this->logger->debug('JWT validation failed', ['reason' => $e->getMessage()]);
            return null;
        }
    }
}
