<?php

declare(strict_types=1);

use App\Cli\CommandRegistry;
use App\Domain\GoogleAuthClientInterface;
use App\Domain\Repository\UserRepositoryInterface;
use App\Infrastructure\Auth\GoogleAuthClient;
use App\Infrastructure\Database\DatabaseConnector;
use App\Infrastructure\Logging\LoggerFactory;
use App\Infrastructure\Persistence\MySqlUserRepository;
use App\Infrastructure\RateLimit\FileRateLimitStore;
use App\Router\ActionRouter;
use DI\ContainerBuilder;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\ClientInterface;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Definición del contenedor PHP-DI.
 *
 * Devuelve una FACTORÍA, no el contenedor: así cada entrypoint decide cuándo
 * construirlo. Lo consumen los dos:
 *   - public/index.php  (vía App\Application)
 *   - bin/tcgdesk       (la capa CLI del M3)
 *
 * Que ambos compartan este fichero es lo que hace que los use cases de ingesta
 * sean use cases normales y no un apaño paralelo al HTTP.
 */
return function (): ContainerInterface {
    $builder = new ContainerBuilder();

    // Autowiring por defecto; compilación solo en producción.
    if (($_ENV['APP_ENV'] ?? 'development') === 'production') {
        $builder->enableCompilation(__DIR__ . '/../storage/cache/di');
    }

    $builder->addDefinitions([

        // ====================================================================
        // INFRAESTRUCTURA
        // ====================================================================

        PDO::class => function (): PDO {
            return (new DatabaseConnector())->getConnection();
        },

        LoggerInterface::class => function (): LoggerInterface {
            return LoggerFactory::create('api');
        },

        FileRateLimitStore::class => function (LoggerInterface $logger): FileRateLimitStore {
            $dir = $_ENV['RATE_LIMIT_PATH'] ?? __DIR__ . '/../storage/ratelimit';
            return new FileRateLimitStore($dir, $logger);
        },

        // ====================================================================
        // ROUTER
        // ====================================================================

        'routes' => require __DIR__ . '/routes.php',

        ActionRouter::class => function (ContainerInterface $c): ActionRouter {
            return new ActionRouter($c->get('routes'), $c, $c->get(LoggerInterface::class));
        },

        // ====================================================================
        // CAPA CLI
        // ====================================================================
        // Se define aquí, en el contenedor compartido, y NO en un bootstrap
        // propio: es lo que hace que los comandos de ingesta sean use cases
        // normales en vez de un backend paralelo al HTTP.

        'commands' => require __DIR__ . '/commands.php',

        CommandRegistry::class => function (ContainerInterface $c): CommandRegistry {
            return new CommandRegistry($c, $c->get(LoggerInterface::class), $c->get('commands'));
        },

        // ====================================================================
        // AUTENTICACIÓN
        // ====================================================================

        ClientInterface::class => DI\create(GuzzleClient::class),

        GoogleAuthClientInterface::class => DI\get(GoogleAuthClient::class),

        // ====================================================================
        // REPOSITORIOS — interfaz → implementación
        // ====================================================================
        // Todo lo demás (controllers, middlewares, comandos) lo resuelve el
        // autowiring de PHP-DI y no hace falta declararlo.

        UserRepositoryInterface::class => DI\get(MySqlUserRepository::class),

    ]);

    return $builder->build();
};
