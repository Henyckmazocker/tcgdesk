<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Application\UseCase\ObtenerPrivacidad;
use App\Domain\Social\Nivel;
use App\Domain\Social\Seccion;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Doubles\PrivacidadFalsa;

/**
 * La mitad de lectura del M2: **un usuario sin fila devuelve los cinco
 * defaults**, que es literalmente la primera condición del hito.
 *
 * No es un caso de borde: hoy `user_privacy_settings` está vacía, así que ese es
 * el estado de absolutamente todo el mundo. Si esto devolviera un array vacío o
 * un null, el panel del M6 pintaría cinco selectores sin valor y el primer clic
 * guardaría los otros cuatro mal.
 *
 * Y los dos defectos que no se uniformizan —`value` y `wishlist` en `friends`—
 * se comprueban por separado: son la única asimetría del modelo, están razonadas
 * en el plan y son las que más caro salen si alguien las «arregla».
 */
final class ObtenerPrivacidadTest extends TestCase
{
    private const USUARIO = 7;

    private PrivacidadFalsa $privacidad;

    private ObtenerPrivacidad $obtener;

    protected function setUp(): void
    {
        $this->privacidad = new PrivacidadFalsa();
        $this->obtener    = new ObtenerPrivacidad($this->privacidad);
    }

    public function testUnUsuarioSinFilaDevuelveLosCincoDefectos(): void
    {
        $resultado = ($this->obtener)(self::USUARIO);

        self::assertSame([
            'collection' => 'everyone',
            'value'      => 'friends',
            'decks'      => 'everyone',
            'sets'       => 'everyone',
            'wishlist'   => 'friends',
        ], $resultado['privacy']);
    }

    /**
     * `value` y `wishlist` nacen en `friends` —o sea, cerrado— porque cuánto
     * dinero tienes en cartas es información patrimonial y lo que te falta es
     * por dónde te regatea un desconocido. Uniformizarlos a `everyone` es un
     * cambio de política, no una limpieza.
     */
    public function testLosDosDefectosCerradosNoSonComoLosOtrosTres(): void
    {
        $privacidad = ($this->obtener)(self::USUARIO)['privacy'];

        self::assertSame('friends', $privacidad['value']);
        self::assertSame('friends', $privacidad['wishlist']);
        self::assertSame('everyone', $privacidad['collection']);
        self::assertSame('everyone', $privacidad['decks']);
        self::assertSame('everyone', $privacidad['sets']);
    }

    public function testDevuelveSiempreLasCincoSecciones(): void
    {
        $this->privacidad->pon(self::USUARIO, Seccion::Coleccion, Nivel::Nadie);

        $privacidad = ($this->obtener)(self::USUARIO)['privacy'];

        self::assertCount(5, $privacidad);

        foreach (Seccion::cases() as $seccion) {
            self::assertArrayHasKey($seccion->value, $privacidad);
        }
    }

    /**
     * La clave es `collection`, **no** `show_collection`. El prefijo `show_` es
     * cosa del esquema, y dejarlo salir ataría el contrato del panel y del
     * perfil público al nombre físico de la columna.
     */
    public function testLasClavesSonLasDeLaSeccionYNoLasDeLaColumna(): void
    {
        $privacidad = ($this->obtener)(self::USUARIO)['privacy'];

        foreach (Seccion::cases() as $seccion) {
            self::assertArrayNotHasKey($seccion->columna(), $privacidad);
        }
    }

    /** Lo que viaja es JSON: los niveles salen en texto, no como objetos `Nivel`. */
    public function testLosNivelesSalenEnTextoYNoComoEnum(): void
    {
        $privacidad = ($this->obtener)(self::USUARIO)['privacy'];

        foreach ($privacidad as $nivel) {
            self::assertIsString($nivel);
            self::assertNotNull(Nivel::intentar($nivel));
        }
    }

    public function testConFilaDevuelveLoGuardadoYNoElDefecto(): void
    {
        $this->privacidad->todasEn(self::USUARIO, Nivel::Nadie);

        self::assertSame([
            'collection' => 'nobody',
            'value'      => 'nobody',
            'decks'      => 'nobody',
            'sets'       => 'nobody',
            'wishlist'   => 'nobody',
        ], ($this->obtener)(self::USUARIO)['privacy']);
    }

    /**
     * Leer la privacidad **no decide nada**: no pregunta por `Visibilidad` ni la
     * necesita. La regla de oro del plan mira en la otra dirección —ningún use
     * case consulta la tabla por su cuenta para decidir—, y este es el único que
     * la consulta precisamente porque no decide: devuelve lo que hay puesto.
     */
    public function testLeerNoCuestaMasDeUnaConsulta(): void
    {
        ($this->obtener)(self::USUARIO);

        self::assertSame(1, $this->privacidad->lecturas);
        self::assertSame(0, $this->privacidad->escrituras, 'Leer la privacidad no escribe la fila');
    }
}
