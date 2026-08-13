<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Cli\Commands\CatalogImportCommand;
use App\Domain\Repository\CatalogRepositoryInterface;
use App\Infrastructure\Mtgjson\MtgJsonDownloader;
use App\Infrastructure\Mtgjson\MtgJsonMapper;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Repositorio de mentira que acumula lo que le mandan.
 *
 * Con él se puede comprobar lo que MySQL no dejaría ver hasta que fuese tarde:
 * qué filas exactas envía el importador, si las deduplica antes de mandarlas, y
 * si dos ejecuciones seguidas producen lo mismo.
 */
class CatalogoFalso implements CatalogRepositoryInterface
{
    /** @var list<array<string, mixed>> */
    public array $sets = [];
    /** @var list<array<string, mixed>> */
    public array $cards = [];
    /** @var list<array<string, mixed>> */
    public array $printings = [];
    /** @var list<array<string, mixed>> */
    public array $localized = [];
    /** @var list<array<string, mixed>> */
    public array $legalities = [];

    /**
     * Los lotes tal como se enviaron, sin aplanar.
     *
     * La restricción de MySQL es **por sentencia**: el mismo oracle_id en dos
     * sets distintos son dos INSERT separados y es correcto, pero repetido
     * dentro de un mismo lote aborta la sentencia entera.
     *
     * @var array<string, list<list<array<string, mixed>>>>
     */
    public array $lotes = ['cards' => [], 'printings' => [], 'localized' => [], 'legalities' => []];

    public function upsertSet(array $set): void
    {
        $this->sets[] = $set;
    }

    public function upsertCards(array $filas): int
    {
        $this->cards           = array_merge($this->cards, $filas);
        $this->lotes['cards'][] = $filas;
        return count($filas);
    }

    public function upsertPrintings(array $filas): int
    {
        $this->printings            = array_merge($this->printings, $filas);
        $this->lotes['printings'][] = $filas;
        return count($filas);
    }

    public function upsertLocalized(array $filas): int
    {
        $this->localized            = array_merge($this->localized, $filas);
        $this->lotes['localized'][] = $filas;
        return count($filas);
    }

    public function upsertLegalities(array $filas): int
    {
        $this->legalities            = array_merge($this->legalities, $filas);
        $this->lotes['legalities'][] = $filas;
        return count($filas);
    }

    public function contadores(): array
    {
        return [
            'mtg_set'                => count($this->sets),
            'mtg_card'               => count($this->cards),
            'mtg_printing'           => count($this->printings),
            'mtg_printing_localized' => count($this->localized),
            'mtg_legality'           => count($this->legalities),
        ];
    }
}

/**
 * Lo que protege este test es el criterio de aceptación del hito: que la ingesta
 * sea **idempotente** y que no mande a MySQL lotes con la clave repetida, que es
 * el fallo que aborta un `INSERT ... ON DUPLICATE KEY UPDATE` multi-fila.
 */
final class CatalogImportCommandTest extends TestCase
{
    private string $fichero;

    protected function setUp(): void
    {
        // Formato AllPrintings: data es un mapa código de set → set.
        // Dos sets que reimprimen la misma carta (mismo oracleId, uuid distinto),
        // una carta de doble cara, y una carta sin oracleId que debe descartarse.
        $sol = fn (string $uuid, string $sf) => [
            'uuid'        => $uuid,
            'name'        => 'Sol Ring',
            'number'      => '263',
            'rarity'      => 'uncommon',
            'finishes'    => ['nonfoil', 'foil'],
            'legalities'  => ['commander' => 'Legal'],
            'identifiers' => ['scryfallId' => $sf, 'scryfallOracleId' => 'oracle-sol'],
            'foreignData' => [
                ['language' => 'Spanish',  'name' => 'Anillo solar'],
                ['language' => 'Japanese', 'name' => '太陽の指輪'],
            ],
        ];

        $datos = [
            'meta' => ['date' => '2026-08-13', 'version' => '5.3.0'],
            'data' => [
                'C21' => [
                    'code'         => 'C21',
                    'name'         => 'Commander 2021',
                    'releaseDate'  => '2021-04-23',
                    'type'         => 'commander',
                    'totalSetSize' => 2,
                    'cards'        => [
                        $sol('uuid-sol-c21', 'sf-sol-c21'),
                        // Arte alternativo: MISMO oracleId dentro del mismo set.
                        // Si no se deduplica, MySQL rechaza el lote entero.
                        $sol('uuid-sol-c21-alt', 'sf-sol-c21-alt'),
                        ['uuid' => 'uuid-sin-oracle', 'name' => 'Rota', 'number' => '9', 'rarity' => 'rare', 'identifiers' => []],
                    ],
                ],
                'ISD' => [
                    'code'         => 'ISD',
                    'name'         => 'Innistrad',
                    'releaseDate'  => '2011-09-30',
                    'type'         => 'expansion',
                    'totalSetSize' => 3,
                    'cards'        => [
                        // Reimpresión en otro set: mismo oracleId otra vez.
                        $sol('uuid-sol-isd', 'sf-sol-isd'),
                        [
                            'uuid'         => 'uuid-cloistered-a',
                            'side'         => 'a',
                            'layout'       => 'transform',
                            'name'         => 'Cloistered Youth // Unholy Fiend',
                            'number'       => '97',
                            'rarity'       => 'uncommon',
                            'text'         => 'Cara frontal.',
                            'otherFaceIds' => ['uuid-cloistered-b'],
                            'identifiers'  => ['scryfallId' => 'sf-cloistered', 'scryfallOracleId' => 'oracle-cloistered'],
                        ],
                        [
                            'uuid'         => 'uuid-cloistered-b',
                            'side'         => 'b',
                            'layout'       => 'transform',
                            'name'         => 'Cloistered Youth // Unholy Fiend',
                            'number'       => '97',
                            'rarity'       => 'uncommon',
                            'text'         => 'Cara trasera.',
                            'otherFaceIds' => ['uuid-cloistered-a'],
                            // El mismo scryfallId que la cara a: si entrase, violaría uq_scryfall.
                            'identifiers'  => ['scryfallId' => 'sf-cloistered', 'scryfallOracleId' => 'oracle-cloistered'],
                        ],
                    ],
                ],
            ],
        ];

        $this->fichero = tempnam(sys_get_temp_dir(), 'allprintings') . '.json';
        file_put_contents($this->fichero, json_encode($datos, JSON_UNESCAPED_UNICODE));
    }

    protected function tearDown(): void
    {
        @unlink($this->fichero);
    }

    private function ejecutar(CatalogoFalso $catalogo, array $args = []): int
    {
        $comando = new CatalogImportCommand(
            $catalogo,
            new MtgJsonMapper(),
            new MtgJsonDownloader(new NullLogger(), sys_get_temp_dir()),
            new NullLogger()
        );

        ob_start();
        $codigo = $comando->run(array_merge(["--file={$this->fichero}"], $args));
        ob_end_clean();

        return $codigo;
    }

    public function testIngiereLosDosSetsYDevuelveCero(): void
    {
        $catalogo = new CatalogoFalso();

        self::assertSame(0, $this->ejecutar($catalogo));
        self::assertCount(2, $catalogo->sets);
    }

    /**
     * Tres printings: Sol Ring ×2 en C21, Sol Ring en ISD y la cara frontal de
     * Cloistered Youth. La cara b y la carta sin oracleId no entran.
     */
    public function testDescartaLaCaraTraseraYLaCartaSinOracleId(): void
    {
        $catalogo = new CatalogoFalso();
        $this->ejecutar($catalogo);

        $uuids = array_column($catalogo->printings, 'uuid');

        self::assertContains('uuid-cloistered-a', $uuids);
        self::assertNotContains('uuid-cloistered-b', $uuids);
        self::assertNotContains('uuid-sin-oracle', $uuids);
        self::assertCount(4, $uuids);
    }

    /**
     * Dos printings del mismo set comparten oracleId (arte alternativo). Si el
     * importador los manda como dos filas en el MISMO lote,
     * `INSERT ... ON DUPLICATE KEY UPDATE` falla con "Duplicate entry" y se
     * pierde el set entero.
     *
     * Entre sets distintos sí se repite, y debe repetirse: son sentencias
     * separadas y es justo lo que el upsert existe para absorber.
     */
    public function testDeduplicaOracleIdDentroDelMismoLote(): void
    {
        $catalogo = new CatalogoFalso();
        $this->ejecutar($catalogo);

        // C21 tiene dos printings de Sol Ring y manda una sola fila de carta.
        self::assertSame(['oracle-sol'], array_column($catalogo->lotes['cards'][0], 'oracle_id'));

        foreach ($catalogo->lotes['cards'] as $lote) {
            $claves = array_column($lote, 'oracle_id');
            self::assertSame(count($claves), count(array_unique($claves)), 'oracle_id repetido en un lote');
        }

        foreach ($catalogo->lotes['legalities'] as $lote) {
            $claves = array_map(fn (array $f) => $f['oracle_id'] . '|' . $f['format'], $lote);
            self::assertSame(count($claves), count(array_unique($claves)), 'legalidad repetida en un lote');
        }

        // El mismo oracle_id sí aparece en el lote de C21 y en el de ISD.
        self::assertContains('oracle-sol', array_column($catalogo->lotes['cards'][1], 'oracle_id'));
    }

    public function testLocalizadosSinClaveRepetidaPorPrintingEIdiomaEnCadaLote(): void
    {
        $catalogo = new CatalogoFalso();
        $this->ejecutar($catalogo);

        foreach ($catalogo->lotes['localized'] as $lote) {
            $claves = array_map(fn (array $f) => $f['printing_uuid'] . '|' . $f['language'], $lote);
            self::assertSame(count($claves), count(array_unique($claves)));
        }

        $todas = array_map(
            fn (array $f) => $f['printing_uuid'] . '|' . $f['language'],
            $catalogo->localized
        );
        self::assertContains('uuid-sol-c21|Japanese', $todas);
    }

    /**
     * La prueba de la idempotencia: dos ejecuciones seguidas mandan exactamente
     * las mismas filas. Sobre MySQL eso es lo que hace que los contadores no se
     * muevan en la segunda pasada.
     */
    public function testDosEjecucionesSeguidasProducenLasMismasFilas(): void
    {
        $primera = new CatalogoFalso();
        $this->ejecutar($primera);

        $segunda = new CatalogoFalso();
        $this->ejecutar($segunda);

        self::assertEquals($primera->sets, $segunda->sets);
        self::assertEquals($primera->cards, $segunda->cards);
        self::assertEquals($primera->printings, $segunda->printings);
        self::assertEquals($primera->localized, $segunda->localized);
        self::assertEquals($primera->legalities, $segunda->legalities);
    }

    public function testElFiltroDeSetIngiereSoloEseSet(): void
    {
        $catalogo = new CatalogoFalso();

        self::assertSame(0, $this->ejecutar($catalogo, ['--set=isd']));
        self::assertCount(1, $catalogo->sets);
        self::assertSame('ISD', $catalogo->sets[0]['code']);
    }

    /**
     * Un set que no existe no puede terminar en éxito: el cron lo leería como
     * "catálogo actualizado" y nadie se enteraría de que no se ingirió nada.
     */
    public function testUnSetInexistenteDevuelveCodigoDeError(): void
    {
        $catalogo = new CatalogoFalso();

        self::assertSame(1, $this->ejecutar($catalogo, ['--set=NOEXISTE']));
        self::assertSame([], $catalogo->sets);
    }

    public function testUnFicheroInexistenteDevuelveCodigoDeError(): void
    {
        $comando = new CatalogImportCommand(
            new CatalogoFalso(),
            new MtgJsonMapper(),
            new MtgJsonDownloader(new NullLogger(), sys_get_temp_dir()),
            new NullLogger()
        );

        ob_start();
        $codigo = $comando->run(['--file=/no/existe/nada.json']);
        ob_end_clean();

        self::assertSame(1, $codigo);
    }
}
