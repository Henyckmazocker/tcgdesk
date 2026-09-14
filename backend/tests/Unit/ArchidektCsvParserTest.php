<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Import\ParsedRow;
use App\Infrastructure\Import\ArchidektCsvParser;
use PHPUnit\Framework\TestCase;

/**
 * Archidekt es el más parecido al vocabulario común, y justo por eso es el que
 * más cerca está de confundirse con ManaBox: la única columna que de verdad los
 * separa es **`Finish` frente a `Foil`**.
 *
 * Lo que se protege aquí es eso, las dos grafías de la edición (`Set Code` y
 * `Edition Code`), y **la regla de oro del plan**: lo que no case va a
 * conflicto, nunca al valor por defecto.
 */
final class ArchidektCsvParserTest extends TestCase
{
    private ArchidektCsvParser $parser;

    protected function setUp(): void
    {
        $this->parser = new ArchidektCsvParser();
    }

    private function csvDeEjemplo(): string
    {
        $contenido = file_get_contents(__DIR__ . '/../fixtures/archidekt.csv');

        self::assertIsString($contenido, 'No se pudo leer tests/fixtures/archidekt.csv');

        return $contenido;
    }

    /** @return ParsedRow[] */
    private function filasDelEjemplo(): array
    {
        return $this->parser->parse($this->csvDeEjemplo());
    }

    // --------------------------------------------------------------- detección

    public function testSeReconocePorLaCabeceraYNoPorElNombreDelFichero(): void
    {
        $contenido = $this->csvDeEjemplo();

        self::assertTrue($this->parser->supports('archidekt-collection.csv', $contenido));
        self::assertTrue($this->parser->supports('', $contenido));
        self::assertTrue($this->parser->supports('cartas.csv', $contenido));
    }

    public function testTambienReconoceLaExportacionLargaConEditionCode(): void
    {
        // Las exportaciones largas de Archidekt traen `Edition Code` y columnas
        // que no tiene nadie más (`Multiverse Id`, `MTGO Card ID`).
        $csv = "Quantity,Name,Finish,Condition,Date Added,Language,Purchase Price,Tags,"
             . "Edition Name,Edition Code,Multiverse Id,Scryfall ID,MTGO Card ID,Collector Number\n"
             . "2,Sol Ring,Foil,NM,2026-09-01,es,12.99,commander,Commander 2021,c21,522374,"
             . "bd3d4b4b-cf31-4f89-8140-9650edb03c7b,89123,263\n";

        self::assertTrue($this->parser->supports('archidekt.csv', $csv));

        $fila = $this->parser->parse($csv)[0];

        self::assertSame('C21', $fila->setCode, 'Edition Code vale igual que Set Code');
        self::assertSame('foil', $fila->finish);
        self::assertSame('Spanish', $fila->language);
        self::assertSame(2, $fila->quantity);
    }

    public function testNoReconoceElCsvDeLasOtrasDosApps(): void
    {
        // ManaBox llama `Foil` a la columna de acabado: sin `Finish` no es de
        // Archidekt, aunque comparta `Quantity`, `Name` y `Scryfall ID`.
        $manabox = "Name,Set Code,Set Name,Collector Number,Foil,Rarity,Quantity,"
                 . "ManaBox ID,Scryfall ID\n";
        $moxfield = "\"Count\",\"Tradelist Count\",\"Name\",\"Edition\",\"Condition\",\"Foil\"\n";

        self::assertFalse($this->parser->supports('manabox.csv', $manabox));
        self::assertFalse($this->parser->supports('moxfield.csv', $moxfield));
        self::assertFalse($this->parser->supports('lista.txt', "4 Lightning Bolt (M10) 146\n"));
        self::assertFalse($this->parser->supports('vacio.csv', ''));
    }

    // ------------------------------------------------------------------ parseo

    public function testUnCsvRealSeParseaAFilasConIdiomaYCondicion(): void
    {
        $filas = $this->filasDelEjemplo();

        self::assertCount(5, $filas);

        $primera = $filas[0];
        self::assertSame('ce711943-c1a1-43a0-8b89-8d169cfb8e06', $primera->scryfallId);
        self::assertSame('Lightning Bolt', $primera->name);
        self::assertSame('LEA', $primera->setCode);
        self::assertSame('161', $primera->collectorNumber);
        self::assertSame('normal', $primera->finish);
        self::assertSame('English', $primera->language);
        self::assertSame('NM', $primera->condition);
        self::assertSame(4, $primera->quantity);
        self::assertSame(2, $primera->sourceLine);
        self::assertTrue($primera->esValida());
    }

    public function testFinishEsTextoDeTresValoresYNoUnBooleano(): void
    {
        // 'etched' tiene precio propio en mtg_price_current.
        $filas = $this->filasDelEjemplo();

        self::assertSame('normal', $filas[0]->finish);
        self::assertSame('foil', $filas[1]->finish);
        self::assertSame('etched', $filas[2]->finish);
    }

    public function testLosCodigosDeCondicionDeArchidektSeReconocen(): void
    {
        $filas = $this->filasDelEjemplo();

        self::assertSame('NM', $filas[0]->condition);
        self::assertSame('LP', $filas[1]->condition);
        self::assertSame('PL', $filas[2]->condition, 'HP = Heavily Played');
        self::assertSame('PO', $filas[3]->condition, 'D = Damaged');
    }

    public function testElIdiomaSaleEnLaFormaLargaDeMtgjson(): void
    {
        $filas = $this->filasDelEjemplo();

        self::assertSame('Spanish', $filas[1]->language);
        self::assertSame('Japanese', $filas[2]->language);
        self::assertSame('German', $filas[3]->language);
        self::assertSame('Italian', $filas[4]->language);
    }

    public function testLosNombresConComaYConDobleCaraSobrevivenAlCsv(): void
    {
        $filas = $this->filasDelEjemplo();

        self::assertSame('Rin and Seri, Inseparable', $filas[1]->name);
        self::assertSame('Delver of Secrets // Insectile Aberration', $filas[3]->name);
    }

    public function testCadaFilaLlevaSuNumeroDeLineaFisica(): void
    {
        $lineas = array_map(
            static fn (ParsedRow $fila): int => $fila->sourceLine,
            $this->filasDelEjemplo()
        );

        self::assertSame([2, 3, 4, 5, 6], $lineas);
    }

    // ------------------------------------- la regla de oro: nada de NM por defecto

    public function testUnAcabadoQueNoCasaInvalidaLaFilaYNoCaeANormal(): void
    {
        $fila = $this->filasDelEjemplo()[4];

        self::assertFalse($fila->esValida());
        self::assertArrayHasKey('finish', $fila->errores);
        self::assertSame('Galaxy Foil', $fila->finish, 'el valor crudo se conserva');
        self::assertNotSame('normal', $fila->finish);
        self::assertSame('Sheoldred, the Apocalypse', $fila->name, 'la fila no se descarta');
        self::assertSame('NM', $fila->condition, 'lo que sí casa se normaliza igual');
    }

    public function testUnaCondicionQueNoCasaTampocoCaeANm(): void
    {
        $csv = "Quantity,Name,Finish,Condition,Language,Set Code,Collector Number,Scryfall ID\n"
             . "1,Sol Ring,Normal,casi nuevo,en,c21,263,bd3d4b4b-cf31-4f89-8140-9650edb03c7b\n";

        $fila = $this->parser->parse($csv)[0];

        self::assertFalse($fila->esValida());
        self::assertSame('casi nuevo', $fila->condition);
        self::assertNotSame('NM', $fila->condition);
    }

    public function testUnFicheroSoloConCabeceraNoDaFilas(): void
    {
        self::assertSame([], $this->parser->parse("Quantity,Name,Finish,Scryfall ID\n"));
        self::assertSame([], $this->parser->parse(''));
    }
}
