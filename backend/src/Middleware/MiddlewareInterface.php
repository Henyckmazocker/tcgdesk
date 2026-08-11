<?php

declare(strict_types=1);

namespace App\Middleware;

/**
 * Middleware Interface
 * All middlewares must implement this interface
 */
interface MiddlewareInterface
{
    /**
     * Process the request and pass to next middleware
     *
     * @param array    $request Request data
     * @param callable $next    Next middleware in chain
     * @return array Response data
     */
    public function handle(array $request, callable $next): array;
}
