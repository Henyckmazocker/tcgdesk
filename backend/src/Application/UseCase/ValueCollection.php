<?php

declare(strict_types=1);

namespace App\Application\UseCase;

use App\Domain\Repository\CollectionRepositoryInterface;

/**
 * Cuánto vale la colección, y de dónde sale ese valor.
 *
 * Entrega lo que pide el plan: **valor total en EUR**, **desglose por edición**,
 * **desglose por rareza**, **top 10 de cartas más caras** y **cartas únicas vs.
 * total de ejemplares**. (La vista del dashboard es M5; esto es solo el
 * cálculo.)
 *
 * Tres cosas que no son casuales:
 *
 * - **Se recalcula siempre, nunca se cachea en una columna.** Los precios
 *   cambian a diario: un `valor_total` guardado estaría mal el 100 % de los
 *   días.
 * - **Una carta sin precio en Cardmarket suma 0 al total pero sigue contando
 *   como carta.** Es el `COALESCE(..., 0)` del plan: el total no revienta, y la
 *   ficha conserva su `price` a `null` para poder decir "sin precio" en vez de
 *   "0 €". Por eso se informa aparte de cuántas líneas no cotizan: un total
 *   pequeño puede significar "no tengo nada caro" o "no tengo precios".
 * - **El precio ya viene por `(printing, finish)`**, así que un foil y su
 *   versión normal se valoran por separado aunque sean la misma carta.
 *
 * El agregado se hace aquí, en una sola pasada sobre las mismas filas, y no en
 * cuatro `GROUP BY` distintos: así el total y los desgloses no pueden
 * contradecirse, y el cálculo se prueba con un doble de repositorio.
 */
class ValueCollection
{
    public const TOP = 10;

    /** Orden de presentación del desglose por rareza. */
    private const ORDEN_RAREZA = ['mythic', 'rare', 'uncommon', 'common', 'special', 'bonus'];

    public function __construct(
        private readonly CollectionRepositoryInterface $coleccion
    ) {
    }

    /**
     * @param  array<string, mixed> $peticion
     * @return array<string, mixed>
     */
    public function __invoke(int $userId, array $peticion = []): array
    {
        $esDeseos = filter_var($peticion['is_wishlist'] ?? false, FILTER_VALIDATE_BOOL);

        $lineas = $this->coleccion->allLines($userId, $esDeseos);

        $valorTotal   = 0.0;
        $ejemplares   = 0;
        $sinPrecio    = 0;
        $printings    = [];
        $cartas       = [];
        $porEdicion   = [];
        $porRareza    = [];

        foreach ($lineas as $linea) {
            $cantidad = (int) ($linea['quantity'] ?? 0);
            $precio   = isset($linea['priceEur']) && $linea['priceEur'] !== null
                ? (float) $linea['priceEur']
                : null;

            // COALESCE(precio, 0) en el valor: una carta que no cotiza no debe
            // reventar el total ni excluir la línea del recuento.
            $valorLinea = $cantidad * ($precio ?? 0.0);

            $valorTotal += $valorLinea;
            $ejemplares += $cantidad;

            if ($precio === null) {
                $sinPrecio++;
            }

            $printings[(string) ($linea['printingUuid'] ?? '')] = true;
            $cartas[(string) ($linea['oracleId'] ?? '')]        = true;

            $codigo = (string) ($linea['setCode'] ?? '');
            $porEdicion[$codigo] ??= [
                'setCode'  => $codigo,
                'setName'  => (string) ($linea['setName'] ?? ''),
                'items'    => 0,
                'copies'   => 0,
                'valueEur' => 0.0,
            ];
            $porEdicion[$codigo]['items']++;
            $porEdicion[$codigo]['copies']   += $cantidad;
            $porEdicion[$codigo]['valueEur'] += $valorLinea;

            $rareza = (string) ($linea['rarity'] ?? '');
            $porRareza[$rareza] ??= [
                'rarity'   => $rareza,
                'items'    => 0,
                'copies'   => 0,
                'valueEur' => 0.0,
            ];
            $porRareza[$rareza]['items']++;
            $porRareza[$rareza]['copies']   += $cantidad;
            $porRareza[$rareza]['valueEur'] += $valorLinea;
        }

        return [
            'totals' => [
                'valueEur'  => $this->redondear($valorTotal),
                // Cartas únicas vs. ejemplares. Son TRES números distintos y los
                // tres se piden alguna vez: líneas de colección (una por
                // combinación de acabado/idioma/estado), impresiones distintas y
                // cartas distintas del oráculo. Un playset de cuatro Rayos de la
                // misma edición es 1 línea, 1 printing, 1 carta y 4 ejemplares.
                'uniqueItems'     => count($lineas),
                'uniquePrintings' => count($printings),
                'uniqueCards'     => count($cartas),
                'totalCopies'     => $ejemplares,
                // Cuántas líneas no cotizan en Cardmarket. Sin este dato, un
                // total bajo es ambiguo.
                'itemsWithoutPrice' => $sinPrecio,
            ],
            'bySet'    => $this->porValor($porEdicion),
            'byRarity' => $this->porRareza($porRareza),
            'topCards' => $this->masCaras($lineas),
        ];
    }

    /**
     * Ediciones de más valor primero; a igualdad, por nombre.
     *
     * @param  array<string, array<string, mixed>> $grupos
     * @return list<array<string, mixed>>
     */
    private function porValor(array $grupos): array
    {
        $filas = array_values($grupos);

        usort($filas, static fn (array $a, array $b): int
            => [$b['valueEur'], $a['setName']] <=> [$a['valueEur'], $b['setName']]);

        return array_map(
            fn (array $f): array => array_replace($f, ['valueEur' => $this->redondear($f['valueEur'])]),
            $filas
        );
    }

    /**
     * El desglose por rareza va en el orden de la rareza, no del valor: es una
     * escala, y verla desordenada no dice nada.
     *
     * @param  array<string, array<string, mixed>> $grupos
     * @return list<array<string, mixed>>
     */
    private function porRareza(array $grupos): array
    {
        $filas = array_values($grupos);

        usort($filas, static function (array $a, array $b): int {
            $posA = array_search($a['rarity'], self::ORDEN_RAREZA, true);
            $posB = array_search($b['rarity'], self::ORDEN_RAREZA, true);

            return ($posA === false ? PHP_INT_MAX : $posA) <=> ($posB === false ? PHP_INT_MAX : $posB);
        });

        return array_map(
            fn (array $f): array => array_replace($f, ['valueEur' => $this->redondear($f['valueEur'])]),
            $filas
        );
    }

    /**
     * Las diez líneas de mayor precio unitario.
     *
     * Por precio de la carta y no por valor de la línea: "mis joyas" es el Black
     * Lotus, no las cuarenta islas que suman más entre todas. Las que no cotizan
     * quedan fuera —no son las más caras, es que no se sabe—.
     *
     * @param  list<array<string, mixed>> $lineas
     * @return list<array<string, mixed>>
     */
    private function masCaras(array $lineas): array
    {
        $conPrecio = array_values(array_filter(
            $lineas,
            static fn (array $l): bool => isset($l['priceEur']) && $l['priceEur'] !== null
        ));

        usort($conPrecio, static fn (array $a, array $b): int => (float) $b['priceEur'] <=> (float) $a['priceEur']);

        return array_slice($conPrecio, 0, self::TOP);
    }

    /** Dos decimales: son euros, y la suma de céntimos en coma flotante deriva. */
    private function redondear(float $valor): float
    {
        return round($valor, 2);
    }
}
