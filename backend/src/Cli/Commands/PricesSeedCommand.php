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
 * Siembra el histórico con los 90 días que MTGJSON conserva.
 *
 * Se ejecuta **una sola vez**, al montar el mirror, y es la única oportunidad de
 * tener esos 90 días: a partir de ahí MTGJSON los va tirando y solo queda lo que
 * haya ido guardando `prices:sync`.
 *
 * `AllPrices.json.gz` son 149 MB y trae 90 fechas por carta y acabado, así que
 * escribe varios millones de filas. No toca `mtg_price_current`: el precio
 * vigente lo pone el sync diario, que es quien sabe cuál es el último día.
 */
class PricesSeedCommand implements CommandInterface
{
    private const FICHERO = 'AllPrices.json.gz';

    public function __construct(
        private readonly PriceIngestion $ingesta,
        private readonly PriceRepositoryInterface $precios,
        private readonly MtgJsonDownloader $downloader,
        private readonly LoggerInterface $logger
    ) {
    }

    public function getName(): string
    {
        return 'prices:seed';
    }

    public function getDescription(): string
    {
        return 'Siembra el histórico con los 90 días de MTGJSON (una sola vez)';
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
            echo "prices:seed — siembra los 90 días de histórico\n\n"
                . "  --file=RUTA       Usa un fichero ya descargado\n"
                . "  --force-download  Vuelve a bajar AllPrices.json.gz aunque ya esté en disco\n"
                . "  --help            Esto\n\n"
                . "Descarga AllPrices.json.gz (149 MB) y escribe SOLO en mtg_price_daily.\n"
                . "Es idempotente: relanzarlo no duplica nada, solo tarda.\n";
            return 0;
        }

        $inicio = microtime(true);

        echo "Sembrando el histórico de precios. Esto tarda: son 149 MB y 90 días por carta.\n";

        try {
            // Sin `--force-download` se reutiliza el .gz que haya en disco, que es
            // lo que se quiere mientras se depura. Para rellenar un hueco del
            // histórico es justo al revés: el fichero viejo trae la ventana de 90
            // días de *entonces*, y con `INSERT IGNORE` la siembra no insertaría
            // nada pareciendo que fue bien.
            $ruta = $opciones['file']
                ?? $this->downloader->descargar(self::FICHERO, isset($opciones['force-download']));

            $stats = $this->ingesta->ingerir(
                (string) $ruta,
                actualizarVigentes: false,
                progreso: function (int $uuids, int $filas): void {
                    printf("\r  %d uuids · %d filas de histórico", $uuids, $filas);
                }
            );
        } catch (Throwable $e) {
            $this->logger->error('prices:seed falló', [
                'message'         => $e->getMessage(),
                'exception_class' => get_class($e),
            ]);

            fwrite(STDERR, "\nprices:seed falló: {$e->getMessage()}\n");

            return 1;
        }

        printf(
            "\n\nHistórico sembrado en %s\n"
            . "  uuids en el fichero  : %d\n"
            . "  fuera del catálogo   : %d\n"
            . "  sin precio Cardmarket: %d\n"
            . "  filas nuevas         : %d\n",
            $this->duracion(microtime(true) - $inicio),
            $stats['uuids'],
            $stats['desconocidos'],
            $stats['sin_precio'],
            $stats['historico']
        );

        foreach ($this->precios->contadores() as $tabla => $filas) {
            printf("  %-20s %10d filas\n", $tabla, $filas);
        }

        if ($stats['historico'] === 0 && $stats['uuids'] === 0) {
            fwrite(STDERR, "El fichero no traía ningún precio.\n");
            return 1;
        }

        return 0;
    }

    private function duracion(float $segundos): string
    {
        return $segundos < 60
            ? sprintf('%.1f s', $segundos)
            : sprintf('%d min %02d s', (int) ($segundos / 60), (int) $segundos % 60);
    }
}
