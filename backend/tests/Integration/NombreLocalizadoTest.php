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
 * El paso 3c contra las **410.604 filas reales** de `mtg_printing_localized`.
 *
 * Aquí y solo aquí se puede probar lo que decide si este paso sirve, porque son
 * tres cosas que ningún doble reproduce:
 *
 *  - que la columna `name_normalized` esté **poblada**. La rellena
 *    `catalog:normalize` y la mantiene la ingesta; si alguien se lleva por
 *    delante cualquiera de los dos enganches, este paso deja de encontrar nada
 *    **sin un solo error** — exactamente lo que ya pasó con `mtg_card`;
 *  - que la clave que se calcula al preguntar sea **la misma** que se escribió al
 *    normalizar. Son la misma función, y esa es toda la garantía que hay;
 *  - que el **desempate por dominancia** haga su trabajo sobre el dato sucio de
 *    MTGJSON, que es el motivo entero de que exista.
 *
 * Si no hay MySQL, o el catálogo no está cargado, los tests se saltan en vez de
 * fallar: la suite unitaria tiene que seguir corriendo sin base de datos.
 */
final class NombreLocalizadoTest extends TestCase
{
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

        $pobladas = (int) self::$db
            ->query('SELECT COUNT(*) FROM mtg_printing_localized WHERE name_normalized IS NOT NULL')
            ->fetchColumn();

        if ($pobladas === 0) {
            $this->markTestSkipped(
                'La columna `mtg_printing_localized.name_normalized` está vacía: '
                . 'lanza `catalog:normalize` antes.'
            );
        }
    }

    /**
     * **El `*Hecho cuando:*` del M2.** «Llanura» resuelve a *Plains*.
     *
     * Es el caso que el plan persigue desde el principio y el que demuestra que
     * el resolvedor dejó de ser monolingüe: hasta hoy esta clave devolvía
     * `not_found` en el escáner **y en `/import`**.
     *
     * Y es además el caso sucio: MTGJSON llama «Llanura» a cuatro *Swamp* del
     * set INV, frente a las 407 filas correctas de *Plains*. Sin el desempate por
     * dominancia esto sería un empate y la tierra básica más común no se
     * resolvería nunca.
     */
    public function testLlanuraResuelveAPlainsPeseAlDatoSucioDeMtgjson(): void
    {
        $porClave = $this->repositorio->cartasPorNombreLocalizado(['llanura']);

        self::assertArrayHasKey('llanura', $porClave);
        self::assertCount(
            1,
            $porClave['llanura'],
            'Con el desempate por dominancia, las 4 filas de INV no convierten esto en un empate.'
        );
        self::assertSame('Plains', $porClave['llanura'][0]['name']);
    }

    /** Las otras cuatro tierras básicas en español, que es el escaneo masivo real. */
    public function testLasCincoTierrasBasicasEnEspanolResuelvenAUnaSolaCarta(): void
    {
        $esperado = [
            'llanura' => 'Plains',
            'isla'    => 'Island',
            'pantano' => 'Swamp',
            'montana' => 'Mountain',
            'bosque'  => 'Forest',
        ];

        $porClave = $this->repositorio->cartasPorNombreLocalizado(array_keys($esperado));

        foreach ($esperado as $clave => $carta) {
            self::assertCount(1, $porClave[$clave], "«{$clave}» tendría que resolver a una sola carta.");
            self::assertSame($carta, $porClave[$clave][0]['name']);
        }
    }

    /**
     * **`impresiones` cuenta las de la CARTA, no las filas localizadas**, y esto
     * es lo que impide que la verja de certeza mienta.
     *
     * *Plains* tiene cientos de impresiones y solo una parte están traducidas al
     * español. Si se contaran las filas localizadas del `WHERE`, una carta con
     * una sola traducción diría `impresiones = 1`, el escáner lo convertiría en
     * `certaintySource: 'single'` y el modo manos libres escribiría en la
     * colección **una edición inventada, sola y sin preguntar**.
     */
    public function testLasImpresionesSonLasDeLaCartaEnteraYNoLasTraducidas(): void
    {
        $porClave = $this->repositorio->cartasPorNombreLocalizado(['llanura']);
        $plains   = $porClave['llanura'][0];

        self::assertGreaterThan(
            100,
            $plains['impresiones'],
            'Plains tiene cientos de impresiones: contar solo las traducidas rompería la verja.'
        );
        self::assertNull(
            $plains['printingUuid'],
            'Con varias impresiones no se elige ninguna: eso lo hace el chooser, y se marca asumido.'
        );
    }

    /**
     * Un nombre inventado no devuelve nada, y eso **no es un error**: es «cede el
     * turno» al paso 4. La ausencia es una respuesta.
     */
    public function testUnNombreQueNoExisteCedeElTurnoEnVezDeFallar(): void
    {
        $porClave = $this->repositorio->cartasPorNombreLocalizado(['qwertyuiopasdfghjkl']);

        self::assertSame([], $porClave['qwertyuiopasdfghjkl']);
    }

    /**
     * La consulta va **por lote**, como todos los pasos exactos: nueve cartas de
     * una página de binder son una consulta, no nueve.
     */
    public function testElLoteEnteroVuelveIndexadoPorLaClavePedida(): void
    {
        $claves   = ['llanura', 'isla', 'bosque', 'no-existe-esta-clave'];
        $porClave = $this->repositorio->cartasPorNombreLocalizado($claves);

        self::assertSame($claves, array_keys($porClave));
    }
}
