<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Application\UseCase\SearchCards;
use App\Domain\Catalog\SearchCriteria;
use App\Domain\Repository\CardRepositoryInterface;
use PHPUnit\Framework\TestCase;

/**
 * Repositorio de mentira que guarda los criterios con los que se le llamó.
 */
class CartasFalsas implements CardRepositoryInterface
{
    public ?SearchCriteria $ultimosCriterios = null;

    /** @param list<array<string, mixed>> $devuelve */
    public function __construct(
        private readonly array $devuelve = [],
        private readonly ?string $nextCursor = null
    ) {
    }

    public function search(SearchCriteria $criterios): array
    {
        $this->ultimosCriterios = $criterios;

        return ['items' => $this->devuelve, 'nextCursor' => $this->nextCursor];
    }

    public function findByUuid(string $uuid): ?array
    {
        return null;
    }

    public function allSets(): array
    {
        return [];
    }
}

/**
 * El use case es fino a propósito —traduce la petición a criterios validados y
 * delega—, y eso es justo lo que hay que proteger: que NO deje pasar al
 * repositorio nada que venga crudo del cliente.
 */
final class SearchCardsTest extends TestCase
{
    public function testTraduceLaPeticionACriteriosValidados(): void
    {
        $repo   = new CartasFalsas();
        $buscar = new SearchCards($repo);

        $buscar(['q' => ' sol ring ', 'set' => 'c21', 'rarity' => 'INVENTADA', 'limit' => 9999]);

        self::assertSame('sol ring', $repo->ultimosCriterios->q);
        self::assertSame('C21', $repo->ultimosCriterios->setCode);
        self::assertNull($repo->ultimosCriterios->rarity);
        self::assertSame(SearchCriteria::LIMITE_MAXIMO, $repo->ultimosCriterios->limit);
    }

    public function testDevuelveLosItemsConSuRecuento(): void
    {
        $items = [
            ['uuid' => 'a', 'name' => 'Sol Ring'],
            ['uuid' => 'b', 'name' => 'Sol Ring'],
        ];

        $resultado = (new SearchCards(new CartasFalsas($items)))(['q' => 'sol ring']);

        self::assertSame($items, $resultado['items']);
        self::assertSame(2, $resultado['count']);
    }

    public function testUnaBusquedaSinResultadosNoEsUnError(): void
    {
        $resultado = (new SearchCards(new CartasFalsas()))(['q' => 'no existe esta carta']);

        self::assertSame([], $resultado['items']);
        self::assertSame(0, $resultado['count']);
        self::assertNull($resultado['nextCursor']);
    }

    public function testElCursorDeLaPaginaSiguienteSePropagaAlCliente(): void
    {
        $resultado = (new SearchCards(new CartasFalsas([['uuid' => 'a']], 'CURSOR123')))(['q' => 'sol']);

        self::assertSame('CURSOR123', $resultado['nextCursor']);
    }

    public function testElCursorRecibidoLlegaAlRepositorio(): void
    {
        $repo = new CartasFalsas();
        (new SearchCards($repo))(['q' => 'sol', 'cursor' => 'ABC']);

        self::assertSame('ABC', $repo->ultimosCriterios->cursor);
    }

    public function testUnaPeticionVaciaSigueSiendoValida(): void
    {
        // Es el caso de entrar a /catalog sin buscar nada: se listan cartas.
        $repo = new CartasFalsas();
        (new SearchCards($repo))([]);

        self::assertFalse($repo->ultimosCriterios->tieneTexto());
        self::assertSame('relevance', $repo->ultimosCriterios->sort);
    }
}
