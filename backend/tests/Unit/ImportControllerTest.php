<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Application\UseCase\ApplyImport;
use App\Application\UseCase\ResolveCards;
use App\Controllers\ImportController;
use App\Domain\Import\AssumedPrintingChooser;
use App\Domain\Import\CardResolver;
use App\Domain\Import\NameNormalizer;
use App\Domain\Import\ParserRegistry;
use App\Infrastructure\Import\ArchidektCsvParser;
use App\Infrastructure\Import\ManaBoxCsvParser;
use App\Infrastructure\Import\MoxfieldCsvParser;
use App\Infrastructure\Import\PlainTextParser;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Tests\Unit\Doubles\CatalogoDeResolucionFalso;
use Tests\Unit\Doubles\ColeccionFalsa;
use Tests\Unit\Doubles\MazosFalsos;
use Tests\Unit\Doubles\TransaccionesFalsas;
use Tests\Unit\Doubles\ImpresionesAsumidasFalsas;

/**
 * Las dos acciones de la importación, con el registro de parsers **de verdad**:
 * lo que se prueba aquí es la detección automática de formato y el alto
 * obligatorio, y con un registro de mentira no se probaría ninguna de las dos.
 *
 * El test que da nombre al hito es `testLaPrevisualizacionNoEscribeNadaEnLaColeccion`:
 * el plan lo pide literalmente en su sección de verificación, y es la garantía de
 * que una importación de 3.000 cartas no se aplica sola.
 */
final class ImportControllerTest extends TestCase
{
    private CatalogoDeResolucionFalso $catalogo;
    private ImpresionesAsumidasFalsas $impresiones;
    private ColeccionFalsa $coleccion;
    private MazosFalsos $mazos;
    private ImportController $controller;

    protected function setUp(): void
    {
        $this->catalogo    = new CatalogoDeResolucionFalso();
        $this->impresiones = new ImpresionesAsumidasFalsas();
        $this->coleccion   = new ColeccionFalsa();
        $this->mazos       = new MazosFalsos($this->coleccion);

        $this->controller = new ImportController(
            new ParserRegistry([
                new ManaBoxCsvParser(),
                new MoxfieldCsvParser(),
                new ArchidektCsvParser(),
                new PlainTextParser(),
            ]),
            new ResolveCards(
                new CardResolver($this->catalogo, new NameNormalizer()),
                new AssumedPrintingChooser($this->impresiones)
            ),
            new ApplyImport($this->coleccion, $this->mazos, new TransaccionesFalsas()),
            new NullLogger()
        );
    }

    /** @param array<string, mixed> $datos */
    private function peticion(string $accion, array $datos): array
    {
        // La forma que arma ActionRouter: el payload entero bajo `data` y el
        // user_id puesto por AuthMiddleware, jamás por el cuerpo.
        return ['action' => $accion, 'user_id' => 7, 'data' => $datos];
    }

    private function fixture(string $nombre): string
    {
        return file_get_contents(__DIR__ . '/../fixtures/' . $nombre);
    }

    public function testDetectaElFormatoSinQueElUsuarioLoElija(): void
    {
        $respuesta = $this->controller->preview($this->peticion('import_preview', [
            'content' => $this->fixture('manabox.csv'),
        ]));

        self::assertSame('success', $respuesta['status']);
        self::assertSame('manabox', $respuesta['data']['format']);
        self::assertGreaterThan(0, $respuesta['data']['total']);
    }

    /**
     * **El alto obligatorio.** `import_preview` lee el fichero, lo resuelve y no
     * toca la colección: nunca se escribe sin pasar por la previsualización.
     */
    public function testLaPrevisualizacionNoEscribeNadaEnLaColeccion(): void
    {
        $this->controller->preview($this->peticion('import_preview', [
            'content' => $this->fixture('manabox.csv'),
        ]));

        $this->controller->preview($this->peticion('import_preview', [
            'content' => $this->fixture('plaintext.txt'),
        ]));

        self::assertSame([], $this->coleccion->filas, 'import_preview no puede escribir una sola fila.');
    }

    /** La respuesta trae los dos montones y el resumen del contrato del plan. */
    public function testLaRespuestaTieneLaFormaDelContrato(): void
    {
        $this->catalogo->conCarta('Sol Ring', 'o-sol', printingUuid: 'uuid-sol', setCode: 'C21');

        $respuesta = $this->controller->preview($this->peticion('import_preview', [
            'content' => "2 Sol Ring\n3 Carta Que No Existe\n",
        ]));

        $datos = $respuesta['data'];

        self::assertSame('plaintext', $datos['format']);
        self::assertSame(2, $datos['total']);
        self::assertCount(1, $datos['resolved']);
        self::assertCount(1, $datos['conflicts']);
        self::assertSame(
            ['resolvedCount', 'conflictCount', 'assumedCount', 'totalQuantity'],
            array_keys($datos['summary'])
        );
        self::assertSame(2, $datos['summary']['totalQuantity']);
    }

    /**
     * Un fichero de otro juego produce un **error claro**, no una importación a
     * medias. Y va con la lista de formatos, para que la vista pueda ofrecer
     * elegirlo a mano: una lista de cartas sin ninguna cantidad no se autodetecta
     * a propósito, y forzar el formato es su única salida.
     */
    public function testUnFicheroQueNadieReclamaDaErrorClaroYLosFormatosConocidos(): void
    {
        $respuesta = $this->controller->preview($this->peticion('import_preview', [
            'content' => '{"jugadores": [{"nombre": "Ash", "pokemon": "Pikachu"}]}',
        ]));

        self::assertSame('error', $respuesta['status']);
        self::assertSame(422, $respuesta['http_code']);
        self::assertContains('manabox', $respuesta['data']['formats']);
        self::assertContains('plaintext', $respuesta['data']['formats']);
    }

    public function testSePuedeForzarElFormatoCuandoLaDeteccionNoLlega(): void
    {
        $this->catalogo->conCarta('Sol Ring', 'o-sol', printingUuid: 'uuid-sol', setCode: 'C21');

        // Sin cantidad en ninguna línea, PlainTextParser no lo reclama solo.
        $suelto = "Sol Ring\nCounterspell\n";

        self::assertSame(
            'error',
            $this->controller->preview($this->peticion('import_preview', ['content' => $suelto]))['status']
        );

        $forzado = $this->controller->preview($this->peticion('import_preview', [
            'content' => $suelto,
            'format'  => 'plaintext',
        ]));

        self::assertSame('success', $forzado['status']);
        self::assertSame(2, $forzado['data']['total']);
    }

    public function testUnFormatoInventadoNoRevientaElServidor(): void
    {
        $respuesta = $this->controller->preview($this->peticion('import_preview', [
            'content' => "4 Sol Ring\n",
            'format'  => 'deckbox',
        ]));

        self::assertSame(422, $respuesta['http_code']);
    }

    public function testElContenidoVacioSeRechazaAntesDeTocarNingunParser(): void
    {
        $respuesta = $this->controller->preview($this->peticion('import_preview', ['content' => "   \n  "]));

        self::assertSame(422, $respuesta['http_code']);
    }

    /**
     * `import_apply` escribe SOLO lo que le mandan, que es lo que el usuario
     * confirmó: ni reparsea el fichero ni vuelve a resolver nada.
     */
    public function testAplicarEscribeLoConfirmadoYDevuelveElResumen(): void
    {
        $respuesta = $this->controller->apply($this->peticion('import_apply', [
            'rows' => [
                ['printingUuid' => 'uuid-sol', 'finish' => 'foil', 'language' => 'Spanish', 'condition' => 'EX', 'quantity' => 3],
            ],
        ]));

        self::assertSame('success', $respuesta['status']);
        self::assertSame(1, $respuesta['data']['inserted']);
        self::assertSame(0, $respuesta['data']['updated']);
        self::assertSame(3, $respuesta['data']['totalQuantity']);
        self::assertCount(1, $this->coleccion->filas);
    }

    public function testAplicarSinFilasResponde422YNoEscribeNada(): void
    {
        $respuesta = $this->controller->apply($this->peticion('import_apply', ['rows' => []]));

        self::assertSame(422, $respuesta['http_code']);
        self::assertSame([], $this->coleccion->filas);
    }
}
