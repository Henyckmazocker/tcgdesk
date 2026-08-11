<?php

declare(strict_types=1);

namespace App\Infrastructure\RateLimit;

/**
 * Client IP resolver
 *
 * Resolves the real client IP taking proxies and Cloudflare into account.
 * Producción irá detrás de Cloudflare Tunnel + Nginx, que propagan
 * CF-Connecting-IP, X-Real-IP y X-Forwarded-For al backend.
 */
final class ClientIp
{
    /** @var string[] Header lookup order (most trusted proxy headers first) */
    private const HEADERS = [
        'HTTP_CF_CONNECTING_IP',     // Cloudflare
        'HTTP_CLIENT_IP',            // Proxy
        'HTTP_X_FORWARDED_FOR',      // Load Balancer/Proxy
        'HTTP_X_FORWARDED',          // Proxy
        'HTTP_X_CLUSTER_CLIENT_IP',  // Cluster
        'HTTP_FORWARDED_FOR',        // Proxy
        'HTTP_FORWARDED',            // Proxy
        'REMOTE_ADDR',               // Standard
    ];

    /**
     * Resolve the client IP, or null if it cannot be determined.
     */
    public static function resolve(): ?string
    {
        foreach (self::HEADERS as $header) {
            if (empty($_SERVER[$header])) {
                continue;
            }

            // X-Forwarded-For may be a comma-separated list; the first entry is
            // the original client.
            $ips = explode(',', (string) $_SERVER[$header]);
            $ip  = trim($ips[0]);

            if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                return $ip;
            }
        }

        return $_SERVER['REMOTE_ADDR'] ?? null;
    }
}
