<?php

declare(strict_types=1);

namespace Tests\Unit\Doubles;

use App\Domain\Catalog\Cursor;
use App\Domain\Catalog\PreconPlayability;
use App\Domain\Catalog\PreconSearchCriteria;
use App\Domain\Repository\PreconRepositoryInterface;

/**
 * Precons de mentira, en memoria.
 *
 * No cuenta llamadas: **reproduce lo que la ruta necesita que sea cierto** —el
 * filtro por tipo y edición, el orden total `(name, file_name)` y la paginación
 * por cursor que arranca justo después de la última fila entregada—. Sin eso, el
 * test del router pasaría igual con una paginación que repite filas, que es
 * exactamente el fallo que el hito quiere evitar.
 *
 * Y guarda las cartas **con sus huérfanas incluidas** (`known: false`): son 254
 * en la base real y el contrato dice que salen marcadas, no que desaparecen.
 */
class PreconesFalsos implements PreconRepositoryInterface
{
    /** @var list<array<string, mixed>> Cabeceras, tal como las devuelve el repositorio real */
    public array $precons = [];

    /** @var array<string, list<array<string, mixed>>> file_name → sus cartas */
    public array $cartas = [];

    public ?PreconSearchCriteria $ultimosCriterios = null;

    public function buscar(PreconSearchCriteria $criterios): array
    {
        $this->ultimosCriterios = $criterios;

        $filas = array_values(array_filter($this->precons, static function (array $p) use ($criterios): bool {
            if ($criterios->deckType !== null && $p['deckType'] !== $criterios->deckType) {
                return false;
            }

            if ($criterios->setCode !== null && $p['setCode'] !== $criterios->setCode) {
                return false;
            }

            if ($criterios->soloJugables && !PreconPlayability::esJugable((string) $p['deckType'])) {
                return false;
            }

            return !$criterios->tieneTexto()
                || stripos((string) $p['name'], (string) $criterios->q) !== false;
        }));

        usort($filas, static fn (array $a, array $b): int
            => [$a['name'], $a['fileName']] <=> [$b['name'], $b['fileName']]);

        $datos = Cursor::decodificar($criterios->cursor);

        if ($datos !== null && isset($datos['v'], $datos['u'])) {
            $filas = array_values(array_filter(
                $filas,
                static fn (array $p): bool
                    => [$p['name'], $p['fileName']] > [(string) $datos['v'], (string) $datos['u']]
            ));
        }

        $pagina = array_slice($filas, 0, $criterios->limit);
        $hayMas = count($filas) > $criterios->limit;

        return [
            'items'      => $pagina,
            'nextCursor' => $hayMas && $pagina !== []
                ? Cursor::porColumna(
                    (string) $pagina[count($pagina) - 1]['name'],
                    (string) $pagina[count($pagina) - 1]['fileName']
                )
                : null,
        ];
    }

    /**
     * Las facetas, calculadas sobre los precons en memoria.
     *
     * Se agrupa de verdad en vez de devolver una lista fija: lo que el hito
     * protege es que los tipos salgan **del dato** y no de una constante, y un
     * doble que devolviera las 48 cadenas a mano probaría justo lo contrario.
     */
    public function facetas(): array
    {
        $tipos = [];
        $sets  = [];

        foreach ($this->precons as $precon) {
            $tipos[(string) $precon['deckType']] = ($tipos[(string) $precon['deckType']] ?? 0) + 1;

            $codigo = (string) $precon['setCode'];

            $sets[$codigo] ??= ['code' => $codigo, 'name' => $precon['setName'], 'count' => 0];
            $sets[$codigo]['count']++;
        }

        arsort($tipos);

        return [
            'types' => array_map(
                static fn (string $tipo, int $total): array => [
                    'type'     => $tipo,
                    'count'    => $total,
                    'playable' => PreconPlayability::esJugable($tipo),
                ],
                array_keys($tipos),
                array_values($tipos)
            ),
            'sets' => array_values($sets),
        ];
    }

    public function find(string $fileName): ?array
    {
        foreach ($this->precons as $precon) {
            if ($precon['fileName'] === $fileName) {
                return $precon;
            }
        }

        return null;
    }

    public function cartas(string $fileName): array
    {
        return $this->cartas[$fileName] ?? [];
    }

    // ---- Escritura: la ingesta de M2, que estos tests no ejercitan ----

    public function upsertPrecons(array $filas): int
    {
        return count($filas);
    }

    public function upsertCartas(array $filas): int
    {
        return count($filas);
    }

    public function actualizarCardCount(array $conteos): int
    {
        return count($conteos);
    }

    public function contadores(): array
    {
        return ['mtg_precon' => count($this->precons), 'mtg_precon_card' => 0];
    }

    public function huerfanos(): array
    {
        return ['filas' => 0, 'uuids' => 0];
    }
}
