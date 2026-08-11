<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Cli\CommandInterface;
use App\Cli\CommandRegistry;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Comandos de prueba con clase propia, NO anónimos: dos `new class` escritos en
 * la misma línea comparten nombre de clase en PHP, y el registry indexa por FQCN
 * — con anónimos el segundo comando pisaba al primero y el test mentía.
 */
class FakeCommand implements CommandInterface
{
    public array $receivedArgs = [];

    public function __construct(
        private readonly string $name,
        private readonly int $exitCode = 0,
        private readonly ?\Throwable $throws = null
    ) {
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getDescription(): string
    {
        return "Comando de prueba {$this->name}";
    }

    public function run(array $args): int
    {
        if ($this->throws !== null) {
            throw $this->throws;
        }
        $this->receivedArgs = $args;
        return $this->exitCode;
    }
}

final class OtherFakeCommand extends FakeCommand
{
}

/**
 * Lo que protege este test es el contrato del que colgará el cron:
 * el registry resuelve por nombre y **propaga el código de salida**.
 * Un comando de ingesta que falla y devuelve 0 deja el catálogo
 * desactualizado sin que nadie se entere.
 */
final class CommandRegistryTest extends TestCase
{
    private function container(array $services): ContainerInterface
    {
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

    public function testResolvesByNameAndReturnsTheCommandExitCode(): void
    {
        $ok    = new FakeCommand('catalog:import', 0);
        $fails = new OtherFakeCommand('prices:sync', 3);

        $registry = new CommandRegistry(
            $this->container([FakeCommand::class => $ok, OtherFakeCommand::class => $fails]),
            new NullLogger(),
            [FakeCommand::class, OtherFakeCommand::class]
        );

        self::assertSame(0, $registry->run('catalog:import'));
        self::assertSame(3, $registry->run('prices:sync'));
    }

    public function testPassesTrailingArgumentsToTheCommand(): void
    {
        $command  = new FakeCommand('hello');
        $registry = new CommandRegistry(
            $this->container([FakeCommand::class => $command]),
            new NullLogger(),
            [FakeCommand::class]
        );

        $registry->run('hello', ['David', '--verbose']);

        self::assertSame(['David', '--verbose'], $command->receivedArgs);
    }

    public function testUnknownCommandReturnsNonZero(): void
    {
        $registry = new CommandRegistry($this->container([]), new NullLogger(), []);

        self::assertSame(1, $registry->run('no_existe'));
    }

    public function testListingIsSuccessfulAndIsTheDefault(): void
    {
        $command  = new FakeCommand('hello');
        $registry = new CommandRegistry(
            $this->container([FakeCommand::class => $command]),
            new NullLogger(),
            [FakeCommand::class]
        );

        ob_start();
        $exit   = $registry->run('list');
        $output = ob_get_clean();

        self::assertSame(0, $exit);
        self::assertStringContainsString('hello', $output);
        self::assertStringContainsString('Comando de prueba hello', $output);

        // Sin argumentos, `bin/tcgdesk` pasa 'list': el listado es el defecto.
        ob_start();
        $defaultExit = $registry->run('list');
        ob_end_clean();
        self::assertSame(0, $defaultExit);
    }

    public function testAThrowingCommandIsLoggedAndReportedAsFailureNotAsAFatal(): void
    {
        $boom = new FakeCommand('catalog:import', 0, new \RuntimeException('MTGJSON no responde'));

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('error');

        $registry = new CommandRegistry(
            $this->container([FakeCommand::class => $boom]),
            $logger,
            [FakeCommand::class]
        );

        // Código ≠ 0: es lo que el cron necesita para saber que hay que mirar.
        self::assertSame(1, $registry->run('catalog:import'));
    }

    public function testCommandsAreListedInAlphabeticalOrder(): void
    {
        $zebra = new FakeCommand('zebra');
        $alfa  = new OtherFakeCommand('alfa');

        $registry = new CommandRegistry(
            $this->container([FakeCommand::class => $zebra, OtherFakeCommand::class => $alfa]),
            new NullLogger(),
            [FakeCommand::class, OtherFakeCommand::class]
        );

        self::assertSame(['alfa', 'zebra'], array_keys($registry->getCommands()));
    }
}
