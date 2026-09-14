<?php

declare(strict_types=1);

namespace App\Infrastructure\Scryfall;

use GuzzleHttp\ClientInterface;
use InvalidArgumentException;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

/**
 * Baja una imagen de carta del CDN de Scryfall a `storage/images/`.
 *
 * Tres cosas que no son opcionales:
 *
 *  1. **No se llama a la API.** La URL se compone desde el `scryfall_id` que
 *     MTGJSON ya nos dio, con los dos primeros caracteres como carpetas del CDN
 *     — el mismo esquema que usa `frontend/src/services/scryfall.js`. Cero
 *     peticiones extra para averiguar nada.
 *  2. **La imagen se guarda TAL CUAL, byte a byte.** [[TCGDesk/Fuentes de Datos]]:
 *     las imágenes son copyright de Wizards of the Coast y **no se pueden
 *     recortar, distorsionar, difuminar ni marcar al agua**. Aquí no hay ni una
 *     línea que toque los píxeles: se copia el flujo a disco y se acabó. El
 *     tamaño `normal` del CDN (488×680) es la carta entera, con su línea de
 *     copyright y el nombre del artista; `art_crop` —que sí es un recorte— no se
 *     usa y no debe usarse.
 *  3. **Streaming a disco, nunca a memoria.** El `memory_limit` real del
 *     contenedor es 128M. Guzzle escribe al `sink` sin acumular el cuerpo, igual
 *     que hace `MtgJsonDownloader` con los 177 MB de AllPrintings.
 */
class ScryfallImageDownloader
{
    private const CDN = 'https://cards.scryfall.io';

    /** Los tamaños del CDN que admite el ENUM de `mtg_image_cache`. */
    public const TAMANOS = ['small', 'normal', 'large'];

    /**
     * Scryfall exige `User-Agent` y `Accept` identificando la aplicación. Una
     * petición anónima es exactamente lo que sus reglas de uso prohíben.
     */
    private const USER_AGENT = 'TCGDesk/0.1 (coleccion personal; +https://github.com/tcgdesk)';

    public function __construct(
        private readonly ClientInterface $http,
        private readonly LoggerInterface $logger,
        private readonly string $directorio,
        private readonly int $timeout = 30
    ) {
    }

    /**
     * La URL pública del CDN para una carta. También la usa el router HTTP para
     * redirigir cuando todavía no hay copia local.
     */
    public static function url(string $scryfallId, string $size = 'normal'): string
    {
        return sprintf(
            '%s/%s/front/%s/%s/%s.jpg',
            self::CDN,
            $size,
            $scryfallId[0],
            $scryfallId[1],
            $scryfallId
        );
    }

    /**
     * Descarga la imagen y devuelve la ruta **relativa a `storage/`** y su tamaño.
     *
     * La ruta es relativa a propósito: `mtg_image_cache.local_path` es
     * `VARCHAR(512)` y guarda rutas relativas, así que mover `storage/` de sitio
     * o montar el proyecto en otra ruta dentro del contenedor no invalida la
     * tabla entera.
     *
     * @return array{ruta: string, bytes: int, ancho: int, alto: int}
     */
    public function descargar(string $scryfallId, string $size = 'normal'): array
    {
        if (!in_array($size, self::TAMANOS, true)) {
            throw new InvalidArgumentException("Tamaño desconocido: {$size}");
        }

        if (!self::esIdValido($scryfallId)) {
            throw new InvalidArgumentException("scryfall_id con forma inválida: {$scryfallId}");
        }

        $relativa = $this->rutaRelativa($scryfallId, $size);
        $destino  = $this->rutaAbsoluta($relativa);
        $carpeta  = dirname($destino);

        if (!is_dir($carpeta) && !mkdir($carpeta, 0775, true) && !is_dir($carpeta)) {
            throw new RuntimeException("No se pudo crear {$carpeta}");
        }

        // Igual que MtgJsonDownloader: se escribe a un temporal y se renombra al
        // final. Si la descarga se corta a la mitad, la próxima ejecución no
        // encuentra un JPEG truncado y decide que ya lo tiene.
        $temporal = $destino . '.parcial';

        try {
            $respuesta = $this->http->request('GET', self::url($scryfallId, $size), [
                'sink'        => $temporal,
                'timeout'     => $this->timeout,
                'http_errors' => false,
                'headers'     => [
                    'User-Agent' => self::USER_AGENT,
                    'Accept'     => 'image/jpeg,image/*;q=0.8',
                ],
            ]);
        } catch (Throwable $e) {
            @unlink($temporal);
            throw new RuntimeException("Fallo de red bajando {$scryfallId}: {$e->getMessage()}", 0, $e);
        }

        $codigo = $respuesta->getStatusCode();

        if ($codigo !== 200) {
            @unlink($temporal);
            throw new RuntimeException("Scryfall devolvió {$codigo} para {$scryfallId}");
        }

        // getimagesize() lee solo la cabecera, no la imagen entera: es barato y
        // es lo que impide registrar como imagen una página de error de 2 KB que
        // llegó con un 200. Y de paso da las dimensiones que el comando informa,
        // que es como se comprueba que no es un recorte.
        $medidas = @getimagesize($temporal);

        if ($medidas === false) {
            @unlink($temporal);
            throw new RuntimeException("Lo descargado para {$scryfallId} no es una imagen");
        }

        $bytes = (int) filesize($temporal);

        if (!rename($temporal, $destino)) {
            @unlink($temporal);
            throw new RuntimeException("No se pudo mover {$temporal} a {$destino}");
        }

        $this->logger->info('Scryfall: imagen cacheada', [
            'scryfall_id' => $scryfallId,
            'size'        => $size,
            'ruta'        => $relativa,
            'bytes'       => $bytes,
            'dimensiones' => $medidas[0] . 'x' . $medidas[1],
        ]);

        return [
            'ruta'  => $relativa,
            'bytes' => $bytes,
            'ancho' => (int) $medidas[0],
            'alto'  => (int) $medidas[1],
        ];
    }

    /** ¿Existe ya el fichero de esta carta? Lo mira el comando para no repetir trabajo. */
    public function yaEnDisco(string $rutaRelativa): bool
    {
        $absoluta = $this->rutaAbsoluta($rutaRelativa);

        return is_file($absoluta) && filesize($absoluta) > 0;
    }

    /** La raíz de `storage/`, para que el router pueda leer el fichero. */
    public function directorio(): string
    {
        return $this->directorio;
    }

    /** Convierte una ruta relativa guardada en la tabla en una ruta de disco. */
    public function rutaAbsoluta(string $rutaRelativa): string
    {
        return rtrim($this->directorio, '/') . '/' . ltrim($rutaRelativa, '/');
    }

    /**
     * La forma canónica de un UUID en minúsculas.
     *
     * Se comprueba **antes** de que el id toque nada, y por eso el router puede
     * reutilizarlo: un id que pasa este filtro no contiene ni `/` ni `.`, así
     * que no hay `../` posible ni con la URL más maliciosa.
     */
    public static function esIdValido(string $scryfallId): bool
    {
        return (bool) preg_match(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/',
            $scryfallId
        );
    }

    /**
     * `images/scryfall/<size>/<a>/<b>/<id>.jpg`, relativa a `storage/`.
     *
     * Se replica el sharding por los dos primeros caracteres del id que usa el
     * propio CDN: 110.384 ficheros en una sola carpeta es lo que hace que `ls`
     * tarde medio minuto y que ext4 empiece a sufrir.
     */
    private function rutaRelativa(string $scryfallId, string $size): string
    {
        return sprintf(
            'images/scryfall/%s/%s/%s/%s.jpg',
            $size,
            $scryfallId[0],
            $scryfallId[1],
            $scryfallId
        );
    }
}
