<?php

declare(strict_types=1);

namespace App\Router;

use App\Middleware\MiddlewarePipeline;
use App\Middleware\RateLimitMiddleware;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * ActionRouter — despacha acciones a los controllers a través de la pila de middleware.
 *
 * Las rutas se declaran en config/routes.php:
 *
 *   'ping' => [
 *       'controller' => [PingController::class, 'ping'],
 *       'middleware' => [LoggingMiddleware::class],
 *   ]
 *
 * DIVERGENCIA RESPECTO A LibraryVue (deliberada, ver el CLAUDE.md de este repo):
 * allí el router lleva dos `match` gigantes —acción → llamada al método y nombre →
 * instancia de controller— y añadir un endpoint obliga a tocar tres sitios
 * (routes.php, el router y el controller). Aquí la ruta lleva el FQCN del
 * controller y el router lo resuelve del contenedor, así que **añadir un endpoint
 * es tocar routes.php y el controller**. El router no vuelve a cambiar nunca.
 *
 * Esto importa más aquí que en LibraryVue porque el mirror del catálogo añade
 * muchos endpoints de lectura.
 */
class ActionRouter
{
    public function __construct(
        private readonly array $routes,
        private readonly ContainerInterface $container,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Dispatch an action to the appropriate controller through middleware pipeline.
     *
     * @param  string|null $action    The action to execute
     * @param  array       $inputData The input data for the action
     * @return array Response array with status, message, http_code
     */
    public function dispatch(?string $action, array $inputData): array
    {
        try {
            if ($action === null || !isset($this->routes[$action])) {
                return $this->handleUnknownAction($action);
            }

            $route = $this->routes[$action];

            $request = [
                'action'     => $action,
                'data'       => $inputData,
                'csrf_token' => $inputData['csrf_token'] ?? null,
            ];

            $pipeline = new MiddlewarePipeline();

            // Apply a global default rate limiter (env-configured) to every route
            // that does not declare its own RateLimitMiddleware. Routes needing a
            // stricter/looser limit override it by listing RateLimitMiddleware
            // explicitly with config in routes.php.
            if (!$this->hasRateLimitMiddleware($route['middleware'])) {
                $pipeline->add($this->container->get(RateLimitMiddleware::class));
            }

            foreach ($route['middleware'] as $middlewareConfig) {
                if (is_array($middlewareConfig)) {
                    // Middleware with configuration: [MiddlewareClass::class, ['key' => 'value']]
                    [$middlewareClass, $config] = $middlewareConfig;
                    $middleware = $this->container->get($middlewareClass);

                    if (method_exists($middleware, 'setConfig')) {
                        $middleware->setConfig($config);
                    }

                    $pipeline->add($middleware);
                } else {
                    $pipeline->add($this->container->get($middlewareConfig));
                }
            }

            return $pipeline->execute($request, function (array $request) use ($route): array {
                return $this->executeController($route['controller'], $request);
            });
        } catch (\InvalidArgumentException $e) {
            $this->logger->warning('ActionRouter Validation Error', [
                'message'         => $e->getMessage(),
                'action'          => $action,
                'exception_class' => get_class($e),
            ]);

            return [
                'status'    => 'error',
                'message'   => $e->getMessage(),
                'http_code' => 400,
            ];
        } catch (\Throwable $e) {
            $this->logger->error('ActionRouter Unexpected Error', [
                'message'         => $e->getMessage(),
                'action'          => $action,
                'exception_class' => get_class($e),
                'file'            => $e->getFile(),
                'line'            => $e->getLine(),
            ]);

            return [
                'status'    => 'error',
                'message'   => 'An unexpected error occurred.',
                'http_code' => 500,
            ];
        }
    }

    /**
     * Check whether a route's middleware stack already declares a RateLimitMiddleware
     * (either as a bare class name or as a [class, config] pair).
     */
    private function hasRateLimitMiddleware(array $middlewares): bool
    {
        foreach ($middlewares as $middlewareConfig) {
            $class = is_array($middlewareConfig) ? ($middlewareConfig[0] ?? null) : $middlewareConfig;
            if ($class === RateLimitMiddleware::class) {
                return true;
            }
        }

        return false;
    }

    /**
     * Resolve the controller from the container and invoke the route's method.
     *
     * El controller recibe el `$request` entero —incluido el `user_id` que le haya
     * puesto AuthMiddleware— y decide qué necesita de él.
     *
     * @param array $controllerConfig [ControllerClass::class, 'methodName']
     */
    private function executeController(array $controllerConfig, array $request): array
    {
        [$class, $method] = $controllerConfig;

        if (!$this->container->has($class)) {
            throw new \RuntimeException("Unknown controller: {$class}");
        }

        $controller = $this->container->get($class);

        if (!method_exists($controller, $method)) {
            throw new \RuntimeException("Controller {$class} has no method {$method}()");
        }

        return $controller->$method($request);
    }

    /**
     * Handle unknown action requests
     */
    private function handleUnknownAction(?string $action): array
    {
        $this->logger->warning('Unknown action requested', [
            'action'           => $action,
            'available_routes' => array_keys($this->routes),
        ]);

        return [
            'status'    => 'error',
            'message'   => 'No valid action specified. Action: ' . ($action ?? 'null'),
            'http_code' => 400,
        ];
    }
}
