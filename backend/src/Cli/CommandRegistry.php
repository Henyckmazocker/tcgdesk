<?php

declare(strict_types=1);

namespace App\Cli;

use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Resuelve un comando por nombre y lo ejecuta.
 *
 * Los comandos se declaran en config/commands.php por FQCN y se instancian
 * **perezosamente**: `bin/tcgdesk hello` no construye el importador del catálogo.
 * Es el mismo criterio que sigue el ActionRouter con los controllers.
 */
class CommandRegistry
{
    /** @var array<string, class-string<CommandInterface>> nombre → FQCN */
    private array $names = [];

    /**
     * @param class-string<CommandInterface>[] $commandClasses
     */
    public function __construct(
        private readonly ContainerInterface $container,
        private readonly LoggerInterface $logger,
        array $commandClasses = []
    ) {
        foreach ($commandClasses as $class) {
            // El nombre lo declara el propio comando, así que hay que instanciarlo
            // una vez para indexar. Es barato: los comandos no hacen trabajo en el
            // constructor, solo reciben dependencias.
            $name = $this->container->get($class)->getName();
            $this->names[$name] = $class;
        }

        ksort($this->names);
    }

    /**
     * @param  string[] $args
     * @return int Código de salida del proceso.
     */
    public function run(string $name, array $args = []): int
    {
        if ($name === 'list' || $name === '' || $name === '--help' || $name === '-h') {
            echo $this->renderList();
            return 0;
        }

        if (!isset($this->names[$name])) {
            fwrite(STDERR, "Comando desconocido: {$name}\n\n" . $this->renderList());
            return 1;
        }

        $command = $this->container->get($this->names[$name]);

        try {
            return $command->run($args);
        } catch (Throwable $e) {
            $this->logger->error('Comando CLI falló', [
                'command'         => $name,
                'message'         => $e->getMessage(),
                'exception_class' => get_class($e),
                'file'            => $e->getFile(),
                'line'            => $e->getLine(),
            ]);

            fwrite(STDERR, "ERROR en '{$name}': {$e->getMessage()}\n");

            // Código ≠ 0 para que el cron sepa que ha fallado.
            return 1;
        }
    }

    /** @return array<string, class-string<CommandInterface>> */
    public function getCommands(): array
    {
        return $this->names;
    }

    /**
     * Devuelve el listado en vez de escribirlo, para que quien llama decida el
     * destino (stdout al listar, stderr al fallar) y para que sea comprobable.
     */
    private function renderList(): string
    {
        $out = "TCGDesk CLI\n\n"
            . "Uso: php bin/tcgdesk <comando> [argumentos]\n\n"
            . "Comandos disponibles:\n";

        if ($this->names === []) {
            return $out . "  (ninguno registrado — ver backend/config/commands.php)\n";
        }

        $width = max(array_map('strlen', array_keys($this->names)));

        foreach ($this->names as $name => $class) {
            $description = $this->container->get($class)->getDescription();
            $out .= sprintf("  %-{$width}s  %s\n", $name, $description);
        }

        return $out;
    }
}
