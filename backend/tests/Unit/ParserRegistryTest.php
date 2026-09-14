<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Import\CollectionParserInterface;
use App\Domain\Import\ParsedRow;
use App\Domain\Import\ParserRegistry;
use App\Infrastructure\Import\ArchidektCsvParser;
use App\Infrastructure\Import\ManaBoxCsvParser;
use App\Infrastructure\Import\MoxfieldCsvParser;
use App\Infrastructure\Import\PlainTextParser;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/**
 * El registro es lo que hace que **el usuario nunca elija formato**: sube su
 * exportación y la app la reconoce por la cabecera.
 *
 * Lo que se protege aquí son las dos reglas que lo hacen fiable: gana el primer
 * parser que dice que sí (por eso el genérico de texto plano va el último), y
 * un fichero que nadie reconoce devuelve `null` para que la importación falle
 * con un error claro en vez de a medias.
 */
final class ParserRegistryTest extends TestCase
{
    /** Un parser de mentira que acepta lo que se le diga. */
    private function parserFalso(string $nombre, bool $acepta): CollectionParserInterface
    {
        return new class ($nombre, $acepta) implements CollectionParserInterface {
            public function __construct(
                private readonly string $nombre,
                private readonly bool $acepta,
            ) {
            }

            public function supports(string $filename, string $sample): bool
            {
                return $this->acepta;
            }

            public function getName(): string
            {
                return $this->nombre;
            }

            /** @return ParsedRow[] */
            public function parse(string $content): array
            {
                return [];
            }
        };
    }

    public function testDetectaElCsvDeManaboxSinQueElUsuarioElijaFormato(): void
    {
        $contenido = (string) file_get_contents(__DIR__ . '/../fixtures/manabox.csv');
        $registro  = new ParserRegistry([new ManaBoxCsvParser()]);

        $parser = $registro->detectar('ManaBox_Collection.csv', $contenido);

        self::assertNotNull($parser);
        self::assertSame('manabox', $parser->getName());
    }

    public function testGanaElPrimeroQueDiceQueSi(): void
    {
        // El orden manda: un parser genérico —el de texto plano del M4— acepta
        // cualquier cosa y por eso tiene que ir SIEMPRE el último de la lista.
        $registro = new ParserRegistry([
            $this->parserFalso('especifico', true),
            $this->parserFalso('generico', true),
        ]);

        self::assertSame('especifico', $registro->detectar('x.csv', 'lo que sea')?->getName());
    }

    public function testUnFicheroQueNadieReconoceDevuelveNull(): void
    {
        // Un fichero corrupto o de otro juego produce un error claro, no una
        // importación a medias.
        $registro = new ParserRegistry([$this->parserFalso('manabox', false)]);

        self::assertNull($registro->detectar('otro-juego.csv', 'Pokémon,Nivel\n'));
    }

    public function testSoloSeLePasaUnaMuestraAlParser(): void
    {
        // Decidir el formato de un fichero de 5 MB no puede obligar a recorrerlo
        // entero.
        $espia = new class implements CollectionParserInterface {
            public string $muestra = '';

            public function supports(string $filename, string $sample): bool
            {
                $this->muestra = $sample;

                return true;
            }

            public function getName(): string
            {
                return 'espia';
            }

            /** @return ParsedRow[] */
            public function parse(string $content): array
            {
                return [];
            }
        };

        (new ParserRegistry([$espia]))->detectar('grande.csv', str_repeat('a', 100_000));

        self::assertSame(4096, strlen($espia->muestra));
    }

    public function testSePuedeForzarElFormatoPorNombre(): void
    {
        $registro = new ParserRegistry([new ManaBoxCsvParser()]);

        self::assertSame('manabox', $registro->porNombre('manabox')->getName());
        self::assertSame(['manabox'], $registro->nombres());
    }

    public function testUnFormatoDesconocidoSeRechaza(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new ParserRegistry([new ManaBoxCsvParser()]))->porNombre('deckbox');
    }

    // ------------------------------------------------- los tres CSV, a la vez

    /**
     * Los tres parsers reales, en el MISMO orden en que los registra
     * `config/container.php`.
     *
     * @return CollectionParserInterface[]
     */
    private function losTresParsers(): array
    {
        return [
            new ManaBoxCsvParser(),
            new MoxfieldCsvParser(),
            new ArchidektCsvParser(),
        ];
    }

    private function fixture(string $formato): string
    {
        $contenido = file_get_contents(__DIR__ . '/../fixtures/' . $formato . '.csv');

        self::assertIsString($contenido, 'No se pudo leer el fixture de ' . $formato);

        return $contenido;
    }

    public function testLosTresCsvSeDetectanSolosSinQueElUsuarioElijaFormato(): void
    {
        // ESTE es el "hecho cuando" del hito: el usuario sube su exportación y
        // la app sabe de qué app viene. No hay selector de formato en la UI.
        $registro = new ParserRegistry($this->losTresParsers());

        foreach (['manabox', 'moxfield', 'archidekt'] as $formato) {
            $contenido = $this->fixture($formato);

            self::assertSame(
                $formato,
                $registro->detectar($formato . '.csv', $contenido)?->getName(),
                'no se detectó el CSV de ' . $formato
            );

            // Manda la cabecera: da igual cómo se llame el fichero, y da igual
            // que no tenga nombre porque el contenido se pegó a mano.
            self::assertSame($formato, $registro->detectar('', $contenido)?->getName());
            self::assertSame(
                $formato,
                $registro->detectar('mi coleccion (1).csv', $contenido)?->getName()
            );
        }

        self::assertSame(['manabox', 'moxfield', 'archidekt'], $registro->nombres());
    }

    public function testCadaParserReclamaSuFixtureYRechazaLosOtrosDos(): void
    {
        // Con tres CSV parecidos, que gane "el primero que dice que sí" solo es
        // fiable si cada `supports()` dice que sí a UNO. Si esta matriz deja de
        // ser la diagonal, la detección ha pasado a depender del orden.
        foreach ($this->losTresParsers() as $parser) {
            foreach (['manabox', 'moxfield', 'archidekt'] as $formato) {
                self::assertSame(
                    $parser->getName() === $formato,
                    $parser->supports('', $this->fixture($formato)),
                    $parser->getName() . ' frente al CSV de ' . $formato
                );
            }
        }
    }

    public function testUnCsvQueNoEsDeNingunoDeLosTresNoLoReclamaNadie(): void
    {
        // Un registro que acepta cualquier cosa no está detectando: adivina.
        $registro = new ParserRegistry($this->losTresParsers());

        $pokemon = "Nombre,Numero,Set,Rareza,Cantidad\n"
                 . "Pikachu,58,Base Set,Common,3\n";

        self::assertNull($registro->detectar('coleccion-pokemon.csv', $pokemon));
        self::assertNull($registro->detectar('gastos.csv', "Fecha,Concepto,Importe\n2026-09-01,Sobres,12.5\n"));
    }

    public function testUnFicheroQueNiSiquieraEsCsvNoLoReclamaNadie(): void
    {
        $registro = new ParserRegistry($this->losTresParsers());

        // Texto plano: ninguno de los tres CSV lo reclama. El del M4 sí, y va
        // el último del registro — ver los cuatro parsers, más abajo.
        self::assertNull($registro->detectar('lista.txt', "4 Lightning Bolt (M10) 146\n2x Counterspell\n"));
        self::assertNull($registro->detectar('coleccion.json', "{\n  \"cards\": [\n    {\"name\": \"Sol Ring\"}\n  ]\n}\n"));
        self::assertNull($registro->detectar('escaneo.png', "\x89PNG\r\n\x1a\n\x00\x00\x00\rIHDR"));
        self::assertNull($registro->detectar('vacio.csv', ''));
    }

    // ------------------------------------ los cuatro parsers, con el texto plano

    /**
     * Los cuatro parsers reales, en el MISMO orden en que los registra
     * `config/container.php`. `PlainTextParser` **el último**: es el único que
     * podría reclamar cualquier cosa.
     *
     * @return CollectionParserInterface[]
     */
    private function losCuatroParsers(): array
    {
        return [
            new ManaBoxCsvParser(),
            new MoxfieldCsvParser(),
            new ArchidektCsvParser(),
            new PlainTextParser(),
        ];
    }

    public function testLaListaDeTextoPlanoLaReclamaElParserDelM4(): void
    {
        $registro = new ParserRegistry($this->losCuatroParsers());

        $contenido = file_get_contents(__DIR__ . '/../fixtures/plaintext.txt');
        self::assertIsString($contenido, 'No se pudo leer tests/fixtures/plaintext.txt');

        self::assertSame('plaintext', $registro->detectar('lista.txt', $contenido)?->getName());
        // Manda el contenido: una lista se pega a mano y no tiene nombre.
        self::assertSame('plaintext', $registro->detectar('', $contenido)?->getName());
        self::assertSame(
            'plaintext',
            $registro->detectar('', "4 Lightning Bolt (M10) 146\n2x Counterspell\n")?->getName()
        );

        self::assertSame(
            ['manabox', 'moxfield', 'archidekt', 'plaintext'],
            $registro->nombres(),
            'el genérico tiene que ser el último de la lista'
        );
    }

    public function testConElTextoPlanoRegistradoLosTresCsvSiguenSaliendoConSuParser(): void
    {
        // El riesgo de meter un parser genérico es justo este: que se coma la
        // detección de los específicos. Va el último, y además su `supports()`
        // rechaza los tres CSV por su cuenta (`PlainTextParserTest`).
        $registro = new ParserRegistry($this->losCuatroParsers());

        foreach (['manabox', 'moxfield', 'archidekt'] as $formato) {
            $contenido = $this->fixture($formato);

            self::assertSame($formato, $registro->detectar($formato . '.csv', $contenido)?->getName());
            self::assertSame($formato, $registro->detectar('', $contenido)?->getName());
        }
    }

    public function testConElTextoPlanoRegistradoSiguenSinReclamarseLosSeisNegativos(): void
    {
        // Los mismos seis de antes del M4: si el genérico los aceptara, un
        // fichero de otro juego se importaría a medias en vez de dar un error
        // claro.
        $registro = new ParserRegistry($this->losCuatroParsers());

        $pokemon = "Nombre,Numero,Set,Rareza,Cantidad\n"
                 . "Pikachu,58,Base Set,Common,3\n";

        self::assertNull($registro->detectar('coleccion-pokemon.csv', $pokemon));
        self::assertNull($registro->detectar('gastos.csv', "Fecha,Concepto,Importe\n2026-09-01,Sobres,12.5\n"));
        self::assertNull($registro->detectar('coleccion.json', "{\n  \"cards\": [\n    {\"name\": \"Sol Ring\"}\n  ]\n}\n"));
        self::assertNull($registro->detectar('escaneo.png', "\x89PNG\r\n\x1a\n\x00\x00\x00\rIHDR"));
        self::assertNull($registro->detectar('vacio.csv', ''));
        self::assertNull($registro->detectar('correo.txt', "Hola, esto es un correo.\nUn saludo.\n"));
    }
}
