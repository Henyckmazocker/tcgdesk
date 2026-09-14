<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Application\UseCase\AddToCollection;
use App\Application\UseCase\RemoveFromCollection;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Doubles\ColeccionFalsa;

final class RemoveFromCollectionTest extends TestCase
{
    private ColeccionFalsa $repo;

    protected function setUp(): void
    {
        $this->repo = new ColeccionFalsa();
        (new AddToCollection($this->repo))(1, ['printing_uuid' => 'uuid-x']);
    }

    public function testQuitarUnaLineaLaBorraDeVerdad(): void
    {
        self::assertTrue((new RemoveFromCollection($this->repo))(1, ['item_id' => 1]));
        self::assertSame([], $this->repo->filas);
    }

    public function testNoSePuedeBorrarLaLineaDeOtroUsuario(): void
    {
        // `id` es un autoincremental global: sin el user_id en el WHERE bastaría
        // con probar números para vaciar la colección de otra persona.
        self::assertFalse((new RemoveFromCollection($this->repo))(2, ['item_id' => 1]));
        self::assertCount(1, $this->repo->filas);
    }

    public function testBorrarLoQueNoExisteNoEsUnExito(): void
    {
        self::assertFalse((new RemoveFromCollection($this->repo))(1, ['item_id' => 999]));
    }

    public function testSinItemIdSeRechaza(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new RemoveFromCollection($this->repo))(1, []);
    }
}
