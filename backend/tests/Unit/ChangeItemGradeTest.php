<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Application\UseCase\AddToCollection;
use App\Application\UseCase\ChangeItemGrade;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Doubles\ColeccionFalsa;

/**
 * `ChangeItemGrade` mueve una línea a otro estado físico.
 *
 * Lo que hay que probar no es que escriba una columna —eso lo haría un `UPDATE`
 * cualquiera—, sino qué pasa cuando el destino **ya existe**: `condition_grade`
 * está dentro del `UNIQUE KEY uq_item`, así que cambiarlo mueve la fila a una
 * combinación que puede estar ocupada, y entonces las dos son la misma carta en
 * el mismo estado y deben fundirse sumando cantidades. El doble reproduce ese
 * `UNIQUE KEY`, así que estos tests fallarían con un repositorio que se limitara
 * a reescribir la columna.
 */
final class ChangeItemGradeTest extends TestCase
{
    private ColeccionFalsa $repo;

    private ChangeItemGrade $cambiar;

    protected function setUp(): void
    {
        $this->repo    = new ColeccionFalsa();
        $this->cambiar = new ChangeItemGrade($this->repo);

        // Línea 1: la misma carta en NM, tres ejemplares.
        (new AddToCollection($this->repo))(1, ['printing_uuid' => 'uuid-x', 'quantity' => 3]);
    }

    public function testConElDestinoLibreLaLineaSeMueveYConservaSuId(): void
    {
        $resultado = ($this->cambiar)(1, ['item_id' => 1, 'condition' => 'LP']);

        self::assertFalse($resultado['merged']);
        self::assertSame(1, $resultado['item']['id'], 'Sin colisión no hay motivo para cambiar de fila');
        self::assertSame('LP', $resultado['item']['condition']);
        self::assertSame(3, $resultado['item']['quantity'], 'Mover no crea ni destruye ejemplares');
        self::assertCount(1, $this->repo->filas);
    }

    public function testSiElDestinoYaExisteLasDosLineasSeFundenSumando(): void
    {
        // Línea 2: la misma carta, ya en LP, con dos ejemplares.
        (new AddToCollection($this->repo))(1, [
            'printing_uuid' => 'uuid-x',
            'condition'     => 'LP',
            'quantity'      => 2,
        ]);
        self::assertCount(2, $this->repo->filas);

        $resultado = ($this->cambiar)(1, ['item_id' => 1, 'condition' => 'LP']);

        self::assertTrue($resultado['merged']);
        self::assertSame(2, $resultado['item']['id'], 'La superviviente es la fila de destino');
        self::assertSame(5, $resultado['item']['quantity'], '3 + 2: no se pierde ni se duplica ninguna');
        self::assertCount(1, $this->repo->filas, 'Dos filas con la misma clave violarían uq_item');
    }

    public function testLaFusionSoloOcurreSiCOINCIDENLasOtrasCincoColumnas(): void
    {
        // Mismo printing y mismo estado de destino, pero FOIL: otra combinación
        // de la clave, y un foil no vale lo mismo. No debe fundirse.
        (new AddToCollection($this->repo))(1, [
            'printing_uuid' => 'uuid-x',
            'finish'        => 'foil',
            'condition'     => 'LP',
            'quantity'      => 2,
        ]);

        $resultado = ($this->cambiar)(1, ['item_id' => 1, 'condition' => 'LP']);

        self::assertFalse($resultado['merged']);
        self::assertCount(2, $this->repo->filas);
        self::assertSame(3, $resultado['item']['quantity']);
        self::assertSame(2, $this->repo->filas[2]['quantity'], 'El foil no se ha tocado');
    }

    public function testElNombreLargoYElCodigoCortoSonElMismoEstado(): void
    {
        // Es LA razón de ser del objeto de valor: si 'Near Mint' no normalizara
        // a 'NM', esto movería la fila a un estado inexistente y partiría la
        // colección en dos.
        $resultado = ($this->cambiar)(1, ['item_id' => 1, 'condition' => 'Near Mint']);

        self::assertFalse($resultado['merged']);
        self::assertSame('NM', $resultado['item']['condition']);
        self::assertSame(3, $resultado['item']['quantity'], 'Pedir el estado que ya tiene no cambia nada');
    }

    public function testUnaLineaQueNoExisteDaNullParaQueElControllerResponda404(): void
    {
        self::assertNull(($this->cambiar)(1, ['item_id' => 404, 'condition' => 'EX']));
    }

    public function testNoSePuedeTocarLaLineaDeOtroUsuario(): void
    {
        self::assertNull(($this->cambiar)(2, ['item_id' => 1, 'condition' => 'PO']));
        self::assertSame('NM', $this->repo->filas[1]['condition_grade']);
    }

    public function testUnEstadoInventadoSeRechazaEnVezDeCaerEnElPorDefecto(): void
    {
        // Guardarlo como NM «porque es lo normal» falsearía el inventario en
        // silencio: el use case escribe, y lo que escribe no se adivina.
        $this->expectException(InvalidArgumentException::class);
        ($this->cambiar)(1, ['item_id' => 1, 'condition' => 'perfecta']);
    }

    public function testSinCondicionNoHayNadaQueCambiar(): void
    {
        $this->expectException(InvalidArgumentException::class);
        ($this->cambiar)(1, ['item_id' => 1]);
    }

    public function testSinItemIdNoHayNadaQueCambiar(): void
    {
        $this->expectException(InvalidArgumentException::class);
        ($this->cambiar)(1, ['condition' => 'EX']);
    }
}
