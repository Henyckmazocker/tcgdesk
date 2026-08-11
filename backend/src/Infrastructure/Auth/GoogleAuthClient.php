<?php

declare(strict_types=1);

namespace App\Infrastructure\Auth;

use App\Domain\GoogleAuthClientInterface;
use App\Domain\GoogleIdentity;
use Firebase\JWT\JWK;
use Firebase\JWT\JWT;
use GuzzleHttp\ClientInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

/**
 * Verifica los ID tokens de Google contra sus claves públicas (JWKS).
 *
 * Se hace con firebase/php-jwt + Guzzle en vez de con `google/apiclient` porque
 * la única pieza que hacía falta de esa librería era esto, y arrastra decenas de
 * dependencias. La verificación es la estándar: firma contra JWKS, `iss` de
 * Google, `aud` igual a nuestro Client ID, y `exp` no caducado (lo comprueba
 * `JWT::decode`).
 *
 * OJO: es la ÚNICA salida a internet del backend en tiempo de petición. El
 * catálogo y los precios no salen a la red nunca (decisión 1 de
 * [[TCGDesk/Decisiones Técnicas]]); las claves de Google sí, y se cachean.
 */
class GoogleAuthClient implements GoogleAuthClientInterface
{
    private const JWKS_URL   = 'https://www.googleapis.com/oauth2/v3/certs';
    private const ISSUERS    = ['https://accounts.google.com', 'accounts.google.com'];
    private const CACHE_TTL  = 3600;

    private string $clientId;
    private string $cacheDir;

    public function __construct(
        private readonly ClientInterface $http,
        private readonly LoggerInterface $logger,
        ?string $cacheDir = null
    ) {
        $clientId = $_ENV['GOOGLE_CLIENT_ID'] ?? '';

        if ($clientId === '' || str_starts_with($clientId, 'PENDIENTE')) {
            throw new RuntimeException(
                'GOOGLE_CLIENT_ID no está configurado. Crea uno en Google Cloud Console (tipo "aplicación web").'
            );
        }

        $this->clientId = $clientId;
        $this->cacheDir = $cacheDir ?? ($_ENV['CACHE_PATH'] ?? __DIR__ . '/../../../storage/cache');
    }

    public function verifyIdToken(string $idToken): ?GoogleIdentity
    {
        try {
            $keys    = JWK::parseKeySet($this->fetchJwks());
            $payload = (array) JWT::decode($idToken, $keys);
        } catch (Throwable $e) {
            // Un token caducado o manipulado es funcionamiento normal, no una avería.
            $this->logger->info('Google ID token rechazado', ['reason' => $e->getMessage()]);
            return null;
        }

        if (!in_array($payload['iss'] ?? '', self::ISSUERS, true)) {
            $this->logger->warning('Google ID token con emisor inesperado', ['iss' => $payload['iss'] ?? null]);
            return null;
        }

        if (($payload['aud'] ?? '') !== $this->clientId) {
            // Token legítimo de Google pero emitido para OTRA aplicación.
            $this->logger->warning('Google ID token con audiencia ajena', ['aud' => $payload['aud'] ?? null]);
            return null;
        }

        if (empty($payload['sub']) || empty($payload['email'])) {
            $this->logger->warning('Google ID token sin sub o email');
            return null;
        }

        if (($payload['email_verified'] ?? false) !== true) {
            $this->logger->warning('Google ID token con email sin verificar', ['email' => $payload['email']]);
            return null;
        }

        return new GoogleIdentity(
            googleId:    (string) $payload['sub'],
            email:       (string) $payload['email'],
            displayName: $payload['name'] ?? null,
            avatarUrl:   $payload['picture'] ?? null,
        );
    }

    /**
     * Las claves de Google rotan pero no a cada minuto: se cachean una hora en
     * disco para no salir a la red en cada login.
     */
    private function fetchJwks(): array
    {
        $cacheFile = $this->cacheDir . '/google_jwks.json';

        if (is_file($cacheFile) && (time() - filemtime($cacheFile)) < self::CACHE_TTL) {
            $cached = json_decode((string) file_get_contents($cacheFile), true);
            if (is_array($cached) && isset($cached['keys'])) {
                return $cached;
            }
        }

        $response = $this->http->request('GET', self::JWKS_URL, ['timeout' => 5]);
        $jwks     = json_decode((string) $response->getBody(), true);

        if (!is_array($jwks) || !isset($jwks['keys'])) {
            throw new RuntimeException('Respuesta JWKS de Google inesperada.');
        }

        if (!is_dir($this->cacheDir)) {
            mkdir($this->cacheDir, 0775, true);
        }
        file_put_contents($cacheFile, json_encode($jwks));

        return $jwks;
    }
}
