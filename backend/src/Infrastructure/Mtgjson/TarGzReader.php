<?php

declare(strict_types=1);

namespace App\Infrastructure\Mtgjson;

use Generator;
use RuntimeException;

/**
 * Recorre un `.tar.gz` **secuencialmente**, sin extraerlo a disco.
 *
 * Existe por una restricción medida en el M0 del Plan - Catálogo de Precons:
 * `AllDeckFiles.tar.gz` son 257 MB que descomprimen a 816,7 MB y el `memory_limit`
 * real del contenedor es 128M (`bin/tcgdesk` lo sube a 512M por su cuenta). Las
 * dos alternativas cómodas están descartadas por el mismo motivo:
 *
 * - **`PharData` no sirve**: es un Phar y necesita `seek` sobre el fichero entero.
 * - **El `.zip` tampoco**: guarda su índice al final, así que leerlo exige `seek`.
 * - **`fseek` sobre `compress.zlib://` no es fiable**: el flujo no es posicionable
 *   hacia atrás y hacia delante depende de la implementación. Para saltarse una
 *   entrada hay que leer sus bytes y tirarlos.
 *
 * Lo que queda es lo que hace esta clase: `fopen('compress.zlib://…')` —el mismo
 * mecanismo que `CatalogImportCommand::recorrerSets()`— y las cabeceras tar de 512
 * bytes parseadas a mano.
 *
 * **El contrato es perezoso a propósito**: cada entrada llega con un `leer` que
 * materializa su contenido sólo si el consumidor lo llama. Si no lo llama, los
 * bytes se descomprimen igual (zlib es secuencial, no hay forma de saltárselos)
 * pero se tiran sin construir un string de 571 KB ni decodificar nada. Es lo que
 * permite que un `--type=` recorra los 3.029 ficheros pagando sólo los que quiere.
 */
final class TarGzReader
{
    /** El tamaño de bloque del formato tar. Todo va alineado a él. */
    private const BLOQUE = 512;

    /** Cuánto se lee de una vez al tirar bytes que no interesan. */
    private const TROZO_DESCARTE = 262144;

    /**
     * Entradas de fichero regular, en el orden en que están en el archivo.
     *
     * Los directorios y las cabeceras PAX (`typeflag` 'x' / 'g', que MTGJSON pone
     * delante de CADA fichero) se saltan sin llegar al consumidor.
     *
     * @return Generator<int, array{nombre: string, tamano: int, leer: callable(): string}>
     */
    public function recorrer(string $ruta): Generator
    {
        $flujo = @fopen($this->origen($ruta), 'rb');

        if ($flujo === false) {
            throw new RuntimeException("No se pudo abrir {$this->origen($ruta)}");
        }

        // El `path` que haya dejado la última cabecera PAX, para la entrada que
        // viene detrás de ella.
        $nombrePax = null;

        try {
            while (true) {
                $cabecera = $this->leerExacto($flujo, self::BLOQUE);

                // Fin de fichero sin el bloque nulo final: el tar está truncado,
                // pero ya no hay nada más que leer.
                if ($cabecera === null) {
                    return;
                }

                // Dos bloques a cero marcan el final del archivo; con el primero
                // basta para saber que se acabó.
                if (trim($cabecera, "\0") === '') {
                    return;
                }

                if (substr($cabecera, 257, 5) !== 'ustar') {
                    throw new RuntimeException('Cabecera tar corrupta: no lleva la marca ustar');
                }

                $nombre  = rtrim(substr($cabecera, 0, 100), "\0");
                $prefijo = rtrim(substr($cabecera, 345, 155), "\0");

                if ($prefijo !== '') {
                    $nombre = $prefijo . '/' . $nombre;
                }

                // El tamaño va en OCTAL, en ASCII, y puede venir rellenado con
                // espacios o con nulos según quién escribiera el tar.
                $tamano  = (int) octdec(trim(str_replace("\0", '', substr($cabecera, 124, 12))));
                $tipo    = substr($cabecera, 156, 1);
                $relleno = (self::BLOQUE - ($tamano % self::BLOQUE)) % self::BLOQUE;

                // Una cabecera PAX ('x') lleva el nombre REAL de la entrada que
                // viene detrás. No es un detalle académico: MTGJSON pone una
                // delante de cada fichero, y los 11 mazos con nombre no ASCII
                // —`DandânDeck_SLD`, `魔法学院青春白書`, `JakubŠlemr…`— llevan en el
                // campo `name` de la cabecera ustar una versión mutilada del
                // nombre. Sin leer la PAX esos 11 mazos no casan con su
                // `fileName` de `DeckList.json` y se quedan sin cartas EN SILENCIO.
                if ($tipo === 'x') {
                    $ruta = $this->rutaPax($this->leerExacto($flujo, $tamano) ?? '');

                    if ($ruta !== null) {
                        $nombrePax = $ruta;
                    }

                    $this->descartar($flujo, $relleno);
                    continue;
                }

                // El resto de lo que no es fichero regular —directorio '5',
                // cabecera PAX global 'g', nombres largos 'L'…— se salta entero.
                if ($tipo !== '0' && $tipo !== "\0") {
                    $this->descartar($flujo, $tamano + $relleno);
                    continue;
                }

                if ($nombrePax !== null) {
                    $nombre    = $nombrePax;
                    $nombrePax = null;
                }

                $pendiente = $tamano + $relleno;

                $leer = function () use ($flujo, $tamano, $relleno, &$pendiente, $nombre): string {
                    if ($pendiente === 0) {
                        throw new RuntimeException("Los bytes de {$nombre} ya se consumieron");
                    }

                    $contenido = $this->leerExacto($flujo, $tamano);

                    if ($contenido === null) {
                        throw new RuntimeException("Fichero truncado dentro del tar: {$nombre}");
                    }

                    $this->descartar($flujo, $relleno);
                    $pendiente = 0;

                    return $contenido;
                };

                yield ['nombre' => $nombre, 'tamano' => $tamano, 'leer' => $leer];

                // El consumidor no quiso el contenido: sus bytes se tiran para
                // dejar el flujo alineado en la siguiente cabecera.
                if ($pendiente > 0) {
                    $this->descartar($flujo, $pendiente);
                    $pendiente = 0;
                }
            }
        } finally {
            fclose($flujo);
        }
    }

    /**
     * El `path` de una cabecera PAX, si lo trae.
     *
     * El formato es una tira de registros `"<longitud> <clave>=<valor>\n"`. Se
     * busca la clave directamente en vez de ir contando longitudes: el valor es
     * una ruta y no puede llevar un salto de línea, así que el registro termina
     * donde dice el `\n`, y así una longitud mal escrita por quien generó el tar
     * no se lleva por delante el nombre del fichero.
     */
    private function rutaPax(string $cabecera): ?string
    {
        if (preg_match('/(?:^|\n)\d+ path=([^\n]*)\n/', $cabecera, $coincidencia) !== 1) {
            return null;
        }

        return $coincidencia[1] !== '' ? $coincidencia[1] : null;
    }

    /** Acepta también un `.tar` sin comprimir, que es lo que usan los tests. */
    private function origen(string $ruta): string
    {
        return str_ends_with($ruta, '.gz') ? 'compress.zlib://' . $ruta : $ruta;
    }

    /**
     * Lee exactamente `$bytes`, o null si el flujo se acabó antes.
     *
     * No vale un `fread` suelto: un flujo con el filtro zlib devuelve trozos
     * cortos cuando le apetece, y un bloque tar leído a medias descuadra TODO lo
     * que viene detrás.
     *
     * @param resource $flujo
     */
    private function leerExacto($flujo, int $bytes): ?string
    {
        if ($bytes === 0) {
            return '';
        }

        $buffer = '';

        while (strlen($buffer) < $bytes) {
            $trozo = fread($flujo, $bytes - strlen($buffer));

            if ($trozo === false || $trozo === '') {
                return null;
            }

            $buffer .= $trozo;
        }

        return $buffer;
    }

    /**
     * Avanza `$bytes` tirando lo leído.
     *
     * Descomprimirlos es inevitable; **no materializarlos** es lo que importa.
     *
     * @param resource $flujo
     */
    private function descartar($flujo, int $bytes): void
    {
        while ($bytes > 0) {
            $trozo = fread($flujo, min($bytes, self::TROZO_DESCARTE));

            if ($trozo === false || $trozo === '') {
                return;
            }

            $bytes -= strlen($trozo);
        }
    }
}
