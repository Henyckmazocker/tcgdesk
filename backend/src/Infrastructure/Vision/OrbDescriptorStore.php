<?php

declare(strict_types=1);

namespace App\Infrastructure\Vision;

use InvalidArgumentException;
use RuntimeException;

/**
 * Los bloques ORB en disco, bajo `storage/vision/orb/`.
 *
 * **Esto no es visión por computador y no va a serlo nunca.** PHP no ejecuta ni
 * una línea de ORB en este proyecto: el móvil extrae los descriptores con
 * opencv.js y aquí solo se guardan bytes opacos. Es la única garantía fuerte de
 * que consulta y referencia salen del MISMO código, y este proyecto ya pagó la
 * lección contraria con el dHash —cambiar solo el algoritmo de reescalado movía
 * el hash 7 bits contra un umbral de 4—.
 *
 * ## Por qué a disco y no a la fila
 *
 * Con `nfeatures=700` sobre el tamaño `normal` cada cara son **28.000 B** —el
 * contador satura: 700,0 de media y 686 el mínimo sobre 777 referencias medidas
 * el 2026-09-15—. La BD ya pesa 3,28 GB con un `innodb_buffer_pool_size` de
 * 128 MB, y no hay un solo BLOB en las 16 migraciones. La fila guarda la ruta,
 * como `mtg_image_cache.local_path`.
 *
 * ## El patrón de escritura es el de `ScryfallImageDownloader`, literal
 *
 * Se escribe a un `.parcial` y se renombra al final. Si la petición se corta a
 * la mitad, lo que queda en disco es un `.parcial` huérfano y no un bloque
 * truncado que el móvil leería como descriptores válidos — y un `cv.Mat` con la
 * forma equivocada **empareja sin quejarse y devuelve basura**, que es el
 * síntoma más caro de depurar de todo este plan.
 *
 * ## El troceado por prefijo
 *
 * `vision/orb/<2 primeros del uuid>/<uuid>-<face>-<idioma>.orb`, que es lo que
 * pide el plan. No es exactamente el de `storage/images/scryfall/`, que usa **dos
 * niveles de un carácter** (`<c1>/<c2>/`) replicando al CDN de Scryfall; aquí no
 * hay CDN al que parecerse y un solo nivel de dos caracteres da las mismas 256
 * carpetas. Con las 110.384 impresiones eso son ~431 ficheros por carpeta: el
 * problema que el troceado resuelve —110.384 ficheros en un solo directorio, que
 * es lo que hace que `ls` tarde medio minuto y que ext4 empiece a sufrir— queda
 * resuelto igual.
 *
 * ## EL IDIOMA ENTRÓ EN EL NOMBRE EL 2026-09-16, Y LAS RUTAS VIEJAS SE QUEDAN
 *
 * Sin él, dos idiomas de la misma cara se pisarían el fichero — y desde el M6
 * hay uno por idioma, porque sembrar la imagen inglesa para una carta española
 * tira el 70,9 % de sus keypoints. **Las 2.638 rutas sembradas antes de ese día
 * no se renombraron**: dicen `<uuid>-<face>.orb` y siguen sirviendo tal cual.
 * Que las dos formas convivan no cuesta ni un `if`, y el motivo es que
 * `leer()` recibe **la ruta que guardó la tabla** y no la recompone nunca; el
 * único sitio que compone una ruta es `rutaRelativa()`, y solo para escribir
 * algo que todavía no existe.
 */
class OrbDescriptorStore
{
    /** Las dos caras, las mismas que el ENUM de la tabla y que el CDN. */
    public const CARAS = ['front', 'back'];

    /**
     * Bytes por keypoint. **40 y no 32, y esto es lo que muerde en silencio.**
     *
     * El bloque son dos cosas pegadas: `keypoints*32` de descriptores (CV_8U, 32
     * columnas) seguidos de `keypoints*8` de coordenadas (2 float32
     * little-endian por keypoint). Las coordenadas no son un extra: son el
     * `dstPoints` de `findHomography`, y `MARGEN_MINIMO` está definido sobre los
     * inliers de RANSAC. Sin ellas no hay homografía, no hay inliers y no hay
     * nada que comparar con 1,5.
     */
    public const BYTES_POR_KEYPOINT = 40;

    public function __construct(
        private readonly string $directorio
    ) {
    }

    /**
     * Escribe el bloque y devuelve su ruta **relativa a `storage/`**.
     *
     * Relativa a propósito, igual que en `ScryfallImageDownloader`: `local_path`
     * es `VARCHAR(512)` y mover `storage/` de sitio o montar el proyecto en otra
     * ruta del contenedor no puede invalidar la tabla entera.
     *
     * **El renombrado final es responsabilidad de quien llama**, y no es un
     * descuido: entre escribir los bytes y publicarlos va el `INSERT IGNORE` que
     * decide si esta siembra gana o si devuelve 409, y un 409 **no puede
     * reescribir el fichero que ya estaba**. Por eso este método deja el
     * `.parcial` puesto y `publicar()` es un segundo paso.
     *
     * @return array{relativa: string, parcial: string}
     */
    public function escribirParcial(
        string $printingUuid,
        string $face,
        string $language,
        string $bloque
    ): array {
        $relativa = $this->rutaRelativa($printingUuid, $face, $language);
        $destino  = $this->rutaAbsoluta($relativa);
        $carpeta  = dirname($destino);

        if (!is_dir($carpeta) && !mkdir($carpeta, 0775, true) && !is_dir($carpeta)) {
            throw new RuntimeException("No se pudo crear {$carpeta}");
        }

        $parcial = $destino . '.parcial';

        if (file_put_contents($parcial, $bloque) !== strlen($bloque)) {
            @unlink($parcial);
            throw new RuntimeException("No se pudo escribir {$parcial}");
        }

        return ['relativa' => $relativa, 'parcial' => $parcial];
    }

    /** Publica el `.parcial` en su nombre definitivo. */
    public function publicar(string $parcial): void
    {
        $destino = substr($parcial, 0, -strlen('.parcial'));

        if (!rename($parcial, $destino)) {
            @unlink($parcial);
            throw new RuntimeException("No se pudo mover {$parcial} a {$destino}");
        }
    }

    /** Tira el `.parcial` sin tocar el definitivo. Es la vuelta atrás del 409. */
    public function descartarParcial(string $parcial): void
    {
        @unlink($parcial);
    }

    /**
     * El bloque de bytes de una ruta guardada, o `null` si el fichero no está.
     *
     * **La ruta sale de la tabla, nunca del cliente**, igual que en
     * `MySqlImageCacheRepository::buscar()`: es lo que impide que un `../` en la
     * petición componga una ruta fuera de `storage/`.
     *
     * Que devuelva `null` en vez de reventar es a propósito: una fila cuyo
     * fichero se borró a mano es un índice incompleto, no un error del escáner —
     * el móvil pedirá la imagen y volverá a sembrar.
     */
    public function leer(string $rutaRelativa): ?string
    {
        $absoluta = $this->rutaAbsoluta($rutaRelativa);

        if (!is_file($absoluta)) {
            return null;
        }

        $bloque = @file_get_contents($absoluta);

        return $bloque === false || $bloque === '' ? null : $bloque;
    }

    /** ¿Está ya el fichero definitivo en disco? */
    public function yaEnDisco(string $rutaRelativa): bool
    {
        $absoluta = $this->rutaAbsoluta($rutaRelativa);

        return is_file($absoluta) && filesize($absoluta) > 0;
    }

    /** Convierte una ruta relativa guardada en la tabla en una ruta de disco. */
    public function rutaAbsoluta(string $rutaRelativa): string
    {
        return rtrim($this->directorio, '/') . '/' . ltrim($rutaRelativa, '/');
    }

    /**
     * `vision/orb/<2 primeros del uuid>/<uuid>-<face>-<idioma>.orb`, relativa a
     * `storage/`.
     *
     * El uuid se valida **antes** de componer nada. Un id que pasa este filtro no
     * contiene ni `/` ni `.`, así que no hay `../` posible ni con la petición más
     * maliciosa — mismo criterio que `ScryfallImageDownloader::esIdValido()`. Y
     * el idioma no llega crudo al nombre: pasa por `CardLanguage`, que es una
     * lista cerrada, y de ahí sale por `sufijoDeIdioma()`.
     *
     * **Esto compone rutas NUEVAS y nada más.** Las 2.638 filas sembradas antes
     * del 2026-09-16 guardan la forma vieja, sin idioma, y se leen por la ruta
     * que dice su fila: nadie recompone una ruta para leerla.
     */
    public function rutaRelativa(string $printingUuid, string $face, string $language): string
    {
        if (!self::esUuidValido($printingUuid)) {
            throw new InvalidArgumentException("printing_uuid con forma inválida: {$printingUuid}");
        }

        if (!in_array($face, self::CARAS, true)) {
            throw new InvalidArgumentException("Cara desconocida: {$face}");
        }

        return sprintf(
            'vision/orb/%s/%s-%s-%s.orb',
            substr($printingUuid, 0, 2),
            $printingUuid,
            $face,
            self::sufijoDeIdioma($language)
        );
    }

    /**
     * `'Portuguese (Brazil)'` → `portuguese-brazil`. El trozo del nombre de
     * fichero que distingue un idioma de otro.
     *
     * **El idioma se valida contra `CardLanguage` antes de llegar aquí**, que es
     * una lista cerrada de 18 valores: lo que entra no puede traer ni `/` ni
     * `..`. Este método solo lo hace escribible, y la comprobación de abajo es
     * el cinturón por si algún día alguien lo llama desde otro sitio.
     *
     * Los 18 nombres de MTGJSON dan 18 sufijos distintos, así que no hay dos
     * idiomas que colapsen al mismo fichero — que es lo único que este método
     * tiene que garantizar.
     */
    public static function sufijoDeIdioma(string $language): string
    {
        $sufijo = strtolower(trim($language));
        $sufijo = (string) preg_replace('/[^a-z0-9]+/', '-', $sufijo);
        $sufijo = trim($sufijo, '-');

        if ($sufijo === '') {
            throw new InvalidArgumentException("Idioma sin forma utilizable: {$language}");
        }

        return $sufijo;
    }

    /** La forma canónica de un UUID en minúsculas. */
    public static function esUuidValido(string $uuid): bool
    {
        return (bool) preg_match(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/',
            $uuid
        );
    }
}
