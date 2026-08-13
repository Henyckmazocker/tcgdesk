<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Infrastructure\Mtgjson\MtgJsonPriceExtractor;
use PHPUnit\Framework\TestCase;

/**
 * Lo que protege este test es que **no entre ni un dólar** en una columna que
 * toda la app lee como euros. MTGJSON publica cinco proveedores y solo
 * Cardmarket cotiza en EUR; un fallo aquí no da error, da precios equivocados.
 */
final class MtgJsonPriceExtractorTest extends TestCase
{
    private MtgJsonPriceExtractor $extractor;

    protected function setUp(): void
    {
        $this->extractor = new MtgJsonPriceExtractor();
    }

    /**
     * La forma real de AllPricesToday, tomada del fichero del 2026-08-12.
     *
     * @return array<string, mixed>
     */
    private function precios(): array
    {
        return [
            'paper' => [
                'cardmarket' => [
                    'currency' => 'EUR',
                    'buylist'  => ['normal' => ['2026-08-12' => 1.50]],
                    'retail'   => [
                        'normal' => ['2026-08-12' => 3.07],
                        'foil'   => ['2026-08-12' => 4.28],
                    ],
                ],
                'tcgplayer' => [
                    'currency' => 'USD',
                    'retail'   => ['normal' => ['2026-08-12' => 11.16]],
                ],
                'cardkingdom' => [
                    'currency' => 'USD',
                    'retail'   => ['foil' => ['2026-08-12' => 9.49]],
                ],
            ],
            'mtgo' => [
                'cardhoarder' => [
                    'currency' => 'USD',
                    'retail'   => ['normal' => ['2026-08-12' => 0.02]],
                ],
            ],
        ];
    }

    public function testSoloSeQuedaConCardmarketRetail(): void
    {
        $filas = $this->extractor->extraer($this->precios());

        self::assertCount(2, $filas);
        self::assertEqualsCanonicalizing([3.07, 4.28], array_column($filas, 'price_eur'));
    }

    public function testIgnoraLosProveedoresEnDolares(): void
    {
        $precios = $this->extractor->extraer($this->precios());
        $valores = array_column($precios, 'price_eur');

        // Los precios de tcgplayer, cardkingdom y cardhoarder no aparecen.
        self::assertNotContains(11.16, $valores);
        self::assertNotContains(9.49, $valores);
        self::assertNotContains(0.02, $valores);
    }

    /**
     * El buylist es lo que la tienda paga por comprarte la carta, no lo que
     * cuesta. Valorar una colección con el buylist la infravalora a la mitad.
     */
    public function testIgnoraElBuylist(): void
    {
        self::assertNotContains(1.50, array_column($this->extractor->extraer($this->precios()), 'price_eur'));
    }

    public function testExtraeLosTresAcabadosIncluidoEtched(): void
    {
        $precios = [
            'paper' => ['cardmarket' => [
                'currency' => 'EUR',
                'retail'   => [
                    'normal' => ['2026-08-12' => 1.0],
                    'foil'   => ['2026-08-12' => 2.0],
                    'etched' => ['2026-08-12' => 3.0],
                ],
            ]],
        ];

        self::assertEqualsCanonicalizing(
            ['normal', 'foil', 'etched'],
            array_column($this->extractor->extraer($precios), 'finish')
        );
    }

    /**
     * Si MTGJSON cambiara la divisa de Cardmarket, es preferible no guardar nada
     * a guardar dólares donde la app espera euros.
     */
    public function testUnaDivisaQueNoSeaEurSeDescartaEntera(): void
    {
        $precios = [
            'paper' => ['cardmarket' => [
                'currency' => 'USD',
                'retail'   => ['normal' => ['2026-08-12' => 3.07]],
            ]],
        ];

        self::assertSame([], $this->extractor->extraer($precios));
    }

    public function testUnaCartaSinCardmarketNoDaFilas(): void
    {
        self::assertSame([], $this->extractor->extraer([
            'paper' => ['tcgplayer' => ['currency' => 'USD', 'retail' => ['normal' => ['2026-08-12' => 5.0]]]],
        ]));

        self::assertSame([], $this->extractor->extraer([]));
        self::assertSame([], $this->extractor->extraer(['mtgo' => ['cardhoarder' => ['retail' => []]]]));
    }

    public function testDescartaLosPreciosQueNoSonNumericos(): void
    {
        $precios = [
            'paper' => ['cardmarket' => [
                'currency' => 'EUR',
                'retail'   => ['normal' => ['2026-08-12' => null, '2026-08-11' => 'n/a', '2026-08-10' => 2.5]],
            ]],
        ];

        $filas = $this->extractor->extraer($precios);

        self::assertCount(1, $filas);
        self::assertSame(2.5, $filas[0]['price_eur']);
    }

    // ------------------------------------------------------------------
    // El precio vigente
    // ------------------------------------------------------------------

    public function testTomaElDiaMasRecienteDeCadaAcabado(): void
    {
        $filas = [
            ['finish' => 'normal', 'price_date' => '2026-08-10', 'price_eur' => 1.0],
            ['finish' => 'normal', 'price_date' => '2026-08-12', 'price_eur' => 3.0],
            ['finish' => 'normal', 'price_date' => '2026-08-11', 'price_eur' => 2.0],
            ['finish' => 'foil',   'price_date' => '2026-08-09', 'price_eur' => 9.0],
        ];

        $vigentes = $this->extractor->masRecientesPorAcabado($filas);

        self::assertCount(2, $vigentes);

        $porAcabado = array_column($vigentes, null, 'finish');
        self::assertSame(3.0, $porAcabado['normal']['price_eur']);
        self::assertSame('2026-08-12', $porAcabado['normal']['price_date']);
        self::assertSame(9.0, $porAcabado['foil']['price_eur']);
    }

    public function testSinFilasNoHayVigentes(): void
    {
        self::assertSame([], $this->extractor->masRecientesPorAcabado([]));
    }
}
