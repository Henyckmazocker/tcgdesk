<?php

declare(strict_types=1);

namespace App\Middleware;

/**
 * Middleware Pipeline
 * Executes a chain of middlewares in order
 */
class MiddlewarePipeline
{
    /** @var MiddlewareInterface[] */
    private array $middlewares = [];

    /**
     * Add middleware to the pipeline
     */
    public function add(MiddlewareInterface $middleware): self
    {
        $this->middlewares[] = $middleware;
        return $this;
    }

    /**
     * Execute the middleware pipeline
     *
     * @param array    $request Initial request data
     * @param callable $handler Final handler to call after all middlewares
     * @return array Response data
     */
    public function execute(array $request, callable $handler): array
    {
        // Build the pipeline from last to first middleware
        $pipeline = array_reduce(
            array_reverse($this->middlewares),
            fn ($next, $middleware) => fn ($req) => $middleware->handle($req, $next),
            $handler
        );

        return $pipeline($request);
    }

    /**
     * Get count of middlewares in pipeline
     */
    public function count(): int
    {
        return count($this->middlewares);
    }
}
