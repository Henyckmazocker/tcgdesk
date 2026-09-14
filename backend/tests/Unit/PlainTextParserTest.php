<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Import\ParsedRow;
use App\Infrastructure\Import\PlainTextParser;
use PHPUnit\Framework\TestCase;

/**
 * El texto plano es **el único formato sin Scryfall ID**, así que lo que se
 * protege aquí no es leer un fichero: es que la lista que el usuario pega desde
 * una web salga con las cantidades y los nombres correctos, y que **lo que no
 * casa aparezca como conflicto con su número de línea físico** — las dos
 * mitades del *hecho cuando* del hito.
 *
 * Y una trampa propia de este formato que cuesta una tarde: `//` es a la vez
 * marca de comentario y separador de las caras de una carta de doble cara.
 */
final class PlainTextParserTest extends TestCase
{
    private PlainTextParser $parser;

    protected function setUp(): void
    {
        $this->parser = new PlainTextParser();
    }

    private function listaDeEjemplo(): string
    {
        $contenido = file_get_contents(__DIR__ . '/../fixtures/plaintext.txt');

        self::assertIsString($contenido, 'No se pudo leer tests/fixtures/plaintext.txt');

        return $contenido;
    }

    /** @return ParsedRow[] */
    private function filasDelEjemplo(): array
    {
        return $this->parser->parse($this->listaDeEjemplo());
    }

    // --------------------------------------------------------------- detección

    public function testReconoceUnaListaPegadaDeUnaWeb(): void
    {
        $contenido = $this->listaDeEjemplo();

        self::assertStringStartsWith("\xEF\xBB\xBF", $contenido, 'el fixture imita un pegado con BOM');
        self::assertStringContainsString("\r\n", $contenido, 'y con finales de línea de Windows');

        self::assertTrue($this->parser->supports('lista.txt', $contenido));
        // El contenido se pega a mano: no hay nombre de fichero del que fiarse.
        self::assertTrue($this->parser->supports('', $contenido));
        self::assertTrue($this->parser->supports('mazo (1).dec', $contenido));
    }

    public function testReconoceLasTresFormasDelPlanSueltas(): void
    {
        self::assertTrue($this->parser->supports('', "4 Lightning Bolt (M10) 146\n"));
        self::assertTrue($this->parser->supports('', "4x Lightning Bolt\n"));
        self::assertTrue($this->parser->supports('', "4 Lightning Bolt\n2 Counterspell\n"));
    }

    public function testNoReclamaLoQueNoEsUnaListaDeCartas(): void
    {
        // Los seis negativos que `ParserRegistryTest` exige que nadie reclame:
        // si este parser dijera que sí a alguno, la detección dejaría de ser
        // detección y un fichero de otro juego se importaría a medias.
        $pokemon = "Nombre,Numero,Set,Rareza,Cantidad\nPikachu,58,Base Set,Common,3\n";

        self::assertFalse($this->parser->supports('coleccion-pokemon.csv', $pokemon));
        self::assertFalse($this->parser->supports('gastos.csv', "Fecha,Concepto,Importe\n2026-09-01,Sobres,12.5\n"));
        self::assertFalse($this->parser->supports('coleccion.json', "{\n  \"cards\": [\n    {\"name\": \"Sol Ring\"}\n  ]\n}\n"));
        self::assertFalse($this->parser->supports('escaneo.png', "\x89PNG\r\n\x1a\n\x00\x00\x00\rIHDR"));
        self::assertFalse($this->parser->supports('vacio.txt', ''));
        self::assertFalse($this->parser->supports('prosa.txt', "Hola, esto es un correo.\nUn saludo.\n"));
    }

    public function testNoReclamaLosCsvDeLasTresApps(): void
    {
        foreach (['manabox', 'moxfield', 'archidekt'] as $formato) {
            $csv = file_get_contents(__DIR__ . '/../fixtures/' . $formato . '.csv');

            self::assertIsString($csv);
            self::assertFalse(
                $this->parser->supports($formato . '.csv', $csv),
                'el texto plano reclama el CSV de ' . $formato
            );
        }
    }

    public function testUnaListaDeNombresSueltosNoSeAutodetecta(): void
    {
        // Decisión asumida y documentada: la firma de una lista de cartas es la
        // cantidad al principio de la línea. Un nombre suelto es indistinguible
        // de una línea de prosa o de una fila de otro CSV, así que sin ninguna
        // cantidad no se reclama —aunque `parse()` sí sabe leerla, y M5 la
        // importa forzando el formato.
        $lista = "Lightning Bolt\nCounterspell\nSol Ring\n";

        self::assertFalse($this->parser->supports('lista.txt', $lista));
        self::assertCount(3, $this->parser->parse($lista));
    }

    public function testUnasPocasLineasRotasNoTumbanLaDeteccion(): void
    {
        // El hito pide que las líneas malas salgan como conflicto, no que
        // invaliden el fichero entero.
        $lista = "4 Lightning Bolt\n2 Counterspell\nComprado por $32.10\n";

        self::assertTrue($this->parser->supports('', $lista));
    }

    // ------------------------------------------------------------------ parseo

    public function testLaListaPegadaSeImportaEntera(): void
    {
        // ESTE es el "hecho cuando" del hito: una lista copiada de una web real
        // se convierte en filas con sus cantidades y sus nombres.
        $filas = $this->filasDelEjemplo();

        self::assertCount(10, $filas);

        $resumen = array_map(
            static fn (ParsedRow $fila): array => [$fila->quantity, $fila->name],
            $filas
        );

        self::assertSame([
            [4,  'Lightning Bolt'],
            [4,  'Counterspell'],
            [2,  'Jace, the Mind Sculptor'],
            [3,  'Delver of Secrets // Insectile Aberration'],
            [1,  "Lim-Dûl's Vault"],
            [1,  'Sol Ring'],
            [2,  'Path to Exile'],
            [15, 'Thoughtseize'],
            [0,  null],
            [1,  'Sheoldred, the Apocalypse'],
        ], $resumen);
    }

    public function testLaEdicionYElNumeroSalenCuandoLaLineaLosTrae(): void
    {
        $filas = $this->filasDelEjemplo();

        self::assertSame('M10', $filas[0]->setCode);
        self::assertSame('146', $filas[0]->collectorNumber);

        // Y no se inventan cuando no vienen: elegir impresión es del resolvedor.
        self::assertNull($filas[1]->setCode);
        self::assertNull($filas[1]->collectorNumber);

        // El código se normaliza a mayúsculas, como en los tres CSV.
        self::assertSame('CON', $filas[6]->setCode, 'la línea trae (con)');
        self::assertSame('15', $filas[6]->collectorNumber);
    }

    public function testLasTresFormasDelPlanYLaDeSoloUnNombre(): void
    {
        $filas = $this->parser->parse(
            "4 Lightning Bolt (M10) 146\n"
            . "4x Lightning Bolt\n"
            . "4 Lightning Bolt\n"
            . "4 Lightning Bolt (M10)\n"
            . "Lightning Bolt\n"
        );

        self::assertCount(5, $filas);

        foreach ($filas as $fila) {
            self::assertSame('Lightning Bolt', $fila->name);
            self::assertTrue($fila->esValida());
        }

        self::assertSame([4, 4, 4, 4, 1], array_map(
            static fn (ParsedRow $fila): int => $fila->quantity,
            $filas
        ));
        self::assertSame(['M10', null, null, 'M10', null], array_map(
            static fn (ParsedRow $fila): ?string => $fila->setCode,
            $filas
        ));
        self::assertSame(['146', null, null, null, null], array_map(
            static fn (ParsedRow $fila): ?string => $fila->collectorNumber,
            $filas
        ));
    }

    public function testUnNombreSueltoValeUnaCopia(): void
    {
        $fila = $this->parser->parse("Sol Ring\n")[0];

        self::assertSame(1, $fila->quantity);
        self::assertSame('Sol Ring', $fila->name);
        self::assertTrue($fila->esValida());
    }

    public function testElTextoPlanoNoTraeScryfallIdYCaeALosValoresPorDefecto(): void
    {
        // Aquí el valor por defecto SÍ es legítimo: el formato no tiene columna
        // de acabado, idioma ni estado. Lo que la regla de oro prohíbe es caer a
        // `NM` cuando el fichero decía otra cosa, y aquí no dice nada.
        $fila = $this->parser->parse("4 Lightning Bolt (M10) 146\n")[0];

        self::assertNull($fila->scryfallId, 'es el único formato del plan sin Scryfall ID');
        self::assertSame('normal', $fila->finish);
        self::assertSame('English', $fila->language);
        self::assertSame('NM', $fila->condition);
    }

    // ------------------------------------------------- la trampa del `//`

    public function testUnComentarioSeIgnoraYUnaDobleCaraNoSeParte(): void
    {
        // `//` al principio es comentario; ` // ` en medio de un nombre NO lo
        // es: las 501 de 501 cartas transform/modal_dfc del catálogo guardan el
        // nombre completo con ` // `, y recortar el comentario a final de línea
        // las partiría por la mitad.
        $filas = $this->parser->parse(
            "// 4 Esto es un comentario y no se importa\n"
            . "   // con espacios delante, también\n"
            . "# y con almohadilla\n"
            . "3 Delver of Secrets // Insectile Aberration\n"
            . "Fable of the Mirror-Breaker // Reflection of Kiki-Jiki\n"
        );

        self::assertCount(2, $filas);
        self::assertSame('Delver of Secrets // Insectile Aberration', $filas[0]->name);
        self::assertSame(3, $filas[0]->quantity);
        self::assertSame('Fable of the Mirror-Breaker // Reflection of Kiki-Jiki', $filas[1]->name);
        self::assertSame(4, $filas[0]->sourceLine);
        self::assertSame(5, $filas[1]->sourceLine);
    }

    public function testLaDobleCaraDelFixtureSobreviveEntera(): void
    {
        self::assertSame(
            'Delver of Secrets // Insectile Aberration',
            $this->filasDelEjemplo()[3]->name
        );
    }

    // --------------------------------------- conflictos con su número de línea

    public function testLaLineaQueNoCasaSaleComoConflictoConSuLineaFisica(): void
    {
        // La otra mitad del "hecho cuando". La línea 15 del fixture es la basura
        // que se cuela al copiar de una web, y por delante lleva dos líneas en
        // blanco, dos comentarios y dos cabeceras: si el parser contara filas en
        // vez de líneas físicas, el número que ve el usuario señalaría otra cosa.
        $fila = $this->filasDelEjemplo()[8];

        self::assertFalse($fila->esValida());
        self::assertSame(15, $fila->sourceLine);
        self::assertArrayHasKey('linea', $fila->errores);
        self::assertSame('Comprado en TCGPlayer por $32.10', $fila->crudo['linea']);
        self::assertStringContainsString('$32.10', (string) $fila->motivoDeError());
        self::assertNull($fila->name, 'no se adivina un nombre de carta a partir de la basura');
    }

    public function testLasCabecerasYLosComentariosNoConsumenNumeroDeLinea(): void
    {
        $lineas = array_map(
            static fn (ParsedRow $fila): int => $fila->sourceLine,
            $this->filasDelEjemplo()
        );

        self::assertSame([2, 3, 4, 7, 8, 10, 13, 14, 15, 16], $lineas);
    }

    public function testLasCabecerasDeSeccionNoSonUnaCartaYSusCartasNoSePierden(): void
    {
        $filas = $this->parser->parse(
            "Deck\n4 Lightning Bolt\n\nSideboard:\n2 Path to Exile\n"
            . "Commander\n1 Atraxa, Praetors' Voice\nCompanion\n1 Yorion, Sky Nomad\n"
        );

        self::assertSame(
            ['Lightning Bolt', 'Path to Exile', "Atraxa, Praetors' Voice", 'Yorion, Sky Nomad'],
            array_map(static fn (ParsedRow $fila): ?string => $fila->name, $filas)
        );
    }

    // ------------------------------------------------------------------ zonas

    /**
     * **M7: las cabeceras ya no se tiran, cambian el board activo.** Las cuatro
     * que el parser conoce, con la traducción del ENUM (`Deck` → `main`,
     * `Sideboard` → `side`).
     */
    public function testCadaCabeceraCambiaLaZonaYLasFilasViajanMarcadas(): void
    {
        $filas = $this->parser->parse(
            "Deck\n4 Lightning Bolt\n\nSideboard:\n2 Path to Exile\n"
            . "Commander\n1 Atraxa, Praetors' Voice\nCompanion\n1 Yorion, Sky Nomad\n"
        );

        self::assertSame(
            ['main', 'side', 'commander', 'companion'],
            array_map(static fn (ParsedRow $fila): string => $fila->board, $filas)
        );
    }

    /** Una lista sin ninguna cabecera es todo `main`: es lo que significa. */
    public function testSinCabecerasTodoEsElBoardPorDefecto(): void
    {
        $filas = $this->parser->parse("4 Lightning Bolt\n2 Counterspell\n");

        self::assertSame(['main', 'main'], array_map(
            static fn (ParsedRow $fila): string => $fila->board,
            $filas
        ));
    }

    /** La cabecera vale para TODAS las de abajo, no solo para la siguiente. */
    public function testLaZonaSeMantieneHastaLaSiguienteCabecera(): void
    {
        $filas = $this->parser->parse(
            "Sideboard\n2 Path to Exile\n// un comentario en medio\n1 Rest in Peace\n\n3 Duress\n"
        );

        self::assertSame(['side', 'side', 'side'], array_map(
            static fn (ParsedRow $fila): string => $fila->board,
            $filas
        ));
    }

    /**
     * **Lo que el hito avisa por escrito:** cambiar de zona NO puede consumir
     * número de línea, o la previsualización dejaría de señalar la línea que el
     * usuario tiene delante.
     */
    public function testCambiarDeZonaNoDescuadraLaLineaFisica(): void
    {
        $filas = $this->parser->parse(
            "Deck\n4 Lightning Bolt\nSideboard\n2 Path to Exile\n"
        );

        self::assertSame([2, 4], array_map(
            static fn (ParsedRow $fila): int => $fila->sourceLine,
            $filas
        ));
        self::assertSame(['main', 'side'], array_map(
            static fn (ParsedRow $fila): string => $fila->board,
            $filas
        ));
    }

    /**
     * Una fila que va a conflicto también viaja con su zona: arreglarla a mano
     * en la previsualización no puede mandarla al main por olvido.
     */
    public function testLaFilaEnConflictoTambienConservaSuZona(): void
    {
        $filas = $this->parser->parse("Sideboard\n*** pegado a medias {{ }}\n");

        self::assertCount(1, $filas);
        self::assertFalse($filas[0]->esValida());
        self::assertSame('side', $filas[0]->board);
    }

    /**
     * Las cabeceras siguen fuera del cómputo de `supports()`: el umbral del
     * 50 % de líneas con cantidad **no se relaja** al empezar a usarlas.
     */
    public function testLasCabecerasSiguenSinContarParaLaDeteccion(): void
    {
        // Cuatro cabeceras y una sola carta con cantidad: si las cabeceras
        // contaran como línea útil, la proporción caería a 1/5 y no se
        // reclamaría.
        self::assertTrue($this->parser->supports('', "Deck\nSideboard\nCommander\nCompanion\n2 Duress\n"));

        // Y lo que no es una lista de cartas sigue sin reclamarse.
        self::assertFalse($this->parser->supports('', "Deck\nSideboard\nprosa suelta sin cantidad\n"));
    }

    public function testUnaLineaDeProsaVaAConflictoYNoSeImportaComoCarta(): void
    {
        $filas = $this->parser->parse(
            "4 Lightning Bolt\n"
            . "Compra este mazo por \$32.10\n"
            . "*** pegado a medias {{ }}\n"
            . "2 Counterspell\n"
        );

        self::assertCount(4, $filas);
        self::assertTrue($filas[0]->esValida());
        self::assertFalse($filas[1]->esValida());
        self::assertFalse($filas[2]->esValida());
        self::assertTrue($filas[3]->esValida());
        self::assertSame([1, 2, 3, 4], array_map(
            static fn (ParsedRow $fila): int => $fila->sourceLine,
            $filas
        ));
    }

    public function testUnaCantidadCeroInvalidaLaFilaYNoSeDescarta(): void
    {
        $fila = $this->parser->parse("0 Lightning Bolt\n")[0];

        self::assertFalse($fila->esValida());
        self::assertArrayHasKey('quantity', $fila->errores);
        self::assertSame('Lightning Bolt', $fila->name, 'la fila viaja marcada, no se descarta');
        self::assertSame(0, $fila->quantity);
    }

    // ------------------------------------------------- casos medidos del catálogo

    public function testLosNombresQueEmpiezanPorNumeroNoSeLeenComoCantidad(): void
    {
        // Medido sobre el catálogo: hay tres nombres que empiezan por dígito, y
        // con una cantidad de cuatro cifras `1996 World Champion` se importaría
        // como 1996 copias de «World Champion».
        $filas = $this->parser->parse(
            "1996 World Champion\n17-Year Cicadas\n70,000 Light-Years from Home\n"
            . "4 1996 World Champion\n"
        );

        self::assertSame([
            ['1996 World Champion', 1],
            ['17-Year Cicadas', 1],
            ['70,000 Light-Years from Home', 1],
            ['1996 World Champion', 4],
        ], array_map(
            static fn (ParsedRow $fila): array => [$fila->name, $fila->quantity],
            $filas
        ));
    }

    public function testLosNombresConComaDiacriticosYApostrofoSobreviven(): void
    {
        $filas = $this->parser->parse(
            "2 Jace, the Mind Sculptor\n1 Lim-Dûl's Vault (ALL) 107\n1 Jötun Grunt\n"
            . "1 Circle of Protection: Red\n1 \"Ach! Hans, Run!\"\n"
        );

        self::assertSame([
            'Jace, the Mind Sculptor',
            "Lim-Dûl's Vault",
            'Jötun Grunt',
            'Circle of Protection: Red',
            '"Ach! Hans, Run!"',
        ], array_map(static fn (ParsedRow $fila): ?string => $fila->name, $filas));

        foreach ($filas as $fila) {
            self::assertTrue($fila->esValida(), (string) $fila->name);
        }
    }

    public function testUnFicheroVacioODeSoloComentariosNoDaFilas(): void
    {
        self::assertSame([], $this->parser->parse(''));
        self::assertSame([], $this->parser->parse("\n\n   \n"));
        self::assertSame([], $this->parser->parse("// solo comentarios\n# y cabeceras\nDeck\nSideboard\n"));
    }

    public function testElNombreDelFormatoEsEstable(): void
    {
        self::assertSame('plaintext', $this->parser->getName());
    }
}
