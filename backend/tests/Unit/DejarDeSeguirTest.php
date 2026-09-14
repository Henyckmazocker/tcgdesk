<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Application\UseCase\DejarDeSeguir;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Doubles\SeguimientosFalsos;
use Tests\Unit\Doubles\UsuariosFalsos;

/**
 * Quitar el marcador. Tres cosas, y las tres son de las que se olvidan:
 *
 *  1. **Que se puede dejar de seguir un perfil que YA SE CERRÓ.** Es la
 *     diferencia deliberada con `Seguir`: la condición de entrada —alguna
 *     sección en `everyone`— no se puede exigir para salir, o quien siguió a
 *     alguien que después lo cerró todo se queda con un marcador imposible de
 *     soltar. Este use case ni siquiera recibe el puerto de privacidad, así que
 *     no hay forma de escribir esa comprobación sin cambiarle la firma.
 *  2. **Que quita SOLO mi marcador.** La clave es asimétrica: que yo deje de
 *     seguirte no puede borrar el marcador que tú pusiste sobre mí.
 *  3. **Que es idempotente**: dejar de seguir a quien no sigues es un 200, no un
 *     404. El 404 se reserva para lo único que de verdad no existe: la persona.
 */
final class DejarDeSeguirTest extends TestCase
{
    private UsuariosFalsos $usuarios;

    private SeguimientosFalsos $seguimientos;

    private DejarDeSeguir $dejarDeSeguir;

    private int $yo;

    private int $otro;

    protected function setUp(): void
    {
        $this->usuarios     = new UsuariosFalsos();
        $this->seguimientos = new SeguimientosFalsos();

        $this->dejarDeSeguir = new DejarDeSeguir($this->seguimientos, $this->usuarios);

        $this->yo   = $this->usuarios->alta('henyckma', 'David');
        $this->otro = $this->usuarios->alta('vecina', 'La Vecina');
    }

    public function testQuitaElMarcador(): void
    {
        $this->seguimientos->siguiendo($this->yo, $this->otro);

        $resultado = ($this->dejarDeSeguir)($this->yo, ['username' => 'vecina']);

        self::assertNotNull($resultado);
        self::assertFalse($resultado['following']);
        self::assertFalse($this->seguimientos->hayMarcador($this->yo, $this->otro));
    }

    /**
     * El caso que justifica que este use case **no** reciba
     * `UserPrivacyRepositoryInterface`: la vecina cerró su perfil del todo
     * después de que la siguieras.
     */
    public function testSePuedeDejarDeSeguirUnPerfilQueYaSeCerroDelTodo(): void
    {
        $this->seguimientos->siguiendo($this->yo, $this->otro);

        // Aquí NO se monta ninguna privacidad, y esa es la prueba: este use case
        // no recibe `UserPrivacyRepositoryInterface`, así que ni con el perfil
        // entero en `nobody` hay forma de que este camino falle. Comprobar de
        // nuevo la condición de entrada exigiría cambiarle la firma.

        $resultado = ($this->dejarDeSeguir)($this->yo, ['username' => 'vecina']);

        self::assertNotNull($resultado);
        self::assertFalse($this->seguimientos->hayMarcador($this->yo, $this->otro));
    }

    /** La clave es asimétrica: soltar lo mío no toca lo tuyo. */
    public function testNoTocaElMarcadorQueLaOtraPersonaPusoSobreMi(): void
    {
        $this->seguimientos->siguiendo($this->yo, $this->otro);
        $this->seguimientos->siguiendo($this->otro, $this->yo);

        ($this->dejarDeSeguir)($this->yo, ['username' => 'vecina']);

        self::assertFalse($this->seguimientos->hayMarcador($this->yo, $this->otro));
        self::assertTrue(
            $this->seguimientos->hayMarcador($this->otro, $this->yo),
            'Que yo te deje de seguir no puede borrarte a ti de tus seguidos.'
        );
    }

    public function testDejarDeSeguirAQuienNoSiguesNoEsUnError(): void
    {
        $resultado = ($this->dejarDeSeguir)($this->yo, ['username' => 'vecina']);

        self::assertNotNull($resultado);
        self::assertFalse($resultado['following']);
        self::assertSame([], $this->seguimientos->filas);
    }

    public function testUnNombreQueNoExisteDevuelveNull(): void
    {
        self::assertNull(($this->dejarDeSeguir)($this->yo, ['username' => 'nadie-de-estos']));
    }

    public function testSinUsernameEsUn422(): void
    {
        $this->expectException(InvalidArgumentException::class);

        ($this->dejarDeSeguir)($this->yo, []);
    }

    public function testLaRespuestaNoLlevaElEmailNiElIdDeNadie(): void
    {
        $this->seguimientos->siguiendo($this->yo, $this->otro);

        $resultado = ($this->dejarDeSeguir)($this->yo, ['username' => 'vecina']);

        self::assertNotNull($resultado);
        self::assertSame(['username', 'displayName', 'avatarUrl'], array_keys($resultado['user']));
        self::assertStringNotContainsString('@', json_encode($resultado, JSON_THROW_ON_ERROR));
    }
}
