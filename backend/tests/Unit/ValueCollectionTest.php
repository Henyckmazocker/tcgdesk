<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Application\UseCase\ValueCollection;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Doubles\ColeccionFalsa;

/**
 * La valoración es el punto donde el plan avisa dos veces: **una carta sin
 * precio en Cardmarket tiene que seguir contando** y **un foil vale otra cosa
 * que su versión normal**. Los dos casos están aquí.
 *
 * El total se recalcula en cada llamada, nunca se lee de una columna guardada:
 * los precios cambian a diario.
 */
final class ValueCollectionTest extends TestCase
{
    /**
     * Una colección pequeña con los tres casos difíciles: la joya cara, el mismo
     * printing en dos acabados, y cuarenta tierras que no cotizan.
     *
     * @return list<array<string, mixed>>
     */
    private function coleccion(): array
    {
        return [
            $this->linea(1, 'p-lotus', 'o-lotus', 'Black Lotus', 'LEA', 'Limited Edition Alpha', 'rare', 'normal', 1, 12000.0),
            $this->linea(2, 'p-sol', 'o-sol', 'Sol Ring', 'C21', 'Commander 2021', 'uncommon', 'normal', 4, 2.5),
            $this->linea(3, 'p-sol', 'o-sol', 'Sol Ring', 'C21', 'Commander 2021', 'uncommon', 'foil', 1, 9.0),
            $this->linea(4, 'p-island', 'o-island', 'Island', 'C21', 'Commander 2021', 'common', 'normal', 40, null),
            $this->linea(5, 'p-ragavan', 'o-ragavan', 'Ragavan', 'MH2', 'Modern Horizons 2', 'mythic', 'normal', 2, 1.5),
        ];
    }

    /** @return array<string, mixed> */
    private function linea(
        int $id,
        string $printing,
        string $oracle,
        string $nombre,
        string $set,
        string $setName,
        string $rareza,
        string $acabado,
        int $cantidad,
        ?float $precio
    ): array {
        return [
            'id'           => $id,
            'printingUuid' => $printing,
            'oracleId'     => $oracle,
            'name'         => $nombre,
            'setCode'      => $set,
            'setName'      => $setName,
            'rarity'       => $rareza,
            'finish'       => $acabado,
            'quantity'     => $cantidad,
            'priceEur'     => $precio,
            'lineValue'    => $cantidad * ($precio ?? 0.0),
        ];
    }

    public function testElValorTotalEsLaSumaDeCantidadPorPrecio(): void
    {
        // 12000 + (4 × 2,5) + 9 + (40 × sin precio) + (2 × 1,5) = 12.022 €
        $resumen = (new ValueCollection(new ColeccionFalsa($this->coleccion())))(1);

        self::assertSame(12022.0, $resumen['totals']['valueEur']);
    }

    public function testUnaCartaSinPrecioNoRompeElTotalNiDesaparece(): void
    {
        // El COALESCE(..., 0) del plan: suma 0 al valor, pero la línea sigue
        // contando como carta y como ejemplares. Y se informa aparte de cuántas
        // no cotizan, porque si no un total bajo sería ambiguo.
        $resumen = (new ValueCollection(new ColeccionFalsa($this->coleccion())))(1);

        self::assertSame(5, $resumen['totals']['uniqueItems']);
        self::assertSame(1, $resumen['totals']['itemsWithoutPrice']);
        self::assertSame(48, $resumen['totals']['totalCopies']);
    }

    public function testUnFoilSeValoraAparteDeSuVersionNormal(): void
    {
        // Mismo printing, dos acabados, dos precios distintos: son dos líneas y
        // el desglose de la edición tiene que sumar las dos.
        $resumen = (new ValueCollection(new ColeccionFalsa($this->coleccion())))(1);

        $c21 = $this->porClave($resumen['bySet'], 'setCode', 'C21');

        self::assertSame(19.0, $c21['valueEur'], '4×2,5 del normal + 9 del foil + 0 de las islas');
        self::assertSame(3, $c21['items']);
        self::assertSame(45, $c21['copies']);
    }

    public function testCartasUnicasNoEsLoMismoQueEjemplares(): void
    {
        // Tres números distintos: líneas (una por acabado/idioma/estado),
        // impresiones y cartas del oráculo. 'p-sol' aparece en dos líneas.
        $resumen = (new ValueCollection(new ColeccionFalsa($this->coleccion())))(1);

        self::assertSame(5, $resumen['totals']['uniqueItems']);
        self::assertSame(4, $resumen['totals']['uniquePrintings']);
        self::assertSame(4, $resumen['totals']['uniqueCards']);
        self::assertSame(48, $resumen['totals']['totalCopies']);
    }

    public function testElDesgloseSeOrdenaPorValorEnLasEdicionesYPorEscalaEnLasRarezas(): void
    {
        $resumen = (new ValueCollection(new ColeccionFalsa($this->coleccion())))(1);

        self::assertSame(['LEA', 'C21', 'MH2'], array_column($resumen['bySet'], 'setCode'));
        self::assertSame(['mythic', 'rare', 'uncommon', 'common'], array_column($resumen['byRarity'], 'rarity'));
    }

    public function testElDesgloseSumaExactamenteElTotal(): void
    {
        // Total y desgloses salen de la misma pasada: no pueden contradecirse.
        $resumen = (new ValueCollection(new ColeccionFalsa($this->coleccion())))(1);

        self::assertSame($resumen['totals']['valueEur'], array_sum(array_column($resumen['bySet'], 'valueEur')));
        self::assertSame($resumen['totals']['valueEur'], array_sum(array_column($resumen['byRarity'], 'valueEur')));
    }

    public function testElTopEsPorPrecioDeLaCartaYDejaFueraLasQueNoCotizan(): void
    {
        $resumen = (new ValueCollection(new ColeccionFalsa($this->coleccion())))(1);

        self::assertSame(['Black Lotus', 'Sol Ring', 'Sol Ring', 'Ragavan'], array_column($resumen['topCards'], 'name'));
        self::assertSame([12000.0, 9.0, 2.5, 1.5], array_column($resumen['topCards'], 'priceEur'));
    }

    public function testElTopSeQuedaEnDiez(): void
    {
        $lineas = [];
        for ($i = 1; $i <= 25; $i++) {
            $lineas[] = $this->linea($i, "p{$i}", "o{$i}", "Carta {$i}", 'C21', 'Commander 2021', 'rare', 'normal', 1, (float) $i);
        }

        $resumen = (new ValueCollection(new ColeccionFalsa($lineas)))(1);

        self::assertCount(ValueCollection::TOP, $resumen['topCards']);
        self::assertSame(25.0, $resumen['topCards'][0]['priceEur']);
    }

    public function testUnaColeccionVaciaValeCeroYNoRevienta(): void
    {
        $resumen = (new ValueCollection(new ColeccionFalsa([])))(1);

        self::assertSame(0.0, $resumen['totals']['valueEur']);
        self::assertSame(0, $resumen['totals']['uniqueItems']);
        self::assertSame([], $resumen['bySet']);
        self::assertSame([], $resumen['topCards']);
    }

    public function testSePuedeValorarLaListaDeDeseos(): void
    {
        // Cuánto costaría comprar lo que quieres: el mismo cálculo sobre la otra
        // lista.
        $repo = new ColeccionFalsa($this->coleccion());

        (new ValueCollection($repo))(7, ['is_wishlist' => true]);

        self::assertSame(7, $repo->ultimoUserId);
        self::assertTrue($repo->ultimoWishlist);
    }

    /**
     * @param  list<array<string, mixed>> $filas
     * @return array<string, mixed>
     */
    private function porClave(array $filas, string $clave, string $valor): array
    {
        foreach ($filas as $fila) {
            if ($fila[$clave] === $valor) {
                return $fila;
            }
        }

        self::fail("No hay ninguna fila con {$clave} = {$valor}");
    }
}
