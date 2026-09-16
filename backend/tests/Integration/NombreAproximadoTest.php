<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Infrastructure\Database\DatabaseConnector;
use App\Infrastructure\Persistence\MySqlCardResolutionRepository;
use App\Infrastructure\Persistence\Search\BooleanExpressionBuilder;
use PDO;
use PHPUnit\Framework\TestCase;
use Throwable;

/**
 * El paso 5 contra el catálogo real, que es el único sitio donde se puede saber
 * si de verdad rescata una errata **sin arrastrar media carta equivocada**.
 *
 * Un doble con tres cartas dentro siempre dirá que sí: la pregunta que importa
 * es qué pasa cuando compite contra las **34.992** claves inglesas y las
 * **232.439** localizadas, y eso solo se mide aquí.
 *
 * Si no hay MySQL, o el catálogo no está cargado, los tests se saltan en vez de
 * fallar: la suite unitaria tiene que seguir corriendo sin base de datos.
 */
final class NombreAproximadoTest extends TestCase
{
    /** La misma que usa el resolvedor: `CardResolver::DISTANCIA_MAXIMA`. */
    private const DISTANCIA = 2;

    private static ?PDO $db = null;

    private MySqlCardResolutionRepository $repositorio;

    public static function setUpBeforeClass(): void
    {
        try {
            self::$db = (new DatabaseConnector())->getConnection();
        } catch (Throwable) {
            self::$db = null;
        }
    }

    protected function setUp(): void
    {
        if (self::$db === null) {
            $this->markTestSkipped('Sin conexión a MySQL: los tests de integración necesitan el catálogo real.');
        }

        $this->repositorio = new MySqlCardResolutionRepository(self::$db, new BooleanExpressionBuilder());
    }

    /**
     * **Los dos fallos REALES del OCR**, tal cual aparecen en
     * `frontend/tests/fixtures/scan_mlkit_lecturas.json`: «Tlanura» y «Llasura»,
     * las dos erratas de un solo carácter sobre *Llanura*.
     *
     * Son el motivo entero de que este paso exista. Antes de él, una letra mal
     * leída tiraba la lectura completa: ni la igualdad del paso 3 ni el
     * `FULLTEXT` del 4 las rescatan.
     *
     * Y demuestran de paso que el paso 5 **no es monolingüe**: la clave que casa
     * está en `mtg_printing_localized`, no en `mtg_card`, donde esta carta se
     * llama *Plains*.
     */
    public function testLasDosErratasRealesDelOcrResuelvenAPlains(): void
    {
        foreach (['tlanura', 'llasura'] as $errata) {
            $candidatos = $this->repositorio->cartasPorNombreAproximado($errata, self::DISTANCIA);

            self::assertCount(1, $candidatos, "«{$errata}» tendría que dejar UN candidato.");
            self::assertSame('Plains', $candidatos[0]['name']);
        }
    }

    /** Un nombre inglés bien escrito se encuentra a distancia 0, sin ruido. */
    public function testUnNombreInglesExactoSeEncuentraASiMismoYSolo(): void
    {
        $candidatos = $this->repositorio->cartasPorNombreAproximado('lightning bolt', self::DISTANCIA);

        self::assertCount(1, $candidatos);
        self::assertSame('Lightning Bolt', $candidatos[0]['name']);
    }

    /**
     * Lo que no se parece a nada devuelve vacío, y eso **no es un error**: el
     * `not_found` que puso el paso 4 sigue siendo la respuesta.
     */
    public function testUnNombreInventadoNoRescataNada(): void
    {
        self::assertSame(
            [],
            $this->repositorio->cartasPorNombreAproximado('qwertyuiopasdf', self::DISTANCIA)
        );
    }

    /**
     * **La regla dura, contra el catálogo entero.** Este paso devuelve solo el
     * escalón más cercano, así que lo que vuelve o es un candidato —y el
     * resolvedor lo acepta— o son varios empatados —y el resolvedor no elige—.
     * Lo que NUNCA puede pasar es que mezcle distancias: un candidato a 1 y otro
     * a 2 en la misma lista convertirían un acierto en un empate.
     */
    public function testNuncaMezclaEscalonesDeDistancia(): void
    {
        // «plaims» está a UNA sustitución de *Plains* y a dos de un puñado más.
        // Si el escalón no estuviera acotado, volverían todas y el resolvedor
        // convertiría un acierto en un empate.
        //
        // Se mide con SEIS caracteres a propósito: por debajo de eso el corte
        // del M3b devuelve vacío y este test no probaría nada.
        $candidatos = $this->repositorio->cartasPorNombreAproximado('plaims', self::DISTANCIA);

        self::assertCount(1, $candidatos, 'Con el escalón acotado solo vuelve el más cercano.');
        self::assertSame('Plains', $candidatos[0]['name']);
    }

    /**
     * **El filtro del M3b: un fragmento corto no busca parecidos.**
     *
     * Medido en el Realme sobre 21 cartas: el OCR lee `Pla` en el borde de una
     * Llanura, `PF` en el marco y `tab` en el texto de reglas, y este paso les
     * encontraba carta —a distancia 2, un fragmento de tres letras alcanza media
     * docena de nombres reales—. Con el modo manos libres encendido, eso escribe
     * en la colección una carta que no está sobre la mesa.
     *
     * Las erratas que este paso existe para rescatar miden siete caracteres, así
     * que el corte no les afecta: lo que corta es justo el ruido.
     */
    public function testUnFragmentoCortoNoBuscaParecidos(): void
    {
        foreach (['pla', 'plc', 'pf', 'tab', 'plar'] as $ruido) {
            self::assertSame(
                [],
                $this->repositorio->cartasPorNombreAproximado($ruido, self::DISTANCIA),
                "«{$ruido}» es ruido del OCR y no puede resolver a ninguna carta."
            );
        }

        // Y el corte no se lleva por delante lo que sí sirve.
        self::assertCount(1, $this->repositorio->cartasPorNombreAproximado('tlanura', self::DISTANCIA));
    }

    /**
     * El coste, medido y con techo. Este paso recorre dos ventanas de longitud
     * sin índice —`CHAR_LENGTH()` no lo permite— y mide la distancia en PHP.
     *
     * Solo corre sobre lo que ya salió `not_found`, así que ~110 ms está bien;
     * lo que este test vigila es que no se vaya a segundos el día que alguien
     * quite el acotado por longitud, que es lo único que lo hace barato. Con el
     * bucle del escáner disparando cada 500 ms, eso sería la feature muerta.
     */
    public function testElAcotadoPorLongitudLoMantieneLejosDelSegundo(): void
    {
        $inicio = microtime(true);
        $this->repositorio->cartasPorNombreAproximado('tlanura', self::DISTANCIA);
        $ms = (microtime(true) - $inicio) * 1000;

        self::assertLessThan(1000, $ms, sprintf('El paso 5 tardó %.0f ms: se ha perdido el acotado.', $ms));
    }
}
