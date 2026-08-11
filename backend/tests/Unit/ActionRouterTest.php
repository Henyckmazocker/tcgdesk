<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Middleware\MiddlewareInterface;
use App\Middleware\RateLimitMiddleware;
use App\Router\ActionRouter;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Lo que este test protege es el contrato del router:
 * la pila de middleware se ejecuta EN ORDEN y envuelve al controller.
 * Es la propiedad de la que cuelgan auth, CSRF y rate limit.
 */
final class ActionRouterTest extends TestCase
{
    /**
     * Contenedor mínimo que sirve de un mapa. Evita levantar PHP-DI entero
     * para probar una pieza que solo necesita `get`/`has`.
     *
     * Trae siempre un RateLimitMiddleware de paso: el router inyecta uno por
     * defecto en toda ruta que no lo declare, y sin él estos tests fallarían
     * por no encontrarlo en el contenedor, no por lo que quieren probar.
     */
    private function container(array $services): ContainerInterface
    {
        $services += [RateLimitMiddleware::class => new class implements MiddlewareInterface {
            public function handle(array $request, callable $next): array
            {
                return $next($request);
            }
        }];

        return new class ($services) implements ContainerInterface {
            public function __construct(private array $services)
            {
            }

            public function get(string $id): mixed
            {
                if (!$this->has($id)) {
                    throw new \RuntimeException("Service not found: {$id}");
                }
                return $this->services[$id];
            }

            public function has(string $id): bool
            {
                return isset($this->services[$id]);
            }
        };
    }

    /**
     * Middleware que deja constancia de su paso en un rastro compartido,
     * antes y después de llamar al siguiente.
     */
    private function tracer(string $name, \ArrayObject $trail): MiddlewareInterface
    {
        return new class ($name, $trail) implements MiddlewareInterface {
            public function __construct(private string $name, private \ArrayObject $trail)
            {
            }

            public function handle(array $request, callable $next): array
            {
                $this->trail[] = "{$this->name}:in";
                $response = $next($request);
                $this->trail[] = "{$this->name}:out";
                return $response;
            }
        };
    }

    private function controller(\ArrayObject $trail): object
    {
        return new class ($trail) {
            public function __construct(private \ArrayObject $trail)
            {
            }

            public function handle(array $request): array
            {
                $this->trail[] = 'controller';
                return ['status' => 'success', 'message' => 'pong', 'http_code' => 200];
            }
        };
    }

    public function testMiddlewarePipelineRunsInDeclaredOrderAroundTheController(): void
    {
        $trail      = new \ArrayObject();
        $controller = $this->controller($trail);

        $routes = [
            'ping' => [
                'controller' => [$controller::class, 'handle'],
                'middleware' => ['first', 'second'],
            ],
        ];

        $container = $this->container([
            $controller::class => $controller,
            'first'            => $this->tracer('first', $trail),
            'second'           => $this->tracer('second', $trail),
        ]);

        $router   = new ActionRouter($routes, $container, new NullLogger());
        $response = $router->dispatch('ping', []);

        self::assertSame('success', $response['status']);
        self::assertSame(200, $response['http_code']);

        // Entrada en orden de declaración, salida en orden inverso: es una cebolla,
        // no una lista. Si esto se rompe, Auth deja de envolver a CSRF.
        self::assertSame(
            ['first:in', 'second:in', 'controller', 'second:out', 'first:out'],
            $trail->getArrayCopy()
        );
    }

    public function testShortCircuitingMiddlewareNeverReachesTheController(): void
    {
        $trail      = new \ArrayObject();
        $controller = $this->controller($trail);

        $blocker = new class implements MiddlewareInterface {
            public function handle(array $request, callable $next): array
            {
                return ['status' => 'error', 'message' => 'nope', 'http_code' => 401];
            }
        };

        $routes = [
            'ping' => [
                'controller' => [$controller::class, 'handle'],
                'middleware' => ['blocker'],
            ],
        ];

        $container = $this->container([
            $controller::class => $controller,
            'blocker'          => $blocker,
        ]);

        $response = (new ActionRouter($routes, $container, new NullLogger()))->dispatch('ping', []);

        self::assertSame(401, $response['http_code']);
        self::assertSame([], $trail->getArrayCopy(), 'El controller no debe ejecutarse.');
    }

    public function testUnknownActionReturns400(): void
    {
        $router   = new ActionRouter([], $this->container([]), new NullLogger());
        $response = $router->dispatch('no_existe', []);

        self::assertSame('error', $response['status']);
        self::assertSame(400, $response['http_code']);
    }

    public function testNullActionReturns400(): void
    {
        $router   = new ActionRouter([], $this->container([]), new NullLogger());
        $response = $router->dispatch(null, []);

        self::assertSame(400, $response['http_code']);
    }

    public function testUnknownControllerIsReportedAs500NotAsAFatal(): void
    {
        $routes = [
            'ping' => [
                'controller' => ['App\\Controllers\\NoExiste', 'ping'],
                'middleware' => [],
            ],
        ];

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('error');

        $response = (new ActionRouter($routes, $this->container([]), $logger))->dispatch('ping', []);

        self::assertSame(500, $response['http_code']);
    }
}
