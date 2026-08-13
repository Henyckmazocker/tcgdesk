<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Catalog\SearchCriteria;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/**
 * Los filtros llegan de una query string que cualquiera puede teclear a mano, y
 * dos de ellos —`sort` y `rarity`— acaban interpolados en el SQL porque `ORDER
 * BY` no admite marcador de posición en PDO. Que aquí solo salgan valores de
 * lista blanca es lo que hace que esa interpolación sea segura.
 */
final class SearchCriteriaTest extends TestCase
{
    public function testLosValoresPorDefectoSonRazonables(): void
    {
        $c = SearchCriteria::desdePeticion([]);

        self::assertNull($c->q);
        self::assertSame('relevance', $c->sort);
        self::assertSame(60, $c->limit);
        self::assertFalse($c->tieneTexto());
        self::assertFalse($c->filtraPorPrecio());
    }

    public function testElLimiteSeAcotaEnVezDeRechazarse(): void
    {
        // Viene de la red: se recorta, no se revienta la petición.
        self::assertSame(SearchCriteria::LIMITE_MAXIMO, SearchCriteria::desdePeticion(['limit' => 9999])->limit);
        self::assertSame(1, SearchCriteria::desdePeticion(['limit' => 0])->limit);
        self::assertSame(1, SearchCriteria::desdePeticion(['limit' => -50])->limit);
        self::assertSame(25, SearchCriteria::desdePeticion(['limit' => '25'])->limit);
    }

    public function testUnaRarezaDesconocidaSeIgnora(): void
    {
        self::assertNull(SearchCriteria::desdePeticion(['rarity' => 'legendaria'])->rarity);
        self::assertNull(SearchCriteria::desdePeticion(['rarity' => "rare'; DROP TABLE"])->rarity);
        self::assertSame('mythic', SearchCriteria::desdePeticion(['rarity' => 'MYTHIC'])->rarity);
    }

    public function testUnOrdenDesconocidoCaeARelevancia(): void
    {
        self::assertSame('relevance', SearchCriteria::desdePeticion(['sort' => 'edhrec'])->sort);
        self::assertSame('relevance', SearchCriteria::desdePeticion(['sort' => 'name; DELETE FROM users'])->sort);
        self::assertSame('price_desc', SearchCriteria::desdePeticion(['sort' => 'PRICE_DESC'])->sort);
    }

    public function testElConstructorSiRechazaLosValoresFueraDeListaBlanca(): void
    {
        // desdePeticion() normaliza porque viene de la red; el constructor es
        // estricto porque lo llama el código y un valor raro ahí es un bug.
        $this->expectException(InvalidArgumentException::class);
        new SearchCriteria(sort: 'lo_que_sea');
    }

    public function testLaRarezaInvalidaEnElConstructorTambienRevienta(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new SearchCriteria(rarity: 'legendaria');
    }

    public function testElLimiteFueraDeRangoEnElConstructorRevienta(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new SearchCriteria(limit: 500);
    }

    public function testLosColoresSeNormalizanADistintasLetrasEnMayuscula(): void
    {
        self::assertSame('WU', SearchCriteria::desdePeticion(['colors' => 'wu'])->colors);
        self::assertSame('WU', SearchCriteria::desdePeticion(['colors' => 'WUW'])->colors);
        // Lo que no es un color de Magic se cae.
        self::assertSame('WU', SearchCriteria::desdePeticion(['colors' => 'W-U;X'])->colors);
        self::assertNull(SearchCriteria::desdePeticion(['colors' => 'xyz'])->colors);
    }

    public function testLosColoresSeSirvenComoLetrasSueltasParaElFiltro(): void
    {
        self::assertSame(['W', 'U', 'B'], SearchCriteria::desdePeticion(['colors' => 'wub'])->coloresComoLetras());
        self::assertSame([], SearchCriteria::desdePeticion([])->coloresComoLetras());
    }

    public function testElCodigoDeEdicionSeNormalizaAMayusculas(): void
    {
        self::assertSame('C21', SearchCriteria::desdePeticion(['set' => 'c21'])->setCode);
    }

    public function testElTextoEnBlancoCuentaComoAusente(): void
    {
        self::assertFalse(SearchCriteria::desdePeticion(['q' => '   '])->tieneTexto());
        self::assertTrue(SearchCriteria::desdePeticion(['q' => ' sol ring '])->tieneTexto());
        // Y se recorta, para que no llegue con espacios al comparador de exactitud.
        self::assertSame('sol ring', SearchCriteria::desdePeticion(['q' => ' sol ring '])->q);
    }

    public function testElRangoDePrecioSoloCuentaConNumeros(): void
    {
        $c = SearchCriteria::desdePeticion(['price_min' => '2.5', 'price_max' => 'diez']);

        self::assertSame(2.5, $c->priceMin);
        self::assertNull($c->priceMax);
        self::assertTrue($c->filtraPorPrecio());
    }
}
