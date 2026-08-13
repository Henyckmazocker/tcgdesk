<?php

declare(strict_types=1);

namespace App\Infrastructure\Mtgjson;

use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Descarga ficheros de MTGJSON a storage/mtgjson/.
 *
 * Escribe en disco en vez de devolver el contenido: `AllPrintings.json.gz` son
 * 177 MB y el `memory_limit` real del contenedor es 128M. El fichero además se
 * reutiliza —la ingesta se relanza a menudo mientras se depura—, y por eso existe
 * el flag `--file` del comando.
 */
class MtgJsonDownloader
{
    private const BASE_URL = 'https://mtgjson.com/api/v5/';

    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly string $directorio
    ) {
    }

    /**
     * Descarga `$fichero` (p. ej. 'AllPrintings.json.gz') y devuelve la ruta local.
     *
     * Si ya existe y no se fuerza, no vuelve a bajarlo.
     */
    public function descargar(string $fichero, bool $forzar = false): string
    {
        if (!is_dir($this->directorio) && !mkdir($this->directorio, 0775, true) && !is_dir($this->directorio)) {
            throw new RuntimeException("No se pudo crear {$this->directorio}");
        }

        $destino = rtrim($this->directorio, '/') . '/' . $fichero;

        if (!$forzar && is_file($destino) && filesize($destino) > 0) {
            $this->logger->info('MTGJSON: se reutiliza el fichero ya descargado', [
                'fichero' => $destino,
                'bytes'   => filesize($destino),
            ]);

            return $destino;
        }

        $url = self::BASE_URL . $fichero;

        // Se escribe a un temporal y se renombra al final: si la descarga se corta
        // a la mitad, la próxima ejecución no encuentra un .gz truncado y decide
        // que ya lo tiene.
        $temporal = $destino . '.parcial';
        $entrada  = @fopen($url, 'rb');

        if ($entrada === false) {
            throw new RuntimeException("No se pudo abrir {$url}");
        }

        $salida = fopen($temporal, 'wb');

        if ($salida === false) {
            fclose($entrada);
            throw new RuntimeException("No se pudo escribir {$temporal}");
        }

        $bytes = stream_copy_to_stream($entrada, $salida);

        fclose($entrada);
        fclose($salida);

        if ($bytes === false || $bytes === 0) {
            @unlink($temporal);
            throw new RuntimeException("Descarga vacía de {$url}");
        }

        rename($temporal, $destino);

        $this->logger->info('MTGJSON: descarga completada', [
            'url'   => $url,
            'bytes' => $bytes,
        ]);

        return $destino;
    }
}
