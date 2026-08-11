<?php

declare(strict_types=1);

namespace App\Middleware;

use Psr\Log\LoggerInterface;

/**
 * Logging Middleware
 * Logs all requests and responses
 */
class LoggingMiddleware implements MiddlewareInterface
{
    public function __construct(
        private readonly LoggerInterface $logger
    ) {
    }

    public function handle(array $request, callable $next): array
    {
        $startTime = microtime(true);

        $this->logger->info('Request started', [
            'action'     => $request['action'] ?? 'unknown',
            'user_id'    => $request['user_id'] ?? $_SESSION['user_id'] ?? 'guest',
            'ip'         => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'unknown',
        ]);

        // Execute next middleware/handler
        $response = $next($request);

        $executionTime = microtime(true) - $startTime;

        $this->logger->info('Request completed', [
            'action'         => $request['action'] ?? 'unknown',
            'status'         => $response['status'] ?? 'unknown',
            'execution_time' => round($executionTime * 1000, 2) . 'ms',
            'user_id'        => $request['user_id'] ?? $_SESSION['user_id'] ?? 'guest',
        ]);

        return $response;
    }
}
