<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Application\UseCase\ResolveCards;
use App\Domain\Catalog\SearchCriteria;
use App\Controllers\ScanController;
use App\Domain\Import\AssumedPrintingChooser;
use App\Domain\Import\CardResolution;
use App\Domain\Import\CardResolver;
use App\Domain\Import\NameNormalizer;
use App\Domain\Repository\CardRepositoryInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionParameter;
use Psr\Log\NullLogger;
use RuntimeException;
use Tests\Unit\Doubles\CatalogoDeResolucionFalso;
use Tests\Unit\Doubles\ImpresionesAsumidasFalsas;

/**
 * `scan_resolve`, con el resolvedor **de verdad** detrás.
 *
 * Lo que se prueba aquí no es el resolvedor —eso es `CardResolverTest`— sino
 * que el escáner **no necesita tocarlo**: se monta el mismo `ResolveCards` que
 * inyecta `ImportController` y se comprueba que las tres formas de lectura que
 * produce una cámara salen con los tres veredictos que el plan pide. Con un
 * resolvedor de mentira no se probaría ninguna de las dos cosas.
 *
 * El test que da nombre al hito es `testLasTresFormasDeLecturaDeUnaCamara`: son
 * literalmente las tres del `*Hecho cuando:*` del M2, y van **en la misma
 * petición** porque así es como llegan de verdad —una página de binder son nueve
 * detecciones de golpe— y porque un lote es también lo que puede romper el
 * emparejamiento de cada veredicto con su `id`.
 *
 * El catálogo de mentira reproduce datos reales: `2X2 117` es *Lightning Bolt*,
 * e `ICE 96` es *Shyft* y **no** `Lim-Dûl's Vault`, que es la línea canónica del
 * conflicto `mismatch` de este proyecto.
 *
 */
final class ScanControllerTest extends TestCase
{
    private CatalogoDeResolucionFalso $catalogo;
    private ImpresionesAsumidasFalsas $impresiones;
    private CardRepositoryInterface $cartas;
    private ScanController $controller;

    /** Llamadas a `porUuids()`: tiene que ser UNA por petición, pase lo que pase. */
    private int $consultasDeFicha = 0;

    protected function setUp(): void
    {
        $this->catalogo    = new CatalogoDeResolucionFalso();
        $this->impresiones = new ImpresionesAsumidasFalsas();

        // Una carta muy reimpresa (sin impresión evidente: el paso 3 resuelve la
        // CARTA y la edición la asume el chooser) y la impresión concreta que el
        // par (set, número) identifica sin ambigüedad.
        $this->catalogo->conCarta('Lightning Bolt', 'o-bolt', impresiones: 130);
        $this->catalogo->conCarta("Lim-Dûl's Vault", 'o-limdul', impresiones: 5);
        $this->catalogo->conCarta('Shyft', 'o-shyft', impresiones: 1, printingUuid: 'uuid-shyft-ice', setCode: 'ICE');

        $this->catalogo->porSetYNumero['2X2|117'] = [
            'printingUuid' => 'uuid-bolt-2x2',
            'oracleId'     => 'o-bolt',
            'name'         => 'Lightning Bolt',
            'setCode'      => '2X2',
        ];
        $this->catalogo->porSetYNumero['ICE|96'] = [
            'printingUuid' => 'uuid-shyft-ice',
            'oracleId'     => 'o-shyft',
            'name'         => 'Shyft',
            'setCode'      => 'ICE',
        ];

        // La edición asumida de la carta reimpresa: la más barata de sus 130.
        $this->impresiones->conImpresion('o-bolt', 'normal', 'uuid-bolt-lea', 'LEA', 130, 1.49);

        $this->cartas = $this->cartasFalsas();

        $this->controller = new ScanController(
            new ResolveCards(
                new CardResolver($this->catalogo, new NameNormalizer()),
                new AssumedPrintingChooser($this->impresiones)
            ),
            $this->cartas,
            new NullLogger()
        );
    }

    /**
     * El repositorio de catálogo, en memoria y **solo con el método nuevo**.
     *
     * Anónimo y no en `Doubles/` porque ningún otro test lo necesita: lo que
     * aquí importa es que `porUuids()` se llame UNA vez con el lote entero, y
     * eso se cuenta desde dentro. Los otros cuatro métodos de la interfaz
     * revientan a propósito: si el controller llegase a llamarlos estaría
     * pidiendo la ficha carta a carta, que es exactamente lo que este método
     * existe para no hacer.
     */
    private function cartasFalsas(): CardRepositoryInterface
    {
        $test   = $this;
        $fichas = [
            'uuid-bolt-2x2' => self::ficha('uuid-bolt-2x2', 'Lightning Bolt', 'o-bolt', '2X2', '117', true, true, false, 1.49, 2.23, null),
            'uuid-bolt-lea' => self::ficha('uuid-bolt-lea', 'Lightning Bolt', 'o-bolt', 'LEA', '161', false, true, false, 349.0, null, null),
            'uuid-shyft-ice' => self::ficha('uuid-shyft-ice', 'Shyft', 'o-shyft', 'ICE', '96', false, true, false, 0.30, null, null),
        ];

        return new class ($test, $fichas) implements CardRepositoryInterface {
            /** @param array<string, array<string, mixed>> $fichas */
            public function __construct(private ScanControllerTest $test, private array $fichas)
            {
            }

            public function porUuids(array $uuids): array
            {
                $this->test->anotarConsultaDeFicha();

                return array_intersect_key($this->fichas, array_flip($uuids));
            }

            public function search(SearchCriteria $criterios): array
            {
                throw new RuntimeException('scan_resolve no busca en el catálogo.');
            }

            public function findByUuid(string $uuid): ?array
            {
                throw new RuntimeException('scan_resolve no pide la ficha carta a carta: son cuatro consultas cada una.');
            }

            public function impresionesDe(string $uuid, ?string $cursor, int $limite): ?array
            {
                throw new RuntimeException('scan_resolve no lista impresiones hermanas.');
            }

            public function allSets(): array
            {
                throw new RuntimeException('scan_resolve no lista ediciones.');
            }
        };
    }

    public function anotarConsultaDeFicha(): void
    {
        $this->consultasDeFicha++;
    }

    /**
     * Una ficha con la forma exacta del contrato CatalogCard, recortada a lo que
     * el escáner consume: el resto de columnas no cambian ningún veredicto.
     *
     * @return array<string, mixed>
     */
    private static function ficha(
        string $uuid,
        string $name,
        string $oracleId,
        string $setCode,
        string $collectorNumber,
        bool $foil,
        bool $nonfoil,
        bool $etched,
        ?float $normal,
        ?float $precioFoil,
        ?float $precioEtched
    ): array {
        return [
            'uuid'            => $uuid,
            'oracleId'        => $oracleId,
            'name'            => $name,
            'setCode'         => $setCode,
            'collectorNumber' => $collectorNumber,
            'finishes'        => ['foil' => $foil, 'nonfoil' => $nonfoil, 'etched' => $etched],
            'priceEur'        => ['normal' => $normal, 'foil' => $precioFoil, 'etched' => $precioEtched],
        ];
    }

    /** @param list<array<string, mixed>> $lecturas */
    private function peticion(array $lecturas): array
    {
        // La forma que arma ActionRouter: el payload bajo `data` y el user_id
        // puesto por AuthMiddleware, jamás por el cuerpo.
        return ['action' => 'scan_resolve', 'user_id' => 7, 'data' => ['lecturas' => $lecturas]];
    }

    /**
     * **El `*Hecho cuando:*` del M2.** Tres lecturas en una petición:
     *
     *  1. set y número exactos        → resolución exacta por el paso 2
     *  2. solo el nombre, muy reimpresa → resuelta con `assumedPrinting: true`
     *  3. nombre y número que se contradicen → conflicto `mismatch` con las dos
     */
    public function testLasTresFormasDeLecturaDeUnaCamara(): void
    {
        $respuesta = $this->controller->resolve($this->peticion([
            ['id' => 'd1', 'name' => 'Lightning Bolt', 'setCode' => '2X2', 'collectorNumber' => '117',
             'language' => 'English', 'rarity' => 'uncommon'],
            ['id' => 'd2', 'name' => 'Lightning Bolt', 'language' => 'English'],
            ['id' => 'd3', 'name' => "Lim-Dûl's Vault", 'setCode' => 'ICE', 'collectorNumber' => '96',
             'language' => 'English'],
        ]));

        self::assertSame('success', $respuesta['status']);

        $resultados = $respuesta['data']['results'];
        self::assertCount(3, $resultados);

        // Los `id` vuelven intactos y en el orden en que llegaron: es lo único
        // que empareja cada veredicto con su fila del menú.
        self::assertSame(['d1', 'd2', 'd3'], array_column($resultados, 'id'));

        // --- 1. Set y número exactos: la impresión, sin nada que asumir -------
        $exacta = $resultados[0];
        self::assertTrue($exacta['resolved']);
        self::assertSame('uuid-bolt-2x2', $exacta['printingUuid']);
        self::assertSame('o-bolt', $exacta['oracleId']);
        self::assertSame('Lightning Bolt', $exacta['name']);
        self::assertSame('2X2', $exacta['setCode']);
        self::assertSame('2', $exacta['step']);
        self::assertFalse($exacta['assumedPrinting']);
        self::assertSame(1, $exacta['printingCount']);
        self::assertNull($exacta['reason']);
        self::assertSame([], $exacta['candidates']);

        // Los tres campos que `ResolveCards` NO trae y que el menú necesita:
        // salen de la lectura de catálogo por lote, no del resolvedor.
        self::assertSame('117', $exacta['collectorNumber']);
        self::assertSame(['foil' => true, 'nonfoil' => true, 'etched' => false], $exacta['finishes']);
        self::assertSame(['normal' => 1.49, 'foil' => 2.23, 'etched' => null], $exacta['priceEur']);

        // --- 2. Solo el nombre de una carta muy reimpresa: edición asumida ----
        $asumida = $resultados[1];
        self::assertTrue($asumida['resolved']);
        self::assertTrue($asumida['assumedPrinting'], 'Sin la marca, el menú escribiría una edición a ciegas.');
        self::assertSame('uuid-bolt-lea', $asumida['printingUuid']);
        self::assertSame(130, $asumida['printingCount'], 'Entre cuántas ediciones se eligió.');
        self::assertSame('3', $asumida['step']);
        self::assertNull($asumida['reason']);
        self::assertSame('161', $asumida['collectorNumber']);

        // --- 3. Nombre y número que se contradicen: mismatch con las dos ------
        $conflicto = $resultados[2];
        self::assertFalse($conflicto['resolved']);
        self::assertSame(CardResolution::DESACUERDO, $conflicto['reason']);
        self::assertNull($conflicto['printingUuid']);
        self::assertCount(2, $conflicto['candidates'], 'El mismatch enseña la carta del NOMBRE y la del NÚMERO.');

        // En el orden en que el usuario los tecleó: primero el nombre, última la
        // impresión que dice el número. `ICE 96` es *Shyft*, y por eso la lectura
        // no se escribe en silencio.
        self::assertSame("Lim-Dûl's Vault", $conflicto['candidates'][0]['name']);
        self::assertSame('Shyft', $conflicto['candidates'][1]['name']);
    }

    /**
     * Una sola consulta de catálogo para el lote entero.
     *
     * Es el motivo de que el método nuevo del repositorio sea por lote: nueve
     * cartas de una página de binder no pueden ser nueve consultas, y con
     * `findByUuid()` habrían sido treinta y seis.
     */
    public function testLaFichaDeCatalogoSePreguntaUnaSolaVezParaTodoElLote(): void
    {
        $this->controller->resolve($this->peticion([
            ['id' => 'a', 'name' => 'Lightning Bolt', 'setCode' => '2X2', 'collectorNumber' => '117'],
            ['id' => 'b', 'name' => 'Lightning Bolt', 'setCode' => '2X2', 'collectorNumber' => '117'],
            ['id' => 'c', 'name' => 'Lightning Bolt'],
        ]));

        self::assertSame(1, $this->consultasDeFicha);
    }

    /**
     * El tope de lecturas por petición.
     *
     * **La ruta no lleva `ValidationMiddleware`**: si el controller no acota, no
     * acota nadie y una petición con 10.000 entradas ata el resolvedor.
     */
    public function testUnLoteDemasiadoLargoSeRechazaSinLlegarAlResolvedor(): void
    {
        $lecturas = array_map(
            static fn (int $i): array => ['id' => 'd' . $i, 'name' => 'Lightning Bolt'],
            range(1, ScanController::MAXIMO_LECTURAS + 1)
        );

        $respuesta = $this->controller->resolve($this->peticion($lecturas));

        self::assertSame('error', $respuesta['status']);
        self::assertSame(422, $respuesta['http_code']);
        self::assertSame(0, $this->catalogo->llamadas['cartasPorNombreNormalizado'], 'Ni una consulta: se rechaza antes.');
        self::assertSame(0, $this->consultasDeFicha);
    }

    /** Sin lecturas no hay nada que resolver, y eso es un 422, no un 500. */
    public function testUnaPeticionSinLecturasSeRechaza(): void
    {
        $respuesta = $this->controller->resolve(['action' => 'scan_resolve', 'user_id' => 7, 'data' => []]);

        self::assertSame('error', $respuesta['status']);
        self::assertSame(422, $respuesta['http_code']);
    }

    /**
     * Una lectura sin nada legible —el parser no casó el bloque de la esquina y
     * tampoco leyó el título— no rompe el lote: sale en conflicto
     * `not_found` con la misma forma que las demás.
     */
    public function testUnaLecturaIlegibleSaleEnConflictoYNoTumbaElLote(): void
    {
        $respuesta = $this->controller->resolve($this->peticion([
            ['id' => 'ok', 'name' => 'Lightning Bolt', 'setCode' => '2X2', 'collectorNumber' => '117'],
            ['id' => 'basura', 'name' => '   ', 'collectorNumber' => ''],
        ]));

        $resultados = $respuesta['data']['results'];

        self::assertTrue($resultados[0]['resolved']);
        self::assertFalse($resultados[1]['resolved']);
        self::assertSame(CardResolution::NO_ENCONTRADA, $resultados[1]['reason']);

        // Misma forma exacta en los dos veredictos: el menú pinta una lista, no
        // dos, y un cliente que tenga que mirar qué claves existen se rompe el
        // día que una carta deja de resolver.
        self::assertSame(array_keys($resultados[0]), array_keys($resultados[1]));
    }

    /**
     * Sin `id` del cliente, el índice de la detección hace de identificador.
     *
     * No se inventa uno propio: el cliente ya tiene el suyo pegado a la fila del
     * menú y devolverle otro le obligaría a mantener dos.
     */
    public function testSinIdDelClienteVuelveElIndiceDeLaDeteccion(): void
    {
        $respuesta = $this->controller->resolve($this->peticion([
            ['name' => 'Lightning Bolt', 'setCode' => '2X2', 'collectorNumber' => '117'],
            ['name' => 'Lightning Bolt'],
        ]));

        self::assertSame(['0', '1'], array_column($respuesta['data']['results'], 'id'));
    }

    /**
     * El escáner **no escribe**: este controller no conoce el repositorio de la
     * colección y no puede tocarla ni por accidente. Meter la carta es
     * `collection_add`, que ya existía.
     */
    public function testElControllerNoConoceLaColeccion(): void
    {
        $constructor = (new ReflectionClass(ScanController::class))->getConstructor();
        self::assertNotNull($constructor);

        $tipos = array_map(
            static fn (ReflectionParameter $p): string => (string) $p->getType(),
            $constructor->getParameters()
        );

        foreach ($tipos as $tipo) {
            self::assertStringNotContainsString('Collection', $tipo);
            self::assertStringNotContainsString('Deck', $tipo);
        }
    }

    // =======================================================================
    // M2 — la verja de certeza: ¿está cerrada la IMPRESIÓN, y por qué?
    // =======================================================================

    /**
     * **El `*Hecho cuando:*` del M2.** Una fila con `(setCode, nº)` sale con
     * `printingCertain: true` y `certaintySource: 'corner'`.
     *
     * Es la fuente que el modo manos libres necesita para la mayoría de las
     * cartas: el bloque de la esquina identifica la IMPRESIÓN, no solo la carta.
     */
    public function testUnaLecturaConElBloqueDeEsquinaEsCiertaPorCorner(): void
    {
        $respuesta = $this->controller->resolve($this->peticion([
            ['id' => 'd1', 'name' => 'Lightning Bolt', 'setCode' => '2X2', 'collectorNumber' => '117'],
        ]));

        $fila = $respuesta['data']['results'][0];

        self::assertTrue($fila['resolved']);
        self::assertTrue($fila['printingCertain']);
        self::assertSame('corner', $fila['certaintySource']);
    }

    /**
     * **El `*Hecho cuando:*` del M2.** Una carta resuelta solo por su nombre y
     * con muchas impresiones sale `printingCertain: false` y sin fuente.
     *
     * Resolver la CARTA no es resolver la IMPRESIÓN: el chooser asume una de las
     * 130 y `assumedPrinting` lo dice. Escribirla sola metería en la colección
     * una edición inventada, que es lo que la verja existe para impedir.
     */
    public function testUnaCartaMuyReimpresaResueltaPorNombreNoEsCierta(): void
    {
        $respuesta = $this->controller->resolve($this->peticion([
            ['id' => 'd1', 'name' => 'Lightning Bolt'],
        ]));

        $fila = $respuesta['data']['results'][0];

        self::assertTrue($fila['resolved']);
        self::assertTrue($fila['assumedPrinting']);
        self::assertFalse($fila['printingCertain']);
        self::assertNull($fila['certaintySource']);
    }

    /**
     * La otra fuente: la carta tiene **una sola impresión**, así que no hay nada
     * que asumir aunque se resolviera por el nombre.
     *
     * Es el 41,8 % del catálogo y solo el 4,4 % de una colección real: por sí
     * sola no sostiene el modo manos libres, y por eso la verja es una lista.
     */
    public function testUnaCartaDeUnaSolaImpresionEsCiertaPorSingle(): void
    {
        $respuesta = $this->controller->resolve($this->peticion([
            ['id' => 'd1', 'name' => 'Shyft'],
        ]));

        $fila = $respuesta['data']['results'][0];

        self::assertTrue($fila['resolved']);
        self::assertSame(1, $fila['printingCount']);
        self::assertTrue($fila['printingCertain']);
        self::assertSame('single', $fila['certaintySource']);
    }

    /** Una fila que ni resolvió la carta no puede tener cerrada la impresión. */
    public function testUnConflictoNuncaEsCierto(): void
    {
        $respuesta = $this->controller->resolve($this->peticion([
            ['id' => 'd1', 'name' => "Lim-Dûl's Vault", 'setCode' => 'ICE', 'collectorNumber' => '96'],
        ]));

        $fila = $respuesta['data']['results'][0];

        self::assertFalse($fila['resolved']);
        self::assertFalse($fila['printingCertain']);
        self::assertNull($fila['certaintySource']);
    }

    /**
     * **`art` está declarada y HOY NO LA EMITE NADIE**, y eso se prueba.
     *
     * La emitirá el Plan - Reconocimiento de la Impresión por su Arte cuando ORB
     * identifique la impresión mirando la ilustración. Que la rama exista desde
     * el primer día es la diferencia entre que aquel plan encaje y que tenga que
     * refactorizar la verja entera: si alguien «limpia» este hueco porque no lo
     * usa nadie, este test se pone rojo y dice por qué.
     */
    public function testLaFuenteArtEstaDeclaradaPeroNadieLaEmiteTodavia(): void
    {
        $respuesta = $this->controller->resolve($this->peticion([
            ['id' => 'd1', 'name' => 'Lightning Bolt', 'setCode' => '2X2', 'collectorNumber' => '117'],
            ['id' => 'd2', 'name' => 'Lightning Bolt'],
            ['id' => 'd3', 'name' => 'Shyft'],
            ['id' => 'd4', 'name' => "Lim-Dûl's Vault", 'setCode' => 'ICE', 'collectorNumber' => '96'],
        ]));

        foreach ($respuesta['data']['results'] as $fila) {
            self::assertContains(
                $fila['certaintySource'],
                ['single', 'corner', null],
                'Las únicas fuentes que este backend emite hoy son `single` y `corner`.'
            );
        }
    }

    /** Las dos claves nuevas viajan SIEMPRE, resuelva la fila o no. */
    public function testLasDosClavesDeCertezaEstanEnLasDosFormasDelContrato(): void
    {
        $respuesta = $this->controller->resolve($this->peticion([
            ['id' => 'd1', 'name' => 'Lightning Bolt'],
            ['id' => 'd2', 'name' => "Lim-Dûl's Vault", 'setCode' => 'ICE', 'collectorNumber' => '96'],
        ]));

        foreach ($respuesta['data']['results'] as $fila) {
            self::assertArrayHasKey('printingCertain', $fila);
            self::assertArrayHasKey('certaintySource', $fila);
        }
    }

    /**
     * El catálogo de mentira en español, para los cuatro tests del M8.
     *
     * Reproduce datos medidos en la BD el 2026-09-16 sobre las 232.439 claves de
     * `mtg_printing_localized.name_normalized`:
     *
     *  - `linterna cromatica` apunta a **un solo idioma** (`Spanish`), como el
     *    97,8 % de las claves.
     *  - `a todo vapor` apunta a **dos** —español y portugués de Brasil—, que es
     *    el caso que no se puede resolver por mayoría.
     */
    private function conCartasEnEspanol(): void
    {
        $this->catalogo->conCarta('Chromatic Lantern', 'o-lantern', impresiones: 1, printingUuid: 'uuid-lantern-rtr', setCode: 'RTR');
        $this->catalogo->conNombreLocalizado('o-lantern', 'Linterna cromática', 'Spanish');

        $this->catalogo->conCarta('Full Steam Ahead', 'o-steam', impresiones: 1, printingUuid: 'uuid-steam-vow', setCode: 'VOW');
        $this->catalogo->conNombreLocalizado('o-steam', 'A todo vapor', 'Spanish');
        $this->catalogo->conNombreLocalizado('o-steam', 'A todo vapor', 'Portuguese (Brazil)');
    }

    /**
     * **Escalón 2 de la cascada del M8**: el nombre casó en
     * `mtg_printing_localized` y su clave apunta a un solo idioma.
     *
     * Es literalmente la prueba de campo que motivó el hito: con el selector en
     * **inglés**, la *Linterna cromática* española tiene que detectarse sola. El
     * `language` que llega en la lectura es el ajuste del usuario —`English`— y
     * el veredicto contesta `Spanish` **sin haberlo preguntado**.
     */
    public function testElNombreEnEspanolDetectaElIdiomaAunqueElAjusteDigaIngles(): void
    {
        $this->conCartasEnEspanol();

        $respuesta = $this->controller->resolve($this->peticion([
            ['id' => 'd1', 'name' => 'Linterna cromática', 'language' => 'English'],
        ]));

        $fila = $respuesta['data']['results'][0];

        self::assertTrue($fila['resolved']);
        self::assertSame('3c', $fila['step'], 'Tiene que haber resuelto por el índice localizado.');
        self::assertSame(
            'Spanish',
            $fila['language'],
            'El nombre lo dijo: la clave `linterna cromatica` solo existe en español.'
        );
    }

    /**
     * **Escalón 3**: el nombre casó en el índice INGLÉS, y eso es en sí mismo la
     * señal.
     *
     * Sin este escalón una carta inglesa heredaría el `Spanish` que el usuario
     * dejó puesto en el selector, y el idioma entra en el `UNIQUE KEY` de
     * `mtg_collection_item`: la colección se partiría en dos en silencio. Por eso
     * la lectura llega con `language: 'Spanish'` y el veredicto contesta
     * `English`.
     */
    public function testElNombreEnInglesDetectaInglesAunqueElAjusteDigaEspanol(): void
    {
        $this->conCartasEnEspanol();

        $respuesta = $this->controller->resolve($this->peticion([
            ['id' => 'd1', 'name' => 'Chromatic Lantern', 'language' => 'Spanish'],
        ]));

        $fila = $respuesta['data']['results'][0];

        self::assertTrue($fila['resolved']);
        self::assertSame('3', $fila['step']);
        self::assertSame('English', $fila['language'], 'Casar en `mtg_card` es haber leído un nombre inglés.');
    }

    /**
     * **Escalón 4, la mitad prohibida**: una clave ambigua NO se resuelve por
     * mayoría.
     *
     * `a todo vapor` es español **y** portugués de Brasil. La carta resuelve sin
     * problema —es la misma en los dos idiomas— pero el idioma no se elige: sale
     * `null`, el cliente cae en su ajuste y el comportamiento es el de antes del
     * hito. Elegir el idioma con más filas detrás es la tentación evidente y
     * está vetada: aquí no se elige carta —eso se ve en pantalla—, se elige lo
     * que entra en el `UNIQUE KEY` de la línea de colección.
     */
    public function testUnaClaveEnDosIdiomasNoDeclaraNinguno(): void
    {
        $this->conCartasEnEspanol();

        $respuesta = $this->controller->resolve($this->peticion([
            ['id' => 'd1', 'name' => 'A todo vapor', 'language' => 'English'],
        ]));

        $fila = $respuesta['data']['results'][0];

        self::assertTrue($fila['resolved'], 'La CARTA sí resuelve: es la misma en los dos idiomas.');
        self::assertSame('3c', $fila['step']);
        self::assertNull($fila['language'], 'Sin señal clara no se adivina: decide el ajuste del cliente.');
    }

    /**
     * **Escalón 4, la otra mitad**: una lectura resuelta por `(edición, número)`
     * sin nombre no tiene nombre que declare nada.
     *
     * `2X2 117` identifica la impresión sin ambigüedad, pero identificar una
     * impresión no es leer un idioma: la misma impresión existe en diez. `null`,
     * y el cliente hace lo de siempre.
     */
    public function testUnaLecturaPorEdicionYNumeroSinNombreNoDeclaraIdioma(): void
    {
        $respuesta = $this->controller->resolve($this->peticion([
            ['id' => 'd1', 'setCode' => '2X2', 'collectorNumber' => '117', 'language' => 'Spanish'],
        ]));

        $fila = $respuesta['data']['results'][0];

        self::assertTrue($fila['resolved']);
        self::assertSame('2', $fila['step']);
        self::assertNull($fila['language'], 'El par (edición, número) no dice en qué idioma está impresa.');
    }

    /** `language` viaja SIEMPRE, resuelva la fila o no: las dos formas del contrato tienen las mismas claves. */
    public function testElIdiomaDetectadoEstaEnLasDosFormasDelContrato(): void
    {
        $respuesta = $this->controller->resolve($this->peticion([
            ['id' => 'd1', 'name' => 'Lightning Bolt'],
            ['id' => 'd2', 'name' => "Lim-Dûl's Vault", 'setCode' => 'ICE', 'collectorNumber' => '96'],
        ]));

        foreach ($respuesta['data']['results'] as $fila) {
            self::assertArrayHasKey('language', $fila);
        }

        // Y el conflicto no declara idioma: no se sabe ni qué carta es.
        self::assertNull($respuesta['data']['results'][1]['language']);
    }
}
