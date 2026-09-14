<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Application\UseCase\ResolveCards;
use App\Domain\Import\AssumedPrintingChooser;
use App\Domain\Import\CardResolution;
use App\Domain\Import\CardResolver;
use App\Domain\Import\NameNormalizer;
use App\Domain\Import\ParsedRow;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Doubles\CatalogoDeResolucionFalso;
use Tests\Unit\Doubles\ImpresionesAsumidasFalsas;

/**
 * El use case reparte los veredictos en los dos montones del contrato
 * `import_preview` y no hace nada más — en particular, **no escribe nada**: es la
 * mitad de arriba del alto obligatorio del pipeline.
 *
 * Lo que se protege aquí es que un conflicto llegue a la previsualización con lo
 * necesario para arreglarlo a mano: su número de línea, lo que decía el fichero
 * sin normalizar, el motivo y los candidatos.
 */
final class ResolveCardsTest extends TestCase
{
    private CatalogoDeResolucionFalso $catalogo;
    private ImpresionesAsumidasFalsas $impresiones;
    private ResolveCards $useCase;

    protected function setUp(): void
    {
        $this->catalogo    = new CatalogoDeResolucionFalso();
        $this->impresiones = new ImpresionesAsumidasFalsas();

        $this->useCase = new ResolveCards(
            new CardResolver($this->catalogo, new NameNormalizer()),
            new AssumedPrintingChooser($this->impresiones)
        );
    }

    private function fila(string $name, int $cantidad, int $linea, array $errores = []): ParsedRow
    {
        return new ParsedRow(
            null,
            $name,
            null,
            null,
            'foil',
            'Spanish',
            'NM',
            $cantidad,
            $linea,
            $errores,
            ['Name' => $name, 'Quantity' => (string) $cantidad],
        );
    }

    public function testRepartimosEnResueltasYConflictosConSuResumen(): void
    {
        $this->catalogo
            ->conCarta('Sol Ring', 'o-sol')
            ->conCarta('Sly Spy', 'o-spy-1')
            ->conCarta('Sly Spy', 'o-spy-2');

        $salida = ($this->useCase)([
            $this->fila('Sol Ring', 3, 2),
            $this->fila('Sly Spy', 1, 3),
            $this->fila('Cosa inexistente', 4, 4),
        ]);

        self::assertSame(3, $salida['total']);
        self::assertSame(1, $salida['summary']['resolvedCount']);
        self::assertSame(2, $salida['summary']['conflictCount']);

        // totalQuantity cuenta SOLO lo resuelto: es lo único que podría acabar en
        // la colección sin que el usuario toque nada.
        self::assertSame(3, $salida['summary']['totalQuantity']);
    }

    public function testUnaFilaResueltaLlevaLoQueHaceFaltaParaAplicarla(): void
    {
        $this->catalogo->conCarta('Sol Ring', 'o-sol', printingUuid: 'uuid-sol', setCode: 'C21');

        $salida = ($this->useCase)([$this->fila('Sol Ring', 2, 7)]);
        $fila   = $salida['resolved'][0];

        self::assertSame(7, $fila['line']);
        self::assertSame('uuid-sol', $fila['printingUuid']);
        self::assertSame('Sol Ring', $fila['name']);
        self::assertSame('C21', $fila['setCode']);
        self::assertSame('foil', $fila['finish']);
        self::assertSame('Spanish', $fila['language']);
        self::assertSame('NM', $fila['condition']);
        self::assertSame(2, $fila['quantity']);
        self::assertSame('3', $fila['step']);
    }

    public function testUnConflictoLlegaConLineaCrudoMotivoYCandidatos(): void
    {
        $this->catalogo
            ->conCarta('Sly Spy', 'o-spy-1')
            ->conCarta('Sly Spy', 'o-spy-2');

        $salida    = ($this->useCase)([$this->fila('Sly Spy', 1, 9)]);
        $conflicto = $salida['conflicts'][0];

        self::assertSame(9, $conflicto['line']);
        self::assertSame(CardResolution::AMBIGUA, $conflicto['reason']);
        self::assertSame(['Name' => 'Sly Spy', 'Quantity' => '1'], $conflicto['raw']);
        self::assertCount(2, $conflicto['candidates']);
    }

    /**
     * La fila que el parser marcó inválida no se resuelve ni se descarta: viaja
     * a la previsualización con su motivo, y con el valor CRUDO que traía el
     * fichero, que es lo que el usuario tiene que ver para arreglarlo.
     */
    public function testLaFilaInvalidaLlegaConMotivoInvalidYSuDetalle(): void
    {
        $salida = ($this->useCase)([
            $this->fila('Sol Ring', 1, 5, ['condition' => 'valor desconocido: MINT?']),
        ]);

        self::assertSame(CardResolution::INVALIDA, $salida['conflicts'][0]['reason']);
        self::assertSame('condition: valor desconocido: MINT?', $salida['conflicts'][0]['detail']);
    }

    /**
     * **La edición asumida.** El resolvedor identifica la CARTA cuando el
     * fichero solo trae un nombre, y deja la impresión sin decidir en cuanto esa
     * carta tiene varias. Mandar esas líneas a conflicto convertiría una lista de
     * 300 cartas pegada de una web en 300 elecciones manuales, así que se
     * resuelven solas con la impresión más barata — pero **marcadas**, que es lo
     * que las separa de un fallo silencioso.
     */
    public function testUnaCartaConVariasImpresionesSeResuelveSolaYViajaMarcada(): void
    {
        $this->catalogo->conCarta('Lightning Bolt', 'o-bolt', impresiones: 47);
        $this->impresiones->conImpresion('o-bolt', 'foil', 'uuid-barata', 'LEA', 47, 0.35);

        $salida = ($this->useCase)([$this->fila('Lightning Bolt', 4, 3)]);
        $fila   = $salida['resolved'][0];

        self::assertSame('uuid-barata', $fila['printingUuid']);
        self::assertSame('LEA', $fila['setCode']);
        self::assertTrue($fila['assumedPrinting']);
        self::assertSame(47, $fila['printingCount']);
        self::assertSame(1, $salida['summary']['assumedCount']);
        self::assertSame(4, $salida['summary']['totalQuantity']);
    }

    /**
     * Lo contrario, y es lo que hace útil la marca: una fila cuya impresión venía
     * en el fichero NO se cuenta como asumida. Si `assumedCount` las incluyera,
     * el resumen diría "118 ediciones asumidas" en una importación de ManaBox
     * donde no se ha asumido ni una.
     */
    public function testLaFilaQueYaTraiaImpresionNoSeCuentaComoAsumida(): void
    {
        $this->catalogo->conCarta('Sol Ring', 'o-sol', printingUuid: 'uuid-sol', setCode: 'C21');

        $salida = ($this->useCase)([$this->fila('Sol Ring', 1, 2)]);

        self::assertFalse($salida['resolved'][0]['assumedPrinting']);
        self::assertSame(1, $salida['resolved'][0]['printingCount']);
        self::assertSame(0, $salida['summary']['assumedCount']);
        self::assertSame([], $this->impresiones->llamadas);
    }

    /**
     * La carta existe pero el catálogo no da ninguna impresión suya. No puede
     * salir como resuelta: sin `printingUuid` no hay nada que escribir en la
     * colección, y colarla dejaría el `import_apply` reventando por una fila que
     * la previsualización dio por buena.
     */
    public function testSinImpresionQueAsumirLaFilaBajaAConflicto(): void
    {
        $this->catalogo->conCarta('Carta Fantasma', 'o-fantasma', impresiones: 9);

        $salida = ($this->useCase)([$this->fila('Carta Fantasma', 2, 4)]);

        self::assertSame([], $salida['resolved']);
        self::assertSame(CardResolution::NO_ENCONTRADA, $salida['conflicts'][0]['reason']);
        self::assertSame(0, $salida['summary']['totalQuantity']);
    }

    /**
     * La promesa de escala: la misma carta en 300 líneas se pregunta **una vez**,
     * y el lote entero va en **una sola llamada**. Con una consulta por fila, un
     * fichero de 20.000 líneas sin edición es un timeout con otro nombre.
     */
    public function testLaEdicionAsumidaSePreguntaEnLoteYSinRepetirCarta(): void
    {
        $this->catalogo
            ->conCarta('Lightning Bolt', 'o-bolt', impresiones: 47)
            ->conCarta('Counterspell', 'o-counter', impresiones: 30);

        $this->impresiones
            ->conImpresion('o-bolt', 'foil', 'uuid-bolt', 'LEA', 47, 0.35)
            ->conImpresion('o-counter', 'foil', 'uuid-counter', 'LEB', 30, 0.20);

        ($this->useCase)([
            $this->fila('Lightning Bolt', 1, 1),
            $this->fila('Lightning Bolt', 2, 2),
            $this->fila('Counterspell', 1, 3),
            $this->fila('Lightning Bolt', 1, 4),
        ]);

        self::assertCount(1, $this->impresiones->llamadas, 'La edición asumida debe ir en lote.');
        self::assertCount(2, $this->impresiones->llamadas[0], 'Tres líneas de la misma carta son UNA pregunta.');
    }

    /**
     * Un candidato de conflicto tiene que llegar **aplicable**. Los pasos por
     * nombre lo devuelven sin impresión en cuanto su carta tiene varias, y
     * elegirlo daría una fila que `import_apply` no puede escribir: el arreglo
     * manual del conflicto —que es el plan B entero de M0— no serviría de nada.
     */
    public function testLosCandidatosDeUnConflictoLleganConImpresionElegible(): void
    {
        $this->catalogo
            ->conCarta('Sly Spy', 'o-spy-1', impresiones: 4)
            ->conCarta('Sly Spy', 'o-spy-2', impresiones: 4);

        $this->impresiones
            ->conImpresion('o-spy-1', 'foil', 'uuid-spy-1', 'UST', 4, 0.10)
            ->conImpresion('o-spy-2', 'foil', 'uuid-spy-2', 'UST', 4, 0.12);

        $candidatos = ($this->useCase)([$this->fila('Sly Spy', 1, 9)])['conflicts'][0]['candidates'];

        self::assertSame('uuid-spy-1', $candidatos[0]['printingUuid']);
        self::assertSame('uuid-spy-2', $candidatos[1]['printingUuid']);
        self::assertTrue($candidatos[0]['assumedPrinting']);
        self::assertSame(4, $candidatos[0]['printingCount']);
    }

    public function testUnLoteVacioNoConsultaNadaYDevuelveLosDosMontonesVacios(): void
    {
        $salida = ($this->useCase)([]);

        self::assertSame(0, $salida['total']);
        self::assertSame([], $salida['resolved']);
        self::assertSame([], $salida['conflicts']);
        self::assertSame(0, array_sum($this->catalogo->llamadas));
    }
}
