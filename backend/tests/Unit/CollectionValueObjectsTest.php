<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Collection\CardLanguage;
use App\Domain\Collection\CollectionItem;
use App\Domain\Collection\Condition;
use App\Domain\Collection\Finish;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/**
 * Los tres objetos de valor existen por una sola razón: las seis columnas del
 * `UNIQUE KEY` de `mtg_collection_item`. Si `'NM'` y `'Near Mint'` llegan a la
 * base de datos como strings distintos, la clave no casa y la misma carta acaba
 * en dos filas. Lo que se prueba aquí es exactamente eso: que **normalizan** lo
 * que se puede reconocer y **rechazan** lo que no.
 */
final class CollectionValueObjectsTest extends TestCase
{
    // ------------------------------------------------------------------ Finish

    public function testElAcabadoNormalizaLasFormasDeMtgjsonYScryfall(): void
    {
        self::assertSame(Finish::Normal, Finish::desde('nonfoil'));
        self::assertSame(Finish::Normal, Finish::desde(' NORMAL '));
        self::assertSame(Finish::Foil, Finish::desde('Foil'));
    }

    public function testEtchedEsUnAcabadoDePlenoDerecho(): void
    {
        // No es "foil raro": tiene su propia fila en mtg_price_current y por
        // tanto su propio precio. Confundirlo con foil falsea la valoración.
        self::assertSame(Finish::Etched, Finish::desde('etched'));
        self::assertSame(Finish::Etched, Finish::desde('Etched Foil'));
        self::assertNotSame(Finish::Foil, Finish::desde('etched'));
    }

    public function testUnAcabadoInventadoSeRechaza(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Finish::desde('galaxy foil');
    }

    // --------------------------------------------------------------- Condition

    public function testNmYNearMintSonElMismoEstado(): void
    {
        // ESTE es el test que resume el hito.
        self::assertSame(Condition::desde('NM'), Condition::desde('Near Mint'));
        self::assertSame(Condition::desde('NM'), Condition::desde('near   mint'));
        self::assertSame('NM', Condition::desde('Near Mint')->value);
    }

    public function testLosNombresLargosDeOtrasEscalasCaenEnLaDeCardmarket(): void
    {
        self::assertSame(Condition::Mint, Condition::desde('Mint'));
        self::assertSame(Condition::Played, Condition::desde('Heavily Played'));
        self::assertSame(Condition::Poor, Condition::desde('Damaged'));
    }

    public function testUnEstadoInventadoSeRechaza(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Condition::desde('Casi nuevo');
    }

    // ------------------------------------------------------------ CardLanguage

    public function testElIdiomaSeGuardaConElNombreLargoDeMtgjson(): void
    {
        // 'Spanish', no 'es': es la forma con la que hay que casar en el JOIN con
        // mtg_printing_localized. Con el código ISO el JOIN no casaría NADA y no
        // saltaría ningún error: solo faltarían los nombres traducidos.
        self::assertSame('Spanish', CardLanguage::desde('es')->value);
        self::assertSame('Spanish', CardLanguage::desde('spanish')->value);
        self::assertSame('Japanese', CardLanguage::desde('ja')->value);
    }

    public function testLosIdiomasLargosCabenEnLaColumnaVarchar32(): void
    {
        // El motivo de que la columna sea VARCHAR(32) y no VARCHAR(16): estos dos
        // miden 19 y van DENTRO del UNIQUE KEY, así que truncarlos fundiría filas
        // que no son la misma carta.
        self::assertSame('Chinese Traditional', CardLanguage::desde('zht')->value);
        self::assertSame('Portuguese (Brazil)', CardLanguage::desde('pt')->value);
        self::assertSame(19, mb_strlen(CardLanguage::Portuguese->value));

        foreach (CardLanguage::cases() as $idioma) {
            self::assertLessThanOrEqual(32, mb_strlen($idioma->value), $idioma->value);
        }
    }

    public function testUnIdiomaInventadoSeRechaza(): void
    {
        $this->expectException(InvalidArgumentException::class);
        CardLanguage::desde('Klingon');
    }

    public function testUnFiltroConValorDesconocidoSeIgnoraEnVezDeRomper(): void
    {
        // `intentar()` es para los filtros de una vista, que vienen de una query
        // string que cualquiera puede teclear: ahí no se revienta, se ignora.
        self::assertNull(Finish::intentar('galaxy foil'));
        self::assertNull(Condition::intentar('regulero'));
        self::assertNull(CardLanguage::intentar('Klingon'));
        self::assertNull(Finish::intentar(''));
    }

    // ---------------------------------------------------------- CollectionItem

    public function testLosValoresPorDefectoSonLosDelBotonDeUnClic(): void
    {
        $item = CollectionItem::desdePeticion(7, ['printing_uuid' => 'abc']);

        self::assertSame(7, $item->userId);
        self::assertSame(Finish::Normal, $item->finish);
        self::assertSame(CardLanguage::English, $item->language);
        self::assertSame(Condition::NearMint, $item->condition);
        self::assertSame(1, $item->quantity);
        self::assertFalse($item->isWishlist);
    }

    public function testUnaLineaSinPrintingNoEsUnaLinea(): void
    {
        $this->expectException(InvalidArgumentException::class);
        CollectionItem::desdePeticion(1, ['quantity' => 3]);
    }

    public function testLaFilaLlevaLasSeisColumnasDeLaClaveUnica(): void
    {
        $fila = CollectionItem::desdePeticion(1, [
            'printing_uuid' => 'abc',
            'finish'        => 'Etched Foil',
            'language'      => 'es',
            'condition'     => 'Near Mint',
            'is_wishlist'   => true,
        ])->aFila();

        self::assertSame(
            ['user_id', 'printing_uuid', 'finish', 'language', 'condition_grade', 'quantity', 'is_wishlist', 'notes'],
            array_keys($fila)
        );
        self::assertSame('etched', $fila['finish']);
        self::assertSame('Spanish', $fila['language']);
        self::assertSame('NM', $fila['condition_grade']);
        self::assertSame(1, $fila['is_wishlist']);
    }
}
