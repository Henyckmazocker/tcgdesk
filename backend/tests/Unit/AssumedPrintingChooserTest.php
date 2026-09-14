<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Import\AssumedPrintingChooser;
use App\Domain\Import\CardResolution;
use App\Domain\Import\ParsedRow;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Doubles\ImpresionesAsumidasFalsas;

/**
 * La edición asumida, aislada del resolvedor.
 *
 * El criterio de «la más barata» es una función de ventana en MySQL y se
 * comprueba contra el catálogo real; lo que se prueba aquí es **a quién se le
 * pregunta**, que es donde están los dos errores caros: preguntar por una fila
 * que ya sabía su impresión (y cambiarla por otra), y preguntar sin decir el
 * acabado (y elegir la más barata de una carta en foil mirando precios de
 * no-foil, el bug que ya infló una colección de prueba en 500 €).
 */
final class AssumedPrintingChooserTest extends TestCase
{
    private ImpresionesAsumidasFalsas $catalogo;
    private AssumedPrintingChooser $chooser;

    protected function setUp(): void
    {
        $this->catalogo = new ImpresionesAsumidasFalsas();
        $this->chooser  = new AssumedPrintingChooser($this->catalogo);
    }

    private function fila(string $finish = 'normal'): ParsedRow
    {
        return new ParsedRow(null, 'Lightning Bolt', null, null, $finish, 'English', 'NM', 1, 1);
    }

    /** Carta identificada y edición sin decidir: eso, y solo eso, se pregunta. */
    public function testSoloSePreguntaPorLoResueltoQueNoSabeSuImpresion(): void
    {
        $veredictos = [
            CardResolution::resuelta($this->fila(), ['oracleId' => 'o-sol', 'printingUuid' => 'uuid-sol'], '1'),
            CardResolution::resuelta($this->fila(), ['oracleId' => 'o-bolt'], '3'),
            CardResolution::conflicto($this->fila(), CardResolution::AMBIGUA),
        ];

        $this->catalogo->conImpresion('o-bolt', 'normal', 'uuid-barata');

        $elegidas = $this->chooser->elegir($veredictos);

        self::assertSame(['o-bolt|normal'], array_keys($elegidas));
        self::assertSame('uuid-barata', $elegidas['o-bolt|normal']['printingUuid']);
        self::assertSame([['oracleId' => 'o-bolt', 'finish' => 'normal']], $this->catalogo->llamadas[0]);
    }

    /**
     * **El acabado forma parte de la pregunta.** La misma carta en foil y en
     * normal son dos preguntas y pueden dar dos impresiones distintas: el precio
     * se une por `(printing, finish)`, nunca solo por printing.
     */
    public function testLaMismaCartaEnDosAcabadosSonDosPreguntas(): void
    {
        $this->catalogo
            ->conImpresion('o-bolt', 'normal', 'uuid-normal-barata', 'M10', 47, 0.30)
            ->conImpresion('o-bolt', 'foil', 'uuid-foil-barata', 'MM3', 47, 2.10);

        $elegidas = $this->chooser->elegir([
            CardResolution::resuelta($this->fila('normal'), ['oracleId' => 'o-bolt'], '3'),
            CardResolution::resuelta($this->fila('foil'), ['oracleId' => 'o-bolt'], '3'),
        ]);

        self::assertSame('uuid-normal-barata', $elegidas['o-bolt|normal']['printingUuid']);
        self::assertSame('uuid-foil-barata', $elegidas['o-bolt|foil']['printingUuid']);
        self::assertCount(2, $this->catalogo->llamadas[0]);
    }

    /**
     * Los candidatos de un conflicto entran en la MISMA pregunta que las filas.
     * Sin esto, elegir a mano *Lightning Bolt* en una ambigüedad daría una fila
     * sin `printingUuid`, que `import_apply` no puede escribir — y el arreglo
     * manual, que es el plan B entero de M0, no serviría de nada.
     */
    public function testLosCandidatosDeUnConflictoTambienRecibenEdicionAsumida(): void
    {
        $this->catalogo->conImpresion('o-spy-2', 'normal', 'uuid-spy-barata');

        $elegidas = $this->chooser->elegir([
            CardResolution::conflicto($this->fila(), CardResolution::AMBIGUA, [
                ['oracleId' => 'o-spy-1', 'printingUuid' => 'uuid-spy-1'],
                ['oracleId' => 'o-spy-2', 'printingUuid' => null],
            ]),
        ]);

        // Solo se pregunta por el que no sabe su impresión.
        self::assertSame([['oracleId' => 'o-spy-2', 'finish' => 'normal']], $this->catalogo->llamadas[0]);
        self::assertSame('uuid-spy-barata', $elegidas['o-spy-2|normal']['printingUuid']);
    }

    /** Si el catálogo no ofrece impresión, no se inventa ninguna: no hay entrada. */
    public function testLaCartaSinImpresionNoDevuelveNada(): void
    {
        $elegidas = $this->chooser->elegir([
            CardResolution::resuelta($this->fila(), ['oracleId' => 'o-fantasma'], '4'),
        ]);

        self::assertSame([], $elegidas);
    }

    /** Sin huecos que rellenar no se hace ni una consulta. */
    public function testSinHuecosNoSeConsultaNada(): void
    {
        $this->chooser->elegir([
            CardResolution::resuelta($this->fila(), ['oracleId' => 'o-sol', 'printingUuid' => 'uuid-sol'], '2'),
        ]);

        self::assertSame([], $this->catalogo->llamadas);
    }
}
