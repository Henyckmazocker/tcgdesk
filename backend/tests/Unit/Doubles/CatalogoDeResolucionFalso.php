<?php

declare(strict_types=1);

namespace Tests\Unit\Doubles;

use App\Domain\Import\NameNormalizer;
use App\Domain\Repository\CardResolutionRepositoryInterface;

/**
 * Catálogo en memoria para probar el resolvedor sin MySQL.
 *
 * Reproduce lo único que cambia el veredicto: **cuántos candidatos devuelve cada
 * paso**. La normalización es la de verdad (`NameNormalizer`), porque la clave
 * con la que se indexa aquí tiene que ser exactamente la misma que calcula el
 * resolvedor: si el doble normalizase distinto, el test pasaría probando otra
 * cosa.
 *
 * Cuenta además las llamadas: el resolvedor promete resolver los pasos exactos
 * **en lote**, y una consulta por fila es un timeout con otro nombre en un
 * fichero de 20.000 líneas.
 */
class CatalogoDeResolucionFalso implements CardResolutionRepositoryInterface
{
    /** @var array<string, array<string, mixed>> scryfallId → impresión */
    public array $porScryfallId = [];

    /** @var array<string, array<string, mixed>> 'SET|numero' → impresión */
    public array $porSetYNumero = [];

    /** @var list<array{name: string, oracleId: string, printingUuid: ?string, setCode: ?string, impresiones: int}> */
    public array $cartas = [];

    /** @var array<string, list<array<string, mixed>>> texto tecleado → lo que devuelve el FULLTEXT */
    public array $fulltext = [];

    /** @var array<string, int> Llamadas por método */
    public array $llamadas = [
        'impresionesPorScryfallId'    => 0,
        'impresionesPorSetYNumero'    => 0,
        'cartasPorNombreNormalizado'  => 0,
        'cartasPorCaraFrontal'        => 0,
        'cartasPorTexto'              => 0,
    ];

    private NameNormalizer $normalizador;

    public function __construct()
    {
        $this->normalizador = new NameNormalizer();
    }

    /**
     * Añade una carta al catálogo de mentira. `$impresiones` es lo que decide si
     * el candidato trae impresión concreta o solo la carta.
     */
    public function conCarta(string $name, string $oracleId, int $impresiones = 1, ?string $printingUuid = null, ?string $setCode = null): self
    {
        $this->cartas[] = [
            'name'         => $name,
            'oracleId'     => $oracleId,
            'impresiones'  => $impresiones,
            'printingUuid' => $impresiones === 1 ? ($printingUuid ?? 'uuid-' . $oracleId) : null,
            'setCode'      => $impresiones === 1 ? ($setCode ?? 'TST') : null,
        ];

        return $this;
    }

    public function impresionesPorScryfallId(array $scryfallIds): array
    {
        $this->llamadas[__FUNCTION__]++;

        return array_intersect_key($this->porScryfallId, array_flip($scryfallIds));
    }

    public function impresionesPorSetYNumero(array $pares): array
    {
        $this->llamadas[__FUNCTION__]++;

        $claves = array_map(
            static fn (array $p): string => strtoupper($p['setCode']) . '|' . $p['collectorNumber'],
            $pares
        );

        return array_intersect_key($this->porSetYNumero, array_flip($claves));
    }

    public function cartasPorNombreNormalizado(array $claves): array
    {
        $this->llamadas[__FUNCTION__]++;

        $salida = [];

        foreach ($claves as $clave) {
            $salida[$clave] = $this->candidatos(
                fn (array $carta): bool => $this->normalizador->normalizar($carta['name']) === $clave
            );
        }

        return $salida;
    }

    public function cartasPorCaraFrontal(array $caras): array
    {
        $this->llamadas[__FUNCTION__]++;

        $salida = [];

        foreach ($caras as $cara) {
            $salida[$cara] = $this->candidatos(
                function (array $carta) use ($cara): bool {
                    $clave = $this->normalizador->normalizar($carta['name']);

                    return $this->normalizador->tieneVariasCaras($clave)
                        && str_starts_with($clave, $cara . NameNormalizer::SEPARADOR);
                }
            );
        }

        return $salida;
    }

    public function cartasPorTexto(string $texto, int $limite = 25): array
    {
        $this->llamadas[__FUNCTION__]++;

        return array_slice($this->fulltext[$texto] ?? [], 0, $limite);
    }

    /**
     * @param  callable(array<string, mixed>): bool $casa
     * @return list<array<string, mixed>>
     */
    private function candidatos(callable $casa): array
    {
        return array_values(array_filter($this->cartas, $casa));
    }
}
