<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Collection\Finish;
use App\Infrastructure\Mtgjson\MtgJsonDeckMapper;
use PHPUnit\Framework\TestCase;

/**
 * El parseo de UN fichero de mazo y el mapeo del acabado.
 *
 * El fixture es un mazo **recortado a mano**, no los 571 KB reales: de cada carta
 * se dejan los campos que el mapper mira y dos de relleno (`rulings`,
 * `foreignData`) que representan los 47 que hay que atravesar sin materializar.
 * Un fichero real aquí convertiría el test en una descarga de MTGJSON.
 */
final class MtgJsonDeckMapperTest extends TestCase
{
    private MtgJsonDeckMapper $mapper;

    protected function setUp(): void
    {
        $this->mapper = new MtgJsonDeckMapper();
    }

    /**
     * Un fichero de mazo con las seis zonas que MTGJSON publica.
     *
     * El orden de las claves es el de MTGJSON: ALFABÉTICO. Importa, porque es la
     * razón por la que el filtro por tipo sale del índice y no de aquí —`type`
     * va después de `mainBoard`—.
     */
    private function ficheroDeMazo(): string
    {
        $carta = static fn (string $uuid, array $extra = []): array => $extra + [
            'count'       => 1,
            'foreignData' => [],
            'isEtched'    => false,
            'isFoil'      => false,
            'name'        => 'Carta ' . $uuid,
            'rulings'     => [],
            'uuid'        => $uuid,
        ];

        return (string) json_encode([
            'data' => [
                'code'             => 'ZNC',
                'commander'        => [$carta('uuid-comandante')],
                'displayCommander' => [$carta('uuid-comandante')],
                'mainBoard'        => [
                    $carta('uuid-tierra', ['count' => 20]),
                    $carta('uuid-foil', ['isFoil' => true]),
                    $carta('uuid-etched', ['isEtched' => true]),
                    // Sin uuid: no hay nada que referenciar y no debe generar fila.
                    ['count' => 1, 'name' => 'Rota'],
                ],
                'name'             => 'Sneak Attack',
                'planes'           => [$carta('uuid-plano')],
                'releaseDate'      => '2020-09-25',
                'schemes'          => [$carta('uuid-esquema')],
                'sideBoard'        => [$carta('uuid-banquillo')],
                'source'           => 'https://magic.wizards.com/precon',
                'tokens'           => [$carta('uuid-ficha', ['count' => 2])],
                'type'             => 'Commander Deck',
            ],
            'meta' => ['date' => '2026-09-11', 'version' => '5.3.0'],
        ], JSON_UNESCAPED_SLASHES);
    }

    // ------------------------------------------------------------------
    // El índice
    // ------------------------------------------------------------------

    public function testLaFilaDelIndiceUsaFileNameComoClaveNatural(): void
    {
        $fila = $this->mapper->precon([
            'code'        => 'znc',
            'fileName'    => 'SneakAttack_ZNC',
            'name'        => 'Sneak Attack',
            'releaseDate' => '2020-09-25',
            'source'      => 'https://magic.wizards.com/precon',
            'type'        => 'Commander Deck',
        ]);

        self::assertSame('SneakAttack_ZNC', $fila['file_name']);
        self::assertSame('ZNC', $fila['set_code']);
        self::assertSame('Commander Deck', $fila['deck_type']);
        // MTGJSON lo llama `source`; la columna, `source_url`.
        self::assertSame('https://magic.wizards.com/precon', $fila['source_url']);
        // `card_count` NO lo pone el índice: lo mueve la fase 2.
        self::assertArrayNotHasKey('card_count', $fila);
    }

    /** Sin `fileName` no hay clave natural: `name` no vale, se repite entre ediciones. */
    public function testUnaEntradaSinFileNameSeDescarta(): void
    {
        self::assertNull($this->mapper->precon(['code' => 'ZNC', 'name' => 'Sneak Attack']));
    }

    public function testLaFechaYLaFuenteVaciasSonNullYNoCadenaVacia(): void
    {
        $fila = $this->mapper->precon(['fileName' => 'X_ABC', 'code' => 'ABC', 'name' => 'X', 'type' => 'Theme Deck']);

        self::assertNull($fila['release_date']);
        self::assertNull($fila['source_url']);
    }

    // ------------------------------------------------------------------
    // isFoil / isEtched → finish
    // ------------------------------------------------------------------

    public function testSinFoilNiEtchedElAcabadoEsNormal(): void
    {
        self::assertSame(Finish::Normal, $this->mapper->finish(['isFoil' => false, 'isEtched' => false]));
        self::assertSame(Finish::Normal, $this->mapper->finish([]));
    }

    public function testIsFoilMapeaAFoil(): void
    {
        self::assertSame(Finish::Foil, $this->mapper->finish(['isFoil' => true, 'isEtched' => false]));
    }

    /**
     * **`isEtched` es un campo aparte, no un foil raro.** Tiene precio propio en
     * `mtg_price_daily.finish` y columna propia en `mtg_printing.has_etched`:
     * mapearlo a `foil` falsearía el valor de todo lo posterior a Commander
     * Legends.
     */
    public function testIsEtchedMapeaAEtchedYNoAFoil(): void
    {
        self::assertSame(Finish::Etched, $this->mapper->finish(['isFoil' => false, 'isEtched' => true]));
    }

    /** MTGJSON marca los dos a la vez en algunas entradas; gana `etched`. */
    public function testConLosDosMarcadosGanaEtched(): void
    {
        self::assertSame(Finish::Etched, $this->mapper->finish(['isFoil' => true, 'isEtched' => true]));
    }

    // ------------------------------------------------------------------
    // El fichero de mazo
    // ------------------------------------------------------------------

    public function testSacaLasSeisZonasConSuBoard(): void
    {
        $filas = $this->mapper->cartas($this->ficheroDeMazo(), 'SneakAttack_ZNC');

        $porUuid = array_column($filas, 'board', 'printing_uuid');

        self::assertSame('main', $porUuid['uuid-tierra']);
        self::assertSame('side', $porUuid['uuid-banquillo']);
        self::assertSame('commander', $porUuid['uuid-comandante']);
        self::assertSame('planes', $porUuid['uuid-plano']);
        self::assertSame('schemes', $porUuid['uuid-esquema']);
        self::assertSame('tokens', $porUuid['uuid-ficha']);
    }

    /** `displayCommander` es cosmético —qué carta enseña la caja— y no una zona. */
    public function testDisplayCommanderNoGeneraFilas(): void
    {
        $filas = $this->mapper->cartas($this->ficheroDeMazo(), 'SneakAttack_ZNC');

        $comandante = array_filter($filas, static fn (array $f): bool => $f['printing_uuid'] === 'uuid-comandante');

        self::assertCount(1, $comandante);
    }

    public function testCadaFilaLlevaElFicheroDelMazoYSuAcabado(): void
    {
        $filas   = $this->mapper->cartas($this->ficheroDeMazo(), 'SneakAttack_ZNC');
        $porUuid = [];

        foreach ($filas as $fila) {
            self::assertSame('SneakAttack_ZNC', $fila['precon_file']);
            $porUuid[$fila['printing_uuid']] = $fila;
        }

        self::assertSame('normal', $porUuid['uuid-tierra']['finish']);
        self::assertSame('foil', $porUuid['uuid-foil']['finish']);
        self::assertSame('etched', $porUuid['uuid-etched']['finish']);
        self::assertSame(20, $porUuid['uuid-tierra']['count']);
    }

    public function testUnaCartaSinUuidNoGeneraFila(): void
    {
        $filas = $this->mapper->cartas($this->ficheroDeMazo(), 'SneakAttack_ZNC');

        self::assertCount(8, $filas);
        self::assertNotContains('', array_column($filas, 'printing_uuid'));
    }

    /**
     * `(precon_file, printing_uuid, board, finish)` es la PK de la tabla, y una
     * clave repetida dentro del mismo `INSERT … ON DUPLICATE KEY UPDATE`
     * multi-fila aborta la sentencia ENTERA: se perdería el mazo completo.
     */
    public function testDeduplicaLaClaveSumandoLosCount(): void
    {
        $json = (string) json_encode([
            'data' => [
                'mainBoard' => [
                    ['uuid' => 'uuid-isla', 'count' => 5, 'isFoil' => false, 'isEtched' => false],
                    ['uuid' => 'uuid-isla', 'count' => 3, 'isFoil' => false, 'isEtched' => false],
                    // Mismo uuid pero foil: es otra fila legítima, no una repetida.
                    ['uuid' => 'uuid-isla', 'count' => 1, 'isFoil' => true,  'isEtched' => false],
                ],
            ],
        ]);

        $filas = $this->mapper->cartas($json, 'Prueba_ABC');
        $claves = array_map(
            static fn (array $f): string => $f['printing_uuid'] . '|' . $f['board'] . '|' . $f['finish'],
            $filas
        );

        self::assertSame(count($claves), count(array_unique($claves)));
        self::assertCount(2, $filas);
        self::assertSame(8, $filas[0]['count']);
    }

    /**
     * `card_count` cuenta ejemplares, no filas, y **los tokens no cuentan**: un
     * token se genera, no se compra (`Board::esPoseible()`).
     */
    public function testLosEjemplaresNoCuentanLosTokens(): void
    {
        $filas = $this->mapper->cartas($this->ficheroDeMazo(), 'SneakAttack_ZNC');

        // 20 tierras + foil + etched + comandante + banquillo + plano + esquema = 26.
        // Las 2 fichas quedan fuera.
        self::assertSame(26, $this->mapper->ejemplares($filas));
    }
}
