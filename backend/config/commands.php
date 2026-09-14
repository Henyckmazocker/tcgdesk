<?php

declare(strict_types=1);

use App\Cli\Commands\CatalogImportCommand;
use App\Cli\Commands\CatalogNormalizeCommand;
use App\Cli\Commands\DecksImportCommand;
use App\Cli\Commands\HelloCommand;
use App\Cli\Commands\ImagesCacheCommand;
use App\Cli\Commands\PricesSeedCommand;
use App\Cli\Commands\PricesSyncCommand;

/**
 * Comandos de `bin/tcgdesk`, por FQCN.
 *
 * El nombre con el que se invoca cada uno lo declara el propio comando en su
 * `getName()`; aquí solo se dice cuáles existen. Es el equivalente de
 * config/routes.php para la capa CLI.
 *
 * Aquí entran los comandos de ingesta del Plan - Mirror del Catálogo MTG y, desde
 * el M6 del Plan - Colección y Vistas, `images:cache`: no era de aquel plan
 * porque solo se cachea lo que coleccionas.
 *
 * `prices:sync` e `images:cache` son los que cuelgan del cron, y por eso su
 * código de salida es el que vigila alguien todos los días.
 *
 * `decks:import` llega con el M2 del Plan - Catálogo de Precons: ingiere los
 * 3.029 mazos preconstruidos de `DeckList.json` y `AllDeckFiles.tar.gz`. El cron
 * es opcional para él —los precons sólo cambian cuando sale una edición— pero si
 * se instala va DESPUÉS de `catalog:import`, nunca antes, o cada set nuevo dará
 * huérfanos.
 *
 * `catalog:normalize` llega con el M2 del Plan - Importación de Colecciones: es
 * el backfill de `mtg_card.name_normalized`, la clave con la que el resolvedor
 * casa lo que el usuario teclea. No cuelga del cron —la ingesta mantiene la
 * columna al día por su cuenta—, pero devuelve 1 si queda una sola carta fuera
 * del índice, que es la comprobación de que sigue estándolo.
 */
return [
    CatalogImportCommand::class,
    CatalogNormalizeCommand::class,
    DecksImportCommand::class,
    HelloCommand::class,
    ImagesCacheCommand::class,
    PricesSeedCommand::class,
    PricesSyncCommand::class,
];
