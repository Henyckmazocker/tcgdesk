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
 * `impresionesDe()` contra el catálogo REAL, no contra un doble.
 *
 * Es el único sitio donde tiene sentido probar este método: lo que puede
 * romperse no es la aritmética de la paginación —eso lo prueba `CursorTest`—
 * sino la consulta, y una consulta solo falla contra datos. Las tres cosas que
 * un doble jamás habría cazado:
 *
 *  - que el `JOIN mtg_set` no duplique filas ni se coma las que no casan;
 *  - que el orden por `release_date` sea total de verdad, con el `uuid`
 *    desempatando a las decenas de impresiones que comparten fecha por venir de
 *    la misma edición;
 *  - que el cursor por tupla recorra la lista entera **una sola vez**, que es el
 *    motivo por el que no se pagina por offset.
 *
 * Y las dos que separan el `null` del `[]`, porque de ellas cuelga el 404 del
 * router: una carta **de impresión única** devuelve una fila y no `null`, y un
 * **uuid que no existe** devuelve `null` y no una lista vacía. Las dos se
 * resuelven contra el catálogo real y no con uuid fijos: MTGJSON los rehace en
 * cada `catalog:import` y no son contrato nuestro.
 *
 * Si el catálogo no está cargado —o no hay MySQL— los tests se saltan en vez de
 * fallar: la suite unitaria tiene que seguir corriendo sin base de datos.
 */
final class ImpresionesDeCartaTest extends TestCase
{
    /**
     * Una carta muy reimpresa y que no va a dejar de serlo. No se fija el uuid:
     * el catálogo se reconstruye con `catalog:import` y los uuid de MTGJSON no
     * son un contrato nuestro, así que se resuelve por nombre en cada ejecución.
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

        // El constructor por defecto basta: la búsqueda a texto completo no
        // interviene en esta consulta, que filtra por `oracle_id` y nada más.
        $this->repositorio = new MySqlCardRepository(self::$db, new BooleanExpressionBuilder());
    }

    public function testUnaCartaReimpresaDevuelveTodasSusEdicionesDeNuevaAVieja(): void
    {
        $uuid = $this->unUuidDe(self::CARTA);

        $pagina = $this->repositorio->impresionesDe($uuid, null, 100);

        self::assertNotNull($pagina, 'El uuid existe en mtg_printing: no puede ser un 404.');
        self::assertGreaterThan(1, count($pagina['items']), self::CARTA . ' está reimpresa: una sola fila delata un JOIN roto.');

        // Todas son de la MISMA carta, y la pedida está dentro: el cliente
        // enseña "la que tienes" junto a las alternativas sin recomponer nada.
        $oracleIds = array_unique(array_column($pagina['items'], 'oracleId'));
        self::assertCount(1, $oracleIds);
        self::assertContains($uuid, array_column($pagina['items'], 'uuid'));

        // Orden ESTRICTAMENTE decreciente por (fecha, uuid). Estricto y no
        // "decreciente a secas" a propósito: dos filas empatadas en la tupla
        // entera serían dos filas indistinguibles para el cursor, y el scroll
        // acabaría repitiéndolas.
        $fechas   = $this->fechasDeEdicion();
        $anterior = null;

        foreach ($pagina['items'] as $item) {
            $clave = [$fechas[$item['setCode']], $item['uuid']];

            if ($anterior !== null) {
                self::assertTrue($clave < $anterior, "Fila fuera de orden: {$item['setCode']} {$item['uuid']}");
            }

            $anterior = $clave;
        }
    }

    public function testElCursorRecorreLaListaEnteraSinRepetirNiSaltarseNinguna(): void
    {
        $uuid = $this->unUuidDe(self::CARTA);

        $completa = $this->repositorio->impresionesDe($uuid, null, 1000);
        self::assertNotNull($completa);
        self::assertNull($completa['nextCursor'], 'Con 1000 por página no puede quedar página siguiente.');

        $esperados = array_column($completa['items'], 'uuid');

        // Páginas pequeñas a propósito: con una carta de decenas de impresiones
        // eso son muchos saltos, y cada salto es una oportunidad de saltarse una
        // fila en la frontera entre dos ediciones de la misma fecha.
        $recorridos = [];
        $cursor     = null;
        $vueltas    = 0;

        do {
            $pagina = $this->repositorio->impresionesDe($uuid, $cursor, 5);
            self::assertNotNull($pagina);

            $recorridos = array_merge($recorridos, array_column($pagina['items'], 'uuid'));
            $cursor     = $pagina['nextCursor'];
            $vueltas++;

            // Red de seguridad: un cursor que no avanzase daría un bucle
            // infinito en vez de un test rojo.
            self::assertLessThan(200, $vueltas, 'El cursor no avanza.');
        } while ($cursor !== null);

        self::assertSame($esperados, $recorridos, 'El recorrido por cursor tiene que dar la misma lista y en el mismo orden.');
        self::assertSame(count($recorridos), count(array_unique($recorridos)), 'Ninguna impresión puede salir dos veces.');
    }

    public function testUnaCartaDeImpresionUnicaDevuelveUnaFilaYNoNull(): void
    {
        $uuid = $this->unUuidSinHermanas();

        $pagina = $this->repositorio->impresionesDe($uuid, null, 100);

        // La distinción que sostiene el 404 del router: "no hay otras
        // ediciones" NO es "este uuid no existe". Devolver null aquí haría que
        // `/import` leyera «esta carta no se puede corregir» como «este uuid
        // está roto», y son dos cosas con salidas distintas.
        self::assertNotNull($pagina);
        self::assertCount(1, $pagina['items']);
        self::assertSame($uuid, $pagina['items'][0]['uuid']);

        // Sin página siguiente: un `nextCursor` aquí haría que el desplegable
        // pintara un «Ver más» que no trae nada.
        self::assertNull($pagina['nextCursor']);
    }

    public function testUnUuidQueNoEstaEnElCatalogoDevuelveNull(): void
    {
        // Un uuid con la forma correcta y que no existe: lo que decide el 404
        // es `oracleIdDe()`, no que la consulta grande devuelva cero filas.
        $pagina = $this->repositorio->impresionesDe('00000000-0000-0000-0000-000000000000', null, 60);

        self::assertNull($pagina);
    }

    public function testUnCursorIlegibleDevuelveLaPrimeraPaginaYNoUnError(): void
    {
        $uuid = $this->unUuidDe(self::CARTA);

        $primera = $this->repositorio->impresionesDe($uuid, null, 5);
        $basura  = $this->repositorio->impresionesDe($uuid, 'esto-no-es-un-cursor', 5);

        self::assertNotNull($primera);
        self::assertNotNull($basura);

        // `Cursor::decodificar()` contesta null a lo que no sabe leer, y aquí
        // eso significa "empieza por el principio". Es deliberado: un cursor
        // caducado o manipulado no puede convertirse en un error que el cliente
        // no sabría resolver — solo en la primera página.
        self::assertSame(
            array_column($primera['items'], 'uuid'),
            array_column($basura['items'], 'uuid')
        );
    }

    /**
     * El uuid de una carta que NUNCA se reimprimió, resuelto contra el catálogo
     * como el otro: se busca el `oracle_id` con una sola fila en
     * `mtg_printing`, no se fija un uuid que la próxima ingesta cambiaría.
     */
    private function unUuidSinHermanas(): string
    {
        $uuid = self::$db
            ->query(
                'SELECT p.uuid
                   FROM mtg_printing p
                   JOIN (SELECT oracle_id
                           FROM mtg_printing
                          GROUP BY oracle_id
                         HAVING COUNT(*) = 1) unicas ON unicas.oracle_id = p.oracle_id
                  ORDER BY p.uuid
                  LIMIT 1'
            )
            ->fetchColumn();

        if ($uuid === false) {
            $this->markTestSkipped('El catálogo no tiene ninguna carta de impresión única.');
        }

        return (string) $uuid;
    }

    private function unUuidDe(string $nombre): string
    {
        $stmt = self::$db->prepare(
            'SELECT p.uuid
               FROM mtg_printing p
               JOIN mtg_card c ON c.oracle_id = p.oracle_id
              WHERE c.name = :nombre
              ORDER BY p.uuid
              LIMIT 1'
        );
        $stmt->execute(['nombre' => $nombre]);

        $uuid = $stmt->fetchColumn();

        if ($uuid === false) {
            $this->markTestSkipped("El catálogo no tiene '{$nombre}': falta ejecutar catalog:import.");
        }

        return (string) $uuid;
    }

    /**
     * Código de edición → fecha de salida, con el mismo centinela que el
     * repositorio. Reconstruirlo aquí es lo que permite comprobar el orden sin
     * añadir `releaseDate` al contrato de carta, que no lo lleva ni debe.
     *
     * @return array<string, string>
     */
    private function fechasDeEdicion(): array
    {
        return self::$db
            ->query("SELECT code, COALESCE(release_date, '0001-01-01') FROM mtg_set")
            ->fetchAll(PDO::FETCH_KEY_PAIR);
    }
}
