<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Cli\Commands\DecksImportCommand;
use App\Domain\Catalog\PreconSearchCriteria;
use App\Domain\Repository\PreconRepositoryInterface;
use App\Infrastructure\Mtgjson\MtgJsonDeckMapper;
use App\Infrastructure\Mtgjson\MtgJsonDownloader;
use App\Infrastructure\Mtgjson\TarGzReader;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Repositorio de mentira que se comporta como MySQL en lo que importa.
 *
 * Guarda las filas **indexadas por su clave primaria**, que es lo que hace que
 * `contadores()` responda como respondería la base de datos tras un
 * `ON DUPLICATE KEY UPDATE`: por eso una segunda pasada no mueve los contadores y
 * el test de idempotencia significa algo. Los lotes se guardan además **sin
 * aplanar**, porque la restricción de la clave repetida es POR SENTENCIA.
 */
class PreconsFalsos implements PreconRepositoryInterface
{
    /** @var array<string, array<string, mixed>> file_name → fila */
    public array $precons = [];

    /** @var array<string, array<string, mixed>> PK de cuatro columnas → fila */
    public array $cartas = [];

    /** @var array<string, int> file_name → ejemplares */
    public array $conteos = [];

    /** @var array<string, list<list<array<string, mixed>>>> */
    public array $lotes = ['precons' => [], 'cartas' => []];

    /** Lo que devuelve `huerfanos()`; en MySQL sale de un LEFT JOIN. */
    public int $huerfanos = 0;

    public function upsertPrecons(array $filas): int
    {
        $this->lotes['precons'][] = $filas;

        foreach ($filas as $fila) {
            $this->precons[$fila['file_name']] = $fila;
        }

        return count($filas);
    }

    public function upsertCartas(array $filas): int
    {
        $this->lotes['cartas'][] = $filas;

        foreach ($filas as $fila) {
            $this->cartas[self::clave($fila)] = $fila;
        }

        return count($filas);
    }

    public function actualizarCardCount(array $conteos): int
    {
        foreach ($conteos as $fichero => $ejemplares) {
            $this->conteos[$fichero] = $ejemplares;

            if (isset($this->precons[$fichero])) {
                $this->precons[$fichero]['card_count'] = $ejemplares;
            }
        }

        return count($conteos);
    }

    public function contadores(): array
    {
        return [
            'mtg_precon'      => count($this->precons),
            'mtg_precon_card' => count($this->cartas),
        ];
    }

    public function huerfanos(): array
    {
        return ['filas' => $this->huerfanos, 'uuids' => $this->huerfanos];
    }

    // ---- Lectura: las dos rutas GET de M3, que este test no ejercita. La
    // ficha y la lista tienen su propio doble en `Doubles/PreconesFalsos`.

    public function buscar(PreconSearchCriteria $criterios): array
    {
        return ['items' => array_values($this->precons), 'nextCursor' => null];
    }

    public function facetas(): array
    {
        return ['types' => [], 'sets' => []];
    }

    public function find(string $fileName): ?array
    {
        return $this->precons[$fileName] ?? null;
    }

    public function cartas(string $fileName): array
    {
        return array_values(array_filter(
            $this->cartas,
            static fn (array $fila): bool => $fila['precon_file'] === $fileName
        ));
    }

    /** @param array<string, mixed> $fila */
    public static function clave(array $fila): string
    {
        return $fila['precon_file'] . '|' . $fila['printing_uuid'] . '|' . $fila['board'] . '|' . $fila['finish'];
    }
}

/**
 * Lo que protege este test es el criterio de aceptación de M2: que el índice
 * entre entero, que las cartas salgan del tar en streaming, que **relanzarlo no
 * mueva ningún contador** y que el **código de salida** sea el correcto — es lo
 * que mira el cron, y un comando que falla devolviendo 0 deja el catálogo
 * desactualizado sin que nadie se entere.
 *
 * El tar se construye aquí, con cabeceras de 512 bytes de verdad y su cabecera
 * PAX delante de cada fichero como hace MTGJSON, y los mazos van **recortados**:
 * los 571 KB reales convertirían el test en una descarga.
 */
final class DecksImportCommandTest extends TestCase
{
    private string $indice;
    private string $tar;

    protected function setUp(): void
    {
        $base = sys_get_temp_dir() . '/decksimport-' . bin2hex(random_bytes(6));

        $this->indice = $base . '-DeckList.json';
        $this->tar    = $base . '-AllDeckFiles.tar.gz';

        file_put_contents($this->indice, (string) json_encode([
            'meta' => ['date' => '2026-09-11', 'version' => '5.3.0'],
            'data' => [
                [
                    'code'        => 'ZNC',
                    'fileName'    => 'SneakAttack_ZNC',
                    'name'        => 'Sneak Attack',
                    'releaseDate' => '2020-09-25',
                    'source'      => 'https://magic.wizards.com/precon',
                    'type'        => 'Commander Deck',
                ],
                [
                    'code'        => '10E',
                    'fileName'    => 'ArcanisSGuile_10E',
                    'name'        => "Arcanis's Guile",
                    'releaseDate' => '2007-07-13',
                    'source'      => 'http://www.wizards.com/tema',
                    'type'        => 'Theme Deck',
                ],
                [
                    'code'        => 'SLD',
                    'fileName'    => 'DandânDeck_SLD',
                    'name'        => 'Dandân Deck',
                    'releaseDate' => '2023-06-02',
                    'source'      => 'https://magic.wizards.com/sld',
                    'type'        => 'Secret Lair Drop',
                ],
                // Sin fileName: no hay clave natural y se descarta.
                ['code' => 'SLD', 'name' => 'Sin fichero', 'type' => 'Secret Lair Drop'],
            ],
        ]));

        file_put_contents($this->tar, gzencode(
            $this->entradaTar('AllDeckFiles/SneakAttack_ZNC.json', $this->mazoComandante())
            . $this->entradaTar('AllDeckFiles/ArcanisSGuile_10E.json', $this->mazoTematico())
            // Un fichero que el índice NO trae. Tiene que saltarse: `precon_file`
            // es FK de `mtg_precon` y escribirlo reventaría la ingesta.
            . $this->entradaTar('AllDeckFiles/NoIndexado_XXX.json', $this->mazoTematico())
            // Nombre no ASCII: el campo `name` de la cabecera ustar viene
            // MUTILADO y el nombre bueno sólo está en la cabecera PAX. Le pasa a
            // 11 de los 3.029 mazos reales de MTGJSON.
            . $this->entradaTar(
                'AllDeckFiles/DandânDeck_SLD.json',
                $this->mazoTematico(),
                'AllDeckFiles/Dand?nDeck_SLD.json'
            )
            . str_repeat("\0", 1024)
        ));
    }

    protected function tearDown(): void
    {
        @unlink($this->indice);
        @unlink($this->tar);
    }

    // ------------------------------------------------------------------
    // Los ficheros de prueba
    // ------------------------------------------------------------------

    private function mazoComandante(): string
    {
        return (string) json_encode([
            'data' => [
                'code'      => 'ZNC',
                'commander' => [$this->carta('uuid-comandante')],
                'mainBoard' => [
                    $this->carta('uuid-tierra', ['count' => 20]),
                    $this->carta('uuid-foil', ['isFoil' => true]),
                    $this->carta('uuid-etched', ['isEtched' => true]),
                ],
                'name'      => 'Sneak Attack',
                'tokens'    => [$this->carta('uuid-ficha', ['count' => 2])],
                'type'      => 'Commander Deck',
            ],
            'meta' => ['date' => '2026-09-11'],
        ]);
    }

    private function mazoTematico(): string
    {
        return (string) json_encode([
            'data' => [
                'code'      => '10E',
                'mainBoard' => [$this->carta('uuid-tematica', ['count' => 4])],
                'name'      => "Arcanis's Guile",
                'sideBoard' => [$this->carta('uuid-banquillo')],
                'type'      => 'Theme Deck',
            ],
            'meta' => ['date' => '2026-09-11'],
        ]);
    }

    /** @param array<string, mixed> $extra */
    private function carta(string $uuid, array $extra = []): array
    {
        return $extra + [
            'count'       => 1,
            'foreignData' => [],
            'isEtched'    => false,
            'isFoil'      => false,
            'name'        => 'Carta ' . $uuid,
            'rulings'     => [],
            'uuid'        => $uuid,
        ];
    }

    /**
     * Una entrada de tar de verdad: cabecera PAX + cabecera ustar + contenido
     * alineado a 512 bytes. MTGJSON pone la PAX delante de cada fichero y el
     * lector tiene que saltarla por su `typeflag`.
     */
    private function entradaTar(string $nombre, string $contenido, ?string $nombreUstar = null): string
    {
        $pax = $this->registroPax('path', $nombre);

        return $this->cabecera('PaxHeaders/' . basename($nombre), strlen($pax), 'x')
            . $this->rellenar($pax)
            . $this->cabecera($nombreUstar ?? $nombre, strlen($contenido))
            . $this->rellenar($contenido);
    }

    /**
     * Un registro PAX: `"<longitud> <clave>=<valor>\n"`, y la longitud se cuenta a
     * sí misma — de ahí la corrección cuando al sumarla cambia de número de
     * dígitos.
     */
    private function registroPax(string $clave, string $valor): string
    {
        $sinLongitud = strlen($clave) + strlen($valor) + 3;
        $total       = $sinLongitud + strlen((string) $sinLongitud);

        if (strlen((string) $total) !== strlen((string) $sinLongitud)) {
            $total = $sinLongitud + strlen((string) $total);
        }

        return $total . ' ' . $clave . '=' . $valor . "\n";
    }

    private function rellenar(string $datos): string
    {
        $relleno = (512 - (strlen($datos) % 512)) % 512;

        return $datos . str_repeat("\0", $relleno);
    }

    private function cabecera(string $nombre, int $tamano, string $tipo = '0'): string
    {
        $cabecera = str_pad($nombre, 100, "\0")          // name
            . str_pad('0000644', 8, "\0")                 // mode
            . str_pad('0000000', 8, "\0")                 // uid
            . str_pad('0000000', 8, "\0")                 // gid
            . sprintf('%011o', $tamano) . "\0"            // size
            . sprintf('%011o', 0) . "\0"                  // mtime
            . '        '                                  // checksum: espacios para calcularlo
            . $tipo                                       // typeflag
            . str_repeat("\0", 100)                       // linkname
            . "ustar\0" . '00'                            // magic + version
            . str_repeat("\0", 32 + 32 + 8 + 8)           // uname, gname, devmajor, devminor
            . str_repeat("\0", 155)                       // prefix
            . str_repeat("\0", 12);                       // relleno hasta 512

        $suma = array_sum((array) unpack('C*', $cabecera));

        return substr_replace($cabecera, sprintf('%06o', $suma) . "\0 ", 148, 8);
    }

    // ------------------------------------------------------------------
    // El arranque
    // ------------------------------------------------------------------

    /** @param string[] $args */
    private function ejecutar(PreconsFalsos $precons, array $args = []): int
    {
        [$codigo] = $this->ejecutarConSalida($precons, $args);

        return $codigo;
    }

    /**
     * @param  string[] $args
     * @return array{0: int, 1: string}
     */
    private function ejecutarConSalida(PreconsFalsos $precons, array $args = []): array
    {
        $comando = new DecksImportCommand(
            $precons,
            new MtgJsonDeckMapper(),
            new MtgJsonDownloader(new NullLogger(), sys_get_temp_dir()),
            new TarGzReader(),
            new NullLogger()
        );

        ob_start();
        $codigo = $comando->run(array_merge(["--index={$this->indice}", "--file={$this->tar}"], $args));
        $salida = (string) ob_get_clean();

        return [$codigo, $salida];
    }

    // ------------------------------------------------------------------
    // Fase 1 — el índice
    // ------------------------------------------------------------------

    public function testIngiereElIndiceEnteroYDevuelveCero(): void
    {
        $precons = new PreconsFalsos();

        self::assertSame(0, $this->ejecutar($precons));
        // Las tres con fileName; la cuarta entrada no tiene clave natural.
        self::assertCount(3, $precons->precons);
        self::assertSame('ZNC', $precons->precons['SneakAttack_ZNC']['set_code']);
        self::assertSame('Theme Deck', $precons->precons['ArcanisSGuile_10E']['deck_type']);
    }

    // ------------------------------------------------------------------
    // Fase 2 — las cartas
    // ------------------------------------------------------------------

    public function testSacaDelTarLasCartasConSuBoardYSuAcabado(): void
    {
        $precons = new PreconsFalsos();
        $this->ejecutar($precons);

        self::assertArrayHasKey('SneakAttack_ZNC|uuid-comandante|commander|normal', $precons->cartas);
        self::assertArrayHasKey('SneakAttack_ZNC|uuid-foil|main|foil', $precons->cartas);
        self::assertArrayHasKey('SneakAttack_ZNC|uuid-etched|main|etched', $precons->cartas);
        self::assertArrayHasKey('SneakAttack_ZNC|uuid-ficha|tokens|normal', $precons->cartas);
        self::assertArrayHasKey('ArcanisSGuile_10E|uuid-banquillo|side|normal', $precons->cartas);
        self::assertSame(20, $precons->cartas['SneakAttack_ZNC|uuid-tierra|main|normal']['count']);
    }

    /**
     * `mtg_precon_card.precon_file` es FK de `mtg_precon`: una fila de un mazo que
     * el índice no trae reventaría la ingesta entera contra MySQL.
     */
    public function testElMazoDelTarQueNoEstaEnElIndiceNiSeParsea(): void
    {
        $precons = new PreconsFalsos();
        $this->ejecutar($precons);

        $ficheros = array_unique(array_column($precons->cartas, 'precon_file'));
        sort($ficheros);

        self::assertSame(['ArcanisSGuile_10E', 'DandânDeck_SLD', 'SneakAttack_ZNC'], $ficheros);
        self::assertArrayNotHasKey('NoIndexado_XXX', $precons->conteos);
    }

    /**
     * **El nombre bueno está en la cabecera PAX, no en el campo `name` de la
     * cabecera ustar.** 11 de los 3.029 mazos de MTGJSON tienen nombre no ASCII
     * —`DandânDeck_SLD`, `魔法学院青春白書…`, `JakubŠlemr…`— y el ustar los trae
     * mutilados. Leyendo sólo el ustar, esos 11 no casan con su `fileName` del
     * índice y se quedan sin cartas EN SILENCIO: el mazo aparece en el catálogo
     * con `card_count` a 0 y nada falla.
     */
    public function testUsaElNombrePaxCuandoLaCabeceraUstarVieneMutilada(): void
    {
        $precons = new PreconsFalsos();
        $this->ejecutar($precons);

        self::assertArrayHasKey('DandânDeck_SLD|uuid-tematica|main|normal', $precons->cartas);
        self::assertSame(5, $precons->conteos['DandânDeck_SLD']);
    }

    /** `card_count` cuenta ejemplares y los tokens no cuentan: 20 + 1 + 1 + 1 = 23. */
    public function testCardCountSonLosEjemplaresSinContarLasFichas(): void
    {
        $precons = new PreconsFalsos();
        $this->ejecutar($precons);

        self::assertSame(23, $precons->conteos['SneakAttack_ZNC']);
        self::assertSame(5, $precons->conteos['ArcanisSGuile_10E']);
    }

    /**
     * Los huérfanos se IMPRIMEN. No es decorativo: un uuid que no está en
     * `mtg_printing` es un `catalog:import` pendiente, y callárselo sería ingerir
     * a medias en silencio.
     */
    public function testImprimeElNumeroDeHuerfanosAlTerminar(): void
    {
        $precons            = new PreconsFalsos();
        $precons->huerfanos = 7;

        [$codigo, $salida] = $this->ejecutarConSalida($precons);

        self::assertSame(0, $codigo);
        self::assertStringContainsString('Huérfanos', $salida);
        self::assertStringContainsString('7 filas', $salida);
        self::assertStringContainsString('catalog:import', $salida);
    }

    // ------------------------------------------------------------------
    // Idempotencia y lotes
    // ------------------------------------------------------------------

    /**
     * El criterio de aceptación del hito: relanzarlo deja los contadores
     * idénticos. Sobre MySQL eso lo consigue el `ON DUPLICATE KEY UPDATE` sobre la
     * PK de cuatro columnas; aquí se comprueba que las filas enviadas son
     * exactamente las mismas, que es la condición previa.
     */
    public function testRelanzarloNoMueveNingunContador(): void
    {
        $precons = new PreconsFalsos();

        $this->ejecutar($precons);
        $primera = $precons->contadores();
        $filas   = $precons->cartas;

        $this->ejecutar($precons);

        self::assertSame($primera, $precons->contadores());
        self::assertEquals($filas, $precons->cartas);
    }

    /**
     * Una clave repetida dentro del mismo `INSERT … ON DUPLICATE KEY UPDATE`
     * multi-fila aborta la sentencia ENTERA, no sólo la fila.
     */
    public function testNingunLoteLlevaLaClaveRepetida(): void
    {
        $precons = new PreconsFalsos();
        $this->ejecutar($precons);

        foreach ($precons->lotes['cartas'] as $lote) {
            $claves = array_map([PreconsFalsos::class, 'clave'], $lote);
            self::assertSame(count($claves), count(array_unique($claves)), 'clave de carta repetida en un lote');
        }

        foreach ($precons->lotes['precons'] as $lote) {
            $claves = array_column($lote, 'file_name');
            self::assertSame(count($claves), count(array_unique($claves)), 'file_name repetido en un lote');
        }
    }

    // ------------------------------------------------------------------
    // Flags y códigos de salida
    // ------------------------------------------------------------------

    /**
     * `--type` filtra las CARTAS, no el índice: el índice son 626 KB y dejarlo
     * incompleto no ahorra nada, pero saltarse 2.839 ficheros de mazo sí.
     */
    public function testElFiltroDeTipoSoloIngiereLasCartasDeEseTipo(): void
    {
        $precons = new PreconsFalsos();

        self::assertSame(0, $this->ejecutar($precons, ['--type=Commander Deck']));
        self::assertCount(3, $precons->precons);

        self::assertSame(
            ['SneakAttack_ZNC'],
            array_values(array_unique(array_column($precons->cartas, 'precon_file')))
        );
    }

    /**
     * Un tipo que no existe no puede terminar en éxito: el cron lo leería como
     * «catálogo de mazos actualizado».
     */
    public function testUnTipoInexistenteDevuelveCodigoDeError(): void
    {
        self::assertSame(1, $this->ejecutar(new PreconsFalsos(), ['--type=No Existe']));
    }

    public function testUnIndiceInexistenteDevuelveCodigoDeError(): void
    {
        $comando = new DecksImportCommand(
            new PreconsFalsos(),
            new MtgJsonDeckMapper(),
            new MtgJsonDownloader(new NullLogger(), sys_get_temp_dir()),
            new TarGzReader(),
            new NullLogger()
        );

        ob_start();
        $codigo = $comando->run(['--index=/no/existe/DeckList.json', "--file={$this->tar}"]);
        ob_end_clean();

        self::assertSame(1, $codigo);
    }

    public function testUnTarInexistenteDevuelveCodigoDeErrorAunqueElIndiceEntre(): void
    {
        $precons = new PreconsFalsos();

        $comando = new DecksImportCommand(
            $precons,
            new MtgJsonDeckMapper(),
            new MtgJsonDownloader(new NullLogger(), sys_get_temp_dir()),
            new TarGzReader(),
            new NullLogger()
        );

        ob_start();
        $codigo = $comando->run(["--index={$this->indice}", '--file=/no/existe/AllDeckFiles.tar.gz']);
        ob_end_clean();

        self::assertSame(1, $codigo);
        // El índice sí entró: es barato y deja el catálogo de mazos completo.
        self::assertCount(3, $precons->precons);
    }

    public function testLaAyudaNoIngiereNadaYDevuelveCero(): void
    {
        $precons = new PreconsFalsos();

        [$codigo, $salida] = $this->ejecutarConSalida($precons, ['--help']);

        self::assertSame(0, $codigo);
        self::assertStringContainsString('decks:import', $salida);
        self::assertSame([], $precons->precons);
    }
}
