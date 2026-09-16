<?php

declare(strict_types=1);

namespace App\Domain\Repository;

/**
 * Escritura del catálogo MTG.
 *
 * Todos los métodos son **upserts idempotentes**: ejecutar la ingesta dos veces
 * seguidas deja la base de datos igual. Es lo que permite relanzarla cuando sale
 * un set nuevo sin borrar nada, y es el criterio de aceptación de `catalog:import`.
 *
 * Trabajan por lotes porque son ~740.000 filas: una sentencia por fila multiplica
 * por mil los viajes de ida y vuelta a MySQL.
 */
interface CatalogRepositoryInterface
{
    /** @param array<string, mixed> $set Fila de mtg_set */
    public function upsertSet(array $set): void;

    /**
     * @param  list<array<string, mixed>> $filas Filas de mtg_card
     * @return int Filas enviadas
     */
    public function upsertCards(array $filas): int;

    /**
     * @param  list<array<string, mixed>> $filas Filas de mtg_printing
     * @return int Filas enviadas
     */
    public function upsertPrintings(array $filas): int;

    /**
     * @param  list<array<string, mixed>> $filas Filas de mtg_printing_localized
     * @return int Filas enviadas
     */
    public function upsertLocalized(array $filas): int;

    /**
     * @param  list<array<string, mixed>> $filas Filas de mtg_legality
     * @return int Filas enviadas
     */
    public function upsertLegalities(array $filas): int;

    /**
     * Backfill de `mtg_printing_localized.scryfall_id`. **Solo actualiza: nunca
     * crea filas.**
     *
     * Es lo que separa a este método de `upsertLocalized()`, que es un
     * `INSERT ... ON DUPLICATE KEY UPDATE` y que insertaría una fila a medias
     * —sin `name_normalized`— por cada traducción que MTGJSON publique y que
     * nuestro catálogo todavía no tenga ingerida. Esa fila no daría ningún
     * error: dejaría a `catalog:normalize` devolviendo 1 sin que nadie entendiera
     * por qué.
     *
     * @param  list<array{printingUuid: string, language: string, scryfallId: string}> $filas
     * @return int Filas enviadas
     */
    public function escribirIdsLocalizados(array $filas): int;

    /**
     * De esas claves, **cuántas existen en la tabla y siguen con `scryfall_id` a
     * NULL**. Cero es el estado sano y es el código de salida del backfill.
     *
     * Se pregunta por un lote concreto y no por la tabla entera a propósito: un
     * `COUNT(*) WHERE scryfall_id IS NULL` global cuenta también las traducciones
     * para las que **MTGJSON no publica id**, que son NULL legítimos y lo van a
     * ser siempre. Lo que hay que detectar es otra cosa: una fila que la fuente
     * SÍ trae con id y que no llegó a escribirse.
     *
     * @param  list<array{printingUuid: string, language: string}> $claves
     * @return int Filas que deberían tener id y no lo tienen
     */
    public function contarLocalizadosSinId(array $claves): int;

    /**
     * Reconstruye `mtg_format` con los formatos que hay en `mtg_legality`.
     *
     * Es **el único sitio** donde se escribe esa tabla, y el `SELECT DISTINCT`
     * es el único origen de su contenido: ninguna lista de formatos se teclea a
     * mano en este repositorio, porque cada vez que MTGJSON publica un formato
     * nuevo la lista escrita se queda mintiendo en silencio.
     *
     * **Se llama al FINAL de la ingesta, jamás al principio.** `mtg_legality`
     * se llena durante `catalog:import`; preguntarle al empezar devolvería los
     * formatos de la ingesta anterior, o ninguno en una instalación nueva.
     *
     * El `DISTINCT` sigue costando sus 66 ms. Lo que cambia es cuántas veces se
     * paga: una por ingesta, en vez de una por cada carga de la ficha de un mazo
     * (ver `DeckRepositoryInterface::formatoConocido()`).
     *
     * @return int Formatos que quedan en `mtg_format`
     */
    public function refrescarFormatos(): int;

    /**
     * Conteo por tabla del catálogo, para comprobar la idempotencia.
     *
     * @return array<string, int> nombre de tabla → filas
     */
    public function contadores(): array;
}
