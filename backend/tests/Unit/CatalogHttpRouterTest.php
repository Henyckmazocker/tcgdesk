<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Application\UseCase\GetPrecon;
use App\Application\UseCase\SearchCards;
use App\Application\UseCase\SearchPrecons;
use App\Domain\Catalog\SearchCriteria;
use App\Domain\Repository\CardRepositoryInterface;
use App\Router\CatalogHttpRouter;
use PHPUnit\Framework\Attributes\DataProvider;
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

    public function impresionesDe(string $uuid, ?string $cursor, int $limite): ?array
    {
        return null;
    }

    /**
     * El escáner no pasa por aquí: este doble prueba otra cosa. Existe porque
     * la interfaz lo declara desde el M2 del Plan - Escáner de Cartas por Cámara.
     */
    public function porUuids(array $uuids): array
    {
        return [];
    }

    public function allSets(): array
    {
        return [];
    }
}

/**
 * Un catálogo que sí sabe de impresiones: el doble de las rutas de carta.
 *
 * Apunta lo que recibe `impresionesDe()` en vez de devolver algo fijo, porque lo
 * que prueba el router de `/printings` no es la consulta —eso es de
 * `ImpresionesDeCartaTest`, contra MySQL de verdad— sino **lo que el router le
 * pasa al repositorio**: el `limit` acotado, el cursor tal cual y el uuid
 * descodificado. Un doble que solo devolviera filas dejaría el acotado sin
 * probar, que es justo el único sitio donde se acota (`ValidationMiddleware` no
 * ve esta ruta).
 */
class CatalogoDeImpresiones implements CardRepositoryInterface
{
    /** @var list<array{uuid: string, cursor: string|null, limite: int}> */
    public array $llamadas = [];

    /** Lo que devolverá `impresionesDe()`. `null` es el uuid que no existe. */
    public ?array $pagina = null;

    /** Lo que devolverá `findByUuid()`, para distinguir la ficha de la lista. */
    public ?array $ficha = null;

    public function search(SearchCriteria $criterios): array
    {
        return ['items' => [], 'nextCursor' => null];
    }

    public function findByUuid(string $uuid): ?array
    {
        return $this->ficha;
    }

    public function impresionesDe(string $uuid, ?string $cursor, int $limite): ?array
    {
        $this->llamadas[] = ['uuid' => $uuid, 'cursor' => $cursor, 'limite' => $limite];

        return $this->pagina;
    }

    /**
     * El escáner no pasa por aquí: este doble prueba otra cosa. Existe porque
     * la interfaz lo declara desde el M2 del Plan - Escáner de Cartas por Cámara.
     */
    public function porUuids(array $uuids): array
    {
        return [];
    }

    public function allSets(): array
    {
        return [];
    }
}

/**
 * Las rutas `GET` de precons y la sexta ruta, `/cards/{uuid}/printings`.
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
 * Y de `/printings`, por el mismo orden:
 *
 *  1. **Que el `limit` se acota aquí o no lo acota nadie.** Esta ruta no tiene
 *     criteria que normalice `$_GET` y `public/index.php` la desvía antes de
 *     construir `Application`, así que `ValidationMiddleware` ni la ve. Un
 *     `limit=100000` sin acotar es un `LIMIT` de seis cifras sobre las 949
 *     impresiones del peor caso.
 *  2. **Que el 404 es del `uuid` y no de la carta.** El repositorio devuelve
 *     `null` solo cuando el uuid no está en `mtg_printing`; una carta que nunca
 *     se reimprimió es un 200 con un único item, y confundirlos haría que
 *     `/import` leyera «esta carta no se puede corregir» como «este uuid está
 *     roto».
 *  3. **Que `/printings` no se la come la ficha.** Su rama va antes en el
 *     `match` (`CatalogHttpRouter.php:87`), y el doble devuelve cosas distintas
 *     por cada camino para que intercambiarlas se vea.
 *
 * Las cabeceras no se pueden inspeccionar bajo el SAPI de CLI —mismo límite que
 * `ImageHttpRouterTest`—, así que el `Cache-Control` de 5 minutos se verifica con
 * `curl` contra el contenedor, no aquí. Lo que sí se comprueba es el código de
 * respuesta y el cuerpo.
 */
final class CatalogHttpRouterTest extends TestCase
{
    private PreconesFalsos $precons;

    private CatalogoDeImpresiones $cartas;

    private CatalogHttpRouter $router;

    protected function setUp(): void
    {
        $this->precons = new PreconesFalsos();
        $this->cartas  = new CatalogoDeImpresiones();

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
            $this->cartas,
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

    /* ── La sexta ruta: /api/catalog/cards/{uuid}/printings ──────────────── */

    public function testLasImpresionesSalenConSuCursorYSinUnaClaveDeMas(): void
    {
        $this->cartas->pagina = [
            'items'      => [['uuid' => 'u-1', 'setCode' => 'LEA'], ['uuid' => 'u-2', 'setCode' => 'A25']],
            'nextCursor' => 'eyJ2IjoiMTk5MyJ9',
        ];

        $cuerpo = $this->pedir('/api/catalog/cards/u-1/printings');

        $this->assertSame(200, http_response_code());
        // Exactamente `items` + `nextCursor`, el mismo par que /cards y /decks:
        // es lo que permite que el scroll infinito del frontend sea un solo
        // trozo de código. Una clave de más aquí lo bifurcaría.
        $this->assertSame(['items', 'nextCursor'], array_keys($cuerpo));
        $this->assertSame(['u-1', 'u-2'], array_column($cuerpo['items'], 'uuid'));
        $this->assertSame('eyJ2IjoiMTk5MyJ9', $cuerpo['nextCursor']);
    }

    public function testUnUuidQueNoExisteEs404PrintingNotFound(): void
    {
        // `null` del repositorio = el uuid no está en `mtg_printing`.
        $this->cartas->pagina = null;

        $cuerpo = $this->pedir('/api/catalog/cards/no-existe/printings');

        $this->assertSame(404, http_response_code());
        $this->assertSame(['error' => 'printing_not_found'], $cuerpo);
    }

    public function testUnaCartaDeImpresionUnicaEs200ConUnItem(): void
    {
        $this->cartas->pagina = ['items' => [['uuid' => 'u-solo']], 'nextCursor' => null];

        $cuerpo = $this->pedir('/api/catalog/cards/u-solo/printings');

        // El 404 es del `uuid`, no de la carta: una impresión que existe y cuya
        // carta no tiene hermanas devuelve su única fila. Si esto fuese 404,
        // `/import` leería «esta carta no se puede corregir» como «este uuid
        // está roto», que es otro fallo y con otra salida.
        $this->assertSame(200, http_response_code());
        $this->assertCount(1, $cuerpo['items']);
        $this->assertNull($cuerpo['nextCursor']);
    }

    /**
     * El acotado de `limit`, que es lo único que separa a esta ruta de un
     * `LIMIT` de seis cifras: no hay criteria que normalice `$_GET` y
     * `ValidationMiddleware` no ve el desvío del catálogo.
     *
     * Los cuatro casos son los que M2 midió a mano contra el contenedor.
     *
     * @return list<array{0: array<string, string>, 1: int}>
     */
    public static function limitesQueLleganPorLaUrl(): array
    {
        return [
            'el techo de 100'        => [['limit' => '100000'], 100],
            'el defecto de 60'       => [[], 60],
            'el suelo de 1'          => [['limit' => '0'], 1],
            'no numerico, al defecto' => [['limit' => 'abc'], 60],
        ];
    }

    /**
     * @param array<string, string> $query
     */
    #[DataProvider('limitesQueLleganPorLaUrl')]
    public function testElLimiteSeAcotaEntreUnoYCien(array $query, int $esperado): void
    {
        $this->cartas->pagina = ['items' => [], 'nextCursor' => null];

        $_GET = $query;
        $this->pedir('/api/catalog/cards/u-1/printings?' . http_build_query($query));

        $this->assertSame($esperado, $this->cartas->llamadas[0]['limite']);
    }

    public function testElCursorViajaCrudoYSinElNoHayCursor(): void
    {
        $this->cartas->pagina = ['items' => [], 'nextCursor' => null];

        // Un cursor ilegible NO es un error: `Cursor::decodificar()` lo trata
        // como «empieza por el principio». Validarlo aquí convertiría un cursor
        // caducado en un 400 que el cliente no sabría resolver.
        $_GET = ['cursor' => 'basura-que-no-es-base64'];
        $this->pedir('/api/catalog/cards/u-1/printings?cursor=basura-que-no-es-base64');

        $this->assertSame('basura-que-no-es-base64', $this->cartas->llamadas[0]['cursor']);

        $_GET = [];
        $this->pedir('/api/catalog/cards/u-1/printings');

        $this->assertNull($this->cartas->llamadas[1]['cursor']);
    }

    public function testElUuidLlegaDescodificadoAlRepositorio(): void
    {
        $this->cartas->pagina = ['items' => [], 'nextCursor' => null];

        $this->pedir('/api/catalog/cards/' . rawurlencode('uuid con espacio') . '/printings');

        $this->assertSame('uuid con espacio', $this->cartas->llamadas[0]['uuid']);
    }

    public function testLaFichaNoSeComeLaRutaDeImpresionesNiAlReves(): void
    {
        $this->cartas->ficha  = ['uuid' => 'u-1', 'name' => 'Lightning Bolt'];
        $this->cartas->pagina = ['items' => [['uuid' => 'u-1']], 'nextCursor' => null];

        // La ficha sigue siendo la ficha: no lleva `items`.
        $ficha = $this->pedir('/api/catalog/cards/u-1');

        $this->assertSame(200, http_response_code());
        $this->assertSame('Lightning Bolt', $ficha['name']);
        $this->assertArrayNotHasKey('items', $ficha);
        $this->assertSame([], $this->cartas->llamadas);

        // Y `/printings` va por su rama, que en el `match` está ANTES.
        $lista = $this->pedir('/api/catalog/cards/u-1/printings');

        $this->assertArrayHasKey('items', $lista);
        $this->assertArrayNotHasKey('name', $lista);
        $this->assertCount(1, $this->cartas->llamadas);
    }

    public function testUnSegmentoDeMasDespuesDePrintingsNoLoAtiendeNadie(): void
    {
        $cuerpo = $this->pedir('/api/catalog/cards/u-1/printings/otra-cosa');

        $this->assertSame(404, http_response_code());
        $this->assertSame(['error' => 'not_found'], $cuerpo);
        $this->assertSame([], $this->cartas->llamadas);
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
