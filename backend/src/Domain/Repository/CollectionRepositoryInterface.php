<?php

declare(strict_types=1);

namespace App\Domain\Repository;

use App\Domain\Collection\CollectionCriteria;
use App\Domain\Collection\CollectionItem;
use App\Domain\Collection\Condition;

/**
 * La colección del usuario: lectura y escritura.
 *
 * A diferencia del catálogo —que se parte en dos interfaces porque solo la CLI
 * escribe—, aquí lee y escribe el mismo actor: el usuario dueño de las filas.
 *
 * **Todos los métodos reciben el `user_id` como primer argumento y lo llevan al
 * `WHERE`.** No es defensa en profundidad decorativa: `id` es un
 * `BIGINT AUTO_INCREMENT` global, así que sin ese filtro cualquiera podría
 * borrar la fila de otro probando números.
 */
interface CollectionRepositoryInterface
{
    /**
     * Añade cantidad a una línea, creándola si no existía.
     *
     * Es un `INSERT ... ON DUPLICATE KEY UPDATE quantity = quantity + VALUES(quantity)`
     * sobre el `UNIQUE KEY` de seis columnas: añadir dos veces la misma carta
     * **suma**, no duplica. De ahí que la importación del plan siguiente pueda
     * relanzarse sin miedo.
     *
     * @return int El `id` de la fila resultante, se haya insertado o sumado
     */
    public function upsert(CollectionItem $item): int;

    /**
     * La importación entera, por **la misma vía** que `upsert()`.
     *
     * No es una optimización: es la garantía que promete el Plan - Importación de
     * Colecciones. Reimportar el mismo fichero **suma cantidades y no crea filas
     * nuevas** porque estas escrituras chocan contra el mismo `UNIQUE KEY` de
     * seis columnas que el botón "Añadir" de la ficha. Escribir la importación
     * con un SQL propio sería una segunda puerta a la tabla, y la promesa
     * duraría hasta que una de las dos cambiara.
     *
     * Va todo en **una transacción**: el plan pide que un fichero corrupto dé un
     * error claro y no una importación a medias, así que un `printing_uuid` que
     * no está en el catálogo tira las 3.412 filas del lote, no deja 1.200
     * dentro.
     *
     * `inserted` cuenta las líneas nuevas y `updated` las que ya existían y han
     * sumado; `totalQuantity` son los **ejemplares escritos**, que es lo que
     * tiene que haber subido el `SUM(quantity)` de la colección.
     *
     * @param  list<CollectionItem> $items
     * @return array{inserted: int, updated: int, totalQuantity: int}
     */
    public function upsertLote(array $items): array;

    /**
     * Fija la cantidad de una línea. **Cero borra la fila.**
     *
     * No es un capricho de API: una fila con `quantity = 0` sigue contando como
     * carta única en los agregados del dashboard, y la colección se llenaría de
     * fantasmas.
     *
     * @return array<string, mixed>|null La línea actualizada; null si se borró
     *                                   o si no existe / no es del usuario
     */
    public function changeQuantity(int $userId, int $itemId, int $quantity): ?array;

    /**
     * Mueve (total o parcialmente) una línea a otra combinación de `uq_item`.
     *
     * De las seis columnas de la clave, `condition_grade` e `is_wishlist` son
     * las dos que el usuario cruza —cambiar de estado, cumplir un deseo—, y
     * cruzarlas **no edita** la fila: la mueve a una combinación que **puede ya
     * existir**. Si el destino está ocupado las dos filas son la misma carta y
     * hay que **fundirlas sumando**; si el origen se agota, hay que **borrarlo**
     * —una fila a `quantity = 0` seguiría contando como carta única en los
     * agregados—. Son dos escrituras que ocurren **en una sola transacción**: si
     * se quedara a medias existiría un instante en el que esas cartas no están
     * en ninguna de las dos filas, y el usuario vería desaparecer ejemplares.
     *
     * Por eso no lo resuelve la UI con `changeQuantity(0)` + `upsert()`: son dos
     * peticiones HTTP y la segunda puede no llegar nunca.
     *
     * `$quantity` a `null` significa **la línea entera**, que es lo que hace
     * `changeGrade()`. Un número menor la **parte en dos**: quieres cuatro Sol
     * Ring y compras uno.
     *
     * **El `id` se conserva cuando se puede** —movimiento total a un destino
     * libre—, porque la vista actualiza esa línea en su sitio sin recargar. En
     * los demás casos el `id` de `item` NO es el que mandó el cliente.
     *
     * @param  array{condition?: Condition, isWishlist?: bool} $destino Solo lo que
     *         cambia; lo que no venga se hereda del origen
     * @param  int|null $quantity Ejemplares a mover; null = la línea entera
     * @return array{item: array<string, mixed>|null, merged: bool, origen: array<string, mixed>|null}|null
     *         null si la línea no existe o no es de este usuario. `merged` dice
     *         si el destino ya estaba ocupado; `origen` es la fila de origen tal
     *         como quedó —o null si se agotó—, que es lo que la vista necesita
     *         para decidir si quita la línea o solo le baja el número.
     * @throws \InvalidArgumentException si `$quantity` no es positiva o supera
     *         los ejemplares que hay en la línea
     */
    public function moveLine(int $userId, int $itemId, array $destino, ?int $quantity = null): ?array;

    /**
     * Cambia el estado físico de una línea, **fundiéndola** si hace falta.
     *
     * Es el caso particular de `moveLine()`: mover la línea **entera** a la
     * misma combinación con otro `condition_grade`. Se conserva porque es el
     * verbo que usa `collection_change_grade` y porque nombra lo que hace, pero
     * la mecánica —transacción, bloqueo, fusión— vive en un solo sitio.
     *
     * @return array{item: array<string, mixed>|null, merged: bool, origen: array<string, mixed>|null}|null
     *         null si la línea no existe o no es de este usuario; `merged` dice
     *         si el destino ya estaba ocupado y las dos filas se han fundido.
     *         `origen` es null salvo en el no-op —pedir el estado que ya tiene—,
     *         porque mover la línea entera deja vacía la combinación de partida
     */
    public function changeGrade(int $userId, int $itemId, Condition $condition): ?array;

    /** @return bool false si la línea no existe o no es de ese usuario */
    public function remove(int $userId, int $itemId): bool;

    /**
     * Una línea con su carta y su precio.
     *
     * @return array<string, mixed>|null
     */
    public function findById(int $userId, int $itemId): ?array;

    /**
     * La consulta central de la app: la colección con carta, edición y precio.
     *
     * @return array{items: list<array<string, mixed>>, nextCursor: string|null}
     */
    public function search(int $userId, CollectionCriteria $criterios): array;

    /**
     * Los `printing_uuid` **distintos** que el usuario tiene en deseos.
     *
     * Es lo que necesita el corazón del catálogo para pintarse relleno, y por
     * eso no devuelve líneas sino impresiones: al usuario le da igual si lo que
     * quiere es el foil en japonés o el normal en inglés, lo que le importa es
     * «esta carta ya la quiero». De ahí el `DISTINCT`: la misma impresión puede
     * estar en varias líneas de deseo —dos acabados, dos idiomas, dos estados—
     * y el corazón es uno solo.
     *
     * **No se reusa `allLines()` para esto.** Aquel trae la fila entera con su
     * `JOIN` a la carta, a la edición y al precio, y el catálogo lo pediría en
     * cada entrada solo para quedarse con una columna.
     *
     * @return list<string>
     */
    public function wishedPrintingUuids(int $userId): array;

    /**
     * **Todas** las líneas del usuario, con su precio unitario, para valorar.
     *
     * Devuelve las filas y no unos totales ya sumados a propósito: el valor
     * total, el desglose por edición, el desglose por rareza y el top 10 salen
     * de **una sola pasada sobre las mismas filas**, así que no pueden
     * contradecirse entre sí, y el cálculo vive en un use case que se puede
     * probar con un doble —que es lo que pide este hito—. El tamaño lo acota la
     * realidad: son las cartas que una persona posee, no el catálogo.
     *
     * Lo que NUNCA se hace es guardar el total en una columna: los precios
     * cambian a diario y un `valor_total` cacheado estaría mal el 100 % de los
     * días.
     *
     * @return list<array<string, mixed>>
     */
    public function allLines(int $userId, bool $isWishlist): array;

    /**
     * Cuánto llevas de cada edición **de la que tienes algo**.
     *
     * Es el numerador y el denominador del porcentaje del plan
     * (`COUNT(DISTINCT printing) / mtg_set.total_set_size`), sin dividir: la
     * división se hace en el use case, que es donde se puede probar qué pasa
     * cuando `total_set_size` es NULL —la columna es nullable— sin montar una
     * base de datos.
     *
     * **Una sola consulta agregada con `GROUP BY`**, nunca una por edición: son
     * 868 ediciones en el catálogo y un `N+1` aquí serían 868 viajes a MySQL
     * para pintar una pantalla.
     *
     * Solo salen las ediciones en las que el usuario tiene al menos una carta:
     * las 868 con un 0 % no son progreso, son ruido. Por eso se devuelve
     * también cuántas hay en el catálogo, que es lo que da contexto a ese
     * recuento.
     *
     * @return array{sets: list<array<string, mixed>>, catalogSets: int}
     */
    public function setProgress(int $userId, bool $isWishlist): array;
}
