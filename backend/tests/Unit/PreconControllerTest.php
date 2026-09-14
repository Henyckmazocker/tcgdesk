<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Application\UseCase\ImportPreconToCollection;
use App\Controllers\PreconController;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Tests\Unit\Doubles\ColeccionFalsa;
use Tests\Unit\Doubles\MazosFalsos;
use Tests\Unit\Doubles\PreconesFalsos;
use Tests\Unit\Doubles\TransaccionesFalsas;

/**
 * El contrato de `precon_add_to_collection`, por donde entra de verdad: el
 * request que arma `ActionRouter` —payload bajo `data`, `user_id` puesto por
 * `AuthMiddleware`— y la respuesta con su `http_code`.
 *
 * Existe por lo mismo que `DeckControllerTest`: el login es el de Google y no se
 * puede hacer con `curl` en local. Lo que `curl` sí prueba, y se hizo, es el
 * **401 sin sesión**, que ocurre antes de llegar aquí.
 *
 * Y prueba una cosa más, que es del plan y no del código: **el mensaje dice en
 * voz alta lo que se asumió**. Si algún día alguien lo recorta, esto se pone
 * rojo.
 */
final class PreconControllerTest extends TestCase
{
    private const USUARIO = 7;

    private PreconesFalsos $precons;

    private PreconController $controller;

    protected function setUp(): void
    {
        $this->precons = new PreconesFalsos();
        $coleccion     = new ColeccionFalsa();

        $this->controller = new PreconController(
            new ImportPreconToCollection(
                $this->precons,
                $coleccion,
                new MazosFalsos($coleccion),
                new TransaccionesFalsas()
            ),
            new NullLogger()
        );
    }

    private function caja(): void
    {
        $this->precons->precons[] = [
            'fileName'    => 'SneakAttack_ZNC',
            'name'        => 'Sneak Attack',
            'deckType'    => 'Commander Deck',
            'setCode'     => 'ZNC',
            'setName'     => 'Zendikar Rising Commander',
            'releaseDate' => '2020-09-25',
            'cardCount'   => 100,
        ];

        $this->precons->cartas['SneakAttack_ZNC'] = [
            [
                'printingUuid' => 'uuid-yuriko',
                'board'        => 'commander',
                'finish'       => 'foil',
                'count'        => 1,
                'known'        => true,
            ],
            [
                'printingUuid' => 'uuid-isla',
                'board'        => 'main',
                'finish'       => 'normal',
                'count'        => 30,
                'known'        => true,
            ],
        ];
    }

    /** @param array<string, mixed> $datos */
    private function peticion(array $datos): array
    {
        return ['action' => 'precon_add_to_collection', 'user_id' => self::USUARIO, 'data' => $datos];
    }

    public function testUnClicDevuelveElMazoYLosEjemplares(): void
    {
        $this->caja();

        $respuesta = $this->controller->addToCollection($this->peticion(['file_name' => 'SneakAttack_ZNC']));

        self::assertSame('success', $respuesta['status']);
        self::assertSame(200, $respuesta['http_code']);
        self::assertSame(31, $respuesta['data']['totalQuantity']);
        self::assertSame('built', $respuesta['data']['deck']['status']);
    }

    /**
     * El defecto que el plan obliga a decir en voz alta: MTGJSON no publica ni
     * idioma ni condición, así que la respuesta lo lleva escrito.
     */
    public function testElMensajeDiceEnVozAltaLoQueSeAsume(): void
    {
        $this->caja();

        $respuesta = $this->controller->addToCollection($this->peticion(['file_name' => 'SneakAttack_ZNC']));

        self::assertStringContainsString('English', $respuesta['message']);
        self::assertStringContainsString('NM', $respuesta['message']);
        self::assertSame(['language' => 'English', 'condition' => 'NM'], $respuesta['data']['assumed']);
    }

    public function testUnPreconInexistenteEs404(): void
    {
        $respuesta = $this->controller->addToCollection($this->peticion(['file_name' => 'NoExiste_XXX']));

        self::assertSame('error', $respuesta['status']);
        self::assertSame(404, $respuesta['http_code']);
    }

    public function testUnEstadoDeMazoInventadoEs422(): void
    {
        $this->caja();

        $respuesta = $this->controller->addToCollection($this->peticion([
            'file_name'   => 'SneakAttack_ZNC',
            'deck_status' => 'a medio montar',
        ]));

        self::assertSame(422, $respuesta['http_code']);
    }

    /**
     * M7: la misma acción con la bandera del lote. El mazo sale `building` y el
     * mensaje **dice dónde han caído las cartas**: decir «en tu colección» a
     * quien pulsó «lo quiero» sería mentir sobre la única diferencia que hay.
     */
    public function testLaCajaADeseosRespondeBuildingYLoDiceEnElMensaje(): void
    {
        $this->caja();

        $respuesta = $this->controller->addToCollection($this->peticion([
            'file_name'   => 'SneakAttack_ZNC',
            'is_wishlist' => true,
        ]));

        self::assertSame(200, $respuesta['http_code']);
        self::assertTrue($respuesta['data']['isWishlist']);
        self::assertSame('building', $respuesta['data']['deck']['status']);
        self::assertStringContainsString('lista de deseos', $respuesta['message']);
        self::assertStringNotContainsString('en tu colección', $respuesta['message']);
    }
}
