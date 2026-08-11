<?php

declare(strict_types=1);

use App\Cli\Commands\HelloCommand;

/**
 * Comandos de `bin/tcgdesk`, por FQCN.
 *
 * El nombre con el que se invoca cada uno lo declara el propio comando en su
 * `getName()`; aquí solo se dice cuáles existen. Es el equivalente de
 * config/routes.php para la capa CLI.
 *
 * Aquí entrarán los comandos de ingesta del Plan - Mirror del Catálogo MTG:
 * `catalog:import`, `prices:sync` e `images:cache`.
 */
return [
    HelloCommand::class,
];
