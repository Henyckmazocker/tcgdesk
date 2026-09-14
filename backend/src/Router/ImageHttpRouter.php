<?php

declare(strict_types=1);

namespace App\Router;

use App\Domain\Repository\ImageCacheRepositoryInterface;
use App\Infrastructure\Scryfall\ScryfallImageDownloader;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * `GET /api/images/{scryfall_id}` — la imagen de una carta.
 *
 * **Por qué no es una acción del endpoint único.** Porque no devuelve JSON. El
 * endpoint `POST /index.php` responde `{"status": ...}`; un JPEG de 90 KB no
 * cabe ahí sin meterlo en base64 —un 33 % más de bytes— y sin renunciar al
 * caché del navegador, que es justo lo que hace barata una rejilla de cartas.
 *
 * Es el mismo razonamiento y el mismo mecanismo que la divergencia del catálogo
 * (`CatalogHttpRouter`): **solo GET y solo lectura**, desviado en `public/index.php`
 * antes de construir `Application`. La divergencia estaba declarada como «solo el
 * catálogo»; esto la extiende a un segundo recurso estático de lectura pública, y
 * queda dicho aquí a propósito para que se vea que es una decisión y no un
 * despiste. Cualquier escritura sigue siendo una acción `POST`.
 *
 * **El comportamiento que pide el plan**: la copia local cuando existe, la URL de
 * Scryfall cuando no.
 *
 *   - hay copia local  → 200 con los bytes del fichero y caché de un año
 *   - no hay copia     → 302 al CDN de Scryfall, **sin cachear el redirect**, para
 *                        que la próxima visita ya se lleve la copia local en
 *                        cuanto `images:cache` la haya bajado
 *   - id con mala pinta → 400, y ni se toca el disco
 *
 * **Directory traversal**: el id se valida contra la forma canónica de UUID
 * (`ScryfallImageDownloader::esIdValido`) antes de nada, y aun así la ruta del
 * fichero **no se compone con lo que venga en la URL**: sale de la columna
 * `local_path` de `mtg_image_cache`, que la escribió el comando.
 */
class ImageHttpRouter
{
    /** La imagen de una carta no cambia nunca: se cachea un año. */
    private const CACHE_SEGUNDOS = 31536000;

    public function __construct(
        private readonly ImageCacheRepositoryInterface $cache,
        private readonly ScryfallImageDownloader $downloader,
        private readonly LoggerInterface $logger
    ) {
    }

    /** ¿Esta petición es de una imagen? Lo decide public/index.php con esto. */
    public static function atiende(string $metodo, string $uri): bool
    {
        return ($metodo === 'GET' || $metodo === 'HEAD')
            && str_starts_with(self::ruta($uri), '/api/images');
    }

    public function handle(string $uri, string $metodo = 'GET'): void
    {
        $ruta = self::ruta($uri);

        if (!preg_match('#^/api/images/([^/]+)$#', $ruta, $m)) {
            $this->error(404, 'not_found');
            return;
        }

        $scryfallId = strtolower(rawurldecode($m[1]));

        if (!ScryfallImageDownloader::esIdValido($scryfallId)) {
            $this->error(400, 'invalid_scryfall_id');
            return;
        }

        $size = (string) ($_GET['size'] ?? 'normal');

        if (!in_array($size, ScryfallImageDownloader::TAMANOS, true)) {
            $size = 'normal';
        }

        try {
            $fila = $this->cache->buscar($scryfallId);
        } catch (Throwable $e) {
            $this->logger->error('Image HTTP falló', [
                'uri'             => $uri,
                'message'         => $e->getMessage(),
                'exception_class' => get_class($e),
            ]);

            $this->error(500, 'internal_error');
            return;
        }

        if ($fila !== null) {
            // La ruta viene de la tabla, no de la URL.
            $absoluta = $this->downloader->rutaAbsoluta($fila['localPath']);

            if (is_file($absoluta) && filesize($absoluta) > 0) {
                $this->servir($absoluta, $metodo);
                return;
            }

            // Fila sin fichero: la caché miente. Se cae al CDN en vez de dar un
            // 404, que dejaría un hueco permanente en la rejilla.
            $this->logger->warning('Imagen en mtg_image_cache sin fichero en disco', [
                'scryfall_id' => $scryfallId,
                'local_path'  => $fila['localPath'],
            ]);
        }

        $this->redirigir(ScryfallImageDownloader::url($scryfallId, $size));
    }

    private function servir(string $absoluta, string $metodo): void
    {
        http_response_code(200);
        header('Content-Type: image/jpeg');
        header('Content-Length: ' . filesize($absoluta));
        header('Cache-Control: public, max-age=' . self::CACHE_SEGUNDOS . ', immutable');
        header('X-TCGDesk-Image: local');

        if ($metodo === 'HEAD') {
            return;
        }

        // readfile() copia el fichero al output en trozos: no carga los 90 KB
        // —ni el fichero que sea— en una variable. Con el memory_limit real del
        // contenedor en 128M, la costumbre importa más que este caso concreto.
        readfile($absoluta);
    }

    private function redirigir(string $url): void
    {
        http_response_code(302);
        header('Location: ' . $url);
        // NUNCA se cachea el redirect: en cuanto images:cache baje la imagen,
        // esta misma URL debe empezar a servir la copia local. Un 302 cacheado
        // un año ataría la app al CDN para siempre.
        header('Cache-Control: no-store');
        header('X-TCGDesk-Image: remote');
    }

    private function error(int $codigo, string $mensaje): void
    {
        http_response_code($codigo);
        header('Content-Type: application/json');
        header('Cache-Control: no-store');

        echo json_encode(['error' => $mensaje]);
    }

    /** La ruta sin query string ni barra final. */
    private static function ruta(string $uri): string
    {
        $ruta = parse_url($uri, PHP_URL_PATH) ?: '/';

        return rtrim($ruta, '/') ?: '/';
    }
}
