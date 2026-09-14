<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Application\UseCase\ListSetProgress;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Doubles\ColeccionFalsa;

/**
 * El porcentaje de completado por edición.
 *
 * La agregación la hace MySQL con un `GROUP BY`; lo que se prueba aquí es la
 * **división**, que es donde están los dos casos que rompen la pantalla:
 * `mtg_set.total_set_size` es una columna **nullable**, y una edición sin tamaño
 * declarado no debe dar ni un `NaN` ni un "0 %" mentiroso.
 */
final class ListSetProgressTest extends TestCase
{
    /** @return list<array<string, mixed>> */
    private function progreso(): array
    {
        return [
            $this->edicion('LEA', 'Limited Edition Alpha', 295, 7),
            $this->edicion('C21', 'Commander 2021', 100, 50),
            // Una edición sin tamaño declarado: la columna es NULL en la BD.
            $this->edicion('PLST', 'The List', null, 3),
            // Y otra completa del todo.
            $this->edicion('MH2', 'Modern Horizons 2', 10, 10),
        ];
    }

    /** @return array<string, mixed> */
    private function edicion(string $codigo, string $nombre, ?int $tamano, int $propias): array
    {
        return [
            'setCode'        => $codigo,
            'setName'        => $nombre,
            'releaseDate'    => '2021-06-18',
            'totalSetSize'   => $tamano,
            'ownedPrintings' => $propias,
            'items'          => $propias,
            'copies'         => $propias * 2,
            'valueEur'       => 10.0,
        ];
    }

    private function conProgreso(array $sets, int $catalogo = 868): ListSetProgress
    {
        $repo                  = new ColeccionFalsa();
        $repo->progresoSets    = $sets;
        $repo->setsDelCatalogo = $catalogo;

        return new ListSetProgress($repo);
    }

    public function testElPorcentajeEsPrintingsPropiosEntreElTamanoDeLaEdicion(): void
    {
        $resultado = ($this->conProgreso($this->progreso()))(1);

        $lea = $this->porCodigo($resultado['sets'], 'LEA');

        // 7 / 295 = 2,37 %
        self::assertSame(2.4, $lea['percent']);
        self::assertFalse($lea['complete']);
    }

    public function testUnaEdicionSinTamanoDeclaradoNoDaNiNaNNiUnCeroFalso(): void
    {
        // `total_set_size` es NULL en la BD para algunas ediciones. El
        // porcentaje se queda a null —la vista dirá "tamaño desconocido"—, y
        // desde luego no es un 0 % ni una división por cero.
        $resultado = ($this->conProgreso($this->progreso()))(1);

        $lista = $this->porCodigo($resultado['sets'], 'PLST');

        self::assertNull($lista['percent']);
        self::assertFalse($lista['complete']);
        self::assertSame(3, $lista['ownedPrintings']);
    }

    public function testUnTamanoCeroTampocoDivide(): void
    {
        $resultado = ($this->conProgreso([$this->edicion('X', 'Rara', 0, 2)]))(1);

        self::assertNull($resultado['sets'][0]['percent']);
    }

    public function testTenerlaEnteraEsElCienPorCien(): void
    {
        $resultado = ($this->conProgreso($this->progreso()))(1);

        $mh2 = $this->porCodigo($resultado['sets'], 'MH2');

        self::assertSame(100.0, $mh2['percent']);
        self::assertTrue($mh2['complete']);
    }

    public function testLasEdicionesSinTamanoVanAlFinalYElRestoPorPorcentaje(): void
    {
        $resultado = ($this->conProgreso($this->progreso()))(1);

        // 100 % · 50 % · 2,4 % · sin tamaño
        self::assertSame(['MH2', 'C21', 'LEA', 'PLST'], array_column($resultado['sets'], 'setCode'));
    }

    public function testLosTotalesCuentanEmpezadasCompletasYSinTamano(): void
    {
        $resultado = ($this->conProgreso($this->progreso()))(1);

        self::assertSame(868, $resultado['totals']['catalogSets']);
        self::assertSame(4, $resultado['totals']['startedSets']);
        self::assertSame(1, $resultado['totals']['completedSets']);
        self::assertSame(1, $resultado['totals']['unsizedSets']);
        self::assertSame(70, $resultado['totals']['ownedPrintings']);
        self::assertSame(140, $resultado['totals']['totalCopies']);
    }

    public function testUnaColeccionVaciaNoRevienta(): void
    {
        $resultado = ($this->conProgreso([]))(1);

        self::assertSame([], $resultado['sets']);
        self::assertSame(0, $resultado['totals']['startedSets']);
        self::assertSame(868, $resultado['totals']['catalogSets']);
    }

    public function testSePuedeMirarElProgresoDeLaListaDeDeseos(): void
    {
        $repo                  = new ColeccionFalsa();
        $repo->setsDelCatalogo = 868;

        (new ListSetProgress($repo))(7, ['is_wishlist' => true]);

        self::assertSame(7, $repo->ultimoUserId);
        self::assertTrue($repo->ultimoWishlist);
    }

    /**
     * @param  list<array<string, mixed>> $filas
     * @return array<string, mixed>
     */
    private function porCodigo(array $filas, string $codigo): array
    {
        foreach ($filas as $fila) {
            if ($fila['setCode'] === $codigo) {
                return $fila;
            }
        }

        self::fail("No hay ninguna edición con código {$codigo}");
    }
}
