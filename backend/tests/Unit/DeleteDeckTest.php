<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Application\UseCase\AddCardToDeck;
use App\Application\UseCase\AddToCollection;
use App\Application\UseCase\CreateDeck;
use App\Application\UseCase\DeleteDeck;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Doubles\ColeccionFalsa;
use Tests\Unit\Doubles\MazosFalsos;

/**
 * `DeleteDeck` es el use case delicado del hito.
 *
 * `with_cards` decide entre dos operaciones muy distintas: borrar la lista, o
 * borrar la lista **y descontar sus cartas de la colección**. Lo que hay que
 * probar es el caso incómodo: **la colección tiene menos de lo que dice el
 * mazo**. No es un error —vendiste la carta y nunca actualizaste el mazo—, así
 * que se resta hasta 0, se borra la fila y la diferencia se devuelve para que la
 * UI la enseñe.
 */
final class DeleteDeckTest extends TestCase
{
    private ColeccionFalsa $coleccion;

    private MazosFalsos $repo;

    private DeleteDeck $borrar;

    protected function setUp(): void
    {
        $this->coleccion = new ColeccionFalsa();
        $this->repo      = new MazosFalsos($this->coleccion);
        $this->borrar    = new DeleteDeck($this->repo);

        (new CreateDeck($this->repo))(1, ['name' => 'Atraxa', 'status' => 'built']);
    }

    public function testSinWithCardsLaColeccionNoSeToca(): void
    {
        // Deshacer un mazo no es vender sus cartas.
        $this->anadirAlMazo(['printing_uuid' => 'uuid-sol', 'count' => 2]);
        $this->anadirALaColeccion(['printing_uuid' => 'uuid-sol', 'quantity' => 2]);

        $resultado = ($this->borrar)(1, ['deck_id' => 1]);

        self::assertTrue($resultado['deleted']);
        self::assertSame(0, $resultado['removedFromCollection']);
        self::assertSame([], $resultado['shortfall']);
        self::assertSame(2, $this->coleccion->filas[1]['quantity']);
        self::assertCount(0, $this->repo->mazos);
        self::assertCount(0, $this->repo->cartas, 'ON DELETE CASCADE se lleva las líneas');
    }

    public function testConWithCardsSeDescuentaYLaFilaAceroSeBorra(): void
    {
        // Igual que changeQuantity(0): una fila a cero seguiría contando como
        // «carta única» en el dashboard.
        $this->anadirAlMazo(['printing_uuid' => 'uuid-sol', 'count' => 2]);
        $this->anadirAlMazo(['printing_uuid' => 'uuid-bolt', 'count' => 1]);
        $this->anadirALaColeccion(['printing_uuid' => 'uuid-sol', 'quantity' => 2]);
        $this->anadirALaColeccion(['printing_uuid' => 'uuid-bolt', 'quantity' => 4]);

        $resultado = ($this->borrar)(1, ['deck_id' => 1, 'with_cards' => true]);

        self::assertSame(3, $resultado['removedFromCollection']);
        self::assertSame([], $resultado['shortfall']);
        self::assertArrayNotHasKey(1, $this->coleccion->filas, 'La línea del Sol Ring llegó a 0 y se borró');
        self::assertSame(3, $this->coleccion->filas[2]['quantity'], 'La del Bolt baja de 4 a 3');
    }

    public function testConLaColeccionInsuficienteSeRestaHastaCeroSinFallarYSeDevuelveLaDiferencia(): void
    {
        // EL caso del plan. Vendiste dos Sol Ring y nunca actualizaste el mazo:
        // la discrepancia es lo esperado, no el error.
        $this->anadirAlMazo(['printing_uuid' => 'uuid-sol', 'count' => 3]);
        $this->anadirAlMazo(['printing_uuid' => 'uuid-nada', 'count' => 2]);
        $this->anadirALaColeccion(['printing_uuid' => 'uuid-sol', 'quantity' => 1]);

        $resultado = ($this->borrar)(1, ['deck_id' => 1, 'with_cards' => true]);

        self::assertTrue($resultado['deleted']);
        self::assertSame(1, $resultado['removedFromCollection'], 'Solo había una, y una se quitó');
        self::assertCount(2, $resultado['shortfall']);

        $porCarta = array_column($resultado['shortfall'], null, 'printingUuid');

        self::assertSame(3, $porCarta['uuid-sol']['requested']);
        self::assertSame(1, $porCarta['uuid-sol']['removed']);
        self::assertSame(2, $porCarta['uuid-sol']['missing']);

        // La que no estaba en la colección ni siquiera existía como fila.
        self::assertSame(0, $porCarta['uuid-nada']['removed']);
        self::assertSame(2, $porCarta['uuid-nada']['missing']);

        self::assertSame([], $this->coleccion->filas, 'Esas líneas quedan a 0, es decir, borradas');
    }

    public function testLaListaDeDeseosNoSeDescuenta(): void
    {
        // Sin el filtro is_wishlist = 0, desmontar un mazo descontaría de lo que
        // QUIERES en vez de lo que tienes.
        $this->anadirAlMazo(['printing_uuid' => 'uuid-sol', 'count' => 1]);
        $this->anadirALaColeccion(['printing_uuid' => 'uuid-sol', 'quantity' => 1, 'is_wishlist' => true]);

        $resultado = ($this->borrar)(1, ['deck_id' => 1, 'with_cards' => true]);

        self::assertSame(0, $resultado['removedFromCollection']);
        self::assertSame(1, $this->coleccion->filas[1]['quantity'], 'La wishlist se queda como estaba');
        self::assertSame(1, $resultado['shortfall'][0]['missing']);
    }

    public function testLosTokensNoSeDescuentanDeLaColeccion(): void
    {
        // Un token no es una carta que se posea, así que nunca estuvo en la
        // colección de la que restar: ni se descuenta ni sale en el shortfall.
        $this->anadirAlMazo(['printing_uuid' => 'uuid-token', 'board' => 'tokens', 'count' => 5]);

        $resultado = ($this->borrar)(1, ['deck_id' => 1, 'with_cards' => true]);

        self::assertSame(0, $resultado['removedFromCollection']);
        self::assertSame([], $resultado['shortfall']);
    }

    public function testLaMismaCartaEnElMainYEnElSideSeDescuentaUnaSolaVez(): void
    {
        // Son dos líneas del mazo pero UNA fila de colección: descontarlas por
        // separado partiría el shortfall en dos mitades que no dicen nada.
        $this->anadirAlMazo(['printing_uuid' => 'uuid-bolt', 'count' => 3]);
        $this->anadirAlMazo(['printing_uuid' => 'uuid-bolt', 'count' => 1, 'board' => 'sideboard']);
        $this->anadirALaColeccion(['printing_uuid' => 'uuid-bolt', 'quantity' => 2]);

        $resultado = ($this->borrar)(1, ['deck_id' => 1, 'with_cards' => true]);

        self::assertSame(2, $resultado['removedFromCollection']);
        self::assertCount(1, $resultado['shortfall'], 'Un solo aviso: «pedías 4, tenías 2»');
        self::assertSame(4, $resultado['shortfall'][0]['requested']);
        self::assertSame(2, $resultado['shortfall'][0]['missing']);
    }

    public function testWithCardsAusenteEsFalse(): void
    {
        // Borrar un mazo es reversible a mano; vaciar la colección porque el
        // cliente olvidó un campo, no.
        $this->anadirAlMazo(['printing_uuid' => 'uuid-sol', 'count' => 1]);
        $this->anadirALaColeccion(['printing_uuid' => 'uuid-sol', 'quantity' => 1]);

        ($this->borrar)(1, ['deck_id' => 1]);

        self::assertSame(1, $this->coleccion->filas[1]['quantity']);
    }

    public function testUnMazoQueNoExisteDaNullParaQueElControllerResponda404(): void
    {
        self::assertNull(($this->borrar)(1, ['deck_id' => 404, 'with_cards' => true]));
    }

    public function testNoSePuedeBorrarElMazoDeOtroUsuarioNiVaciarleLaColeccion(): void
    {
        $this->anadirAlMazo(['printing_uuid' => 'uuid-sol', 'count' => 1]);
        $this->anadirALaColeccion(['printing_uuid' => 'uuid-sol', 'quantity' => 1]);

        self::assertNull(($this->borrar)(2, ['deck_id' => 1, 'with_cards' => true]));
        self::assertCount(1, $this->repo->mazos);
        self::assertSame(1, $this->coleccion->filas[1]['quantity']);
    }

    public function testSinDeckIdNoHayNadaQueBorrar(): void
    {
        $this->expectException(InvalidArgumentException::class);
        ($this->borrar)(1, ['with_cards' => true]);
    }

    /** @param array<string, mixed> $peticion */
    private function anadirAlMazo(array $peticion): void
    {
        (new AddCardToDeck($this->repo))(1, $peticion + ['deck_id' => 1]);
    }

    /** @param array<string, mixed> $peticion */
    private function anadirALaColeccion(array $peticion): void
    {
        (new AddToCollection($this->coleccion))(1, $peticion);
    }
}
