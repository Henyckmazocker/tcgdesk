<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Cli\Commands\ImagesCacheCommand;
use App\Infrastructure\Scryfall\ScryfallImageDownloader;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Tests\Unit\Doubles\CacheDeImagenesFalsa;

/**
 * Lo que protege este test es el `*Hecho cuando:*` del hito, en lo que se puede
 * comprobar sin red:
 *
 *  - que la **cola** es la colección menos lo ya cacheado, y por tanto que
 *    relanzar el comando **no vuelve a pedir nada** (idempotencia);
 *  - que la imagen se guarda **byte a byte como llegó**, que es la forma
 *    verificable de «sin recortes ni overlays» de [[TCGDesk/Fuentes de Datos]];
 *  - que una carta que falla **no aborta el resto** ni se anota en la tabla —una
 *    fila sin fichero saldría de la cola y nadie volvería a intentarlo—;
 *  - y que el **código de salida** dice la verdad, que es lo único que mira el
 *    cron.
 *
 * El CDN se sustituye por un MockHandler de Guzzle: el comando escribe en un
 * directorio temporal y nadie sale a internet.
 */
final class ImagesCacheCommandTest extends TestCase
{
    private string $directorio;

    /** @var list<string> URLs que se pidieron, en orden */
    private array $pedidas = [];

    private string $jpeg;

    protected function setUp(): void
    {
        $this->jpeg = base64_decode(
            '/9j/4AAQSkZJRgABAQEAYABgAAD/2wBDAAgGBgcGBQgHBwcJCQgKDBQNDAsLDBkSEw8UHRof'
            . 'Hh0aHBwgJC4nICIsIxwcKDcpLDAxNDQ0Hyc5PTgyPC4zNDL/wAALCAABAAEBAREA/8QAFAAB'
            . 'AAAAAAAAAAAAAAAAAAAACf/EABQQAQAAAAAAAAAAAAAAAAAAAAD/2gAIAQEAAD8AKp//2Q==',
            true
        ) ?: '';

        $this->directorio = sys_get_temp_dir() . '/tcgdesk-img-' . bin2hex(random_bytes(6));
        mkdir($this->directorio, 0775, true);
        $this->pedidas = [];
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

    /**
     * @param list<Response|\Throwable> $respuestas
     */
    private function descargador(array $respuestas): ScryfallImageDownloader
    {
        $mock = new MockHandler($respuestas);
        $pila = HandlerStack::create($mock);

        $pila->push(function (callable $siguiente) {
            return function ($peticion, array $opciones) use ($siguiente) {
                $this->pedidas[] = (string) $peticion->getUri();
                return $siguiente($peticion, $opciones);
            };
        });

        return new ScryfallImageDownloader(
            new Client(['handler' => $pila]),
            new NullLogger(),
            $this->directorio
        );
    }

    /** @return array{0: int, 1: string} código de salida y salida por pantalla */
    private function ejecutar(ImagesCacheCommand $comando, array $args = []): array
    {
        ob_start();
        // --rps alto para que el test no duerma: el ritmo real lo prueba
        // RateLimiterTest, aquí lo que se prueba es la cola.
        $codigo = $comando->run(array_merge(['--rps=10'], $args));
        $salida = (string) ob_get_clean();

        return [$codigo, $salida];
    }

    private function jpegOk(): Response
    {
        return new Response(200, ['Content-Type' => 'image/jpeg'], $this->jpeg);
    }

    public function testBajaLasPendientesYLasAnotaConRutaRelativa(): void
    {
        $cache = new CacheDeImagenesFalsa([
            '00000000-0000-4000-8000-000000000001',
            '0a1b2c3d-4e5f-4a6b-8c7d-9e0f1a2b3c4d',
        ]);

        $comando = new ImagesCacheCommand(
            $cache,
            $this->descargador([$this->jpegOk(), $this->jpegOk()]),
            new NullLogger()
        );

        [$codigo] = $this->ejecutar($comando);

        self::assertSame(0, $codigo);
        self::assertCount(2, $cache->filas);

        $fila = $cache->filas['0a1b2c3d-4e5f-4a6b-8c7d-9e0f1a2b3c4d'];

        // Ruta RELATIVA a storage/, como manda la columna VARCHAR(512).
        self::assertSame(
            'images/scryfall/normal/0/a/0a1b2c3d-4e5f-4a6b-8c7d-9e0f1a2b3c4d.jpg',
            $fila['localPath']
        );
        self::assertSame('normal', $fila['size']);
        self::assertFileExists($this->directorio . '/' . $fila['localPath']);
    }

    /**
     * «Sin recortes ni overlays» es una restricción de licencia, no una
     * preferencia estética. La forma de comprobarla en un test es que el fichero
     * en disco sea **byte a byte** lo que mandó el CDN: si algún día alguien
     * mete un redimensionado «para ahorrar espacio», esto se pone rojo.
     */
    public function testLaImagenSeGuardaByteAByteComoLlego(): void
    {
        $cache = new CacheDeImagenesFalsa(['00000000-0000-4000-8000-000000000001']);

        $comando = new ImagesCacheCommand($cache, $this->descargador([$this->jpegOk()]), new NullLogger());

        $this->ejecutar($comando);

        $ruta = $this->directorio . '/' . $cache->filas['00000000-0000-4000-8000-000000000001']['localPath'];

        self::assertSame($this->jpeg, file_get_contents($ruta));
        self::assertSame([1, 1], array_slice((array) getimagesize($ruta), 0, 2));
    }

    /** La URL es la del CDN compuesta desde el id: cero llamadas a la API. */
    public function testComponeLaUrlDelCdnDesdeElIdSinLlamarALaApi(): void
    {
        $cache = new CacheDeImagenesFalsa(['0a1b2c3d-4e5f-4a6b-8c7d-9e0f1a2b3c4d']);

        $comando = new ImagesCacheCommand($cache, $this->descargador([$this->jpegOk()]), new NullLogger());

        $this->ejecutar($comando);

        self::assertSame(
            ['https://cards.scryfall.io/normal/front/0/a/0a1b2c3d-4e5f-4a6b-8c7d-9e0f1a2b3c4d.jpg'],
            $this->pedidas
        );
    }

    /**
     * La prueba de la idempotencia, que es la que pide el enunciado: la segunda
     * pasada no pide **nada**. Y no porque el comando lo compruebe, sino porque
     * la cola es «estar en la colección y no estar en la tabla».
     */
    public function testRelanzarloNoVuelveADescargarNada(): void
    {
        $cache = new CacheDeImagenesFalsa(['00000000-0000-4000-8000-000000000001']);

        $primero = new ImagesCacheCommand($cache, $this->descargador([$this->jpegOk()]), new NullLogger());
        [$codigo] = $this->ejecutar($primero);
        self::assertSame(0, $codigo);
        self::assertCount(1, $this->pedidas);

        $this->pedidas = [];

        // Sin respuestas en la cola del mock: si pidiera algo, el test reventaría.
        $segundo = new ImagesCacheCommand($cache, $this->descargador([]), new NullLogger());
        [$codigo, $salida] = $this->ejecutar($segundo);

        self::assertSame(0, $codigo);
        self::assertSame([], $this->pedidas);
        self::assertStringContainsString('Nada que descargar', $salida);
    }

    /**
     * Una carta que falla no puede llevarse por delante a las demás **ni
     * anotarse en la tabla**: una fila sin fichero saldría de la cola y nadie
     * volvería a intentarlo nunca.
     */
    public function testUnaCartaQueFallaNoAbortaElRestoNiSeAnota(): void
    {
        $cache = new CacheDeImagenesFalsa([
            '00000000-0000-4000-8000-000000000001',
            '00000000-0000-4000-8000-000000000002',
            '00000000-0000-4000-8000-000000000003',
        ]);

        $comando = new ImagesCacheCommand(
            $cache,
            $this->descargador([$this->jpegOk(), new Response(404, [], 'no'), $this->jpegOk()]),
            new NullLogger()
        );

        [$codigo] = $this->ejecutar($comando);

        // Hubo progreso: el cron no tiene nada que hacer, y la carta fallida
        // sigue pendiente para mañana.
        self::assertSame(0, $codigo);
        self::assertArrayNotHasKey('00000000-0000-4000-8000-000000000002', $cache->filas);
        self::assertCount(2, $cache->filas);
        self::assertSame(['00000000-0000-4000-8000-000000000002'], $cache->pendientes());
    }

    /** Un 200 con algo que no es una imagen tampoco se anota ni se deja en disco. */
    public function testUnDoscientosQueNoEsUnaImagenNoSeAnota(): void
    {
        $cache = new CacheDeImagenesFalsa(['00000000-0000-4000-8000-000000000001']);

        $comando = new ImagesCacheCommand(
            $cache,
            $this->descargador([new Response(200, ['Content-Type' => 'text/html'], '<html>error</html>')]),
            new NullLogger()
        );

        [$codigo] = $this->ejecutar($comando);

        self::assertSame(1, $codigo);
        self::assertSame([], $cache->filas);
        self::assertFileDoesNotExist(
            $this->directorio . '/images/scryfall/normal/0/0/00000000-0000-4000-8000-000000000001.jpg'
        );
    }

    /**
     * Que no se baje NADA habiendo trabajo pendiente es lo accionable —la red
     * caída, el CDN bloqueado, storage/ sin permisos— y es lo único que tiñe de
     * rojo la ejecución para el cron.
     */
    public function testSiNoSeBajaNadaHabiendoPendientesDevuelveUno(): void
    {
        $cache = new CacheDeImagenesFalsa(['00000000-0000-4000-8000-000000000001']);

        $comando = new ImagesCacheCommand(
            $cache,
            $this->descargador([new Response(503, [], '')]),
            new NullLogger()
        );

        [$codigo] = $this->ejecutar($comando);

        self::assertSame(1, $codigo);
    }

    /** Sin nada pendiente el comando es un no-op con éxito, no un fallo. */
    public function testColeccionVaciaDevuelveCero(): void
    {
        $comando = new ImagesCacheCommand(
            new CacheDeImagenesFalsa([]),
            $this->descargador([]),
            new NullLogger()
        );

        [$codigo, $salida] = $this->ejecutar($comando);

        self::assertSame(0, $codigo);
        self::assertStringContainsString('Nada que descargar', $salida);
    }

    /**
     * `mtg_printing.scryfall_id` es NULL en algunos printings. Una carta sin id
     * no tiene imagen que componer: no puede reventar el comando, pero tampoco
     * puede pasar en silencio y dejar un hueco sin explicación.
     */
    public function testLasCartasSinScryfallIdSeAvisanYNoRompenNada(): void
    {
        $cache = new CacheDeImagenesFalsa(['00000000-0000-4000-8000-000000000001'], sinScryfallId: 3);

        $comando = new ImagesCacheCommand($cache, $this->descargador([$this->jpegOk()]), new NullLogger());

        [$codigo, $salida] = $this->ejecutar($comando);

        self::assertSame(0, $codigo);
        self::assertStringContainsString('3 impresión(es) de la colección no tienen scryfall_id', $salida);
    }

    public function testElLimiteCortaLaPasada(): void
    {
        $cache = new CacheDeImagenesFalsa([
            '00000000-0000-4000-8000-000000000001',
            '00000000-0000-4000-8000-000000000002',
        ]);

        $comando = new ImagesCacheCommand($cache, $this->descargador([$this->jpegOk()]), new NullLogger());

        [$codigo] = $this->ejecutar($comando, ['--limit=1']);

        self::assertSame(0, $codigo);
        self::assertCount(1, $cache->filas);
        self::assertCount(1, $cache->pendientes());
    }

    /**
     * El ritmo NO es un parámetro de rendimiento que se pueda subir: es la
     * condición de uso de Scryfall. Pedir 50 req/s es un error de uso, no una
     * optimización.
     */
    public function testNoDejaSubirElRitmoPorEncimaDeDiez(): void
    {
        $comando = new ImagesCacheCommand(
            new CacheDeImagenesFalsa(['00000000-0000-4000-8000-000000000001']),
            $this->descargador([]),
            new NullLogger()
        );

        ob_start();
        $codigo = $comando->run(['--rps=50']);
        ob_end_clean();

        self::assertSame(1, $codigo);
        self::assertSame([], $this->pedidas);
    }

    public function testUnTamanoQueNoEstaEnElEnumSeRechaza(): void
    {
        $comando = new ImagesCacheCommand(
            new CacheDeImagenesFalsa(['00000000-0000-4000-8000-000000000001']),
            $this->descargador([]),
            new NullLogger()
        );

        ob_start();
        $codigo = $comando->run(['--size=art_crop']);
        ob_end_clean();

        // `art_crop` es literalmente el recorte que la licencia prohíbe guardar,
        // y el ENUM de la tabla tampoco lo admite.
        self::assertSame(1, $codigo);
        self::assertSame([], $this->pedidas);
    }

    public function testLaAyudaNoDescargaNada(): void
    {
        $comando = new ImagesCacheCommand(
            new CacheDeImagenesFalsa(['00000000-0000-4000-8000-000000000001']),
            $this->descargador([]),
            new NullLogger()
        );

        [$codigo, $salida] = $this->ejecutar($comando, ['--help']);

        self::assertSame(0, $codigo);
        self::assertStringContainsString('images:cache', $salida);
        self::assertSame([], $this->pedidas);
    }
}
