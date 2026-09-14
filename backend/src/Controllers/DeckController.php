<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Application\UseCase\AddCardToDeck;
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
use InvalidArgumentException;
use PDOException;
use Psr\Log\LoggerInterface;

/**
 * Las doce acciones de los mazos.
 *
 * **Todas son privadas**: llevan `AuthMiddleware` en su pila de `routes.php` y el
 * `user_id` se lee de `$request['user_id']` —donde lo deja ese middleware— y
 * **jamás** del cuerpo de la petición. `mtg_deck.id` es un autoincremental
 * global: si el usuario viniera del payload, bastaría con cambiar un número para
 * leer, editar o borrar el mazo de otro.
 *
 * El controller es fino a propósito, igual que `CollectionController`: saca el
 * usuario, delega en el use case y traduce el resultado a un código HTTP. La
 * lógica de mazos vive en `src/Application/UseCase` y en `MySqlDeckRepository`,
 * que son los que se pueden probar con un doble.
 *
 * Tres traducciones que se repiten y conviene leer una sola vez:
 *
 *  - **`null` del use case = 404.** Los use cases devuelven null cuando el mazo o
 *    la línea no existen *o no son de este usuario*, y las dos cosas se
 *    responden igual: decir «existe pero no es tuyo» confirmaría al que va
 *    probando números que ahí hay un mazo.
 *  - **`InvalidArgumentException` = 422.** Un estado, un acabado o una zona que
 *    no se entienden son un fallo del cliente, no del servidor. Las escrituras
 *    usan `desde()` y no `intentar()` justamente para que revienten aquí en vez
 *    de caer al valor por defecto y guardar una carta distinta de la señalada.
 *  - **`PDOException` de clave foránea = 422.** Solo puede significar que el
 *    `printing_uuid` no está en el catálogo.
 *
 * Las dos últimas —`deck_share` y `deck_unshare`— son las únicas que abren algo
 * hacia fuera, y siguen las mismas tres reglas: el `user_id` del request es lo
 * que impide compartir el mazo de otro, y un mazo que no es tuyo es 404 y no
 * 403. **Lo que se comparte no se sirve desde aquí**: el enlace lo lee
 * `PublicHttpRouter`, sin sesión y con su propia lista blanca de campos.
 */
class DeckController extends BaseController
{
    public function __construct(
        private readonly ListDecks $listar,
        private readonly GetDeck $ver,
        private readonly CreateDeck $crear,
        private readonly UpdateDeck $editar,
        private readonly DeleteDeck $borrar,
        private readonly AddCardToDeck $anadirCarta,
        private readonly ChangeDeckCardCount $fijarCantidad,
        private readonly RemoveCardFromDeck $quitarCarta,
        private readonly ChangeDeckCardIdentity $cambiarVersion,
        private readonly ListDeckCardVariants $variantes,
        private readonly AnalyzeDeckAvailability $analizar,
        private readonly ShareDeck $compartir,
        private readonly UnshareDeck $dejarDeCompartir,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Los mazos del usuario, con lo que le falta a cada uno.
     *
     * `missingCount` sigue viniendo por mazo —el contrato no ha cambiado— pero
     * **ya no lo calcula este método**. Hasta el 2026-09-12 aquí había un
     * `foreach` que llamaba a `AnalyzeDeckAvailability` mazo a mazo, y lo que lo
     * sacó de aquí no fue el `N+1` sino el sitio: decidir qué acompaña a un mazo
     * en la lista es negocio, y el negocio vive en el use case. El `N+1` era el
     * síntoma —`faltantesDeTodos()` lo resuelve en una consulta agregada—, y la
     * regla del repositorio («nunca una consulta por fila») ya lo desmentía.
     *
     * Lo que sí hace este método es lo de siempre: sacar el usuario, delegar y
     * traducir.
     */
    public function list(array $request): array
    {
        $userId = $this->usuario($request);

        try {
            $resultado = ($this->listar)($userId, $request['data'] ?? []);
        } catch (InvalidArgumentException $e) {
            return $this->errorResponse($e->getMessage(), 422);
        }

        return $this->successResponse('Mazos.', $resultado);
    }

    /**
     * Un mazo con sus cartas por zona y el análisis de lo que le falta.
     *
     * El análisis viaja **con el mazo y no en otra petición** porque la vista de
     * `/deck/:id` no puede pintar una línea sin saber si la tienes: enseñar
     * primero la lista y luego los avisos haría que la pantalla cambiara sola
     * delante del usuario. `conflicts` viene entero —no recortado a este mazo—
     * porque un conflicto es siempre entre varios y hay que poder nombrar al
     * otro.
     */
    public function get(array $request): array
    {
        $userId = $this->usuario($request);

        try {
            $mazo = ($this->ver)($userId, $request['data'] ?? []);
        } catch (InvalidArgumentException $e) {
            return $this->errorResponse($e->getMessage(), 422);
        }

        if ($mazo === null) {
            return $this->errorResponse('Ese mazo no existe o no es tuyo.', 404);
        }

        $analisis = ($this->analizar)($userId, $request['data'] ?? []) ?? [];

        return $this->successResponse('Mazo.', $mazo + [
            'availability'    => $analisis['lines'] ?? [],
            'missing'         => $analisis['missing'] ?? 0,
            'missingValueEur' => $analisis['missingValueEur'] ?? 0.0,
            'conflicts'       => $analisis['conflicts'] ?? [],
            'overallocated'   => $analisis['overallocated'] ?? false,
        ]);
    }

    /**
     * Crear un mazo vacío. Solo el nombre es obligatorio.
     */
    public function create(array $request): array
    {
        $userId = $this->usuario($request);

        try {
            $resultado = ($this->crear)($userId, $request['data'] ?? []);
        } catch (InvalidArgumentException $e) {
            return $this->errorResponse($e->getMessage(), 422);
        }

        $this->logger->info('Mazo creado', [
            'user_id' => $userId,
            'deck_id' => $resultado['deck']['id'] ?? null,
        ]);

        return $this->successResponse('Mazo creado.', $resultado);
    }

    /**
     * Editar nombre, estado, formato o notas. **La edición es parcial**: el
     * botón «desmontar» manda solo `status` y no puede borrar lo demás.
     */
    public function update(array $request): array
    {
        $userId = $this->usuario($request);

        try {
            $resultado = ($this->editar)($userId, $request['data'] ?? []);
        } catch (InvalidArgumentException $e) {
            return $this->errorResponse($e->getMessage(), 422);
        }

        if ($resultado === null) {
            return $this->errorResponse('Ese mazo no existe o no es tuyo.', 404);
        }

        return $this->successResponse('Mazo actualizado.', $resultado);
    }

    /**
     * Borrar un mazo, con o sin descontar sus cartas de la colección.
     *
     * `with_cards` es lo único que separa «he deshecho la lista» de «he vendido
     * el mazo entero», así que la respuesta dice siempre cuántos ejemplares se
     * descontaron y qué faltaba (`shortfall`): si la colección tenía menos de lo
     * que decía el mazo se resta hasta 0 y **no se falla**, porque la
     * discrepancia es el caso esperado —vendiste la carta y nunca actualizaste
     * el mazo— y la UI tiene que poder enseñarla.
     */
    public function delete(array $request): array
    {
        $userId = $this->usuario($request);

        try {
            $resultado = ($this->borrar)($userId, $request['data'] ?? []);
        } catch (InvalidArgumentException $e) {
            return $this->errorResponse($e->getMessage(), 422);
        }

        if ($resultado === null) {
            return $this->errorResponse('Ese mazo no existe o no es tuyo.', 404);
        }

        $this->logger->info('Mazo borrado', [
            'user_id'               => $userId,
            'deck_id'               => $request['data']['deck_id'] ?? null,
            'removedFromCollection' => $resultado['removedFromCollection'],
            'shortfall'             => count($resultado['shortfall']),
        ]);

        return $this->successResponse(
            $resultado['removedFromCollection'] > 0
                ? 'Mazo borrado y cartas descontadas de la colección.'
                : 'Mazo borrado.',
            $resultado
        );
    }

    /**
     * Meter una carta en el mazo. Repetir la llamada **suma** a la línea.
     */
    public function cardAdd(array $request): array
    {
        $userId = $this->usuario($request);

        try {
            $resultado = ($this->anadirCarta)($userId, $request['data'] ?? []);
        } catch (InvalidArgumentException $e) {
            return $this->errorResponse($e->getMessage(), 422);
        } catch (PDOException $e) {
            return $this->traducirErrorDeBaseDeDatos(
                $e,
                $userId,
                $this->logger,
                'Carta rechazada: el printing no existe'
            );
        }

        if ($resultado === null) {
            return $this->errorResponse('Ese mazo no existe o no es tuyo.', 404);
        }

        return $this->successResponse('Carta añadida al mazo.', $resultado);
    }

    /**
     * Fijar cuántas copias lleva una línea. **Cero la borra**, y por eso `count`
     * no está en los `required` de la ruta: `ValidationMiddleware` trataría el 0
     * como un campo ausente y lo rechazaría con un 400, cuando es una petición
     * legítima. La valida el use case.
     */
    public function cardSet(array $request): array
    {
        $userId = $this->usuario($request);

        try {
            $resultado = ($this->fijarCantidad)($userId, $request['data'] ?? []);
        } catch (InvalidArgumentException $e) {
            return $this->errorResponse($e->getMessage(), 422);
        } catch (PDOException $e) {
            return $this->traducirErrorDeBaseDeDatos(
                $e,
                $userId,
                $this->logger,
                'Carta rechazada: el printing no existe'
            );
        }

        if ($resultado === null) {
            return $this->errorResponse('Esa carta no está en ese mazo.', 404);
        }

        return $this->successResponse(
            $resultado['removed'] ? 'Carta quitada del mazo.' : 'Cantidad actualizada.',
            $resultado
        );
    }

    /**
     * Sacar una línea entera del mazo. **No toca la colección**: la carta sigue
     * siendo tuya, solo deja de estar en esta lista.
     */
    public function cardRemove(array $request): array
    {
        $userId = $this->usuario($request);

        try {
            $quitada = ($this->quitarCarta)($userId, $request['data'] ?? []);
        } catch (InvalidArgumentException $e) {
            return $this->errorResponse($e->getMessage(), 422);
        }

        if (!$quitada) {
            return $this->errorResponse('Esa carta no está en ese mazo.', 404);
        }

        return $this->successResponse('Carta quitada del mazo.', ['removed' => true]);
    }

    /**
     * Cambiar **qué versión** de la carta pide el mazo. Puede FUNDIR dos líneas.
     *
     * Las cuatro columnas están dentro de `uq_deck_card`, así que el cambio no
     * modifica la fila: la **mueve**, y el destino puede estar ya ocupado por
     * otra línea del mismo mazo. Cuando lo está, las dos se funden sumando
     * `count` y el `id` que devuelve la respuesta **no es el que mandó el
     * cliente**: `merged: true` es lo que avisa a la tabla de que la fila de
     * origen ya no existe.
     */
    public function cardChange(array $request): array
    {
        $userId = $this->usuario($request);

        try {
            $resultado = ($this->cambiarVersion)($userId, $request['data'] ?? []);
        } catch (InvalidArgumentException $e) {
            return $this->errorResponse($e->getMessage(), 422);
        } catch (PDOException $e) {
            return $this->traducirErrorDeBaseDeDatos(
                $e,
                $userId,
                $this->logger,
                'Carta rechazada: el printing no existe'
            );
        }

        if ($resultado === null) {
            return $this->errorResponse('Esa carta no está en ese mazo.', 404);
        }

        if ($resultado['merged']) {
            $this->logger->info('Dos líneas del mazo fundidas al cambiar de versión', [
                'user_id' => $userId,
                'card_id' => $resultado['card']['id'] ?? null,
            ]);
        }

        return $this->successResponse(
            $resultado['merged']
                ? 'Versión cambiada: la línea se ha unido a la que ya tenías.'
                : 'Versión cambiada.',
            $resultado
        );
    }

    /**
     * Las versiones de esa carta que el usuario **tiene de verdad**.
     *
     * Es lo que convierte el cambio de versión en un clic: el desplegable de M5
     * se puebla con esto —`finish`, `language`, `condition_grade`, cuántas tienes
     * y cuántas te quedan libres— y elegir una dispara `deck_card_change`. Nadie
     * teclea cuatro campos.
     */
    public function cardVariants(array $request): array
    {
        $userId = $this->usuario($request);

        try {
            $resultado = ($this->variantes)($userId, $request['data'] ?? []);
        } catch (InvalidArgumentException $e) {
            return $this->errorResponse($e->getMessage(), 422);
        }

        if ($resultado === null) {
            return $this->errorResponse('Esa carta no está en ese mazo.', 404);
        }

        return $this->successResponse('Versiones que tienes de esa carta.', $resultado);
    }

    /**
     * **Compartir el mazo por enlace**, o regenerar el enlace que ya tenía.
     *
     * La respuesta lleva `shareToken` y `url`, y la `url` es **relativa**
     * (`/#/shared/deck/<token>`) porque el backend no sabe en qué origen vive el
     * frontend y lo único que tendría para adivinarlo es la cabecera `Host`, que
     * la manda el cliente. El M6 la compone con `window.location.origin`.
     *
     * **Llamarlo dos veces no es un error: es regenerar**, y el enlace anterior
     * deja de funcionar en el acto. Es la respuesta a «creo que lo he pegado
     * donde no debía».
     *
     * Se registra en el log con el `deck_id` pero **nunca con el token**: un
     * enlace público escrito en `storage/logs` es un enlace público que sobrevive
     * a `deck_unshare`.
     */
    public function share(array $request): array
    {
        $userId = $this->usuario($request);

        try {
            $resultado = ($this->compartir)($userId, $request['data'] ?? []);
        } catch (InvalidArgumentException $e) {
            return $this->errorResponse($e->getMessage(), 422);
        }

        if ($resultado === null) {
            return $this->errorResponse('Ese mazo no existe o no es tuyo.', 404);
        }

        $this->logger->info('Mazo compartido', [
            'user_id' => $userId,
            'deck_id' => $request['data']['deck_id'] ?? null,
        ]);

        return $this->successResponse('Mazo compartido.', $resultado);
    }

    /**
     * **Dejar de compartir**: el token se borra y el enlace muere.
     *
     * Es idempotente —revocar lo que no estaba compartido responde que sí— por
     * lo que explica `UnshareDeck`: el mazo existe y es tuyo, así que un 404
     * sería mentira, y el botón del M6 no puede saber si otra pestaña se
     * adelantó.
     */
    public function unshare(array $request): array
    {
        $userId = $this->usuario($request);

        try {
            $resultado = ($this->dejarDeCompartir)($userId, $request['data'] ?? []);
        } catch (InvalidArgumentException $e) {
            return $this->errorResponse($e->getMessage(), 422);
        }

        if ($resultado === null) {
            return $this->errorResponse('Ese mazo no existe o no es tuyo.', 404);
        }

        $this->logger->info('Mazo dejado de compartir', [
            'user_id' => $userId,
            'deck_id' => $request['data']['deck_id'] ?? null,
        ]);

        return $this->successResponse('El enlace de ese mazo ya no funciona.', $resultado);
    }
}
