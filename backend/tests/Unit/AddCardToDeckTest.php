<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Application\UseCase\AddCardToDeck;
use App\Application\UseCase\AddToCollection;
use App\Application\UseCase\CreateDeck;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Doubles\ColeccionFalsa;
use Tests\Unit\Doubles\MazosFalsos;

/**
 * `AddCardToDeck` mete una carta en un mazo.
 *
 * Lo que hay que probar es que **suma en vez de duplicar** —el doble reproduce
 * el `UNIQUE KEY uq_deck_card`, así que estos tests fallarían con un repositorio
 * que insertara siempre— y que meter una carta en un mazo **no toca la
 * colección**.
 */
final class AddCardToDeckTest extends TestCase
{
    private ColeccionFalsa $coleccion;

    private MazosFalsos $repo;

    private AddCardToDeck $anadir;

    protected function setUp(): void
    {
        $this->coleccion = new ColeccionFalsa();
        $this->repo      = new MazosFalsos($this->coleccion);
        $this->anadir    = new AddCardToDeck($this->repo);

        (new CreateDeck($this->repo))(1, ['name' => 'Atraxa']);
    }

    public function testAnadirDosVecesLaMismaCartaSumaEnVezDeDuplicar(): void
    {
        ($this->anadir)(1, ['deck_id' => 1, 'printing_uuid' => 'uuid-sol']);
        $resultado = ($this->anadir)(1, ['deck_id' => 1, 'printing_uuid' => 'uuid-sol', 'count' => 3]);

        self::assertCount(1, $this->repo->cartas, 'Dos filas con la misma clave violarían uq_deck_card');
        self::assertSame(4, $resultado['card']['count'], 'El cliente necesita saber que ahora lleva 4, no que ha añadido 3');
    }

    public function testLaMismaCartaEnElMainYEnElSideSonDosLineas(): void
    {
        // `board` está DENTRO de uq_deck_card a propósito.
        ($this->anadir)(1, ['deck_id' => 1, 'printing_uuid' => 'uuid-bolt', 'count' => 4]);
        ($this->anadir)(1, ['deck_id' => 1, 'printing_uuid' => 'uuid-bolt', 'count' => 2, 'board' => 'sideboard']);

        self::assertCount(2, $this->repo->cartas);
    }

    public function testElMismoSolRingNormalYFoilSonDosLineas(): void
    {
        // Es el caso que justifica la granularidad del plan: valorar en euros
        // exige (printing, finish).
        ($this->anadir)(1, ['deck_id' => 1, 'printing_uuid' => 'uuid-sol']);
        ($this->anadir)(1, ['deck_id' => 1, 'printing_uuid' => 'uuid-sol', 'finish' => 'foil']);

        self::assertCount(2, $this->repo->cartas);
    }

    public function testAnadirAlMazoNoMueveNiUnaCartaDeLaColeccion(): void
    {
        (new AddToCollection($this->coleccion))(1, ['printing_uuid' => 'uuid-sol', 'quantity' => 2]);

        ($this->anadir)(1, ['deck_id' => 1, 'printing_uuid' => 'uuid-sol', 'count' => 4]);

        self::assertSame(2, $this->coleccion->filas[1]['quantity'], 'Nadie te compra la carta por escribirla en una lista');
    }

    public function testNoSePuedeEscribirEnElMazoDeOtroUsuario(): void
    {
        // `mtg_deck_card` no lleva user_id: sin comprobar el mazo, un deck_id
        // ajeno pasaría la clave foránea sin problema.
        self::assertNull(($this->anadir)(2, ['deck_id' => 1, 'printing_uuid' => 'uuid-sol']));
        self::assertCount(0, $this->repo->cartas);
    }

    public function testUnMazoQueNoExisteDaNull(): void
    {
        self::assertNull(($this->anadir)(1, ['deck_id' => 404, 'printing_uuid' => 'uuid-sol']));
    }

    public function testSinDeckIdNoHayNadaQueAnadir(): void
    {
        $this->expectException(InvalidArgumentException::class);
        ($this->anadir)(1, ['printing_uuid' => 'uuid-sol']);
    }

    public function testUnAcabadoInventadoSeRechazaEnVezDeCaerEnElPorDefecto(): void
    {
        // Guardarlo como 'normal' haría que el mazo reclamara una carta distinta
        // de la que el usuario quiso meter.
        $this->expectException(InvalidArgumentException::class);
        ($this->anadir)(1, ['deck_id' => 1, 'printing_uuid' => 'uuid-sol', 'finish' => 'brillante']);
    }
}
