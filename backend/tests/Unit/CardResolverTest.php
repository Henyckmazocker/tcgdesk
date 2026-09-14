<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Import\CardResolution;
use App\Domain\Import\CardResolver;
use App\Domain\Import\NameNormalizer;
use App\Domain\Import\ParsedRow;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Doubles\CatalogoDeResolucionFalso;

/**
 * Los cuatro pasos del resolvedor y, sobre todo, **lo que NO hace**.
 *
 * La métrica que manda en el plan no es cuántas cartas acierta: es cuántas se
 * resuelven mal en silencio, que tienen que ser cero. Meter *Chain Lightning* en
 * la colección creyendo que es *Lightning Bolt* corrompe el inventario sin que
 * nadie se entere; mandar la línea a conflicto solo cuesta un clic.
 *
 * Por eso la mitad de este fichero comprueba conflictos y no aciertos.
 */
final class CardResolverTest extends TestCase
{
    private CatalogoDeResolucionFalso $catalogo;
    private CardResolver $resolvedor;

    protected function setUp(): void
    {
        $this->catalogo   = new CatalogoDeResolucionFalso();
        $this->resolvedor = new CardResolver($this->catalogo, new NameNormalizer());
    }

    private function fila(
        ?string $name = null,
        ?string $scryfallId = null,
        ?string $setCode = null,
        ?string $collectorNumber = null,
        array $errores = [],
        int $linea = 1,
    ): ParsedRow {
        return new ParsedRow(
            $scryfallId,
            $name,
            $setCode,
            $collectorNumber,
            'normal',
            'English',
            'NM',
            1,
            $linea,
            $errores,
            ['Name' => (string) $name],
        );
    }

    /** @return array<string, mixed> */
    private function impresion(string $uuid, string $oracleId, string $name, string $setCode, string $numero): array
    {
        return [
            'printingUuid'    => $uuid,
            'oracleId'        => $oracleId,
            'name'            => $name,
            // Igual que la consulta real: la impresión trae la clave de
            // `mtg_card.name_normalized`, que es contra lo que el paso 2 contrasta.
            'nameNormalized'  => (new NameNormalizer())->normalizar($name),
            'setCode'         => $setCode,
            'collectorNumber' => $numero,
            'impresiones'     => 1,
        ];
    }

    // ------------------------------------------------------------------ paso 1

    public function testPaso1ElScryfallIdGanaSobreTodoLoDemas(): void
    {
        $this->catalogo->porScryfallId['4a1b-bolt'] =
            $this->impresion('uuid-bolt', 'oracle-bolt', 'Lightning Bolt', 'M10', '146');

        // El nombre que trae la fila es OTRO a propósito: si el paso 1 no ganara,
        // el resultado sería distinto.
        [$veredicto] = $this->resolvedor->resolver([$this->fila('Cosa que no existe', '4a1b-bolt')]);

        self::assertTrue($veredicto->estaResuelta());
        self::assertSame('1', $veredicto->paso);
        self::assertSame('uuid-bolt', $veredicto->printingUuid);
        self::assertSame('Lightning Bolt', $veredicto->name);
    }

    /**
     * Un Scryfall ID que el mirror no conoce —una carta más nueva que la última
     * ingesta— no invalida la fila: cede el turno al nombre.
     */
    public function testUnScryfallIdDesconocidoNoDescartaLaFilaYPasaAlNombre(): void
    {
        $this->catalogo->conCarta('Lightning Bolt', 'oracle-bolt');

        [$veredicto] = $this->resolvedor->resolver([$this->fila('Lightning Bolt', 'id-que-no-esta')]);

        self::assertTrue($veredicto->estaResuelta());
        self::assertSame('3', $veredicto->paso);
    }

    // ------------------------------------------------------------------ paso 2

    public function testPaso2SetMasNumeroEsTanExactoComoElScryfallId(): void
    {
        $this->catalogo->porSetYNumero['M10|146'] =
            $this->impresion('uuid-bolt-m10', 'oracle-bolt', 'Lightning Bolt', 'M10', '146');

        [$veredicto] = $this->resolvedor->resolver([$this->fila('Lightning Bolt', null, 'm10', '146')]);

        self::assertSame('2', $veredicto->paso);
        self::assertSame('uuid-bolt-m10', $veredicto->printingUuid);
    }

    /**
     * EL fallo silencioso que M0 no podía ver: su lista de control son 50 nombres
     * sueltos, así que el paso 2 se ejecutó **cero veces**.
     *
     * Verificado contra la BD real: `ICE 96` **es** *Shyft*. Quien teclea
     * `Lim-Dûl's Vault (ICE) 96` de una lista pegada de una web se llevaba *Shyft*
     * a la colección sin un solo aviso. Dos datos exactos que se contradicen no se
     * desempatan: se enseñan los dos.
     */
    public function testUnNumeroEquivocadoYaNoMeteOtraCartaEnSilencio(): void
    {
        $this->catalogo->porSetYNumero['ICE|96'] =
            $this->impresion('uuid-shyft', 'oracle-shyft', 'Shyft', 'ICE', '96');
        $this->catalogo->conCarta("Lim-Dûl's Vault", 'oracle-vault');

        [$veredicto] = $this->resolvedor->resolver([$this->fila("Lim-Dûl's Vault", null, 'ICE', '96')]);

        self::assertFalse($veredicto->estaResuelta());
        self::assertSame(CardResolution::DESACUERDO, $veredicto->motivo);
        self::assertNull($veredicto->printingUuid);
        self::assertSame(
            ["Lim-Dûl's Vault", 'Shyft'],
            array_column($veredicto->candidatos, 'name')
        );
        self::assertSame(
            ['oracle-vault', 'oracle-shyft'],
            array_column($veredicto->candidatos, 'oracleId')
        );
        // Un dato contradictorio no se lleva al paso difuso a ver si se arregla.
        self::assertSame(0, $this->catalogo->llamadas['cartasPorTexto']);
    }

    /** El segundo caso medido contra la BD: `MM2 20` es *Iona, Shield of Emeria*. */
    public function testElSegundoDesacuerdoMedidoTambienEsConflicto(): void
    {
        $this->catalogo->porSetYNumero['MM2|20'] =
            $this->impresion('uuid-iona', 'oracle-iona', 'Iona, Shield of Emeria', 'MM2', '20');
        $this->catalogo->conCarta('Path to Exile', 'oracle-path');

        [$veredicto] = $this->resolvedor->resolver([$this->fila('Path to Exile', null, 'MM2', '20')]);

        self::assertFalse($veredicto->estaResuelta());
        self::assertSame(CardResolution::DESACUERDO, $veredicto->motivo);
        self::assertSame(
            ['Path to Exile', 'Iona, Shield of Emeria'],
            array_column($veredicto->candidatos, 'name')
        );
    }

    /**
     * Y lo contrario, que es la mitad que se rompe si uno se pasa de listo: un
     * nombre que **sí** concuerda resuelve, y concuerda por la clave normalizada,
     * no por igualdad literal (`Lim-Dul's` sin acento es la misma carta).
     */
    public function testUnNombreQueConcuerdaConSuParSigueResolviendoEnElPaso2(): void
    {
        $this->catalogo->porSetYNumero['ICE|51'] =
            $this->impresion('uuid-vault-ice', 'oracle-vault', "Lim-Dûl's Vault", 'ICE', '51');

        [$veredicto] = $this->resolvedor->resolver([$this->fila("Lim-Dul's Vault", null, 'ice', '51')]);

        self::assertTrue($veredicto->estaResuelta());
        self::assertSame('2', $veredicto->paso);
        self::assertSame('uuid-vault-ice', $veredicto->printingUuid);
        self::assertSame(0, $this->catalogo->llamadas['cartasPorNombreNormalizado']);
    }

    /** Sin nombre no hay contradicción que detectar: el par resuelve solo. */
    public function testSoloSetYNumeroSinNombreResuelveIgual(): void
    {
        $this->catalogo->porSetYNumero['ICE|96'] =
            $this->impresion('uuid-shyft', 'oracle-shyft', 'Shyft', 'ICE', '96');

        [$veredicto] = $this->resolvedor->resolver([$this->fila(null, null, 'ICE', '96')]);

        self::assertTrue($veredicto->estaResuelta());
        self::assertSame('2', $veredicto->paso);
        self::assertSame('Shyft', $veredicto->name);
    }

    /**
     * La trampa de comparar literalmente: quien teclea `Delver of Secrets (ISD) 51`
     * escribe **solo la cara frontal** y el catálogo guarda las dos caras. Sin el
     * reintento por cara frontal, las 501 cartas de doble cara del catálogo
     * pasarían a ser todas un conflicto.
     */
    public function testLaCaraFrontalDeUnaDobleCaraConcuerdaConSuPar(): void
    {
        $this->catalogo->porSetYNumero['ISD|51'] = $this->impresion(
            'uuid-delver-isd',
            'oracle-delver',
            'Delver of Secrets // Insectile Aberration',
            'ISD',
            '51'
        );

        [$veredicto] = $this->resolvedor->resolver([$this->fila('Delver of Secrets', null, 'ISD', '51')]);

        self::assertTrue($veredicto->estaResuelta());
        self::assertSame('2', $veredicto->paso);
        self::assertSame('uuid-delver-isd', $veredicto->printingUuid);
    }

    /**
     * El dominio no queda a merced de que la infraestructura se acuerde de traer
     * la columna: sin `nameNormalized`, la clave se recalcula del nombre con el
     * mismo normalizador y el desacuerdo se detecta igual.
     */
    public function testElDesacuerdoSeDetectaAunqueLaImpresionNoTraigaLaClave(): void
    {
        $impresion = $this->impresion('uuid-shyft', 'oracle-shyft', 'Shyft', 'ICE', '96');
        unset($impresion['nameNormalized']);
        $this->catalogo->porSetYNumero['ICE|96'] = $impresion;
        $this->catalogo->conCarta("Lim-Dûl's Vault", 'oracle-vault');

        [$veredicto] = $this->resolvedor->resolver([$this->fila("Lim-Dûl's Vault", null, 'ICE', '96')]);

        self::assertSame(CardResolution::DESACUERDO, $veredicto->motivo);
        self::assertCount(2, $veredicto->candidatos);
    }

    /**
     * Y el contraste **no** convierte el paso 2 en una consulta por fila: las
     * líneas en desacuerdo viajan con el resto del lote a la búsqueda por nombre,
     * que se sigue haciendo una sola vez.
     */
    public function testContrastarElNombreNoAnadeUnaConsultaPorFila(): void
    {
        $this->catalogo->porSetYNumero['ICE|96'] =
            $this->impresion('uuid-shyft', 'oracle-shyft', 'Shyft', 'ICE', '96');
        $this->catalogo->porSetYNumero['MM2|20'] =
            $this->impresion('uuid-iona', 'oracle-iona', 'Iona, Shield of Emeria', 'MM2', '20');
        $this->catalogo
            ->conCarta("Lim-Dûl's Vault", 'oracle-vault')
            ->conCarta('Path to Exile', 'oracle-path')
            ->conCarta('Sol Ring', 'oracle-ring');

        $veredictos = $this->resolvedor->resolver([
            $this->fila("Lim-Dûl's Vault", null, 'ICE', '96', linea: 1),
            $this->fila('Path to Exile', null, 'MM2', '20', linea: 2),
            $this->fila('Sol Ring', linea: 3),
        ]);

        self::assertSame(1, $this->catalogo->llamadas['impresionesPorSetYNumero']);
        self::assertSame(1, $this->catalogo->llamadas['cartasPorNombreNormalizado']);
        self::assertSame(
            [CardResolution::DESACUERDO, CardResolution::DESACUERDO, null],
            array_map(static fn ($v) => $v->motivo, $veredictos)
        );
    }

    // ------------------------------------------------------------------ paso 3

    public function testPaso3ResuelveLaTipografiaSinBusquedaDifusa(): void
    {
        $this->catalogo->conCarta("Lim-Dûl's Vault", 'oracle-vault');

        [$veredicto] = $this->resolvedor->resolver([$this->fila("Lim-Dul's Vault")]);

        self::assertSame('3', $veredicto->paso);
        self::assertSame("Lim-Dûl's Vault", $veredicto->name);
    }

    /**
     * EL caso que cierra el hito: **dos candidatos → conflicto, no elección.**
     *
     * Son 23 claves reales del catálogo (`everythingamajig`, `scavenger hunt`,
     * `sly spy`…). Quedarse con la primera sería el fallo silencioso que el plan
     * prohíbe, y excluirlas del índice fue descartado: van a conflicto con sus
     * candidatos y decide el usuario.
     */
    public function testDosCandidatosConLaMismaClaveSonConflictoNoEleccion(): void
    {
        $this->catalogo
            ->conCarta('Sly Spy', 'oracle-spy-1')
            ->conCarta('Sly Spy', 'oracle-spy-2');

        [$veredicto] = $this->resolvedor->resolver([$this->fila('Sly Spy')]);

        self::assertFalse($veredicto->estaResuelta());
        self::assertSame(CardResolution::AMBIGUA, $veredicto->motivo);
        self::assertNull($veredicto->printingUuid);
        self::assertNull($veredicto->oracleId);
        self::assertCount(2, $veredicto->candidatos);
        self::assertSame(
            ['oracle-spy-1', 'oracle-spy-2'],
            array_column($veredicto->candidatos, 'oracleId')
        );
    }

    /**
     * Y no baja al paso 4 a ver si «desempata»: el empate ya es la respuesta, y
     * un paso más difuso solo podría empeorarla.
     */
    public function testUnEmpateEnElPaso3NoSeIntentaDeshacerConElPaso4(): void
    {
        $this->catalogo
            ->conCarta('Sly Spy', 'oracle-spy-1')
            ->conCarta('Sly Spy', 'oracle-spy-2');
        $this->catalogo->fulltext['Sly Spy'] = [['oracleId' => 'oracle-spy-1', 'name' => 'Sly Spy']];

        $this->resolvedor->resolver([$this->fila('Sly Spy')]);

        self::assertSame(0, $this->catalogo->llamadas['cartasPorTexto']);
    }

    /**
     * Los blancos de las Un-sets conservados, vistos desde el resolvedor: teclear
     * «Goblin» ya no se lleva `_____ Goblin`.
     */
    public function testTeclearGoblinNoSeLlevaLaCartaDeBlancosDeUnfinity(): void
    {
        $this->catalogo->conCarta('_____ Goblin', 'oracle-blanco');
        $this->catalogo->fulltext['Goblin'] = [
            ['oracleId' => 'oracle-blanco', 'name' => '_____ Goblin', 'impresiones' => 1, 'printingUuid' => 'u1', 'setCode' => 'UNF'],
            ['oracleId' => 'oracle-barrage', 'name' => 'Goblin Barrage', 'impresiones' => 1, 'printingUuid' => 'u2', 'setCode' => 'WAR'],
        ];

        [$veredicto] = $this->resolvedor->resolver([$this->fila('Goblin')]);

        self::assertFalse($veredicto->estaResuelta());
        self::assertSame(CardResolution::AMBIGUA, $veredicto->motivo);
    }

    // ----------------------------------------------------------------- paso 3b

    public function testPaso3bResuelvePorLaCaraFrontal(): void
    {
        $this->catalogo->conCarta('Delver of Secrets // Insectile Aberration', 'oracle-delver');

        [$veredicto] = $this->resolvedor->resolver([$this->fila('Delver of Secrets')]);

        self::assertSame('3b', $veredicto->paso);
        self::assertSame('Delver of Secrets // Insectile Aberration', $veredicto->name);
    }

    public function testDosCarasFrontalesIgualesTambienSonConflicto(): void
    {
        $this->catalogo
            ->conCarta('Fast // Furious', 'oracle-fast-1')
            ->conCarta('Fast // Furioso', 'oracle-fast-2');

        [$veredicto] = $this->resolvedor->resolver([$this->fila('Fast')]);

        self::assertFalse($veredicto->estaResuelta());
        self::assertSame(CardResolution::AMBIGUA, $veredicto->motivo);
        self::assertCount(2, $veredicto->candidatos);
    }

    // ------------------------------------------------------------------ paso 4

    public function testPaso4ResuelveSoloConExactamenteUnResultado(): void
    {
        $this->catalogo->fulltext['Counterspel'] = [
            ['oracleId' => 'oracle-cs', 'name' => 'Counterspell', 'impresiones' => 1, 'printingUuid' => 'u-cs', 'setCode' => 'LEA'],
        ];

        [$veredicto] = $this->resolvedor->resolver([$this->fila('Counterspel')]);

        self::assertSame('4', $veredicto->paso);
        self::assertSame('Counterspell', $veredicto->name);
        self::assertSame('u-cs', $veredicto->printingUuid);
    }

    /**
     * Los sufijos `*` de la expresión booleana hacen que `Lightning` case con
     * *Lightning Bolt* y con *Lightning Strike* a la vez. Varios resultados no
     * son un ranking: son un conflicto.
     */
    public function testPaso4ConVariosResultadosEsConflictoConSusCandidatos(): void
    {
        $this->catalogo->fulltext['Lightning'] = [
            ['oracleId' => 'o1', 'name' => 'Lightning Bolt', 'impresiones' => 40, 'printingUuid' => null, 'setCode' => null],
            ['oracleId' => 'o2', 'name' => 'Lightning Strike', 'impresiones' => 9, 'printingUuid' => null, 'setCode' => null],
            ['oracleId' => 'o3', 'name' => 'Chain Lightning', 'impresiones' => 5, 'printingUuid' => null, 'setCode' => null],
        ];

        [$veredicto] = $this->resolvedor->resolver([$this->fila('Lightning')]);

        self::assertFalse($veredicto->estaResuelta());
        self::assertSame(CardResolution::AMBIGUA, $veredicto->motivo);
        self::assertCount(3, $veredicto->candidatos);
    }

    public function testSinNingunResultadoElMotivoEsNotFound(): void
    {
        [$veredicto] = $this->resolvedor->resolver([$this->fila('Lightnig Bolt')]);

        self::assertFalse($veredicto->estaResuelta());
        self::assertSame(CardResolution::NO_ENCONTRADA, $veredicto->motivo);
        self::assertSame([], $veredicto->candidatos);
    }

    // -------------------------------------------------------- filas inválidas

    /**
     * Una fila que el parser marcó como inválida va directa a conflicto: no se
     * intenta resolver, y desde luego no cae a un valor por defecto.
     */
    public function testUnaFilaInvalidaNiSiquieraEntraEnLosCuatroPasos(): void
    {
        $fila = $this->fila('Lightning Bolt', errores: ['condition' => 'valor desconocido: MINT?']);

        [$veredicto] = $this->resolvedor->resolver([$fila]);

        self::assertFalse($veredicto->estaResuelta());
        self::assertSame(CardResolution::INVALIDA, $veredicto->motivo);
        self::assertSame(0, $this->catalogo->llamadas['cartasPorNombreNormalizado']);
        self::assertSame(0, $this->catalogo->llamadas['cartasPorTexto']);
    }

    // ------------------------------------------------------------------- lote

    /**
     * El contrato del plan: 20.000 líneas no pueden ser 20.000 consultas. Los
     * pasos exactos resuelven el lote entero de una vez.
     */
    public function testLosPasosExactosResuelvenElLoteConUnaConsultaCadaUno(): void
    {
        $this->catalogo
            ->conCarta('Lightning Bolt', 'o1')
            ->conCarta('Counterspell', 'o2')
            ->conCarta('Sol Ring', 'o3');

        $filas = [
            $this->fila('Lightning Bolt', linea: 1),
            $this->fila('Counterspell', linea: 2),
            $this->fila('Sol Ring', linea: 3),
        ];

        $veredictos = $this->resolvedor->resolver($filas);

        self::assertSame(1, $this->catalogo->llamadas['cartasPorNombreNormalizado']);
        self::assertSame(0, $this->catalogo->llamadas['cartasPorTexto']);
        self::assertSame(
            ['Lightning Bolt', 'Counterspell', 'Sol Ring'],
            array_map(static fn ($v) => $v->name, $veredictos)
        );
    }

    /** El orden de salida es el de entrada, con inválidas y conflictos por medio. */
    public function testDevuelveUnVeredictoPorFilaYEnElMismoOrden(): void
    {
        $this->catalogo->conCarta('Sol Ring', 'o3');

        $veredictos = $this->resolvedor->resolver([
            $this->fila('Lightnig Bolt', linea: 1),
            $this->fila('Sol Ring', errores: ['quantity' => 'no es un número'], linea: 2),
            $this->fila('Sol Ring', linea: 3),
        ]);

        self::assertCount(3, $veredictos);
        self::assertSame(CardResolution::NO_ENCONTRADA, $veredictos[0]->motivo);
        self::assertSame(CardResolution::INVALIDA, $veredictos[1]->motivo);
        self::assertTrue($veredictos[2]->estaResuelta());
        self::assertSame([1, 2, 3], array_map(static fn ($v) => $v->fila->sourceLine, $veredictos));
    }

    /**
     * Resolver la CARTA no siempre es resolver la IMPRESIÓN. Si el fichero no
     * decía edición y la carta se imprimió 40 veces, el resolvedor no elige una:
     * la fila queda resuelta y sin `printingUuid`.
     */
    public function testUnaCartaConVariasImpresionesQuedaResueltaPeroSinEdicion(): void
    {
        $this->catalogo->conCarta('Lightning Bolt', 'oracle-bolt', impresiones: 40);

        [$veredicto] = $this->resolvedor->resolver([$this->fila('Lightning Bolt')]);

        self::assertTrue($veredicto->estaResuelta());
        self::assertFalse($veredicto->tieneImpresion());
        self::assertSame('oracle-bolt', $veredicto->oracleId);
    }
}
