<?php

declare(strict_types=1);

namespace App\Domain\Repository;

use App\Domain\Catalog\PreconSearchCriteria;

/**
 * El catálogo de mazos preconstruidos (`mtg_precon`, `mtg_precon_card`).
 *
 * Puerto aparte de `CatalogRepositoryInterface` y de `DeckRepositoryInterface`,
 * aunque los tres escriban tablas `mtg_*`:
 *
 * - **No es el catálogo**: los precons se ingieren de otros dos ficheros de
 *   MTGJSON (`DeckList.json` y `AllDeckFiles.tar.gz`) y se reingieren cuando sale
 *   una caja, no cuando sale un set.
 * - **No son mazos de usuario**: `mtg_deck` es zona 3, dato irrecuperable que se
 *   respalda; esto es zona 1, catálogo reconstruible relanzando un comando. Ni
 *   siquiera llevan `user_id`, y por eso ningún método lo recibe.
 *
 * La **escritura** son todo **upserts idempotentes y por lotes**: relanzar
 * `decks:import` no mueve ningún contador, y 112.577 filas de carta son unos
 * cuantos `INSERT` multi-valor, no 112.577 consultas.
 *
 * La **lectura** son los dos últimos métodos, y los sirven las dos rutas `GET`
 * de `CatalogHttpRouter`. Viven aquí y no en un puerto nuevo porque leen
 * exactamente las dos tablas que escriben los de arriba; un segundo puerto sobre
 * las mismas tablas solo garantiza que los dos se desincronicen. Se paginan
 * **por cursor, nunca por offset**, como el resto del catálogo.
 */
interface PreconRepositoryInterface
{
    /**
     * Fase 1: el índice de `DeckList.json`.
     *
     * **No escribe `card_count`**, y eso es deliberado: si lo mandara, cada
     * ejecución dejaría los 3.029 mazos a 0 hasta que la fase 2 los recorriese, y
     * un `decks:import` interrumpido a la mitad dejaría el catálogo diciendo que
     * los mazos están vacíos. La columna la mueve `actualizarCardCount()`.
     *
     * @param  list<array<string, mixed>> $filas Filas de mtg_precon
     * @return int Filas enviadas
     */
    public function upsertPrecons(array $filas): int;

    /**
     * Fase 2: las cartas de un mazo.
     *
     * La clave es la PK de cuatro columnas `(precon_file, printing_uuid, board,
     * finish)`. El llamante tiene que entregarlas ya deduplicadas por esa clave:
     * una repetición dentro del mismo lote aborta la sentencia entera.
     *
     * @param  list<array<string, mixed>> $filas Filas de mtg_precon_card
     * @return int Filas enviadas
     */
    public function upsertCartas(array $filas): int;

    /**
     * Pone `mtg_precon.card_count` al número de ejemplares de cada mazo.
     *
     * @param  array<string, int> $conteos file_name → ejemplares
     * @return int Filas enviadas
     */
    public function actualizarCardCount(array $conteos): int;

    /**
     * Conteo por tabla, para comprobar la idempotencia.
     *
     * @return array<string, int> nombre de tabla → filas
     */
    public function contadores(): array;

    /**
     * La lista paginada de precons, filtrada y **por cursor**.
     *
     * El orden es siempre `name, file_name` y no la relevancia del `MATCH`: el
     * desempate por `file_name` —que es la PK— es lo que da un orden TOTAL, y sin
     * él dos cajas del mismo nombre en ediciones distintas se repetirían o se
     * saltarían entre páginas. La relevancia del texto no se puede usar de
     * cursor de columna por lo mismo que en `SearchCriteria`: es una expresión
     * calculada, no una columna indexable.
     *
     * @return array{items: list<array<string, mixed>>, nextCursor: string|null}
     */
    public function buscar(PreconSearchCriteria $criterios): array;

    /**
     * Los valores que el filtro de la vista puede ofrecer: tipos y ediciones.
     *
     * **Salen de un `GROUP BY` sobre `mtg_precon`, nunca de una lista escrita a
     * mano.** Son 48 tipos hoy y MTGJSON añadirá más en cuanto invente otro
     * producto; una constante copiada en el frontend se desincronizaría sola y
     * dejaría cajas inalcanzables por filtro. Mismo motivo por el que las
     * stopwords se leen de `information_schema` y no se copian.
     *
     * Las ediciones son **las 295 que tienen precon**, no las 868 del catálogo:
     * un desplegable en el que 573 opciones devuelven cero resultados es un
     * desplegable que miente.
     *
     * Los conteos son **globales, sin aplicar los filtros en curso**: son las
     * opciones que existen, no las que quedan. Si menguaran con cada filtro, el
     * tipo por el que acabas de filtrar sería el único del desplegable y no
     * habría forma de cambiar de idea.
     *
     * Cada tipo viaja con `playable`, la marca de `PreconPlayability`: es lo que
     * permite que el desplegable separe los mazos de los productos sin copiar
     * ninguna cadena en el frontend.
     *
     * @return array{types: list<array{type: string, count: int, playable: bool}>,
     *               sets: list<array{code: string, name: string|null, count: int}>}
     */
    public function facetas(): array;

    /**
     * La cabecera de un precon por su clave natural, el `fileName`.
     *
     * `fileName` y no `name`: hay precons con el mismo nombre en ediciones
     * distintas y `SneakAttack_ZNC` es único.
     *
     * @return array<string, mixed>|null null si no existe — el 404 de la ruta
     */
    public function find(string $fileName): ?array;

    /**
     * Las cartas de un precon, con su precio vigente.
     *
     * **Todos los `JOIN` del catálogo son `LEFT JOIN`**, y no por elegancia: hay
     * 254 filas cuyo `printing_uuid` no está todavía en `mtg_printing` (ver
     * `huerfanos()`), y con un `JOIN` a secas esas cartas **desaparecerían de la
     * lista en silencio**, que es el peor fallo posible. Salen con los campos de
     * carta a `null` y se marcan.
     *
     * El precio se une por `(printing_uuid, finish)`, nunca solo por printing:
     * unir solo por printing valora los foils a precio de no-foil.
     *
     * @return list<array<string, mixed>>
     */
    public function cartas(string $fileName): array;

    /**
     * Cartas de precon cuyo `printing_uuid` **no está en `mtg_printing`**.
     *
     * No es un error: es un `catalog:import` pendiente, porque precons y catálogo
     * se ingieren por separado y MTGJSON publica los mazos de una edición nueva
     * antes de que nadie haya reimportado `AllPrintings`. Por eso `mtg_precon_card`
     * no tiene FK a `mtg_printing` —la tendría y la ingesta entera fallaría por
     * una carta— y por eso el comando **imprime este número al terminar**: ruidoso,
     * nunca silencioso. Es el riesgo #11 del Roadmap.
     *
     * Se resuelve con UNA consulta agregada, no recorriendo filas.
     *
     * @return array{filas: int, uuids: int} filas huérfanas y uuids distintos
     */
    public function huerfanos(): array;
}
