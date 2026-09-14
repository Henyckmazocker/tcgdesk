<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Application\UseCase\AddToCollection;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Doubles\ColeccionFalsa;

/**
 * Lo único que hay que proteger de `AddToCollection` es que **añadir dos veces
 * la misma carta sume en vez de duplicar** —la prueba nº 1 de la verificación
 * del plan— y que el `user_id` no se pueda falsificar desde el payload.
 */
final class AddToCollectionTest extends TestCase
{
    public function testAnadirLaMismaCartaDosVecesSumaYNoCreaDosFilas(): void
    {
        $repo   = new ColeccionFalsa();
        $anadir = new AddToCollection($repo);

        $anadir(1, ['printing_uuid' => 'uuid-sol-ring']);
        $segundo = $anadir(1, ['printing_uuid' => 'uuid-sol-ring']);

        self::assertCount(1, $repo->filas, 'El UNIQUE KEY debe fundir las dos altas en una fila');
        self::assertSame(2, $segundo['item']['quantity']);
    }

    public function testUnAcabadoDistintoEsOtraLinea(): void
    {
        // Un foil vale otra cosa y tiene su propio precio: no puede sumarse con
        // la versión normal.
        $repo   = new ColeccionFalsa();
        $anadir = new AddToCollection($repo);

        $anadir(1, ['printing_uuid' => 'uuid-sol-ring']);
        $anadir(1, ['printing_uuid' => 'uuid-sol-ring', 'finish' => 'foil']);
        $anadir(1, ['printing_uuid' => 'uuid-sol-ring', 'finish' => 'etched']);

        self::assertCount(3, $repo->filas);
    }

    public function testQuererUnaCartaQueYaTienesNoColisiona(): void
    {
        // is_wishlist entra en el UNIQUE KEY a propósito.
        $repo   = new ColeccionFalsa();
        $anadir = new AddToCollection($repo);

        $anadir(1, ['printing_uuid' => 'uuid-lotus']);
        $anadir(1, ['printing_uuid' => 'uuid-lotus', 'is_wishlist' => true]);

        self::assertCount(2, $repo->filas);
    }

    public function testNmYNearMintCaenEnLaMismaLinea(): void
    {
        $repo   = new ColeccionFalsa();
        $anadir = new AddToCollection($repo);

        $anadir(1, ['printing_uuid' => 'uuid-x', 'condition' => 'NM']);
        $anadir(1, ['printing_uuid' => 'uuid-x', 'condition' => 'Near Mint']);

        self::assertCount(1, $repo->filas, 'Es justo el duplicado que evitan los objetos de valor');
        self::assertSame(2, $repo->filas[1]['quantity']);
    }

    public function testElUserIdDelPayloadNoTieneNingunEfecto(): void
    {
        // El user_id sale de AuthMiddleware, no del cuerpo de la petición. Si el
        // payload pudiera imponerlo, cualquiera escribiría en la colección ajena.
        $repo = new ColeccionFalsa();

        (new AddToCollection($repo))(1, ['printing_uuid' => 'uuid-x', 'user_id' => 99]);

        self::assertSame(1, $repo->filas[1]['user_id']);
    }

    public function testLaCantidadPedidaEsLaQueSeSuma(): void
    {
        $repo = new ColeccionFalsa();

        $resultado = (new AddToCollection($repo))(1, ['printing_uuid' => 'uuid-x', 'quantity' => 4]);

        self::assertSame(4, $resultado['item']['quantity']);
    }

    public function testUnaCantidadDeCeroOMenosNoEsUnAlta(): void
    {
        // Restar por la puerta de atrás con una cantidad negativa desbordaría el
        // SMALLINT UNSIGNED del `quantity + VALUES(quantity)`.
        $this->expectException(InvalidArgumentException::class);

        (new AddToCollection(new ColeccionFalsa()))(1, ['printing_uuid' => 'uuid-x', 'quantity' => -3]);
    }

    public function testUnAcabadoInvalidoNoSeGuardaComoNormal(): void
    {
        // Guardarlo como 'normal' falsearía la valoración en silencio.
        $this->expectException(InvalidArgumentException::class);

        (new AddToCollection(new ColeccionFalsa()))(1, ['printing_uuid' => 'uuid-x', 'finish' => 'galaxy']);
    }
}
