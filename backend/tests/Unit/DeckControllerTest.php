<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Application\UseCase\AddCardToDeck;
use App\Application\UseCase\AddToCollection;
use App\Application\UseCase\AnalyzeDeckAvailability;
use App\Application\UseCase\ChangeDeckCardCount;
use App\Application\UseCase\ChangeDeckCardIdentity;
use App\Application\UseCase\CreateDeck;
use App\Application\UseCase\DeleteDeck;
use App\Application\UseCase\GetDeck;
use App\Application\UseCase\ListDeckCardVariants;
use App\Application\UseCase\ListDecks;
use App\Application\UseCase\RemoveCardFromDeck;
use App\Application\UseCase\ShareDeck;
use App\Application\UseCase\UnshareDeck;
use App\Application\UseCase\UpdateDeck;
use App\Controllers\DeckController;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Tests\Unit\Doubles\ColeccionFalsa;
use Tests\Unit\Doubles\MazosFalsos;

/**
 * **Los doce contratos de los mazos** —los diez de M4 y los dos que el
 * Plan - Perfil Público añadió al compartirlos por enlace—, uno por uno, por
 * donde entran de verdad: el
 * request que arma `ActionRouter` —el payload bajo `data` y el `user_id` puesto
 * por `AuthMiddleware`— y la respuesta con su `http_code`.
 *
 * Existen porque el login de este proyecto es el de Google y **no se puede hacer
 * con `curl`** en local: no hay forma de conseguir un `id_token` válido desde la
 * línea de órdenes, así que la cobertura de los diez contratos vive aquí. Lo que
 * `curl` sí prueba, y se hizo, son las dos cosas que estos tests no pueden ver
 * porque ocurren *antes* del controller: el **403** de una escritura sin token
 * CSRF y el **401** sin sesión. Hay precedente de esta división en
 * `ImportControllerTest`.
 *
 * El assert que más importa no es ninguno de los diez sino
 * `testElUserIdSaleDelRequestYNuncaDelPayload`: `mtg_deck.id` es un
 * autoincremental **global**, así que si el `user_id` viniera del cuerpo de la
 * petición bastaría con cambiar un número para leer o vaciar el mazo de otro.
 */
final class DeckControllerTest extends TestCase
{
    private const USUARIO = 7;

    private const OTRO = 99;

    private ColeccionFalsa $coleccion;

    private MazosFalsos $repo;

    private DeckController $controller;

    protected function setUp(): void
    {
        $this->coleccion = new ColeccionFalsa();
        $this->repo      = new MazosFalsos($this->coleccion);

        $this->controller = new DeckController(
            new ListDecks($this->repo),
            new GetDeck($this->repo),
            new CreateDeck($this->repo),
            new UpdateDeck($this->repo),
            new DeleteDeck($this->repo),
            new AddCardToDeck($this->repo),
            new ChangeDeckCardCount($this->repo),
            new RemoveCardFromDeck($this->repo),
            new ChangeDeckCardIdentity($this->repo),
            new ListDeckCardVariants($this->repo),
            new AnalyzeDeckAvailability($this->repo),
            new ShareDeck($this->repo),
            new UnshareDeck($this->repo),
            new NullLogger()
        );
    }

    /**
     * La forma exacta que arma `ActionRouter`: el payload entero bajo `data` y
     * el `user_id` que puso `AuthMiddleware`, jamás el del cuerpo.
     *
     * @param  array<string, mixed> $datos
     * @return array<string, mixed>
     */
    private function peticion(string $accion, array $datos = [], int $userId = self::USUARIO): array
    {
        return ['action' => $accion, 'user_id' => $userId, 'data' => $datos];
    }

    /** @param array<string, mixed> $datos */
    private function crearMazo(array $datos = ['name' => 'Atraxa']): int
    {
        return (int) $this->controller->create($this->peticion('deck_create', $datos))['data']['deck']['id'];
    }

    /** @param array<string, mixed> $datos */
    private function anadirCarta(int $deckId, array $datos): int
    {
        $respuesta = $this->controller->cardAdd($this->peticion('deck_card_add', $datos + ['deck_id' => $deckId]));

        return (int) $respuesta['data']['card']['id'];
    }

    /** @param array<string, mixed> $datos */
    private function anadirALaColeccion(array $datos): void
    {
        (new AddToCollection($this->coleccion))(self::USUARIO, $datos);
    }

    // ========================================================================
    // 1. deck_create  {name, status?, format?, notes?} → {deck:{...}}
    // ========================================================================

    public function testDeckCreateDevuelveElMazoRecienCreado(): void
    {
        $respuesta = $this->controller->create($this->peticion('deck_create', [
            'name'   => 'Atraxa',
            'format' => 'commander',
        ]));

        self::assertSame('success', $respuesta['status']);
        self::assertSame(200, $respuesta['http_code']);
        self::assertSame('Atraxa', $respuesta['data']['deck']['name']);
        self::assertSame('commander', $respuesta['data']['deck']['format']);
        // Un mazo recién creado está vacío, luego no puede estar montado.
        self::assertSame('building', $respuesta['data']['deck']['status']);
    }

    /**
     * Un estado inventado es un fallo del cliente, no algo que adivinar: 422 y
     * nunca el valor por defecto, que dejaría el mazo consumiendo colección sin
     * que nadie lo haya pedido.
     */
    public function testDeckCreateConUnEstadoInventadoDevuelve422(): void
    {
        $respuesta = $this->controller->create($this->peticion('deck_create', [
            'name'   => 'Atraxa',
            'status' => 'medio-montado',
        ]));

        self::assertSame('error', $respuesta['status']);
        self::assertSame(422, $respuesta['http_code']);
    }

    // ========================================================================
    // 2. deck_list  {} → {decks:[{id,name,status,format,cards,valueEur,missingCount}]}
    // ========================================================================

    public function testDeckListDevuelveLosMazosConSusContadoresYLoQueFalta(): void
    {
        $mazo = $this->crearMazo(['name' => 'Atraxa', 'status' => 'built']);

        $this->anadirCarta($mazo, ['printing_uuid' => 'uuid-sol', 'count' => 3]);
        $this->anadirALaColeccion(['printing_uuid' => 'uuid-sol', 'quantity' => 1]);

        $respuesta = $this->controller->list($this->peticion('deck_list'));

        self::assertSame('success', $respuesta['status']);
        self::assertCount(1, $respuesta['data']['decks']);

        $fila = $respuesta['data']['decks'][0];

        foreach (['id', 'name', 'status', 'format', 'cards', 'valueEur', 'missingCount'] as $clave) {
            self::assertArrayHasKey($clave, $fila, "El contrato de deck_list exige la clave {$clave}.");
        }

        self::assertSame(3, $fila['cards']);
        self::assertSame(2, $fila['missingCount'], 'Pide 3 y tiene 1: le faltan 2');
    }

    /** Un mazo vacío no se pregunta: no hay nada que le pueda faltar. */
    public function testDeckListDeUnMazoVacioDiceQueNoLeFaltaNada(): void
    {
        $this->crearMazo();

        $respuesta = $this->controller->list($this->peticion('deck_list'));

        self::assertSame(0, $respuesta['data']['decks'][0]['missingCount']);
    }

    public function testDeckListSoloVeLosMazosDelUsuarioDelRequest(): void
    {
        $this->crearMazo(['name' => 'Atraxa']);

        $respuesta = $this->controller->list($this->peticion('deck_list', [], self::OTRO));

        self::assertSame([], $respuesta['data']['decks']);
    }

    // ========================================================================
    // 3. deck_get  {deck_id} → {deck, boards, valueEur, availability, legality}
    // ========================================================================

    public function testDeckGetDevuelveElMazoPorZonasYSuAnalisis(): void
    {
        $mazo = $this->crearMazo(['name' => 'Atraxa', 'status' => 'built']);

        $this->anadirCarta($mazo, ['printing_uuid' => 'uuid-sol', 'count' => 2]);
        $this->anadirCarta($mazo, ['printing_uuid' => 'uuid-swords', 'count' => 1, 'board' => 'side']);
        $this->anadirALaColeccion(['printing_uuid' => 'uuid-sol', 'quantity' => 2]);

        $respuesta = $this->controller->get($this->peticion('deck_get', ['deck_id' => $mazo]));

        self::assertSame('success', $respuesta['status']);
        self::assertSame('Atraxa', $respuesta['data']['deck']['name']);
        self::assertArrayHasKey('valueEur', $respuesta['data']);

        // Las zonas, y solo las que tienen cartas.
        self::assertSame(['main', 'side'], array_keys($respuesta['data']['boards']));
        self::assertCount(1, $respuesta['data']['boards']['main']);

        // El análisis viaja con el mazo: la vista no puede pintar una línea sin
        // saber si la tienes.
        $porCarta = array_column($respuesta['data']['availability'], null, 'printingUuid');

        self::assertSame(0, $porCarta['uuid-sol']['missing'], 'Pide 2 y tiene 2');
        self::assertSame(1, $porCarta['uuid-swords']['missing'], 'Pide 1 y no tiene ninguna');
        self::assertSame(1, $respuesta['data']['missing']);

        // El mazo está CONSTRUIDO y pide una carta que no está en la colección,
        // así que la misma línea sale además en `conflicts` —que es el aviso
        // global— con el mazo nombrado, que es lo que la UI necesita para
        // ofrecer desmontarlo. `conflicts` no se recorta a este mazo a
        // propósito: un conflicto es siempre entre varios.
        self::assertTrue($respuesta['data']['overallocated']);
        self::assertSame('uuid-swords', $respuesta['data']['conflicts'][0]['printingUuid']);
        self::assertSame('Atraxa', $respuesta['data']['conflicts'][0]['decks'][0]['name']);
    }

    public function testDeckGetLlevaLaLegalidadDentro(): void
    {
        // La legalidad NO es una acción aparte: viaja con la ficha, igual que la
        // disponibilidad. Pedir el mazo y los avisos por separado haría que la
        // pantalla cambiara sola delante del usuario.
        $mazo = $this->crearMazo(['name' => 'Atraxa', 'format' => 'legacy']);

        $this->repo->oracles     = ['uuid-sol' => 'oracle-sol'];
        $this->repo->legalidades = ['oracle-sol|legacy' => 'banned'];
        $this->repo->formatos    = ['legacy'];

        $this->anadirCarta($mazo, ['printing_uuid' => 'uuid-sol', 'count' => 2]);

        $respuesta = $this->controller->get($this->peticion('deck_get', ['deck_id' => $mazo]));

        self::assertSame('success', $respuesta['status'], 'Un mazo ilegal es un mazo que existe');
        self::assertSame('banned', $respuesta['data']['legality']['statuses']['oracle-sol']);
        self::assertSame(60, $respuesta['data']['legality']['minSize']);
        self::assertTrue($respuesta['data']['legality']['belowMinimum']);
        // Y la carta prohibida sigue en la lista, marcada y no escondida.
        self::assertCount(1, $respuesta['data']['boards']['main']);
    }

    public function testDeckGetDeUnMazoAjenoDevuelve404YNoDiceQueExiste(): void
    {
        $mazo = $this->crearMazo();

        $respuesta = $this->controller->get($this->peticion('deck_get', ['deck_id' => $mazo], self::OTRO));

        self::assertSame('error', $respuesta['status']);
        self::assertSame(404, $respuesta['http_code']);
    }

    // ========================================================================
    // 4. deck_update  {deck_id, name?, status?, format?, notes?} → {deck:{...}}
    // ========================================================================

    /**
     * **La edición es parcial**: el botón «desmontar» de M5 manda solo `status`,
     * y si esto construyera un mazo completo con los defectos que faltan, ese
     * clic borraría el nombre, el formato y las notas.
     */
    public function testDeckUpdateSoloConStatusNoBorraElResto(): void
    {
        $mazo = $this->crearMazo(['name' => 'Atraxa', 'format' => 'commander', 'notes' => 'la de la caja azul']);

        $respuesta = $this->controller->update($this->peticion('deck_update', [
            'deck_id' => $mazo,
            'status'  => 'dismantled',
        ]));

        self::assertSame('success', $respuesta['status']);
        self::assertSame('dismantled', $respuesta['data']['deck']['status']);
        self::assertSame('Atraxa', $respuesta['data']['deck']['name']);
        self::assertSame('commander', $respuesta['data']['deck']['format']);
        self::assertSame('la de la caja azul', $respuesta['data']['deck']['notes']);
    }

    public function testDeckUpdateDeUnMazoAjenoDevuelve404(): void
    {
        $mazo = $this->crearMazo();

        $respuesta = $this->controller->update(
            $this->peticion('deck_update', ['deck_id' => $mazo, 'name' => 'Mío ahora'], self::OTRO)
        );

        self::assertSame(404, $respuesta['http_code']);
        self::assertSame('Atraxa', $this->repo->mazos[$mazo]['name']);
    }

    // ========================================================================
    // 5. deck_delete  {deck_id, with_cards} → {deleted, removedFromCollection, shortfall}
    // ========================================================================

    public function testDeckDeleteSinCartasNoTocaLaColeccion(): void
    {
        $mazo = $this->crearMazo();

        $this->anadirCarta($mazo, ['printing_uuid' => 'uuid-sol', 'count' => 2]);
        $this->anadirALaColeccion(['printing_uuid' => 'uuid-sol', 'quantity' => 2]);

        $respuesta = $this->controller->delete($this->peticion('deck_delete', ['deck_id' => $mazo]));

        self::assertSame('success', $respuesta['status']);
        self::assertTrue($respuesta['data']['deleted']);
        self::assertSame(0, $respuesta['data']['removedFromCollection']);
        self::assertSame([], $respuesta['data']['shortfall']);
        self::assertCount(1, $this->coleccion->filas, 'Deshacer un mazo no es vender sus cartas');
    }

    /**
     * Con `with_cards` y la colección corta: se resta hasta 0, **no se falla** y
     * la diferencia sale en `shortfall` para que la UI la enseñe. Vendiste la
     * carta y nunca actualizaste el mazo: es el caso esperado, no el error.
     */
    public function testDeckDeleteConCartasDescuentaYDevuelveLoQueFaltaba(): void
    {
        $mazo = $this->crearMazo();

        $this->anadirCarta($mazo, ['printing_uuid' => 'uuid-sol', 'count' => 3]);
        $this->anadirALaColeccion(['printing_uuid' => 'uuid-sol', 'quantity' => 1]);

        $respuesta = $this->controller->delete($this->peticion('deck_delete', [
            'deck_id'    => $mazo,
            'with_cards' => true,
        ]));

        self::assertSame('success', $respuesta['status']);
        self::assertSame(1, $respuesta['data']['removedFromCollection']);
        self::assertCount(1, $respuesta['data']['shortfall']);
        self::assertSame(2, $respuesta['data']['shortfall'][0]['missing']);
        self::assertSame([], $this->coleccion->filas, 'La fila a 0 se borra, no se queda de fantasma');
    }

    public function testDeckDeleteDeUnMazoAjenoDevuelve404YNoBorraNada(): void
    {
        $mazo = $this->crearMazo();

        $respuesta = $this->controller->delete(
            $this->peticion('deck_delete', ['deck_id' => $mazo], self::OTRO)
        );

        self::assertSame(404, $respuesta['http_code']);
        self::assertArrayHasKey($mazo, $this->repo->mazos);
    }

    // ========================================================================
    // 6. deck_card_add  {deck_id, printing_uuid, …} → {card:{...}}
    // ========================================================================

    public function testDeckCardAddDevuelveLaLineaYSumaEnLugarDeDuplicar(): void
    {
        $mazo = $this->crearMazo();

        $this->controller->cardAdd($this->peticion('deck_card_add', [
            'deck_id'       => $mazo,
            'printing_uuid' => 'uuid-sol',
            'count'         => 1,
        ]));

        $respuesta = $this->controller->cardAdd($this->peticion('deck_card_add', [
            'deck_id'       => $mazo,
            'printing_uuid' => 'uuid-sol',
            'count'         => 1,
        ]));

        self::assertSame('success', $respuesta['status']);
        self::assertSame(2, $respuesta['data']['card']['count'], 'Añadir dos veces SUMA');
        self::assertCount(1, $this->repo->cartas, 'y deja UNA línea, no dos');
    }

    public function testDeckCardAddSobreUnMazoAjenoDevuelve404(): void
    {
        $mazo = $this->crearMazo();

        $respuesta = $this->controller->cardAdd($this->peticion('deck_card_add', [
            'deck_id'       => $mazo,
            'printing_uuid' => 'uuid-sol',
        ], self::OTRO));

        self::assertSame(404, $respuesta['http_code']);
        self::assertSame([], $this->repo->cartas);
    }

    // ========================================================================
    // 7. deck_card_set  {deck_id, card_id, count} → {card:{...}|null}
    // ========================================================================

    public function testDeckCardSetFijaLaCantidadAbsoluta(): void
    {
        $mazo  = $this->crearMazo();
        $carta = $this->anadirCarta($mazo, ['printing_uuid' => 'uuid-sol', 'count' => 1]);

        $respuesta = $this->controller->cardSet($this->peticion('deck_card_set', [
            'deck_id' => $mazo,
            'card_id' => $carta,
            'count'   => 3,
        ]));

        self::assertSame('success', $respuesta['status']);
        self::assertFalse($respuesta['data']['removed']);
        self::assertSame(3, $respuesta['data']['card']['count'], 'Absoluta: tres, no una más tres');
    }

    /**
     * **El 0 borra**, y es una petición legítima: por eso `count` no está en los
     * `required` de la ruta —`ValidationMiddleware` lo trataría como ausente y
     * devolvería un 400— y por eso el contrato admite `card: null`.
     */
    public function testDeckCardSetACeroBorraLaLineaYDevuelveCardNull(): void
    {
        $mazo  = $this->crearMazo();
        $carta = $this->anadirCarta($mazo, ['printing_uuid' => 'uuid-sol', 'count' => 2]);

        $respuesta = $this->controller->cardSet($this->peticion('deck_card_set', [
            'deck_id' => $mazo,
            'card_id' => $carta,
            'count'   => 0,
        ]));

        self::assertSame('success', $respuesta['status']);
        self::assertTrue($respuesta['data']['removed']);
        self::assertNull($respuesta['data']['card']);
        self::assertSame([], $this->repo->cartas);
    }

    public function testDeckCardSetDeUnaLineaQueNoExisteDevuelve404(): void
    {
        $mazo = $this->crearMazo();

        $respuesta = $this->controller->cardSet($this->peticion('deck_card_set', [
            'deck_id' => $mazo,
            'card_id' => 4242,
            'count'   => 2,
        ]));

        self::assertSame(404, $respuesta['http_code']);
    }

    // ========================================================================
    // 8. deck_card_remove  {deck_id, card_id} → {removed:true}
    // ========================================================================

    public function testDeckCardRemoveQuitaLaLineaYNoTocaLaColeccion(): void
    {
        $mazo  = $this->crearMazo();
        $carta = $this->anadirCarta($mazo, ['printing_uuid' => 'uuid-sol', 'count' => 2]);

        $this->anadirALaColeccion(['printing_uuid' => 'uuid-sol', 'quantity' => 2]);

        $respuesta = $this->controller->cardRemove($this->peticion('deck_card_remove', [
            'deck_id' => $mazo,
            'card_id' => $carta,
        ]));

        self::assertSame('success', $respuesta['status']);
        self::assertTrue($respuesta['data']['removed']);
        self::assertSame([], $this->repo->cartas);
        self::assertSame(2, $this->coleccion->filas[1]['quantity'], 'La carta sigue siendo tuya');
    }

    public function testDeckCardRemoveDeUnaLineaAjenaDevuelve404(): void
    {
        $mazo  = $this->crearMazo();
        $carta = $this->anadirCarta($mazo, ['printing_uuid' => 'uuid-sol', 'count' => 1]);

        $respuesta = $this->controller->cardRemove($this->peticion('deck_card_remove', [
            'deck_id' => $mazo,
            'card_id' => $carta,
        ], self::OTRO));

        self::assertSame(404, $respuesta['http_code']);
        self::assertCount(1, $this->repo->cartas);
    }

    // ========================================================================
    // 9. deck_card_change  {deck_id, card_id, …} → {card:{...}, merged:bool}
    // ========================================================================

    public function testDeckCardChangeMueveLaLineaAOtraVersion(): void
    {
        $mazo  = $this->crearMazo();
        $carta = $this->anadirCarta($mazo, ['printing_uuid' => 'uuid-sol', 'count' => 2]);

        $respuesta = $this->controller->cardChange($this->peticion('deck_card_change', [
            'deck_id' => $mazo,
            'card_id' => $carta,
            'finish'  => 'foil',
        ]));

        self::assertSame('success', $respuesta['status']);
        self::assertFalse($respuesta['data']['merged']);
        self::assertSame('foil', $respuesta['data']['card']['finish']);
        self::assertSame(2, $respuesta['data']['card']['count']);
    }

    /**
     * El caso que da nombre a la enmienda: las cuatro columnas están dentro de
     * `uq_deck_card`, así que el cambio **mueve** la fila y el destino puede
     * estar ocupado. Cuando lo está se funden sumando `count`, y `merged: true`
     * avisa a la tabla de que la fila de origen ya no existe.
     */
    public function testDeckCardChangeFundeConLaLineaQueYaExistiaYAvisaConMerged(): void
    {
        $mazo   = $this->crearMazo();
        $normal = $this->anadirCarta($mazo, ['printing_uuid' => 'uuid-sol', 'count' => 2]);

        $this->anadirCarta($mazo, ['printing_uuid' => 'uuid-sol', 'count' => 1, 'finish' => 'foil']);

        $respuesta = $this->controller->cardChange($this->peticion('deck_card_change', [
            'deck_id' => $mazo,
            'card_id' => $normal,
            'finish'  => 'foil',
        ]));

        self::assertTrue($respuesta['data']['merged']);
        self::assertSame(3, $respuesta['data']['card']['count'], '2 + 1 en una sola línea');
        self::assertCount(1, $this->repo->cartas);
        self::assertNotSame($normal, $respuesta['data']['card']['id'], 'El id devuelto NO es el que mandó el cliente');
    }

    /** Un acabado que no existe es 422: guardarlo como 'normal' mentiría en silencio. */
    public function testDeckCardChangeConUnAcabadoInventadoDevuelve422(): void
    {
        $mazo  = $this->crearMazo();
        $carta = $this->anadirCarta($mazo, ['printing_uuid' => 'uuid-sol', 'count' => 1]);

        $respuesta = $this->controller->cardChange($this->peticion('deck_card_change', [
            'deck_id' => $mazo,
            'card_id' => $carta,
            'finish'  => 'holográfica',
        ]));

        self::assertSame(422, $respuesta['http_code']);
    }

    // ========================================================================
    // 10. deck_card_variants  {deck_id, card_id} → {variants:[…]}
    // ========================================================================

    /**
     * **Lo que hace que el cambio sea un clic y no un formulario**: el
     * desplegable de M5 se puebla con las versiones que el usuario tiene de
     * verdad, con cuántas tiene y cuántas le quedan libres.
     */
    public function testDeckCardVariantsDevuelveSoloLoQueElUsuarioTiene(): void
    {
        $mazo  = $this->crearMazo(['name' => 'Atraxa', 'status' => 'built']);
        $carta = $this->anadirCarta($mazo, ['printing_uuid' => 'uuid-sol', 'count' => 1]);

        // Tres versiones en la colección: dos normales NM (una de ellas ya la
        // reclama este mazo), una foil NM y una normal LP.
        $this->anadirALaColeccion(['printing_uuid' => 'uuid-sol', 'quantity' => 2]);
        $this->anadirALaColeccion(['printing_uuid' => 'uuid-sol', 'quantity' => 1, 'finish' => 'foil']);
        $this->anadirALaColeccion(['printing_uuid' => 'uuid-sol', 'quantity' => 1, 'condition' => 'LP']);

        // Y una carta distinta, que no puede colarse en la lista.
        $this->anadirALaColeccion(['printing_uuid' => 'uuid-swords', 'quantity' => 4]);

        $this->repo->precios['uuid-sol|foil'] = 12.5;

        $respuesta = $this->controller->cardVariants($this->peticion('deck_card_variants', [
            'deck_id' => $mazo,
            'card_id' => $carta,
        ]));

        self::assertSame('success', $respuesta['status']);
        self::assertCount(3, $respuesta['data']['variants'], 'Solo las de esta carta');

        foreach ($respuesta['data']['variants'] as $variante) {
            foreach (['finish', 'language', 'condition_grade', 'quantity', 'free', 'priceEur'] as $clave) {
                self::assertArrayHasKey($clave, $variante, "El contrato exige la clave {$clave}.");
            }
        }

        $porClave = [];

        foreach ($respuesta['data']['variants'] as $variante) {
            $porClave[$variante['finish'] . '|' . $variante['condition_grade']] = $variante;
        }

        // La normal NM: tiene 2 y el mazo construido ya reclama 1, queda 1 libre.
        self::assertSame(2, $porClave['normal|NM']['quantity']);
        self::assertSame(1, $porClave['normal|NM']['free']);

        // La foil, entera libre y con su precio.
        self::assertSame(1, $porClave['foil|NM']['free']);
        self::assertSame(12.5, $porClave['foil|NM']['priceEur']);

        // La normal LP no cotiza: NULL, para poder decir «sin precio» y no «0 €».
        self::assertNull($porClave['normal|LP']['priceEur']);
    }

    /**
     * La lista de deseos **no es colección**: ofrecerla como versión a la que
     * cambiar haría que el mazo pidiera una carta que no está en ninguna caja.
     */
    public function testDeckCardVariantsNoOfreceLoQueEstaEnLaWishlist(): void
    {
        $mazo  = $this->crearMazo();
        $carta = $this->anadirCarta($mazo, ['printing_uuid' => 'uuid-sol', 'count' => 1]);

        $this->anadirALaColeccion([
            'printing_uuid' => 'uuid-sol',
            'quantity'      => 3,
            'finish'        => 'foil',
            'is_wishlist'   => true,
        ]);

        $respuesta = $this->controller->cardVariants($this->peticion('deck_card_variants', [
            'deck_id' => $mazo,
            'card_id' => $carta,
        ]));

        self::assertSame([], $respuesta['data']['variants']);
    }

    /**
     * Una línea ajena devuelve 404 y **no** una lista vacía: «no tienes ninguna
     * versión» es una respuesta muy distinta de «ese mazo no es tuyo».
     */
    public function testDeckCardVariantsDeUnaLineaAjenaDevuelve404(): void
    {
        $mazo  = $this->crearMazo();
        $carta = $this->anadirCarta($mazo, ['printing_uuid' => 'uuid-sol', 'count' => 1]);

        $respuesta = $this->controller->cardVariants($this->peticion('deck_card_variants', [
            'deck_id' => $mazo,
            'card_id' => $carta,
        ], self::OTRO));

        self::assertSame(404, $respuesta['http_code']);
    }

    // ========================================================================
    // 11. deck_share    {deck_id} → {shareToken, url}
    // 12. deck_unshare  {deck_id} → {shareToken: null}
    // ========================================================================
    // Las dos únicas acciones de mazos que abren algo hacia fuera. Lo que sirve
    // el enlace es `PublicHttpRouter`, sin sesión: aquí solo se decide SI se
    // comparte, y ese «si» es lo que protege el `user_id` del request.

    public function testDeckShareDevuelveUnTokenDeTreintaYDosBytesYSuUrl(): void
    {
        $mazo = $this->crearMazo();

        $respuesta = $this->controller->share($this->peticion('deck_share', ['deck_id' => $mazo]));

        self::assertSame('success', $respuesta['status']);
        self::assertSame(200, $respuesta['http_code']);
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $respuesta['data']['shareToken']);
        self::assertSame(
            '/#/shared/deck/' . $respuesta['data']['shareToken'],
            $respuesta['data']['url'],
            'La URL es relativa: el backend no sabe en qué origen vive el frontend'
        );
    }

    /**
     * Regenerar es la operación pedida, no un error: el token viejo deja de
     * resolver en el acto porque `share_token` es **una columna**, no una tabla
     * de enlaces.
     */
    public function testDeckShareDosVecesRegeneraEInvalidaElAnterior(): void
    {
        $mazo = $this->crearMazo();

        $viejo = $this->controller->share($this->peticion('deck_share', ['deck_id' => $mazo]))['data']['shareToken'];
        $nuevo = $this->controller->share($this->peticion('deck_share', ['deck_id' => $mazo]))['data']['shareToken'];

        self::assertNotSame($viejo, $nuevo);
        self::assertNull($this->repo->findByShareToken($viejo));
        self::assertSame(
            ['userId' => self::USUARIO, 'deckId' => $mazo],
            $this->repo->findByShareToken($nuevo)
        );
    }

    public function testDeckUnshareMataElEnlaceYEsIdempotente(): void
    {
        $mazo  = $this->crearMazo();
        $token = $this->controller->share($this->peticion('deck_share', ['deck_id' => $mazo]))['data']['shareToken'];

        $respuesta = $this->controller->unshare($this->peticion('deck_unshare', ['deck_id' => $mazo]));

        self::assertSame('success', $respuesta['status']);
        self::assertNull($respuesta['data']['shareToken']);
        self::assertNull($this->repo->findByShareToken($token));

        // Revocar lo que ya no estaba compartido responde que sí: el mazo existe
        // y es tuyo, así que un 404 sería mentira — y el botón del M6 no puede
        // saber si otra pestaña se adelantó.
        $segunda = $this->controller->unshare($this->peticion('deck_unshare', ['deck_id' => $mazo]));

        self::assertSame(200, $segunda['http_code']);
    }

    /**
     * El `deck_id` de otro es **404 y no 403**, como en el resto de mazos: decir
     * «existe pero no es tuyo» confirmaría al que va probando números que ahí
     * hay un mazo. Y aquí ese número no leería un mazo ajeno: lo abriría a
     * internet.
     */
    public function testNoSePuedeCompartirNiRevocarElMazoDeOtro(): void
    {
        $mazo = $this->crearMazo();

        $compartir = $this->controller->share($this->peticion('deck_share', ['deck_id' => $mazo], self::OTRO));
        $revocar   = $this->controller->unshare($this->peticion('deck_unshare', ['deck_id' => $mazo], self::OTRO));

        self::assertSame(404, $compartir['http_code']);
        self::assertSame(404, $revocar['http_code']);
        self::assertNull($this->repo->mazos[$mazo]['share_token'] ?? null, 'No se ha escrito nada');
    }

    // ========================================================================
    // Lo que sostiene a los doce
    // ========================================================================

    /**
     * **El assert que más importa del hito.** `mtg_deck.id` es un
     * autoincremental global: si el `user_id` saliera del payload, cambiar un
     * número bastaría para leer el mazo de otro. El del cuerpo se ignora
     * SIEMPRE; manda el que puso `AuthMiddleware`.
     */
    public function testElUserIdSaleDelRequestYNuncaDelPayload(): void
    {
        $mazo = $this->crearMazo(['name' => 'Atraxa']);

        // El atacante es OTRO y dice ser USUARIO en el cuerpo de la petición.
        $peticion = $this->peticion('deck_get', [
            'deck_id' => $mazo,
            'user_id' => self::USUARIO,
        ], self::OTRO);

        self::assertSame(404, $this->controller->get($peticion)['http_code']);

        // Y al revés: el mazo se crea a nombre del request, no del payload.
        $this->controller->create($this->peticion('deck_create', [
            'name'    => 'De otro',
            'user_id' => self::USUARIO,
        ], self::OTRO));

        $delOtro = $this->controller->list($this->peticion('deck_list', [], self::OTRO))['data']['decks'];

        self::assertCount(1, $delOtro);
        self::assertSame('De otro', $delOtro[0]['name']);
    }

    /**
     * Falta el `deck_id`: 422 del use case, no un 500. La ruta ya lo exige con
     * `ValidationMiddleware`, pero el controller no puede confiar en que la
     * declaración esté puesta.
     */
    public function testSinDeckIdElUseCaseRespondeCon422(): void
    {
        self::assertSame(422, $this->controller->get($this->peticion('deck_get'))['http_code']);
        self::assertSame(422, $this->controller->delete($this->peticion('deck_delete'))['http_code']);
        self::assertSame(422, $this->controller->share($this->peticion('deck_share'))['http_code']);
        self::assertSame(422, $this->controller->unshare($this->peticion('deck_unshare'))['http_code']);
    }
}
