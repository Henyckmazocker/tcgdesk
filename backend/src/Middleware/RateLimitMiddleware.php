<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Infrastructure\RateLimit\ClientIp;
use App\Infrastructure\RateLimit\FileRateLimitStore;
use Psr\Log\LoggerInterface;

/**
 * Rate Limiting Middleware
 *
 * Applies a fixed-window request limit per route. Configured declaratively in
 * routes.php via setConfig(), e.g.:
 *
 *   [RateLimitMiddleware::class, ['limit' => 5, 'window' => 300, 'by' => 'ip']]
 *
 * Config keys:
 *   - limit  (int)    Max requests allowed within the window.
 *   - window (int)    Window length in seconds.
 *   - by     (string) Key strategy: 'ip' | 'user' | 'ip_user'.
 *
 * Defaults come from the RATE_LIMIT_* environment variables. When
 * RATE_LIMIT_ENABLED is false the middleware is a no-op.
 *
 * On limit exceeded it returns an HTTP 429 array (honoured by
 * Application::sendResponse) and sets Retry-After / X-RateLimit-* headers.
 */
class RateLimitMiddleware implements MiddlewareInterface
{
    private int $limit;
    private int $window;
    private string $by;

    public function __construct(
        private readonly FileRateLimitStore $store,
        private readonly LoggerInterface $logger
    ) {
        // Defaults from environment (window is configured in minutes there).
        $this->limit  = (int) ($_ENV['RATE_LIMIT_MAX_REQUESTS'] ?? 60);
        $this->window = ((int) ($_ENV['RATE_LIMIT_WINDOW_MINUTES'] ?? 1)) * 60;
        $this->by     = 'ip';
    }

    /**
     * Per-route configuration set by the ActionRouter.
     *
     * @param array{limit?: int, window?: int, by?: string} $config
     */
    public function setConfig(array $config): void
    {
        if (isset($config['limit'])) {
            $this->limit = (int) $config['limit'];
        }
        if (isset($config['window'])) {
            $this->window = (int) $config['window'];
        }
        if (isset($config['by']) && in_array($config['by'], ['ip', 'user', 'ip_user'], true)) {
            $this->by = $config['by'];
        }
    }

    public function handle(array $request, callable $next): array
    {
        if (!$this->isEnabled()) {
            return $next($request);
        }

        $key    = $this->buildKey($request);
        $result = $this->store->hit($key, $this->window);

        $count     = $result['count'];
        $resetAt   = $result['reset_at'];
        $remaining = max(0, $this->limit - $count);

        $this->setHeader("X-RateLimit-Limit: {$this->limit}");
        $this->setHeader("X-RateLimit-Remaining: {$remaining}");
        $this->setHeader("X-RateLimit-Reset: {$resetAt}");

        if ($count > $this->limit) {
            $retryAfter = max(1, $resetAt - time());
            $this->setHeader("Retry-After: {$retryAfter}");

            $this->logger->warning('Rate limit exceeded', [
                'action'  => $request['action'] ?? 'unknown',
                'ip'      => ClientIp::resolve(),
                'user_id' => $request['user_id'] ?? null,
                'count'   => $count,
                'limit'   => $this->limit,
                'window'  => $this->window,
            ]);

            return [
                'status'    => 'error',
                'message'   => 'Too many requests. Please try again later.',
                'http_code' => 429,
            ];
        }

        return $next($request);
    }

    private function isEnabled(): bool
    {
        $value = $_ENV['RATE_LIMIT_ENABLED'] ?? 'true';
        return !in_array(strtolower((string) $value), ['false', '0', 'off', 'no', ''], true);
    }

    /**
     * Build the counter key from the action and the configured identifier.
     */
    private function buildKey(array $request): string
    {
        $action = $request['action'] ?? 'unknown';
        $userId = $request['user_id'] ?? null;
        $ip     = ClientIp::resolve() ?? 'unknown';

        $identifier = match ($this->by) {
            // Fall back to IP when the request is unauthenticated.
            'user'    => $userId !== null ? "u:{$userId}" : "ip:{$ip}",
            'ip_user' => "ip:{$ip}|u:" . ($userId ?? 'anon'),
            default   => "ip:{$ip}",
        };

        return "{$action}|{$identifier}";
    }

    /**
     * Set a response header when possible (skipped if headers already sent,
     * e.g. in tests).
     */
    private function setHeader(string $header): void
    {
        if (!headers_sent()) {
            header($header);
        }
    }
}
