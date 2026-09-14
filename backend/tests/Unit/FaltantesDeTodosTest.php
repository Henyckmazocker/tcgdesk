<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Application\UseCase\AddCardToDeck;
use App\Application\UseCase\AddToCollection;
use App\Application\UseCase\AnalyzeDeckAvailability;
use App\Application\UseCase\CreateDeck;
use App\Application\UseCase\ListDecks;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Doubles\ColeccionFalsa;
use Tests\Unit\Doubles\MazosFalsos;

/**
 * **El agregado tiene que decir exactamente lo mismo que el bucle que sustituye.**
 *
 * `missingCount` es un número que nadie va a auditar a ojo: si
 * `faltantesDeTodos()` no coincide con lo que devolvía llamar a
 * `AnalyzeDeckAvailability` mazo a mazo, `/decks` empieza a mentir sobre lo que
 * te falta y nadie se entera. Así que lo que se compara aquí no son valores
 * escritos a mano sino **las dos vías, una contra otra**, sobre el mismo juego
 * de mazos.
 *
 * Y el caso que la comparación NO puede cubrir sola, porque no existe en la base
 * de datos de desarrollo: **el mazo vacío**. Antes se saltaba explícitamente en
 * el controller —`cardLines === 0` → 0 sin preguntar— y ahora sale del `?? 0` de
 * `ListDecks`, porque un mazo sin cartas no tiene ni una línea que cruzar y no
 * aparece en el array. Sin ese defecto, la lista enseñaría un hueco justo en el
 * mazo recién creado, que es el que más veces se mira.
 */
final class FaltantesDeTodosTest extends TestCase
{
    private const USUARIO = 7;

    private const OTRO = 99;

    private ColeccionFalsa $coleccion;

    private MazosFalsos $repo;

    private ListDecks $listar;

    private AnalyzeDeckAvailability $analizar;

    protected function setUp(): void
    {
        $this->coleccion = new ColeccionFalsa();
        $this->repo      = new MazosFalsos($this->coleccion);
        $this->listar    = new ListDecks($this->repo);
        $this->analizar  = new AnalyzeDeckAvailability($this->repo);
    }

    /**
     * La comparación que pide el plan: el lote contra las N llamadas sueltas,
     * sobre un juego de mazos con de todo —uno que va justo, uno al que le
     * sobra, uno en construcción, uno desmontado y uno vacío—.
     */
    public function testElLoteDiceLoMismoQueLlamarAFaltantesMazoAMazo(): void
    {
        $completo = $this->crearMazo('Completo', 'built');
        $this->anadirCarta($completo, 'uuid-sol', 3);
        $this->enLaColeccion('uuid-sol', 3);

        $incompleto = $this->crearMazo('Incompleto', 'built');
        $this->anadirCarta($incompleto, 'uuid-mox', 4);
        $this->enLaColeccion('uuid-mox', 1);

        // En construcción: `faltantes()` NO filtra por estado, y justo lo que se
        // quiere saber de un mazo a medias es cuánto le falta.
        $enObra = $this->crearMazo('En obra', 'building');
        $this->anadirCarta($enObra, 'uuid-lotus', 2);

        // Desmontado: es archivo, pero sigue pidiendo lo que pide.
        $archivado = $this->crearMazo('Archivado', 'dismantled');
        $this->anadirCarta($archivado, 'uuid-ring', 1);

        $this->crearMazo('Vacío', 'building');

        $lote = $this->repo->faltantesDeTodos(self::USUARIO);

        foreach ($this->repo->allByUser(self::USUARIO) as $mazo) {
            $unoAUno = 0;

            foreach ($this->repo->faltantes(self::USUARIO, $mazo['id']) as $linea) {
                $unoAUno += (int) $linea['missing'];
            }

            self::assertSame(
                $unoAUno,
                $lote[$mazo['id']] ?? 0,
                "El lote y las N llamadas discrepan en el mazo «{$mazo['name']}»."
            );
        }

        // Y los números concretos, para que la comparación no pueda salir verde
        // por ser dos ceros.
        self::assertSame(0, $lote[$completo]);
        self::assertSame(3, $lote[$incompleto]);
        self::assertSame(2, $lote[$enObra]);
        self::assertSame(1, $lote[$archivado]);
    }

    /**
     * Lo que el `missingCount` ve, que es lo que de verdad pinta `/decks`: el
     * use case contra el bucle que hacía el controller hasta el 2026-09-12.
     */
    public function testElMissingCountDeListDecksEsElMismoQueDabaElBucleDelController(): void
    {
        $uno = $this->crearMazo('Uno', 'built');
        $this->anadirCarta($uno, 'uuid-sol', 3);
        $this->enLaColeccion('uuid-sol', 1);

        // El `uuid-sol` de este mazo SÍ está cubierto —el cruce es por mazo, no
        // entre mazos: lo que reclame «Uno» no se le descuenta a «Dos»—, así que
        // sus 3 que faltan salen enteros del `uuid-mox`.
        $dos = $this->crearMazo('Dos', 'building');
        $this->anadirCarta($dos, 'uuid-mox', 3);
        $this->anadirCarta($dos, 'uuid-sol', 1);

        $tres = $this->crearMazo('Tres', 'built');

        foreach (($this->listar)(self::USUARIO, [])['decks'] as $mazo) {
            // El código exacto que corría en `DeckController::list()`.
            $comoAntes = $mazo['cardLines'] === 0
                ? 0
                : (int) (($this->analizar)(self::USUARIO, ['deck_id' => $mazo['id']])['missing'] ?? 0);

            self::assertSame(
                $comoAntes,
                $mazo['missingCount'],
                "El mazo «{$mazo['name']}» ya no dice lo mismo que decía el bucle."
            );
        }

        self::assertSame(
            [$uno => 2, $dos => 3, $tres => 0],
            array_column(($this->listar)(self::USUARIO, [])['decks'], 'missingCount', 'id')
        );
    }

    /**
     * **El caso que la base de datos de desarrollo no tiene**: un mazo sin
     * cartas. No aparece en el array del lote —no hay línea que cruzar— y tiene
     * que salir como `0`, nunca ausente.
     */
    public function testUnMazoVacioNoSaleEnElLoteYSuMissingCountEsCero(): void
    {
        $vacio = $this->crearMazo('Recién creado', 'building');

        self::assertArrayNotHasKey(
            $vacio,
            $this->repo->faltantesDeTodos(self::USUARIO),
            'Un mazo sin cartas no produce fila: quien lo consuma pone el 0.'
        );

        $decks = ($this->listar)(self::USUARIO, [])['decks'];

        self::assertArrayHasKey('missingCount', $decks[0], 'Sin la clave, la vista pintaría `undefined`.');
        self::assertSame(0, $decks[0]['missingCount']);
    }

    /**
     * Un mazo de solo tokens es un mazo vacío a efectos del cruce: los tokens no
     * consumen colección, así que tampoco pueden faltar.
     */
    public function testUnMazoDeSoloTokensTambienSaleACero(): void
    {
        $mazo = $this->crearMazo('Solo fichas', 'built');
        $this->anadirCarta($mazo, 'uuid-token', 5, 'tokens');

        self::assertArrayNotHasKey($mazo, $this->repo->faltantesDeTodos(self::USUARIO));
        self::assertSame(0, ($this->listar)(self::USUARIO, [])['decks'][0]['missingCount']);
    }

    /**
     * **Lo que sobra en una línea no tapa lo que falta en otra.**
     *
     * Es la razón por la que la consulta agrega en dos niveles: si el recorte a
     * 0 se hiciera sobre el total del mazo en vez de línea a línea, las tres
     * copias de más de una carta cancelarían las tres que faltan de otra y el
     * mazo diría que está completo.
     */
    public function testLoQueSobraEnUnaLineaNoTapaLoQueFaltaEnOtra(): void
    {
        $mazo = $this->crearMazo('Descompensado', 'built');

        $this->anadirCarta($mazo, 'uuid-sobra', 1);
        $this->enLaColeccion('uuid-sobra', 4);

        $this->anadirCarta($mazo, 'uuid-falta', 3);

        self::assertSame(3, $this->repo->faltantesDeTodos(self::USUARIO)[$mazo]);
    }

    /** El lote lleva el `user_id` al `WHERE`, igual que el resto del puerto. */
    public function testElLoteNoVeLosMazosDeOtroUsuario(): void
    {
        $mio = $this->crearMazo('Mío', 'built');
        $this->anadirCarta($mio, 'uuid-sol', 2);

        $suyo = (new CreateDeck($this->repo))(self::OTRO, ['name' => 'Suyo', 'status' => 'built']);
        (new AddCardToDeck($this->repo))(self::OTRO, [
            'deck_id'       => $suyo['deck']['id'],
            'printing_uuid' => 'uuid-sol',
            'count'         => 9,
        ]);

        $lote = $this->repo->faltantesDeTodos(self::USUARIO);

        self::assertSame([$mio => 2], $lote);
    }

    private function crearMazo(string $nombre, string $estado): int
    {
        $creado = (new CreateDeck($this->repo))(self::USUARIO, ['name' => $nombre, 'status' => $estado]);

        return (int) $creado['deck']['id'];
    }

    private function anadirCarta(int $deckId, string $uuid, int $cantidad, string $board = 'main'): void
    {
        (new AddCardToDeck($this->repo))(self::USUARIO, [
            'deck_id'       => $deckId,
            'printing_uuid' => $uuid,
            'count'         => $cantidad,
            'board'         => $board,
        ]);
    }

    private function enLaColeccion(string $uuid, int $cantidad): void
    {
        (new AddToCollection($this->coleccion))(self::USUARIO, [
            'printing_uuid' => $uuid,
            'quantity'      => $cantidad,
        ]);
    }
}
