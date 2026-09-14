<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Collection\CardLanguage;
use App\Domain\Collection\Condition;
use App\Domain\Collection\Finish;
use App\Domain\Deck\Board;
use App\Domain\Deck\Deck;
use App\Domain\Deck\DeckCard;
use App\Domain\Deck\DeckStatus;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/**
 * Los objetos de valor del mazo.
 *
 * `DeckStatus` y `Board` existen por lo mismo que `Finish` o `Condition`:
 * **`board` está DENTRO de `uq_deck_card`**, así que dos formas de escribir la
 * misma zona crearían dos líneas para la misma carta. Y `DeckCard` **reutiliza**
 * los tres enums de la colección en vez de duplicarlos, que es lo que hace que
 * el cruce mazo ↔ colección de M3 case filas.
 */
final class DeckValueObjectsTest extends TestCase
{
    public function testUnMazoNaceEnConstruccionPorqueEstaVacio(): void
    {
        self::assertSame(DeckStatus::Building, DeckStatus::porDefecto());
    }

    public function testSoloElMazoConstruidoConsumeColeccion(): void
    {
        // Es LA asimetría de los tres estados: un `WHERE status != 'dismantled'`
        // escrito por inercia metería los mazos en construcción en el consumo y
        // la app avisaría de conflictos que no existen.
        self::assertTrue(DeckStatus::Built->consumeColeccion());
        self::assertFalse(DeckStatus::Building->consumeColeccion());
        self::assertFalse(DeckStatus::Dismantled->consumeColeccion());
    }

    public function testUnEstadoDeMazoInventadoSeRechazaEnVezDeCaerEnElPorDefecto(): void
    {
        $this->expectException(InvalidArgumentException::class);
        DeckStatus::desde('terminado');
    }

    public function testElFiltroDeEstadoDevuelveNullEnVezDeLanzar(): void
    {
        self::assertNull(DeckStatus::intentar('terminado'));
        self::assertNull(DeckStatus::intentar(''));
        self::assertSame(DeckStatus::Built, DeckStatus::intentar('BUILT'));
    }

    public function testLasCabecerasDeLasDecklistsSonZonasDelMazo(): void
    {
        // Son las cuatro de `PlainTextParser::CABECERAS`, que hoy se tiran a la
        // basura y en M7 entrarán por aquí.
        self::assertSame(Board::Main, Board::desde('Deck'));
        self::assertSame(Board::Side, Board::desde('Sideboard'));
        self::assertSame(Board::Commander, Board::desde('Commander'));
        self::assertSame(Board::Companion, Board::desde('Companion'));
        // Y las claves de MTGJSON.
        self::assertSame(Board::Main, Board::desde('mainBoard'));
        self::assertSame(Board::Side, Board::desde('sideBoard'));
    }

    public function testLosTokensSonLaUnicaZonaQueNoSePosee(): void
    {
        // De aquí salen las tres consecuencias que el plan repite: no consumen
        // colección, no suman al valor y no cuentan para el tamaño mínimo.
        self::assertFalse(Board::Tokens->esPoseible());

        foreach ([Board::Main, Board::Side, Board::Commander, Board::Companion, Board::Planes, Board::Schemes] as $zona) {
            self::assertTrue($zona->esPoseible(), "{$zona->value} sí es una carta que se posee");
        }
    }

    public function testUnaZonaInventadaSeRechaza(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Board::desde('maybeboard');
    }

    public function testElMazoNaceSoloConSuNombre(): void
    {
        $mazo = Deck::desdePeticion(1, ['name' => '  Atraxa  ']);

        self::assertSame('Atraxa', $mazo->name, 'El nombre se recorta');
        self::assertSame(DeckStatus::Building, $mazo->status);
        self::assertNull($mazo->format);
        self::assertNull($mazo->notes);
        self::assertSame(1, $mazo->userId);
    }

    public function testElFormatoSeGuardaEnMinusculasParaCasarConMtgLegality(): void
    {
        // Con otra caja el JOIN de M6 no casaría ni una fila y nadie vería un
        // error: solo faltarían los avisos de legalidad.
        self::assertSame('commander', Deck::desdePeticion(1, ['name' => 'x', 'format' => 'Commander'])->format);
        self::assertNull(Deck::desdePeticion(1, ['name' => 'x', 'format' => '   '])->format);
    }

    public function testUnMazoSinNombreNoSeCrea(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Deck::desdePeticion(1, ['name' => '   ']);
    }

    public function testLaEdicionParcialDevuelveSoloLoQueVino(): void
    {
        // El botón «desmontar» de M5 manda solo el estado: si esto devolviera
        // las cuatro columnas, ese clic borraría el nombre y las notas.
        self::assertSame(['status' => 'dismantled'], Deck::camposDesdePeticion(['status' => 'dismantled']));
        self::assertSame([], Deck::camposDesdePeticion(['deck_id' => 7]));
        self::assertSame(['format' => null], Deck::camposDesdePeticion(['format' => null]), 'null es "quítame el formato"');
    }

    public function testLaLineaDeMazoReutilizaLosEnumsDeLaColeccion(): void
    {
        $carta = DeckCard::desdePeticion(9, [
            'printing_uuid'   => 'uuid-x',
            'finish'          => 'nonfoil',
            'language'        => 'es',
            'condition_grade' => 'Near Mint',
            'board'           => 'sideboard',
            'count'           => 4,
        ]);

        self::assertSame(Finish::Normal, $carta->finish, 'El alias de MTGJSON lo resuelve Finish, no una copia');
        self::assertSame(CardLanguage::Spanish, $carta->language);
        self::assertSame(Condition::NearMint, $carta->condition);
        self::assertSame(Board::Side, $carta->board);
        self::assertSame(4, $carta->count);
        self::assertSame(9, $carta->deckId);
    }

    public function testLaLineaAceptaConditionYConditionGrade(): void
    {
        // Los contratos de mazo lo llaman `condition_grade` y los de colección
        // `condition`; el frontend comparte el selector entre las dos pantallas.
        self::assertSame(
            Condition::LightPlayed,
            DeckCard::desdePeticion(1, ['printing_uuid' => 'uuid-x', 'condition' => 'LP'])->condition
        );
    }

    public function testUnaLineaDeMazoSinCartaNoSeCrea(): void
    {
        $this->expectException(InvalidArgumentException::class);
        DeckCard::desdePeticion(1, ['printing_uuid' => '']);
    }

    public function testElAltaExigeAlMenosUnaCopia(): void
    {
        // Poner una línea a cero es OTRA operación (`ChangeDeckCardCount`), y
        // allí el 0 significa borrarla.
        $this->expectException(InvalidArgumentException::class);
        DeckCard::desdePeticion(1, ['printing_uuid' => 'uuid-x', 'count' => 0]);
    }
}
