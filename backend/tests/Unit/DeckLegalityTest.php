<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Application\UseCase\AddCardToDeck;
use App\Application\UseCase\ChangeDeckCardCount;
use App\Application\UseCase\CreateDeck;
use App\Application\UseCase\GetDeck;
use App\Application\UseCase\UpdateDeck;
use App\Domain\Deck\Deck;
use App\Domain\Deck\LegalityStatus;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Doubles\MazosFalsos;

/**
 * La legalidad del mazo, **como aviso**.
 *
 * El caso que manda es el mismo mazo con *Sol Ring* al que se le cambia el
 * formato tres veces: `legacy` lo marca `banned`, `modern` lo marca `not_legal`
 * **por ausencia de fila** y `commander` no marca nada. Los tres datos son los
 * reales de `mtg_legality`, medidos contra la base: *Sol Ring* está `banned` en
 * Legacy, `restricted` en Vintage, `legal` en Commander y **no tiene ninguna
 * fila de `modern`**.
 *
 * Lo que este hito NO hace, y también se prueba aquí: **no bloquea nada**. Un
 * mazo ilegal es un mazo que existe, sus cartas siguen en la lista y cuatro
 * copias de una carta `restricted` se marcan y ya —la regla de «máximo 1 copia»
 * sería validar—.
 */
final class DeckLegalityTest extends TestCase
{
    private const ORACLE_SOL_RING = 'oracle-sol-ring';

    private MazosFalsos $repo;

    private GetDeck $ver;

    private UpdateDeck $editar;

    protected function setUp(): void
    {
        $this->repo   = new MazosFalsos();
        $this->ver    = new GetDeck($this->repo);
        $this->editar = new UpdateDeck($this->repo);

        // Dos ediciones distintas del MISMO Sol Ring: la legalidad es de la
        // carta y no de la edición, así que las dos comparten `oracle_id`.
        $this->repo->oracles = [
            'uuid-sol-ring-lea' => self::ORACLE_SOL_RING,
            'uuid-sol-ring-c21' => self::ORACLE_SOL_RING,
        ];

        // El dato real de `mtg_legality`, con `modern` AUSENTE a propósito:
        // `not_legal` no es un valor que la ingesta escriba, es la falta de fila.
        $this->repo->legalidades = [
            self::ORACLE_SOL_RING . '|commander' => 'legal',
            self::ORACLE_SOL_RING . '|legacy'    => 'banned',
            self::ORACLE_SOL_RING . '|vintage'   => 'restricted',
        ];

        // `modern` SÍ existe como formato aunque Sol Ring no tenga fila en él:
        // es justo la diferencia entre «no es legal ahí» y «ese formato no
        // existe», y sin declararlo el test probaría la segunda cosa.
        $this->repo->formatos = ['commander', 'legacy', 'modern', 'vintage'];

        (new CreateDeck($this->repo))(1, ['name' => 'El mazo del Sol Ring', 'format' => 'commander']);

        (new AddCardToDeck($this->repo))(1, [
            'deck_id'       => 1,
            'printing_uuid' => 'uuid-sol-ring-lea',
            'count'         => 1,
        ]);
    }

    // =====================================================================
    // El *Hecho cuando*: el mismo mazo, tres formatos
    // =====================================================================

    public function testEnLegacyElSolRingSaleMarcadoBanned(): void
    {
        $legalidad = $this->conFormato('legacy');

        self::assertSame('banned', $legalidad['statuses'][self::ORACLE_SOL_RING]);
        self::assertSame(1, $legalidad['banned']);
        self::assertSame(0, $legalidad['notLegal']);
    }

    public function testEnModernElSolRingSaleMarcadoNotLegalPorAusenciaDeFila(): void
    {
        // El dato: `mtg_legality` no tiene NINGUNA fila de `modern` para esta
        // carta, y `SELECT DISTINCT status` nunca devuelve `not_legal`.
        self::assertArrayNotHasKey(
            self::ORACLE_SOL_RING . '|modern',
            $this->repo->legalidades,
            'No hay fila de modern: si la hubiera, este test no probaría la ausencia'
        );

        $legalidad = $this->conFormato('modern');

        self::assertSame('not_legal', $legalidad['statuses'][self::ORACLE_SOL_RING]);
        self::assertSame(1, $legalidad['notLegal']);
        self::assertSame(0, $legalidad['banned']);
    }

    public function testEnCommanderNoSeMarcaNada(): void
    {
        $legalidad = $this->conFormato('commander');

        self::assertSame([], $legalidad['statuses'], 'Lo legal no se marca: marcarlo todo sería ruido');
        self::assertSame(0, $legalidad['banned']);
        self::assertSame(0, $legalidad['restricted']);
        self::assertSame(0, $legalidad['notLegal']);
    }

    // =====================================================================
    // El LEFT JOIN: la carta sin fila SIGUE en la lista
    // =====================================================================

    public function testLaCartaSinFilaSigueEnLaListaEnVezDeDesaparecer(): void
    {
        // Es el fallo exacto que este hito evita: con un `JOIN` a secas las
        // cartas no legales desaparecen de la lista en vez de marcarse.
        $filas = $this->repo->legalidad(1, 1, 'modern');

        self::assertArrayHasKey(self::ORACLE_SOL_RING, $filas, 'El LEFT JOIN la conserva');
        self::assertNull($filas[self::ORACLE_SOL_RING], 'Sin fila: null, no un status');

        $mazo = $this->conMazo('modern');

        self::assertCount(1, $mazo['cards'], 'Y la carta sigue en el mazo, marcada');
        self::assertCount(1, $mazo['boards']['main']);
    }

    public function testDosEdicionesDeLaMismaCartaCompartenUnaSolaMarca(): void
    {
        // La legalidad es de la carta, no de la edición: la clave es el oracle.
        (new AddCardToDeck($this->repo))(1, [
            'deck_id'       => 1,
            'printing_uuid' => 'uuid-sol-ring-c21',
        ]);

        $legalidad = $this->conFormato('legacy');

        self::assertCount(2, $this->conMazo('legacy')['cards']);
        self::assertSame(['oracle-sol-ring' => 'banned'], $legalidad['statuses']);
        self::assertSame(1, $legalidad['banned'], 'Una carta prohibida, no dos');
    }

    // =====================================================================
    // `restricted` se avisa, pero NO se valida
    // =====================================================================

    public function testEnVintageSeAvisaDeRestrictedSinImplementarLaReglaDeUnaCopia(): void
    {
        (new ChangeDeckCardCount($this->repo))(1, [
            'deck_id' => 1,
            'card_id' => 1,
            'count'   => 4,
        ]);

        $mazo = $this->conMazo('vintage');

        self::assertSame('restricted', $mazo['legality']['statuses'][self::ORACLE_SOL_RING]);
        self::assertSame(1, $mazo['legality']['restricted']);
        // Cuatro copias de una carta `restricted` se guardan igual: esto avisa,
        // no valida.
        self::assertSame(4, $mazo['boards']['main'][0]['count']);
    }

    // =====================================================================
    // Sin formato, y con un formato que no existe
    // =====================================================================

    public function testUnMazoSinFormatoNoMarcaNada(): void
    {
        // `mtg_deck.format` es NULLable y «sin formato» es un mazo válido.
        ($this->editar)(1, ['deck_id' => 1, 'format' => null]);

        $legalidad = $this->conMazo(null)['legality'];

        self::assertNull($legalidad['format']);
        self::assertFalse($legalidad['known']);
        self::assertSame([], $legalidad['statuses']);
        self::assertNull($legalidad['minSize'], 'Sin formato tampoco hay tamaño mínimo');
        self::assertFalse($legalidad['belowMinimum']);
    }

    public function testUnFormatoQueNoExisteSeDiceEnVezDeMarcarTodoNotLegal(): void
    {
        // «edh» en vez de «commander»: sin esto, las cien cartas del mazo
        // saldrían `not_legal` y sería una alarma falsa y completa.
        $legalidad = $this->conFormato('edh');

        self::assertSame('edh', $legalidad['format']);
        self::assertFalse($legalidad['known']);
        self::assertSame([], $legalidad['statuses']);
        self::assertSame(0, $legalidad['notLegal']);
    }

    // =====================================================================
    // El tamaño mínimo, que también es aviso
    // =====================================================================

    public function testCommanderPideCienYElRestoSesenta(): void
    {
        self::assertSame(100, Deck::tamanoMinimo('commander'));
        self::assertSame(60, Deck::tamanoMinimo('legacy'));
        self::assertSame(60, Deck::tamanoMinimo('modern'));
        self::assertNull(Deck::tamanoMinimo(null));
    }

    public function testElMazoCortoAvisaDelTamanoMinimoSinBloquearNada(): void
    {
        $legalidad = $this->conFormato('commander');

        self::assertSame(1, $legalidad['size']);
        self::assertSame(100, $legalidad['minSize']);
        self::assertTrue($legalidad['belowMinimum']);

        $legacy = $this->conFormato('legacy');

        self::assertSame(60, $legacy['minSize'], '60 en general');
    }

    public function testLosTokensNoCuentanParaElTamanoMinimo(): void
    {
        (new AddCardToDeck($this->repo))(1, [
            'deck_id'       => 1,
            'printing_uuid' => 'uuid-token',
            'board'         => 'tokens',
            'count'         => 40,
        ]);

        $legalidad = $this->conFormato('legacy');

        self::assertSame(1, $legalidad['size'], 'Cuarenta tokens no son cuarenta cartas del mazo');
        self::assertArrayNotHasKey('uuid-token', $legalidad['statuses'], 'Ni se marcan');
    }

    public function testUnMazoQueLlegaAlMinimoNoAvisa(): void
    {
        (new ChangeDeckCardCount($this->repo))(1, [
            'deck_id' => 1,
            'card_id' => 1,
            'count'   => 100,
        ]);

        $legalidad = $this->conFormato('commander');

        self::assertSame(100, $legalidad['size']);
        self::assertFalse($legalidad['belowMinimum']);
    }

    // =====================================================================
    // El objeto de valor
    // =====================================================================

    public function testLaAusenciaDeFilaEsNotLegal(): void
    {
        self::assertSame(LegalityStatus::NotLegal, LegalityStatus::desdeFila(null));
        self::assertSame(LegalityStatus::Banned, LegalityStatus::desdeFila('banned'));
        // Un estatus que MTGJSON invente mañana marca de más, no tumba la ficha.
        self::assertSame(LegalityStatus::NotLegal, LegalityStatus::desdeFila('inventado'));
    }

    public function testSoloLoLegalDejaDeSerAviso(): void
    {
        self::assertFalse(LegalityStatus::Legal->esAviso());
        self::assertTrue(LegalityStatus::Banned->esAviso());
        self::assertTrue(LegalityStatus::Restricted->esAviso());
        self::assertTrue(LegalityStatus::NotLegal->esAviso());
    }

    /**
     * El mazo entero con ese formato puesto. `null` no toca el formato: sirve
     * para leerlo tal y como haya quedado.
     *
     * @return array<string, mixed>
     */
    private function conMazo(?string $formato): array
    {
        if ($formato !== null) {
            ($this->editar)(1, ['deck_id' => 1, 'format' => $formato]);
        }

        $mazo = ($this->ver)(1, ['deck_id' => 1]);

        self::assertNotNull($mazo);

        return $mazo;
    }

    /** @return array<string, mixed> */
    private function conFormato(string $formato): array
    {
        return $this->conMazo($formato)['legality'];
    }
}
