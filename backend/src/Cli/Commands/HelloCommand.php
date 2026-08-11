<?php

declare(strict_types=1);

namespace App\Cli\Commands;

use App\Cli\CommandInterface;
use Psr\Log\LoggerInterface;

/**
 * Comando de prueba de la capa CLI.
 *
 * Existe para demostrar la propiedad que hace útil a `bin/tcgdesk`: que resuelve
 * sus dependencias del MISMO contenedor que el HTTP —aquí, el logger— sin que
 * haya un ciclo de petición de por medio. Si esto funciona con Apache parado,
 * los comandos de ingesta también lo harán.
 */
class HelloCommand implements CommandInterface
{
    public function __construct(
        private readonly LoggerInterface $logger
    ) {
    }

    public function getName(): string
    {
        return 'hello';
    }

    public function getDescription(): string
    {
        return 'Comprueba que la capa CLI arranca y resuelve dependencias del contenedor';
    }

    public function run(array $args): int
    {
        $who = $args[0] ?? 'mundo';

        $this->logger->info('CLI hello ejecutado', ['who' => $who]);

        echo "Hola, {$who}. La capa CLI de TCGDesk funciona.\n";
        echo '  PHP     : ' . PHP_VERSION . "\n";
        echo '  Entorno : ' . ($_ENV['APP_ENV'] ?? 'unknown') . "\n";

        return 0;
    }
}
