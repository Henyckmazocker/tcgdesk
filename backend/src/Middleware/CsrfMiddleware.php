<?php

declare(strict_types=1);

namespace App\Middleware;

use Psr\Log\LoggerInterface;

/**
 * CSRF Protection Middleware
 * Validates CSRF token for state-changing operations
 */
class CsrfMiddleware implements MiddlewareInterface
{
    public function __construct(
        private readonly LoggerInterface $logger
    ) {
    }

    public function handle(array $request, callable $next): array
    {
        // JWT-authenticated requests (mobile/Capacitor) don't use session cookies,
        // so CSRF protection via session token is not applicable — skip it.
        if (($request['auth_method'] ?? '') === 'jwt') {
            $this->logger->debug('CSRF skipped for JWT auth', [
                'action' => $request['action'] ?? 'unknown',
            ]);
            return $next($request);
        }

        // Session is already started in Application::bootstrap()
        $csrfToken = $request['csrf_token'] ?? null;

        if (!isset($_SESSION['csrf_token'])
            || !is_string($csrfToken)
            || !hash_equals($_SESSION['csrf_token'], $csrfToken)
        ) {
            $this->logger->warning('CSRF validation failed', [
                'action'         => $request['action'] ?? 'unknown',
                'user_id'        => $_SESSION['user_data']['id'] ?? 'unknown',
                'provided_token' => $csrfToken ? 'present' : 'missing',
                'ip'             => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
            ]);

            return [
                'status'    => 'error',
                'message'   => 'Invalid CSRF token. Please refresh and try again.',
                'http_code' => 403,
            ];
        }

        $this->logger->debug('CSRF token validated', [
            'action'  => $request['action'] ?? 'unknown',
            'user_id' => $_SESSION['user_data']['id'] ?? 'unknown',
        ]);

        return $next($request);
    }
}
