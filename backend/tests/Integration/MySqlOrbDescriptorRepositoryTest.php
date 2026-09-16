<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Infrastructure\Database\DatabaseConnector;
use App\Infrastructure\Persistence\MySqlOrbDescriptorRepository;
use PDO;
use PHPUnit\Framework\TestCase;
use Throwable;

/**
 * `mtg_printing_orb` contra MySQL de verdad, que es el único sitio donde estas
 * tres cosas pueden fallar:
 *
 *  - **El `LEFT JOIN` devuelve fila para las impresiones SIN sembrar.** Con un
 *    `JOIN` a secas, una carta nueva devolvería cero referencias y la siembra
 *    perezosa del M2 no arrancaría nunca, en silencio. Es el mismo fallo que el
 *    `LEFT JOIN` + `COALESCE` del buscador de usuarios evita, y el mismo que
 *    tienen los cruces de legalidad.
 *  - **`estadoDe()` repite el uuid con dos marcadores nombrados distintos.** Con
 *    `ATTR_EMULATE_PREPARES = false` reutilizar `:uuid` en dos puntos de la
 *    sentencia da `SQLSTATE[HY093]`, y ningún doble caza eso. La trampa está
 *    documentada en `CLAUDE.md` y resuelta en `MySqlCardRepository::porUuids()`.
 *  - **`sembrar()` es `INSERT IGNORE` y NO sobrescribe.** Un doble puede
 *    reproducirlo; lo que solo se ve aquí es que además **se traga la violación
 *    de clave ajena**, que es el motivo entero de que `estadoDe()` exista.
 *  - **El CTE con `ROW_NUMBER()` que elige idioma (M6).** Es la pieza más
 *    delicada de la consulta: tiene que servir la fila del idioma pedido, caer a
 *    la inglesa cuando esa impresión no está sembrada en él, y **no duplicar la
 *    fila** cuando están sembrados los dos. Un doble responde igual con las tres
 *    reglas mal escritas.
 *
 * No se fijan uuid: MTGJSON los rehace en cada `catalog:import` y no son
 * contrato nuestro, así que se resuelven por nombre en cada ejecución. Las filas
 * que escribe este test se borran en `tearDown()`, y la tabla es reconstruible
 * de todas formas.
 *
 * Si no hay MySQL, o el catálogo no está cargado, los tests se saltan en vez de
 * fallar: la suite unitaria tiene que seguir corriendo sin base de datos.
 */
final class MySqlOrbDescriptorRepositoryTest extends TestCase
{
    /** Una carta muy reimpresa: garantiza varias impresiones bajo el mismo oracle_id. */
    private const CARTA = 'Lightning Bolt';

    /** Un uuid con forma canónica que el catálogo no tiene: la FK que INSERT IGNORE se traga. */
    private const UUID_FANTASMA = 'ffffffff-ffff-4fff-8fff-ffffffffffff';

    /** El idioma de las 2.638 filas que ya existían, y el defecto del contrato. */
    private const INGLES = 'English';

    /** El idioma con el que se prueba la tercera columna de la clave. */
    private const ESPANOL = 'Spanish';

    private static ?PDO $db = null;

    private MySqlOrbDescriptorRepository $repositorio;

    /** @var list<string> uuids tocados por el test, para limpiarlos al salir. */
    private array $sembrados = [];

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

        if (!$this->existeLaTabla()) {
            $this->markTestSkipped('Falta `mtg_printing_orb`: aplica la migración con ./dev-setup.sh --migrate.');
        }

        $this->repositorio = new MySqlOrbDescriptorRepository(self::$db);
    }

    protected function tearDown(): void
    {
        foreach ($this->sembrados as $uuid) {
            $stmt = self::$db?->prepare('DELETE FROM mtg_printing_orb WHERE printing_uuid = :uuid');
            $stmt?->execute(['uuid' => $uuid]);
        }

        $this->sembrados = [];
    }

    /**
     * El caso del `*Hecho cuando:*`: un `oracleId` real, **una fila por impresión
     * y todo a null**.
     */
    public function testDevuelveUnaFilaPorImpresionAunqueNingunaEsteSembrada(): void
    {
        [$oracleId, $impresiones] = $this->cartaDePrueba();

        $refs = $this->repositorio->refsDe($oracleId, self::INGLES);

        self::assertCount(
            $impresiones,
            $refs,
            'El LEFT JOIN tiene que devolver también las impresiones sin sembrar: son las que hay que ir a sembrar.'
        );

        foreach ($refs as $ref) {
            self::assertNull($ref['localPath']);
            self::assertNull($ref['keypoints']);
            self::assertNull($ref['nfeatures']);
            self::assertSame('front', $ref['face'], 'Sin fila, la cara por defecto es la frontal.');
            self::assertMatchesRegularExpression('/^[0-9a-f-]{36}$/', $ref['printingUuid']);
        }
    }

    /** Sembrada una cara, esa impresión trae sus datos y las demás siguen a null. */
    public function testLaImpresionSembradaTraeSuRutaYSusContadores(): void
    {
        [$oracleId, $impresiones] = $this->cartaDePrueba();
        $uuid = $this->repositorio->refsDe($oracleId, self::INGLES)[0]['printingUuid'];

        self::assertTrue($this->sembrar($uuid, 'front', 700, 686, 'vision/orb/xx/una-ruta.orb'));

        $refs = $this->repositorio->refsDe($oracleId, self::INGLES);

        self::assertCount($impresiones, $refs, 'Sembrar una cara no puede añadir ni perder filas.');

        $sembrada = null;
        foreach ($refs as $ref) {
            if ($ref['printingUuid'] === $uuid) {
                $sembrada = $ref;
            }
        }

        self::assertNotNull($sembrada);
        self::assertSame('front', $sembrada['face']);
        self::assertSame(700, $sembrada['nfeatures']);
        self::assertSame(686, $sembrada['keypoints'], 'El mínimo medido sobre 777 referencias con 700/normal.');
        self::assertSame('vision/orb/xx/una-ruta.orb', $sembrada['localPath']);
    }

    /**
     * Las dos caras de una misma impresión son **dos filas**: la PK es
     * `(printing_uuid, face)`.
     */
    public function testLasDosCarasDeUnaImpresionSonDosFilas(): void
    {
        [$oracleId, $impresiones] = $this->cartaDePrueba();
        $uuid = $this->repositorio->refsDe($oracleId, self::INGLES)[0]['printingUuid'];

        self::assertTrue($this->sembrar($uuid, 'front', 700, 700, 'vision/orb/xx/f.orb'));
        self::assertTrue($this->sembrar($uuid, 'back', 700, 512, 'vision/orb/xx/b.orb'));

        $refs  = $this->repositorio->refsDe($oracleId, self::INGLES);
        $caras = [];

        foreach ($refs as $ref) {
            if ($ref['printingUuid'] === $uuid) {
                $caras[] = $ref['face'];
            }
        }

        self::assertCount($impresiones + 1, $refs, 'Una impresión con dos caras aporta dos filas.');
        self::assertSame(['front', 'back'], $caras, 'El orden del ENUM: front antes que back, y estable entre llamadas.');
    }

    /**
     * **`INSERT IGNORE` no sobrescribe**, y eso es la contención contra el
     * envenenamiento del índice.
     */
    public function testSembrarDosVecesLaMismaCaraNoSobrescribeYDevuelveFalse(): void
    {
        [$oracleId] = $this->cartaDePrueba();
        $uuid = $this->repositorio->refsDe($oracleId, self::INGLES)[0]['printingUuid'];

        self::assertTrue($this->sembrar($uuid, 'front', 700, 700, 'vision/orb/xx/buena.orb'));
        self::assertFalse(
            $this->sembrar($uuid, 'front', 300, 12, 'vision/orb/xx/mala.orb'),
            'El primero que siembra una impresión la siembra; el segundo recibe un 409.'
        );

        $fila = $this->repositorio->refsDe($oracleId, self::INGLES);
        $encontrada = null;
        foreach ($fila as $ref) {
            if ($ref['printingUuid'] === $uuid) {
                $encontrada = $ref;
            }
        }

        self::assertSame('vision/orb/xx/buena.orb', $encontrada['localPath'], 'La fila buena sigue siendo la buena.');
        self::assertSame(700, $encontrada['nfeatures']);
    }

    /**
     * **`estadoDe()` no puede dar `SQLSTATE[HY093]`.**
     *
     * Son dos `EXISTS` que preguntan por el MISMO uuid, y con
     * `ATTR_EMULATE_PREPARES = false` reutilizar el nombre del marcador revienta.
     * Este test es lo único que lo ve: un doble responde igual con una sentencia
     * rota.
     */
    public function testEstadoDeDistingueLasDosPreguntasSinReutilizarElMarcador(): void
    {
        [$oracleId] = $this->cartaDePrueba();
        $uuid = $this->repositorio->refsDe($oracleId, self::INGLES)[0]['printingUuid'];

        self::assertSame(
            ['existe' => true, 'sembrada' => false],
            $this->repositorio->estadoDe($uuid, 'front', self::INGLES)
        );

        $this->sembrar($uuid, 'front', 700, 700, 'vision/orb/xx/f.orb');

        self::assertSame(
            ['existe' => true, 'sembrada' => true],
            $this->repositorio->estadoDe($uuid, 'front', self::INGLES)
        );

        self::assertSame(
            ['existe' => true, 'sembrada' => false],
            $this->repositorio->estadoDe($uuid, 'back', self::INGLES),
            'La otra cara de la MISMA impresión sigue sin sembrar.'
        );

        self::assertSame(
            ['existe' => true, 'sembrada' => false],
            $this->repositorio->estadoDe($uuid, 'front', self::ESPANOL),
            'Sembrada en inglés, la MISMA cara sigue sembrable en español: es el M6 entero.'
        );

        self::assertSame(
            ['existe' => false, 'sembrada' => false],
            $this->repositorio->estadoDe(self::UUID_FANTASMA, 'front', self::INGLES)
        );
    }

    /**
     * **La trampa que el plan avisa: `INSERT IGNORE` se traga también la clave
     * ajena rota.**
     *
     * Sembrar un `printing_uuid` que no está en el catálogo devuelve `false` —el
     * mismo `false` que «ya estaba»— y no escribe una fila. Sin `estadoDe()`
     * delante, el controller lo leería como 409 o, peor, como 204, y esa carta
     * no se sembraría **nunca**.
     */
    public function testSembrarUnaImpresionQueNoExisteNoEscribeNadaYNoSeQueja(): void
    {
        self::assertFalse(
            $this->sembrar(self::UUID_FANTASMA, 'front', 700, 700, 'vision/orb/ff/fantasma.orb'),
            'INSERT IGNORE se traga la FK rota: por eso la validación va ANTES y explícita.'
        );

        $stmt = self::$db->prepare('SELECT COUNT(*) FROM mtg_printing_orb WHERE printing_uuid = :uuid');
        $stmt->execute(['uuid' => self::UUID_FANTASMA]);

        self::assertSame(0, (int) $stmt->fetchColumn());
    }

    // ========================================================================
    // El idioma (M6)
    // ========================================================================

    /**
     * **La caída al inglés**: sembrada solo en inglés, se sirve a quien pide
     * español y la respuesta dice que mandó la inglesa.
     *
     * Es lo que hace que el M6 **nunca empeore** lo de hoy: las 2.638 filas ya
     * sembradas siguen sirviendo a todo el mundo.
     */
    public function testSembradaSoloEnInglesSeSirveAQuienPideEspanolYLoDice(): void
    {
        [$oracleId] = $this->cartaDePrueba();
        $uuid = $this->repositorio->refsDe($oracleId, self::INGLES)[0]['printingUuid'];

        self::assertTrue($this->sembrar($uuid, 'front', 700, 700, 'vision/orb/xx/en.orb'));

        $fila = $this->filaDe($this->repositorio->refsDe($oracleId, self::ESPANOL), $uuid);

        self::assertSame('vision/orb/xx/en.orb', $fila['localPath'], 'La inglesa vale como referencia.');
        self::assertSame(self::INGLES, $fila['language'], 'Y la respuesta dice cuál mandó.');
    }

    /**
     * Sembrada en los dos idiomas, **manda la del pedido y sigue siendo UNA fila
     * por cara**.
     *
     * Lo segundo es lo que el CTE existe para garantizar: con dos `LEFT JOIN` a
     * la misma tabla, esta impresión saldría dos veces y el móvil casaría la
     * misma carta contra dos referencias contándolas como impresiones distintas.
     */
    public function testConLosDosIdiomasSembradosMandaElPedidoYNoDuplicaLaFila(): void
    {
        [$oracleId, $impresiones] = $this->cartaDePrueba();
        $uuid = $this->repositorio->refsDe($oracleId, self::INGLES)[0]['printingUuid'];

        self::assertTrue($this->sembrar($uuid, 'front', 700, 700, 'vision/orb/xx/en.orb'));
        self::assertTrue(
            $this->sembrar($uuid, 'front', 700, 640, 'vision/orb/xx/es.orb', self::ESPANOL),
            'La misma cara en otro idioma NO es un duplicado: la PK lleva tres columnas.'
        );

        $refs = $this->repositorio->refsDe($oracleId, self::ESPANOL);

        self::assertCount($impresiones, $refs, 'Dos idiomas de la misma cara son UNA fila en la respuesta.');

        $fila = $this->filaDe($refs, $uuid);

        self::assertSame('vision/orb/xx/es.orb', $fila['localPath']);
        self::assertSame(self::ESPANOL, $fila['language']);
        self::assertSame(640, $fila['keypoints']);

        // Y al revés: quien pide inglés sigue recibiendo la inglesa.
        self::assertSame(
            'vision/orb/xx/en.orb',
            $this->filaDe($this->repositorio->refsDe($oracleId, self::INGLES), $uuid)['localPath']
        );
    }

    /**
     * **El `scryfallId` es el de la traducción cuando MTGJSON lo publica.**
     *
     * Es la imagen que el móvil va a bajar para sembrar, y la mitad del hito:
     * con la inglesa, el 70,9 % de los keypoints de una carta española no casa
     * con nada. Se salta si la columna está vacía, que es el estado anterior a
     * `catalog:localized-ids`.
     */
    public function testElScryfallIdEsElLocalizadoCuandoMtgjsonLoPublica(): void
    {
        $stmt = self::$db->query(
            "SELECT p.oracle_id, l.printing_uuid, l.scryfall_id
               FROM mtg_printing_localized l
               JOIN mtg_printing p ON p.uuid = l.printing_uuid
              WHERE l.language = 'Spanish'
                AND l.scryfall_id IS NOT NULL
                AND l.scryfall_id <> p.scryfall_id
              LIMIT 1"
        );
        $fuente = $stmt->fetch();

        if ($fuente === false) {
            $this->markTestSkipped('Sin ids localizados en la BD: lanza `catalog:localized-ids`.');
        }

        $fila = $this->filaDe(
            $this->repositorio->refsDe((string) $fuente['oracle_id'], self::ESPANOL),
            (string) $fuente['printing_uuid']
        );

        self::assertSame((string) $fuente['scryfall_id'], $fila['scryfallId']);

        // Y en inglés sigue saliendo el de `mtg_printing`, que es exactamente lo
        // que devolvía antes del M6.
        $enIngles = $this->filaDe(
            $this->repositorio->refsDe((string) $fuente['oracle_id'], self::INGLES),
            (string) $fuente['printing_uuid']
        );

        self::assertNotSame((string) $fuente['scryfall_id'], $enIngles['scryfallId']);
    }

    /**
     * **La enmienda del 2026-09-16: `scryfallLanguage` sobre una impresión que no
     * existe en el idioma pedido**, que es el 52 % del catálogo.
     *
     * Aquí se mide contra la BD real porque es donde vive el defecto: el
     * `COALESCE(l.scryfall_id, p.scryfall_id)` cae al id inglés **en silencio**
     * mientras `COALESCE(c.language, :idioma_salida)` sigue diciendo el pedido.
     * Un cliente que sellara la siembra con ese `language` subiría descriptores
     * ingleses etiquetados `Spanish`, y con `INSERT IGNORE` eso **no se puede
     * deshacer**.
     *
     * La impresión la busca el propio test con un `NOT EXISTS`, porque los uuid
     * los rehace cada `catalog:import` y no son contrato nuestro.
     */
    public function testSinFilaEnElIdiomaPedidoElIdiomaDeLaImagenEsIngles(): void
    {
        $stmt = self::$db->query(
            "SELECT p.oracle_id, p.uuid, p.scryfall_id
               FROM mtg_printing p
              WHERE p.scryfall_id IS NOT NULL
                AND NOT EXISTS (SELECT 1
                                  FROM mtg_printing_localized l
                                 WHERE l.printing_uuid = p.uuid
                                   AND l.language      = 'Spanish')
                -- Y sin sembrar en ninguno: así el `language` de la fila es el
                -- PEDIDO y se ve que los dos campos son independientes. Sobre
                -- una de las 2.638 ya sembradas en inglés, los dos dirían
                -- `English` por motivos distintos y el test no probaría nada.
                AND NOT EXISTS (SELECT 1
                                  FROM mtg_printing_orb o
                                 WHERE o.printing_uuid = p.uuid)
              LIMIT 1"
        );
        $fuente = $stmt->fetch();

        if ($fuente === false) {
            $this->markTestSkipped('El catálogo no está cargado: sin impresiones que mirar.');
        }

        $fila = $this->filaDe(
            $this->repositorio->refsDe((string) $fuente['oracle_id'], self::ESPANOL),
            (string) $fuente['uuid']
        );

        self::assertSame(
            (string) $fuente['scryfall_id'],
            $fila['scryfallId'],
            'Sin traducción, la imagen que se manda es la inglesa de `mtg_printing`.'
        );
        self::assertSame(
            self::INGLES,
            $fila['scryfallLanguage'],
            'Y la respuesta lo DICE. Sellar esa siembra como `Spanish` la bloquearía para siempre.'
        );
        self::assertSame(
            self::ESPANOL,
            $fila['language'],
            'El idioma de la REFERENCIA sigue siendo el pedido: sin sembrar, es en el que hay que sembrarla.'
        );
    }

    /**
     * Con traducción publicada, `scryfallLanguage` es el idioma **pedido**: es la
     * otra mitad de la enmienda, y sin ella el campo podría ser un `'English'`
     * constante sin que nadie se enterase.
     */
    public function testConImagenLocalizadaElIdiomaDeLaImagenEsElPedido(): void
    {
        $stmt = self::$db->query(
            "SELECT p.oracle_id, l.printing_uuid, l.scryfall_id
               FROM mtg_printing_localized l
               JOIN mtg_printing p ON p.uuid = l.printing_uuid
              WHERE l.language = 'Spanish'
                AND l.scryfall_id IS NOT NULL
              LIMIT 1"
        );
        $fuente = $stmt->fetch();

        if ($fuente === false) {
            $this->markTestSkipped('Sin ids localizados en la BD: lanza `catalog:localized-ids`.');
        }

        $fila = $this->filaDe(
            $this->repositorio->refsDe((string) $fuente['oracle_id'], self::ESPANOL),
            (string) $fuente['printing_uuid']
        );

        self::assertSame((string) $fuente['scryfall_id'], $fila['scryfallId']);
        self::assertSame(self::ESPANOL, $fila['scryfallLanguage']);

        // Y quien pide inglés recibe la inglesa y lo dice: es el comportamiento
        // anterior a la enmienda, intacto.
        $enIngles = $this->filaDe(
            $this->repositorio->refsDe((string) $fuente['oracle_id'], self::INGLES),
            (string) $fuente['printing_uuid']
        );

        self::assertSame(self::INGLES, $enIngles['scryfallLanguage']);
    }

    /**
     * `olvidar()` borra **solo el idioma que se le dice**.
     *
     * Sin el idioma en el `DELETE`, deshacer una siembra española se llevaría por
     * delante la inglesa, que está buena y costó tiempo de móvil real.
     */
    public function testOlvidarNoSeLlevaPorDelanteLosOtrosIdiomas(): void
    {
        [$oracleId] = $this->cartaDePrueba();
        $uuid = $this->repositorio->refsDe($oracleId, self::INGLES)[0]['printingUuid'];

        $this->sembrar($uuid, 'front', 700, 700, 'vision/orb/xx/en.orb');
        $this->sembrar($uuid, 'front', 700, 640, 'vision/orb/xx/es.orb', self::ESPANOL);

        $this->repositorio->olvidar($uuid, 'front', self::ESPANOL);

        self::assertFalse($this->repositorio->estadoDe($uuid, 'front', self::ESPANOL)['sembrada']);
        self::assertTrue(
            $this->repositorio->estadoDe($uuid, 'front', self::INGLES)['sembrada'],
            'La inglesa sigue ahí.'
        );
    }

    /** `olvidar()` es la vuelta atrás del renombrado fallido, y deja la cara sembrable otra vez. */
    public function testOlvidarDejaLaCaraSembrableDeNuevo(): void
    {
        [$oracleId] = $this->cartaDePrueba();
        $uuid = $this->repositorio->refsDe($oracleId, self::INGLES)[0]['printingUuid'];

        $this->sembrar($uuid, 'front', 700, 700, 'vision/orb/xx/f.orb');
        $this->repositorio->olvidar($uuid, 'front', self::INGLES);

        self::assertFalse($this->repositorio->estadoDe($uuid, 'front', self::INGLES)['sembrada']);
        self::assertTrue($this->sembrar($uuid, 'front', 700, 700, 'vision/orb/xx/f.orb'));
    }

    /** Un `oracle_id` que no existe devuelve lista vacía, no una excepción. */
    public function testUnOracleIdDesconocidoDevuelveListaVacia(): void
    {
        self::assertSame([], $this->repositorio->refsDe(self::UUID_FANTASMA, self::INGLES));
    }

    // ========================================================================
    // Auxiliares
    // ========================================================================

    /**
     * Un `oracle_id` real con varias impresiones, y cuántas son.
     *
     * Se exige que ninguna esté sembrada al empezar: si lo estuviera, los
     * recuentos de arriba medirían otra cosa. En una BD de dev recién migrada
     * la tabla está vacía, y este test limpia lo suyo al salir.
     *
     * @return array{0: string, 1: int}
     */
    private function cartaDePrueba(): array
    {
        $stmt = self::$db->prepare(
            'SELECT p.oracle_id, COUNT(*) AS impresiones
               FROM mtg_printing p
               JOIN mtg_card c ON c.oracle_id = p.oracle_id
              WHERE c.name = :nombre
           GROUP BY p.oracle_id
           ORDER BY impresiones DESC
              LIMIT 1'
        );

        $stmt->execute(['nombre' => self::CARTA]);
        $fila = $stmt->fetch();

        if ($fila === false) {
            $this->markTestSkipped('El catálogo no está cargado: falta ' . self::CARTA . '.');
        }

        $oracleId = (string) $fila['oracle_id'];

        $yaSembradas = self::$db->prepare(
            'SELECT COUNT(*)
               FROM mtg_printing_orb o
               JOIN mtg_printing p ON p.uuid = o.printing_uuid
              WHERE p.oracle_id = :oracle_id'
        );
        $yaSembradas->execute(['oracle_id' => $oracleId]);

        if ((int) $yaSembradas->fetchColumn() > 0) {
            $this->markTestSkipped('Esa carta ya tiene descriptores sembrados: los recuentos medirían otra cosa.');
        }

        return [$oracleId, (int) $fila['impresiones']];
    }

    /**
     * La fila de una impresión concreta dentro de una respuesta de `refsDe()`.
     *
     * @param  list<array<string, mixed>> $refs
     * @return array<string, mixed>
     */
    private function filaDe(array $refs, string $uuid): array
    {
        foreach ($refs as $ref) {
            if ($ref['printingUuid'] === $uuid) {
                return $ref;
            }
        }

        self::fail("La respuesta no trae la impresión {$uuid}.");
    }

    /** Siembra anotando el uuid para limpiarlo en `tearDown()`. */
    private function sembrar(
        string $uuid,
        string $face,
        int $nfeatures,
        int $keypoints,
        string $ruta,
        string $language = self::INGLES
    ): bool {
        $this->sembrados[] = $uuid;

        return $this->repositorio->sembrar($uuid, $face, $language, $nfeatures, $keypoints, $ruta);
    }

    private function existeLaTabla(): bool
    {
        return self::$db?->query("SHOW TABLES LIKE 'mtg_printing_orb'")->fetchColumn() !== false;
    }
}
