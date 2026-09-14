<?php

declare(strict_types=1);

namespace App\Application\UseCase;

use App\Domain\Catalog\PreconFormat;
use App\Domain\Collection\CardLanguage;
use App\Domain\Collection\CollectionItem;
use App\Domain\Collection\Condition;
use App\Domain\Collection\Finish;
use App\Domain\Deck\Board;
use App\Domain\Deck\Deck;
use App\Domain\Deck\DeckCard;
use App\Domain\Deck\DeckStatus;
use App\Domain\Repository\CollectionRepositoryInterface;
use App\Domain\Repository\DeckRepositoryInterface;
use App\Domain\Repository\PreconRepositoryInterface;
use App\Domain\Repository\TransactionManagerInterface;
use InvalidArgumentException;

/**
 * El botón de un clic: acabas de comprar la caja, y con pulsar una vez la tienes
 * **en la colección y montada como mazo**.
 *
 * Es hermano de `ApplyImport` y hace lo mismo por los mismos motivos —escribe
 * `mtg_collection_item` por el puerto de colección y crea el mazo por el de
 * mazos, las dos cosas **en una sola transacción**—, con una diferencia de
 * origen: aquí las filas no las manda el cliente, las pone MTGJSON. El cliente
 * solo dice **qué caja** (`file_name`, la clave natural) y, si acaso, en qué
 * estado nace el mazo.
 *
 * ## Las cinco decisiones de este caso de uso
 *
 * 1. **El mazo nace `built`.** Acabas de comprar la caja y la tienes montada, así
 *    que consume colección desde el primer momento (`DeckStatus::consumeColeccion()`).
 *    Es el único sitio de la app donde el defecto NO es `building`: allí un mazo
 *    recién creado está vacío, y aquí nace con sus 100 cartas dentro. Quien
 *    compre la caja para desmontarla manda `deck_status`.
 *
 *    **Salvo si la caja va a la lista de deseos**, y entonces el defecto es
 *    `building`. No es un capricho de la UI sino la única combinación coherente:
 *    `built` **consume** colección cruzando por las cinco dimensiones con
 *    `is_wishlist = 0` (`MySqlDeckRepository.php:194`), así que un mazo `built`
 *    cuyas 100 cartas solo se **desean** diría estar construido con cartas que no
 *    existen y dispararía conflictos de sobreasignación falsos. `building` es el
 *    estado que calcula lo que **falta** sin consumir: abres el mazo y te dice
 *    «te faltan 100», que es exactamente la verdad. El defecto vive aquí y no en
 *    el cliente porque es un invariante del dato, no una preferencia de pantalla;
 *    un `deck_status` explícito sigue mandando sobre él.
 *
 * 2. **El `format` se deduce solo cuando es obvio** —`Commander Deck` →
 *    `commander`— y **NULL cuando no**. La tabla y el porqué están en
 *    `PreconFormat`; inventar un formato hace que la app avise de ilegalidades
 *    que no existen.
 *
 * 3. **`language = 'English'` y `condition_grade = 'NM'` son una ASUNCIÓN, no un
 *    dato.** MTGJSON no publica ni idioma ni condición de un precon: publica
 *    `uuid`, `count`, `isFoil` y `isEtched`. Es el mismo defecto que el botón
 *    «Añadir» de la ficha de catálogo, y **la UI tiene que decirlo en voz alta**
 *    —quien compre la caja en japonés tendrá que corregirlo, y no puede
 *    enterarse por sorpresa—. El `finish` sí es dato: viene de `isFoil`/`isEtched`
 *    y lo mapeó `MtgJsonDeckMapper::finish()` en la ingesta, con `etched`
 *    separado de `foil` porque tiene precio propio.
 *
 * 4. **Los `tokens` van al mazo pero NO a la colección.** La caja los trae y el
 *    mazo puede listarlos; tu inventario no, porque una ficha se genera, no se
 *    compra. Es `Board::esPoseible()`, la misma regla que deja fuera del valor y
 *    del tamaño mínimo a los cinco precons que solo traen fichas.
 *
 * 5. **Las cartas huérfanas se SALTAN y se cuentan.** Hay 254 filas de
 *    `mtg_precon_card` cuyo `printing_uuid` todavía no está en `mtg_printing`
 *    —MTGJSON publica las cajas antes de que nadie reimporte `AllPrintings`—, y
 *    tanto `mtg_collection_item` como `mtg_deck_card` tienen **FK a
 *    `mtg_printing`**: meterlas tiraría la transacción entera y el clic no
 *    escribiría nada. Se quedan fuera, se devuelven contadas en
 *    `unknownPrintings` y el controller lo dice en el mensaje. Lo que NO se hace
 *    es importar a medias en silencio: es el riesgo #11 del Roadmap.
 *
 * 6. **La caja entera puede ir a la lista de deseos.** Es `is_wishlist` en el
 *    payload, la misma bandera de lote que acepta `import_apply`
 *    (`ApplyImport.php:111`), y lo único que cambia son las líneas de colección:
 *    nacen con `is_wishlist = 1`. No hay acción nueva porque no hay operación
 *    nueva —es esta misma, con las cartas cayendo en el otro conjunto—, y el
 *    `UNIQUE KEY` lleva `is_wishlist` dentro, así que desear la caja y comprarla
 *    después son **dos filas distintas** que no se pisan: querer un Sol Ring no
 *    es tenerlo.
 *
 * ## Comprar dos veces suma, y deja dos mazos
 *
 * La colección va por `upsertLote()`, el mismo `INSERT ... ON DUPLICATE KEY
 * UPDATE quantity = quantity + VALUES(quantity)` sobre el `UNIQUE KEY` de seis
 * columnas que usa el botón «Añadir»: **dos cajas iguales son cantidades
 * sumadas, nunca filas duplicadas**. El mazo, en cambio, es uno por clic —igual
 * que en `ApplyImport`—: quien compra dos Sneak Attack tiene dos cajas en la
 * estantería, y fundirlas en un solo mazo sería inventarse lo contrario.
 */
class ImportPreconToCollection
{
    /**
     * El `deck_id` con el que se validan las líneas antes de que el mazo exista.
     *
     * El de verdad lo pone el `AUTO_INCREMENT` dentro de la transacción y
     * `addCards()` lo escribe en todas las líneas del lote, igual que en
     * `ApplyImport`.
     */
    private const MAZO_SIN_ID = 0;

    public function __construct(
        private readonly PreconRepositoryInterface $precons,
        private readonly CollectionRepositoryInterface $coleccion,
        private readonly DeckRepositoryInterface $mazos,
        private readonly TransactionManagerInterface $transacciones
    ) {
    }

    /**
     * @param  array<string, mixed> $peticion Payload: `file_name`, `deck_status?` e `is_wishlist?`
     * @return array{precon: array<string, mixed>, deck: array<string, mixed>|null,
     *               deckCards: int, inserted: int, updated: int, totalQuantity: int,
     *               unknownPrintings: int, tokenLines: int, isWishlist: bool,
     *               assumed: array<string, string>}|null
     *         null si ese `file_name` no existe — el 404 de la acción
     * @throws InvalidArgumentException si el payload no es del dominio o la caja
     *                                  no tiene ni una carta importable
     */
    public function __invoke(int $userId, array $peticion): ?array
    {
        $fichero = isset($peticion['file_name']) && is_scalar($peticion['file_name'])
            ? trim((string) $peticion['file_name'])
            : '';

        if ($fichero === '') {
            throw new InvalidArgumentException('Falta el identificador (file_name) del precon.');
        }

        $precon = $this->precons->find($fichero);

        if ($precon === null) {
            return null;
        }

        // La bandera del lote, leída como en `ApplyImport`: ausente es `false`,
        // y `filter_var` acepta el `true` del JSON y el "1" de un formulario.
        $aDeseos = filter_var($peticion['is_wishlist'] ?? false, FILTER_VALIDATE_BOOL);

        // Ausente = `built`, o `building` si la caja va a deseos; presente pero
        // inválido = error. Lo dice `DeckStatus::desde()`, que revienta en vez de
        // caer en el defecto: un estado inventado dejaría el mazo sin consumir
        // colección y el aviso de sobreasignación no saltaría jamás.
        $mazo = new Deck(
            userId: $userId,
            name:   (string) $precon['name'],
            status: isset($peticion['deck_status'])
                ? DeckStatus::desde($peticion['deck_status'])
                : ($aDeseos ? DeckStatus::Building : DeckStatus::Built),
            format: PreconFormat::deDeckType(isset($precon['deckType']) ? (string) $precon['deckType'] : null),
        );

        $lineas = $this->precons->cartas($fichero);

        $items        = [];
        $cartas       = [];
        $desconocidas = 0;
        $fichas       = 0;

        foreach ($lineas as $linea) {
            // Sin printing no hay FK que valga: ni mazo ni colección. Se cuenta
            // y se dice, nunca se cuela a medias.
            if (($linea['known'] ?? true) === false) {
                $desconocidas++;

                continue;
            }

            // El mapeo de zona es EXPLÍCITO a propósito: `mtg_precon_card.board`
            // no tiene `companion` y `mtg_deck_card.board` sí, así que los dos
            // ENUM no son el mismo aunque se parezcan. `Board::desde()` es quien
            // sabe traducir, y revienta con una zona que no exista en vez de
            // caer en `main`.
            $zona     = Board::desde($linea['board']);
            $cantidad = (int) $linea['count'];
            $acabado  = Finish::desde($linea['finish']);

            $cartas[] = new DeckCard(
                deckId:       self::MAZO_SIN_ID,
                printingUuid: (string) $linea['printingUuid'],
                finish:       $acabado,
                language:     CardLanguage::porDefecto(),
                condition:    Condition::porDefecto(),
                board:        $zona,
                count:        $cantidad,
            );

            // Las fichas llegan hasta el mazo y se paran ahí.
            if (!$zona->esPoseible()) {
                $fichas++;

                continue;
            }

            $items[] = new CollectionItem(
                userId:       $userId,
                printingUuid: (string) $linea['printingUuid'],
                finish:       $acabado,
                language:     CardLanguage::porDefecto(),
                condition:    Condition::porDefecto(),
                quantity:     $cantidad,
                isWishlist:   $aDeseos,
            );
        }

        if ($cartas === []) {
            throw new InvalidArgumentException($this->porQueNoSePuede($lineas, $desconocidas));
        }

        // Las dos escrituras, o ninguna: un mazo sin cartas en la colección, o
        // unas cartas sin mazo, serían peor que no importar nada. `upsertLote()`
        // y `addCards()` respetan la transacción ya abierta.
        $resultado = $this->transacciones->enTransaccion(
            function () use ($userId, $mazo, $items, $cartas): array {
                $escrito = $this->coleccion->upsertLote($items);

                $deckId     = $this->mazos->create($mazo);
                $ejemplares = $this->mazos->addCards($userId, $deckId, $cartas) ?? 0;

                return $escrito + [
                    // Se relee para devolver el mazo con sus contadores ya
                    // calculados (`cards`, `valueEur`), que es lo que la ficha
                    // enseña al volver de `/decks`. Mismo criterio que `CreateDeck`.
                    'deck'      => $this->mazos->findById($userId, $deckId),
                    'deckCards' => $ejemplares,
                ];
            }
        );

        return $resultado + [
            'precon'           => $precon,
            'unknownPrintings' => $desconocidas,
            'tokenLines'       => $fichas,
            // Dónde han caído las cartas, para que el parte del clic no tenga
            // que deducirlo del payload que mandó el cliente.
            'isWishlist'       => $aDeseos,
            // Lo que NO es dato sino asunción, devuelto para que la UI pueda
            // decirlo en voz alta en vez de dejarlo implícito.
            'assumed'          => [
                'language'  => CardLanguage::porDefecto()->value,
                'condition' => Condition::porDefecto()->value,
            ],
        ];
    }

    /**
     * Por qué esta caja no se puede importar, dicho con su número.
     *
     * Son dos casos distintos y merecen dos frases distintas: la caja que
     * todavía no tiene lista ingerida (`decks:import` pendiente) y la que la
     * tiene entera en cartas que el catálogo no conoce (`catalog:import`
     * pendiente). Decir «no se pudo» a secas dejaría al usuario sin saber cuál
     * de los dos comandos le falta.
     *
     * @param list<array<string, mixed>> $lineas
     */
    private function porQueNoSePuede(array $lineas, int $desconocidas): string
    {
        if ($lineas === []) {
            return 'Esa caja está en el catálogo pero todavía no tiene lista de cartas ingerida.';
        }

        return 'Ninguna de las ' . $desconocidas . ' línea(s) de esa caja está todavía en tu catálogo. '
            . 'Se arregla reimportando el catálogo (catalog:import): MTGJSON publica las cajas antes '
            . 'que las cartas de la edición.';
    }
}
