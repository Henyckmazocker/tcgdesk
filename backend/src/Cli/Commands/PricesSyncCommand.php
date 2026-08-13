<?php

declare(strict_types=1);

namespace App\Cli\Commands;

use App\Cli\CommandInterface;
use App\Domain\Repository\PriceRepositoryInterface;
use App\Infrastructure\Mtgjson\MtgJsonDownloader;
use App\Infrastructure\Mtgjson\PriceIngestion;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Sincronización diaria de precios de Cardmarket, en euros.
 *
 * Este es el comando que cuelga del cron, y por eso su código de salida importa
 * más que el de ningún otro: **un fallo que devuelva 0 deja el histórico con un
 * agujero que no se puede rellenar después**. MTGJSON solo retiene 90 días.
 *
 * Se ejecuta después de las 15:00 CEST, porque el build de MTGJSON se publica a
 * las 9:00 EST.
 */
class PricesSyncCommand implements CommandInterface
{
    private const FICHERO = 'AllPricesToday.json.gz';

    public function __construct(
        private readonly PriceIngestion $ingesta,
        private readonly PriceRepositoryInterface $precios,
        private readonly MtgJsonDownloader $downloader,
        private readonly LoggerInterface $logger
    ) {
    }

    public function getName(): string
    {
        return 'prices:sync';
    }

    public function getDescription(): string
    {
        return 'Sincroniza los precios de Cardmarket del día (para el cron diario)';
    }

    public function run(array $args): int
    {
        $opciones = [];
        foreach ($args as $arg) {
            if (str_starts_with($arg, '--')) {
                $partes                = explode('=', substr($arg, 2), 2);
                $opciones[$partes[0]] = $partes[1] ?? true;
            }
        }

        if (isset($opciones['help'])) {
            echo "prices:sync — precios de Cardmarket del día\n\n"
                . "  --file=RUTA   Usa un fichero ya descargado\n"
                . "  --help        Esto\n\n"
                . "Descarga AllPricesToday.json.gz (5,5 MB) y escribe en mtg_price_daily\n"
                . "(histórico, solo inserta) y mtg_price_current (vigente, se reescribe).\n";
            return 0;
        }

        $inicio = microtime(true);

        try {
            // Siempre se fuerza la descarga: el fichero se llama igual todos los
            // días y reutilizar el de ayer sincronizaría precios viejos sin que
            // nada fallara.
            $ruta = $opciones['file'] ?? $this->downloader->descargar(self::FICHERO, true);

            $stats = $this->ingesta->ingerir((string) $ruta, actualizarVigentes: true);
        } catch (Throwable $e) {
            // Se registra y se propaga como código ≠ 0: es lo que el cron mira.
            $this->logger->error('prices:sync falló', [
                'message'         => $e->getMessage(),
                'exception_class' => get_class($e),
            ]);

            fwrite(STDERR, "prices:sync falló: {$e->getMessage()}\n");

            return 1;
        }

        $segundos = microtime(true) - $inicio;

        printf(
            "Precios sincronizados en %.1f s\n"
            . "  uuids en el fichero  : %d\n"
            . "  fuera del catálogo   : %d\n"
            . "  sin precio Cardmarket: %d\n"
            . "  histórico (nuevas)   : %d\n"
            . "  vigentes (escritas)  : %d\n",
            $segundos,
            $stats['uuids'],
            $stats['desconocidos'],
            $stats['sin_precio'],
            $stats['historico'],
            $stats['vigentes']
        );

        foreach ($this->precios->contadores() as $tabla => $filas) {
            printf("  %-20s %10d filas\n", $tabla, $filas);
        }

        // Un sync que no actualiza ni un precio no es un éxito silencioso: o el
        // fichero venía vacío o el catálogo no casa con él, y en ambos casos hay
        // que enterarse por el código de salida.
        if ($stats['vigentes'] === 0) {
            fwrite(STDERR, "No se actualizó ningún precio vigente.\n");
            return 1;
        }

        return 0;
    }
}
