<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Catalog\PreconSearchCriteria;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/**
 * Los filtros de `/api/catalog/decks`, que vienen de una query string.
 *
 * Lo que se protege: que **nada de lo que llegue por la URL pueda romper la
 * lista**. Es una ruta pública sin sesión, así que cualquiera teclea lo que
 * quiera en `limit`, `type` o `cursor`; la política es acotar y normalizar, no
 * devolver errores, igual que en `SearchCriteria`.
 */
final class PreconSearchCriteriaTest extends TestCase
{
    public function testPeticionVaciaDaLosValoresPorDefecto(): void
    {
        $criterios = PreconSearchCriteria::desdePeticion([]);

        $this->assertNull($criterios->q);
        $this->assertNull($criterios->deckType);
        $this->assertNull($criterios->setCode);
        $this->assertNull($criterios->cursor);
        $this->assertSame(60, $criterios->limit);
        $this->assertFalse($criterios->tieneTexto());
    }

    public function testLeeLosTresFiltrosDeLaQueryString(): void
    {
        $criterios = PreconSearchCriteria::desdePeticion([
            'type' => 'Commander Deck',
            'set'  => 'znc',
            'q'    => '  Sneak Attack  ',
        ]);

        $this->assertSame('Commander Deck', $criterios->deckType);
        // El código de edición se mayúscula; el tipo NO, porque es texto de
        // MTGJSON y se compara tal cual llega de la tabla.
        $this->assertSame('ZNC', $criterios->setCode);
        $this->assertSame('Sneak Attack', $criterios->q);
        $this->assertTrue($criterios->tieneTexto());
    }

    public function testElLimiteSeAcotaEnVezDeRechazarse(): void
    {
        $this->assertSame(100, PreconSearchCriteria::desdePeticion(['limit' => 5000])->limit);
        $this->assertSame(1, PreconSearchCriteria::desdePeticion(['limit' => 0])->limit);
        $this->assertSame(1, PreconSearchCriteria::desdePeticion(['limit' => -12])->limit);
        $this->assertSame(60, PreconSearchCriteria::desdePeticion(['limit' => 'muchos'])->limit);
    }

    public function testUnFiltroVacioEsComoNoMandarlo(): void
    {
        $criterios = PreconSearchCriteria::desdePeticion(['type' => '   ', 'set' => '', 'q' => '']);

        $this->assertNull($criterios->deckType);
        $this->assertNull($criterios->setCode);
        $this->assertNull($criterios->q);
    }

    public function testUnFiltroQueNoEsEscalarNoRompe(): void
    {
        // `?type[]=x` llega como array. No es un caso teórico: es lo que manda
        // un navegador con una query string tecleada a mano.
        $criterios = PreconSearchCriteria::desdePeticion(['type' => ['Commander Deck'], 'q' => ['x']]);

        $this->assertNull($criterios->deckType);
        $this->assertNull($criterios->q);
    }

    public function testConCursorConservaElRestoDeFiltros(): void
    {
        $criterios = PreconSearchCriteria::desdePeticion(['type' => 'Commander Deck', 'limit' => 10]);
        $siguiente = $criterios->conCursor('abc');

        $this->assertSame('abc', $siguiente->cursor);
        $this->assertSame('Commander Deck', $siguiente->deckType);
        $this->assertSame(10, $siguiente->limit);
    }

    public function testPlayableSoloSeActivaSiLoPidenYSobreviveAlCursor(): void
    {
        // El defecto es «todo», el mismo comportamiento que tenía la ruta antes
        // de existir este filtro: la vista pide `playable=1` explícitamente.
        $this->assertFalse(PreconSearchCriteria::desdePeticion([])->soloJugables);
        $this->assertFalse(PreconSearchCriteria::desdePeticion(['playable' => '0'])->soloJugables);
        $this->assertTrue(PreconSearchCriteria::desdePeticion(['playable' => '1'])->soloJugables);

        // Y sigue puesto en la página siguiente del scroll: si se perdiera con
        // el cursor, la segunda tirada metería los 739 Secret Lair Drop.
        $this->assertTrue(
            PreconSearchCriteria::desdePeticion(['playable' => '1'])->conCursor('abc')->soloJugables
        );
    }

    public function testElConstructorSiRechazaUnLimiteImposible(): void
    {
        // `desdePeticion()` acota, pero construirlo a mano con una barbaridad es
        // un error de programación y tiene que sonar.
        $this->expectException(InvalidArgumentException::class);

        new PreconSearchCriteria(limit: 1000);
    }
}
