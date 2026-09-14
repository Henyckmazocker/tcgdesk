<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Import\ParsedRow;
use App\Infrastructure\Import\MoxfieldCsvParser;
use PHPUnit\Framework\TestCase;

/**
 * Moxfield es el CSV que más se aparta del vocabulario común: la cantidad se
 * llama `Count`, la edición `Edition`, y **`Foil` viene vacío** cuando la carta
 * no es foil.
 *
 * Lo que se protege aquí son esas tres trampas, el BOM del fichero exportado
 * desde el navegador en Windows, y **la regla de oro del plan**: lo que no case
 * va a conflicto, nunca a `NM` por defecto.
 */
final class MoxfieldCsvParserTest extends TestCase
{
    private MoxfieldCsvParser $parser;

    protected function setUp(): void
    {
        $this->parser = new MoxfieldCsvParser();
    }

    private function csvDeEjemplo(): string
    {
        $contenido = file_get_contents(__DIR__ . '/../fixtures/moxfield.csv');

        self::assertIsString($contenido, 'No se pudo leer tests/fixtures/moxfield.csv');

        return $contenido;
    }

    /** @return ParsedRow[] */
    private function filasDelEjemplo(): array
    {
        return $this->parser->parse($this->csvDeEjemplo());
    }

    // --------------------------------------------------------------- detección

    public function testElBomNoImpideReconocerElFormato(): void
    {
        $contenido = $this->csvDeEjemplo();

        self::assertStringStartsWith("\xEF\xBB\xBF", $contenido);
        self::assertTrue($this->parser->supports('moxfield_haves.csv', $contenido));
        // Manda la cabecera, no el nombre del fichero.
        self::assertTrue($this->parser->supports('', $contenido));
        self::assertTrue($this->parser->supports('cartas.csv', $contenido));
    }

    public function testNoReconoceElCsvDeLasOtrasDosApps(): void
    {
        $manabox = "Name,Set Code,Set Name,Collector Number,Foil,Rarity,Quantity,"
                 . "ManaBox ID,Scryfall ID\n";
        $archidekt = "Quantity,Name,Finish,Condition,Language,Set Code,Set Name,"
                   . "Collector Number,Scryfall ID\n";

        self::assertFalse($this->parser->supports('manabox.csv', $manabox));
        self::assertFalse($this->parser->supports('archidekt.csv', $archidekt));
        self::assertFalse($this->parser->supports('lista.txt', "4 Lightning Bolt (M10) 146\n"));
        self::assertFalse($this->parser->supports('vacio.csv', ''));
    }

    // ------------------------------------------------------------------ parseo

    public function testUnCsvRealSeParseaAFilasConIdiomaYCondicion(): void
    {
        $filas = $this->filasDelEjemplo();

        self::assertCount(6, $filas);

        $primera = $filas[0];
        self::assertSame('ce711943-c1a1-43a0-8b89-8d169cfb8e06', $primera->scryfallId);
        self::assertSame('Lightning Bolt', $primera->name);
        self::assertSame('LEA', $primera->setCode, 'Edition trae el código, en minúsculas');
        self::assertSame('161', $primera->collectorNumber);
        self::assertSame('normal', $primera->finish);
        self::assertSame('English', $primera->language);
        self::assertSame('NM', $primera->condition);
        self::assertSame(4, $primera->quantity);
        self::assertSame(2, $primera->sourceLine);
        self::assertTrue($primera->esValida());
    }

    public function testLaCantidadSaleDeCountYNoDeTradelistCount(): void
    {
        // `Tradelist Count` es cuántas ofreces en cambio, no cuántas tienes:
        // confundirlas duplicaría —o vaciaría— la colección entera.
        $filas = $this->filasDelEjemplo();

        self::assertSame('0', $filas[0]->crudo['tradelist count']);
        self::assertSame(4, $filas[0]->quantity);
        self::assertSame(2, $filas[2]->quantity);
    }

    public function testUnFoilVacioEsNormalYNoUnError(): void
    {
        // Moxfield no escribe 'normal': deja la celda vacía. Una celda vacía es
        // «el fichero no afirma nada» y cae al valor por defecto.
        $filas = $this->filasDelEjemplo();

        self::assertSame('', $filas[0]->crudo['foil']);
        self::assertSame('normal', $filas[0]->finish);
        self::assertTrue($filas[0]->esValida());

        self::assertSame('foil', $filas[1]->finish);
        self::assertSame('etched', $filas[2]->finish);
    }

    public function testLosCodigosDeCondicionDeMoxfieldSeReconocen(): void
    {
        // M, NM, LP, MP, HP, D son los suyos; el `D` de Damaged es el que
        // faltaba en el enum antes de este hito.
        $filas = $this->filasDelEjemplo();

        self::assertSame('NM', $filas[0]->condition);
        self::assertSame('LP', $filas[1]->condition);
        self::assertSame('M', $filas[2]->condition);
        self::assertSame('PO', $filas[3]->condition, 'D = Damaged');
        self::assertSame('PL', $filas[4]->condition, 'MP = Moderately Played');
    }

    public function testElIdiomaSaleEnLaFormaLargaDeMtgjson(): void
    {
        $filas = $this->filasDelEjemplo();

        self::assertSame('Spanish', $filas[1]->language);
        self::assertSame('Japanese', $filas[2]->language);
        self::assertSame('German', $filas[4]->language);
        self::assertSame('Italian', $filas[5]->language);
    }

    public function testLosNombresConComaYConDobleCaraSobrevivenAlCsv(): void
    {
        $filas = $this->filasDelEjemplo();

        self::assertSame('Rin and Seri, Inseparable', $filas[3]->name);
        self::assertSame('Delver of Secrets // Insectile Aberration', $filas[4]->name);
    }

    public function testCadaFilaLlevaSuNumeroDeLineaFisica(): void
    {
        $lineas = array_map(
            static fn (ParsedRow $fila): int => $fila->sourceLine,
            $this->filasDelEjemplo()
        );

        self::assertSame([2, 3, 4, 5, 6, 7], $lineas);
    }

    // ------------------------------------- la regla de oro: nada de NM por defecto

    public function testUnaCondicionQueNoCasaInvalidaLaFilaYNoCaeANm(): void
    {
        // La última fila del ejemplo trae la condición editada a mano.
        $fila = $this->filasDelEjemplo()[5];

        self::assertFalse($fila->esValida());
        self::assertArrayHasKey('condition', $fila->errores);
        self::assertSame('Excelente', $fila->condition, 'el valor crudo se conserva');
        self::assertNotSame('NM', $fila->condition);
        self::assertSame('Sheoldred, the Apocalypse', $fila->name, 'la fila no se descarta');
    }

    public function testLaColumnaEditionAusenteNoInvalidaLaFila(): void
    {
        // Columna ausente = el fichero no afirma nada. Solo un valor presente
        // que no case manda la fila a conflicto.
        $csv = "Count,Tradelist Count,Name,Collector Number,Scryfall ID\n"
             . "2,0,Lightning Bolt,161,ce711943-c1a1-43a0-8b89-8d169cfb8e06\n";

        $filas = $this->parser->parse($csv);

        self::assertCount(1, $filas);
        self::assertTrue($filas[0]->esValida());
        self::assertNull($filas[0]->setCode);
        self::assertSame('NM', $filas[0]->condition);
        self::assertSame('English', $filas[0]->language);
        self::assertSame('normal', $filas[0]->finish);
    }

    public function testUnFicheroSoloConCabeceraNoDaFilas(): void
    {
        self::assertSame([], $this->parser->parse("Count,Tradelist Count,Name,Edition\n"));
        self::assertSame([], $this->parser->parse(''));
    }
}
