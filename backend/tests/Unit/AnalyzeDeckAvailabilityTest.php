<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Application\UseCase\AddCardToDeck;
use App\Application\UseCase\AddToCollection;
use App\Application\UseCase\AnalyzeDeckAvailability;
use App\Application\UseCase\CreateDeck;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Doubles\ColeccionFalsa;
use Tests\Unit\Doubles\MazosFalsos;

/**
 * El cruce mazo ↔ colección, que es **lo que ninguna app hace bien**: saber que
 * dos mazos construidos se están peleando por el mismo Sol Ring.
 *
 * Lo que hay que probarle son dos cosas, y la segunda importa más que la
 * primera:
 *
 *  1. que el conflicto salga **con los dos mazos nombrados**, porque un aviso sin
 *     nombres no se puede resolver: la UI ofrece desmontar **uno**;
 *  2. que **nada cambie de estado solo**. Un `UPDATE` automático sobre
 *     `mtg_deck.status` disparado por una condición calculada es exactamente lo
 *     que este hito prohíbe, y es el tipo de cosa que se implementa «de más» sin
 *     querer.
 *
 * Y las tres asimetrías del cruce, que son las que se escriben mal: solo `built`
 * consume, los tokens no cuentan y la wishlist no es colección.
 */
final class AnalyzeDeckAvailabilityTest extends TestCase
{
    private ColeccionFalsa $coleccion;

    private MazosFalsos $repo;

    private AnalyzeDeckAvailability $analizar;

    protected function setUp(): void
    {
        $this->coleccion = new ColeccionFalsa();
        $this->repo      = new MazosFalsos($this->coleccion);
        $this->analizar  = new AnalyzeDeckAvailability($this->repo);
    }

    public function testElCasoDelPlanDosMazosConstruidosSePeleanPorElMismoSolRing(): void
    {
        // Dos mazos CONSTRUIDOS que piden 2 Sol Ring cada uno, y una colección
        // con 3: reclamado 4, en colección 3, falta 1.
        $atraxa = $this->crearMazo('Atraxa', 'built');
        $edgar  = $this->crearMazo('Edgar Markov', 'built');

        $this->anadirAlMazo($atraxa, ['printing_uuid' => 'uuid-sol', 'count' => 2]);
        $this->anadirAlMazo($edgar, ['printing_uuid' => 'uuid-sol', 'count' => 2]);
        $this->anadirALaColeccion(['printing_uuid' => 'uuid-sol', 'quantity' => 3]);

        $analisis = ($this->analizar)(1, []);

        self::assertTrue($analisis['overallocated']);
        self::assertCount(1, $analisis['conflicts'], 'Una carta en conflicto, no una por mazo');

        $conflicto = $analisis['conflicts'][0];

        self::assertSame('uuid-sol', $conflicto['printingUuid']);
        self::assertSame(4, $conflicto['claimed'], '2 + 2: los dos mazos suman');
        self::assertSame(3, $conflicto['inCollection']);
        self::assertSame(1, $conflicto['missing']);
        self::assertSame(0, $conflicto['free'], 'Sobreasignado: no queda ninguna libre');

        // Los dos mazos, nombrados: sin esto la UI no puede ofrecer desmontar
        // ninguno en concreto.
        $porId = array_column($conflicto['decks'], null, 'id');

        self::assertCount(2, $porId);
        self::assertSame('Atraxa', $porId[$atraxa]['name']);
        self::assertSame('Edgar Markov', $porId[$edgar]['name']);
        self::assertSame(2, $porId[$atraxa]['claimed']);
        self::assertSame(2, $porId[$edgar]['claimed']);
    }

    public function testElAnalisisNoDesmontaNadaYLosDosMazosSiguenConstruidos(): void
    {
        // EL assert que de verdad importa. La sobreasignación INFORMA: es el
        // usuario quien decide desmontar, y un cambio de estado automático
        // disparado por una condición calculada es un cambio que luego nadie
        // sabe explicar.
        $atraxa = $this->crearMazo('Atraxa', 'built');
        $edgar  = $this->crearMazo('Edgar Markov', 'built');

        $this->anadirAlMazo($atraxa, ['printing_uuid' => 'uuid-sol', 'count' => 2]);
        $this->anadirAlMazo($edgar, ['printing_uuid' => 'uuid-sol', 'count' => 2]);
        $this->anadirALaColeccion(['printing_uuid' => 'uuid-sol', 'quantity' => 3]);

        ($this->analizar)(1, []);
        ($this->analizar)(1, ['deck_id' => $atraxa]);

        self::assertSame('built', $this->repo->mazos[$atraxa]['status']);
        self::assertSame('built', $this->repo->mazos[$edgar]['status']);
    }

    public function testConLaColeccionSuficienteNoHayConflicto(): void
    {
        $atraxa = $this->crearMazo('Atraxa', 'built');
        $edgar  = $this->crearMazo('Edgar Markov', 'built');

        $this->anadirAlMazo($atraxa, ['printing_uuid' => 'uuid-sol', 'count' => 2]);
        $this->anadirAlMazo($edgar, ['printing_uuid' => 'uuid-sol', 'count' => 2]);
        $this->anadirALaColeccion(['printing_uuid' => 'uuid-sol', 'quantity' => 4]);

        $analisis = ($this->analizar)(1, []);

        self::assertFalse($analisis['overallocated']);
        self::assertSame([], $analisis['conflicts']);
    }

    public function testUnMazoEnConstruccionNoConsumeColeccion(): void
    {
        // Los tres estados NO son simétricos: escribir `!= 'dismantled'` por
        // inercia metería este mazo en el consumo y la app avisaría de un
        // conflicto que no existe.
        $atraxa = $this->crearMazo('Atraxa', 'built');
        $nuevo  = $this->crearMazo('Prueba', 'building');

        $this->anadirAlMazo($atraxa, ['printing_uuid' => 'uuid-sol', 'count' => 2]);
        $this->anadirAlMazo($nuevo, ['printing_uuid' => 'uuid-sol', 'count' => 2]);
        $this->anadirALaColeccion(['printing_uuid' => 'uuid-sol', 'quantity' => 2]);

        self::assertSame([], ($this->analizar)(1, [])['conflicts']);
    }

    public function testUnMazoDesmontadoTampocoConsumeYEsPuroArchivo(): void
    {
        $viejo = $this->crearMazo('El de 2019', 'dismantled');

        $this->anadirAlMazo($viejo, ['printing_uuid' => 'uuid-sol', 'count' => 4]);

        self::assertSame([], ($this->analizar)(1, [])['conflicts']);
    }

    public function testLosTokensNoConsumenColeccion(): void
    {
        // Un token se genera, no se compra: no puede pelearse con nadie por él.
        $atraxa = $this->crearMazo('Atraxa', 'built');

        $this->anadirAlMazo($atraxa, ['printing_uuid' => 'uuid-token', 'board' => 'tokens', 'count' => 9]);

        $analisis = ($this->analizar)(1, ['deck_id' => $atraxa]);

        self::assertSame([], $analisis['conflicts']);
        self::assertSame([], $analisis['lines'], 'El token no es una línea que se posea');
        self::assertSame(0, $analisis['missing']);
    }

    public function testLaWishlistNoCuentaComoCartaQueTienes(): void
    {
        // Sin `is_wishlist = 0`, una carta que QUIERES contaría como carta que
        // TIENES y el mazo diría que está completo.
        $atraxa = $this->crearMazo('Atraxa', 'built');

        $this->anadirAlMazo($atraxa, ['printing_uuid' => 'uuid-sol', 'count' => 1]);
        $this->anadirALaColeccion(['printing_uuid' => 'uuid-sol', 'quantity' => 1, 'is_wishlist' => true]);

        $conflictos = ($this->analizar)(1, [])['conflicts'];

        self::assertCount(1, $conflictos);
        self::assertSame(0, $conflictos[0]['inCollection']);
        self::assertSame(1, $conflictos[0]['missing']);
    }

    public function testDeUnMazoEnConstruccionSaleLoQueFaltaYCuantoCuesta(): void
    {
        // El «te faltan N»: mismo cruce, un solo mazo y sin filtrar por estado,
        // porque `building` nunca pasaría un filtro de `built` y es justo el que
        // necesita saber cuánto le falta.
        $nuevo = $this->crearMazo('Modern a medias', 'building');

        $this->anadirAlMazo($nuevo, ['printing_uuid' => 'uuid-bolt', 'count' => 4]);
        $this->anadirALaColeccion(['printing_uuid' => 'uuid-bolt', 'quantity' => 1]);
        $this->repo->precios['uuid-bolt|normal'] = 2.5;

        $analisis = ($this->analizar)(1, ['deck_id' => $nuevo]);

        self::assertSame('Modern a medias', $analisis['deck']['name']);
        self::assertCount(1, $analisis['lines']);
        self::assertSame(4, $analisis['lines'][0]['claimed']);
        self::assertSame(1, $analisis['lines'][0]['inCollection']);
        self::assertSame(3, $analisis['lines'][0]['missing']);
        self::assertSame(2.5, $analisis['lines'][0]['priceEur']);
        self::assertSame(7.5, $analisis['lines'][0]['missingValueEur'], 'Tres a 2,50 €');
        self::assertSame(3, $analisis['missing']);
        self::assertSame(7.5, $analisis['missingValueEur']);
    }

    public function testUnaCartaSinCotizacionSigueSaliendoConPrecioDesconocido(): void
    {
        // Es el motivo del LEFT JOIN: con un JOIN normal la carta desaparecería
        // del análisis, que es el peor fallo posible y además silencioso.
        $nuevo = $this->crearMazo('Modern a medias', 'building');

        $this->anadirAlMazo($nuevo, ['printing_uuid' => 'uuid-raro', 'count' => 2]);

        $analisis = ($this->analizar)(1, ['deck_id' => $nuevo]);

        self::assertCount(1, $analisis['lines']);
        self::assertNull($analisis['lines'][0]['priceEur'], 'Sin precio no es 0 €');
        self::assertSame(0.0, $analisis['missingValueEur'], 'Al sumar, el desconocido sí vale 0');
        self::assertSame(2, $analisis['missing']);
    }

    public function testLasLineasCubiertasSalenTambienConLoQueQuedaLibre(): void
    {
        // El contrato pide reclamado, en colección y libre POR LÍNEA: una lista
        // recortada a los conflictos no podría decir de las demás que están
        // cubiertas.
        $nuevo = $this->crearMazo('Modern a medias', 'building');

        $this->anadirAlMazo($nuevo, ['printing_uuid' => 'uuid-bolt', 'count' => 2]);
        $this->anadirALaColeccion(['printing_uuid' => 'uuid-bolt', 'quantity' => 5]);

        $linea = ($this->analizar)(1, ['deck_id' => $nuevo])['lines'][0];

        self::assertSame(2, $linea['claimed']);
        self::assertSame(5, $linea['inCollection']);
        self::assertSame(3, $linea['free']);
        self::assertSame(0, $linea['missing']);
        self::assertSame(0.0, $linea['missingValueEur']);
    }

    public function testLaMismaCartaEnElMainYEnElSideEsUnaSolaLineaDelCruce(): void
    {
        // Dos líneas del mazo, pero UNA sola fila de colección: partir el aviso
        // en dos mitades no diría nada.
        $nuevo = $this->crearMazo('Modern a medias', 'building');

        $this->anadirAlMazo($nuevo, ['printing_uuid' => 'uuid-bolt', 'count' => 3]);
        $this->anadirAlMazo($nuevo, ['printing_uuid' => 'uuid-bolt', 'count' => 1, 'board' => 'sideboard']);
        $this->anadirALaColeccion(['printing_uuid' => 'uuid-bolt', 'quantity' => 2]);

        $analisis = ($this->analizar)(1, ['deck_id' => $nuevo]);

        self::assertCount(1, $analisis['lines'], 'Un solo aviso: «pides 4, tienes 2»');
        self::assertSame(4, $analisis['lines'][0]['claimed']);
        self::assertSame(2, $analisis['lines'][0]['missing']);
    }

    public function testElFoilYElNoFoilSonCartasDistintas(): void
    {
        // Las cinco dimensiones son la carta: tener el foil no completa la línea
        // que pide el normal.
        $atraxa = $this->crearMazo('Atraxa', 'built');

        $this->anadirAlMazo($atraxa, ['printing_uuid' => 'uuid-sol', 'count' => 1]);
        $this->anadirALaColeccion(['printing_uuid' => 'uuid-sol', 'quantity' => 1, 'finish' => 'foil']);

        $conflictos = ($this->analizar)(1, [])['conflicts'];

        self::assertCount(1, $conflictos);
        self::assertSame('normal', $conflictos[0]['finish']);
        self::assertSame(0, $conflictos[0]['inCollection']);
    }

    public function testSinDeckIdSoloSaleElAvisoGlobal(): void
    {
        $atraxa = $this->crearMazo('Atraxa', 'built');

        $this->anadirAlMazo($atraxa, ['printing_uuid' => 'uuid-sol', 'count' => 2]);

        $analisis = ($this->analizar)(1, []);

        self::assertNull($analisis['deck']);
        self::assertSame([], $analisis['lines']);
        self::assertSame(0, $analisis['missing']);
        self::assertCount(1, $analisis['conflicts']);
    }

    public function testUnMazoQueNoExisteDaNullParaQueElControllerResponda404(): void
    {
        self::assertNull(($this->analizar)(1, ['deck_id' => 404]));
    }

    public function testNoSeAnalizaElMazoDeOtroUsuario(): void
    {
        // Una lista vacía significa «no te falta nada», que es una respuesta muy
        // distinta de «ese mazo no es tuyo».
        $atraxa = $this->crearMazo('Atraxa', 'built');

        $this->anadirAlMazo($atraxa, ['printing_uuid' => 'uuid-sol', 'count' => 2]);

        self::assertNull(($this->analizar)(2, ['deck_id' => $atraxa]));
    }

    public function testElConflictoDeOtroUsuarioNoSeMezclaConElTuyo(): void
    {
        $mio  = $this->crearMazo('Atraxa', 'built');
        $suyo = (new CreateDeck($this->repo))(2, ['name' => 'El de otro', 'status' => 'built'])['deck']['id'];

        $this->anadirAlMazo($mio, ['printing_uuid' => 'uuid-sol', 'count' => 1]);
        (new AddCardToDeck($this->repo))(2, ['deck_id' => $suyo, 'printing_uuid' => 'uuid-sol', 'count' => 9]);
        $this->anadirALaColeccion(['printing_uuid' => 'uuid-sol', 'quantity' => 1]);

        self::assertSame([], ($this->analizar)(1, [])['conflicts'], 'Lo que reclame otro no es mi conflicto');
    }

    /** El id del mazo recién creado. */
    private function crearMazo(string $nombre, string $estado): int
    {
        return (int) (new CreateDeck($this->repo))(1, ['name' => $nombre, 'status' => $estado])['deck']['id'];
    }

    /** @param array<string, mixed> $peticion */
    private function anadirAlMazo(int $deckId, array $peticion): void
    {
        (new AddCardToDeck($this->repo))(1, $peticion + ['deck_id' => $deckId]);
    }

    /** @param array<string, mixed> $peticion */
    private function anadirALaColeccion(array $peticion): void
    {
        (new AddToCollection($this->coleccion))(1, $peticion);
    }
}
