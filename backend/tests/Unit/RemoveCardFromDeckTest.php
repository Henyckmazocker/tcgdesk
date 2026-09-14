<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Application\UseCase\AddCardToDeck;
use App\Application\UseCase\AddToCollection;
use App\Application\UseCase\CreateDeck;
use App\Application\UseCase\RemoveCardFromDeck;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Doubles\ColeccionFalsa;
use Tests\Unit\Doubles\MazosFalsos;

/**
 * `RemoveCardFromDeck` saca una línea del mazo. La carta **sigue siendo tuya**.
 */
final class RemoveCardFromDeckTest extends TestCase
{
    private ColeccionFalsa $coleccion;

    private MazosFalsos $repo;

    private RemoveCardFromDeck $quitar;

    protected function setUp(): void
    {
        $this->coleccion = new ColeccionFalsa();
        $this->repo      = new MazosFalsos($this->coleccion);
        $this->quitar    = new RemoveCardFromDeck($this->repo);

        (new CreateDeck($this->repo))(1, ['name' => 'Atraxa']);
        (new AddCardToDeck($this->repo))(1, ['deck_id' => 1, 'printing_uuid' => 'uuid-sol', 'count' => 2]);
        (new AddToCollection($this->coleccion))(1, ['printing_uuid' => 'uuid-sol', 'quantity' => 2]);
    }

    public function testLaLineaDesapareceDelMazoPeroNoDeLaColeccion(): void
    {
        self::assertTrue(($this->quitar)(1, ['deck_id' => 1, 'card_id' => 1]));

        self::assertCount(0, $this->repo->cartas);
        self::assertSame(2, $this->coleccion->filas[1]['quantity'], 'La carta sigue siendo tuya, solo sale de la lista');
    }

    public function testUnaLineaQueNoExisteDaFalse(): void
    {
        self::assertFalse(($this->quitar)(1, ['deck_id' => 1, 'card_id' => 404]));
    }

    public function testNoSePuedeQuitarUnaLineaDelMazoDeOtro(): void
    {
        self::assertFalse(($this->quitar)(2, ['deck_id' => 1, 'card_id' => 1]));
        self::assertCount(1, $this->repo->cartas);
    }

    public function testElDeckIdTambienTieneQueCasar(): void
    {
        // La línea es un autoincremental global: con solo el card_id bastaría
        // con probar números.
        self::assertFalse(($this->quitar)(1, ['deck_id' => 99, 'card_id' => 1]));
    }

    public function testSinCardIdNoHayNadaQueQuitar(): void
    {
        $this->expectException(InvalidArgumentException::class);
        ($this->quitar)(1, ['deck_id' => 1]);
    }
}
