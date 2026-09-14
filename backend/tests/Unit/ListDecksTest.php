<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Application\UseCase\CreateDeck;
use App\Application\UseCase\ListDecks;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Doubles\MazosFalsos;

/**
 * `ListDecks` lista los mazos del usuario, opcionalmente por estado.
 *
 * Lo que hay que probar es el aislamiento entre usuarios y que el filtro por
 * estado pregunte por el estado CONCRETO: los tres no son simétricos y un
 * `!= 'dismantled'` escrito por inercia metería los mazos en construcción donde
 * no deben estar.
 */
final class ListDecksTest extends TestCase
{
    private MazosFalsos $repo;

    private ListDecks $listar;

    protected function setUp(): void
    {
        $this->repo   = new MazosFalsos();
        $this->listar = new ListDecks($this->repo);

        $crear = new CreateDeck($this->repo);

        $crear(1, ['name' => 'Atraxa', 'status' => 'built']);
        $crear(1, ['name' => 'Burn', 'status' => 'building']);
        $crear(1, ['name' => 'Viejo', 'status' => 'dismantled']);
        $crear(2, ['name' => 'De otro', 'status' => 'built']);
    }

    public function testSoloSalenLosMazosDelUsuario(): void
    {
        $resultado = ($this->listar)(1, []);

        self::assertSame(3, $resultado['count']);
        self::assertSame(['Atraxa', 'Burn', 'Viejo'], array_column($resultado['decks'], 'name'));
    }

    public function testElFiltroPreguntaPorElEstadoConcretoYNoPorLaNegacion(): void
    {
        $resultado = ($this->listar)(1, ['status' => 'built']);

        self::assertSame(1, $resultado['count']);
        self::assertSame('Atraxa', $resultado['decks'][0]['name'], 'Solo `built` consume colección: el filtro es exacto');
    }

    public function testUnEstadoQueNoSeEntiendeNoRompeLaPantalla(): void
    {
        // Es una LECTURA: el filtro va con `intentar()`, así que un status
        // inválido deja de filtrar en vez de reventar la vista de mazos.
        $resultado = ($this->listar)(1, ['status' => 'terminado']);

        self::assertSame(3, $resultado['count']);
    }

    public function testUnUsuarioSinMazosRecibeUnaListaVacia(): void
    {
        $resultado = ($this->listar)(9, []);

        self::assertSame(0, $resultado['count']);
        self::assertSame([], $resultado['decks']);
    }
}
