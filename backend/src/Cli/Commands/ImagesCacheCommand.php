<?php

declare(strict_types=1);

namespace App\Cli\Commands;

use App\Cli\CommandInterface;
use App\Domain\Repository\ImageCacheRepositoryInterface;
use App\Infrastructure\Scryfall\RateLimiter;
use App\Infrastructure\Scryfall\ScryfallImageDownloader;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Baja a disco las imágenes de las cartas que alguien colecciona.
 *
 * **Por qué existe este comando y no una descarga en la petición.** Al añadir una
 * carta a la colección NO se baja su imagen: eso ataría el tiempo de respuesta de
 * `collection_add` al CDN de Scryfall, que es justo lo que este proyecto entero
 * se niega a hacer —ninguna petición de usuario sale a internet—. La carta queda
 * *pendiente* (está en la colección y no en `mtg_image_cache`) y este comando la
 * baja en segundo plano. Mientras tanto la UI enseña la URL remota.
 *
 * **Por qué solo lo coleccionado.** 110.384 printings a ~100 KB son ~11 GB y
 * nadie mira el 99 % de ellos. La colección de un usuario son decenas o cientos
 * de cartas: eso sí cabe, y es exactamente lo que hace falta ver en una tienda
 * sin cobertura.
 *
 * **El ritmo.** Scryfall pide 10 req/s ([[TCGDesk/Fuentes de Datos]]). Lo impone
 * `RateLimiter`, y el comando informa del ritmo medido al terminar para que el
 * dato sea comprobable y no una promesa.
 */
class ImagesCacheCommand implements CommandInterface
{
    /** Cada cuántas descargas se escribe una línea de progreso. */
    private const PROGRESO_CADA = 10;

    public function __construct(
        private readonly ImageCacheRepositoryInterface $cache,
        private readonly ScryfallImageDownloader $downloader,
        private readonly LoggerInterface $logger
    ) {
    }

    public function getName(): string
    {
        return 'images:cache';
    }

    public function getDescription(): string
    {
        return 'Descarga a disco las imágenes de las cartas coleccionadas (cron diario)';
    }

    public function run(array $args): int
    {
        $opciones = [];
        foreach ($args as $arg) {
            if (str_starts_with($arg, '--')) {
                $partes               = explode('=', substr($arg, 2), 2);
                $opciones[$partes[0]] = $partes[1] ?? true;
            }
        }

        if (isset($opciones['help'])) {
            echo "images:cache — imágenes de la colección a disco\n\n"
                . "  --size=normal  Tamaño del CDN: small | normal | large (por defecto normal)\n"
                . "  --limit=N      Baja como mucho N imágenes en esta pasada\n"
                . "  --rps=10       Peticiones por segundo (el máximo que pide Scryfall es 10)\n"
                . "  --help         Esto\n\n"
                . "Pendiente = estar en la colección de alguien y no estar en mtg_image_cache.\n"
                . "Relanzarlo no vuelve a bajar nada de lo que ya tiene.\n";
            return 0;
        }

        $size = (string) ($opciones['size'] ?? 'normal');

        if (!in_array($size, ScryfallImageDownloader::TAMANOS, true)) {
            fwrite(STDERR, "Tamaño desconocido: {$size} (small | normal | large)\n");
            return 1;
        }

        $limite = (int) ($opciones['limit'] ?? 0);
        $rps    = (float) ($opciones['rps'] ?? 10.0);

        if ($rps <= 0 || $rps > 10) {
            // Subirlo por encima de 10 no es una opción que se le ofrezca a
            // nadie: es la condición de uso de la fuente, no un parámetro de
            // rendimiento.
            fwrite(STDERR, "El ritmo debe estar entre 0 y 10 req/s (Scryfall pide 10).\n");
            return 1;
        }

        $limitador = new RateLimiter($rps);

        try {
            $pendientes = $this->cache->pendientes($limite);
            $previos    = $this->cache->contadores();
        } catch (Throwable $e) {
            $this->logger->error('images:cache no pudo leer la cola', [
                'message'         => $e->getMessage(),
                'exception_class' => get_class($e),
            ]);

            fwrite(STDERR, "images:cache falló al leer la cola: {$e->getMessage()}\n");

            return 1;
        }

        $total = count($pendientes);

        printf(
            "images:cache — %d pendiente(s), %d ya en caché, tamaño %s, %.0f req/s\n",
            $total,
            $previos['cacheadas'],
            $size,
            $rps
        );

        if ($previos['sinScryfallId'] > 0) {
            // No es un error: `mtg_printing.scryfall_id` es NULL en algunos
            // printings y sin id no hay URL que componer. Se dice en voz alta
            // para que el hueco en la colección tenga explicación.
            printf(
                "  aviso: %d impresión(es) de la colección no tienen scryfall_id y no se pueden cachear\n",
                $previos['sinScryfallId']
            );
        }

        if ($total === 0) {
            echo "Nada que descargar.\n";
            return 0;
        }

        $inicio      = microtime(true);
        $descargadas = 0;
        $bytes       = 0;
        $fallos      = [];
        $dimensiones = [];

        foreach ($pendientes as $i => $scryfallId) {
            $limitador->esperar();

            try {
                $resultado = $this->downloader->descargar($scryfallId, $size);

                // Solo se anota en la tabla lo que ya está en disco: una fila sin
                // fichero sería peor que no tener fila, porque la cola dejaría de
                // considerarla pendiente y nadie volvería a intentarlo.
                $this->cache->registrar($scryfallId, $size, $resultado['ruta']);

                $descargadas++;
                $bytes += $resultado['bytes'];

                $clave               = $resultado['ancho'] . 'x' . $resultado['alto'];
                $dimensiones[$clave] = ($dimensiones[$clave] ?? 0) + 1;
            } catch (Throwable $e) {
                $fallos[$scryfallId] = $e->getMessage();

                $this->logger->warning('images:cache: imagen no descargada', [
                    'scryfall_id' => $scryfallId,
                    'message'     => $e->getMessage(),
                ]);
            }

            if (($i + 1) % self::PROGRESO_CADA === 0 || $i + 1 === $total) {
                printf("  %d/%d\n", $i + 1, $total);
            }
        }

        $segundos = microtime(true) - $inicio;

        printf(
            "Imágenes cacheadas en %.1f s\n"
            . "  descargadas          : %d\n"
            . "  fallidas             : %d\n"
            . "  MB en disco          : %.1f\n"
            . "  ritmo medido         : %.2f req/s (máximo permitido %.0f)\n",
            $segundos,
            $descargadas,
            count($fallos),
            $bytes / 1048576,
            $segundos > 0 ? $total / $segundos : 0.0,
            $rps
        );

        // Las dimensiones son la prueba de que no se está guardando un recorte:
        // el `normal` de Scryfall es la carta entera (488×680), con su línea de
        // copyright y el nombre del artista. `art_crop` mide 626×457 y sería
        // exactamente lo que la licencia prohíbe.
        foreach ($dimensiones as $clave => $cuantas) {
            printf("  dimensiones          : %s (%d imagen/es)\n", $clave, $cuantas);
        }

        foreach ($fallos as $scryfallId => $mensaje) {
            fwrite(STDERR, "  fallo {$scryfallId}: {$mensaje}\n");
        }

        // El criterio del código de salida es el mismo que el de `prices:sync`:
        // el cron se entera cuando la pasada **no hizo nada** habiendo trabajo
        // —la red caída, el CDN bloqueado, `storage/` sin permisos—, que es lo
        // accionable. Un printing suelto con el id obsoleto no tiñe de rojo la
        // ejecución entera: seguiría pendiente, se reintenta mañana, y un cron
        // que falla todos los días para siempre es un cron que nadie mira.
        if ($descargadas === 0) {
            fwrite(STDERR, "No se descargó ninguna imagen habiendo {$total} pendiente(s).\n");
            return 1;
        }

        return 0;
    }
}
