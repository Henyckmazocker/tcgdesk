<?php

declare(strict_types=1);

namespace App\Domain\Repository;

use App\Domain\Collection\CardLanguage;
use App\Domain\Collection\Condition;
use App\Domain\Collection\Finish;
use App\Domain\Deck\Board;
use App\Domain\Deck\Deck;
use App\Domain\Deck\DeckCard;
use App\Domain\Deck\DeckStatus;

/**
 * Los mazos del usuario: lectura y escritura.
 *
 * **Todos los métodos reciben el `user_id` como primer argumento y lo llevan al
 * `WHERE`**, igual que `CollectionRepositoryInterface`. No es defensa en
 * profundidad decorativa: `mtg_deck.id` es un `BIGINT AUTO_INCREMENT` global,
 * así que sin ese filtro cualquiera podría leer o vaciar el mazo de otro
 * probando números. Y como `mtg_deck_card` **no** tiene `user_id` —cuelga del
 * mazo—, los métodos de carta reciben también el `deck_id` y la propiedad se
 * comprueba por el `JOIN` con `mtg_deck`.
 *
 * Lo que este puerto NO hace: referenciar `mtg_collection_item.id`. El acople
 * mazo ↔ colección **se calcula, no se referencia**, porque ese `id` es
 * inestable —`changeGrade()` funde y borra filas, `changeQuantity(0)` borra— y
 * una FK a él vaciaría el mazo en silencio al cambiar una carta de NM a LP. El
 * porqué está escrito en `ChangeItemGrade.php:14-27`. La única excepción es
 * `deleteConCartas()`, que sí escribe en la colección, y por eso está
 * documentada aparte.
 */
interface DeckRepositoryInterface
{
    /**
     * Crea el mazo y devuelve su `id`.
     *
     * Sin `ON DUPLICATE KEY UPDATE` de ninguna clase: dos mazos con el mismo
     * nombre son dos mazos legítimos —«Atraxa v1» y «Atraxa v1» mientras
     * decides cuál te gusta— y no hay clave única sobre `(user_id, name)`
     * precisamente por eso.
     */
    public function create(Deck $deck): int;

    /**
     * Edición **parcial** del mazo.
     *
     * `$campos` viene ya validado de `Deck::camposDesdePeticion()` y sus claves
     * son nombres de columna de la lista blanca (`name`, `status`, `format`,
     * `notes`): es lo único que puede llegar al `SET`, porque ahí no hay
     * marcador que valga para el nombre de la columna.
     *
     * Parcial y no completa porque el botón «desmontar» de M5 manda solo
     * `status`, y rellenar el resto con defectos borraría el nombre y las notas.
     *
     * @param  array<string, string|null> $campos columna → valor
     * @return array<string, mixed>|null El mazo ya actualizado; null si no
     *                                   existe o no es de este usuario
     */
    public function update(int $userId, int $deckId, array $campos): ?array;

    /**
     * Borra el mazo y, por `ON DELETE CASCADE`, sus cartas.
     *
     * **No toca la colección**: es el `with_cards: false` del contrato. Deshacer
     * un mazo no es vender sus cartas.
     *
     * @return bool false si el mazo no existe o no es de este usuario
     */
    public function delete(int $userId, int $deckId): bool;

    /**
     * Borra el mazo **y descuenta sus cartas de la colección**, en una sola
     * transacción.
     *
     * Es el único método de este puerto que escribe en `mtg_collection_item`, y
     * está aquí y no en el repositorio de colección por una razón concreta: las
     * dos escrituras tienen que compartir transacción. Si el borrado del mazo
     * llegara y el descuento no, el usuario tendría cartas que ya no están en
     * ninguna caja; si fuera al revés, habría perdido cartas de la colección sin
     * que desapareciera nada.
     *
     * Tres reglas que no son obvias:
     *
     *  - **Se cruza por las cinco dimensiones y con `is_wishlist = 0`.** Sin ese
     *    filtro, desmontar un mazo descontaría de la lista de deseos.
     *  - **Si la cantidad llega a 0 se BORRA la fila**, igual que hace
     *    `changeQuantity(0)`: una fila a cero sigue contando como «carta única»
     *    en el dashboard.
     *  - **Si la colección tiene menos de lo que dice el mazo, se resta hasta 0
     *    y no se falla.** La discrepancia es el caso esperado, no el error:
     *    vendiste la carta y nunca actualizaste el mazo. Lo que falta se
     *    devuelve en `shortfall` para que la UI lo enseñe.
     *
     * Las líneas con `board = 'tokens'` **no se descuentan y no aparecen en el
     * `shortfall`**: un token no es una carta que se posea, así que nunca estuvo
     * en la colección de la que restar.
     *
     * @return array{removedFromCollection: int, shortfall: list<array<string, mixed>>}|null
     *         null si el mazo no existe o no es de este usuario;
     *         `removedFromCollection` son los ejemplares realmente descontados
     */
    public function deleteConCartas(int $userId, int $deckId): ?array;

    /**
     * Un mazo con sus contadores: cuántas cartas lleva y cuánto valen.
     *
     * @return array<string, mixed>|null null si no existe o no es de este usuario
     */
    public function findById(int $userId, int $deckId): ?array;

    /**
     * **El enlace del mazo**: escribe, reescribe o borra su `share_token`.
     *
     * Es la única pareja de métodos de este puerto en la que el `user_id` NO
     * está protegiendo la lectura sino **la decisión de publicar**: compartir un
     * mazo es un acto explícito de su dueño, así que el `WHERE` lleva el
     * `user_id` por lo mismo que el resto —`mtg_deck.id` es un autoincremental
     * global— y con más motivo, porque aquí el número ajeno no leería un mazo:
     * lo abriría a internet.
     *
     * Un `$token` a `null` es **dejar de compartir**, y el enlace muere de
     * verdad: la columna vuelve a `NULL` y `findByShareToken()` deja de
     * resolverlo. Escribir un token nuevo sobre uno que ya existía **invalida el
     * anterior** por el mismo camino, sin borrado previo ni fila de historial:
     * la columna es una sola y su valor anterior no se guarda en ninguna parte.
     *
     * @param  string|null $token 64 caracteres hex, o null para revocar
     * @return bool false si el mazo no existe o no es de este usuario
     */
    public function fijarShareToken(int $userId, int $deckId, ?string $token): bool;

    /**
     * **A qué mazo apunta un enlace compartido**, sin saber de quién es.
     *
     * Es el único método de este puerto que **no** recibe `user_id`, y no es un
     * descuido del contrato sino su punto entero: en el mazo compartido **el que
     * autoriza es el token**, no el espectador —que normalmente no tiene ni
     * cuenta—. Lo que hace que eso no sea un agujero es que el token son 32
     * bytes de `random_bytes()` en hexadecimal: no se adivina y no se enumera,
     * al revés que el `deck_id`, que es un autoincremental global.
     *
     * Por eso devuelve el `user_id` del dueño en vez del mazo: con él, todo lo
     * demás se lee por los métodos de siempre, que sí filtran por usuario. Así
     * este puerto sigue teniendo **una sola** puerta sin `user_id`, y es esta.
     *
     * @return array{userId: int, deckId: int}|null null si ese token no está
     *         compartido, tanto si nunca existió como si se revocó: las dos
     *         cosas se responden igual, y con 404
     */
    public function findByShareToken(string $token): ?array;

    /**
     * Los mazos del usuario, con los mismos contadores que `findById()`.
     *
     * El filtro por estado es opcional y va como objeto de valor, no como
     * string: los tres estados **no son simétricos** (solo `built` consume
     * colección) y un `WHERE status != 'dismantled'` escrito por inercia mete
     * los mazos en construcción donde no deben estar.
     *
     * @return list<array<string, mixed>>
     */
    public function allByUser(int $userId, ?DeckStatus $status = null): array;

    /**
     * Las cartas de un mazo, con su carta, su edición y su precio.
     *
     * Con **`LEFT JOIN` a los precios, jamás `JOIN`**: muchísimos printings no
     * cotizan en Cardmarket y un `JOIN` normal los haría desaparecer del mazo,
     * que es el peor fallo posible y además silencioso. Y el precio se une por
     * `(printing_uuid, finish)`, nunca solo por printing: unir solo por printing
     * infló una colección de prueba en 500 € valorando foils a precio de
     * no-foil.
     *
     * @return list<array<string, mixed>>
     */
    public function cards(int $userId, int $deckId): array;

    /**
     * Añade cantidad a una línea del mazo, creándola si no existía.
     *
     * Es un `INSERT ... ON DUPLICATE KEY UPDATE count = count + VALUES(count)`
     * sobre el `UNIQUE KEY` de seis columnas: añadir dos veces la misma carta en
     * la misma zona **suma**, no duplica. Misma propiedad que el `upsert()` de
     * la colección, y la que hará idempotente la importación de decklists de M7.
     *
     * @return int|null El `id` de la línea resultante; null si el mazo no existe
     *                  o no es de este usuario
     */
    public function addCard(int $userId, DeckCard $card): ?int;

    /**
     * Lo mismo, pero para un LOTE de líneas del mismo mazo.
     *
     * Existe por la regla del repositorio —«la importación va en lote, nunca una
     * consulta por fila»—: una decklist de 100 cartas por `addCard()` serían 200
     * consultas, porque cada llamada comprueba además de quién es el mazo. Aquí
     * la propiedad se comprueba **una vez** y luego es la misma sentencia
     * preparada ejecutada N veces, igual que `upsertLote()` en la colección.
     *
     * Suma exactamente igual que `addCard()`: la misma carta repetida en la
     * misma zona deja **una línea** con la suma. Es lo que hace que reimportar
     * una decklist no duplique líneas dentro del mazo.
     *
     * **No abre transacción propia**: quien llama decide el alcance, porque en
     * M7 esto va dentro de la misma transacción que escribe la colección.
     *
     * @param  list<DeckCard> $cards Todas del mismo `deckId`
     * @return int|null Ejemplares escritos; null si el mazo no es de este usuario
     */
    public function addCards(int $userId, int $deckId, array $cards): ?int;

    /**
     * Una línea concreta del mazo.
     *
     * @return array<string, mixed>|null
     */
    public function findCardById(int $userId, int $deckId, int $cardId): ?array;

    /**
     * Fija la cantidad de una línea. **Cero borra la fila.**
     *
     * Mismo criterio que `changeQuantity()` en la colección, y por la misma
     * aritmética: una línea con `count = 0` seguiría contando en «cartas del
     * mazo» y falsearía el tamaño —que es justo lo que M6 mira para avisar del
     * mínimo de 60 o 100—.
     *
     * @return array<string, mixed>|null La línea actualizada; null si se borró,
     *                                   o si no existe / no es de este usuario
     */
    public function changeCardCount(int $userId, int $deckId, int $cardId, int $count): ?array;

    /** @return bool false si la línea no existe o no es de este mazo/usuario */
    public function removeCard(int $userId, int $deckId, int $cardId): bool;

    /**
     * Cambia qué versión de la carta pide el mazo, **fundiendo** si hace falta.
     *
     * No es un `UPDATE` cualquiera, y por el mismo motivo de esquema que
     * `CollectionRepositoryInterface::changeGrade()`: las cuatro columnas
     * (`finish`, `language`, `condition_grade`, `board`) están **dentro** de
     * `uq_deck_card`, así que cambiar una no modifica la fila —**la mueve** a
     * otra combinación de la clave, que puede estar ya ocupada por otra línea
     * del mismo mazo—. Cuando lo está, las dos son la misma carta en el mismo
     * estado y hay que **fundirlas sumando `count`**, y eso son dos escrituras
     * que van en **una sola transacción** con `SELECT ... FOR UPDATE`.
     *
     * Resolverlo desde la interfaz con un `removeCard()` + un `addCard()` se
     * descarta por lo mismo que allí: son dos peticiones HTTP y, si la segunda
     * no llega, la línea desaparece del mazo.
     *
     * **No toca la colección.** Cambiar qué versión pide el mazo no mueve ni una
     * carta de `mtg_collection_item`: solo cambia lo que el mazo reclama, y por
     * tanto lo que el cruce de M3 calcula.
     *
     * Los cuatro cambios son opcionales; los que lleguen a null se quedan como
     * están.
     *
     * @return array{card: array<string, mixed>|null, merged: bool}|null
     *         null si la línea no existe o no es de este mazo/usuario; `merged`
     *         dice si el destino ya estaba ocupado y las dos se han fundido
     */
    public function changeCardIdentity(
        int $userId,
        int $deckId,
        int $cardId,
        ?Finish $finish = null,
        ?CardLanguage $language = null,
        ?Condition $condition = null,
        ?Board $board = null
    ): ?array;

    /**
     * **La sobreasignación**: lo que reclaman TODOS los mazos construidos del
     * usuario, contra lo que hay de verdad en la colección.
     *
     * El acople mazo ↔ colección **se calcula, no se referencia**, y esta es la
     * consulta que lo calcula. Agrega por las cinco dimensiones —dos mazos que
     * piden el mismo Sol Ring `normal/English/NM` piden la misma carta— y compara
     * con la fila equivalente de `mtg_collection_item`, con `is_wishlist = 0`:
     * sin ese filtro, una carta que *quieres* contaría como carta que *tienes* y
     * los mazos dirían que están completos.
     *
     * Tres reglas que no son negociables:
     *
     *  - **Solo `status = 'built'`.** Los tres estados no son simétricos: un mazo
     *    en construcción **no** consume nada (`DeckStatus::consumeColeccion()`).
     *    Escribir `!= 'dismantled'` por inercia mete los `building` en el consumo
     *    y la app avisa de conflictos que no existen.
     *  - **`board = 'tokens'` fuera.** Un token no es una carta que se posea, así
     *    que no puede pelearse con nadie por ella (`Board::esPoseible()`).
     *  - **Solo devuelve los conflictos**, por el `HAVING reclamado > enColeccion`.
     *    Sin conflicto no hay nada que enseñar.
     *
     * **Este método NO escribe.** No hay `UPDATE` sobre `mtg_deck.status`
     * disparado por el resultado, ni lo habrá: la sobreasignación **informa**, y
     * es el usuario quien decide desmontar un mazo. Un cambio de estado
     * automático provocado por una condición calculada es exactamente el tipo de
     * cosa que luego nadie sabe explicar.
     *
     * Cada línea trae además `decks`: **qué mazos la reclaman y cuánto pide cada
     * uno**, que es lo que permite a la UI nombrarlos y ofrecer el botón de
     * desmontar sobre uno concreto.
     *
     * @return list<array<string, mixed>> líneas con `claimed`, `inCollection`,
     *         `free`, `missing`, `priceEur`, `missingValueEur` y `decks`
     */
    public function consumo(int $userId): array;

    /**
     * El «te faltan N» de **un solo mazo**: lo que pide cada línea contra lo que
     * hay en la colección.
     *
     * Es el mismo cruce que `consumo()` —las cinco dimensiones, `is_wishlist = 0`
     * y los tokens fuera— con dos diferencias deliberadas:
     *
     *  - **Filtra por un solo `deck_id` y NO por `status`.** Precisamente lo que
     *    se quiere saber de un mazo en construcción es cuánto le falta, y
     *    `building` nunca pasaría un filtro de `built`. El estado del mazo no
     *    cambia lo que ese mazo pide.
     *  - **Sin `HAVING`: devuelve TODAS las líneas del mazo**, no solo las que
     *    faltan. El contrato pide `claimed`, `inCollection` y `free` por línea, y
     *    el precio de lo que falta **solo cuando falta**; una lista recortada a
     *    los conflictos no podría decir de las demás que están cubiertas.
     *
     * Ojo con el nombre: no lo confundas con las variables locales `$faltantes`
     * de `deleteConCartas()`, que son el `shortfall` de un borrado.
     *
     * `free` se calcula **contra este mazo**, no contra el resto: lo que reclaman
     * los demás mazos construidos es asunto de `consumo()`.
     *
     * @return list<array<string, mixed>> mismas claves que `consumo()`, sin `decks`
     */
    public function faltantes(int $userId, int $deckId): array;

    /**
     * Lo que le falta a CADA mazo del usuario, en una sola consulta.
     *
     * Es la versión en lote de `faltantes()`: mismo cruce por las cinco
     * dimensiones con `is_wishlist = 0` y `board <> 'tokens'`, sin filtrar por
     * `status` —el estado del mazo no cambia lo que ese mazo pide— y agrupado
     * por mazo. Lo que se agrega es el `missing` de cada línea ya recortado a 0,
     * exactamente como lo suma `AnalyzeDeckAvailability`: lo que sobra en una
     * línea **no** tapa lo que falta en otra.
     *
     * Existe por la regla del repositorio —«nunca una consulta por fila»—: la
     * lista de mazos pedía el cruce mazo a mazo y eran N consultas para pintar
     * una pantalla.
     *
     * **Los mazos sin cartas NO aparecen en el resultado** —no tienen ni una
     * línea que cruzar—, y tampoco los que solo llevan tokens. Quien lo consuma
     * **tiene que poner 0 por defecto**, o la lista enseñará un hueco en el mazo
     * recién creado.
     *
     * Este método **no escribe**.
     *
     * @return array<int, int> deckId → ejemplares que faltan
     */
    public function faltantesDeTodos(int $userId): array;

    /**
     * **Las versiones de esta carta que el usuario tiene de verdad**, para que
     * cambiar de versión sea un clic y no un formulario de cuatro campos.
     *
     * Dado un `printing_uuid`, devuelve las combinaciones de
     * `finish + language + condition_grade` que existen en su
     * `mtg_collection_item` con **`is_wishlist = 0`** —una carta que *quieres* no
     * es una carta que *tengas*, y ofrecérsela como versión a la que cambiar
     * haría que el mazo pidiera algo que no está en ninguna caja—, con cuántas
     * tiene de cada una y cuántas le quedan **libres**.
     *
     * `free` se calcula con **el mismo criterio que `consumo()`**: lo que ya
     * reclaman todos sus mazos `built` (los tokens fuera) restado de lo que
     * tiene, recortado a 0. Es la cuenta que hace útil el desplegable: «tengo 3
     * en NM pero las 3 están en otro mazo» y «tengo 1 en LP suelta» son
     * respuestas distintas, y la segunda es la que el usuario quiere elegir.
     *
     * **No devuelve el catálogo entero de esa carta**: solo lo que hay en la
     * colección. Una lista de las 40 impresiones posibles no ayudaría a decidir,
     * y el mazo acabaría pidiendo cartas que no se tienen.
     *
     * Este método **no escribe**.
     *
     * @return list<array<string, mixed>> con `finish`, `language`,
     *         `condition_grade`, `quantity`, `free` y `priceEur` (NULL si la
     *         carta no cotiza, nunca 0)
     */
    public function variantesEnColeccion(int $userId, string $printingUuid): array;

    /**
     * **La legalidad de las cartas del mazo en un formato**, como aviso.
     *
     * Es UN `LEFT JOIN` para todas las cartas del mazo, no una consulta por
     * línea: la legalidad de 100 cartas es una consulta. (Un subselect
     * correlacionado por línea es exactamente lo que hundió la búsqueda a 125
     * segundos, y está escrito en el `CLAUDE.md` del repo.)
     *
     * **`LEFT JOIN`, jamás `JOIN`.** `mtg_legality` solo trae las filas de los
     * formatos donde la carta *tiene* estatus, así que `not_legal` **es la
     * ausencia de fila** y no un valor que la ingesta escriba —`SELECT DISTINCT
     * status` devuelve solo `legal`, `banned` y `restricted`—. Con un `JOIN` a
     * secas las cartas no legales **desaparecerían de la lista** en vez de
     * marcarse, que es el fallo contrario al que este aviso busca. Por eso el
     * valor del array puede ser `null`: es la ausencia, y quien la traduce es
     * `LegalityStatus::desdeFila()`.
     *
     * Se cruza por **`oracle_id`**, no por `printing_uuid`: la legalidad es de la
     * carta, no de la edición —un *Sol Ring* está prohibido en Legacy sea de la
     * edición que sea—, y por eso la clave del array es el `oracle_id`.
     *
     * **Los tokens quedan fuera** (`Board::esPoseible()`): un token no es una
     * carta que se juegue ni que se posea, y marcarlo «no legal» sería ruido.
     *
     * Este método **no escribe** y **no bloquea nada**: un mazo ilegal es un mazo
     * que existe.
     *
     * @param  string $formato Uno de `mtg_legality.format`, en minúsculas
     * @return array<string, string|null> oracle_id → status, o **null si no hay
     *         fila** para ese formato
     */
    public function legalidad(int $userId, int $deckId, string $formato): array;

    /**
     * Si ese formato existe de verdad.
     *
     * Hace falta porque `mtg_deck.format` es texto libre acotado —lo escribe el
     * usuario— y sin esta comprobación un formato mal tecleado (`edh` en vez de
     * `commander`) no daría ninguna fila y **las 100 cartas del mazo saldrían
     * marcadas `not_legal`**: una alarma falsa y completa, imposible de
     * distinguir de un mazo de verdad ilegal. Con ella, un formato que no existe
     * se dice y no se marca nada.
     *
     * **Se pregunta a `mtg_format`, no a `mtg_legality`.** La respuesta es la
     * misma —`mtg_format` no es más que el `SELECT DISTINCT` de la otra, y lo
     * ejecuta `catalog:import` al terminar— pero el coste no: aquí es una
     * lectura de clave primaria sobre 21 filas en vez de un recorrido del índice
     * de `mtg_legality`, que son ~740.000. Antes se sorteaba con un `EXISTS`
     * (3 ms contra los 66 ms del `DISTINCT` completo); el `EXISTS` deja de hacer
     * falta cuando la tabla que se consulta ya viene destilada.
     *
     * @param string $formato Tal cual lo tecleó el usuario, ya normalizado a
     *        minúsculas por quien lo guarda: `mtg_format` va en minúsculas
     */
    public function formatoConocido(string $formato): bool;

    /**
     * **Todos** los formatos que existen, en orden alfabético.
     *
     * Es lo que alimenta el desplegable de la ficha del mazo: la lista sale del
     * `SELECT DISTINCT format FROM mtg_legality` que corrió la ingesta, nunca de
     * una constante escrita a mano, porque MTGJSON añade formatos —`timeless` y
     * `oathbreaker` son de ayer— y una lista escrita se queda corta sin avisar.
     *
     * > **No la «simplifiques» volviendo al `SELECT DISTINCT` directo.** Ese
     * > `DISTINCT` cuesta 66 ms y se pagaría en cada carga de la ficha de un
     * > mazo, que es exactamente la deuda que `mtg_format` vino a saldar.
     *
     * Este método **no escribe**.
     *
     * @return list<string> minúsculas, sin repetidos
     */
    public function formatosConocidos(): array;
}
