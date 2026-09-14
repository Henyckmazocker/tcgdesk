<?php

declare(strict_types=1);

namespace App\Application\UseCase;

use App\Domain\Collection\CollectionItem;
use App\Domain\Deck\Deck;
use App\Domain\Deck\DeckCard;
use App\Domain\Repository\CollectionRepositoryInterface;
use App\Domain\Repository\DeckRepositoryInterface;
use App\Domain\Repository\TransactionManagerInterface;
use InvalidArgumentException;

/**
 * La mitad de abajo del pipeline: escribe en la colección **lo que el usuario ha
 * confirmado en la previsualización**, y nada más.
 *
 * No vuelve a parsear ni a resolver nada. Recibe filas ya decididas —las que el
 * resolvedor dio por buenas, más las que el usuario arregló a mano eligiendo
 * candidato, menos las que descartó— y las escribe. Esa es la forma del contrato
 * `import_apply` del plan y es lo que hace que el alto obligatorio sea de verdad
 * un alto: entre la previsualización y la escritura no hay ninguna decisión
 * automática más.
 *
 * ## Por qué escribe por `upsertLote` y no fila a fila
 *
 * Por dos motivos distintos, y ninguno es la velocidad. El primero es que va en
 * **una transacción**: el plan pide que un fichero corrupto produzca un error
 * claro y no una importación a medias, así que un `printingUuid` que no está en
 * el catálogo tira el lote entero por clave foránea y la colección se queda como
 * estaba. El segundo es que `upsertLote` ejecuta **la misma sentencia** que
 * `AddToCollection`: el `INSERT ... ON DUPLICATE KEY UPDATE
 * quantity = quantity + VALUES(quantity)` sobre el `UNIQUE KEY` de seis
 * columnas. De ahí sale la promesa central del plan —**reimportar el mismo
 * fichero suma cantidades pero no crea filas nuevas**—, y de ninguna otra parte.
 *
 * ## Esta importación AÑADE, no sincroniza
 *
 * Es una decisión del plan que la UI tiene que decir en voz alta: quien reimporte
 * su ManaBox esperando «sincronizar» va a duplicar sus cantidades, porque sumar
 * es exactamente lo que hace el `ON DUPLICATE KEY UPDATE`. Aquí no se corrige, se
 * anuncia.
 *
 * ## Importar como mazo (M7)
 *
 * Si el payload trae `deck: {name, status?, format?}`, además de escribir la
 * colección **se crea el mazo** con esas mismas filas, repartidas por la zona
 * que cada una traiga (`board`, que `PlainTextParser` saca de las cabeceras
 * `Deck` / `Sideboard` / `Commander`). Las dos escrituras van en **una sola
 * transacción**: una decklist a medias —las cartas en la colección y ningún
 * mazo, o al revés— sería peor que no importar nada.
 *
 * **Cada importación con mazo crea SU mazo**, también al reimportar el mismo
 * texto. El plan no lo especificaba y es lo coherente con la casilla: el usuario
 * le pone un nombre cada vez, así que dos importaciones son dos mazos —«Burn
 * v1» y «Burn v2»— y no una fusión silenciosa en el mazo de antes. Lo que sí
 * sigue sumando sin duplicar filas es **la colección**, que es la promesa del
 * `ON DUPLICATE KEY UPDATE`; y dentro del mazo nuevo, las líneas repetidas de la
 * misma zona también suman en una sola.
 */
class ApplyImport
{
    /**
     * Techo de filas por petición.
     *
     * Un ManaBox de 20.000 líneas cabe entero, que es la escala que el plan
     * declara. El límite existe para que un cliente roto no meta un millón de
     * filas en una transacción dentro de un proceso con `memory_limit` de 128M.
     */
    public const MAXIMO_FILAS = 20000;

    /**
     * El `deck_id` con el que se validan las líneas del mazo antes de que el
     * mazo exista. El de verdad lo pone el `AUTO_INCREMENT` dentro de la
     * transacción, y `addCards()` lo escribe en todas las líneas del lote.
     */
    private const MAZO_SIN_ID = 0;

    public function __construct(
        private readonly CollectionRepositoryInterface $coleccion,
        private readonly DeckRepositoryInterface $mazos,
        private readonly TransactionManagerInterface $transacciones
    ) {
    }

    /**
     * @param  array<string, mixed> $peticion Payload del cliente: `rows` y, si
     *                                        acaso, `is_wishlist` y `deck` para
     *                                        el lote
     * @return array{inserted: int, updated: int, totalQuantity: int,
     *               deck?: array{id: int, name: string, cards: int}}
     * @throws InvalidArgumentException si el payload no es del dominio
     */
    public function __invoke(int $userId, array $peticion): array
    {
        $filas = $peticion['rows'] ?? null;

        if (!is_array($filas) || $filas === []) {
            throw new InvalidArgumentException('No hay ninguna fila que importar.');
        }

        if (count($filas) > self::MAXIMO_FILAS) {
            throw new InvalidArgumentException(
                'Son ' . count($filas) . ' filas y el máximo por importación es ' . self::MAXIMO_FILAS . '.'
            );
        }

        // El mismo sitio donde entra `is_wishlist` para el lote: dos banderas del
        // lote entero, no de una fila.
        $aLaLista = filter_var($peticion['is_wishlist'] ?? false, FILTER_VALIDATE_BOOL);
        $mazo     = $this->mazoDe($userId, $peticion);

        $items = [];
        // Las líneas del mazo se validan AQUÍ, antes de abrir la transacción,
        // pero todavía sin `deck_id`: el mazo no existe hasta dentro de ella.
        $cartas = [];
        $n      = 0;

        foreach ($filas as $fila) {
            $n++;

            if (!is_array($fila)) {
                throw new InvalidArgumentException("La fila {$n} de la importación no es un objeto.");
            }

            try {
                $payload = $this->aPayload($fila, $aLaLista);
                $items[] = CollectionItem::desdePeticion($userId, $payload);

                if ($mazo !== null) {
                    $cartas[] = DeckCard::desdePeticion(self::MAZO_SIN_ID, $this->aLineaDeMazo($payload, $fila));
                }
            } catch (InvalidArgumentException $e) {
                // El número de fila del lote, no el del fichero: el cliente sabe
                // cuál es cuál y el usuario necesita que se le señale una.
                throw new InvalidArgumentException("Fila {$n} de la importación: " . $e->getMessage());
            }
        }

        if ($mazo === null) {
            return $this->coleccion->upsertLote($items);
        }

        // Las dos escrituras, o ninguna. `upsertLote()` respeta la transacción
        // que ya está abierta —mira `inTransaction()`—, así que la colección
        // entra en esta y no en una suya.
        return $this->transacciones->enTransaccion(
            function () use ($userId, $items, $mazo, $cartas): array {
                $resultado = $this->coleccion->upsertLote($items);

                $deckId     = $this->mazos->create($mazo);
                $ejemplares = $this->mazos->addCards($userId, $deckId, $cartas) ?? 0;

                return $resultado + ['deck' => [
                    'id'    => $deckId,
                    'name'  => $mazo->name,
                    'cards' => $ejemplares,
                ]];
            }
        );
    }

    /**
     * El mazo que pide el payload, o null si esta importación no crea ninguno.
     *
     * `deck` **no puede ir en los `required` de `ValidationMiddleware`** —igual
     * que `is_wishlist`—: es opcional, y el middleware trata el 0 y el `false`
     * como ausencia. Lo valida `Deck::desdePeticion()`, que es quien sabe qué es
     * un nombre, un estado y un formato.
     *
     * @param array<string, mixed> $peticion
     */
    private function mazoDe(int $userId, array $peticion): ?Deck
    {
        $mazo = $peticion['deck'] ?? null;

        if (!is_array($mazo) || $mazo === []) {
            return null;
        }

        return Deck::desdePeticion($userId, $mazo);
    }

    /**
     * La misma fila, leída como línea de mazo.
     *
     * Reaprovecha el payload ya normalizado de la colección —impresión, acabado,
     * idioma y estado son los mismos— y añade las dos cosas que solo son del
     * mazo: la **zona** que traiga la fila (el `board` que `PlainTextParser` saca
     * de las cabeceras; ausente = `main`) y el `count`, que en el mazo se llama
     * así y en la colección `quantity`.
     *
     * `is_wishlist` no viaja: un mazo pide cartas, no las desea.
     *
     * @param  array<string, mixed> $payload Lo que ya se escribe en la colección
     * @param  array<string, mixed> $fila    La fila cruda del contrato
     * @return array<string, mixed>
     */
    private function aLineaDeMazo(array $payload, array $fila): array
    {
        $linea = $payload;

        unset($linea['is_wishlist'], $linea['notes']);

        $linea['count'] = $payload['quantity'] ?? 1;
        $linea['board'] = $fila['board'] ?? null;

        // Ausente = la zona por defecto. Presente pero inválida sí es un error:
        // lo decide `Board::desde()`, no este método.
        if ($linea['board'] === null || $linea['board'] === '') {
            unset($linea['board']);
        }

        return $linea;
    }

    /**
     * Del contrato del plan (`printingUuid`, camelCase) al vocabulario de
     * `CollectionItem` (`printing_uuid`, snake_case).
     *
     * Se aceptan las dos grafías a propósito: el contrato de `import_preview`
     * devuelve camelCase y el resto de acciones de colección hablan snake_case,
     * así que exigir una sola obligaría al cliente a traducir entre dos acciones
     * de la misma pantalla. Lo que **no** se acepta es que falte el
     * `printingUuid`: sin impresión no hay nada que escribir, y adivinarla aquí
     * sería saltarse el alto obligatorio.
     *
     * @param  array<string, mixed> $fila
     * @return array<string, mixed>
     */
    private function aPayload(array $fila, bool $aLaLista): array
    {
        $payload = [
            'printing_uuid' => $fila['printingUuid'] ?? $fila['printing_uuid'] ?? '',
            'is_wishlist'   => $aLaLista,
        ];

        foreach (['finish', 'language', 'condition', 'quantity', 'notes'] as $campo) {
            if (isset($fila[$campo]) && $fila[$campo] !== '') {
                $payload[$campo] = $fila[$campo];
            }
        }

        return $payload;
    }
}
