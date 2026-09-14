<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Application\UseCase\PedirAmistad;
use App\Application\UseCase\RechazarAmistad;
use App\Domain\Social\ResultadoAmistad;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Doubles\AmistadesFalsas;
use Tests\Unit\Doubles\UsuariosFalsos;

/**
 * Rechazar **borra la fila**, y el test que más importa aquí es el último: que
 * después de rechazar **se puede volver a pedir**.
 *
 * Eso es lo que el `DELETE` garantiza y lo que un estado `rejected` habría
 * roto sin que nadie lo decidiera: con el `UNIQUE (user_low, user_high)`
 * simétrico, una fila rechazada bloquearía toda solicitud futura entre esas dos
 * personas **para siempre**. Bloquear está explícitamente fuera del alcance del
 * plan, así que habría entrado por la puerta de atrás.
 *
 * Y quién puede: solo el destinatario, igual que aceptar.
 */
final class RechazarAmistadTest extends TestCase
{
    private const SOLICITANTE  = 7;
    private const DESTINATARIO = 11;

    private AmistadesFalsas $amistades;

    private RechazarAmistad $rechazar;

    private int $id;

    protected function setUp(): void
    {
        $this->amistades = new AmistadesFalsas();
        $this->rechazar  = new RechazarAmistad($this->amistades);

        $this->id = $this->amistades
            ->pide(self::SOLICITANTE, self::DESTINATARIO)
            ->idDe(self::SOLICITANTE, self::DESTINATARIO);
    }

    public function testElDestinatarioRechazaYLaFilaDesaparece(): void
    {
        self::assertSame(
            ResultadoAmistad::Hecho,
            ($this->rechazar)(self::DESTINATARIO, ['friendship_id' => $this->id])
        );

        self::assertNull($this->amistades->buscar($this->id));
    }

    public function testElSolicitanteNoPuedeRechazarLaSuya(): void
    {
        $resultado = ($this->rechazar)(self::SOLICITANTE, ['friendship_id' => $this->id]);

        self::assertSame(ResultadoAmistad::NoTeCorresponde, $resultado);
        self::assertSame(403, $resultado->codigoHttp());
        self::assertNotNull($this->amistades->buscar($this->id));
    }

    /**
     * Una amistad ya aceptada no se «rechaza»: se deshace, y eso lo puede hacer
     * cualquiera de los dos. Se responde 409 y **no se borra**, porque las dos
     * operaciones acaban en el mismo `DELETE` y confundirlas dejaría que un
     * rechazo mal dirigido rompiera una amistad de verdad.
     */
    public function testRechazarUnaAmistadYaAceptadaEsUn409YNoLaBorra(): void
    {
        $this->amistades->acepta(self::SOLICITANTE, self::DESTINATARIO);

        $resultado = ($this->rechazar)(self::DESTINATARIO, ['friendship_id' => $this->id]);

        self::assertSame(ResultadoAmistad::EstadoQueNoToca, $resultado);
        self::assertSame(409, $resultado->codigoHttp());
        self::assertTrue($this->amistades->sonAmigos(self::SOLICITANTE, self::DESTINATARIO));
    }

    /**
     * **El motivo entero de que no haya `rejected`.** Con un estado terminal en
     * la tabla, este segundo `crearSolicitud()` chocaría contra el `UNIQUE`
     * simétrico y devolvería un 409 para siempre: un bloqueo permanente que
     * nadie habría decidido.
     */
    public function testDespuesDeRechazarSePuedeVolverAPedir(): void
    {
        // El ciclo entero por el camino real, con los ids que reparte el doble
        // de usuarios: pedir, rechazar, y volver a pedir a la misma persona.
        $usuarios  = new UsuariosFalsos();
        $solicita  = $usuarios->alta('solicitante');
        $usuarios->alta('destinataria');

        $amistades = new AmistadesFalsas();
        $pedir     = new PedirAmistad($amistades, $usuarios);
        $rechazar  = new RechazarAmistad($amistades);

        $primera = $pedir($solicita, ['username' => 'destinataria']);
        self::assertNotNull($primera);

        self::assertSame(
            ResultadoAmistad::Hecho,
            $rechazar($usuarios->findByUsername('destinataria')->id, ['friendship_id' => $primera['friendshipId']])
        );

        // Y aquí es donde un estado `rejected` habría devuelto un 1062 para
        // siempre: la fila seguiría ahí y el UNIQUE simétrico la protegería.
        $segunda = $pedir($solicita, ['username' => 'destinataria']);

        self::assertNotNull($segunda);
        self::assertNotSame($primera['friendshipId'], $segunda['friendshipId']);
        self::assertSame('pending', $segunda['status']);
    }
}
