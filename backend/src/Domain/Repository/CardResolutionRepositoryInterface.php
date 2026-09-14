<?php

declare(strict_types=1);

namespace App\Domain\Repository;

/**
 * Las cuatro consultas —y solo esas cuatro— que necesita el resolvedor de
 * importación.
 *
 * Es un puerto de **solo lectura** más, como `CardRepositoryInterface`: importar
 * no escribe en el catálogo. Separado de aquel porque lo que pide el resolvedor
 * no se parece a una búsqueda de usuario: no pagina, no ordena por relevancia y
 * lo que le importa de un resultado es **cuántos hay**, no cuál va primero.
 *
 * ## Todo va en lote, y no es una optimización prematura
 *
 * Un CSV de ManaBox real trae miles de líneas. Una consulta por línea son miles
 * de viajes de ida y vuelta a MySQL dentro de una sola petición HTTP, que es un
 * timeout con otro nombre. Por eso los tres primeros pasos reciben la lista
 * entera de claves; solo el paso 4 —al que llegan las pocas líneas que ningún
 * paso exacto resolvió— consulta una a una.
 *
 * ## Forma de un candidato de carta
 *
 * ```
 * ['oracleId' => string, 'name' => string,
 *  'printingUuid' => ?string, 'setCode' => ?string, 'impresiones' => int]
 * ```
 *
 * Una **impresión** (pasos 1 y 2) trae además `collectorNumber` y
 * `nameNormalized`, la clave normalizada de su carta.
 *
 * `printingUuid` y `setCode` vienen rellenos **solo cuando la carta tiene una
 * única impresión**: es el único caso en el que saber la carta implica saber la
 * impresión sin elegir nada por el usuario.
 */
interface CardResolutionRepositoryInterface
{
    /**
     * Paso 1 — la clave universal del ecosistema. `mtg_printing.scryfall_id` es
     * UNIQUE y está poblado en las 110.384 filas, así que aquí no hay ambigüedad
     * posible.
     *
     * @param  list<string> $scryfallIds
     * @return array<string, array<string, mixed>> scryfallId (minúsculas) → impresión
     */
    public function impresionesPorScryfallId(array $scryfallIds): array;

    /**
     * Paso 2 — set + número de coleccionista. Medido sobre el catálogo real:
     * `(set_code, collector_number)` no tiene un solo duplicado en las 110.384
     * impresiones, así que es tan exacto como el paso 1.
     *
     * Exacto **no quiere decir suficiente**: el par lo teclea un humano y un
     * dígito de más apunta a otra carta que también existe. Por eso la impresión
     * devuelta trae `nameNormalized` —la clave de `mtg_card.name_normalized`—,
     * para que el resolvedor pueda contrastarla con el nombre de la línea sin
     * gastar otra consulta y mandar a conflicto lo que se contradice.
     *
     * @param  list<array{setCode: string, collectorNumber: string}> $pares
     * @return array<string, array<string, mixed>> 'SET|numero' → impresión, y solo
     *                                             para los pares que casan con una
     */
    public function impresionesPorSetYNumero(array $pares): array;

    /**
     * Paso 3 — igualdad exacta contra `mtg_card.name_normalized`.
     *
     * Devuelve **todos** los candidatos de cada clave, nunca el primero: 24 claves
     * del catálogo tienen más de una carta detrás (`everythingamajig`,
     * `scavenger hunt`, `sly spy`…) y quedarse con una sería exactamente el fallo
     * silencioso que el plan prohíbe.
     *
     * @param  list<string> $claves
     * @return array<string, list<array<string, mixed>>> clave → candidatos
     */
    public function cartasPorNombreNormalizado(array $claves): array;

    /**
     * Paso 3b — reintento por la CARA FRONTAL, obligatorio y no opcional: las 501
     * de 501 cartas `transform`/`modal_dfc` guardan el nombre completo con ' // '
     * y el usuario teclea solo la mitad izquierda.
     *
     * @param  list<string> $caras Claves ya normalizadas de la cara frontal
     * @return array<string, list<array<string, mixed>>> cara → candidatos
     */
    public function cartasPorCaraFrontal(array $caras): array;

    /**
     * Paso 4 — FULLTEXT en modo booleano, el único paso inexacto.
     *
     * Devuelve la lista tal cual para que sea **el resolvedor** quien aplique la
     * regla de oro: resuelve solo si hay exactamente un resultado. Los sufijos `*`
     * de la expresión hacen que `Lightning` case con *Lightning Bolt* y con
     * *Lightning Strike* a la vez, y ese empate es un conflicto, no una elección.
     *
     * @return list<array<string, mixed>> Candidatos, como mucho $limite
     */
    public function cartasPorTexto(string $texto, int $limite = 25): array;
}
