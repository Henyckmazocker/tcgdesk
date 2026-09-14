<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Application\UseCase\AddCardToDeck;
use App\Application\UseCase\AddToCollection;
use App\Application\UseCase\CreateDeck;
use App\Application\UseCase\UpdateDeck;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Doubles\ColeccionFalsa;
use Tests\Unit\Doubles\MazosFalsos;

/**
 * `UpdateDeck` edita el mazo **parcialmente**.
 *
 * Lo que hay que probar no es que escriba una columna, sino que mandar solo el
 * estado —lo que hace el botón «desmontar» de M5— no borre el nombre, el formato
 * ni las notas.
 */
final class UpdateDeckTest extends TestCase
{
    private MazosFalsos $repo;

    private UpdateDeck $editar;

    protected function setUp(): void
    {
        $this->repo   = new MazosFalsos();
        $this->editar = new UpdateDeck($this->repo);

        (new CreateDeck($this->repo))(1, [
            'name'   => 'Atraxa',
            'status' => 'built',
            'format' => 'commander',
            'notes'  => 'En la caja azul',
        ]);
    }

    public function testDesmontarSoloCambiaElEstado(): void
    {
        $resultado = ($this->editar)(1, ['deck_id' => 1, 'status' => 'dismantled']);

        self::assertSame('dismantled', $resultado['deck']['status']);
        self::assertSame('Atraxa', $resultado['deck']['name'], 'Una edición parcial no puede borrar el nombre');
        self::assertSame('commander', $resultado['deck']['format']);
        self::assertSame('En la caja azul', $resultado['deck']['notes']);
    }

    public function testMandarNullQuitaElFormato(): void
    {
        // Hay que poder distinguir "quítame el formato" de "no lo menciono".
        $resultado = ($this->editar)(1, ['deck_id' => 1, 'format' => null]);

        self::assertNull($resultado['deck']['format']);
        self::assertSame('Atraxa', $resultado['deck']['name']);
    }

    public function testCambiarDeEstadoNoMueveNiUnaCartaDeLaColeccion(): void
    {
        // Pasar a `built` no descuenta nada y pasar a `dismantled` no devuelve
        // nada: lo único que cambia es si el mazo entra en el cruce de M3.
        $coleccion = new ColeccionFalsa();
        $repo      = new MazosFalsos($coleccion);

        (new CreateDeck($repo))(1, ['name' => 'Atraxa']);
        (new AddCardToDeck($repo))(1, ['deck_id' => 1, 'printing_uuid' => 'uuid-sol']);
        (new AddToCollection($coleccion))(1, ['printing_uuid' => 'uuid-sol', 'quantity' => 2]);

        (new UpdateDeck($repo))(1, ['deck_id' => 1, 'status' => 'built']);

        self::assertSame(2, $coleccion->filas[1]['quantity'], 'Montar un mazo no toca la colección');
    }

    public function testUnMazoQueNoExisteDaNullParaQueElControllerResponda404(): void
    {
        self::assertNull(($this->editar)(1, ['deck_id' => 404, 'name' => 'x']));
    }

    public function testNoSePuedeTocarElMazoDeOtroUsuario(): void
    {
        self::assertNull(($this->editar)(2, ['deck_id' => 1, 'name' => 'Robado']));
        self::assertSame('Atraxa', $this->repo->mazos[1]['name']);
    }

    public function testUnaEdicionVaciaDevuelveElMazoTalCual(): void
    {
        $resultado = ($this->editar)(1, ['deck_id' => 1]);

        self::assertSame('Atraxa', $resultado['deck']['name']);
    }

    public function testSinDeckIdNoHayNadaQueEditar(): void
    {
        $this->expectException(InvalidArgumentException::class);
        ($this->editar)(1, ['name' => 'x']);
    }

    public function testUnEstadoInventadoSeRechazaAunqueElMazoNoExista(): void
    {
        // 422 y no 404: una petición mal formada manda a buscar el bug al sitio
        // equivocado si se responde "no existe".
        $this->expectException(InvalidArgumentException::class);
        ($this->editar)(1, ['deck_id' => 404, 'status' => 'terminado']);
    }
}
