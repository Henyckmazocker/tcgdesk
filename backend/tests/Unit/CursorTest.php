<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Catalog\Cursor;
use PHPUnit\Framework\TestCase;

/**
 * El cursor viaja en una query string que el usuario ve, copia y pega. Lo que se
 * protege aquí es que **nada de lo que llegue mal pueda romper el catálogo**: un
 * cursor caducado, recortado al copiarlo o manipulado a mano tiene que degradar a
 * "empieza por el principio", nunca a un error.
 */
final class CursorTest extends TestCase
{
    public function testLoCodificadoSeRecuperaIgual(): void
    {
        $cursor = Cursor::porColumna('Sol Ring', 'uuid-123');

        self::assertSame(
            ['v' => 'Sol Ring', 'u' => 'uuid-123'],
            Cursor::decodificar($cursor)
        );
    }

    public function testEsSeguroEnUnaUrl(): void
    {
        // base64url: sin '+', sin '/' y sin '=' de relleno, para que no haya que
        // escaparlo ni se rompa al copiarlo de la barra de direcciones.
        $cursor = Cursor::porColumna('Æther Vial // Ünicode ñ', 'uuid-123');

        self::assertSame($cursor, rawurlencode($cursor));
        self::assertStringNotContainsString('=', $cursor);
    }

    public function testSobreviveALosNombresConAcentosYBarras(): void
    {
        $nombre = 'Delver of Secrets // Insectile Aberration';
        $datos  = Cursor::decodificar(Cursor::porColumna($nombre, 'u1'));

        self::assertSame($nombre, $datos['v']);
    }

    public function testUnCursorAusenteNoEsUnError(): void
    {
        self::assertNull(Cursor::decodificar(null));
        self::assertNull(Cursor::decodificar(''));
    }

    public function testUnCursorCorruptoSeIgnoraEnVezDeReventar(): void
    {
        self::assertNull(Cursor::decodificar('esto-no-es-base64-valido-!!!'));
        self::assertNull(Cursor::decodificar(base64_encode('no soy json')));
        self::assertNull(Cursor::decodificar(base64_encode('"soy json pero no un array"')));
    }

    public function testElCursorDePosicionGuardaElOffset(): void
    {
        self::assertSame(120, Cursor::offsetDe(Cursor::decodificar(Cursor::porPosicion(120))));
    }

    /**
     * Un offset negativo o de otro tipo daría un `LIMIT ... OFFSET -5`, que es un
     * error de sintaxis de MySQL. Se normaliza a 0.
     */
    public function testUnOffsetInvalidoCaeACero(): void
    {
        self::assertSame(0, Cursor::offsetDe(Cursor::decodificar(Cursor::porPosicion(-5))));
        self::assertSame(0, Cursor::offsetDe(['o' => 'muchos']));
        self::assertSame(0, Cursor::offsetDe(['v' => 'x', 'u' => 'y']));
        self::assertSame(0, Cursor::offsetDe(null));
    }

    public function testElValorNuloSeConserva(): void
    {
        // release_date puede ser NULL y el cursor tiene que poder representarlo.
        self::assertNull(Cursor::decodificar(Cursor::porColumna(null, 'u1'))['v']);
    }
}
