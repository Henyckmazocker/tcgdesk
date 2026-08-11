<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Infrastructure\Auth\JwtService;
use Psr\Log\LoggerInterface;

/**
 * Authentication Middleware
 * Verifies that user is authenticated before proceeding.
 * Supports two methods:
 *   1. PHP session cookie (web browser)
 *   2. Authorization: Bearer <jwt> header (mobile / Capacitor)
 */
class AuthMiddleware implements MiddlewareInterface
{
    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly JwtService $jwtService
    ) {
    }

    public function handle(array $request, callable $next): array
    {
        // --- 1. Session-based auth (web) ---
        if (isset($_SESSION['user_data']['id'])) {
            $request['user_id']     = (int) $_SESSION['user_data']['id'];
            $request['auth_method'] = 'session';
            return $next($request);
        }

        // --- 2. JWT-based auth (mobile / Capacitor) ---
        // Apache may pass the header as HTTP_AUTHORIZATION, REDIRECT_HTTP_AUTHORIZATION,
        // or via getallheaders() depending on the PHP SAPI / mod_rewrite config.
        $authHeader = $_SERVER['HTTP_AUTHORIZATION']
            ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION']
            ?? '';

        if (empty($authHeader) && function_exists('getallheaders')) {
            $headers    = array_change_key_case(getallheaders(), CASE_LOWER);
            $authHeader = $headers['authorization'] ?? '';
        }

        if (str_starts_with($authHeader, 'Bearer ')) {
            $token   = substr($authHeader, 7);
            $payload = $this->jwtService->validate($token);

            if ($payload !== null && isset($payload['user_id'])) {
                $request['user_id']     = (int) $payload['user_id'];
                $request['auth_method'] = 'jwt';

                $this->logger->debug('User authenticated via JWT', [
                    'user_id' => $payload['user_id'],
                    'action'  => $request['action'] ?? 'unknown',
                ]);

                return $next($request);
            }
        }

        // --- Authentication failed ---
        $this->logger->warning('Authentication failed - No user session', [
            'action' => $request['action'] ?? 'unknown',
            'ip'     => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
        ]);

        // 'http_code', NO 'code' como en LibraryVue: Application::run() solo lee
        // 'http_code', así que allí este 401 sale al cliente convertido en 400.
        return [
            'status'    => 'error',
            'message'   => 'Authentication required. Please log in.',
            'http_code' => 401,
        ];
    }
}
