<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Import\ParsedRow;
use App\Infrastructure\Import\ManaBoxCsvParser;
use PHPUnit\Framework\TestCase;

/**
 * ManaBox es el escaneador de móvil del que salen las importaciones grandes de
 * verdad, y su CSV trae **las cuatro cosas que se olvidan**: BOM de Windows,
 * `Foil` como texto de tres valores, la condición en `snake_case` y el idioma
 * en código corto.
 *
 * Lo que se protege aquí es eso y **la regla de oro del plan**: lo que no case
 * va a conflicto, nunca a `NM` por defecto, que falsearía la valoración de la
 * colección al alza.
 */
final class ManaBoxCsvParserTest extends TestCase
{
    private ManaBoxCsvParser $parser;

    protected function setUp(): void
    {
        $this->parser = new ManaBoxCsvParser();
    }

    /** El fichero de ejemplo: exportación de ManaBox con BOM y saltos CRLF. */
    private function csvDeEjemplo(): string
    {
        $ruta      = __DIR__ . '/../fixtures/manabox.csv';
        $contenido = file_get_contents($ruta);

        self::assertIsString($contenido, 'No se pudo leer tests/fixtures/manabox.csv');

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
        // ESTE es el fallo que cuesta una tarde: con el BOM delante, la primera
        // columna de la cabecera es "\xEF\xBB\xBFName" y no casa NUNCA.
        $contenido = $this->csvDeEjemplo();

        self::assertStringStartsWith("\xEF\xBB\xBF", $contenido);
        self::assertTrue($this->parser->supports('ManaBox_Collection.csv', $contenido));
    }

    public function testNoReconoceElCsvDeOtrasApps(): void
    {
        // Moxfield: 'Count' y 'Edition', no 'Quantity' ni 'Set Code'.
        $moxfield = "\"Count\",\"Tradelist Count\",\"Name\",\"Edition\",\"Condition\",\"Language\"\n";
        // Archidekt: 'Finish' y 'Edition Code'.
        $archidekt = "Quantity,Name,Finish,Condition,Language,Edition Name,Edition Code,Scryfall ID\n";

        self::assertFalse($this->parser->supports('moxfield.csv', $moxfield));
        self::assertFalse($this->parser->supports('archidekt.csv', $archidekt));
        self::assertFalse($this->parser->supports('lista.txt', "4 Lightning Bolt (M10) 146\n"));
        self::assertFalse($this->parser->supports('vacio.csv', ''));
    }

    public function testLaDeteccionEsPorCabeceraYNoPorNombreDeFichero(): void
    {
        // El usuario renombra el fichero: da igual, manda la cabecera.
        $contenido = $this->csvDeEjemplo();

        self::assertTrue($this->parser->supports('cartas.csv', $contenido));
        self::assertTrue($this->parser->supports('', $contenido));
    }

    // ------------------------------------------------------------------ parseo

    public function testUnCsvRealSeParseaAFilasConIdiomaYCondicion(): void
    {
        $filas = $this->filasDelEjemplo();

        self::assertCount(6, $filas);

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

    public function testTodasLasFilasDelEjemploSonValidas(): void
    {
        foreach ($this->filasDelEjemplo() as $fila) {
            self::assertTrue(
                $fila->esValida(),
                'Línea ' . $fila->sourceLine . ': ' . (string) $fila->motivoDeError()
            );
        }
    }

    public function testFoilEsTextoDeTresValoresYNoUnBooleano(): void
    {
        // 'etched' tiene precio propio en mtg_price_current: tratarlo como foil
        // falsea la valoración de Commander Legends en adelante.
        $filas = $this->filasDelEjemplo();

        self::assertSame('normal', $filas[0]->finish);
        self::assertSame('foil', $filas[1]->finish);
        self::assertSame('etched', $filas[2]->finish);
    }

    public function testElIdiomaSaleEnLaFormaLargaDeMtgjson(): void
    {
        // 'es' → 'Spanish', que es lo que casa con mtg_printing_localized.
        $filas = $this->filasDelEjemplo();

        self::assertSame('Spanish', $filas[1]->language);
        self::assertSame('Japanese', $filas[2]->language);
        self::assertSame('German', $filas[4]->language);
        self::assertSame('Italian', $filas[5]->language);
    }

    public function testLaCondicionEnSnakeCaseSeReconoce(): void
    {
        // ManaBox escribe 'near_mint' y 'light_played'. Es la causa conocida de
        // los "Could not parse card condition" al importar su CSV en Moxfield.
        $filas = $this->filasDelEjemplo();

        self::assertSame('NM', $filas[0]->condition);   // near_mint
        self::assertSame('EX', $filas[1]->condition);   // excellent
        self::assertSame('LP', $filas[2]->condition);   // light_played
        self::assertSame('NM', $filas[3]->condition);   // Near Mint
        self::assertSame('GD', $filas[4]->condition);   // good
        self::assertSame('M', $filas[5]->condition);    // mint
    }

    public function testElNombreConComaSobreviveAlCsv(): void
    {
        $fila = $this->filasDelEjemplo()[3];

        self::assertSame('Rin and Seri, Inseparable', $fila->name);
        self::assertSame('M21', $fila->setCode);
    }

    public function testElNombreDeDobleCaraConservaLasDosCaras(): void
    {
        // El resolvedor necesita el nombre completo con ' // ': las 501 cartas
        // transform/modal_dfc lo guardan así en mtg_card.name.
        $fila = $this->filasDelEjemplo()[4];

        self::assertSame('Delver of Secrets // Insectile Aberration', $fila->name);
    }

    public function testCadaFilaLlevaSuNumeroDeLineaFisica(): void
    {
        // Es lo que la previsualización usa para señalar el error en el fichero
        // del usuario. La cabecera es la línea 1.
        $lineas = array_map(
            static fn (ParsedRow $fila): int => $fila->sourceLine,
            $this->filasDelEjemplo()
        );

        self::assertSame([2, 3, 4, 5, 6, 7], $lineas);
    }

    // ------------------------------------- la regla de oro: nada de NM por defecto

    public function testUnaCondicionQueNoCasaInvalidaLaFilaYNoCaeANm(): void
    {
        // ESTE es el test que resume el hito. Caer a NM falsearía la valoración
        // de la colección al alza sin que el usuario se entere.
        $fila = $this->unaFilaDe('condition', 'casi nuevo');

        self::assertFalse($fila->esValida());
        self::assertArrayHasKey('condition', $fila->errores);
        self::assertSame('casi nuevo', $fila->condition, 'el valor crudo se conserva');
        self::assertNotSame('NM', $fila->condition);
    }

    public function testUnIdiomaOUnAcabadoQueNoCasanTambienInvalidanLaFila(): void
    {
        $idioma = $this->unaFilaDe('language', 'klingon');
        self::assertFalse($idioma->esValida());
        self::assertSame('klingon', $idioma->language);

        $acabado = $this->unaFilaDe('foil', 'galaxy foil');
        self::assertFalse($acabado->esValida());
        self::assertSame('galaxy foil', $acabado->finish);
    }

    public function testUnaColumnaAUSENTESiCaeAlValorPorDefecto(): void
    {
        // La distinción que manda: columna que no está = el fichero no afirma
        // nada; columna presente con un valor raro = conflicto.
        $csv = "Name,Set Code,Collector Number,Foil,Quantity,ManaBox ID\n"
             . "Lightning Bolt,LEA,161,normal,2,10101\n";

        $filas = $this->parser->parse($csv);

        self::assertCount(1, $filas);
        self::assertTrue($filas[0]->esValida());
        self::assertSame('NM', $filas[0]->condition);
        self::assertSame('English', $filas[0]->language);
    }

    public function testUnaCeldaVaciaSeTrataComoColumnaAusente(): void
    {
        $fila = $this->unaFilaDe('condition', '');

        self::assertTrue($fila->esValida());
        self::assertSame('NM', $fila->condition);
    }

    public function testUnaCantidadNoNumericaInvalidaLaFila(): void
    {
        $fila = $this->unaFilaDe('quantity', 'tres');

        self::assertFalse($fila->esValida());
        self::assertSame(0, $fila->quantity);
        self::assertStringContainsString('quantity', (string) $fila->motivoDeError());
    }

    public function testUnaFilaSinScryfallIdYSinNombreEsInvalida(): void
    {
        $csv = "Name,Set Code,Collector Number,Foil,Quantity,Scryfall ID\n"
             . ",LEA,161,normal,1,\n";

        $fila = $this->parser->parse($csv)[0];

        self::assertFalse($fila->esValida());
        self::assertArrayHasKey('fila', $fila->errores);
    }

    public function testLasFilasInvalidasNoSeDescartan(): void
    {
        // Viajan por el pipeline como cualquier otra: es la previsualización
        // quien decide, no el parser.
        $csv = "Name,Set Code,Collector Number,Foil,Quantity,Condition\n"
             . "Lightning Bolt,LEA,161,normal,1,near_mint\n"
             . "Sol Ring,C21,263,normal,1,casi nuevo\n"
             . "Counterspell,7ED,67,foil,1,poor\n";

        $filas = $this->parser->parse($csv);

        self::assertCount(3, $filas);
        self::assertSame([true, false, true], array_map(
            static fn (ParsedRow $fila): bool => $fila->esValida(),
            $filas
        ));
        self::assertSame(3, $filas[1]->sourceLine);
        self::assertSame('Sol Ring', $filas[1]->name, 'lo demás de la fila se conserva');
    }

    // ----------------------------------------------------------- tolerancia

    public function testLasColumnasSeBuscanPorNombreYNoPorPosicion(): void
    {
        // Las versiones de ManaBox añaden y quitan columnas ('Binder Name',
        // 'Altered', 'Purchase Price Currency'); un parser posicional se rompe
        // con cada actualización de la app.
        $csv = "Binder Name,Binder Type,Quantity,Name,Set Code,Collector Number,"
             . "Foil,Language,Condition,ManaBox ID,Altered\n"
             . "Colección,binder,7,Sol Ring,C21,263,foil,es,near_mint,20202,false\n";

        self::assertTrue($this->parser->supports('manabox.csv', $csv));

        $fila = $this->parser->parse($csv)[0];

        self::assertSame('Sol Ring', $fila->name);
        self::assertSame(7, $fila->quantity);
        self::assertSame('Spanish', $fila->language);
        self::assertSame('NM', $fila->condition);
    }

    public function testSeIgnoranLasLineasEnBlanco(): void
    {
        $csv = "\xEF\xBB\xBFName,Set Code,Collector Number,Foil,Quantity\r\n"
             . "Lightning Bolt,LEA,161,normal,1\r\n"
             . "\r\n"
             . "Sol Ring,C21,263,foil,1\r\n"
             . "\r\n";

        $filas = $this->parser->parse($csv);

        self::assertCount(2, $filas);
        self::assertSame(4, $filas[1]->sourceLine, 'la línea en blanco sí cuenta al numerar');
    }

    public function testUnFicheroSoloConCabeceraNoDaFilas(): void
    {
        self::assertSame([], $this->parser->parse("Name,Set Code,Foil,Quantity,ManaBox ID\n"));
        self::assertSame([], $this->parser->parse(''));
    }

    public function testElRegistroCrudoViajaConLaFilaParaLaPrevisualizacion(): void
    {
        $fila = $this->filasDelEjemplo()[0];

        self::assertSame('common', $fila->crudo['rarity']);
        self::assertSame('2.50', $fila->crudo['purchase price']);
    }

    /** Una sola fila con una columna puesta al valor que se quiera probar. */
    private function unaFilaDe(string $columna, string $valor): ParsedRow
    {
        $csv = "Name,Set Code,Collector Number,Foil,Quantity,Language,Condition\n";

        $celdas = [
            'name'             => 'Lightning Bolt',
            'set code'         => 'LEA',
            'collector number' => '161',
            'foil'             => 'normal',
            'quantity'         => '1',
            'language'         => 'en',
            'condition'        => 'near_mint',
        ];
        $celdas[$columna] = $valor;

        return $this->parser->parse($csv . implode(',', $celdas) . "\n")[0];
    }
}
