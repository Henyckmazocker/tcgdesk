<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Application\UseCase\AceptarAmistad;
use App\Domain\Social\ResultadoAmistad;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Doubles\AmistadesFalsas;

/**
 * **El test del hito.** El *Hecho cuando:* del M2 del Plan - Amigos y
 * Seguimiento dice literalmente que aceptar una solicitud siendo el `requester`
 * devuelve 403, y eso es lo que fija el primer test de aquí.
 *
 * Por qué pesa tanto una comprobación de una línea: aceptar es **lo único** que
 * abre el nivel `friends` de la privacidad de otra persona. Si el solicitante
 * pudiera aceptar su propia solicitud, pedir y ser amigo serían el mismo gesto,
 * la amistad dejaría de ser recíproca y `friends` pasaría a significar
 * «cualquiera que mande dos peticiones» — que es exactamente `everyone` con más
 * pasos.
 *
 * Los tres noes se prueban por separado a propósito, porque son tres códigos
 * distintos y confundirlos tiene consecuencias: el 404 de «no es tuya» esconde
 * que la fila existe (el id es un autoincremental global y se puede recorrer),
 * mientras que el 403 se le da a quien ya sabía que existía porque la mandó él.
 */
final class AceptarAmistadTest extends TestCase
{
    private const SOLICITANTE  = 7;
    private const DESTINATARIO = 11;
    private const AJENO        = 42;

    private AmistadesFalsas $amistades;

    private AceptarAmistad $aceptar;

    private int $id;

    protected function setUp(): void
    {
        $this->amistades = new AmistadesFalsas();
        $this->aceptar   = new AceptarAmistad($this->amistades);

        $this->id = $this->amistades
            ->pide(self::SOLICITANTE, self::DESTINATARIO)
            ->idDe(self::SOLICITANTE, self::DESTINATARIO);
    }

    /** El *Hecho cuando:* del hito, literal. */
    public function testElSolicitanteNoPuedeAceptarSuPropiaSolicitud(): void
    {
        $resultado = ($this->aceptar)(self::SOLICITANTE, ['friendship_id' => $this->id]);

        self::assertSame(ResultadoAmistad::NoTeCorresponde, $resultado);
        self::assertSame(403, $resultado->codigoHttp());

        // Y lo que de verdad importa: la solicitud sigue pendiente y no se ha
        // hecho amigo de nadie.
        self::assertFalse($this->amistades->sonAmigos(self::SOLICITANTE, self::DESTINATARIO));
    }

    public function testElDestinatarioSiPuede(): void
    {
        $resultado = ($this->aceptar)(self::DESTINATARIO, ['friendship_id' => $this->id]);

        self::assertSame(ResultadoAmistad::Hecho, $resultado);
        self::assertSame(200, $resultado->codigoHttp());
        self::assertTrue($this->amistades->sonAmigos(self::SOLICITANTE, self::DESTINATARIO));
        self::assertTrue(
            $this->amistades->sonAmigos(self::DESTINATARIO, self::SOLICITANTE),
            'La amistad es simétrica: se pregunte en el orden que se pregunte.'
        );
    }

    /**
     * Un tercero que va probando números no recibe un 403 —que le confirmaría
     * que ahí hay una amistad— sino el mismo 404 que si el id no existiera.
     */
    public function testUnTerceroRecibeElMismo404QueUnIdInventado(): void
    {
        self::assertSame(
            ResultadoAmistad::NoExiste,
            ($this->aceptar)(self::AJENO, ['friendship_id' => $this->id])
        );

        self::assertSame(
            ResultadoAmistad::NoExiste,
            ($this->aceptar)(self::AJENO, ['friendship_id' => 999999])
        );
    }

    public function testAceptarDosVecesEsUn409YNoUn200(): void
    {
        ($this->aceptar)(self::DESTINATARIO, ['friendship_id' => $this->id]);

        $resultado = ($this->aceptar)(self::DESTINATARIO, ['friendship_id' => $this->id]);

        self::assertSame(ResultadoAmistad::EstadoQueNoToca, $resultado);
        self::assertSame(409, $resultado->codigoHttp());
        self::assertTrue($this->amistades->sonAmigos(self::SOLICITANTE, self::DESTINATARIO));
    }

    public function testSinFriendshipIdEsUn422(): void
    {
        $this->expectException(InvalidArgumentException::class);

        ($this->aceptar)(self::DESTINATARIO, []);
    }
}
