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

    // ----------------------------------------------------------------- paso 2b

    /**
     * Las cuatro filas del hito, y las cuatro salidas distintas que exige.
     *
     * Antes de que el paso 2b existiera, `claveDeSet()` devolvía `null` en cuanto
     * faltaba `setCode` y las dos primeras líneas caían al paso 3 con edición
     * asumida entre 12 y 32 impresiones, teniendo el número delante. Las dos
     * cartas están medidas contra la BD viva el 2026-09-15: *Thoughtseize* `1117`
     * y *Alela, Artful Provocateur* `1630` tienen **una sola impresión** en todo el
     * catálogo, las dos de Secret Lair — que es justo la edición que no imprime su
     * código en la esquina.
     *
     * Las otras dos filas son las que prueban que el paso no se pasa de listo:
     * `Plains 250` vive en 14 ediciones y **tiene que seguir comportándose como
     * antes**, y un par que no existe tiene que llegar al paso 4 sin romperse.
     */
    public function testPaso2bResuelveLaImpresionExactaSinQueLaLineaDigaLaEdicion(): void
    {
        $this->catalogo
            ->conImpresion('Thoughtseize', 'oracle-thoughtseize', 'SLD', '1117')
            ->conImpresion('Alela, Artful Provocateur', 'oracle-alela', 'SLD', '1630')
            // La tierra básica, con dos ediciones detrás del mismo número.
            ->conImpresion('Plains', 'oracle-plains', 'DSK', '250')
            ->conImpresion('Plains', 'oracle-plains', 'BLB', '250');

        // Y las mismas cartas vistas por el paso 3, que es como se resolvían ANTES:
        // carta encontrada, edición sin decidir.
        $this->catalogo
            ->conCarta('Thoughtseize', 'oracle-thoughtseize', impresiones: 12)
            ->conCarta('Alela, Artful Provocateur', 'oracle-alela', impresiones: 3)
            ->conCarta('Plains', 'oracle-plains', impresiones: 14);

        $veredictos = $this->resolvedor->resolver([
            $this->fila('Thoughtseize', null, null, '1117', linea: 1),
            $this->fila('Alela, Artful Provocateur', null, null, '1630', linea: 2),
            $this->fila('Plains', null, null, '250', linea: 3),
            $this->fila('Carta Que No Existe', null, null, '9999', linea: 4),
        ]);

        // 1 y 2 — impresión exacta por el paso 2b. `tieneImpresion()` es lo que
        // `ResolveCards` traduce a `assumedPrinting: false`: no se asumió nada.
        self::assertSame(['2b', '2b'], [$veredictos[0]->paso, $veredictos[1]->paso]);
        self::assertTrue($veredictos[0]->tieneImpresion());
        self::assertTrue($veredictos[1]->tieneImpresion());
        self::assertSame('uuid-sld-1117', $veredictos[0]->printingUuid);
        self::assertSame('uuid-sld-1630', $veredictos[1]->printingUuid);
        self::assertSame('SLD', $veredictos[0]->setCode);

        // 3 — la tierra básica, exactamente igual que antes: carta resuelta por el
        // paso 3 y edición todavía por asumir.
        self::assertTrue($veredictos[2]->estaResuelta());
        self::assertSame('3', $veredictos[2]->paso);
        self::assertFalse($veredictos[2]->tieneImpresion());

        // 4 — el par que no existe no rompe nada: llega al paso 4 y sale conflicto.
        self::assertFalse($veredictos[3]->estaResuelta());
        self::assertSame(CardResolution::NO_ENCONTRADA, $veredictos[3]->motivo);
        self::assertSame(1, $this->catalogo->llamadas['cartasPorTexto']);
    }

    /**
     * La regla que impide que este paso rompa nada, aislada: una `Plains` ambigua
     * **no** se convierte en un conflicto nuevo que `/import` antes no tenía.
     * `Island 2` vive en 20 ediciones y `Plains 250` en 14; si el paso 2b
     * conflictuara en vez de ceder el turno, cada tierra básica de cada fichero
     * pasaría a pedir intervención del usuario.
     */
    public function testUnParAmbiguoCedeElTurnoYNoInventaUnConflictoNuevo(): void
    {
        $this->catalogo
            ->conImpresion('Plains', 'oracle-plains', 'DSK', '250')
            ->conImpresion('Plains', 'oracle-plains', 'BLB', '250')
            ->conCarta('Plains', 'oracle-plains', impresiones: 14);

        [$veredicto] = $this->resolvedor->resolver([$this->fila('Plains', null, null, '250')]);

        self::assertTrue($veredicto->estaResuelta());
        self::assertNull($veredicto->motivo);
        self::assertSame('3', $veredicto->paso);
        self::assertSame('oracle-plains', $veredicto->oracleId);
        self::assertFalse($veredicto->tieneImpresion());
    }

    /**
     * El mismo reintento por la cara frontal que el paso 3: quien teclea
     * `Delver of Secrets 51` escribe la mitad izquierda de lo que guarda el
     * catálogo. Comparando literalmente, las 932 cartas de doble cara no casarían
     * nunca por este paso.
     */
    public function testPaso2bCasaTambienPorLaCaraFrontalDeUnaDobleCara(): void
    {
        $this->catalogo->conImpresion(
            'Delver of Secrets // Insectile Aberration',
            'oracle-delver',
            'ISD',
            '51'
        );

        [$veredicto] = $this->resolvedor->resolver([$this->fila('Delver of Secrets', null, null, '51')]);

        self::assertSame('2b', $veredicto->paso);
        self::assertSame('uuid-isd-51', $veredicto->printingUuid);
    }

    /**
     * **La línea que TRAE edición no pasa por el paso 2b**, y no es un detalle de
     * eficiencia. Si `(set, número)` existió y se contradijo con el nombre, la fila
     * va a conflicto `mismatch` — y buscar por nombre y número resolvería a **otra**
     * impresión de la misma carta, tapando el dato contradictorio que hoy sale a la
     * luz. `ICE 96` es *Shyft*: quien teclea `Lim-Dûl's Vault (ICE) 96` tiene que
     * seguir viendo las dos cartas, no llevarse un *Lim-Dûl's Vault* cualquiera.
     */
    public function testUnaLineaConEdicionNoPasaPorElPaso2b(): void
    {
        $this->catalogo->porSetYNumero['ICE|96'] =
            $this->impresion('uuid-shyft', 'oracle-shyft', 'Shyft', 'ICE', '96');
        $this->catalogo
            ->conImpresion("Lim-Dûl's Vault", 'oracle-vault', 'ICE', '96')
            ->conCarta("Lim-Dûl's Vault", 'oracle-vault');

        [$veredicto] = $this->resolvedor->resolver([$this->fila("Lim-Dûl's Vault", null, 'ICE', '96')]);

        self::assertSame(CardResolution::DESACUERDO, $veredicto->motivo);
        self::assertSame(0, $this->catalogo->llamadas['impresionesPorNombreYNumero']);
    }

    /** Y sin número no hay paso 2b que dar: ni siquiera se consulta. */
    public function testSinNumeroElPaso2bNiSiquieraConsulta(): void
    {
        $this->catalogo->conCarta('Sol Ring', 'oracle-ring');

        [$veredicto] = $this->resolvedor->resolver([$this->fila('Sol Ring')]);

        self::assertSame('3', $veredicto->paso);
        self::assertSame(0, $this->catalogo->llamadas['impresionesPorNombreYNumero']);
    }

    /** El paso 2b es exacto, así que resuelve el lote entero con UNA consulta. */
    public function testElPaso2bResuelveElLoteConUnaSolaConsulta(): void
    {
        $this->catalogo
            ->conImpresion('Thoughtseize', 'oracle-thoughtseize', 'SLD', '1117')
            ->conImpresion('Alela, Artful Provocateur', 'oracle-alela', 'SLD', '1630');

        $veredictos = $this->resolvedor->resolver([
            $this->fila('Thoughtseize', null, null, '1117', linea: 1),
            $this->fila('Alela, Artful Provocateur', null, null, '1630', linea: 2),
            $this->fila('Thoughtseize', null, null, '1117', linea: 3),
        ]);

        self::assertSame(1, $this->catalogo->llamadas['impresionesPorNombreYNumero']);
        self::assertSame(0, $this->catalogo->llamadas['cartasPorNombreNormalizado']);
        self::assertSame(['2b', '2b', '2b'], array_map(static fn ($v) => $v->paso, $veredictos));
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

    // =======================================================================
    // Paso 3c — el nombre en los otros nueve idiomas
    // =======================================================================

    /**
     * **El `*Hecho cuando:*` del M2.** Una lectura que solo trae `name: 'Llanura'`
     * resuelve a *Plains* por el paso `3c`.
     *
     * Antes de este paso devolvía `not_found`: las 410.604 filas de
     * `mtg_printing_localized` no las consultaba nadie, ni desde el escáner ni
     * desde `/import`.
     */
    public function testUnNombreEnEspanolResuelvePorElPaso3c(): void
    {
        $this->catalogo->conCarta('Plains', 'oracle-plains', impresiones: 3);
        $this->catalogo->conNombreLocalizado('oracle-plains', 'Llanura');

        [$veredicto] = $this->resolvedor->resolver([$this->fila('Llanura')]);

        self::assertTrue($veredicto->estaResuelta());
        self::assertSame('oracle-plains', $veredicto->oracleId);
        self::assertSame('3c', $veredicto->paso);
    }

    /** El inglés sigue cerrando en el paso 3: el 3c ni se consulta. */
    public function testElInglesNoBajaAlPasoLocalizado(): void
    {
        $this->catalogo->conCarta('Plains', 'oracle-plains');
        $this->catalogo->conNombreLocalizado('oracle-plains', 'Llanura');

        [$veredicto] = $this->resolvedor->resolver([$this->fila('Plains')]);

        self::assertSame('3', $veredicto->paso);
        self::assertSame(0, $this->catalogo->llamadas['cartasPorNombreLocalizado']);
    }

    /**
     * Misma regla que el paso 3: un nombre traducido que apunta a dos cartas
     * distintas es un empate, y un empate no se elige.
     */
    public function testUnNombreLocalizadoConDosCartasDetrasEsAmbiguo(): void
    {
        $this->catalogo->conCarta('Sly Spy', 'o-spy-1');
        $this->catalogo->conCarta('Sly Spy', 'o-spy-2');
        $this->catalogo->conNombreLocalizado('o-spy-1', 'Espía Astuto');
        $this->catalogo->conNombreLocalizado('o-spy-2', 'Espía Astuto');

        [$veredicto] = $this->resolvedor->resolver([$this->fila('Espía Astuto')]);

        self::assertFalse($veredicto->estaResuelta());
        self::assertSame(CardResolution::AMBIGUA, $veredicto->motivo);
        self::assertCount(2, $veredicto->candidatos);
    }

    // =======================================================================
    // Paso 5 — el nombre aproximado
    // =======================================================================

    /**
     * **El `*Hecho cuando:*` del M2.** «Tlanura» —una errata de UN carácter, que
     * es exactamente lo que el OCR produce— resuelve por el paso `5`.
     *
     * Ni la igualdad del 3 ni el `FULLTEXT` del 4 la rescatan: es el motivo
     * entero de que este paso exista.
     */
    public function testUnaErrataDeUnCaracterResuelvePorElPaso5(): void
    {
        $this->catalogo->conCarta('Llanura', 'oracle-llanura');

        [$veredicto] = $this->resolvedor->resolver([$this->fila('Tlanura')]);

        self::assertTrue($veredicto->estaResuelta());
        self::assertSame('oracle-llanura', $veredicto->oracleId);
        self::assertSame('5', $veredicto->paso);
    }

    /**
     * **La regla dura del paso 5**: dos cartas igual de parecidas a lo leído son
     * un empate, y entre dos igual de parecidas no se elige NUNCA.
     *
     * Es lo que impide que un CSV con un nombre deliberadamente distinto entre
     * por la puerta de atrás del parecido.
     */
    public function testDosCartasALaMismaDistanciaSonAmbiguasYNoSeElige(): void
    {
        // «Llanira» está a UNA sustitución de las dos: `i→u` de *Llanura* y
        // `i→e` de *Llanera*. El empate es en la distancia mínima, que es el
        // único que importa: el repositorio ya descarta los escalones de arriba.
        //
        // Siete caracteres, no cinco: desde el M3b un nombre de menos de seis no
        // busca parecidos, porque a esa longitud lo que llega es ruido del OCR.
        $this->catalogo->conCarta('Llanura', 'o-llanura');
        $this->catalogo->conCarta('Llanera', 'o-llanera');

        [$veredicto] = $this->resolvedor->resolver([$this->fila('Llanira')]);

        self::assertFalse($veredicto->estaResuelta());
        self::assertSame(CardResolution::AMBIGUA, $veredicto->motivo);
        self::assertCount(2, $veredicto->candidatos);
    }

    /**
     * **El `*Hecho cuando:*` del M2.** `Lightning` a secas sale `ambiguous` y no
     * elige — y sale del paso 4, no del 5.
     *
     * Un empate del `FULLTEXT` NO baja al paso 5: si lo leído ya casa con cartas
     * del catálogo, buscar además las que se le parecen solo puede convertir un
     * empate honesto en una resolución falsa.
     */
    public function testUnEmpateDelFulltextNoBajaAlPasoAproximado(): void
    {
        $this->catalogo->conCarta('Lightning Bolt', 'o-bolt');
        $this->catalogo->conCarta('Lightning Strike', 'o-strike');
        $this->catalogo->fulltext['Lightning'] = [
            ['name' => 'Lightning Bolt', 'oracleId' => 'o-bolt', 'impresiones' => 1,
             'printingUuid' => null, 'setCode' => null],
            ['name' => 'Lightning Strike', 'oracleId' => 'o-strike', 'impresiones' => 1,
             'printingUuid' => null, 'setCode' => null],
        ];

        [$veredicto] = $this->resolvedor->resolver([$this->fila('Lightning')]);

        self::assertFalse($veredicto->estaResuelta());
        self::assertSame(CardResolution::AMBIGUA, $veredicto->motivo);
        self::assertSame(0, $this->catalogo->llamadas['cartasPorNombreAproximado']);
    }

    /**
     * Un nombre que no se parece a nada sigue siendo `not_found`: el paso 5 no
     * toca el veredicto que puso el 4, así que nadie tiene que acordarse de
     * volver a escribirlo.
     */
    public function testLoQueNoSeParaceANadaSigueSiendoNotFound(): void
    {
        $this->catalogo->conCarta('Lightning Bolt', 'o-bolt');

        [$veredicto] = $this->resolvedor->resolver([$this->fila('Qwertyuiop Asdfgh')]);

        self::assertFalse($veredicto->estaResuelta());
        self::assertSame(CardResolution::NO_ENCONTRADA, $veredicto->motivo);
    }

    /**
     * El paso 5 **no se consulta una vez por fila**: un lote con la misma errata
     * repetida —el bucle del escáner apuntando tres segundos a la misma carta—
     * la memoriza por clave, igual que hace el paso 4.
     */
    public function testLaMismaErrataRepetidaSoloSeMideUnaVez(): void
    {
        $this->catalogo->conCarta('Llanura', 'oracle-llanura');

        $this->resolvedor->resolver([
            $this->fila('Tlanura', linea: 1),
            $this->fila('Tlanura', linea: 2),
            $this->fila('Tlanura', linea: 3),
        ]);

        self::assertSame(1, $this->catalogo->llamadas['cartasPorNombreAproximado']);
    }
}
