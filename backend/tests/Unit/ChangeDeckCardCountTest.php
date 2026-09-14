<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Application\UseCase\AddCardToDeck;
use App\Application\UseCase\ChangeDeckCardCount;
use App\Application\UseCase\CreateDeck;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Doubles\MazosFalsos;

/**
 * `ChangeDeckCardCount` fija cuántas copias lleva el mazo. **Cero borra.**
 *
 * El 0 es una petición legítima, no una ausencia: `ValidationMiddleware` lo
 * trata como campo vacío, así que `count` no puede ir en la lista de `required`
 * de `deck_card_set` y quien lo valida es este use case. Igual que `quantity` en
 * `collection_update_quantity`.
 */
final class ChangeDeckCardCountTest extends TestCase
{
    private MazosFalsos $repo;

    private ChangeDeckCardCount $fijar;

    protected function setUp(): void
    {
        $this->repo  = new MazosFalsos();
        $this->fijar = new ChangeDeckCardCount($this->repo);

        (new CreateDeck($this->repo))(1, ['name' => 'Atraxa']);
        (new AddCardToDeck($this->repo))(1, ['deck_id' => 1, 'printing_uuid' => 'uuid-bolt', 'count' => 4]);
    }

    public function testLaCantidadEsAbsolutaYNoSeSuma(): void
    {
        // Es la edición en línea de la tabla: el usuario escribe "2" y espera
        // llevar dos, no seis.
        $resultado = ($this->fijar)(1, ['deck_id' => 1, 'card_id' => 1, 'count' => 2]);

        self::assertFalse($resultado['removed']);
        self::assertSame(2, $resultado['card']['count']);
    }

    public function testCeroBorraLaLinea(): void
    {
        // Una línea con count = 0 seguiría contando en «cartas del mazo» y
        // falsearía el tamaño que M6 mira para el mínimo de 60 o 100.
        $resultado = ($this->fijar)(1, ['deck_id' => 1, 'card_id' => 1, 'count' => 0]);

        self::assertTrue($resultado['removed']);
        self::assertNull($resultado['card']);
        self::assertCount(0, $this->repo->cartas);
    }

    public function testBorrarUnaLineaQueNoExisteEs404YNoUn200(): void
    {
        // El repositorio devuelve null tanto si borró como si no existe: por eso
        // el use case comprueba ANTES de tocar nada.
        self::assertNull(($this->fijar)(1, ['deck_id' => 1, 'card_id' => 404, 'count' => 0]));
    }

    public function testNoSePuedeTocarLaLineaDelMazoDeOtro(): void
    {
        self::assertNull(($this->fijar)(2, ['deck_id' => 1, 'card_id' => 1, 'count' => 1]));
        self::assertSame(4, $this->repo->cartas[1]['count']);
    }

    public function testUnaCantidadNegativaSeRechaza(): void
    {
        $this->expectException(InvalidArgumentException::class);
        ($this->fijar)(1, ['deck_id' => 1, 'card_id' => 1, 'count' => -1]);
    }

    public function testUnaCantidadDesorbitadaSeRechazaAntesDeDesbordarLaColumna(): void
    {
        $this->expectException(InvalidArgumentException::class);
        ($this->fijar)(1, ['deck_id' => 1, 'card_id' => 1, 'count' => 100000]);
    }

    public function testSinCantidadNoHayNadaQueFijar(): void
    {
        $this->expectException(InvalidArgumentException::class);
        ($this->fijar)(1, ['deck_id' => 1, 'card_id' => 1]);
    }
}
