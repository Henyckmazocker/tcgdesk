<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Application\UseCase\ApplyImport;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Doubles\ColeccionFalsa;
use Tests\Unit\Doubles\MazosFalsos;
use Tests\Unit\Doubles\TransaccionesFalsas;

/**
 * La mitad de abajo del pipeline.
 *
 * Lo que de verdad hay que probar aquí es **la promesa central del plan**:
 * reimportar el mismo fichero suma cantidades y **no crea filas nuevas**. Se
 * puede probar con un doble porque `ColeccionFalsa` reproduce el `UNIQUE KEY` de
 * seis columnas y la suma del `ON DUPLICATE KEY UPDATE`, que es exactamente lo
 * que la sostiene en MySQL.
 */
final class ApplyImportTest extends TestCase
{
    private ColeccionFalsa $coleccion;
    private MazosFalsos $mazos;
    private TransaccionesFalsas $transacciones;
    private ApplyImport $useCase;

    protected function setUp(): void
    {
        $this->coleccion     = new ColeccionFalsa();
        $this->mazos         = new MazosFalsos($this->coleccion);
        $this->transacciones = new TransaccionesFalsas();
        $this->useCase       = new ApplyImport($this->coleccion, $this->mazos, $this->transacciones);
    }

    /** @return array<string, mixed> */
    private function filaDelContrato(string $uuid, int $cantidad = 1, string $finish = 'normal'): array
    {
        // Los nombres del contrato `import_apply` del plan, en camelCase.
        return [
            'printingUuid' => $uuid,
            'finish'       => $finish,
            'language'     => 'English',
            'condition'    => 'NM',
            'quantity'     => $cantidad,
        ];
    }

    public function testEscribeLasFilasYDevuelveElResumenDelContrato(): void
    {
        $resultado = ($this->useCase)(7, [
            'rows' => [
                $this->filaDelContrato('uuid-bolt', 4),
                $this->filaDelContrato('uuid-sol', 1, 'foil'),
            ],
        ]);

        self::assertSame(2, $resultado['inserted']);
        self::assertSame(0, $resultado['updated']);
        self::assertSame(5, $resultado['totalQuantity']);
        self::assertCount(2, $this->coleccion->filas);
    }

    /**
     * **La prueba del `UNIQUE KEY`.** El mismo fichero dos veces: las cantidades
     * se duplican —comportamiento esperado y anunciado, esta importación AÑADE—
     * pero el número de filas no se mueve.
     */
    public function testReimportarElMismoFicheroSumaCantidadesYNoCreaFilas(): void
    {
        $filas = ['rows' => [
            $this->filaDelContrato('uuid-bolt', 4),
            $this->filaDelContrato('uuid-sol', 1, 'foil'),
        ]];

        ($this->useCase)(7, $filas);
        $filasTrasLaPrimera = count($this->coleccion->filas);

        $segunda = ($this->useCase)(7, $filas);

        self::assertSame($filasTrasLaPrimera, count($this->coleccion->filas), 'Reimportar NO puede crear filas.');
        self::assertSame(0, $segunda['inserted']);
        self::assertSame(2, $segunda['updated']);

        $cantidades = array_column($this->coleccion->filas, 'quantity', 'printing_uuid');
        self::assertSame(8, $cantidades['uuid-bolt']);
        self::assertSame(2, $cantidades['uuid-sol']);
    }

    /**
     * Las cinco dimensiones del `UNIQUE KEY` separan líneas: la misma carta en
     * foil y en normal son dos filas, no una con cantidad 2. Es lo que hace que
     * la importación no pierda el idioma y la condición que traía el CSV.
     */
    public function testElAcabadoYElIdiomaSeparanLineasEnVezDeFundirlas(): void
    {
        $resultado = ($this->useCase)(7, [
            'rows' => [
                $this->filaDelContrato('uuid-bolt', 1, 'normal'),
                $this->filaDelContrato('uuid-bolt', 1, 'foil'),
                ['printingUuid' => 'uuid-bolt', 'finish' => 'normal', 'language' => 'Spanish', 'condition' => 'NM', 'quantity' => 1],
            ],
        ]);

        self::assertSame(3, $resultado['inserted']);
        self::assertCount(3, $this->coleccion->filas);
    }

    /** El `user_id` lo pone AuthMiddleware y llega aparte: nunca del payload. */
    public function testTodasLasFilasSeEscribenAlUsuarioQueLlegaAparte(): void
    {
        ($this->useCase)(42, ['rows' => [
            $this->filaDelContrato('uuid-bolt'),
            ['printingUuid' => 'uuid-sol', 'user_id' => 1, 'quantity' => 1],
        ]]);

        self::assertSame([42, 42], array_column($this->coleccion->filas, 'user_id'));
    }

    /** El contrato habla camelCase y el resto de la colección snake_case. */
    public function testSeAceptanLasDosGrafiasDelPrintingUuid(): void
    {
        $resultado = ($this->useCase)(7, ['rows' => [
            ['printing_uuid' => 'uuid-sol', 'quantity' => 2],
        ]]);

        self::assertSame(1, $resultado['inserted']);
        self::assertSame(2, $resultado['totalQuantity']);
    }

    public function testUnLoteVacioNoEsUnaImportacionCorrecta(): void
    {
        $this->expectException(InvalidArgumentException::class);

        ($this->useCase)(7, ['rows' => []]);
    }

    /**
     * El error señala QUÉ fila del lote falla. Un "falta el printing_uuid" a
     * secas en una importación de 3.000 líneas no es accionable.
     */
    public function testUnaFilaSinImpresionRevientaDiciendoCualEs(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/Fila 2 /');

        ($this->useCase)(7, ['rows' => [
            $this->filaDelContrato('uuid-bolt'),
            ['quantity' => 1],
        ]]);
    }

    /** Nada se escribe si alguna fila no es del dominio: se valida el lote entero antes. */
    public function testSiUnaFilaEsInvalidaNoSeEscribeNingunaDelLote(): void
    {
        try {
            ($this->useCase)(7, ['rows' => [
                $this->filaDelContrato('uuid-bolt'),
                ['printingUuid' => 'uuid-sol', 'finish' => 'holográfico'],
            ]]);
            self::fail('Una fila con un acabado que no existe tiene que abortar la importación.');
        } catch (InvalidArgumentException) {
            self::assertSame([], $this->coleccion->filas);
        }
    }

    public function testHayUnTechoDeFilasPorImportacion(): void
    {
        $this->expectException(InvalidArgumentException::class);

        ($this->useCase)(7, [
            'rows' => array_fill(0, ApplyImport::MAXIMO_FILAS + 1, $this->filaDelContrato('uuid-bolt')),
        ]);
    }

    // ------------------------------------------------ importar como mazo (M7)

    /** Las filas de una decklist pegada, ya resueltas, con su zona. */
    private function filaDeMazo(string $uuid, int $cantidad, string $board): array
    {
        return $this->filaDelContrato($uuid, $cantidad) + ['board' => $board];
    }

    /**
     * El *hecho cuando* del hito: una decklist con `Deck` / `Sideboard` /
     * `Commander` crea el mazo **con las tres zonas bien repartidas** y además
     * suma las cartas a la colección.
     */
    public function testCrearElMazoRepartelasZonasYSumaLasCartasALaColeccion(): void
    {
        $resultado = ($this->useCase)(7, [
            'deck' => ['name' => 'Atraxa', 'status' => 'built', 'format' => 'commander'],
            'rows' => [
                $this->filaDeMazo('uuid-bolt', 4, 'main'),
                $this->filaDeMazo('uuid-path', 2, 'side'),
                $this->filaDeMazo('uuid-atraxa', 1, 'commander'),
            ],
        ]);

        // La colección, igual que sin casilla.
        self::assertSame(3, $resultado['inserted']);
        self::assertSame(7, $resultado['totalQuantity']);
        self::assertCount(3, $this->coleccion->filas);

        // Y el mazo, con las tres zonas.
        self::assertSame('Atraxa', $resultado['deck']['name']);
        self::assertSame(7, $resultado['deck']['cards']);

        $mazo = $this->mazos->mazos[$resultado['deck']['id']];
        self::assertSame('built', $mazo['status']);
        self::assertSame('commander', $mazo['format']);
        self::assertSame(7, $mazo['user_id']);

        self::assertSame(
            ['main' => 4, 'side' => 2, 'commander' => 1],
            array_combine(
                array_column($this->mazos->cartas, 'board'),
                array_column($this->mazos->cartas, 'count')
            )
        );
    }

    /** Sin `deck` en el payload no se crea ningún mazo: la casilla manda. */
    public function testSinCasillaNoSeCreaNingunMazoNiSeAbreTransaccion(): void
    {
        $resultado = ($this->useCase)(7, ['rows' => [$this->filaDeMazo('uuid-bolt', 4, 'side')]]);

        self::assertArrayNotHasKey('deck', $resultado);
        self::assertSame([], $this->mazos->mazos);
        self::assertSame(0, $this->transacciones->aperturas);
    }

    /** Las dos escrituras van dentro de UNA transacción, no cada una por su lado. */
    public function testLaColeccionYElMazoSeEscribenEnLaMismaTransaccion(): void
    {
        ($this->useCase)(7, [
            'deck' => ['name' => 'Burn'],
            'rows' => [$this->filaDeMazo('uuid-bolt', 4, 'main')],
        ]);

        self::assertSame(1, $this->transacciones->aperturas);
    }

    /**
     * **Lo que pasa al reimportar el mismo texto**, que el plan no especificaba:
     * la colección suma sin duplicar filas —la promesa del `ON DUPLICATE KEY
     * UPDATE`— y cada importación crea **su** mazo, porque el usuario le pone un
     * nombre cada vez.
     */
    public function testReimportarSumaEnLaColeccionYCreaUnSegundoMazo(): void
    {
        $peticion = [
            'deck' => ['name' => 'Burn'],
            'rows' => [
                $this->filaDeMazo('uuid-bolt', 4, 'main'),
                $this->filaDeMazo('uuid-path', 2, 'side'),
            ],
        ];

        $primera = ($this->useCase)(7, $peticion);
        $segunda = ($this->useCase)(7, $peticion);

        // La colección: mismas filas, cantidades dobladas.
        self::assertCount(2, $this->coleccion->filas);
        self::assertSame(0, $segunda['inserted']);
        self::assertSame(2, $segunda['updated']);
        self::assertSame([8, 4], array_column($this->coleccion->filas, 'quantity'));

        // El mazo: dos mazos distintos, cada uno con sus dos líneas.
        self::assertNotSame($primera['deck']['id'], $segunda['deck']['id']);
        self::assertCount(2, $this->mazos->mazos);
        self::assertCount(4, $this->mazos->cartas);
    }

    /** Dentro del mazo nuevo, dos líneas iguales de la misma zona SUMAN en una. */
    public function testDosLineasIgualesDelMismoBoardSeFundenEnUnaSolaDelMazo(): void
    {
        $resultado = ($this->useCase)(7, [
            'deck' => ['name' => 'Burn'],
            'rows' => [
                $this->filaDeMazo('uuid-bolt', 2, 'main'),
                $this->filaDeMazo('uuid-bolt', 2, 'main'),
            ],
        ]);

        self::assertCount(1, $this->mazos->cartas);
        self::assertSame(4, reset($this->mazos->cartas)['count']);
        self::assertSame(4, $resultado['deck']['cards']);
    }

    /** La misma carta en el main y en el side son DOS líneas: `board` está en la clave. */
    public function testLaMismaCartaEnDosZonasSonDosLineasDelMazoYUnaDeColeccion(): void
    {
        ($this->useCase)(7, [
            'deck' => ['name' => 'Burn'],
            'rows' => [
                $this->filaDeMazo('uuid-bolt', 3, 'main'),
                $this->filaDeMazo('uuid-bolt', 1, 'side'),
            ],
        ]);

        self::assertCount(2, $this->mazos->cartas);
        self::assertCount(1, $this->coleccion->filas, 'la colección no tiene zonas');
        self::assertSame(4, $this->coleccion->filas[1]['quantity']);
    }

    /** Sin zona, al main: es lo que significa una lista sin cabeceras. */
    public function testUnaFilaSinZonaCaeAlBoardPorDefecto(): void
    {
        ($this->useCase)(7, [
            'deck' => ['name' => 'Burn'],
            'rows' => [$this->filaDelContrato('uuid-bolt', 1)],
        ]);

        self::assertSame('main', reset($this->mazos->cartas)['board']);
    }

    /** Un mazo sin nombre no es un mazo: 422, y ni colección ni mazo escritos. */
    public function testUnMazoSinNombreAbortaLaImportacionEntera(): void
    {
        try {
            ($this->useCase)(7, [
                'deck' => ['name' => '  '],
                'rows' => [$this->filaDelContrato('uuid-bolt', 1)],
            ]);
            self::fail('Un mazo sin nombre tiene que abortar la importación.');
        } catch (InvalidArgumentException) {
            self::assertSame([], $this->coleccion->filas);
            self::assertSame([], $this->mazos->mazos);
            self::assertSame(0, $this->transacciones->aperturas, 'ni se llega a abrir la transacción');
        }
    }

    /** Una zona inventada es un error del cliente, no algo que caiga al main. */
    public function testUnaZonaQueNoExisteAbortaLaImportacionAntesDeEscribir(): void
    {
        try {
            ($this->useCase)(7, [
                'deck' => ['name' => 'Burn'],
                'rows' => [$this->filaDeMazo('uuid-bolt', 1, 'banquillo')],
            ]);
            self::fail('Una zona que no existe tiene que abortar la importación.');
        } catch (InvalidArgumentException) {
            self::assertSame([], $this->coleccion->filas);
            self::assertSame([], $this->mazos->mazos);
        }
    }
}
