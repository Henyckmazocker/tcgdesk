<?php

declare(strict_types=1);

use App\Cli\Commands\CatalogImportCommand;
use App\Cli\Commands\CatalogLocalizedIdsCommand;
use App\Cli\Commands\CatalogNormalizeCommand;
use App\Cli\Commands\DecksImportCommand;
use App\Cli\Commands\HelloCommand;
use App\Cli\Commands\ImagesCacheCommand;
use App\Cli\Commands\PricesHealthCommand;
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
 *
 * `catalog:hash` y `vision:refs` ESTUVIERON AQUÍ y se borraron con el M1 del
 * Plan - Escáner de Cartas por Cámara, junto con la tabla `mtg_printing_hash` y
 * los 883 KB de índice que llenaban. La identificación por hash perceptual está
 * medida y muerta —una foto queda a 11-12 bits de su propia referencia contra un
 * margen mediano de 6, y sobre 8 cartas reales la correcta salió en los puestos
 * #5 a #3218—, y el porqué íntegro vive en el `## 📅 Log` de ese plan. No se
 * vuelven a escribir.
 *
 * `catalog:localized-ids` llega con el M6 del Plan - Reconocimiento de la
 * Impresión por su Arte, y es hermano de `catalog:normalize` y no una bandera
 * suya: aquel recalcula una clave a partir de lo que ya está en la tabla —no sale
 * a internet y termina en 20 s—, y este **tiene que leer `AllPrintings.json.gz`**
 * porque el `scryfallId` de cada traducción no se puede calcular, solo copiar.
 * Rellena la imagen POR IDIOMA, que es lo que hace que el escáner siembre el
 * índice ORB en el idioma de la carta en vez de en inglés. Tampoco cuelga del
 * cron —la ingesta mantiene la columna al día— pero devuelve 1 si queda una fila
 * que MTGJSON sí trae con id y que sigue a NULL.
 *
 * `prices:health` llega con el M3 del Plan - Jobs Programados: no ingiere nada,
 * solo mira si `mtg_price_daily` se ha quedado atrás y lo dice con el código de
 * salida. Va enganchado al final de `prices-sync.sh`, porque el agujero de 33
 * días que motivó aquel plan existió justamente porque nadie miraba.
 */
return [
    CatalogImportCommand::class,
    CatalogLocalizedIdsCommand::class,
    CatalogNormalizeCommand::class,
    DecksImportCommand::class,
    HelloCommand::class,
    ImagesCacheCommand::class,
    PricesHealthCommand::class,
    PricesSeedCommand::class,
    PricesSyncCommand::class,
];
