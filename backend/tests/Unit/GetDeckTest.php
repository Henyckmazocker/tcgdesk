<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Application\UseCase\AddCardToDeck;
use App\Application\UseCase\CreateDeck;
use App\Application\UseCase\GetDeck;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Doubles\MazosFalsos;

/**
 * `GetDeck` devuelve el mazo con sus cartas agrupadas por zona.
 *
 * La agrupación se hace sobre las mismas filas que ya vienen del repositorio,
 * recorridas una vez, así que el total del mazo y lo que suma cada zona no
 * pueden contradecirse.
 */
final class GetDeckTest extends TestCase
{
    private MazosFalsos $repo;

    private GetDeck $ver;

    protected function setUp(): void
    {
        $this->repo = new MazosFalsos();
        $this->ver  = new GetDeck($this->repo);

        (new CreateDeck($this->repo))(1, ['name' => 'Atraxa', 'format' => 'commander']);

        $anadir = new AddCardToDeck($this->repo);

        $anadir(1, ['deck_id' => 1, 'printing_uuid' => 'uuid-atraxa', 'board' => 'commander']);
        $anadir(1, ['deck_id' => 1, 'printing_uuid' => 'uuid-sol', 'count' => 2]);
        $anadir(1, ['deck_id' => 1, 'printing_uuid' => 'uuid-bolt', 'board' => 'sideboard']);
        $anadir(1, ['deck_id' => 1, 'printing_uuid' => 'uuid-token', 'board' => 'tokens', 'count' => 5]);
    }

    public function testLasCartasSalenAgrupadasPorZonaYEnElOrdenDelEnum(): void
    {
        $resultado = ($this->ver)(1, ['deck_id' => 1]);

        self::assertSame(['main', 'side', 'commander', 'tokens'], array_keys($resultado['boards']), 'El orden es el del ENUM, el mismo que ordena MySQL');
        self::assertCount(1, $resultado['boards']['main']);
        self::assertSame(2, $resultado['boards']['main'][0]['count']);
    }

    public function testLasZonasVaciasNoSalen(): void
    {
        // Siete claves vacías en la respuesta son ruido que la vista tendría que
        // filtrar igualmente.
        $resultado = ($this->ver)(1, ['deck_id' => 1]);

        self::assertArrayNotHasKey('planes', $resultado['boards']);
        self::assertArrayNotHasKey('companion', $resultado['boards']);
    }

    public function testLosTokensNoCuentanParaElTamanoDelMazo(): void
    {
        // Cinco tokens no son cinco cartas del mazo: un token no se posee.
        $resultado = ($this->ver)(1, ['deck_id' => 1]);

        self::assertSame(4, $resultado['deck']['cards'], '1 comandante + 2 Sol Ring + 1 de sideboard');
        self::assertSame(4, $resultado['deck']['cardLines'], 'Las líneas sí incluyen la de tokens');
    }

    public function testUnMazoQueNoExisteDaNullParaQueElControllerResponda404(): void
    {
        self::assertNull(($this->ver)(1, ['deck_id' => 404]));
    }

    public function testNoSePuedeVerElMazoDeOtroUsuario(): void
    {
        self::assertNull(($this->ver)(2, ['deck_id' => 1]));
    }

    public function testSinDeckIdNoHayNadaQueVer(): void
    {
        $this->expectException(InvalidArgumentException::class);
        ($this->ver)(1, []);
    }
}
