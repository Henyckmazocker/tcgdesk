<?php

declare(strict_types=1);

namespace App\Cli\Commands;

use App\Cli\CommandInterface;
use App\Domain\Repository\CatalogRepositoryInterface;
use App\Infrastructure\Mtgjson\MtgJsonDownloader;
use App\Infrastructure\Mtgjson\MtgJsonMapper;
use JsonMachine\Items;
use JsonMachine\JsonDecoder\ExtJsonDecoder;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Backfill de `mtg_printing_localized.scryfall_id` — el M6 del
 * Plan - Reconocimiento de la Impresión por su Arte.
 *
 * **Qué arregla.** El índice ORB se sembraba desde `mtg_printing.scryfall_id`,
 * que es la imagen **inglesa**, para todas las cartas. Medido el 2026-09-16
 * sobre `RTR 226`: contra la referencia inglesa el 70,9 % de los keypoints de la
 * foto española —los de la caja de reglas— no casan con nada y el margen se
 * queda en 1,34; contra la española sube a 2,19 y certifica. Para servir la
 * imagen del idioma hace falta su `scryfallId`, y eso es esta columna.
 *
 * **Por qué es un comando hermano y no una bandera de `catalog:normalize`.**
 * `catalog:normalize` recalcula una clave a partir de lo que ya está en la
 * tabla: no sale a internet, no baja nada y termina en ~20 s. Este dato **no se
 * puede calcular**, hay que leerlo de `AllPrintings.json.gz`, que son 177 MB y
 * más de 1 GB en claro. Meterlo detrás de una bandera convertiría un comando que
 * es seguro lanzar en cualquier momento en uno que a veces baja 177 MB, y esa es
 * exactamente la clase de sorpresa que un cron no perdona.
 *
 * **Por qué hace falta un backfill y no basta con la ingesta.** `MtgJsonMapper`
 * ya escribe la columna en cada `catalog:import`, así que lo que se ingiera a
 * partir de ahora nace con su id; pero las **410.604 filas** que ya estaban
 * nacieron a NULL, y una reingesta completa dura ~3,5 min y reescribe el
 * catálogo entero para rellenar una columna.
 *
 * **El fichero se recorre en streaming, set a set**, con el mismo puntero a
 * `/data` de `catalog:import`: el `memory_limit` real del contenedor es 128M
 * (`bin/tcgdesk` lo sube a 512M) y un `json_decode()` del fichero entero no es
 * lento, es imposible.
 *
 * **El código de salida no es decorativo.** Devuelve 1 si al terminar queda
 * alguna fila que la fuente trae con id y que sigue a NULL, igual que
 * `catalog:normalize` con sus claves. Lo que **no** cuenta como pendiente es la
 * traducción para la que MTGJSON no publica id: esa es un NULL legítimo y lo va
 * a ser siempre, y el escáner cae al inglés por ella.
 */
class CatalogLocalizedIdsCommand implements CommandInterface
{
    private const FICHERO = 'AllPrintings.json.gz';

    /**
     * Filas por lote.
     *
     * Cada fila gasta cinco marcadores posicionales entre el `CASE` y el `IN`
     * del `UPDATE`, así que 1.000 filas son 5.000 marcadores: holgado frente al
     * máximo de 65.535 de MySQL, y el mismo tamaño de lote que usa la ingesta.
     */
    private const LOTE = 1000;

    /** Cada cuántas filas escritas se imprime una línea de progreso. */
    private const PROGRESO_CADA = 50000;

    public function __construct(
        private readonly CatalogRepositoryInterface $catalogo,
        private readonly MtgJsonMapper $mapper,
        private readonly MtgJsonDownloader $downloader,
        private readonly LoggerInterface $logger
    ) {
    }

    public function getName(): string
    {
        return 'catalog:localized-ids';
    }

    public function getDescription(): string
    {
        return 'Rellena mtg_printing_localized.scryfall_id (la imagen de cada idioma)';
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
            echo $this->ayuda();
            return 0;
        }

        try {
            $ruta = $opciones['file']
                ?? $this->downloader->descargar(self::FICHERO, isset($opciones['force-download']));
        } catch (Throwable $e) {
            fwrite(STDERR, "No se pudo obtener AllPrintings: {$e->getMessage()}\n");
            return 1;
        }

        if (!is_file((string) $ruta)) {
            fwrite(STDERR, "El fichero no existe: {$ruta}\n");
            return 1;
        }

        $soloSet = isset($opciones['set']) ? strtoupper((string) $opciones['set']) : null;
        $limite  = max(1, (int) ($opciones['batch'] ?? self::LOTE));

        $inicio = microtime(true);

        echo "Rellenando scryfall_id de los nombres localizados desde {$ruta}\n";
        if ($soloSet !== null) {
            echo "Filtrando por set: {$soloSet}\n";
        }
        echo str_repeat('-', 64), "\n";

        $lote       = [];
        $escritas   = 0;
        $sinId      = 0;
        $pendientes = 0;
        $sets       = 0;
        $siguiente  = self::PROGRESO_CADA;

        foreach ($this->recorrerSets((string) $ruta) as $codigo => $set) {
            if ($soloSet !== null && strtoupper((string) $codigo) !== $soloSet) {
                continue;
            }

            $sets++;

            foreach ($set['cards'] ?? [] as $card) {
                if (!$this->mapper->esCaraIngerible($card)) {
                    continue;
                }

                // Se reutiliza el mapper entero y no se vuelve a recorrer
                // `foreignData` aquí: el backfill y la ingesta tienen que leer el
                // MISMO campo del MISMO sitio, o el día que uno de los dos cambie
                // el otro seguirá escribiendo lo de antes sin un solo error.
                foreach ($this->mapper->localized($card) as $fila) {
                    if ($fila['scryfall_id'] === null) {
                        // MTGJSON no publica id para esa traducción: NULL
                        // legítimo, hoy y siempre. No entra en el lote y no
                        // cuenta como pendiente.
                        $sinId++;
                        continue;
                    }

                    $lote[$fila['printing_uuid'] . '|' . $fila['language']] = [
                        'printingUuid' => $fila['printing_uuid'],
                        'language'     => $fila['language'],
                        'scryfallId'   => (string) $fila['scryfall_id'],
                    ];
                }
            }

            // Se vacía por set y no al final: acumular las ~410.000 filas en
            // memoria antes de escribir ninguna es justo lo que el streaming
            // evita.
            while (count($lote) >= $limite) {
                $tanda      = array_slice(array_values($lote), 0, $limite);
                $lote       = array_slice($lote, $limite, null, true);
                $escritas  += $this->catalogo->escribirIdsLocalizados($tanda);
                $pendientes += $this->catalogo->contarLocalizadosSinId($tanda);

                if ($escritas >= $siguiente) {
                    printf("  %7d ids localizados escritos\n", $escritas);
                    $siguiente += self::PROGRESO_CADA;
                }
            }
        }

        if ($lote !== []) {
            $tanda       = array_values($lote);
            $escritas   += $this->catalogo->escribirIdsLocalizados($tanda);
            $pendientes += $this->catalogo->contarLocalizadosSinId($tanda);
        }

        $segundos = microtime(true) - $inicio;

        echo str_repeat('-', 64), "\n";
        printf(
            "Sets %d · ids escritos %d · traducciones sin id en MTGJSON %d\n",
            $sets,
            $escritas,
            $sinId
        );
        printf(
            "Filas que debían tener id y siguen a NULL: %d\n",
            $pendientes
        );
        printf(
            "Tiempo %.1f s · memoria máx. %.1f MB\n",
            $segundos,
            memory_get_peak_usage(true) / 1048576
        );

        $this->logger->info('catalog:localized-ids terminado', [
            'sets'       => $sets,
            'escritas'   => $escritas,
            'sin_id'     => $sinId,
            'pendientes' => $pendientes,
            'set'        => $soloSet,
            'segundos'   => round($segundos, 1),
        ]);

        if ($sets === 0) {
            fwrite(STDERR, 'No se recorrió ningún set' . ($soloSet !== null ? ": ¿existe {$soloSet}?" : '') . "\n");
            return 1;
        }

        if ($pendientes > 0) {
            // Una fila que la fuente trae con id y que no se escribió es una
            // impresión que el escáner seguirá sembrando en inglés, y sin esta
            // línea nadie se enteraría.
            fwrite(STDERR, "Quedan {$pendientes} filas sin el scryfall_id que MTGJSON sí publica.\n");
            return 1;
        }

        return 0;
    }

    /**
     * Recorre `data.<SET_CODE>` devolviendo un set por iteración.
     *
     * Copiado de `CatalogImportCommand` a propósito y no extraído a una base
     * común: son cuatro líneas y compartirlas ataría el backfill a los cambios
     * de la ingesta, que es justo lo que no se quiere de un comando que existe
     * para no tener que lanzar la ingesta.
     *
     * @return iterable<string, array<string, mixed>>
     */
    private function recorrerSets(string $ruta): iterable
    {
        $origen = str_ends_with($ruta, '.gz') ? 'compress.zlib://' . $ruta : $ruta;

        return Items::fromFile($origen, [
            'pointer' => '/data',
            'decoder' => new ExtJsonDecoder(true),
        ]);
    }

    private function ayuda(): string
    {
        return <<<TXT
        catalog:localized-ids — rellena mtg_printing_localized.scryfall_id

        Uso:
          php bin/tcgdesk catalog:localized-ids [opciones]

        Opciones:
          --file=RUTA        Usa un fichero ya descargado (.json o .json.gz)
                             en vez de bajar AllPrintings.json.gz
          --set=CODIGO       Solo ese set, para iterar rápido en desarrollo
          --force-download   Vuelve a descargar aunque el fichero ya esté en disco
          --batch=N          Filas por sentencia (por defecto 1000)
          --help             Esto

        Es el id de la IMAGEN de cada traducción, que es lo que el escáner
        necesita para sembrar el índice ORB en el idioma de la carta en vez de en
        inglés. La ingesta lo mantiene al día por su cuenta; esto es el backfill
        de las 410.604 filas que ya estaban.

        Relanzarlo es seguro: solo actualiza, nunca crea filas. Devuelve 1 si al
        terminar queda alguna fila que MTGJSON sí trae con id y que sigue a NULL.

        TXT;
    }
}
