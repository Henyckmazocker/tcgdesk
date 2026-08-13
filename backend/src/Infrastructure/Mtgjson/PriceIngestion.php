<?php

declare(strict_types=1);

namespace App\Infrastructure\Mtgjson;

use App\Domain\Repository\PriceRepositoryInterface;
use JsonMachine\Items;
use JsonMachine\JsonDecoder\ExtJsonDecoder;
use Psr\Log\LoggerInterface;

/**
 * El motor que comparten `prices:sync` y `prices:seed`.
 *
 * Los dos hacen lo mismo —recorrer un fichero de precios de MTGJSON y volcarlo a
 * `mtg_price_daily`— y solo difieren en dos cosas: el fichero y si además
 * reescriben `mtg_price_current`. Tenerlo aquí evita dos bucles casi idénticos
 * que se desincronizan a la primera corrección.
 */
class PriceIngestion
{
    public function __construct(
        private readonly PriceRepositoryInterface $precios,
        private readonly MtgJsonPriceExtractor $extractor,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * @param  callable(int, int): void|null $progreso uuids vistos, filas escritas
     * @return array<string, int>
     */
    public function ingerir(string $ruta, bool $actualizarVigentes, ?callable $progreso = null): array
    {
        // Los uuid del catálogo, en memoria. AllPricesToday trae precios de
        // cartas que no tenemos —MTGO y productos que MTGJSON lista aparte— y
        // esas filas violarían la clave foránea de mtg_price_daily. Filtrar aquí
        // las cuenta; dejar que MySQL las rechace las escondería.
        $conocidos = $this->precios->uuidsConocidos();

        $origen = str_ends_with($ruta, '.gz') ? 'compress.zlib://' . $ruta : $ruta;

        $items = Items::fromFile($origen, [
            'pointer' => '/data',
            'decoder' => new ExtJsonDecoder(true),
        ]);

        $stats = [
            'uuids'         => 0,
            'desconocidos'  => 0,
            'sin_precio'    => 0,
            'historico'     => 0,
            'vigentes'      => 0,
        ];

        $loteHistorico = [];
        $loteVigentes  = [];

        foreach ($items as $uuid => $precios) {
            $stats['uuids']++;

            if (!isset($conocidos[$uuid])) {
                $stats['desconocidos']++;
                continue;
            }

            $filas = $this->extractor->extraer($precios);

            if ($filas === []) {
                $stats['sin_precio']++;
                continue;
            }

            foreach ($filas as $fila) {
                $loteHistorico[] = ['printing_uuid' => $uuid] + $fila;
            }

            if ($actualizarVigentes) {
                foreach ($this->extractor->masRecientesPorAcabado($filas) as $fila) {
                    $loteVigentes[] = ['printing_uuid' => $uuid] + $fila;
                }
            }

            // Se vuelca por tramos para que la memoria no crezca con el fichero:
            // AllPrices trae 90 días por carta y acumularlo todo no cabe.
            if (count($loteHistorico) >= 5000) {
                $stats['historico'] += $this->precios->insertarHistorico($loteHistorico);
                $loteHistorico = [];

                if ($loteVigentes !== []) {
                    $stats['vigentes'] += $this->precios->reemplazarVigentes($loteVigentes);
                    $loteVigentes = [];
                }

                if ($progreso !== null) {
                    $progreso($stats['uuids'], $stats['historico']);
                }
            }
        }

        $stats['historico'] += $this->precios->insertarHistorico($loteHistorico);
        $stats['vigentes']  += $this->precios->reemplazarVigentes($loteVigentes);

        $this->logger->info('Ingesta de precios completada', $stats + ['fichero' => basename($ruta)]);

        return $stats;
    }
}
