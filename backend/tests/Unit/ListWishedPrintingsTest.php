<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Application\UseCase\AddToCollection;
use App\Application\UseCase\ListWishedPrintings;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Doubles\ColeccionFalsa;

/**
 * `ListWishedPrintings` — la lista que rellena el corazón del catálogo.
 *
 * El use case es fino y lo que hay que probar de él no es la consulta, que es
 * del repositorio, sino **el conjunto que sale**, que es lo que el corazón va a
 * creerse:
 *
 *  - solo deseos: una carta que tienes NO se pinta con el corazón relleno, o el
 *    botón diría que la quieres cuando lo que pasa es que ya la tienes;
 *  - solo tuyos: el `user_id` va al `WHERE` y no al payload, igual que en el
 *    resto de la colección;
 *  - **sin repetir**: la misma impresión puede estar deseada en varias líneas
 *    —dos acabados, dos idiomas, dos estados— y el corazón es uno solo.
 */
final class ListWishedPrintingsTest extends TestCase
{
    private ColeccionFalsa $repo;

    private AddToCollection $anadir;

    private ListWishedPrintings $deseadas;

    protected function setUp(): void
    {
        $this->repo     = new ColeccionFalsa();
        $this->anadir   = new AddToCollection($this->repo);
        $this->deseadas = new ListWishedPrintings($this->repo);
    }

    public function testDevuelveLasImpresionesDeseadasYNoLasQueYaTienes(): void
    {
        $this->linea('uuid-deseado', true);
        $this->linea('uuid-en-coleccion', false);

        $resultado = ($this->deseadas)(1);

        self::assertSame(['uuid-deseado'], $resultado['uuids']);
        self::assertSame(1, $resultado['count']);
    }

    public function testLaMismaImpresionDeseadaEnVariasLineasSaleUnaSolaVez(): void
    {
        // Tres líneas distintas dentro de `uq_item` —acabado, idioma y estado—
        // y una sola carta para el usuario: el corazón no se pinta tres veces.
        $this->linea('uuid-x', true);
        $this->linea('uuid-x', true, ['finish' => 'foil']);
        $this->linea('uuid-x', true, ['language' => 'Japanese']);

        self::assertSame(['uuid-x'], ($this->deseadas)(1)['uuids']);
    }

    public function testNoDevuelveLosDeseosDeOtroUsuario(): void
    {
        $this->linea('uuid-mio', true);
        $this->linea('uuid-ajeno', true, [], 2);

        self::assertSame(['uuid-mio'], ($this->deseadas)(1)['uuids']);
        self::assertSame(['uuid-ajeno'], ($this->deseadas)(2)['uuids']);
    }

    public function testSinNingunDeseoDevuelveUnaListaVaciaYNoNull(): void
    {
        $this->linea('uuid-en-coleccion', false);

        // Lista vacía y no `null`: el cliente construye un Set con esto y un
        // null lo reventaría justo en la pantalla donde no hay nada que pintar.
        self::assertSame([], ($this->deseadas)(1)['uuids']);
        self::assertSame(0, ($this->deseadas)(1)['count']);
    }

    /** @param array<string, mixed> $extra */
    private function linea(string $uuid, bool $deseo, array $extra = [], int $userId = 1): void
    {
        ($this->anadir)($userId, [
            'printing_uuid' => $uuid,
            'quantity'      => 1,
            'is_wishlist'   => $deseo,
        ] + $extra);
    }
}
