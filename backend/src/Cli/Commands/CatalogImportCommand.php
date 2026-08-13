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
 * Ingiere el catálogo completo de MTG desde `AllPrintings.json.gz`.
 *
 * Lee el fichero **set a set**, nunca entero: son 177 MB comprimidos y más de
 * 1 GB en claro, y el `memory_limit` real del contenedor es 128M, así que un
 * json_decode() del fichero completo no es lento, es imposible. El recorrido
 * incremental lo hace json-machine con un puntero a `/data`, que devuelve un set
 * por iteración y descarta el anterior.
 *
 * La ingesta es idempotente: todo son upserts y ejecutarla dos veces seguidas no
 * cambia ningún contador. Eso es lo que permite relanzarla cuando sale un set
 * nuevo sin borrar nada.
 */
class CatalogImportCommand implements CommandInterface
{
    private const FICHERO = 'AllPrintings.json.gz';

    public function __construct(
        private readonly CatalogRepositoryInterface $catalogo,
        private readonly MtgJsonMapper $mapper,
        private readonly MtgJsonDownloader $downloader,
        private readonly LoggerInterface $logger
    ) {
    }

    public function getName(): string
    {
        return 'catalog:import';
    }

    public function getDescription(): string
    {
        return 'Ingiere el catálogo de MTG desde MTGJSON (AllPrintings)';
    }

    public function run(array $args): int
    {
        $opciones = $this->parsearArgumentos($args);

        if (isset($opciones['help'])) {
            echo $this->ayuda();
            return 0;
        }

        $inicio = microtime(true);

        try {
            $ruta = $opciones['file']
                ?? $this->downloader->descargar(self::FICHERO, isset($opciones['force-download']));
        } catch (Throwable $e) {
            fwrite(STDERR, "No se pudo obtener {$this->descripcionOrigen($opciones)}: {$e->getMessage()}\n");
            return 1;
        }

        if (!is_file($ruta)) {
            fwrite(STDERR, "El fichero no existe: {$ruta}\n");
            return 1;
        }

        $soloSet = isset($opciones['set']) ? strtoupper((string) $opciones['set']) : null;

        echo "Ingiriendo catálogo desde {$ruta}\n";
        if ($soloSet !== null) {
            echo "Filtrando por set: {$soloSet}\n";
        }
        echo str_repeat('-', 64), "\n";

        $totales  = ['sets' => 0, 'cards' => 0, 'printings' => 0, 'localized' => 0, 'legalities' => 0];
        $sinOracle = 0;

        foreach ($this->recorrerSets($ruta) as $codigo => $set) {
            if ($soloSet !== null && strtoupper((string) $codigo) !== $soloSet) {
                continue;
            }

            $resultado = $this->ingerirSet((string) $codigo, $set);

            foreach ($totales as $clave => $valor) {
                $totales[$clave] = $valor + ($resultado[$clave] ?? 0);
            }
            $sinOracle += $resultado['sin_oracle'];

            printf(
                "  %-8s %-42s %5d printings\n",
                $codigo,
                mb_strimwidth((string) ($set['name'] ?? ''), 0, 42, '…'),
                $resultado['printings']
            );
        }

        $segundos = microtime(true) - $inicio;

        echo str_repeat('-', 64), "\n";
        printf(
            "Sets %d · cartas %d · printings %d · localizados %d · legalidades %d\n",
            $totales['sets'],
            $totales['cards'],
            $totales['printings'],
            $totales['localized'],
            $totales['legalities']
        );

        if ($sinOracle > 0) {
            printf("Descartadas %d cartas sin scryfallOracleId (sin clave para mtg_card)\n", $sinOracle);
        }

        printf("Tiempo %s · memoria máx. %.1f MB\n", $this->formatearDuracion($segundos), memory_get_peak_usage(true) / 1048576);

        foreach ($this->catalogo->contadores() as $tabla => $filas) {
            printf("  %-24s %8d filas\n", $tabla, $filas);
        }

        $this->logger->info('catalog:import completado', $totales + [
            'segundos'   => round($segundos, 1),
            'sin_oracle' => $sinOracle,
            'set'        => $soloSet,
        ]);

        if ($totales['sets'] === 0) {
            fwrite(STDERR, "No se ingirió ningún set" . ($soloSet !== null ? ": ¿existe {$soloSet}?" : '') . "\n");
            return 1;
        }

        return 0;
    }

    /**
     * Recorre `data.<SET_CODE>` devolviendo un set por iteración.
     *
     * El wrapper `compress.zlib://` descomprime sobre la marcha, así que nunca
     * hay un fichero de 1 GB en disco ni en memoria. Acepta también un .json sin
     * comprimir, que es lo que se usa para probar con un set suelto.
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

    /**
     * @param  array<string, mixed> $set
     * @return array<string, int>
     */
    private function ingerirSet(string $codigo, array $set): array
    {
        $this->catalogo->upsertSet($this->mapper->set($set + ['code' => $codigo]));

        $cards = $set['cards'] ?? [];

        // Índice por uuid para localizar las caras traseras sin recorrer el set
        // entero por cada carta de doble cara.
        $porUuid = [];
        foreach ($cards as $card) {
            $porUuid[$card['uuid']] = $card;
        }

        $filasCards      = [];
        $filasPrintings  = [];
        $filasLocalized  = [];
        $filasLegalities = [];
        $sinOracle       = 0;

        foreach ($cards as $card) {
            if (!$this->mapper->esCaraIngerible($card)) {
                continue;
            }

            $carasTraseras = [];
            foreach ($card['otherFaceIds'] ?? [] as $otroUuid) {
                if (isset($porUuid[$otroUuid])) {
                    $carasTraseras[] = $porUuid[$otroUuid];
                }
            }

            $filaCard     = $this->mapper->card($card, $carasTraseras);
            $filaPrinting = $this->mapper->printing($card, $codigo);

            if ($filaCard === null || $filaPrinting === null) {
                $sinOracle++;
                continue;
            }

            // Deduplicado en memoria por oracle_id: una carta reimpresa aparece
            // varias veces dentro del mismo set (arte alternativo, promos) y
            // MySQL rechaza un INSERT multi-fila con la misma clave repetida en
            // el mismo lote, aunque lleve ON DUPLICATE KEY UPDATE.
            $filasCards[$filaCard['oracle_id']] = $filaCard;
            $filasPrintings[]                   = $filaPrinting;

            foreach ($this->mapper->localized($card) as $fila) {
                $filasLocalized[$fila['printing_uuid'] . '|' . $fila['language']] = $fila;
            }

            foreach ($this->mapper->legalities($card) as $fila) {
                $filasLegalities[$fila['oracle_id'] . '|' . $fila['format']] = $fila;
            }
        }

        // El orden importa: las FK de mtg_printing apuntan a mtg_card y mtg_set, y
        // las de localized y legality a printing y card.
        $cards      = $this->catalogo->upsertCards(array_values($filasCards));
        $printings  = $this->catalogo->upsertPrintings($filasPrintings);
        $localized  = $this->catalogo->upsertLocalized(array_values($filasLocalized));
        $legalities = $this->catalogo->upsertLegalities(array_values($filasLegalities));

        return [
            'sets'       => 1,
            'cards'      => $cards,
            'printings'  => $printings,
            'localized'  => $localized,
            'legalities' => $legalities,
            'sin_oracle' => $sinOracle,
        ];
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

    /** @param array<string, string|bool> $opciones */
    private function descripcionOrigen(array $opciones): string
    {
        return isset($opciones['file']) ? (string) $opciones['file'] : self::FICHERO;
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
        catalog:import — ingiere el catálogo de MTG desde MTGJSON

        Uso:
          php bin/tcgdesk catalog:import [opciones]

        Opciones:
          --file=RUTA        Usa un fichero ya descargado (.json o .json.gz)
                             en vez de bajar AllPrintings.json.gz
          --set=CODIGO       Ingiere un solo set, para iterar rápido en desarrollo
          --force-download   Vuelve a descargar aunque el fichero ya esté en disco
          --help             Esto

        La ingesta es idempotente: ejecutarla dos veces no cambia ningún contador.

        TXT;
    }
}
