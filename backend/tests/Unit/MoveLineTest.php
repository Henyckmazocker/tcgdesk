<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Application\UseCase\AddToCollection;
use App\Domain\Collection\Condition;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Doubles\ColeccionFalsa;

/**
 * `moveLine()` mueve una línea a otra combinación del `UNIQUE KEY uq_item`.
 *
 * Es el caso general de `ChangeItemGradeTest`, y lo que añade es el movimiento
 * **parcial**: cumplir un deseo de uno cuando querías cuatro. Ahí hay que
 * escribir en dos filas a la vez —restar en el deseo, sumar o crear en la
 * colección— y ninguna de las dos puede quedarse a medias.
 *
 * Los cuatro casos que fija la tabla del plan, verificados antes en SQL contra
 * la BD de dev, son los cuatro primeros tests de aquí:
 *
 *  1. destino libre + total    → el deseo se convierte en colección, MISMO `id`
 *  2. destino libre + parcial  → deseo 3 + colección 1, dos filas
 *  3. destino ocupado + total  → deseo borrado, colección 3
 *  4. destino ocupado + parcial→ deseo 3, colección 3
 *
 * Van contra `ColeccionFalsa`, que reproduce el `UNIQUE KEY` de seis columnas:
 * un repositorio que se limitara a reescribir la columna no pasaría de la
 * tercera. Lo que aquí no se puede probar —y por eso está escrito en el
 * repositorio real y no en un test— son los bloqueos de InnoDB.
 */
final class MoveLineTest extends TestCase
{
    private ColeccionFalsa $repo;

    private AddToCollection $anadir;

    protected function setUp(): void
    {
        $this->repo   = new ColeccionFalsa();
        $this->anadir = new AddToCollection($this->repo);
    }

    public function testDestinoLibreYTotalElDeseoSeVuelveColeccionSinCambiarDeId(): void
    {
        $this->deseo(1);

        $resultado = $this->repo->moveLine(1, 1, ['isWishlist' => false]);

        self::assertFalse($resultado['merged']);
        self::assertSame(1, $resultado['item']['id'], 'Sin colisión no hay motivo para cambiar de fila');
        self::assertFalse($resultado['item']['isWishlist'], 'Ha cruzado a la colección');
        self::assertSame(1, $resultado['item']['quantity'], 'Mover no crea ni destruye ejemplares');
        self::assertNull($resultado['origen'], 'La combinación de partida se queda vacía');
        self::assertCount(1, $this->repo->filas);
    }

    public function testDestinoLibreYParcialLaLineaSePartEnDos(): void
    {
        $this->deseo(4, ['notes' => 'para el mazo de Atraxa']);

        $resultado = $this->repo->moveLine(1, 1, ['isWishlist' => false], 1);

        self::assertFalse($resultado['merged']);
        self::assertNotSame(1, $resultado['item']['id'], 'El trozo que se mueve es una fila nueva');
        self::assertSame(1, $resultado['item']['quantity']);
        self::assertFalse($resultado['item']['isWishlist']);
        self::assertSame(
            'para el mazo de Atraxa',
            $resultado['item']['notes'],
            'Partir una línea no es motivo para perder su comentario'
        );
        self::assertSame(1, $resultado['origen']['id'], 'El deseo sigue vivo con lo que no se movió');
        self::assertSame(3, $resultado['origen']['quantity'], '4 - 1');
        self::assertCount(2, $this->repo->filas);
    }

    public function testDestinoOcupadoYTotalLasDosFilasSeFundenYElOrigenDesaparece(): void
    {
        $this->deseo(1);
        $this->enColeccion(2);

        $resultado = $this->repo->moveLine(1, 1, ['isWishlist' => false]);

        self::assertTrue($resultado['merged']);
        self::assertSame(2, $resultado['item']['id'], 'La superviviente es la fila de destino');
        self::assertSame(3, $resultado['item']['quantity'], '2 + 1: ni se pierde ni se duplica');
        self::assertNull($resultado['origen'], 'El deseo se ha agotado, así que se borra');
        self::assertCount(1, $this->repo->filas, 'Dos filas con la misma clave violarían uq_item');
    }

    public function testDestinoOcupadoYParcialSeSumaAlliYSeDescuentaAqui(): void
    {
        $this->deseo(4);
        $this->enColeccion(2);

        $resultado = $this->repo->moveLine(1, 1, ['isWishlist' => false], 1);

        self::assertTrue($resultado['merged']);
        self::assertSame(2, $resultado['item']['id']);
        self::assertSame(3, $resultado['item']['quantity'], '2 + 1 en la colección');
        self::assertSame(3, $resultado['origen']['quantity'], '4 - 1 en los deseos');
        self::assertCount(2, $this->repo->filas, 'Las dos líneas siguen existiendo');
    }

    public function testSeMuevenLasDosColumnasALaVezYTambienFunden(): void
    {
        // Cumplir un deseo diciendo además en qué estado llegó la carta cruza
        // DOS columnas de la clave de una vez. Es lo que distingue `moveLine()`
        // de `changeGrade()`, y el destino puede estar ocupado igualmente.
        $this->deseo(1);
        $this->enColeccion(2, ['condition' => 'LP']);

        $resultado = $this->repo->moveLine(1, 1, [
            'condition'  => Condition::desde('LP'),
            'isWishlist' => false,
        ]);

        self::assertTrue($resultado['merged']);
        self::assertSame(3, $resultado['item']['quantity']);
        self::assertSame('LP', $resultado['item']['condition']);
        self::assertCount(1, $this->repo->filas);
    }

    public function testPedirLaCombinacionQueYaTieneNoMueveNada(): void
    {
        // No es un error del cliente: pedir lo que ya es cierto devuelve la
        // línea tal cual, y es el único caso en el que `origen` e `item` son la
        // misma fila.
        $this->deseo(4);

        $resultado = $this->repo->moveLine(1, 1, ['isWishlist' => true], 1);

        self::assertFalse($resultado['merged']);
        self::assertSame($resultado['item'], $resultado['origen']);
        self::assertSame(4, $resultado['item']['quantity'], 'No se ha partido nada');
        self::assertCount(1, $this->repo->filas);
    }

    public function testMoverMasEjemplaresDeLosQueHayNoEscribeNada(): void
    {
        // `quantity` es SMALLINT UNSIGNED: el origen no se quedaría en negativo,
        // reventaría la sentencia con la transacción a medias.
        $this->deseo(4);

        try {
            $this->repo->moveLine(1, 1, ['isWishlist' => false], 5);
            self::fail('Mover 5 de una línea de 4 tiene que fallar');
        } catch (InvalidArgumentException) {
            self::assertSame(4, $this->repo->filas[1]['quantity'], 'El origen no se ha tocado');
            self::assertCount(1, $this->repo->filas, 'Y no se ha creado el destino');
        }
    }

    public function testMoverCeroEjemplaresEsUnErrorYNoUnaOperacionSinEfecto(): void
    {
        $this->deseo(4);

        $this->expectException(InvalidArgumentException::class);
        $this->repo->moveLine(1, 1, ['isWishlist' => false], 0);
    }

    public function testUnaLineaQueNoExisteDaNullParaQueElControllerResponda404(): void
    {
        self::assertNull($this->repo->moveLine(1, 404, ['isWishlist' => false]));
    }

    public function testNoSePuedeMoverLaLineaDeOtroUsuario(): void
    {
        $this->deseo(1);

        self::assertNull($this->repo->moveLine(2, 1, ['isWishlist' => false]));
        self::assertSame(1, $this->repo->filas[1]['is_wishlist'], 'Sigue siendo un deseo');
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
