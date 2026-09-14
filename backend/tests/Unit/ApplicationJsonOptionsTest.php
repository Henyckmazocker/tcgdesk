<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Application;
use PHPUnit\Framework\TestCase;

/**
 * El sangrado del JSON, que desde el 2026-09-12 depende de `APP_ENV`.
 *
 * Se prueba el criterio y no la respuesta HTTP porque el único camino para
 * verlo en crudo es `{"action":"ping"}` —la única acción sin `AuthMiddleware`—
 * y para el lado `production` habría que recrear el contenedor con otro `.env`:
 * `docker compose restart` no recarga el entorno, así que un test es la única
 * forma de cubrir los dos lados sin dejar el entorno de desarrollo cambiado.
 *
 * Lo que de verdad protege este fichero es la segunda mitad: que quitar el
 * sangrado **no cambia el contrato**. La respuesta parseada tiene que ser la
 * misma en los dos entornos, byte a byte tras `json_decode`, o el #14 habría
 * cambiado respuestas en vez de adelgazarlas.
 */
final class ApplicationJsonOptionsTest extends TestCase
{
    private ?string $entornoOriginal = null;

    protected function setUp(): void
    {
        $this->entornoOriginal = isset($_ENV['APP_ENV']) ? (string) $_ENV['APP_ENV'] : null;
    }

    protected function tearDown(): void
    {
        if ($this->entornoOriginal === null) {
            unset($_ENV['APP_ENV']);

            return;
        }

        $_ENV['APP_ENV'] = $this->entornoOriginal;
    }

    public function testEnProduccionNoSeSangra(): void
    {
        $_ENV['APP_ENV'] = 'production';

        self::assertSame(0, Application::opcionesDeJson() & JSON_PRETTY_PRINT);
    }

    public function testFueraDeProduccionSeSangra(): void
    {
        $_ENV['APP_ENV'] = 'development';

        self::assertSame(JSON_PRETTY_PRINT, Application::opcionesDeJson() & JSON_PRETTY_PRINT);
    }

    /**
     * El valor por defecto es `development`, igual que en las cookies de sesión:
     * si `APP_ENV` falta, lo prudente es la salida legible, no la de producción.
     */
    public function testSinAppEnvSeSangra(): void
    {
        unset($_ENV['APP_ENV']);

        self::assertSame(JSON_PRETTY_PRINT, Application::opcionesDeJson() & JSON_PRETTY_PRINT);
    }

    /**
     * Cualquier valor que no sea exactamente `production` sangra. El criterio es
     * `!== 'production'` y no `=== 'development'` a propósito: un `staging` o un
     * `APP_ENV` mal escrito tiene que salir legible, no adelgazado en silencio.
     */
    public function testUnEntornoDesconocidoSangra(): void
    {
        $_ENV['APP_ENV'] = 'staging';

        self::assertSame(JSON_PRETTY_PRINT, Application::opcionesDeJson() & JSON_PRETTY_PRINT);
    }

    /**
     * Las otras dos banderas no dependen del entorno: si desaparecieran en
     * producción, las URL de imagen saldrían con las barras escapadas y los
     * nombres japoneses en `\uXXXX`. Eso sí sería cambiar la respuesta.
     */
    public function testElEscapadoNoDependeDelEntorno(): void
    {
        $esperadas = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;

        $_ENV['APP_ENV'] = 'production';
        self::assertSame($esperadas, Application::opcionesDeJson() & $esperadas);

        $_ENV['APP_ENV'] = 'development';
        self::assertSame($esperadas, Application::opcionesDeJson() & $esperadas);
    }

    /**
     * El único cambio admisible del #14 es el sangrado: mismos datos, menos
     * bytes.
     */
    public function testLaRespuestaParseadaEsIdenticaEnLosDosEntornos(): void
    {
        $respuesta = [
            'status' => 'success',
            'data'   => [
                'name' => 'Jace, the Mind Sculptor',
                'jp'   => '不忠の糸',
                'url'  => '/api/images/abc-123',
                'qty'  => 4,
            ],
        ];

        $_ENV['APP_ENV'] = 'production';
        $enProduccion = json_encode($respuesta, Application::opcionesDeJson());

        $_ENV['APP_ENV'] = 'development';
        $enDesarrollo = json_encode($respuesta, Application::opcionesDeJson());

        self::assertIsString($enProduccion);
        self::assertIsString($enDesarrollo);

        self::assertSame(
            json_decode($enDesarrollo, true),
            json_decode($enProduccion, true),
            'El #14 adelgaza la respuesta; si cambia lo que se parsea, cambió el contrato.'
        );

        self::assertStringNotContainsString("\n", $enProduccion);
        self::assertStringContainsString("\n", $enDesarrollo);
        self::assertLessThan(strlen($enDesarrollo), strlen($enProduccion));
    }
}
