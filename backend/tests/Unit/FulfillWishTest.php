<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Application\UseCase\AddToCollection;
use App\Application\UseCase\ChangeItemGrade;
use App\Application\UseCase\FulfillWish;
use App\Application\UseCase\ListCollection;
use App\Application\UseCase\ListSetProgress;
use App\Application\UseCase\ListWishedPrintings;
use App\Application\UseCase\RemoveFromCollection;
use App\Application\UseCase\UpdateQuantity;
use App\Application\UseCase\ValueCollection;
use App\Controllers\CollectionController;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Tests\Unit\Doubles\ColeccionFalsa;

/**
 * `FulfillWish` cruza una línea de deseos a la colección.
 *
 * La mecánica —transacción, fusión, borrado del origen— es de `moveLine()` y la
 * prueba `MoveLineTest`. Lo que se prueba aquí es lo único que el repositorio
 * **no puede** decidir, porque `moveLine()` mueve cualquier línea a cualquier
 * combinación sin preguntar de dónde viene:
 *
 *  - que la línea **sea** un deseo, y que no serlo sea **422** y no un 200 sin
 *    efecto —un 200 silencioso escondería que la vista ofreció el botón «ya la
 *    tengo» sobre una línea de colección—;
 *  - que `quantity` no supere lo deseado, con el mensaje que lee el usuario;
 *  - que ausente signifique **uno** y el null explícito **la fila entera**.
 *
 * Los dos últimos tests montan el `CollectionController` entero porque la
 * traducción a códigos HTTP es justamente lo que distingue el 404 del 422, y esa
 * decisión no está en el use case: está en el controller.
 */
final class FulfillWishTest extends TestCase
{
    private ColeccionFalsa $repo;

    private AddToCollection $anadir;

    private FulfillWish $cumplir;

    protected function setUp(): void
    {
        $this->repo    = new ColeccionFalsa();
        $this->anadir  = new AddToCollection($this->repo);
        $this->cumplir = new FulfillWish($this->repo);
    }

    public function testCumplirUnoDeUnDeseoDeCuatroDejaElDeseoEnTresYLaColeccionEnUno(): void
    {
        $this->deseo(4);

        $resultado = ($this->cumplir)(1, ['item_id' => 1, 'quantity' => 1]);

        self::assertFalse($resultado['merged'], 'No había nada en la colección con lo que fundir');
        self::assertFalse($resultado['item']['isWishlist'], 'Ha cruzado a la colección');
        self::assertSame(1, $resultado['item']['quantity']);
        self::assertSame(3, $resultado['origen']['quantity'], '4 - 1: el deseo sigue vivo');
        self::assertCount(2, $this->repo->filas);
    }

    public function testSobreUnaLineaQueNoEsUnDeseoEsUnErrorDelClienteYNoUnaOperacionSinEfecto(): void
    {
        $this->enColeccion(3);

        try {
            ($this->cumplir)(1, ['item_id' => 1]);
            self::fail('Cumplir un deseo que no existe tiene que fallar, no devolver 200');
        } catch (InvalidArgumentException) {
            self::assertSame(3, $this->repo->filas[1]['quantity'], 'Y no se ha tocado la línea');
            self::assertCount(1, $this->repo->filas);
        }
    }

    public function testSinCantidadSeCumpleUnSoloEjemplar(): void
    {
        // El gesto normal: se compra UNA carta y se pulsa el botón. Cumplir las
        // cuatro por no decir nada sería perder la lista de deseos entera.
        $this->deseo(4);

        $resultado = ($this->cumplir)(1, ['item_id' => 1]);

        self::assertSame(1, $resultado['item']['quantity']);
        self::assertSame(3, $resultado['origen']['quantity']);
    }

    public function testLaCantidadNullExplicitaCumpleLaFilaEntera(): void
    {
        $this->deseo(4);

        $resultado = ($this->cumplir)(1, ['item_id' => 1, 'quantity' => null]);

        self::assertSame(4, $resultado['item']['quantity'], 'Las cuatro a la vez');
        self::assertNull($resultado['origen'], 'El deseo se ha agotado, así que se borra');
        self::assertCount(1, $this->repo->filas);
    }

    public function testCumplirMasEjemplaresDeLosDeseadosNoEscribeNada(): void
    {
        $this->deseo(2);

        try {
            ($this->cumplir)(1, ['item_id' => 1, 'quantity' => 3]);
            self::fail('Cumplir 3 de un deseo de 2 tiene que fallar');
        } catch (InvalidArgumentException $e) {
            self::assertStringContainsString('2', $e->getMessage(), 'El mensaje dice cuántos había');
            self::assertSame(2, $this->repo->filas[1]['quantity'], 'El deseo no se ha tocado');
            self::assertCount(1, $this->repo->filas);
        }
    }

    public function testCumplirCeroEjemplaresEsUnError(): void
    {
        $this->deseo(4);

        $this->expectException(InvalidArgumentException::class);
        ($this->cumplir)(1, ['item_id' => 1, 'quantity' => 0]);
    }

    public function testSinConditionElDeseoConservaElEstadoQueSeDeseaba(): void
    {
        $this->deseo(1, ['condition' => 'LP']);

        $resultado = ($this->cumplir)(1, ['item_id' => 1]);

        self::assertSame('LP', $resultado['item']['condition'], 'Nadie pidió cambiarlo');
    }

    public function testConConditionSeCruzanLasDosColumnasYPuedeFundir(): void
    {
        $this->deseo(1);
        $this->enColeccion(2, ['condition' => 'LP']);

        $resultado = ($this->cumplir)(1, ['item_id' => 1, 'condition' => 'Lightly Played']);

        self::assertTrue($resultado['merged'], 'Ya tenías esa misma carta en LP');
        self::assertSame(3, $resultado['item']['quantity'], '2 + 1');
        self::assertSame('LP', $resultado['item']['condition']);
        self::assertCount(1, $this->repo->filas, 'Dos filas con la misma clave violarían uq_item');
    }

    public function testUnEstadoQueNoSeEntiendeNoSeGuardaComoElPorDefecto(): void
    {
        // `Condition::desde()` y no `::intentar()`: esto es una escritura, y
        // guardar NM porque no se entendió lo que llegó falsearía el inventario
        // en silencio. Mismo criterio que `ChangeItemGrade`.
        $this->deseo(1);

        $this->expectException(InvalidArgumentException::class);
        ($this->cumplir)(1, ['item_id' => 1, 'condition' => 'casi nueva']);
    }

    public function testSinItemIdNoHayNadaQueCumplir(): void
    {
        $this->expectException(InvalidArgumentException::class);
        ($this->cumplir)(1, ['quantity' => 1]);
    }

    public function testNoSePuedeCumplirElDeseoDeOtroUsuario(): void
    {
        $this->deseo(1);

        self::assertNull(($this->cumplir)(2, ['item_id' => 1]), 'null para que el controller responda 404');
        self::assertSame(1, $this->repo->filas[1]['is_wishlist'], 'Sigue siendo un deseo');
    }

    /**
     * El *Hecho cuando* del hito, por el camino del controller: es donde se
     * decide el código HTTP, que es la mitad del contrato de la acción.
     */
    public function testElControllerDevuelve200ConElDeseoEnTresYLaColeccionEnUno(): void
    {
        $this->deseo(4);

        $respuesta = $this->controller()->fulfillWish([
            'user_id' => 1,
            'action'  => 'collection_fulfill_wish',
            'data'    => ['item_id' => 1, 'quantity' => 1],
        ]);

        self::assertSame('success', $respuesta['status']);
        self::assertSame(200, $respuesta['http_code']);
        self::assertSame(1, $respuesta['data']['item']['quantity'], 'Uno en la colección');
        self::assertFalse($respuesta['data']['item']['isWishlist']);
        self::assertSame(3, $respuesta['data']['origen']['quantity'], 'Tres siguen deseados');
    }

    public function testElControllerDevuelve422SobreUnaLineaQueNoEsUnDeseo(): void
    {
        $this->enColeccion(3);

        $respuesta = $this->controller()->fulfillWish([
            'user_id' => 1,
            'action'  => 'collection_fulfill_wish',
            'data'    => ['item_id' => 1],
        ]);

        self::assertSame('error', $respuesta['status']);
        self::assertSame(422, $respuesta['http_code'], 'Un 200 silencioso escondería el bug de la vista');
    }

    public function testElControllerDevuelve404SiLaLineaNoExiste(): void
    {
        $respuesta = $this->controller()->fulfillWish([
            'user_id' => 1,
            'action'  => 'collection_fulfill_wish',
            'data'    => ['item_id' => 404],
        ]);

        self::assertSame(404, $respuesta['http_code']);
    }

    private function controller(): CollectionController
    {
        return new CollectionController(
            $this->anadir,
            new UpdateQuantity($this->repo),
            new ChangeItemGrade($this->repo),
            $this->cumplir,
            new RemoveFromCollection($this->repo),
            new ListCollection($this->repo),
            new ValueCollection($this->repo),
            new ListSetProgress($this->repo),
            new ListWishedPrintings($this->repo),
            new NullLogger()
        );
    }

    /** @param array<string, mixed> $extra */
    private function deseo(int $cantidad, array $extra = []): void
    {
        ($this->anadir)(1, [
            'printing_uuid' => 'uuid-x',
            'quantity'      => $cantidad,
            'is_wishlist'   => true,
        ] + $extra);
    }

    /** @param array<string, mixed> $extra */
    private function enColeccion(int $cantidad, array $extra = []): void
    {
        ($this->anadir)(1, [
            'printing_uuid' => 'uuid-x',
            'quantity'      => $cantidad,
        ] + $extra);
    }
}
