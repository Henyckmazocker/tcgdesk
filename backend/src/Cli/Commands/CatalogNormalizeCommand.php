<?php

declare(strict_types=1);

namespace App\Cli\Commands;

use App\Cli\CommandInterface;
use App\Domain\Import\NameNormalizer;
use App\Domain\Repository\CardNameIndexRepositoryInterface;
use Psr\Log\LoggerInterface;

/**
 * Rellena `mtg_card.name_normalized`, la clave del paso 3 del resolvedor.
 *
 * **Por qué es un comando y no un `UPDATE` dentro de la migración.** La clave la
 * calcula `NameNormalizer` en PHP por dos razones que SQL no puede replicar: no
 * hay `ext/intl` en el contenedor —la transliteración es una tabla propia— y la
 * regla no es «quitar la puntuación», porque los blancos `_____` de las Un-sets
 * se conservan a propósito. Un `REPLACE()` encadenado daría una clave parecida y
 * distinta, que es la peor de las opciones: la columna existiría y resolvería
 * mal.
 *
 * **Por qué sigue haciendo falta después de existir el enganche en la ingesta.**
 * `MtgJsonMapper` ya escribe la columna en cada `catalog:import`, así que las
 * cartas nuevas nacen normalizadas; pero las 34.992 que ya estaban ingeridas
 * cuando se aplicó la migración nacieron a NULL, y una reingesta completa dura
 * ~3,5 minutos y baja 177 MB. Este comando hace lo mismo para el índice de
 * nombres en segundos y sin salir a internet.
 *
 * Relanzarlo es seguro: sin `--all` solo mira las que están a NULL, así que la
 * segunda pasada no toca ninguna fila.
 *
 * **El código de salida no es decorativo.** Si al terminar queda alguna carta
 * fuera del índice, devuelve 1: una carta sin clave normalizada no se resuelve
 * por nombre y no lo dice nadie — es exactamente el fallo silencioso que el plan
 * persigue.
 */
class CatalogNormalizeCommand implements CommandInterface
{
    /**
     * Filas por lote.
     *
     * El `memory_limit` real del contenedor es 128M (`bin/tcgdesk` lo sube a 512M
     * por su cuenta), pero traerse las 34.992 filas de golpe no tiene ninguna
     * ventaja: con 500 el pico es plano y la escritura sigue siendo un solo
     * UPDATE por lote.
     */
    private const LOTE = 500;

    /** Cada cuántas cartas se escribe una línea de progreso. */
    private const PROGRESO_CADA = 5000;

    public function __construct(
        private readonly CardNameIndexRepositoryInterface $indice,
        private readonly NameNormalizer $normalizador,
        private readonly LoggerInterface $logger
    ) {
    }

    public function getName(): string
    {
        return 'catalog:normalize';
    }

    public function getDescription(): string
    {
        return 'Rellena las dos columnas name_normalized (clave de resolución por nombre)';
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
            echo "catalog:normalize — índice de nombres del resolvedor de importación\n\n"
                . "  --all        Recalcula TODAS las filas, no solo las que están a NULL.\n"
                . "               Es lo que hay que lanzar si cambia NameNormalizer.\n"
                . "  --batch=N    Filas por lote (por defecto " . self::LOTE . ").\n"
                . "  --help       Esto\n\n"
                . "Normaliza las DOS tablas: `mtg_card` (el nombre en inglés) y\n"
                . "`mtg_printing_localized` (los otros nueve idiomas, 410.604 filas).\n"
                . "Sin --all solo toca lo que está fuera del índice, así que relanzarlo es barato.\n"
                . "Devuelve 1 si al terminar queda alguna fila con name_normalized a NULL.\n";
            return 0;
        }

        $todas  = isset($opciones['all']);
        $limite = max(1, (int) ($opciones['batch'] ?? self::LOTE));

        $inicio = microtime(true);

        echo 'Normalizando nombres del catálogo', $todas ? ' (TODAS las filas)' : '', "\n";
        echo str_repeat('-', 64), "\n";

        $escritasCartas     = $this->normalizarCartas($limite, $todas);
        $escritasLocalizados = $this->normalizarLocalizados($limite, $todas);

        $segundos       = microtime(true) - $inicio;
        $quedanCartas   = $this->indice->contarSinNormalizar();
        $quedanLocales  = $this->indice->contarLocalizadosSinNormalizar();
        $quedan         = $quedanCartas + $quedanLocales;

        echo str_repeat('-', 64), "\n";
        printf(
            "Normalizadas %d cartas y %d nombres localizados en %.1f s\n",
            $escritasCartas,
            $escritasLocalizados,
            $segundos
        );
        printf("Con name_normalized a NULL: %d cartas, %d localizados\n", $quedanCartas, $quedanLocales);

        $this->logger->info('catalog:normalize terminado', [
            'escritas'            => $escritasCartas,
            'escritasLocalizados' => $escritasLocalizados,
            'pendientes'          => $quedanCartas,
            'pendientesLocales'   => $quedanLocales,
            'todas'               => $todas,
            'segundos'            => round($segundos, 1),
        ]);

        if ($quedan > 0) {
            // Una fila sin clave no se resuelve por nombre y no protesta nadie.
            fwrite(STDERR, "Quedan {$quedan} filas fuera del índice de nombres.\n");
            return 1;
        }

        return 0;
    }

    /** `mtg_card.name_normalized`: el nombre en inglés, paginado por `oracle_id`. */
    private function normalizarCartas(int $limite, bool $todas): int
    {
        printf("Cartas fuera del índice antes de empezar: %d\n", $this->indice->contarSinNormalizar());

        $cursor    = '';
        $escritas  = 0;
        $siguiente = self::PROGRESO_CADA;

        while (true) {
            $lote = $this->indice->lotePorNormalizar($cursor, $limite, $todas);

            if ($lote === []) {
                break;
            }

            $claves = [];
            foreach ($lote as $carta) {
                $claves[$carta['oracleId']] = $this->normalizador->normalizar($carta['name']);
                $cursor                     = $carta['oracleId'];
            }

            $escritas += $this->indice->escribirClaves($claves);

            if ($escritas >= $siguiente) {
                printf("  %6d cartas normalizadas\n", $escritas);
                $siguiente += self::PROGRESO_CADA;
            }
        }

        return $escritas;
    }

    /**
     * `mtg_printing_localized.name_normalized`: los otros nueve idiomas.
     *
     * Son **410.604 filas**, doce veces las de `mtg_card`, y por eso el progreso
     * se imprime igual: una pasada larga y muda parece colgada.
     *
     * El cursor son dos columnas porque la PK es `(printing_uuid, language)`.
     * Paginar solo por el uuid dejaría fuera los otros idiomas de la impresión
     * en la que se cortó el lote, en silencio y sin repetir nunca.
     */
    private function normalizarLocalizados(int $limite, bool $todas): int
    {
        printf(
            "Nombres localizados fuera del índice antes de empezar: %d\n",
            $this->indice->contarLocalizadosSinNormalizar()
        );

        $uuid      = '';
        $idioma    = '';
        $escritas  = 0;
        $siguiente = self::PROGRESO_CADA;

        while (true) {
            $lote = $this->indice->loteLocalizadoPorNormalizar($uuid, $idioma, $limite, $todas);

            if ($lote === []) {
                break;
            }

            $filas = [];
            foreach ($lote as $localizado) {
                $filas[] = [
                    'printingUuid' => $localizado['printingUuid'],
                    'language'     => $localizado['language'],
                    'name'         => $localizado['name'],
                    'clave'        => $this->normalizador->normalizar($localizado['name']),
                ];
                $uuid   = $localizado['printingUuid'];
                $idioma = $localizado['language'];
            }

            $escritas += $this->indice->escribirClavesLocalizadas($filas);

            if ($escritas >= $siguiente) {
                printf("  %6d nombres localizados normalizados\n", $escritas);
                $siguiente += self::PROGRESO_CADA;
            }
        }

        return $escritas;
    }
}
