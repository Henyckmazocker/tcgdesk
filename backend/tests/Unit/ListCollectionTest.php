<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Application\UseCase\AddToCollection;
use App\Application\UseCase\ListCollection;
use App\Domain\Collection\CardLanguage;
use App\Domain\Collection\CollectionCriteria;
use App\Domain\Collection\Condition;
use App\Domain\Collection\Finish;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Doubles\ColeccionFalsa;

/**
 * El use case es fino —traduce la petición a criterios validados y delega—, y
 * eso es justo lo que hay que proteger: que **no deje pasar al repositorio nada
 * crudo del cliente**. El `ORDER BY` y el `LIMIT` se interpolan en el SQL porque
 * PDO no admite marcador ahí, así que la lista blanca es la única barrera.
 */
final class ListCollectionTest extends TestCase
{
    public function testLoQueVieneDelClienteSeValidaAntesDeLlegarAlRepositorio(): void
    {
        $repo = new ColeccionFalsa();

        (new ListCollection($repo))(1, [
            'set'    => 'c21',
            'rarity' => 'INVENTADA',
            'sort'   => "name; DROP TABLE mtg_collection_item",
            'limit'  => 9999,
        ]);

        self::assertSame('C21', $repo->ultimosCriterios->setCode);
        self::assertNull($repo->ultimosCriterios->rarity);
        self::assertSame('price_desc', $repo->ultimosCriterios->sort, 'Un orden desconocido cae al defecto');
        self::assertSame(CollectionCriteria::LIMITE_MAXIMO, $repo->ultimosCriterios->limit);
    }

    public function testLosFiltrosDeColeccionSeNormalizanAObjetosDeValor(): void
    {
        $repo = new ColeccionFalsa();

        (new ListCollection($repo))(1, [
            'finish'    => 'Etched Foil',
            'language'  => 'es',
            'condition' => 'Near Mint',
            'colors'    => 'wubrgw',
        ]);

        self::assertSame(Finish::Etched, $repo->ultimosCriterios->finish);
        self::assertSame(CardLanguage::Spanish, $repo->ultimosCriterios->language);
        self::assertSame(Condition::NearMint, $repo->ultimosCriterios->condition);
        self::assertSame('WUBRG', $repo->ultimosCriterios->colors);
    }

    public function testUnFiltroIlegibleSeIgnoraYNoRompeLaVista(): void
    {
        $repo = new ColeccionFalsa();

        (new ListCollection($repo))(1, ['finish' => 'galaxy', 'condition' => 'regulero']);

        self::assertNull($repo->ultimosCriterios->finish);
        self::assertNull($repo->ultimosCriterios->condition);
    }

    public function testElUserIdLlegaAlRepositorioComoArgumentoAparte(): void
    {
        $repo = new ColeccionFalsa();

        (new ListCollection($repo))(42, ['user_id' => 99]);

        self::assertSame(42, $repo->ultimoUserId);
    }

    public function testLaListaDeDeseosEsOtraLista(): void
    {
        $repo   = new ColeccionFalsa();
        $anadir = new AddToCollection($repo);

        $anadir(1, ['printing_uuid' => 'uuid-x']);
        $anadir(1, ['printing_uuid' => 'uuid-y', 'is_wishlist' => true]);

        self::assertSame(1, (new ListCollection($repo))(1, [])['count']);
        self::assertSame(1, (new ListCollection($repo))(1, ['is_wishlist' => true])['count']);
    }

    public function testElCursorViajaEnLosDosSentidos(): void
    {
        $repo             = new ColeccionFalsa();
        $repo->nextCursor = 'CURSOR123';

        $resultado = (new ListCollection($repo))(1, ['cursor' => 'ABC']);

        self::assertSame('ABC', $repo->ultimosCriterios->cursor);
        self::assertSame('CURSOR123', $resultado['nextCursor']);
    }

    public function testUnaColeccionVaciaNoEsUnError(): void
    {
        $resultado = (new ListCollection(new ColeccionFalsa()))(1, []);

        self::assertSame([], $resultado['items']);
        self::assertSame(0, $resultado['count']);
        self::assertNull($resultado['nextCursor']);
    }
}
