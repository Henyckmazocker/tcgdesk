<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Application\UseCase\GuardarPrivacidad;
use App\Application\UseCase\ObtenerPrivacidad;
use App\Domain\Social\Nivel;
use App\Domain\Social\Seccion;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Doubles\PrivacidadFalsa;

/**
 * La mitad de escritura del M2, y el test que da nombre al hito: **cambiar una
 * sección deja las otras cuatro intactas**.
 *
 * Es la segunda condición del *Hecho cuando:* y el fallo que más caro sale de
 * todo el plan, porque es silencioso: si escribir `value` devolviera
 * `collection` a su defecto, la colección de quien la había cerrado se
 * publicaría otra vez sin un error, sin un aviso y sin que la respuesta
 * pareciera rara. Solo se vería abriendo el perfil en incógnito.
 *
 * Lo demás que se prueba aquí, por orden de daño:
 *
 *  1. **Que un nivel inventado revienta** en vez de caer al defecto — y el
 *     defecto de `collection` es `everyone`, o sea, publicar.
 *  2. **Que `friends` se puede guardar.** Que hoy no se lo conceda a nadie es
 *     cosa de resolverlo (`Visibilidad`), no de almacenarlo; el Plan - Amigos
 *     tiene que encontrarse esas filas ya escritas.
 *  3. **Que una petición sin secciones no escribe nada** y lo dice con un 422,
 *     en vez de responder 200 a un cliente que cree haber guardado algo.
 */
final class GuardarPrivacidadTest extends TestCase
{
    private const USUARIO = 7;

    private PrivacidadFalsa $privacidad;

    private GuardarPrivacidad $guardar;

    protected function setUp(): void
    {
        $this->privacidad = new PrivacidadFalsa();
        $this->guardar    = new GuardarPrivacidad($this->privacidad);
    }

    /**
     * El *Hecho cuando:* del hito, literal: sin fila previa, cambiar `value`
     * crea el estado con los otros cuatro en su defecto y no en cualquier cosa.
     */
    public function testCambiarUnaSeccionDejaLasOtrasCuatroEnSuDefecto(): void
    {
        $resultado = ($this->guardar)(self::USUARIO, ['value' => 'nobody']);

        self::assertSame([
            'collection' => 'everyone',
            'value'      => 'nobody',
            'decks'      => 'everyone',
            'sets'       => 'everyone',
            'wishlist'   => 'friends',
        ], $resultado['privacy']);
    }

    /**
     * Y la versión que de verdad duele: con una sección YA cerrada a mano,
     * tocar otra no puede reabrirla. Es la diferencia entre una edición parcial
     * y un formulario que reenvía cinco campos.
     */
    public function testCambiarUnaSeccionNoReabreOtraQueEstabaCerrada(): void
    {
        ($this->guardar)(self::USUARIO, ['collection' => 'nobody']);

        $resultado = ($this->guardar)(self::USUARIO, ['value' => 'everyone']);

        self::assertSame('nobody', $resultado['privacy']['collection'], 'Tocar `value` ha reabierto la colección');
        self::assertSame('everyone', $resultado['privacy']['value']);
        self::assertSame('friends', $resultado['privacy']['wishlist'], 'Y tampoco ha movido el otro defecto cerrado');
    }

    /** Varias secciones en una sola petición: el panel puede mandar dos. */
    public function testSePuedenCambiarVariasDeGolpe(): void
    {
        $resultado = ($this->guardar)(self::USUARIO, ['decks' => 'nobody', 'sets' => 'nobody']);

        self::assertSame('nobody', $resultado['privacy']['decks']);
        self::assertSame('nobody', $resultado['privacy']['sets']);
        self::assertSame('everyone', $resultado['privacy']['collection']);
    }

    /** @return iterable<string, array{Seccion}> */
    public static function secciones(): iterable
    {
        foreach (Seccion::cases() as $seccion) {
            yield $seccion->value => [$seccion];
        }
    }

    /**
     * La matriz va por las cinco a propósito: la única diferencia entre ellas es
     * el defecto, y una sección que se quedara fuera del bucle de escritura solo
     * se vería probándolas todas.
     */
    #[DataProvider('secciones')]
    public function testCadaSeccionSeEscribeSolaYSinTocarLasDemas(Seccion $seccion): void
    {
        $resultado = ($this->guardar)(self::USUARIO, [$seccion->value => 'nobody'])['privacy'];

        self::assertSame('nobody', $resultado[$seccion->value]);

        foreach (Seccion::cases() as $otra) {
            if ($otra !== $seccion) {
                self::assertSame(
                    $otra->nivelPorDefecto()->value,
                    $resultado[$otra->value],
                    "Cambiar {$seccion->value} ha movido {$otra->value}"
                );
            }
        }
    }

    /**
     * Guardar `friends` es válido **hoy**, aunque `Visibilidad` no se lo conceda
     * a nadie mientras no exista `friendships`. Lo inerte es resolverlo, no
     * almacenarlo: si esto lo rechazara, el Plan - Amigos tendría que migrar los
     * datos que nadie pudo escribir.
     */
    public function testFriendsSeGuardaAunqueHoyNoSeLoConcedaANadie(): void
    {
        $resultado = ($this->guardar)(self::USUARIO, ['collection' => 'friends']);

        self::assertSame('friends', $resultado['privacy']['collection']);
    }

    /**
     * El panel manda `collection`, pero copiar el nombre de la columna al
     * formulario es el error más fácil de cometer y `Seccion::desde()` ya sabe
     * traducirlo. Un alias que se ignorase en silencio sería un selector que no
     * guarda nada.
     */
    public function testTambienSeAceptaElNombreDeLaColumna(): void
    {
        $resultado = ($this->guardar)(self::USUARIO, ['show_value' => 'nobody']);

        self::assertSame('nobody', $resultado['privacy']['value']);
    }

    public function testUnNivelInventadoRevientaEnVezDeCaerAlDefecto(): void
    {
        $this->expectException(InvalidArgumentException::class);

        // Si esto cayera al defecto, `collection` quedaría en `everyone`: el
        // cliente pediría cerrar y el servidor publicaría.
        ($this->guardar)(self::USUARIO, ['collection' => 'solo-yo']);
    }

    public function testUnNivelInventadoNoEscribeNada(): void
    {
        try {
            ($this->guardar)(self::USUARIO, ['collection' => 'nobody', 'value' => 'solo-yo']);
        } catch (InvalidArgumentException) {
            // Esperado: lo que se comprueba es que no ha quedado media escritura.
        }

        self::assertSame(0, $this->privacidad->escrituras);
    }

    public function testUnaPeticionSinNingunaSeccionEsUn422YNoUn200(): void
    {
        $this->expectException(InvalidArgumentException::class);

        ($this->guardar)(self::USUARIO, []);
    }

    /**
     * `data` trae también `action` y `csrf_token` —los mete `ActionRouter`—, y
     * ni son secciones ni pueden provocar un error. Lo que no puede pasar es que
     * cuenten como cambio: una petición que solo traiga eso no cambia nada.
     */
    public function testElCsrfYLaAccionNoCuentanComoSecciones(): void
    {
        $this->expectException(InvalidArgumentException::class);

        ($this->guardar)(self::USUARIO, ['action' => 'privacy_set', 'csrf_token' => 'abc']);
    }

    public function testUnaPeticionSinSeccionesNoEscribeLaFila(): void
    {
        try {
            ($this->guardar)(self::USUARIO, ['csrf_token' => 'abc']);
        } catch (InvalidArgumentException) {
            // Esperado.
        }

        self::assertSame(0, $this->privacidad->escrituras, 'Una petición vacía ha creado la fila');
    }

    /** Lo guardado es lo que lee después `privacy_get`, que es de lo que se queja el usuario si no. */
    public function testLoGuardadoEsLoQueLeeDespuesPrivacyGet(): void
    {
        ($this->guardar)(self::USUARIO, ['wishlist' => 'nobody']);

        $leido = (new ObtenerPrivacidad($this->privacidad))(self::USUARIO)['privacy'];

        self::assertSame('nobody', $leido['wishlist']);
        self::assertSame('everyone', $leido['collection']);
    }

    /** La privacidad de otro usuario no se toca ni por accidente. */
    public function testEscribirLaPropiaNoMueveLaDeOtro(): void
    {
        $this->privacidad->pon(99, Seccion::Coleccion, Nivel::Nadie);

        ($this->guardar)(self::USUARIO, ['collection' => 'everyone']);

        self::assertSame(Nivel::Nadie, $this->privacidad->niveles[99]['collection']);
    }

    /** La respuesta trae siempre las cinco, no solo lo que se cambió. */
    public function testLaRespuestaTraeLasCincoSecciones(): void
    {
        $resultado = ($this->guardar)(self::USUARIO, ['value' => 'nobody'])['privacy'];

        self::assertCount(5, $resultado);

        foreach (Seccion::cases() as $seccion) {
            self::assertArrayHasKey($seccion->value, $resultado);
            self::assertIsString($resultado[$seccion->value]);
        }
    }
}
