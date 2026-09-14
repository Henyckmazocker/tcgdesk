<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Application\UseCase\AddCardToDeck;
use App\Application\UseCase\AddToCollection;
use App\Application\UseCase\ChangeDeckCardIdentity;
use App\Application\UseCase\CreateDeck;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Doubles\ColeccionFalsa;
use Tests\Unit\Doubles\MazosFalsos;

/**
 * `ChangeDeckCardIdentity` cambia **qué versión** de la carta pide el mazo.
 *
 * Es el gemelo de `ChangeItemGrade`, y lo que hay que probar es lo mismo: no que
 * escriba una columna —eso lo haría un `UPDATE` cualquiera— sino qué pasa cuando
 * el destino **ya existe**. `finish`, `language`, `condition_grade` y `board`
 * están dentro de `uq_deck_card`, así que el cambio MUEVE la línea a una
 * combinación que puede estar ocupada por otra línea del mismo mazo, y entonces
 * las dos son la misma carta en el mismo estado y deben fundirse sumando
 * `count`. El doble reproduce ese `UNIQUE KEY`.
 */
final class ChangeDeckCardIdentityTest extends TestCase
{
    private ColeccionFalsa $coleccion;

    private MazosFalsos $repo;

    private ChangeDeckCardIdentity $cambiar;

    protected function setUp(): void
    {
        $this->coleccion = new ColeccionFalsa();
        $this->repo      = new MazosFalsos($this->coleccion);
        $this->cambiar   = new ChangeDeckCardIdentity($this->repo);

        (new CreateDeck($this->repo))(1, ['name' => 'Atraxa']);

        // Línea 1: 2 Sol Ring normal/NM.
        (new AddCardToDeck($this->repo))(1, [
            'deck_id'       => 1,
            'printing_uuid' => 'uuid-sol',
            'count'         => 2,
        ]);
    }

    public function testElCasoDelPlanDosNormalesYUnaFoilTerminanEnUnaSolaLineaDeTres(): void
    {
        // «Este Sol Ring lo tengo normal y foil, mete la foil»: el mazo ya lleva
        // 1 foil/NM, así que cambiar los 2 normales choca con esa línea.
        (new AddCardToDeck($this->repo))(1, [
            'deck_id'       => 1,
            'printing_uuid' => 'uuid-sol',
            'finish'        => 'foil',
            'count'         => 1,
        ]);
        self::assertCount(2, $this->repo->cartas);

        $resultado = ($this->cambiar)(1, ['deck_id' => 1, 'card_id' => 1, 'finish' => 'foil']);

        self::assertTrue($resultado['merged']);
        self::assertCount(1, $this->repo->cartas, 'Dos filas con la misma clave violarían uq_deck_card');
        self::assertSame(3, $resultado['card']['count'], '2 + 1: no se pierde ni se duplica ninguna');
        self::assertSame(2, $resultado['card']['id'], 'La superviviente es la línea de destino');
        self::assertSame('foil', $resultado['card']['finish']);
    }

    public function testConElDestinoLibreLaLineaSeMueveYConservaSuId(): void
    {
        $resultado = ($this->cambiar)(1, ['deck_id' => 1, 'card_id' => 1, 'finish' => 'foil']);

        self::assertFalse($resultado['merged']);
        self::assertSame(1, $resultado['card']['id'], 'Sin colisión no hay motivo para cambiar de fila');
        self::assertSame('foil', $resultado['card']['finish']);
        self::assertSame(2, $resultado['card']['count'], 'Mover no crea ni destruye ejemplares');
        self::assertCount(1, $this->repo->cartas);
    }

    public function testCambiarLaVersionNoMueveNiUnaCartaDeLaColeccion(): void
    {
        // Solo cambia lo que el mazo RECLAMA, y por tanto lo que el cruce de M3
        // calcula. Si pides la foil y no la tienes, lo que cambia es que la app
        // empiece a decírtelo, no tu colección.
        (new AddToCollection($this->coleccion))(1, ['printing_uuid' => 'uuid-sol', 'quantity' => 2]);

        ($this->cambiar)(1, ['deck_id' => 1, 'card_id' => 1, 'finish' => 'foil']);

        self::assertCount(1, $this->coleccion->filas);
        self::assertSame('normal', $this->coleccion->filas[1]['finish'], 'La colección sigue teniendo la normal');
        self::assertSame(2, $this->coleccion->filas[1]['quantity']);
    }

    public function testLaFusionSoloOcurreSiCoincidenLasSeisColumnasDeLaClave(): void
    {
        // Mismo printing y mismo acabado de destino, pero en el sideboard: otra
        // combinación de la clave. No debe fundirse.
        (new AddCardToDeck($this->repo))(1, [
            'deck_id'       => 1,
            'printing_uuid' => 'uuid-sol',
            'finish'        => 'foil',
            'board'         => 'sideboard',
            'count'         => 1,
        ]);

        $resultado = ($this->cambiar)(1, ['deck_id' => 1, 'card_id' => 1, 'finish' => 'foil']);

        self::assertFalse($resultado['merged']);
        self::assertCount(2, $this->repo->cartas);
        self::assertSame(1, $this->repo->cartas[2]['count'], 'La del sideboard no se ha tocado');
    }

    public function testMoverDeZonaTambienEsUnCambioDeIdentidad(): void
    {
        // `board` está dentro de uq_deck_card igual que las otras tres.
        $resultado = ($this->cambiar)(1, ['deck_id' => 1, 'card_id' => 1, 'board' => 'sideboard']);

        self::assertSame('side', $resultado['card']['board']);
        self::assertFalse($resultado['merged']);
    }

    public function testPedirLaVersionQueYaTieneNoCambiaNada(): void
    {
        $resultado = ($this->cambiar)(1, ['deck_id' => 1, 'card_id' => 1, 'condition_grade' => 'Near Mint']);

        self::assertFalse($resultado['merged']);
        self::assertSame('NM', $resultado['card']['condition'], "'Near Mint' y 'NM' son el mismo estado");
        self::assertSame(2, $resultado['card']['count']);
    }

    public function testSeCambianVariasDimensionesALaVez(): void
    {
        $resultado = ($this->cambiar)(1, [
            'deck_id'         => 1,
            'card_id'         => 1,
            'finish'          => 'etched',
            'language'        => 'Spanish',
            'condition_grade' => 'LP',
        ]);

        self::assertSame('etched', $resultado['card']['finish']);
        self::assertSame('Spanish', $resultado['card']['language']);
        self::assertSame('LP', $resultado['card']['condition']);
    }

    public function testUnaLineaQueNoExisteDaNullParaQueElControllerResponda404(): void
    {
        self::assertNull(($this->cambiar)(1, ['deck_id' => 1, 'card_id' => 404, 'finish' => 'foil']));
    }

    public function testNoSePuedeTocarLaLineaDelMazoDeOtro(): void
    {
        self::assertNull(($this->cambiar)(2, ['deck_id' => 1, 'card_id' => 1, 'finish' => 'foil']));
        self::assertSame('normal', $this->repo->cartas[1]['finish']);
    }

    public function testSinNingunaDimensionNoHayNadaQueCambiar(): void
    {
        $this->expectException(InvalidArgumentException::class);
        ($this->cambiar)(1, ['deck_id' => 1, 'card_id' => 1]);
    }

    public function testUnAcabadoInventadoSeRechazaEnVezDeCaerEnElPorDefecto(): void
    {
        // Esto es una ESCRITURA: el mazo pediría una carta distinta de la que el
        // usuario señaló y el cruce con la colección mentiría en silencio.
        $this->expectException(InvalidArgumentException::class);
        ($this->cambiar)(1, ['deck_id' => 1, 'card_id' => 1, 'finish' => 'brillante']);
    }
}
