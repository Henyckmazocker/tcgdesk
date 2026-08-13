<?php

declare(strict_types=1);

use App\Cli\Commands\CatalogImportCommand;
use App\Cli\Commands\HelloCommand;
use App\Cli\Commands\PricesSeedCommand;
use App\Cli\Commands\PricesSyncCommand;

/**
 * Comandos de `bin/tcgdesk`, por FQCN.
 *
 * El nombre con el que se invoca cada uno lo declara el propio comando en su
 * `getName()`; aquí solo se dice cuáles existen. Es el equivalente de
 * config/routes.php para la capa CLI.
 *
 * Aquí entran los comandos de ingesta del Plan - Mirror del Catálogo MTG.
 * `images:cache` NO es de este plan, va con la colección: solo se cachea lo que
 * coleccionas.
 *
 * `prices:sync` es el único que cuelga del cron, y el único cuyo código de
 * salida vigila alguien todos los días.
 */
return [
    CatalogImportCommand::class,
    HelloCommand::class,
    PricesSeedCommand::class,
    PricesSyncCommand::class,
];
