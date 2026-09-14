<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Application\UseCase\GetPrecon;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Doubles\PreconesFalsos;

/**
 * La ficha de un precon: zonas, valor y cartas que el catálogo no conoce.
 *
 * Las tres cosas que se protegen aquí son las tres que se rompen solas:
 *
 *  1. **Los `tokens` no suman**, ni al valor ni a los ejemplares. Cinco precons
 *     de la base real son productos de solo fichas y su `cardCount` es 0 **y no
 *     es un error**; si el valor los contara, esos cinco valdrían dinero.
 *  2. **Una carta sin cotizar no desaparece ni vale 0 en la línea.** Suma como
 *     cero y se enseña como `null`: no saber cuánto vale no es valer nada.
 *  3. **Las huérfanas salen marcadas y contadas.** Son 254 filas en la base real
 *     y el peor fallo posible sería que la lista se las tragara en silencio.
 */
final class GetPreconTest extends TestCase
{
    private PreconesFalsos $precons;

    private GetPrecon $caso;

    protected function setUp(): void
    {
        $this->precons = new PreconesFalsos();
        $this->caso    = new GetPrecon($this->precons);

        $this->precons->precons = [[
            'fileName'    => 'SneakAttack_ZNC',
            'name'        => 'Sneak Attack',
            'deckType'    => 'Commander Deck',
            'setCode'     => 'ZNC',
            'setName'     => 'Zendikar Rising Commander',
            'releaseDate' => '2020-09-25',
            'cardCount'   => 100,
        ]];

        $this->precons->cartas['SneakAttack_ZNC'] = [
            $this->carta('c-1', 'commander', 'foil', 1, 10.0),
            $this->carta('m-1', 'main', 'normal', 3, 2.5),
            // Sin cotización: suma cero, se enseña null.
            $this->carta('m-2', 'main', 'normal', 2, null),
            // Huérfana: el uuid no está en `mtg_printing` todavía.
            $this->carta('h-1', 'main', 'normal', 1, null, false),
            // Ficha: ni vale ni cuenta.
            $this->carta('t-1', 'tokens', 'normal', 4, 5.0),
        ];
    }

    public function testUnFileNameQueNoExisteDevuelveNull(): void
    {
        // Es el 404 `precon_not_found` de la ruta: lo decide el use case, no el
        // router, para que la decisión se pueda probar sin HTTP.
        $this->assertNull(($this->caso)(['file_name' => 'NoExiste_XXX']));
        $this->assertNull(($this->caso)(['file_name' => '']));
        $this->assertNull(($this->caso)([]));
    }

    public function testElValorExcluyeLosTokensYTrataElPrecioDesconocidoComoCero(): void
    {
        $ficha = ($this->caso)(['file_name' => 'SneakAttack_ZNC']);

        // 1×10,00 (commander foil) + 3×2,50 (main) = 17,50. Los 4 tokens a 5,00
        // NO entran, y las dos líneas sin precio suman cero.
        $this->assertNotNull($ficha);
        $this->assertSame(17.5, $ficha['valueEur']);
        // 1 + 3 + 2 + 1 = 7 ejemplares. La huérfana SÍ cuenta como ejemplar
        // —MTGJSON dice que la caja la trae— aunque no se pueda valorar; los 4
        // tokens no.
        $this->assertSame(7, $ficha['cardsTotal']);
    }

    public function testLaLineaSinPrecioSeEnsenaNullYValeCero(): void
    {
        $ficha  = ($this->caso)(['file_name' => 'SneakAttack_ZNC']);
        $lineas = [];

        foreach ($ficha['cards'] as $carta) {
            $lineas[$carta['printingUuid']] = $carta;
        }

        $this->assertNull($lineas['m-2']['priceEur']);
        $this->assertSame(0.0, $lineas['m-2']['lineValue']);
        $this->assertSame(7.5, $lineas['m-1']['lineValue']);
        // El token conserva su precio mostrado, pero su línea no vale nada.
        $this->assertSame(5.0, $lineas['t-1']['priceEur']);
        $this->assertSame(0.0, $lineas['t-1']['lineValue']);
    }

    public function testLasCartasSinPrintingSalenMarcadasYContadas(): void
    {
        $ficha = ($this->caso)(['file_name' => 'SneakAttack_ZNC']);

        $this->assertSame(1, $ficha['unknownPrintings']);
        $this->assertCount(5, $ficha['cards'], 'La huérfana no puede desaparecer de la lista');
    }

    public function testLasZonasSoloTraenLasQueTienenCartas(): void
    {
        $ficha = ($this->caso)(['file_name' => 'SneakAttack_ZNC']);

        $this->assertSame(['commander', 'main', 'tokens'], array_keys($ficha['boards']));
        $this->assertCount(3, $ficha['boards']['main']);
        $this->assertCount(1, $ficha['boards']['commander']);
    }

    public function testLaSumaDeLasZonasEsLaLista(): void
    {
        $ficha = ($this->caso)(['file_name' => 'SneakAttack_ZNC']);

        $enZonas = array_sum(array_map('count', $ficha['boards']));

        $this->assertSame(count($ficha['cards']), $enZonas);
    }

    /** @return array<string, mixed> */
    private function carta(
        string $uuid,
        string $board,
        string $finish,
        int $cantidad,
        ?float $precio,
        bool $conocida = true
    ): array {
        return [
            'printingUuid' => $uuid,
            'board'        => $board,
            'finish'       => $finish,
            'count'        => $cantidad,
            'known'        => $conocida,
            'name'         => $conocida ? 'Carta ' . $uuid : null,
            'priceEur'     => $precio,
        ];
    }
}
