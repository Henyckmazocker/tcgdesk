<?php

declare(strict_types=1);

namespace App\Cli\Commands;

use App\Cli\CommandInterface;
use App\Domain\Repository\PreconRepositoryInterface;
use App\Infrastructure\Mtgjson\MtgJsonDeckMapper;
use App\Infrastructure\Mtgjson\MtgJsonDownloader;
use App\Infrastructure\Mtgjson\TarGzReader;
use JsonMachine\Items;
use JsonMachine\JsonDecoder\ExtJsonDecoder;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Ingiere los mazos preconstruidos de MTGJSON. Dos ficheros, dos fases.
 *
 * **Fase 1 — el índice.** `DeckList.json` son 626 KB con los 3.029 mazos (código,
 * fichero, nombre, tipo, fecha, fuente) y ninguna carta. Es barato y siempre se
 * ingiere entero: sale en segundos y deja el catálogo de mazos completo aunque la
 * fase 2 se caiga.
 *
 * **Fase 2 — las cartas.** `AllDeckFiles.tar.gz` son 257 MB que descomprimen a
 * 816,7 MB, y cada fichero de mazo llega a 571 KB porque embebe la carta ENTERA
 * (47 campos, `foreignData`, `rulings`, `legalities`, `purchaseUrls`) para que
 * nosotros nos quedemos con cuatro: `uuid`, `count`, `isFoil`, `isEtched`. Se
 * recorre en streaming con `TarGzReader`, un fichero cada vez, y de cada uno se
 * saca lo que interesa soltando el resto sin materializarlo.
 *
 * **El `gc_collect_cycles()` por mazo no es una optimización, es lo que hace que
 * esto quepa.** json-machine deja ciclos de referencias por cada `Items` que se
 * abandona y el recolector automático no los pilla a tiempo: M0 midió ~0,6 MB por
 * fichero parseado, o sea 110 MB con sólo 190 mazos y ~1,8 GB proyectados a los
 * 3.029 — no cabe ni con el `memory_limit` de 512M que pone `bin/tcgdesk`. Con el
 * `gc_collect_cycles()` el pico se queda PLANO. Si alguien lo quita, esto muere.
 *
 * Idempotente como `catalog:import`: todo son upserts sobre la PK de cuatro
 * columnas y ejecutarlo dos veces seguidas no mueve ningún contador.
 *
 * Se ejecuta **con `-u www-data`, nunca como root**: Monolog crea el log del día
 * con el owner de quien lanza el comando, y si lo lanza root Apache deja de poder
 * escribirlo y toda petición HTTP pasa a devolver 500.
 */
class DecksImportCommand implements CommandInterface
{
    private const INDICE = 'DeckList.json';
    private const MAZOS  = 'AllDeckFiles.tar.gz';

    /** Filas de carta acumuladas antes de vaciar el lote contra MySQL. */
    private const TAMANO_LOTE = 1000;

    /** Cada cuántos mazos procesados se escribe una línea de progreso. */
    private const PROGRESO_CADA = 250;

    public function __construct(
        private readonly PreconRepositoryInterface $precons,
        private readonly MtgJsonDeckMapper $mapper,
        private readonly MtgJsonDownloader $downloader,
        private readonly TarGzReader $tar,
        private readonly LoggerInterface $logger
    ) {
    }

    public function getName(): string
    {
        return 'decks:import';
    }

    public function getDescription(): string
    {
        return 'Ingiere los mazos preconstruidos de MTGJSON (DeckList + AllDeckFiles)';
    }

    public function run(array $args): int
    {
        $opciones = $this->parsearArgumentos($args);

        if (isset($opciones['help'])) {
            echo $this->ayuda();
            return 0;
        }

        $inicio  = microtime(true);
        $forzar  = isset($opciones['force-download']);
        $soloTipo = isset($opciones['type']) ? trim((string) $opciones['type']) : null;

        // -------------------------------------------------------------------
        // Fase 1 — el índice
        // -------------------------------------------------------------------
        try {
            $rutaIndice = $opciones['index'] ?? $this->downloader->descargar(self::INDICE, $forzar);
        } catch (Throwable $e) {
            fwrite(STDERR, "No se pudo obtener " . self::INDICE . ": {$e->getMessage()}\n");
            return 1;
        }

        if (!is_file((string) $rutaIndice)) {
            fwrite(STDERR, "El índice no existe: {$rutaIndice}\n");
            return 1;
        }

        echo "Indexando precons desde {$rutaIndice}\n";
        echo str_repeat('-', 64), "\n";

        /** @var array<string, string> $indexados file_name → deck_type */
        $indexados   = [];
        $porTipo     = [];
        $sinFichero  = 0;
        $lote        = [];
        $indice      = 0;

        foreach (Items::fromFile((string) $rutaIndice, ['pointer' => '/data', 'decoder' => new ExtJsonDecoder(true)]) as $entrada) {
            $fila = $this->mapper->precon((array) $entrada);

            if ($fila === null) {
                $sinFichero++;
                continue;
            }

            $indexados[$fila['file_name']] = $fila['deck_type'];
            $porTipo[$fila['deck_type']]   = ($porTipo[$fila['deck_type']] ?? 0) + 1;

            $lote[] = $fila;
            $indice++;

            if (count($lote) >= self::TAMANO_LOTE) {
                $this->precons->upsertPrecons($lote);
                $lote = [];
            }
        }

        if ($lote !== []) {
            $this->precons->upsertPrecons($lote);
            $lote = [];
        }

        printf("Índice: %d mazos · %d tipos distintos\n", $indice, count($porTipo));

        if ($sinFichero > 0) {
            printf("Descartadas %d entradas sin fileName (no hay clave natural)\n", $sinFichero);
        }

        // Un índice vacío no puede terminar en éxito: el cron lo leería como
        // "catálogo de mazos actualizado" y nadie se enteraría.
        if ($indice === 0) {
            fwrite(STDERR, "No se indexó ningún mazo desde {$rutaIndice}\n");
            return 1;
        }

        // -------------------------------------------------------------------
        // Fase 2 — las cartas
        // -------------------------------------------------------------------
        //
        // El filtro por tipo sale del ÍNDICE y no del campo `type` del fichero de
        // mazo: MTGJSON ordena las claves alfabéticamente y `type` va DESPUÉS de
        // `mainBoard`, así que filtrar por él obligaría a parsear entero cada mazo
        // que no interesa. Con el índice se tiran sus bytes sin decodificar ni uno.
        $buscados = $soloTipo === null
            ? $indexados
            : array_filter($indexados, static fn (string $tipo): bool => strcasecmp($tipo, $soloTipo) === 0);

        if ($soloTipo !== null) {
            printf("Filtrando las cartas por tipo: «%s» (%d mazos)\n", $soloTipo, count($buscados));

            if ($buscados === []) {
                fwrite(STDERR, "Ningún mazo del índice es de tipo «{$soloTipo}»\n");
                return 1;
            }
        }

        try {
            $rutaTar = $opciones['file'] ?? $this->downloader->descargar(self::MAZOS, $forzar);
        } catch (Throwable $e) {
            fwrite(STDERR, "No se pudo obtener " . self::MAZOS . ": {$e->getMessage()}\n");
            return 1;
        }

        if (!is_file((string) $rutaTar)) {
            fwrite(STDERR, "El fichero de mazos no existe: {$rutaTar}\n");
            return 1;
        }

        echo "Ingiriendo cartas desde {$rutaTar}\n";

        $procesados = 0;
        $saltados   = 0;
        $filas      = 0;
        $conteos    = [];

        try {
            foreach ($this->tar->recorrer((string) $rutaTar) as $entrada) {
                $ficheroMazo = basename($entrada['nombre'], '.json');

                // El `leer` NO se llama: sus bytes se descomprimen igual (zlib es
                // secuencial) pero se tiran sin construir el string ni decodificar.
                if (!isset($buscados[$ficheroMazo])) {
                    $saltados++;
                    continue;
                }

                $cartas = $this->mapper->cartas(($entrada['leer'])(), $ficheroMazo);

                $conteos[$ficheroMazo] = $this->mapper->ejemplares($cartas);
                $filas                += count($cartas);
                $procesados++;

                foreach ($cartas as $carta) {
                    $lote[] = $carta;
                }

                unset($cartas);

                if (count($lote) >= self::TAMANO_LOTE) {
                    $this->precons->upsertCartas($lote);
                    $lote = [];
                }

                // EL HALLAZGO DE M0. Ver la cabecera de la clase: sin esto el pico
                // se va a ~1,8 GB con los 3.029 mazos y el comando muere.
                gc_collect_cycles();

                if ($procesados % self::PROGRESO_CADA === 0) {
                    printf(
                        "  %5d/%d mazos · %7d filas · %.1f MB\n",
                        $procesados,
                        count($buscados),
                        $filas,
                        memory_get_peak_usage(true) / 1048576
                    );
                }
            }
        } catch (Throwable $e) {
            fwrite(STDERR, "Fallo recorriendo {$rutaTar}: {$e->getMessage()}\n");
            $this->logger->error('decks:import falló recorriendo el tar', ['error' => $e->getMessage()]);
            return 1;
        }

        if ($lote !== []) {
            $this->precons->upsertCartas($lote);
            $lote = [];
        }

        $this->precons->actualizarCardCount($conteos);

        // -------------------------------------------------------------------
        // El parte
        // -------------------------------------------------------------------
        $segundos  = microtime(true) - $inicio;
        $huerfanos = $this->precons->huerfanos();

        echo str_repeat('-', 64), "\n";
        printf(
            "Precons %d · mazos con cartas %d de %d · saltados sin decodificar %d · filas de carta %d\n",
            $indice,
            $procesados,
            count($buscados),
            $saltados,
            $filas
        );

        // Ruidoso a propósito: un uuid que no está en `mtg_printing` no es un
        // error, es un `catalog:import` pendiente, y callárselo sería ingerir a
        // medias en silencio.
        printf(
            "Huérfanos (uuid que no está en mtg_printing): %d filas · %d uuids distintos%s\n",
            $huerfanos['filas'],
            $huerfanos['uuids'],
            $huerfanos['filas'] > 0 ? ' — relanza catalog:import' : ''
        );

        printf(
            "Tiempo %s · memoria máx. %.1f MB\n",
            $this->formatearDuracion($segundos),
            memory_get_peak_usage(true) / 1048576
        );

        foreach ($this->precons->contadores() as $tabla => $total) {
            printf("  %-24s %8d filas\n", $tabla, $total);
        }

        $this->logger->info('decks:import completado', [
            'precons'   => $indice,
            'mazos'     => $procesados,
            'filas'     => $filas,
            'huerfanos' => $huerfanos['filas'],
            'tipo'      => $soloTipo,
            'segundos'  => round($segundos, 1),
        ]);

        // Que el índice entrara pero ningún mazo diera cartas significa que el
        // tar no traía nada reconocible: el catálogo queda a medias y el cron
        // tiene que enterarse.
        if ($procesados === 0) {
            fwrite(STDERR, "No se ingirió ninguna carta: ¿es {$rutaTar} el AllDeckFiles de MTGJSON?\n");
            return 1;
        }

        return 0;
    }

    /**
     * @param  string[] $args
     * @return array<string, string|bool>
     */
    private function parsearArgumentos(array $args): array
    {
        $opciones = [];

        foreach ($args as $arg) {
            if (!str_starts_with($arg, '--')) {
                continue;
            }

            $sinGuiones = substr($arg, 2);
            $partes     = explode('=', $sinGuiones, 2);

            $opciones[$partes[0]] = $partes[1] ?? true;
        }

        return $opciones;
    }

    private function formatearDuracion(float $segundos): string
    {
        if ($segundos < 60) {
            return sprintf('%.1f s', $segundos);
        }

        return sprintf('%d min %02d s', (int) ($segundos / 60), (int) $segundos % 60);
    }

    private function ayuda(): string
    {
        return <<<TXT
        decks:import — ingiere los mazos preconstruidos de MTGJSON

        Uso:
          php bin/tcgdesk decks:import [opciones]

        Opciones:
          --file=RUTA        Usa un AllDeckFiles ya descargado (.tar.gz o .tar)
                             en vez de bajar los 257 MB
          --index=RUTA       Usa un DeckList.json ya descargado
          --type=TIPO        Ingiere las cartas SÓLO de los mazos de ese tipo
                             ("Commander Deck"), para iterar rápido en desarrollo.
                             El índice se ingiere entero de todas formas: son
                             626 KB y dejarlo incompleto no ahorra nada.
          --force-download   Vuelve a descargar aunque el fichero ya esté en disco
          --help             Esto

        Los tipos que hay NO se copian a mano; salen de la propia ingesta:
          SELECT DISTINCT deck_type FROM mtg_precon;

        La ingesta es idempotente: ejecutarla dos veces no cambia ningún contador.
        Al terminar imprime cuántas cartas de precon tienen un uuid que todavía no
        está en mtg_printing — eso no es un fallo, es un catalog:import pendiente.

        TXT;
    }
}
