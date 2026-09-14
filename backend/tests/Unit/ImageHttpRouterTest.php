<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Infrastructure\Scryfall\ScryfallImageDownloader;
use App\Router\ImageHttpRouter;
use GuzzleHttp\Client;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Tests\Unit\Doubles\CacheDeImagenesFalsa;

/**
 * El desvío que sirve las imágenes.
 *
 * Lo que se protege aquí es, por orden de importancia:
 *
 *  1. **Que no hay directory traversal.** Es una ruta con un parámetro que acaba
 *     tocando el disco, y ese es el sitio donde se cuela un `../`. El id se
 *     valida contra la forma canónica de UUID **antes** de nada, y la ruta del
 *     fichero sale de la tabla, nunca de la URL.
 *  2. **Que la copia local se sirve cuando existe y solo entonces.** Es
 *     literalmente el `*Hecho cuando:*` del hito: sin red, el 200 tiene que
 *     seguir saliendo.
 *  3. **Que una fila sin fichero no deja un hueco permanente**, sino que se cae
 *     al CDN.
 *
 * Las cabeceras no se pueden inspeccionar bajo el SAPI de CLI, así que lo que se
 * comprueba es el código de respuesta y los bytes del cuerpo — que es lo que
 * distingue un 200 local de un 302 al CDN.
 */
final class ImageHttpRouterTest extends TestCase
{
    private const ID = '0a1b2c3d-4e5f-4a6b-8c7d-9e0f1a2b3c4d';

    private string $directorio;

    protected function setUp(): void
    {
        $this->directorio = sys_get_temp_dir() . '/tcgdesk-router-' . bin2hex(random_bytes(6));
        mkdir($this->directorio . '/images/scryfall/normal/0/a', 0775, true);

        http_response_code(200);
    }

    protected function tearDown(): void
    {
        $this->borrar($this->directorio);
    }

    private function borrar(string $ruta): void
    {
        if (is_file($ruta)) {
            @unlink($ruta);
            return;
        }

        if (!is_dir($ruta)) {
            return;
        }

        foreach (array_diff((array) scandir($ruta), ['.', '..']) as $hijo) {
            $this->borrar($ruta . '/' . $hijo);
        }

        @rmdir($ruta);
    }

    private function router(CacheDeImagenesFalsa $cache): ImageHttpRouter
    {
        return new ImageHttpRouter(
            $cache,
            // El cliente no se usa al servir: el router solo lee del disco y
            // compone la URL del CDN, que es una función pura del id.
            new ScryfallImageDownloader(new Client(), new NullLogger(), $this->directorio),
            new NullLogger()
        );
    }

    /** @return array{0: int, 1: string} código y cuerpo */
    private function pedir(CacheDeImagenesFalsa $cache, string $uri, string $metodo = 'GET'): array
    {
        ob_start();
        $this->router($cache)->handle($uri, $metodo);
        $cuerpo = (string) ob_get_clean();

        return [http_response_code(), $cuerpo];
    }

    public function testAtiendeSoloGetYHeadDeApiImages(): void
    {
        self::assertTrue(ImageHttpRouter::atiende('GET', '/api/images/' . self::ID));
        self::assertTrue(ImageHttpRouter::atiende('HEAD', '/api/images/' . self::ID . '?size=small'));

        // Cualquier escritura sigue siendo una acción POST: esta divergencia no
        // se extiende.
        self::assertFalse(ImageHttpRouter::atiende('POST', '/api/images/' . self::ID));
        self::assertFalse(ImageHttpRouter::atiende('GET', '/api/catalog/sets'));
        self::assertFalse(ImageHttpRouter::atiende('GET', '/index.php'));
    }

    public function testSirveLaCopiaLocalCuandoExiste(): void
    {
        $relativa = 'images/scryfall/normal/0/a/' . self::ID . '.jpg';
        file_put_contents($this->directorio . '/' . $relativa, 'BYTES-DE-JPEG');

        $cache         = new CacheDeImagenesFalsa();
        $cache->filas[self::ID] = ['size' => 'normal', 'localPath' => $relativa];

        [$codigo, $cuerpo] = $this->pedir($cache, '/api/images/' . self::ID);

        self::assertSame(200, $codigo);
        self::assertSame('BYTES-DE-JPEG', $cuerpo);
    }

    /** Un HEAD confirma que existe sin gastar los bytes. */
    public function testHeadNoDevuelveElCuerpo(): void
    {
        $relativa = 'images/scryfall/normal/0/a/' . self::ID . '.jpg';
        file_put_contents($this->directorio . '/' . $relativa, 'BYTES-DE-JPEG');

        $cache                  = new CacheDeImagenesFalsa();
        $cache->filas[self::ID] = ['size' => 'normal', 'localPath' => $relativa];

        [$codigo, $cuerpo] = $this->pedir($cache, '/api/images/' . self::ID, 'HEAD');

        self::assertSame(200, $codigo);
        self::assertSame('', $cuerpo);
    }

    /** Sin copia local, la URL de Scryfall: es la mitad de la regla del plan. */
    public function testRedirigeAlCdnCuandoNoHayCopiaLocal(): void
    {
        [$codigo, $cuerpo] = $this->pedir(new CacheDeImagenesFalsa(), '/api/images/' . self::ID);

        self::assertSame(302, $codigo);
        self::assertSame('', $cuerpo);
    }

    /**
     * Una fila que apunta a un fichero que ya no está NO puede dar 404: dejaría
     * un hueco permanente en la rejilla. Se cae al CDN, que es lo que había antes
     * de cachear nada.
     */
    public function testUnaFilaSinFicheroSeCaeAlCdn(): void
    {
        $cache                  = new CacheDeImagenesFalsa();
        $cache->filas[self::ID] = ['size' => 'normal', 'localPath' => 'images/scryfall/normal/0/a/borrada.jpg'];

        [$codigo] = $this->pedir($cache, '/api/images/' . self::ID);

        self::assertSame(302, $codigo);
    }

    /**
     * El test que de verdad importa de esta clase. Ninguna de estas formas puede
     * llegar al disco, y ninguna compone una ruta: se rechazan por forma antes
     * de mirar nada.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('idsMalintencionados')]
    public function testUnIdQueNoEsUnUuidSeRechazaSinTocarElDisco(string $id): void
    {
        [$codigo, $cuerpo] = $this->pedir(new CacheDeImagenesFalsa(), '/api/images/' . $id);

        self::assertContains($codigo, [400, 404], "no rechazado: {$id}");
        self::assertStringNotContainsString('BYTES', $cuerpo);
    }

    /** @return array<string, array{0: string}> */
    public static function idsMalintencionados(): array
    {
        return [
            'traversal codificado'  => ['%2e%2e%2f%2e%2e%2fetc%2fpasswd'],
            'traversal con barras'  => ['..%2F..%2F..%2Fetc%2Fpasswd'],
            'nulo'                  => ['0a1b2c3d-4e5f-4a6b-8c7d-9e0f1a2b3c4d%00.php'],
            'vacío'                 => [''],
            'no uuid'               => ['pepito'],
            'uuid con mayúscula'    => ['0A1B2C3D-4E5F-4A6B-8C7D-9E0F1A2B3C4Z'],
            'uuid corto'            => ['0a1b2c3d-4e5f-4a6b-8c7d-9e0f1a2b3c4'],
        ];
    }

    /** Las mayúsculas sí son un UUID válido: se normalizan, no se rechazan. */
    public function testUnUuidEnMayusculasSeNormaliza(): void
    {
        $relativa = 'images/scryfall/normal/0/a/' . self::ID . '.jpg';
        file_put_contents($this->directorio . '/' . $relativa, 'BYTES-DE-JPEG');

        $cache                  = new CacheDeImagenesFalsa();
        $cache->filas[self::ID] = ['size' => 'normal', 'localPath' => $relativa];

        [$codigo, $cuerpo] = $this->pedir($cache, '/api/images/' . strtoupper(self::ID));

        self::assertSame(200, $codigo);
        self::assertSame('BYTES-DE-JPEG', $cuerpo);
    }

    public function testLaUrlDelCdnSeComponeConElSharding(): void
    {
        self::assertSame(
            'https://cards.scryfall.io/normal/front/0/a/' . self::ID . '.jpg',
            ScryfallImageDownloader::url(self::ID)
        );

        self::assertSame(
            'https://cards.scryfall.io/small/front/0/a/' . self::ID . '.jpg',
            ScryfallImageDownloader::url(self::ID, 'small')
        );
    }
}
