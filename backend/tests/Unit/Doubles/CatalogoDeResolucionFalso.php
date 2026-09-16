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

    /**
     * Impresiones sueltas CON número, que es lo único que mira el paso 2b.
     *
     * Van aparte de `$cartas` porque el paso 2b no cuenta cartas sino impresiones:
     * `Plains 250` es **una** carta y **catorce** impresiones, y es ese catorce el
     * que hace que el par ceda el turno.
     *
     * @var list<array<string, mixed>>
     */
    public array $impresiones = [];

    /** @var array<string, list<array<string, mixed>>> texto tecleado → lo que devuelve el FULLTEXT */
    public array $fulltext = [];

    /** @var array<string, list<string>> clave normalizada en otro idioma → oracle_ids */
    public array $localizados = [];

    /**
     * Clave localizada → idiomas en los que ese nombre está escrito.
     *
     * Es lo que decide el escalón 2 de la cascada del M8: una clave con un solo
     * idioma detrás lo declara, y una con varios **no se resuelve por mayoría**.
     * Va aparte de `$localizados` porque las dos cuentas son distintas: `a todo
     * vapor` es UNA carta y DOS idiomas.
     *
     * @var array<string, list<string>>
     */
    public array $idiomasLocalizados = [];

    /** @var array<string, int> Llamadas por método */
    public array $llamadas = [
        'impresionesPorScryfallId'    => 0,
        'impresionesPorSetYNumero'    => 0,
        'impresionesPorNombreYNumero' => 0,
        'cartasPorNombreNormalizado'  => 0,
        'cartasPorCaraFrontal'        => 0,
        'cartasPorNombreLocalizado'   => 0,
        'cartasPorTexto'              => 0,
        'cartasPorNombreAproximado'   => 0,
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

    /**
     * Añade UNA impresión al catálogo de mentira. Llamarlo varias veces con el
     * mismo nombre y número es como se reproduce una tierra básica.
     */
    public function conImpresion(string $name, string $oracleId, string $setCode, string $numero): self
    {
        $this->impresiones[] = [
            'printingUuid'    => 'uuid-' . strtolower($setCode) . '-' . $numero,
            'oracleId'        => $oracleId,
            'name'            => $name,
            'nameNormalized'  => $this->normalizador->normalizar($name),
            'setCode'         => $setCode,
            'collectorNumber' => $numero,
            'impresiones'     => 1,
        ];

        return $this;
    }

    public function impresionesPorNombreYNumero(array $pares): array
    {
        $this->llamadas[__FUNCTION__]++;

        $salida = [];

        foreach ($pares as $par) {
            $clave  = (string) $par['name'];
            $numero = (string) $par['collectorNumber'];

            // Como la consulta real: casa por la clave entera O por la cara
            // frontal, que es lo que salva a las cartas de doble cara.
            $casan = array_values(array_filter(
                $this->impresiones,
                fn (array $i): bool => $i['collectorNumber'] === $numero
                    && ($i['nameNormalized'] === $clave
                        || $this->normalizador->caraFrontal((string) $i['name']) === $clave)
            ));

            // Y el `HAVING COUNT(*) = 1` de la consulta real: con varias impresiones
            // detrás el par NO sale en el mapa. Esa ausencia es «cede el turno».
            if (count($casan) === 1) {
                $salida[$clave . '|' . $numero] = $casan[0];
            }
        }

        return $salida;
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
     * Da a una carta ya añadida un nombre en otro idioma, con el que el paso 3c
     * tiene que encontrarla.
     */
    public function conNombreLocalizado(string $oracleId, string $nombreLocalizado, string $idioma = 'Spanish'): self
    {
        $clave = $this->normalizador->normalizar($nombreLocalizado);

        $this->localizados[$clave][] = $oracleId;
        $this->idiomasLocalizados[$clave][] = $idioma;

        return $this;
    }

    public function cartasPorNombreLocalizado(array $claves): array
    {
        $this->llamadas[__FUNCTION__]++;

        $salida = [];

        foreach ($claves as $clave) {
            $ids = $this->localizados[$clave] ?? [];

            // La misma regla que la consulta real: el idioma se declara SOLO si
            // la clave apunta a uno. Con dos —`a todo vapor` es español y
            // portugués— el candidato sale con `language` a null, y es el
            // resolvedor quien tiene que respetarlo en vez de elegir mayoría.
            $idiomas = array_values(array_unique($this->idiomasLocalizados[$clave] ?? []));
            $idioma  = count($idiomas) === 1 ? $idiomas[0] : null;

            $salida[$clave] = array_map(
                static fn (array $carta): array => $carta + ['language' => $idioma],
                $this->candidatos(
                    static fn (array $carta): bool => in_array($carta['oracleId'], $ids, true)
                )
            );
        }

        return $salida;
    }

    /**
     * El paso 5, midiendo la distancia **de verdad** con la misma función que el
     * repositorio real y devolviendo **solo el escalón más cercano**.
     *
     * Un doble que devolviera una lista fija dejaría sin probar lo único que el
     * resolvedor decide aquí: que dos cartas igual de parecidas son un empate y
     * no una elección.
     */
    public function cartasPorNombreAproximado(string $nombre, int $maxima): array
    {
        $this->llamadas[__FUNCTION__]++;

        // El mismo corte que el repositorio real: por debajo de seis caracteres
        // no se busca parecido, porque lo que llega no son erratas sino trozos
        // de borde que el OCR lee en cualquier fotograma.
        if (mb_strlen($nombre) < 6) {
            return [];
        }

        $cerca = [];

        foreach ($this->cartas as $carta) {
            $distancia = levenshtein($nombre, $this->normalizador->normalizar($carta['name']));

            if ($distancia <= $maxima) {
                $cerca[] = ['distancia' => $distancia, 'carta' => $carta];
            }
        }

        if ($cerca === []) {
            return [];
        }

        $minima = min(array_column($cerca, 'distancia'));

        return array_values(array_map(
            static fn (array $c): array => $c['carta'],
            array_filter($cerca, static fn (array $c): bool => $c['distancia'] === $minima)
        ));
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
