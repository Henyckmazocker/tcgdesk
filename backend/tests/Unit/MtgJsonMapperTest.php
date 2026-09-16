<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Infrastructure\Mtgjson\MtgJsonMapper;
use PHPUnit\Framework\TestCase;

/**
 * El mapper es la única pieza de la ingesta que no habla con MySQL, y por tanto
 * la única que se puede testear sin base de datos. Lo que se protege aquí son las
 * cuatro cosas que, si se rompen, dejan el catálogo mal sin que nada falle:
 * las traducciones, el filtro de caras, los acabados y las legalidades.
 */
final class MtgJsonMapperTest extends TestCase
{
    private MtgJsonMapper $mapper;

    protected function setUp(): void
    {
        $this->mapper = new MtgJsonMapper();
    }

    /**
     * Carta de doble cara tal como la publica MTGJSON: dos objetos con uuid
     * distinto, el MISMO scryfallId, el mismo oracleId y el nombre completo con
     * ' // ' en ambos. Medido sobre el set ISD.
     *
     * @return array{0: array<string, mixed>, 1: array<string, mixed>}
     */
    private function carasDeCloisteredYouth(): array
    {
        $comun = [
            'name'        => 'Cloistered Youth // Unholy Fiend',
            'layout'      => 'transform',
            'setCode'     => 'ISD',
            'number'      => '97',
            'rarity'      => 'uncommon',
            'finishes'    => ['nonfoil', 'foil'],
            'identifiers' => [
                'scryfallId'       => 'f8b8f0b4-0000-0000-0000-000000000001',
                'scryfallOracleId' => '7552a9b4-0000-0000-0000-000000000002',
            ],
        ];

        $caraA = $comun + [
            'uuid'         => 'eb91f1bf-0000-0000-0000-00000000000a',
            'side'         => 'a',
            'faceName'     => 'Cloistered Youth',
            'text'         => 'At the beginning of your end step, transform Cloistered Youth.',
            'otherFaceIds' => ['ccab0ddd-0000-0000-0000-00000000000b'],
            'foreignData'  => [
                // El español trae `identifiers` con su propio `scryfallId`, que
                // es como MTGJSON lo publica de verdad; el japonés no lo trae, y
                // ese es el NULL legítimo que cae al inglés.
                [
                    'language'    => 'Spanish',
                    'name'        => 'Joven enclaustrada // Demonio impío',
                    'identifiers' => [
                        'multiverseId' => '147645',
                        'scryfallId'   => '1e59f85a-0000-0000-0000-0000000000es',
                    ],
                ],
                ['language' => 'Japanese', 'name' => '修道院の若者 // 不浄の悪鬼'],
                [
                    'language'    => 'Chinese Traditional',
                    'name'        => '幽禁少女 // 瀆聖邪鬼',
                    'identifiers' => ['scryfallId' => '1e59f85a-0000-0000-0000-0000000000zh'],
                ],
            ],
        ];

        $caraB = $comun + [
            'uuid'         => 'ccab0ddd-0000-0000-0000-00000000000b',
            'side'         => 'b',
            'faceName'     => 'Unholy Fiend',
            'text'         => 'Whenever Unholy Fiend attacks, defending player loses 1 life.',
            'otherFaceIds' => ['eb91f1bf-0000-0000-0000-00000000000a'],
        ];

        return [$caraA, $caraB];
    }

    // ------------------------------------------------------------------
    // Caras: solo entra la frontal
    // ------------------------------------------------------------------

    public function testSoloSeIngiereLaCaraFrontal(): void
    {
        [$caraA, $caraB] = $this->carasDeCloisteredYouth();

        self::assertTrue($this->mapper->esCaraIngerible($caraA));
        self::assertFalse($this->mapper->esCaraIngerible($caraB));
    }

    public function testLaCartaDeUnaSolaCaraNoTieneSideYSeIngiere(): void
    {
        self::assertTrue($this->mapper->esCaraIngerible(['uuid' => 'x', 'name' => 'Delver']));
    }

    /**
     * La razón de descartar la cara b: las dos comparten scryfallId y
     * mtg_printing.uq_scryfall es único. Si este test falla, la ingesta completa
     * revienta con una violación de clave.
     */
    public function testLasDosCarasCompartenScryfallIdLoQueObligaADescartarUna(): void
    {
        [$caraA, $caraB] = $this->carasDeCloisteredYouth();

        $a = $this->mapper->printing($caraA, 'ISD');
        $b = $this->mapper->printing($caraB, 'ISD');

        self::assertSame($a['scryfall_id'], $b['scryfall_id']);
        self::assertNotSame($a['uuid'], $b['uuid']);
    }

    public function testElNombreDeLaCaraFrontalIncluyeLasDosCarasParaQueLaBusquedaLasEncuentre(): void
    {
        [$caraA] = $this->carasDeCloisteredYouth();

        $fila = $this->mapper->card($caraA);

        self::assertSame('Cloistered Youth // Unholy Fiend', $fila['name']);
    }

    public function testElTextoDeLasCarasTraserasSeConcatena(): void
    {
        [$caraA, $caraB] = $this->carasDeCloisteredYouth();

        $fila = $this->mapper->card($caraA, [$caraB]);

        self::assertStringContainsString('transform Cloistered Youth', $fila['oracle_text']);
        self::assertStringContainsString('defending player loses 1 life', $fila['oracle_text']);
    }

    // ------------------------------------------------------------------
    // foreignData → mtg_printing_localized
    // ------------------------------------------------------------------

    public function testExtraeUnaFilaPorIdioma(): void
    {
        [$caraA] = $this->carasDeCloisteredYouth();

        $filas = $this->mapper->localized($caraA);

        self::assertCount(3, $filas);
        self::assertSame(
            ['Spanish', 'Japanese', 'Chinese Traditional'],
            array_column($filas, 'language')
        );
    }

    /**
     * name_cjk es la columna con parser ngram. Si se rellena para el español
     * engorda el índice sin motivo; si NO se rellena para el japonés, buscar en
     * japonés devuelve cero resultados — que es el fallo que motivó la columna.
     */
    public function testSoloLosIdiomasSinEspaciosPueblanNameCjk(): void
    {
        [$caraA] = $this->carasDeCloisteredYouth();

        $porIdioma = [];
        foreach ($this->mapper->localized($caraA) as $fila) {
            $porIdioma[$fila['language']] = $fila['name_cjk'];
        }

        self::assertNull($porIdioma['Spanish']);
        self::assertSame('修道院の若者 // 不浄の悪鬼', $porIdioma['Japanese']);
        self::assertSame('幽禁少女 // 瀆聖邪鬼', $porIdioma['Chinese Traditional']);
    }

    /**
     * **`identifiers.scryfallId` de cada traducción se ingiere** — el enganche
     * del M6.
     *
     * Es la imagen POR IDIOMA, y es lo que hace que el escáner siembre el índice
     * ORB con la carta que el usuario tiene en la mano en vez de con su versión
     * inglesa. Si alguien toca este bucle y se lo lleva por delante, cada
     * reimportación dejará las cartas nuevas sin id localizado **en silencio**,
     * exactamente como pasó con `name_normalized`.
     */
    public function testCadaTraduccionSeLlevaSuScryfallIdCuandoMtgjsonLoTrae(): void
    {
        [$caraA] = $this->carasDeCloisteredYouth();

        $porIdioma = [];
        foreach ($this->mapper->localized($caraA) as $fila) {
            $porIdioma[$fila['language']] = $fila['scryfall_id'];
        }

        self::assertSame('1e59f85a-0000-0000-0000-0000000000es', $porIdioma['Spanish']);
        self::assertSame('1e59f85a-0000-0000-0000-0000000000zh', $porIdioma['Chinese Traditional']);
        self::assertNull(
            $porIdioma['Japanese'],
            'MTGJSON no publica id para toda traducción: eso es un NULL legítimo, no un fallo.'
        );
    }

    /**
     * El id localizado **no es el de `mtg_printing`**.
     *
     * Escribir el inglés en las diez filas sería peor que dejarlas a NULL: la
     * caída al inglés dejaría de verse y el escáner seguiría sembrando la imagen
     * equivocada creyendo que tiene la buena.
     */
    public function testElIdLocalizadoNoEsElDeLaImpresion(): void
    {
        [$caraA] = $this->carasDeCloisteredYouth();

        $printing = $this->mapper->printing($caraA, 'ISD');
        $filas    = $this->mapper->localized($caraA);

        self::assertSame('f8b8f0b4-0000-0000-0000-000000000001', $printing['scryfall_id']);

        foreach ($filas as $fila) {
            self::assertNotSame($printing['scryfall_id'], $fila['scryfall_id']);
        }
    }

    public function testElIdiomaNoSeTruncaAunqueMidaMasDe16Caracteres(): void
    {
        [$caraA] = $this->carasDeCloisteredYouth();

        $idiomas = array_column($this->mapper->localized($caraA), 'language');

        // 19 caracteres: no cabía en el VARCHAR(16) original de la tabla.
        self::assertContains('Chinese Traditional', $idiomas);
    }

    public function testLaCartaSinTraduccionesNoDaFilas(): void
    {
        self::assertSame([], $this->mapper->localized(['uuid' => 'x']));
    }

    // ------------------------------------------------------------------
    // finishes → has_foil / has_nonfoil / has_etched
    // ------------------------------------------------------------------

    public function testLosTresAcabadosSalenDelArrayFinishes(): void
    {
        $card = [
            'uuid'        => 'u1',
            'name'        => 'Sol Ring',
            'rarity'      => 'uncommon',
            'number'      => '263',
            'finishes'    => ['nonfoil', 'etched'],
            'identifiers' => ['scryfallOracleId' => 'o1'],
        ];

        $fila = $this->mapper->printing($card, 'C21');

        self::assertSame(1, $fila['has_nonfoil']);
        self::assertSame(0, $fila['has_foil']);
        self::assertSame(1, $fila['has_etched']);
    }

    public function testLaRarezaFueraDelEnumCaeASpecialEnVezDeAbortarElSet(): void
    {
        $card = [
            'uuid'        => 'u1',
            'name'        => 'Big Card',
            'rarity'      => 'oversized',
            'number'      => '1',
            'identifiers' => ['scryfallOracleId' => 'o1'],
        ];

        self::assertSame('special', $this->mapper->printing($card, 'OVR')['rarity']);
    }

    // ------------------------------------------------------------------
    // Sin oracle id no hay clave primaria posible
    // ------------------------------------------------------------------

    public function testLaCartaSinOracleIdSeDescartaEnVezDeRomperLaFk(): void
    {
        $card = ['uuid' => 'u1', 'name' => 'Rara', 'identifiers' => []];

        self::assertNull($this->mapper->card($card));
        self::assertNull($this->mapper->printing($card, 'XXX'));
        self::assertSame([], $this->mapper->legalities($card));
    }

    // ------------------------------------------------------------------
    // legalities → mtg_legality
    // ------------------------------------------------------------------

    public function testLasLegalidadesSeNormalizanAlEnumDeLaTabla(): void
    {
        $card = [
            'uuid'        => 'u1',
            'identifiers' => ['scryfallOracleId' => 'o1'],
            'legalities'  => [
                'commander' => 'Legal',
                'standard'  => 'Not Legal',
                'vintage'   => 'Restricted',
                'legacy'    => 'Banned',
            ],
        ];

        $porFormato = array_column($this->mapper->legalities($card), 'status', 'format');

        self::assertSame([
            'commander' => 'legal',
            'standard'  => 'not_legal',
            'vintage'   => 'restricted',
            'legacy'    => 'banned',
        ], $porFormato);
    }

    public function testUnEstadoDesconocidoSeIgnoraEnVezDeRomperElEnum(): void
    {
        $card = [
            'uuid'        => 'u1',
            'identifiers' => ['scryfallOracleId' => 'o1'],
            'legalities'  => ['formato_nuevo' => 'Sometimes'],
        ];

        self::assertSame([], $this->mapper->legalities($card));
    }

    // ------------------------------------------------------------------
    // Índice de nombres (M2 del Plan - Importación de Colecciones)
    // ------------------------------------------------------------------

    /**
     * La ingesta escribe `name_normalized` en cada pasada.
     *
     * No es un adorno del backfill: `catalog:import` es idempotente y se relanza
     * cada vez que sale un set. Sin esta línea, cada reimportación metería las
     * cartas nuevas con la columna a NULL y esas cartas dejarían de resolverse
     * por nombre **sin que nada fallara**, que es justo el fallo silencioso que
     * el plan existe para evitar.
     */
    public function testCadaCartaSaleDeLaIngestaConSuClaveNormalizada(): void
    {
        $fila = $this->mapper->card([
            'name'        => "Lim-D\u{fb}l's Vault",
            'identifiers' => ['scryfallOracleId' => 'oracle-vault'],
        ]);

        self::assertSame('lim duls vault', $fila['name_normalized']);
    }

    /** Y con la clave del resolvedor, blancos incluidos: la misma que el paso 3. */
    public function testLaClaveDeLaIngestaConservaLosBlancosDeLasUnSets(): void
    {
        $fila = $this->mapper->card([
            'name'        => '_____ Goblin',
            'identifiers' => ['scryfallOracleId' => 'oracle-blanco'],
        ]);

        self::assertSame('_____ goblin', $fila['name_normalized']);
    }

    // ------------------------------------------------------------------
    // Set
    // ------------------------------------------------------------------

    public function testElSetSeMapeaConSusCamposOpcionalesANull(): void
    {
        $fila = $this->mapper->set(['code' => 'ISD', 'name' => 'Innistrad']);

        self::assertSame('ISD', $fila['code']);
        self::assertNull($fila['release_date']);
        self::assertSame(0, $fila['is_online_only']);
    }
}
