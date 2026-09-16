<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Infrastructure\Database\DatabaseConnector;
use App\Infrastructure\Persistence\MySqlCardRepository;
use App\Infrastructure\Persistence\Search\BooleanExpressionBuilder;
use PDO;
use PHPUnit\Framework\TestCase;
use Throwable;

/**
 * `porUuids()` contra el catálogo REAL, que es el único sitio donde una consulta
 * puede fallar.
 *
 * El test unitario del escáner (`ScanControllerTest`) prueba el contrato con un
 * repositorio de mentira; aquí se prueba **el SQL**, y hay tres cosas que un
 * doble jamás habría cazado:
 *
 *  - que el `IN` con **un marcador nombrado por uuid** funcione: con
 *    `ATTR_EMULATE_PREPARES = false` MySQL no admite reutilizar un mismo nombre
 *    en dos puntos de la sentencia, y un `IN` de nueve cartas son nueve puntos;
 *  - que los tres `LEFT JOIN` de precio **no dupliquen filas** ni se coman las
 *    impresiones sin cotizar, que son lo normal y no la excepción;
 *  - que la ficha que sirve el escáner sea **la misma** que sirve la ficha de
 *    catálogo, porque las dos salen de `COLUMNAS` y de `aContrato()`.
 *
 * Si no hay MySQL, o el catálogo no está cargado, los tests se saltan en vez de
 * fallar: la suite unitaria tiene que seguir corriendo sin base de datos.
 */
final class FichasPorLoteTest extends TestCase
{
    /**
     * Una carta muy reimpresa. No se fijan uuid: MTGJSON los rehace en cada
     * `catalog:import` y no son contrato nuestro, así que se resuelven por
     * nombre en cada ejecución.
     */
    private const CARTA = 'Lightning Bolt';

    private static ?PDO $db = null;

    private MySqlCardRepository $repositorio;

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

        $this->repositorio = new MySqlCardRepository(self::$db, new BooleanExpressionBuilder());
    }

    /**
     * Nueve uuid —una página de binder— en una sola consulta, con la forma
     * exacta del contrato y **sin una fila de más**.
     */
    public function testDevuelveLaFichaDeTodoUnLoteIndexadaPorUuid(): void
    {
        $uuids = $this->uuidsDe(self::CARTA, 9);

        self::assertCount(9, $uuids, 'Hacen falta nueve impresiones para simular una página de binder.');

        $fichas = $this->repositorio->porUuids($uuids);

        self::assertCount(9, $fichas, 'Ni una de menos ni una de más: los LEFT JOIN de precio no duplican.');
        self::assertSame($uuids, array_keys(array_intersect_key($fichas, array_flip($uuids))));

        foreach ($fichas as $uuid => $ficha) {
            self::assertSame($uuid, $ficha['uuid'], 'La clave del mapa es el uuid de la propia ficha.');
            self::assertSame(self::CARTA, $ficha['name']);

            // Los tres campos por los que existe este método: `ResolveCards` no
            // devuelve ninguno y el menú del escáner los necesita.
            self::assertIsString($ficha['collectorNumber']);
            self::assertSame(['foil', 'nonfoil', 'etched'], array_keys($ficha['finishes']));
            self::assertSame(['normal', 'foil', 'etched'], array_keys($ficha['priceEur']));

            foreach ($ficha['finishes'] as $admitido) {
                self::assertIsBool($admitido);
            }

            foreach ($ficha['priceEur'] as $precio) {
                // Un printing sin cotizar es NULL y **nunca** 0: cero diría que
                // la carta no vale nada, que es distinto de no saber cuánto vale.
                self::assertTrue($precio === null || is_float($precio));
            }
        }
    }

    /**
     * Un uuid que no existe **no sale en el mapa**, y no arrastra a los que sí.
     *
     * Es lo que permite al controller mezclar lo que el resolvedor le dio sin
     * comprobar nada antes: la ausencia es la respuesta, no un error.
     */
    public function testUnUuidQueNoExisteSimplementeNoSale(): void
    {
        $uuid = $this->uuidsDe(self::CARTA, 1)[0];

        $fichas = $this->repositorio->porUuids([$uuid, 'no-existe-este-uuid']);

        self::assertCount(1, $fichas);
        self::assertArrayHasKey($uuid, $fichas);
    }

    /** El lote vacío no llega a preguntar: `IN ()` no es SQL válido. */
    public function testUnLoteVacioNoPregunta(): void
    {
        self::assertSame([], $this->repositorio->porUuids([]));
        self::assertSame([], $this->repositorio->porUuids(['']));
    }

    /**
     * La misma carta repetida en la misma tanda —el escáner deja el móvil
     * apuntando— se pregunta una vez y vuelve una vez.
     */
    public function testLosUuidRepetidosNoDuplicanLaRespuesta(): void
    {
        $uuid = $this->uuidsDe(self::CARTA, 1)[0];

        $fichas = $this->repositorio->porUuids([$uuid, $uuid, $uuid]);

        self::assertCount(1, $fichas);
    }

    /** @return list<string> */
    private function uuidsDe(string $nombre, int $cuantos): array
    {
        $stmt = self::$db->prepare(
            'SELECT p.uuid
               FROM mtg_printing p
               JOIN mtg_card c ON c.oracle_id = p.oracle_id
              WHERE c.name = :name
              ORDER BY p.uuid
              LIMIT ' . $cuantos
        );
        $stmt->execute(['name' => $nombre]);

        /** @var list<string> */
        return array_map(static fn ($u): string => (string) $u, $stmt->fetchAll(PDO::FETCH_COLUMN));
    }
}
