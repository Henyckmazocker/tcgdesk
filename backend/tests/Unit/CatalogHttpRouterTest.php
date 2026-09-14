<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Application\UseCase\GetPrecon;
use App\Application\UseCase\SearchCards;
use App\Application\UseCase\SearchPrecons;
use App\Domain\Catalog\SearchCriteria;
use App\Domain\Repository\CardRepositoryInterface;
use App\Router\CatalogHttpRouter;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Tests\Unit\Doubles\PreconesFalsos;

/** Un catálogo de cartas que no devuelve nada: aquí se prueban los precons. */
class CatalogoMudo implements CardRepositoryInterface
{
    public function search(SearchCriteria $criterios): array
    {
        return ['items' => [], 'nextCursor' => null];
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
 * Las dos rutas `GET` de precons, la tercera divergencia del `CLAUDE.md`.
 *
 * Lo que se protege, por orden de importancia:
 *
 *  1. **Que la paginación por cursor no repite ni se salta filas.** Es el motivo
 *     entero de que no haya `OFFSET`: recorrer las 3.029 cajas tiene que
 *     devolverlas todas una sola vez.
 *  2. **Que un `fileName` inexistente es un 404 `precon_not_found`**, y no un
 *     200 con una ficha vacía ni el `printing_not_found` de las cartas.
 *  3. **Que la lista y la ficha son rutas distintas** y ni el `match` ni la
 *     expresión regular se comen una por la otra.
 *
 * Las cabeceras no se pueden inspeccionar bajo el SAPI de CLI —mismo límite que
 * `ImageHttpRouterTest`—, así que el `Cache-Control` de 5 minutos se verifica con
 * `curl` contra el contenedor, no aquí. Lo que sí se comprueba es el código de
 * respuesta y el cuerpo.
 */
final class CatalogHttpRouterTest extends TestCase
{
    private PreconesFalsos $precons;

    private CatalogHttpRouter $router;

    protected function setUp(): void
    {
        $this->precons = new PreconesFalsos();

        foreach (['Alfa', 'Bravo', 'Charlie', 'Delta', 'Echo'] as $i => $nombre) {
            $this->precons->precons[] = [
                'fileName'    => $nombre . '_C2' . $i,
                'name'        => $nombre,
                'deckType'    => $i === 4 ? 'Theme Deck' : 'Commander Deck',
                'setCode'     => 'C2' . $i,
                'setName'     => 'Edición ' . $i,
                'releaseDate' => '2020-01-0' . ($i + 1),
                'cardCount'   => 100,
            ];
        }

        // Una caja que NO es un mazo: es el caso que el filtro honesto de
        // `/precons` tiene que poder dejar fuera.
        $this->precons->precons[] = [
            'fileName'    => 'Foxtrot_SLD',
            'name'        => 'Foxtrot',
            'deckType'    => 'Secret Lair Drop',
            'setCode'     => 'SLD',
            'setName'     => 'Secret Lair Drop',
            'releaseDate' => '2021-01-01',
            'cardCount'   => 4,
        ];

        $this->precons->cartas['Alfa_C20'] = [[
            'printingUuid' => 'u-1',
            'board'        => 'main',
            'finish'       => 'normal',
            'count'        => 2,
            'known'        => true,
            'name'         => 'Sol Ring',
            'priceEur'     => 1.5,
        ]];

        $this->router = new CatalogHttpRouter(
            new SearchCards(new CatalogoMudo()),
            new CatalogoMudo(),
            new SearchPrecons($this->precons),
            new GetPrecon($this->precons),
            new NullLogger()
        );

        $_GET = [];
        http_response_code(200);
    }

    protected function tearDown(): void
    {
        $_GET = [];
    }

    public function testLaListaFiltraPorTipoYPaginaPorCursorSinRepetir(): void
    {
        $vistos  = [];
        $cursor  = null;
        $paginas = 0;

        do {
            $_GET = ['type' => 'Commander Deck', 'limit' => '2'];

            if ($cursor !== null) {
                $_GET['cursor'] = $cursor;
            }

            $cuerpo = $this->pedir('/api/catalog/decks?' . http_build_query($_GET));

            $this->assertSame(200, http_response_code());

            foreach ($cuerpo['items'] as $item) {
                $vistos[] = $item['fileName'];
            }

            $cursor = $cuerpo['nextCursor'];
            $paginas++;
        } while ($cursor !== null && $paginas < 10);

        // Las cuatro Commander Deck, en dos páginas de dos y una de cierre, sin
        // repetir ninguna y sin el Theme Deck.
        $this->assertSame(['Alfa_C20', 'Bravo_C21', 'Charlie_C22', 'Delta_C23'], $vistos);
        $this->assertSame($vistos, array_values(array_unique($vistos)));
    }

    public function testLaListaAcotaElLimiteQueLlegaPorLaUrl(): void
    {
        $_GET = ['limit' => '9999'];

        $this->pedir('/api/catalog/decks?limit=9999');

        $this->assertSame(100, $this->precons->ultimosCriterios?->limit);
    }

    public function testLaPrimeraPaginaTraeLosTiposYLasEdicionesQueExisten(): void
    {
        $cuerpo = $this->pedir('/api/catalog/decks');

        // Los tipos salen del dato agrupado, no de una lista escrita a mano: en
        // el fijo hay cuatro Commander Deck y un Theme Deck, y el orden es por
        // número de cajas para que el filtro ofrezca primero lo que abunda.
        $this->assertSame(
            [
                ['type' => 'Commander Deck', 'count' => 4, 'playable' => true],
                ['type' => 'Theme Deck', 'count' => 1, 'playable' => true],
                // El producto sale en la lista, marcado: la vista lo esconde
                // detrás del «ver todo», no lo ignora.
                ['type' => 'Secret Lair Drop', 'count' => 1, 'playable' => false],
            ],
            $cuerpo['deckTypes']
        );

        // Y las ediciones, solo las que tienen precon.
        $this->assertCount(6, $cuerpo['sets']);
        $this->assertSame(['code' => 'C20', 'name' => 'Edición 0', 'count' => 1], $cuerpo['sets'][0]);
    }

    public function testPlayableDejaFueraLoQueNoEsUnMazoYSinElSaleTodo(): void
    {
        $_GET   = ['playable' => '1'];
        $cuerpo = $this->pedir('/api/catalog/decks?playable=1');

        $nombres = array_column($cuerpo['items'], 'fileName');

        $this->assertNotContains('Foxtrot_SLD', $nombres);
        $this->assertContains('Alfa_C20', $nombres);

        // Sin el parámetro, la ruta devuelve lo mismo que devolvía en M3: las
        // 3.029 cajas enteras. El filtro honesto es de la vista, no del endpoint.
        $_GET  = [];
        $todas = $this->pedir('/api/catalog/decks');

        $this->assertContains('Foxtrot_SLD', array_column($todas['items'], 'fileName'));
    }

    public function testLasPaginasSiguientesNoRepitenLasFacetas(): void
    {
        $_GET   = ['limit' => '2'];
        $cuerpo = $this->pedir('/api/catalog/decks?limit=2');

        $this->assertArrayHasKey('deckTypes', $cuerpo);
        $this->assertNotNull($cuerpo['nextCursor']);

        $_GET    = ['limit' => '2', 'cursor' => $cuerpo['nextCursor']];
        $segunda = $this->pedir('/api/catalog/decks?' . http_build_query($_GET));

        // Ni `deckTypes` ni `sets`: son ~12 KB que la vista ya tiene, y el
        // scroll infinito pide esta ruta una vez por tirón.
        $this->assertArrayNotHasKey('deckTypes', $segunda);
        $this->assertArrayNotHasKey('sets', $segunda);
        $this->assertNotEmpty($segunda['items']);
    }

    public function testLaFichaDevuelveLasCartasConSuValor(): void
    {
        $cuerpo = $this->pedir('/api/catalog/decks/Alfa_C20');

        $this->assertSame(200, http_response_code());
        $this->assertSame('Alfa_C20', $cuerpo['precon']['fileName']);
        $this->assertCount(1, $cuerpo['cards']);
        // 2 × 1,50 €. Se compara con delta porque JSON no distingue 3.0 de 3.
        $this->assertEqualsWithDelta(3.0, $cuerpo['valueEur'], 0.001);
    }

    public function testUnFileNameInexistenteEs404PreconNotFound(): void
    {
        $cuerpo = $this->pedir('/api/catalog/decks/NoExisteEsteMazo_XXX');

        $this->assertSame(404, http_response_code());
        $this->assertSame(['error' => 'precon_not_found'], $cuerpo);
    }

    public function testElFileNameLlegaDescodificado(): void
    {
        $this->precons->precons[] = [
            'fileName'    => 'Dandân Deck_SLD',
            'name'        => 'Dandân',
            'deckType'    => 'Theme Deck',
            'setCode'     => 'SLD',
            'setName'     => 'Secret Lair Drop',
            'releaseDate' => '2024-01-01',
            'cardCount'   => 0,
        ];

        $cuerpo = $this->pedir('/api/catalog/decks/' . rawurlencode('Dandân Deck_SLD'));

        $this->assertSame(200, http_response_code());
        $this->assertSame('Dandân Deck_SLD', $cuerpo['precon']['fileName']);
    }

    public function testUnaRutaDeMasNoLaAtiendeElPatronDeLaFicha(): void
    {
        $cuerpo = $this->pedir('/api/catalog/decks/Alfa_C20/cards');

        $this->assertSame(404, http_response_code());
        $this->assertSame(['error' => 'not_found'], $cuerpo);
    }

    /** @return array<string, mixed> */
    private function pedir(string $uri): array
    {
        http_response_code(200);

        ob_start();
        $this->router->handle($uri);
        $salida = (string) ob_get_clean();

        return json_decode($salida, true);
    }
}
