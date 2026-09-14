<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Application\UseCase\AddCardToDeck;
use App\Application\UseCase\CreateDeck;
use App\Application\UseCase\GetDeck;
use App\Application\UseCase\UpdateDeck;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Doubles\MazosFalsos;

/**
 * `mtg_format`: la lista de formatos, destilada una vez por la ingesta.
 *
 * El #17 cambia **de dónde** sale la respuesta, no cuál es. Antes
 * `formatoConocido()` preguntaba a `mtg_legality` con un `EXISTS`; ahora lee
 * `mtg_format`, que es el `SELECT DISTINCT format` de esa misma tabla ya hecho
 * por `catalog:import`. El criterio de este hito es literalmente ese: que la
 * respuesta **no se mueva**.
 *
 * El caso que manda es `commander` contra `edh`, el error de tecleo real:
 * `edh` es como todo el mundo llama al formato y **no existe** en MTGJSON. Un
 * mazo con `format = 'edh'` no puede salir con sus cien cartas marcadas
 * `not_legal`; tiene que salir con `known: false` y sin marcar nada.
 *
 * Va contra `MazosFalsos`, que resuelve los dos métodos sobre la MISMA lista
 * —igual que el repositorio real los resuelve sobre la misma tabla—, así que un
 * doble que dijera «`edh` no existe» y lo ofreciera en el desplegable no podría
 * pasar por aquí. Lo que el doble no puede probar es el SQL; eso se verifica
 * contra MySQL con `catalog:import` y el cotejo de `COUNT(*)`.
 */
final class FormatosConocidosTest extends TestCase
{
    private const ORACLE_SOL_RING = 'oracle-sol-ring';

    private MazosFalsos $repo;

    private GetDeck $ver;

    protected function setUp(): void
    {
        $this->repo = new MazosFalsos();
        $this->ver  = new GetDeck($this->repo);

        $this->repo->oracles = ['uuid-sol-ring' => self::ORACLE_SOL_RING];

        // Las legalidades reales de Sol Ring, con `modern` ausente a propósito:
        // `not_legal` es la falta de fila, no un valor que la ingesta escriba.
        $this->repo->legalidades = [
            self::ORACLE_SOL_RING . '|commander' => 'legal',
            self::ORACLE_SOL_RING . '|legacy'    => 'banned',
            self::ORACLE_SOL_RING . '|vintage'   => 'restricted',
        ];

        // Lo que `catalog:import` dejaría en `mtg_format`: los formatos que
        // existen, incluido `modern` —del que Sol Ring no tiene fila— y sin
        // `edh`, que no existe en MTGJSON por mucho que se teclee.
        $this->repo->formatos = ['commander', 'legacy', 'modern', 'vintage'];

        (new CreateDeck($this->repo))(1, ['name' => 'El mazo del Sol Ring', 'format' => 'commander']);

        (new AddCardToDeck($this->repo))(1, [
            'deck_id'       => 1,
            'printing_uuid' => 'uuid-sol-ring',
            'count'         => 1,
        ]);
    }

    public function testCommanderEsConocidoYEdhNoLoEs(): void
    {
        self::assertTrue($this->repo->formatoConocido('commander'));
        self::assertFalse($this->repo->formatoConocido('edh'));
    }

    /**
     * Un formato que existe pero en el que esta carta no tiene fila **sigue
     * siendo conocido**. Es la distinción entera del #17: «no es legal ahí» y
     * «ese formato no existe» son cosas distintas y se responden distinto.
     */
    public function testUnFormatoSinFilasParaEstaCartaSigueSiendoConocido(): void
    {
        self::assertTrue($this->repo->formatoConocido('modern'));
    }

    /**
     * El motivo por el que `formatoConocido()` existe: con `edh`, las cien
     * cartas del mazo saldrían `not_legal` —una alarma falsa y completa— si no
     * se cortara antes.
     */
    public function testConEdhLaFichaAvisaDelFormatoYNoMarcaNingunaCarta(): void
    {
        (new UpdateDeck($this->repo))(1, ['deck_id' => 1, 'format' => 'edh']);

        $legalidad = ($this->ver)(1, ['deck_id' => 1])['legality'];

        self::assertSame('edh', $legalidad['format']);
        self::assertFalse($legalidad['known'], 'edh no existe: se dice, no se marca');
        self::assertSame(0, $legalidad['notLegal']);
        self::assertSame([], $legalidad['statuses']);
    }

    /**
     * Y el contraste, sobre el mismo mazo y con el mismo Sol Ring: en `legacy`
     * —que sí existe— la ficha entra a mirar y lo marca `banned`. Sin este
     * test, un `formatoConocido()` roto hacia el otro lado —diciendo que no
     * existe nada— dejaría la ficha muda y el test de `edh` pasaría igual.
     */
    public function testConUnFormatoQueExisteLaFichaSiEntraAMirarLaLegalidad(): void
    {
        (new UpdateDeck($this->repo))(1, ['deck_id' => 1, 'format' => 'legacy']);

        $legalidad = ($this->ver)(1, ['deck_id' => 1])['legality'];

        self::assertTrue($legalidad['known']);
        self::assertSame(1, $legalidad['banned'], 'Sol Ring está banned en Legacy');
        self::assertSame([self::ORACLE_SOL_RING => 'banned'], $legalidad['statuses']);
    }

    /**
     * La lista que alimentará el desplegable: completa, ordenada y sin repetidos.
     */
    public function testLaListaLlegaOrdenadaYSinRepetidos(): void
    {
        $formatos = $this->repo->formatosConocidos();

        self::assertSame(['commander', 'legacy', 'modern', 'vintage'], $formatos);
        self::assertSame(array_values(array_unique($formatos)), $formatos);
        self::assertNotContains('edh', $formatos);
    }

    /**
     * Los dos métodos leen **la misma** tabla: lo que se ofrece en la lista es
     * exactamente lo que `formatoConocido()` acepta. Divergir ahí sería ofrecer
     * un formato en el desplegable y avisar de que no existe al elegirlo.
     */
    public function testTodoLoQueSeOfreceEsConocidoYAlReves(): void
    {
        foreach ($this->repo->formatosConocidos() as $formato) {
            self::assertTrue($this->repo->formatoConocido($formato), $formato);
        }

        self::assertFalse($this->repo->formatoConocido('oathbreaker'));
        self::assertNotContains('oathbreaker', $this->repo->formatosConocidos());
    }

    /**
     * Sin lista explícita, el doble la destila de las legalidades — que es lo
     * que hace la ingesta de verdad con su `SELECT DISTINCT`. Sirve para que
     * ningún test tenga que declarar dos veces la misma verdad.
     */
    public function testSinListaExplicitaSeDestilaDeLasLegalidades(): void
    {
        $this->repo->formatos = null;

        self::assertSame(['commander', 'legacy', 'vintage'], $this->repo->formatosConocidos());
        self::assertTrue($this->repo->formatoConocido('commander'));
        self::assertFalse($this->repo->formatoConocido('edh'));
    }

    // =====================================================================
    // La lista, dentro de `deck_get` — el desplegable de la ficha
    // =====================================================================

    /**
     * Viaja **dentro del bloque de legalidad de `deck_get`**, que es donde se
     * decidió que fuera: no hay acción nueva ni ruta `GET` nueva —serían 21
     * valores que ni paginan ni se comparten por URL—, y pedir la ficha y la
     * lista por separado haría que el desplegable llegara después que el campo.
     */
    public function testLaListaViajaDentroDeDeckGet(): void
    {
        $legalidad = ($this->ver)(1, ['deck_id' => 1])['legality'];

        self::assertSame(
            $this->repo->formatosConocidos(),
            $legalidad['formatosDisponibles'],
            'Lo que se ofrece es exactamente lo que el repositorio conoce'
        );
    }

    /**
     * El caso que este hito viene a resolver: **el mazo sin formato**. Es el que
     * más necesita el desplegable y el que se cae por el retorno temprano de
     * `GetDeck::legalidad()` —sin formato no hay legalidades que mirar—, así que
     * si la lista se rellenara solo en el camino largo saldría vacía justo aquí.
     */
    public function testUnMazoSinFormatoTambienTraeLaLista(): void
    {
        (new UpdateDeck($this->repo))(1, ['deck_id' => 1, 'format' => null]);

        $legalidad = ($this->ver)(1, ['deck_id' => 1])['legality'];

        self::assertNull($legalidad['format'], 'El mazo sigue sin formato…');
        self::assertFalse($legalidad['known']);
        self::assertSame(
            ['commander', 'legacy', 'modern', 'vintage'],
            $legalidad['formatosDisponibles'],
            '…y aun así puede elegir uno'
        );
    }

    /**
     * Y el otro retorno temprano, el del formato tecleado a mano que no existe:
     * `edh` sigue avisando —eso no cambia— y el desplegable sigue ofreciendo de
     * dónde elegir el bueno, que es la única forma de corregirlo sin teclear.
     */
    public function testConUnFormatoInventadoSeAvisaYAunAsiSeOfreceLaLista(): void
    {
        (new UpdateDeck($this->repo))(1, ['deck_id' => 1, 'format' => 'edh']);

        $legalidad = ($this->ver)(1, ['deck_id' => 1])['legality'];

        self::assertFalse($legalidad['known'], 'El aviso de `edh` se queda tal cual');
        self::assertContains('commander', $legalidad['formatosDisponibles']);
        self::assertNotContains('edh', $legalidad['formatosDisponibles']);
    }

    /**
     * Nada de lo que ya viajaba en el bloque cambia de nombre ni de forma: la
     * lista es un campo **añadido**. Un renombrado «ya que estamos» aquí tumba
     * la ficha entera del mazo, que lee estas nueve claves.
     */
    public function testElRestoDelBloqueDeLegalidadNoCambiaDeForma(): void
    {
        $legalidad = ($this->ver)(1, ['deck_id' => 1])['legality'];

        self::assertSame(
            [
                'format', 'known', 'statuses', 'banned', 'restricted', 'notLegal',
                'size', 'minSize', 'belowMinimum', 'formatosDisponibles',
            ],
            array_keys($legalidad)
        );
    }
}
