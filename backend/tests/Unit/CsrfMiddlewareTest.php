<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Middleware\CsrfMiddleware;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * CsrfMiddleware — el guardián de las escrituras por cookie de sesión (M7).
 *
 * Lo que se prueba aquí es exactamente el *Hecho cuando* del hito, en los tres
 * caminos que tiene la app:
 *   1. sesión sin token válido  → 403 y el controller NO se ejecuta
 *   2. sesión con token válido  → pasa
 *   3. autenticación por JWT    → se salta (Capacitor no tiene cookie que proteger)
 *
 * El middleware lee `$_SESSION` directamente, así que estos tests lo montan a
 * mano: en CLI no hay sesión arrancada y el superglobal es un array normal.
 */
final class CsrfMiddlewareTest extends TestCase
{
    private const TOKEN = 'a1b2c3d4e5f60718293a4b5c6d7e8f90a1b2c3d4e5f60718293a4b5c6d7e8f90';

    /** Rastro de si el siguiente eslabón (en la práctica, el controller) llegó a correr. */
    private bool $llamado = false;

    protected function setUp(): void
    {
        $_SESSION      = [];
        $this->llamado = false;
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
    }

    private function middleware(): CsrfMiddleware
    {
        return new CsrfMiddleware(new NullLogger());
    }

    private function siguiente(): callable
    {
        return function (array $request): array {
            $this->llamado = true;
            return ['status' => 'success', 'message' => 'hecho', 'http_code' => 200];
        };
    }

    // ------------------------------------------------------------------
    // Camino 1 — sesión SIN token válido
    // ------------------------------------------------------------------

    public function testRechazaLaEscrituraPorSesionCuandoNoViajaTokenAlguno(): void
    {
        $_SESSION['csrf_token'] = self::TOKEN;
        $_SESSION['user_data']  = ['id' => 1];

        $respuesta = $this->middleware()->handle(
            ['action' => 'collection_add', 'auth_method' => 'session', 'csrf_token' => null],
            $this->siguiente()
        );

        self::assertSame('error', $respuesta['status']);
        self::assertSame(403, $respuesta['http_code']);
        self::assertFalse($this->llamado, 'El controller no debe ejecutarse sin token CSRF.');
    }

    public function testRechazaLaEscrituraCuandoElTokenNoCoincide(): void
    {
        $_SESSION['csrf_token'] = self::TOKEN;
        $_SESSION['user_data']  = ['id' => 1];

        $respuesta = $this->middleware()->handle(
            ['action' => 'collection_remove', 'auth_method' => 'session', 'csrf_token' => str_repeat('f', 64)],
            $this->siguiente()
        );

        self::assertSame(403, $respuesta['http_code']);
        self::assertFalse($this->llamado);
    }

    public function testRechazaCuandoLaSesionNoTieneTokenAunqueElClienteMandeUno(): void
    {
        // Sesión sin token emitido: nada con lo que comparar, así que no pasa.
        $respuesta = $this->middleware()->handle(
            ['action' => 'logout', 'auth_method' => 'session', 'csrf_token' => self::TOKEN],
            $this->siguiente()
        );

        self::assertSame(403, $respuesta['http_code']);
        self::assertFalse($this->llamado);
    }

    /**
     * La trampa del repo, escrita como test: `Application::run()` solo mira
     * `http_code`. Un middleware que devolviera `code` haría salir este 403 al
     * cliente convertido en 400 —es lo que le pasa a libraryVue— y la protección
     * parecería un error de validación.
     */
    public function testElRechazoViajaEnHttpCodeYNoEnCode(): void
    {
        $_SESSION['csrf_token'] = self::TOKEN;

        $respuesta = $this->middleware()->handle(
            ['action' => 'collection_add', 'auth_method' => 'session'],
            $this->siguiente()
        );

        self::assertArrayHasKey('http_code', $respuesta);
        self::assertArrayNotHasKey('code', $respuesta);
        self::assertSame(403, $respuesta['http_code']);
    }

    // ------------------------------------------------------------------
    // Camino 2 — sesión CON token válido
    // ------------------------------------------------------------------

    public function testDejaPasarLaEscrituraConElTokenDeLaSesion(): void
    {
        $_SESSION['csrf_token'] = self::TOKEN;
        $_SESSION['user_data']  = ['id' => 1];

        $respuesta = $this->middleware()->handle(
            ['action' => 'collection_add', 'auth_method' => 'session', 'csrf_token' => self::TOKEN],
            $this->siguiente()
        );

        self::assertSame('success', $respuesta['status']);
        self::assertSame(200, $respuesta['http_code']);
        self::assertTrue($this->llamado, 'Con token válido el controller sí debe ejecutarse.');
    }

    // ------------------------------------------------------------------
    // Camino 3 — JWT: se salta
    // ------------------------------------------------------------------

    public function testSeSaltaCuandoLaAutenticacionFueConJwt(): void
    {
        // Ni sesión, ni token en el payload: es exactamente lo que manda Capacitor.
        $respuesta = $this->middleware()->handle(
            ['action' => 'collection_add', 'auth_method' => 'jwt'],
            $this->siguiente()
        );

        self::assertSame('success', $respuesta['status']);
        self::assertTrue($this->llamado, 'El cliente móvil no tiene cookie que proteger: debe pasar.');
    }

    /**
     * El salto depende de `auth_method`, que pone AuthMiddleware. Si CsrfMiddleware
     * se declarase ANTES que él en routes.php, esa marca no existiría y el móvil
     * comería 403 en cada escritura: este test fija esa dependencia por escrito.
     */
    public function testSinLaMarcaDeAuthMiddlewareElCaminoEsElDeLaCookie(): void
    {
        $respuesta = $this->middleware()->handle(
            ['action' => 'collection_add'],
            $this->siguiente()
        );

        self::assertSame(403, $respuesta['http_code']);
        self::assertFalse($this->llamado);
    }
}
