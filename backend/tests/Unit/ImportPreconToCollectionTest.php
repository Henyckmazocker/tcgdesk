<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Application\UseCase\ImportPreconToCollection;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Doubles\ColeccionFalsa;
use Tests\Unit\Doubles\MazosFalsos;
use Tests\Unit\Doubles\PreconesFalsos;
use Tests\Unit\Doubles\TransaccionesFalsas;

/**
 * El botón de un clic.
 *
 * Lo que de verdad hay que probarle es la promesa del hito: **comprar dos veces
 * la misma caja suma cantidades y deja un mazo por clic, sin filas duplicadas en
 * la colección**. Se puede probar con dobles porque `ColeccionFalsa` reproduce el
 * `UNIQUE KEY` de seis columnas y la suma del `ON DUPLICATE KEY UPDATE`, y
 * `MazosFalsos` el `uq_deck_card`: que es exactamente lo que lo sostiene en
 * MySQL.
 *
 * Lo demás que se comprueba aquí son las cuatro reglas que no se ven mirando el
 * SQL: el mazo nace `built`, el `format` solo se deduce cuando es obvio, los
 * `tokens` llegan al mazo y no a la colección, y las huérfanas se saltan y se
 * cuentan en vez de tirar la transacción por clave foránea.
 */
final class ImportPreconToCollectionTest extends TestCase
{
    private const USUARIO = 7;

    private PreconesFalsos $precons;
    private ColeccionFalsa $coleccion;
    private MazosFalsos $mazos;
    private TransaccionesFalsas $transacciones;
    private ImportPreconToCollection $useCase;

    protected function setUp(): void
    {
        $this->precons       = new PreconesFalsos();
        $this->coleccion     = new ColeccionFalsa();
        $this->mazos         = new MazosFalsos($this->coleccion);
        $this->transacciones = new TransaccionesFalsas();

        $this->useCase = new ImportPreconToCollection(
            $this->precons,
            $this->coleccion,
            $this->mazos,
            $this->transacciones
        );
    }

    /** Una caja del catálogo, con la forma que devuelve el repositorio real. */
    private function precon(string $fileName, string $deckType = 'Commander Deck'): void
    {
        $this->precons->precons[] = [
            'fileName'    => $fileName,
            'name'        => 'Sneak Attack',
            'deckType'    => $deckType,
            'setCode'     => 'ZNC',
            'setName'     => 'Zendikar Rising Commander',
            'releaseDate' => '2020-09-25',
            'cardCount'   => 100,
        ];
    }

    /** @return array<string, mixed> Una línea de `cartas()`, con su `known` */
    private function linea(
        string $uuid,
        int $count = 1,
        string $board = 'main',
        string $finish = 'normal',
        bool $known = true
    ): array {
        return [
            'printingUuid' => $uuid,
            'board'        => $board,
            'finish'       => $finish,
            'count'        => $count,
            'known'        => $known,
            'name'         => $known ? 'Carta ' . $uuid : null,
            'priceEur'     => $known ? 1.5 : null,
        ];
    }

    public function testUnClicDejaLaCajaEnLaColeccionYElMazoMontado(): void
    {
        $this->precon('SneakAttack_ZNC');
        $this->precons->cartas['SneakAttack_ZNC'] = [
            $this->linea('uuid-yuriko', 1, 'commander', 'foil'),
            $this->linea('uuid-isla', 30),
            $this->linea('uuid-bolt', 1),
        ];

        $resultado = ($this->useCase)(self::USUARIO, ['file_name' => 'SneakAttack_ZNC']);

        self::assertNotNull($resultado);

        // La colección: tres líneas nuevas y 32 ejemplares.
        self::assertSame(3, $resultado['inserted']);
        self::assertSame(0, $resultado['updated']);
        self::assertSame(32, $resultado['totalQuantity']);
        self::assertCount(3, $this->coleccion->filas);

        // El mazo: montado, en formato commander y con sus 32 cartas.
        self::assertSame('Sneak Attack', $resultado['deck']['name']);
        self::assertSame('built', $resultado['deck']['status']);
        self::assertSame('commander', $resultado['deck']['format']);
        self::assertSame(32, $resultado['deckCards']);
        self::assertCount(3, $this->mazos->cartas);

        // Y las dos escrituras, dentro de UNA transacción.
        self::assertSame(1, $this->transacciones->aperturas);
    }

    /**
     * **La prueba del hito.** Dos clics sobre la misma caja: dos mazos, las
     * cantidades sumadas y **ni una fila nueva** en la colección.
     */
    public function testComprarDosVecesLaMismaCajaSumaYNoDuplica(): void
    {
        $this->precon('SneakAttack_ZNC');
        $this->precons->cartas['SneakAttack_ZNC'] = [
            $this->linea('uuid-yuriko', 1, 'commander', 'foil'),
            $this->linea('uuid-isla', 30),
        ];

        ($this->useCase)(self::USUARIO, ['file_name' => 'SneakAttack_ZNC']);
        $filasTrasLaPrimera = count($this->coleccion->filas);

        $segunda = ($this->useCase)(self::USUARIO, ['file_name' => 'SneakAttack_ZNC']);

        // Colección: las mismas filas, con el doble de ejemplares.
        self::assertCount($filasTrasLaPrimera, $this->coleccion->filas);
        self::assertSame(0, $segunda['inserted']);
        self::assertSame(2, $segunda['updated']);

        $cantidades = array_map(
            static fn (array $fila): int => (int) $fila['quantity'],
            array_values($this->coleccion->filas)
        );
        self::assertSame([2, 60], $cantidades);

        // Mazos: UNO POR CLIC. Dos cajas compradas son dos cajas en la
        // estantería, no una fusión silenciosa en el mazo de antes.
        self::assertCount(2, $this->mazos->mazos);
        self::assertNotSame(
            $segunda['deck']['id'],
            array_key_first($this->mazos->mazos),
            'El segundo clic tiene que crear su propio mazo.'
        );
    }

    /**
     * Los `tokens` llegan al mazo —la caja los trae— y se paran ahí: el
     * inventario no los cuenta, que es `Board::esPoseible()`.
     */
    public function testLosTokensVanAlMazoPeroNoALaColeccion(): void
    {
        $this->precon('SneakAttack_ZNC');
        $this->precons->cartas['SneakAttack_ZNC'] = [
            $this->linea('uuid-bolt', 1),
            $this->linea('uuid-ficha', 2, 'tokens'),
        ];

        $resultado = ($this->useCase)(self::USUARIO, ['file_name' => 'SneakAttack_ZNC']);

        self::assertSame(1, $resultado['tokenLines']);
        self::assertCount(1, $this->coleccion->filas);
        self::assertSame(1, $resultado['totalQuantity']);

        // En el mazo sí están las dos líneas.
        self::assertCount(2, $this->mazos->cartas);

        $zonas = array_column($this->mazos->cartas, 'board');
        self::assertContains('tokens', $zonas);
    }

    /**
     * Las 254 huérfanas: se saltan y se cuentan. Meterlas tiraría la
     * transacción entera por la FK a `mtg_printing` y el clic no escribiría
     * nada, que es el fallo contrario al que se busca.
     */
    public function testLasCartasQueElCatalogoNoConoceSeSaltanYSeCuentan(): void
    {
        $this->precon('DefeatAGod_TDAG');
        $this->precons->cartas['DefeatAGod_TDAG'] = [
            $this->linea('uuid-bolt', 1),
            $this->linea('uuid-fantasma', 3, 'main', 'normal', false),
        ];

        $resultado = ($this->useCase)(self::USUARIO, ['file_name' => 'DefeatAGod_TDAG']);

        self::assertSame(1, $resultado['unknownPrintings']);
        self::assertCount(1, $this->coleccion->filas);
        self::assertCount(1, $this->mazos->cartas);
        self::assertSame(1, $resultado['totalQuantity']);
    }

    /** Si NINGUNA carta es importable no se escribe nada y se dice por qué. */
    public function testUnaCajaEnteraDeCartasDesconocidasNoEscribeNada(): void
    {
        $this->precon('DefeatAGod_TDAG');
        $this->precons->cartas['DefeatAGod_TDAG'] = [
            $this->linea('uuid-fantasma', 3, 'main', 'normal', false),
        ];

        try {
            ($this->useCase)(self::USUARIO, ['file_name' => 'DefeatAGod_TDAG']);
            self::fail('Una caja sin ni una carta importable tiene que dar error.');
        } catch (InvalidArgumentException $e) {
            self::assertStringContainsString('catalog:import', $e->getMessage());
        }

        self::assertSame([], $this->coleccion->filas);
        self::assertSame([], $this->mazos->mazos);
        self::assertSame(0, $this->transacciones->aperturas);
    }

    /** El `etched` es un acabado propio y no un foil: llega tal cual a las dos tablas. */
    public function testElAcabadoEtchedNoSeConfundeConFoil(): void
    {
        $this->precon('CommanderLegends_CMR');
        $this->precons->cartas['CommanderLegends_CMR'] = [
            $this->linea('uuid-etched', 1, 'main', 'etched'),
        ];

        ($this->useCase)(self::USUARIO, ['file_name' => 'CommanderLegends_CMR']);

        self::assertSame('etched', array_values($this->coleccion->filas)[0]['finish']);
        self::assertSame('etched', array_values($this->mazos->cartas)[0]['finish']);
    }

    /**
     * El defecto que la UI tiene que decir en voz alta: MTGJSON no publica ni
     * idioma ni condición, así que se asume inglés en NM.
     */
    public function testAsumeInglesYNmYLoDevuelveDicho(): void
    {
        $this->precon('SneakAttack_ZNC');
        $this->precons->cartas['SneakAttack_ZNC'] = [$this->linea('uuid-bolt', 1)];

        $resultado = ($this->useCase)(self::USUARIO, ['file_name' => 'SneakAttack_ZNC']);

        $fila = array_values($this->coleccion->filas)[0];
        self::assertSame('English', $fila['language']);
        self::assertSame('NM', $fila['condition_grade']);

        $carta = array_values($this->mazos->cartas)[0];
        self::assertSame('English', $carta['language']);
        self::assertSame('NM', $carta['condition_grade']);

        self::assertSame(['language' => 'English', 'condition' => 'NM'], $resultado['assumed']);
    }

    /** `Commander Deck` → `commander`; lo que no sea obvio, NULL. */
    public function testElFormatoSoloSeDeduceCuandoEsObvio(): void
    {
        $this->precon('ElvesVsGoblins_EVG', 'Duel Deck');
        $this->precons->cartas['ElvesVsGoblins_EVG'] = [$this->linea('uuid-bolt', 1)];

        $resultado = ($this->useCase)(self::USUARIO, ['file_name' => 'ElvesVsGoblins_EVG']);

        self::assertNull($resultado['deck']['format']);
    }

    /** Quien compra la caja para desmontarla manda `deck_status`. */
    public function testElEstadoDelMazoSePuedePedir(): void
    {
        $this->precon('SneakAttack_ZNC');
        $this->precons->cartas['SneakAttack_ZNC'] = [$this->linea('uuid-bolt', 1)];

        $resultado = ($this->useCase)(self::USUARIO, [
            'file_name'   => 'SneakAttack_ZNC',
            'deck_status' => 'building',
        ]);

        self::assertSame('building', $resultado['deck']['status']);
    }

    /** Un estado inventado es un fallo del cliente, no algo a adivinar. */
    public function testUnEstadoDeMazoInventadoRevienta(): void
    {
        $this->precon('SneakAttack_ZNC');
        $this->precons->cartas['SneakAttack_ZNC'] = [$this->linea('uuid-bolt', 1)];

        $this->expectException(InvalidArgumentException::class);

        ($this->useCase)(self::USUARIO, [
            'file_name'   => 'SneakAttack_ZNC',
            'deck_status' => 'a medio montar',
        ]);
    }

    /** Un `file_name` que no existe es el 404 de la acción, no un error. */
    public function testUnPreconInexistenteDevuelveNull(): void
    {
        self::assertNull(($this->useCase)(self::USUARIO, ['file_name' => 'NoExiste_XXX']));
        self::assertSame(0, $this->transacciones->aperturas);
    }

    public function testSinFileNameNoHayNadaQueImportar(): void
    {
        $this->expectException(InvalidArgumentException::class);

        ($this->useCase)(self::USUARIO, []);
    }

    // ========================================================================
    // M7 — la caja entera a la lista de deseos
    // ========================================================================

    /**
     * **La primera mitad del hito.** La caja deseada deja sus cartas en la lista
     * de deseos y **ninguna** en la colección: es el mismo `upsertLote()` con
     * `is_wishlist = 1`, no una escritura aparte.
     */
    public function testLaCajaADeseosNoDejaNiUnaCartaEnLaColeccion(): void
    {
        $this->precon('SneakAttack_ZNC');
        $this->precons->cartas['SneakAttack_ZNC'] = [
            $this->linea('uuid-yuriko', 1, 'commander', 'foil'),
            $this->linea('uuid-isla', 30),
            $this->linea('uuid-ficha', 2, 'tokens'),
        ];

        $resultado = ($this->useCase)(self::USUARIO, [
            'file_name'   => 'SneakAttack_ZNC',
            'is_wishlist' => true,
        ]);

        self::assertTrue($resultado['isWishlist']);
        self::assertCount(2, $this->coleccion->filas, 'Las fichas siguen sin entrar, ni siquiera a deseos.');

        $banderas = array_column(array_values($this->coleccion->filas), 'is_wishlist');
        self::assertSame([1, 1], $banderas);
        self::assertNotContains(0, $banderas, 'Ni una carta puede quedarse en la colección.');
    }

    /**
     * **La segunda mitad del hito.** `built` consume colección, y un mazo
     * construido con 31 cartas que solo se desean dispararía conflictos de
     * sobreasignación falsos: el defecto de la caja deseada es `building`, que
     * calcula lo que falta sin consumir.
     */
    public function testElMazoDeUnaCajaDeseadaNaceBuildingYNoBuilt(): void
    {
        $this->precon('SneakAttack_ZNC');
        $this->precons->cartas['SneakAttack_ZNC'] = [
            $this->linea('uuid-yuriko', 1, 'commander', 'foil'),
            $this->linea('uuid-isla', 30),
        ];

        $resultado = ($this->useCase)(self::USUARIO, [
            'file_name'   => 'SneakAttack_ZNC',
            'is_wishlist' => true,
        ]);

        self::assertSame('building', $resultado['deck']['status']);
        self::assertSame(31, $resultado['deckCards'], 'El mazo se crea igual: pide sus 31 cartas.');
    }

    /**
     * El defecto es un defecto, no una imposición: quien mande `deck_status`
     * manda, igual que en la caja que se compra de verdad.
     */
    public function testUnDeckStatusExplicitoMandaSobreElDefectoDeDeseos(): void
    {
        $this->precon('SneakAttack_ZNC');
        $this->precons->cartas['SneakAttack_ZNC'] = [$this->linea('uuid-bolt', 1)];

        $resultado = ($this->useCase)(self::USUARIO, [
            'file_name'   => 'SneakAttack_ZNC',
            'is_wishlist' => true,
            'deck_status' => 'dismantled',
        ]);

        self::assertSame('dismantled', $resultado['deck']['status']);
    }

    /**
     * **La tercera mitad del hito**: desear la caja y comprarla después **suma y
     * no duplica**.
     *
     * Son dos conjuntos distintos del mismo `UNIQUE KEY` —`is_wishlist` está
     * dentro—, así que la compra no pisa el deseo: escribe sus propias líneas.
     * Y comprar la caja dos veces sigue sumando sobre las suyas, que es la
     * promesa de siempre.
     */
    public function testDesearLaCajaYComprarlaDespuesSumaYNoDuplica(): void
    {
        $this->precon('SneakAttack_ZNC');
        $this->precons->cartas['SneakAttack_ZNC'] = [
            $this->linea('uuid-yuriko', 1, 'commander', 'foil'),
            $this->linea('uuid-isla', 30),
        ];

        ($this->useCase)(self::USUARIO, ['file_name' => 'SneakAttack_ZNC', 'is_wishlist' => true]);
        self::assertCount(2, $this->coleccion->filas);

        // Se compra la caja de verdad: dos líneas NUEVAS, las de colección.
        $compra = ($this->useCase)(self::USUARIO, ['file_name' => 'SneakAttack_ZNC']);

        self::assertSame(2, $compra['inserted']);
        self::assertSame(0, $compra['updated']);
        self::assertCount(4, $this->coleccion->filas);

        // Y se compra otra vez: ahora SUMA sobre las de colección, sin tocar el
        // deseo y sin crear ni una fila más.
        $segunda = ($this->useCase)(self::USUARIO, ['file_name' => 'SneakAttack_ZNC']);

        self::assertSame(0, $segunda['inserted']);
        self::assertSame(2, $segunda['updated']);
        self::assertCount(4, $this->coleccion->filas);

        $deseos = array_values(array_filter(
            $this->coleccion->filas,
            static fn (array $fila): bool => $fila['is_wishlist'] === 1
        ));
        $tenidas = array_values(array_filter(
            $this->coleccion->filas,
            static fn (array $fila): bool => $fila['is_wishlist'] === 0
        ));

        self::assertSame([1, 30], array_column($deseos, 'quantity'), 'El deseo no se toca al comprar.');
        self::assertSame([2, 60], array_column($tenidas, 'quantity'), 'La compra suma sobre sí misma.');
    }

    /** Sin la bandera, todo sigue como estaba: colección y mazo `built`. */
    public function testSinLaBanderaLaCajaSigueYendoALaColeccion(): void
    {
        $this->precon('SneakAttack_ZNC');
        $this->precons->cartas['SneakAttack_ZNC'] = [$this->linea('uuid-bolt', 1)];

        $resultado = ($this->useCase)(self::USUARIO, ['file_name' => 'SneakAttack_ZNC']);

        self::assertFalse($resultado['isWishlist']);
        self::assertSame('built', $resultado['deck']['status']);
        self::assertSame(0, array_values($this->coleccion->filas)[0]['is_wishlist']);
    }
}
