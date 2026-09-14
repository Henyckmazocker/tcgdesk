<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Application\UseCase\AddToCollection;
use App\Application\UseCase\UpdateQuantity;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Doubles\ColeccionFalsa;

/**
 * `UpdateQuantity` fija la cantidad (es la edición en línea de la vista), a
 * diferencia de `AddToCollection`, que suma. Y **cero borra la fila**: si se
 * quedara a 0 seguiría contando como carta única en el dashboard.
 */
final class UpdateQuantityTest extends TestCase
{
    private ColeccionFalsa $repo;

    protected function setUp(): void
    {
        $this->repo = new ColeccionFalsa();
        (new AddToCollection($this->repo))(1, ['printing_uuid' => 'uuid-x', 'quantity' => 3]);
    }

    public function testLaCantidadEsAbsolutaNoUnIncremento(): void
    {
        $resultado = (new UpdateQuantity($this->repo))(1, ['item_id' => 1, 'quantity' => 5]);

        self::assertSame(5, $resultado['item']['quantity']);
        self::assertFalse($resultado['removed']);
    }

    public function testCantidadCeroBorraLaFila(): void
    {
        $resultado = (new UpdateQuantity($this->repo))(1, ['item_id' => 1, 'quantity' => 0]);

        self::assertTrue($resultado['removed']);
        self::assertNull($resultado['item']);
        self::assertSame([], $this->repo->filas, 'Una fila a 0 sería un fantasma en los agregados');
    }

    public function testUnaLineaQueNoExisteSeDistingueDeUnaBorrada(): void
    {
        // El repositorio devuelve null en los dos casos; el use case tiene que
        // separar el 404 del "sí, la he borrado porque me pediste 0".
        self::assertNull((new UpdateQuantity($this->repo))(1, ['item_id' => 404, 'quantity' => 2]));
    }

    public function testNoSePuedeTocarLaLineaDeOtroUsuario(): void
    {
        self::assertNull((new UpdateQuantity($this->repo))(2, ['item_id' => 1, 'quantity' => 9]));
        self::assertSame(3, $this->repo->filas[1]['quantity']);
    }

    public function testUnaCantidadNegativaSeRechaza(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new UpdateQuantity($this->repo))(1, ['item_id' => 1, 'quantity' => -1]);
    }

    public function testSinItemIdNoHayNadaQueActualizar(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new UpdateQuantity($this->repo))(1, ['quantity' => 2]);
    }
}
