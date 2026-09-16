<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Controllers\VisionController;
use App\Domain\Collection\CardLanguage;
use App\Infrastructure\Vision\OrbDescriptorStore;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Tests\Unit\Doubles\DescriptoresOrbFalsos;

/**
 * Las dos acciones del índice ORB, con el almacén de disco **de verdad**.
 *
 * El repositorio va doblado —su SQL lo prueba `MySqlOrbDescriptorRepositoryTest`
 * contra MySQL— pero `OrbDescriptorStore` **no**: escribe en un directorio
 * temporal real. Doblarlo dejaría sin probar lo único que el `*Hecho cuando:*`
 * mide de verdad — que el fichero aparece con el tamaño exacto y que **el
 * segundo envío no lo reescribe** —, y esa es exactamente la clase de cobertura
 * de adorno que este repo no escribe.
 *
 * Los cuatro comportamientos del hito, uno por test:
 *
 *  - `scan_orb_refs` con una carta sin sembrar → una fila por impresión, `orb: null`
 *  - `vision_orb_store` con `keypoints*40` bytes → 204, fichero y fila
 *  - el mismo cuerpo dos veces → 409 **sin reescribir el fichero**
 *  - una longitud que no es múltiplo de 40 → 400
 */
final class VisionControllerTest extends TestCase
{
    /** Tres impresiones reales de forma: uuid canónico en minúsculas. */
    private const ORACLE = 'd4246e4d-390d-4925-a5a8-89cd096a237c';
    private const UUID_A = '00010d56-fe38-5e35-8aed-518019aa36a5';
    private const UUID_B = '0001e0d0-2dcd-5640-aadc-a84765cf5fc9';
    private const UUID_C = '0003caab-9ff5-5d1a-bc06-976dd0457f19';

    /** El `identifiers.scryfallId` que MTGJSON trae dentro del `foreignData` español. */
    private const ID_ESPANOL = '1e59f85a-1111-4222-8333-444455556666';

    private string $storage;
    private DescriptoresOrbFalsos $repo;
    private OrbDescriptorStore $almacen;
    private VisionController $controller;

    protected function setUp(): void
    {
        $this->storage = sys_get_temp_dir() . '/orb-test-' . bin2hex(random_bytes(6));
        mkdir($this->storage, 0775, true);

        $this->repo    = new DescriptoresOrbFalsos();
        $this->almacen = new OrbDescriptorStore($this->storage);

        $this->repo->enCatalogo           = [self::UUID_A, self::UUID_B, self::UUID_C];
        $this->repo->impresionesPorOracle = [
            self::ORACLE => [
                // La A tiene imagen española en MTGJSON; la B no, y cae al
                // inglés; la C no tiene ni inglesa. Los tres casos que la
                // consulta real resuelve con un COALESCE.
                [
                    'printingUuid'        => self::UUID_A,
                    'scryfallId'          => 'f6555d1f-d4cf-41f7-99d3-88fd53e75457',
                    'scryfallIdPorIdioma' => ['Spanish' => self::ID_ESPANOL],
                ],
                ['printingUuid' => self::UUID_B, 'scryfallId' => 'e3094187-d666-414b-a1fd-ae0ef55c3fcb'],
                ['printingUuid' => self::UUID_C, 'scryfallId' => null],
            ],
        ];

        $this->controller = new VisionController($this->repo, $this->almacen, new NullLogger());
    }

    protected function tearDown(): void
    {
        $this->borrarArbol($this->storage);
    }

    // ========================================================================
    // scan_orb_refs
    // ========================================================================

    /**
     * El primer comportamiento del `*Hecho cuando:*`: **una fila por impresión,
     * con `orb: null`**.
     *
     * Que una carta sin sembrar devuelva la lista completa y no una lista vacía
     * es el motivo entero de la acción: el móvil no puede sembrar lo que no sabe
     * que le falta. Una respuesta vacía sería indistinguible de «esta carta no
     * existe» y la siembra perezosa del M2 no arrancaría nunca.
     */
    public function testUnaCartaSinSembrarDevuelveUnaFilaPorImpresionConOrbANull(): void
    {
        $r = $this->controller->orbRefs($this->peticion(['oracleId' => self::ORACLE]));

        self::assertSame('success', $r['status']);
        self::assertCount(3, $r['data']['refs']);

        foreach ($r['data']['refs'] as $ref) {
            self::assertNull($ref['orb'], 'Sin sembrar, `orb` es null y no una cadena vacía.');
            self::assertNull($ref['keypoints'], '`keypoints` sin bloque es una promesa que nadie puede cumplir.');
            self::assertNull($ref['nfeatures']);
            self::assertSame('front', $ref['face']);
            self::assertSame(
                [
                    'printingUuid',
                    'face',
                    'language',
                    'scryfallId',
                    'scryfallLanguage',
                    'keypoints',
                    'nfeatures',
                    'orb',
                ],
                array_keys($ref),
                'El contrato tiene SIEMPRE las mismas claves, sembrada o no.'
            );
        }

        self::assertSame(self::UUID_A, $r['data']['refs'][0]['printingUuid']);
        self::assertNull($r['data']['refs'][2]['scryfallId'], 'Sin scryfall_id no hay imagen que bajar, y no es un error.');
    }

    /**
     * Sembrada una cara, esa impresión viaja con su bloque en base64 y las otras
     * dos siguen a null. La respuesta es una mezcla, no dos modos.
     */
    public function testLaImpresionSembradaViajaConSuBloqueYLasDemasSiguenANull(): void
    {
        $bloque = $this->bloque(700);
        $this->sembrar(self::UUID_B, 'front', 700, 700, $bloque);

        $r = $this->controller->orbRefs($this->peticion(['oracleId' => self::ORACLE]));

        $porUuid = [];
        foreach ($r['data']['refs'] as $ref) {
            $porUuid[$ref['printingUuid']] = $ref;
        }

        self::assertCount(3, $porUuid);
        self::assertNull($porUuid[self::UUID_A]['orb']);
        self::assertNull($porUuid[self::UUID_C]['orb']);

        self::assertSame(700, $porUuid[self::UUID_B]['keypoints']);
        self::assertSame(700, $porUuid[self::UUID_B]['nfeatures']);
        self::assertSame($bloque, base64_decode($porUuid[self::UUID_B]['orb'], true));
        self::assertSame(
            28000,
            strlen(base64_decode($porUuid[self::UUID_B]['orb'], true)),
            '700 keypoints x 40 B: los 28.000 B por cara que el M0(b) midió.'
        );
    }

    /**
     * Fila sin fichero → se sirve como NO sembrada.
     *
     * Un `storage/` vaciado a mano deja la tabla entera así. Devolver `orb` con
     * una cadena vacía, o reventar, dejaría el escáner sin salida; devolverla
     * como no sembrada la manda a la siembra perezosa, que es el camino que ya
     * existe.
     */
    public function testUnaFilaSinFicheroSeSirveComoNoSembrada(): void
    {
        $this->sembrar(self::UUID_B, 'front', 700, 700, $this->bloque(700));
        unlink($this->almacen->rutaAbsoluta($this->almacen->rutaRelativa(self::UUID_B, 'front', 'English')));

        $r = $this->controller->orbRefs($this->peticion(['oracleId' => self::ORACLE]));

        foreach ($r['data']['refs'] as $ref) {
            self::assertNull($ref['orb']);
            self::assertNull($ref['keypoints']);
        }
    }

    public function testSinOracleIdNoSePregunta(): void
    {
        self::assertSame(422, $this->controller->orbRefs($this->peticion([]))['http_code']);
        self::assertSame(422, $this->controller->orbRefs($this->peticion(['oracleId' => 'lo-que-sea']))['http_code']);
    }

    // ========================================================================
    // scan_orb_refs · el idioma (M6)
    // ========================================================================

    /**
     * **El test que prueba que no se ha roto nada**: sin `language`, la respuesta
     * es exactamente la de antes del M6.
     *
     * Ausencia = `English`, y eso no es cortesía: es lo único que mantiene vivo
     * al APK que ya está instalado, que manda esta acción sin ese campo.
     */
    public function testSinLanguageLaRespuestaEsLaDeHoy(): void
    {
        $this->sembrar(self::UUID_B, 'front', 700, 700, $this->bloque(700));

        $r = $this->controller->orbRefs($this->peticion(['oracleId' => self::ORACLE]));

        self::assertSame('success', $r['status']);
        self::assertCount(3, $r['data']['refs']);

        $porUuid = [];
        foreach ($r['data']['refs'] as $ref) {
            self::assertSame('English', $ref['language'], 'Sin `language`, todo es inglés, como antes.');
            $porUuid[$ref['printingUuid']] = $ref;
        }

        self::assertSame(
            'f6555d1f-d4cf-41f7-99d3-88fd53e75457',
            $porUuid[self::UUID_A]['scryfallId'],
            'En inglés se sirve el id de `mtg_printing`, nunca el localizado.'
        );
        self::assertNotNull($porUuid[self::UUID_B]['orb']);
    }

    /**
     * Con `language: "Spanish"`, **el `scryfallId` español cuando existe y el
     * inglés cuando no**.
     *
     * Es el id que el móvil usa para bajar la imagen con la que va a sembrar, y
     * es la mitad del hito: sembrar la inglesa para una carta española tira el
     * 70,9 % de los keypoints.
     */
    public function testConLanguageSpanishSirveElIdEspanolYCaeAlInglesCuandoFalta(): void
    {
        $r = $this->controller->orbRefs($this->peticion([
            'oracleId' => self::ORACLE,
            'language' => 'Spanish',
        ]));

        $porUuid = [];
        foreach ($r['data']['refs'] as $ref) {
            $porUuid[$ref['printingUuid']] = $ref;
        }

        self::assertSame(self::ID_ESPANOL, $porUuid[self::UUID_A]['scryfallId']);
        self::assertSame(
            'e3094187-d666-414b-a1fd-ae0ef55c3fcb',
            $porUuid[self::UUID_B]['scryfallId'],
            'Sin id español en MTGJSON se cae al inglés: es el COALESCE de la consulta.'
        );
        self::assertNull($porUuid[self::UUID_C]['scryfallId']);
    }

    /**
     * **La enmienda del 2026-09-16: `scryfallLanguage` dice el idioma de la
     * IMAGEN, no el pedido.**
     *
     * El caso que importa es el de la B, una impresión **sin fila en el idioma
     * pedido** — que son **57.341 de las 110.384 (el 52 %)** del catálogo, no un
     * borde. Ahí el `scryfallId` cae al inglés **en silencio** y el `language` de
     * la fila seguía diciendo `Spanish`: el móvil bajaría la imagen inglesa,
     * le sacaría descriptores y los sellaría como españoles. Con `INSERT IGNORE`
     * esa fila **bloquea para siempre** la siembra buena.
     *
     * Los tres casos, en la misma respuesta: la A tiene imagen española, la B cae
     * al inglés y la C no tiene ninguna —y aun sin imagen que bajar el campo dice
     * de qué idioma habría sido, que es `English`—.
     */
    public function testCadaRefDiceElIdiomaRealDeLaImagenQueManda(): void
    {
        // La B se siembra en español a propósito: `language` dirá `Spanish` y
        // `scryfallLanguage` tiene que seguir diciendo `English`. Son dos
        // preguntas distintas —qué referencia sirvo y qué imagen hay que bajar—
        // y con una sola el fallo pasa desapercibido.
        $this->sembrar(self::UUID_B, 'front', 700, 700, $this->bloque(700), 'Spanish');

        $r = $this->controller->orbRefs($this->peticion([
            'oracleId' => self::ORACLE,
            'language' => 'Spanish',
        ]));

        $porUuid = [];
        foreach ($r['data']['refs'] as $ref) {
            $porUuid[$ref['printingUuid']] = $ref;
        }

        self::assertSame(
            'Spanish',
            $porUuid[self::UUID_A]['scryfallLanguage'],
            'Con imagen localizada, lo que se baja ES español.'
        );

        self::assertSame(
            'English',
            $porUuid[self::UUID_B]['scryfallLanguage'],
            'Sin fila en español el `scryfallId` cae al inglés, y el cliente tiene que saberlo: '
            . 'sembrar eso como `Spanish` envenena el índice para siempre.'
        );
        self::assertSame(
            'Spanish',
            $porUuid[self::UUID_B]['language'],
            'Y `language` sigue siendo el de la referencia servida: son dos campos porque son dos cosas.'
        );

        self::assertSame(
            'English',
            $porUuid[self::UUID_C]['scryfallLanguage'],
            'Sin imagen ninguna el campo no puede faltar: el contrato tiene siempre las mismas claves.'
        );
    }

    /**
     * Sin `language`, `scryfallLanguage` es `English` en todas: el APK viejo no
     * ve un campo nuevo que le cambie nada.
     */
    public function testSinLanguageElIdiomaDeLaImagenEsInglesEnTodas(): void
    {
        $r = $this->controller->orbRefs($this->peticion(['oracleId' => self::ORACLE]));

        foreach ($r['data']['refs'] as $ref) {
            self::assertSame('English', $ref['scryfallLanguage']);
        }
    }

    /**
     * **La caída al inglés, y la marca de cuál mandó.**
     *
     * Una impresión sembrada solo en inglés se sigue sirviendo a quien pide
     * español: es lo que hace que este cambio **nunca empeore** lo de hoy. Lo que
     * no puede hacer es mentir sobre qué mandó, y por eso `language` viaja en la
     * fila.
     */
    public function testSiNoEstaSembradaEnEseIdiomaMandaLaInglesaYLoDice(): void
    {
        $bloque = $this->bloque(700);
        $this->sembrar(self::UUID_B, 'front', 700, 700, $bloque, 'English');

        $r = $this->controller->orbRefs($this->peticion([
            'oracleId' => self::ORACLE,
            'language' => 'Spanish',
        ]));

        $porUuid = [];
        foreach ($r['data']['refs'] as $ref) {
            $porUuid[$ref['printingUuid']] = $ref;
        }

        self::assertSame($bloque, base64_decode($porUuid[self::UUID_B]['orb'], true));
        self::assertSame('English', $porUuid[self::UUID_B]['language'], 'Mandó la inglesa y lo dice.');
        self::assertSame(
            'Spanish',
            $porUuid[self::UUID_A]['language'],
            'Sin sembrar en ninguno, el idioma que sale es el PEDIDO: es en el que hay que sembrarla.'
        );
    }

    /** Sembradas las dos, manda la del idioma pedido y **una sola fila por cara**. */
    public function testConLasDosSembradasMandaLaDelIdiomaPedidoYNoDuplicaFilas(): void
    {
        $ingles  = $this->bloque(700, 0x41);
        $espanol = $this->bloque(700, 0x42);

        $this->sembrar(self::UUID_B, 'front', 700, 700, $ingles, 'English');
        $this->sembrar(self::UUID_B, 'front', 700, 700, $espanol, 'Spanish');

        $r = $this->controller->orbRefs($this->peticion([
            'oracleId' => self::ORACLE,
            'language' => 'Spanish',
        ]));

        self::assertCount(3, $r['data']['refs'], 'Dos idiomas de la misma cara son UNA fila en la respuesta.');

        $porUuid = [];
        foreach ($r['data']['refs'] as $ref) {
            $porUuid[$ref['printingUuid']] = $ref;
        }

        self::assertSame('Spanish', $porUuid[self::UUID_B]['language']);
        self::assertSame($espanol, base64_decode($porUuid[self::UUID_B]['orb'], true));
    }

    /** Un idioma que el catálogo no conoce es un 422, no un inglés silencioso. */
    public function testUnIdiomaDesconocidoNoSeSirveComoIngles(): void
    {
        $r = $this->controller->orbRefs($this->peticion([
            'oracleId' => self::ORACLE,
            'language' => 'Klingon',
        ]));

        self::assertSame(422, $r['http_code']);
        self::assertSame('error', $r['status']);
    }

    // ========================================================================
    // vision_orb_store
    // ========================================================================

    /**
     * El segundo comportamiento: **el fichero y la fila**.
     *
     * Se comprueban las tres cosas que importan: el código, el tamaño exacto en
     * disco y la ruta que quedó en la fila — que es RELATIVA a `storage/`, como
     * `mtg_image_cache.local_path`, para que mover el directorio no invalide la
     * tabla entera.
     */
    public function testSiembraElFicheroYLaFilaConElTroceadoPorPrefijo(): void
    {
        $bloque = $this->bloque(700);

        $r = $this->controller->orbStore($this->peticion([
            'printingUuid' => self::UUID_A,
            'face'         => 'front',
            'nfeatures'    => 700,
            'keypoints'    => 700,
            'orb'          => base64_encode($bloque),
        ]));

        self::assertSame(204, $r['http_code']);

        // El idioma va en el nombre desde el M6: sin él, dos idiomas de la misma
        // cara se pisarían el fichero.
        $relativa = 'vision/orb/00/' . self::UUID_A . '-front-english.orb';
        self::assertSame($relativa, $this->repo->filas[self::UUID_A . '|front|English']['localPath']);
        self::assertSame(700, $this->repo->filas[self::UUID_A . '|front|English']['keypoints']);

        $absoluta = $this->storage . '/' . $relativa;
        self::assertFileExists($absoluta);
        self::assertSame(28000, filesize($absoluta));
        self::assertSame($bloque, file_get_contents($absoluta));

        self::assertFileDoesNotExist($absoluta . '.parcial', 'El `.parcial` se renombra, no se queda.');
    }

    /**
     * El tercer comportamiento: **el mismo cuerpo dos veces devuelve 409 y NO
     * reescribe el fichero**.
     *
     * Lo segundo es lo que de verdad protege el índice, y no se puede comprobar
     * mirando el código: se comprueba mirando el contenido, que sigue siendo el
     * de la primera siembra aunque el segundo envío traiga otros bytes con la
     * misma forma. Un `ON DUPLICATE KEY UPDATE` —lo que hace `mtg_image_cache`—
     * pondría los segundos, y ese es exactamente el envenenamiento del índice.
     */
    public function testElMismoCuerpoDosVecesDevuelve409YNoReescribeElFichero(): void
    {
        $bueno = $this->bloque(700, 0x41);
        $malo  = $this->bloque(700, 0x00);

        $primera = $this->controller->orbStore($this->peticion([
            'printingUuid' => self::UUID_A,
            'face'         => 'front',
            'nfeatures'    => 700,
            'keypoints'    => 700,
            'orb'          => base64_encode($bueno),
        ]));

        self::assertSame(204, $primera['http_code']);

        $absoluta = $this->storage . '/vision/orb/00/' . self::UUID_A . '-front-english.orb';

        $segunda = $this->controller->orbStore($this->peticion([
            'printingUuid' => self::UUID_A,
            'face'         => 'front',
            'nfeatures'    => 700,
            'keypoints'    => 700,
            'orb'          => base64_encode($malo),
        ]));

        self::assertSame(409, $segunda['http_code']);
        self::assertSame('error', $segunda['status']);
        self::assertSame($bueno, file_get_contents($absoluta), 'El segundo envío NO reescribe el fichero.');
        self::assertFileDoesNotExist($absoluta . '.parcial', 'Un 409 no llega siquiera a escribir el `.parcial`.');
    }

    /** La otra cara de la misma impresión **sí** se siembra: la PK lleva la cara. */
    public function testLaCaraDeAtrasEsOtraFilaYOtroFichero(): void
    {
        foreach (['front', 'back'] as $face) {
            $r = $this->controller->orbStore($this->peticion([
                'printingUuid' => self::UUID_A,
                'face'         => $face,
                'nfeatures'    => 700,
                'keypoints'    => 500,
                'orb'          => base64_encode($this->bloque(500)),
            ]));

            self::assertSame(204, $r['http_code'], "La cara {$face} tiene que poder sembrarse.");
            self::assertFileExists($this->storage . '/vision/orb/00/' . self::UUID_A . '-' . $face . '-english.orb');
        }

        self::assertCount(2, $this->repo->filas);
    }

    /**
     * El cuarto comportamiento: **una longitud que no es múltiplo de 40 → 400**.
     *
     * Los tres casos del proveedor son los tres que de verdad llegan:
     * `keypoints*32` es el **contrato viejo**, el que se enmendó el 2026-09-15 al
     * descubrir que sin coordenadas no hay homografía ni inliers ni margen;
     * `keypoints*40 ± 1` es el bloque truncado o con un byte de relleno. Ninguno
     * puede entrar: un `cv.Mat` con la forma equivocada **empareja sin quejarse y
     * devuelve basura**.
     */
    #[DataProvider('longitudesQueNoSonMultiploDeCuarenta')]
    public function testUnaLongitudQueNoCuadraConLosKeypointsDevuelve400(int $bytes, string $porQue): void
    {
        $r = $this->controller->orbStore($this->peticion([
            'printingUuid' => self::UUID_A,
            'face'         => 'front',
            'nfeatures'    => 700,
            'keypoints'    => 700,
            'orb'          => base64_encode(str_repeat("\x41", $bytes)),
        ]));

        self::assertSame(400, $r['http_code'], $porQue);
        self::assertSame([], $this->repo->filas, 'Un 400 no deja fila.');
        self::assertDirectoryDoesNotExist($this->storage . '/vision/orb', 'Un 400 no toca el disco.');
    }

    /** @return array<string, array{int, string}> */
    public static function longitudesQueNoSonMultiploDeCuarenta(): array
    {
        return [
            'el contrato viejo, keypoints*32' => [700 * 32, 'Sin coordenadas no hay inliers y no hay margen que calcular.'],
            'un byte de menos'                => [700 * 40 - 1, 'Un bloque truncado no es un bloque.'],
            'un byte de relleno'              => [700 * 40 + 1, 'Un byte de más es relleno, y el relleno es lo que esta validación descarta.'],
            'keypoints*40 de OTRO keypoints'  => [699 * 40, 'La longitud tiene que cuadrar con el `keypoints` declarado, no ser múltiplo de 40 a secas.'],
        ];
    }

    /**
     * **La impresión tiene que existir, y se comprueba ANTES y explícitamente.**
     *
     * `INSERT IGNORE` se traga también el fallo de clave ajena: sin esta
     * comprobación, un `printing_uuid` que no está en el catálogo devolvería 204
     * sin escribir una fila y esa carta no se sembraría **nunca**, en silencio.
     */
    public function testUnaImpresionQueNoEstaEnElCatalogoNoSeSiembra(): void
    {
        $r = $this->controller->orbStore($this->peticion([
            'printingUuid' => 'ffffffff-ffff-4fff-8fff-ffffffffffff',
            'face'         => 'front',
            'nfeatures'    => 700,
            'keypoints'    => 10,
            'orb'          => base64_encode($this->bloque(10)),
        ]));

        self::assertSame(400, $r['http_code']);
        self::assertSame([], $this->repo->filas);
        self::assertDirectoryDoesNotExist($this->storage . '/vision/orb');
    }

    /**
     * `0 < keypoints <= nfeatures`, y la forma del resto del payload.
     *
     * El tope de `keypoints` no es cosmético: es lo único que impide que el
     * cliente decida cuántos megabytes se escriben en disco.
     */
    #[DataProvider('payloadsQueNoPasanLaValidacionDeForma')]
    public function testLaValidacionDeFormaRechazaElPayload(array $payload, string $porQue): void
    {
        self::assertSame(400, $this->controller->orbStore($this->peticion($payload))['http_code'], $porQue);
        self::assertSame([], $this->repo->filas);
    }

    /** @return array<string, array{array<string, mixed>, string}> */
    public static function payloadsQueNoPasanLaValidacionDeForma(): array
    {
        $base = [
            'printingUuid' => self::UUID_A,
            'face'         => 'front',
            'nfeatures'    => 700,
            'keypoints'    => 10,
            'orb'          => null,
        ];

        $conOrb = static function (array $cambios) use ($base): array {
            $keypoints = $cambios['keypoints'] ?? $base['keypoints'];
            $bytes     = is_int($keypoints) && $keypoints > 0 ? $keypoints * 40 : 400;

            return array_merge($base, ['orb' => base64_encode(str_repeat("\x41", $bytes))], $cambios);
        };

        return [
            'sin printingUuid'        => [$conOrb(['printingUuid' => null]), 'Sin uuid no hay nada que sembrar.'],
            'uuid que no es uuid'     => [$conOrb(['printingUuid' => '../../etc/passwd']), 'Un uuid con barras compondría una ruta fuera de storage/.'],
            'uuid en mayúsculas'      => [$conOrb(['printingUuid' => strtoupper(self::UUID_A)]), 'La forma canónica es en minúsculas; dos formas serían dos ficheros.'],
            'cara desconocida'        => [$conOrb(['face' => 'lateral']), "El ENUM solo tiene 'front' y 'back'."],
            'sin cara'                => [$conOrb(['face' => null]), 'La cara no se adivina: una impresión de doble cara tiene dos.'],
            'keypoints a cero'        => [array_merge($base, ['keypoints' => 0, 'orb' => base64_encode('')]), '`0 < keypoints`: un bloque vacío no es una referencia.'],
            'keypoints por encima'    => [$conOrb(['keypoints' => 701]), '`keypoints <= nfeatures`: el cliente no decide cuánto se escribe.'],
            'nfeatures a cero'        => [$conOrb(['nfeatures' => 0]), 'Con qué se extrajo no puede ser cero.'],
            'nfeatures desbordado'    => [$conOrb(['nfeatures' => 65536]), 'SMALLINT UNSIGNED: por encima sería un 22003, y eso es un 500 con cara de 400.'],
            'keypoints con coma'      => [$conOrb(['keypoints' => 10.5]), 'Un 10.5 truncado a 10 sería una decisión que nadie tomó.'],
            'base64 con basura'       => [array_merge($base, ['orb' => '!!!no soy base64!!!']), 'base64_decode estricto: lo que entra acaba en una tabla compartida.'],
            'sin orb'                 => [array_merge($base, ['orb' => null]), 'Sin bloque no hay siembra.'],
        ];
    }

    // ========================================================================
    // vision_orb_store · el idioma (M6)
    // ========================================================================

    /**
     * **La misma cara en dos idiomas son dos filas y dos ficheros**, y ninguna
     * pisa a la otra.
     *
     * Es el motivo entero de que `language` entre en la clave: con la PK vieja
     * `(uuid, face)` el segundo envío comería 409 y el índice se quedaría
     * congelado en el idioma del primero que pasó por ahí — que hoy, con 2.638
     * filas inglesas, sería el inglés para siempre.
     */
    public function testLaMismaCaraEnDosIdiomasSonDosFilasYDosFicheros(): void
    {
        $ingles  = $this->bloque(700, 0x41);
        $espanol = $this->bloque(700, 0x42);

        $primera = $this->controller->orbStore($this->peticion([
            'printingUuid' => self::UUID_A,
            'face'         => 'front',
            'nfeatures'    => 700,
            'keypoints'    => 700,
            'orb'          => base64_encode($ingles),
        ]));

        $segunda = $this->controller->orbStore($this->peticion([
            'printingUuid' => self::UUID_A,
            'face'         => 'front',
            'language'     => 'Spanish',
            'nfeatures'    => 700,
            'keypoints'    => 700,
            'orb'          => base64_encode($espanol),
        ]));

        self::assertSame(204, $primera['http_code']);
        self::assertSame(204, $segunda['http_code'], 'El español NO es un duplicado del inglés.');

        self::assertCount(2, $this->repo->filas);
        self::assertArrayHasKey(self::UUID_A . '|front|English', $this->repo->filas);
        self::assertArrayHasKey(self::UUID_A . '|front|Spanish', $this->repo->filas);

        $carpeta = $this->storage . '/vision/orb/00/';
        self::assertSame($ingles, file_get_contents($carpeta . self::UUID_A . '-front-english.orb'));
        self::assertSame($espanol, file_get_contents($carpeta . self::UUID_A . '-front-spanish.orb'));
    }

    /** El mismo idioma dos veces **sigue siendo 409**: la contención no se afloja. */
    public function testElMismoIdiomaDosVecesSigueSiendo409(): void
    {
        $codigos = [];

        foreach ([0x41, 0x00] as $relleno) {
            $r = $this->controller->orbStore($this->peticion([
                'printingUuid' => self::UUID_A,
                'face'         => 'front',
                'language'     => 'Spanish',
                'nfeatures'    => 700,
                'keypoints'    => 700,
                'orb'          => base64_encode($this->bloque(700, $relleno)),
            ]));

            $codigos[] = $r['http_code'];
        }

        self::assertSame([204, 409], $codigos);
        self::assertSame(
            $this->bloque(700, 0x41),
            file_get_contents($this->storage . '/vision/orb/00/' . self::UUID_A . '-front-spanish.orb'),
            'El segundo envío no reescribe el fichero, tampoco dentro de un idioma.'
        );
    }

    /**
     * **`'es'` y `'Spanish'` son el MISMO idioma**, y tienen que ser la misma
     * fila.
     *
     * Lo normaliza `CardLanguage`, que es la misma clase que usa la colección: si
     * aquí entrara el código crudo, tres formas del mismo idioma serían tres
     * filas de la misma cara y tres ficheros de 28.000 B.
     */
    public function testElIdiomaSeNormalizaAlNombreLargoDeMtgjson(): void
    {
        $r = $this->controller->orbStore($this->peticion([
            'printingUuid' => self::UUID_A,
            'face'         => 'front',
            'language'     => 'es',
            'nfeatures'    => 700,
            'keypoints'    => 700,
            'orb'          => base64_encode($this->bloque(700)),
        ]));

        self::assertSame(204, $r['http_code']);
        self::assertArrayHasKey(self::UUID_A . '|front|Spanish', $this->repo->filas);
        self::assertFileExists($this->storage . '/vision/orb/00/' . self::UUID_A . '-front-spanish.orb');
    }

    /** Un idioma que el catálogo no conoce no siembra nada y no toca el disco. */
    public function testUnIdiomaDesconocidoNoSiembra(): void
    {
        $r = $this->controller->orbStore($this->peticion([
            'printingUuid' => self::UUID_A,
            'face'         => 'front',
            'language'     => 'Klingon',
            'nfeatures'    => 700,
            'keypoints'    => 700,
            'orb'          => base64_encode($this->bloque(700)),
        ]));

        self::assertSame(400, $r['http_code']);
        self::assertSame([], $this->repo->filas);
        self::assertDirectoryDoesNotExist($this->storage . '/vision/orb');
    }

    /**
     * `'Portuguese (Brazil)'` cabe en un nombre de fichero, y los 18 idiomas dan
     * 18 sufijos distintos.
     *
     * El paréntesis y el espacio son lo que habría roto la ruta si el idioma
     * llegara crudo al `sprintf`.
     */
    public function testUnIdiomaConEspaciosYParentesisDaUnNombreDeFicheroLimpio(): void
    {
        self::assertSame('portuguese-brazil', OrbDescriptorStore::sufijoDeIdioma('Portuguese (Brazil)'));
        self::assertSame('chinese-simplified', OrbDescriptorStore::sufijoDeIdioma('Chinese Simplified'));

        $sufijos = array_map(
            static fn (CardLanguage $idioma): string => OrbDescriptorStore::sufijoDeIdioma($idioma->value),
            CardLanguage::cases()
        );

        self::assertSame(
            count($sufijos),
            count(array_unique($sufijos)),
            'Dos idiomas con el mismo sufijo serían dos referencias en el mismo fichero.'
        );
    }

    // ========================================================================
    // Auxiliares
    // ========================================================================

    /** @param array<string, mixed> $datos */
    private function peticion(array $datos): array
    {
        return ['action' => 'vision', 'user_id' => 7, 'data' => $datos];
    }

    /** Un bloque con la forma exacta: `keypoints * 40` bytes. */
    private function bloque(int $keypoints, int $relleno = 0x41): string
    {
        return str_repeat(chr($relleno), $keypoints * OrbDescriptorStore::BYTES_POR_KEYPOINT);
    }

    /**
     * Siembra saltándose el controller, para montar el estado previo de un test.
     *
     * El idioma por defecto es `English` porque es lo que eran las 2.638 filas
     * que ya existían cuando el M6 abrió la columna.
     */
    private function sembrar(
        string $uuid,
        string $face,
        int $nfeatures,
        int $keypoints,
        string $bloque,
        string $language = 'English'
    ): void {
        $escrito = $this->almacen->escribirParcial($uuid, $face, $language, $bloque);
        $this->almacen->publicar($escrito['parcial']);
        $this->repo->sembrar($uuid, $face, $language, $nfeatures, $keypoints, $escrito['relativa']);
    }

    private function borrarArbol(string $ruta): void
    {
        if (!is_dir($ruta)) {
            return;
        }

        foreach (scandir($ruta) ?: [] as $entrada) {
            if ($entrada === '.' || $entrada === '..') {
                continue;
            }

            $hijo = $ruta . '/' . $entrada;
            is_dir($hijo) ? $this->borrarArbol($hijo) : unlink($hijo);
        }

        rmdir($ruta);
    }
}
