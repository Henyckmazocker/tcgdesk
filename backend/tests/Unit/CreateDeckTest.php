<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Application\UseCase\CreateDeck;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Doubles\MazosFalsos;

/**
 * `CreateDeck` crea un mazo vacío con lo mínimo: un nombre.
 */
final class CreateDeckTest extends TestCase
{
    private MazosFalsos $repo;

    private CreateDeck $crear;

    protected function setUp(): void
    {
        $this->repo  = new MazosFalsos();
        $this->crear = new CreateDeck($this->repo);
    }

    public function testUnNombreBastaYElMazoNaceEnConstruccion(): void
    {
        $resultado = ($this->crear)(1, ['name' => 'Atraxa']);

        self::assertSame('Atraxa', $resultado['deck']['name']);
        self::assertSame('building', $resultado['deck']['status'], 'Un mazo recién creado está vacío: no puede estar montado');
        self::assertNull($resultado['deck']['format']);
        self::assertSame(0, $resultado['deck']['cards']);
    }

    public function testElMazoSeCreaConEstadoFormatoYNotas(): void
    {
        $resultado = ($this->crear)(1, [
            'name'   => 'Burn',
            'status' => 'built',
            'format' => 'Modern',
            'notes'  => 'En la caja roja',
        ]);

        self::assertSame('built', $resultado['deck']['status']);
        self::assertSame('modern', $resultado['deck']['format'], 'mtg_legality.format está en minúsculas');
        self::assertSame('En la caja roja', $resultado['deck']['notes']);
    }

    public function testElMazoSeCreaParaElUsuarioQuePusoAuthMiddlewareYNoParaOtro(): void
    {
        ($this->crear)(1, ['name' => 'Atraxa', 'user_id' => 2]);

        self::assertSame(1, $this->repo->mazos[1]['user_id'], 'El user_id del payload se ignora, siempre');
    }

    public function testDosMazosPuedenLlamarseIgual(): void
    {
        // No hay UNIQUE sobre (user_id, name) a propósito: «Atraxa v1» dos veces
        // mientras decides cuál te gusta son dos mazos legítimos.
        ($this->crear)(1, ['name' => 'Atraxa']);
        ($this->crear)(1, ['name' => 'Atraxa']);

        self::assertCount(2, $this->repo->mazos);
    }

    public function testSinNombreNoHayMazo(): void
    {
        $this->expectException(InvalidArgumentException::class);
        ($this->crear)(1, []);
    }

    public function testUnEstadoInventadoSeRechazaEnVezDeCaerEnElPorDefecto(): void
    {
        // Caer en `building` dejaría un mazo montado sin consumir colección y el
        // aviso de sobreasignación no saltaría jamás.
        $this->expectException(InvalidArgumentException::class);
        ($this->crear)(1, ['name' => 'Atraxa', 'status' => 'terminado']);
    }
}
