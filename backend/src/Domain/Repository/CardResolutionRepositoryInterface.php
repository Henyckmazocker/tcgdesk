<?php

declare(strict_types=1);

namespace App\Domain\Repository;

/**
 * Las cinco consultas —y solo esas cinco— que necesita el resolvedor de
 * importación.
 *
 * Eran cuatro hasta el 2026-09-15, cuando el paso 2b añadió la quinta: el par
 * `(nombre, número)` sin edición, que hasta entonces se tiraba a la basura.
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
 * timeout con otro nombre. Por eso **todos los pasos exactos** reciben la lista
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
     * Paso 2b — nombre + número de coleccionista **sin edición**, el dato que el
     * resolvedor tiraba a la basura hasta el 2026-09-15.
     *
     * `claveDeSet()` devuelve `null` en cuanto falta `setCode`, así que una línea
     * que dice `Thoughtseize / 1117` caía al paso 3, que solo mira el nombre, y se
     * resolvía con edición asumida teniendo información de sobra para acertar la
     * impresión exacta. Lo pagaban `/import` y sobre todo las **Secret Lair**, que
     * no imprimen el código de edición en la esquina.
     *
     * Medido en la BD viva el 2026-09-15 sobre las 110.384 impresiones: el par
     * `(nombre, número)` identifica de forma única el **90,42 %** del catálogo, el
     * **92,70 %** excluyendo tierras básicas y el **98,54 %** de las 2.599 Secret
     * Lair. *Thoughtseize* `1117` y *Alela, Artful Provocateur* `1630` —las dos
     * cartas que obligaron a David a elegir edición a mano— salen únicas.
     *
     * ## Cede el turno, no conflictúa
     *
     * Lo que queda ambiguo son tierras básicas y por goleada: `Island 2` vive en 20
     * ediciones y `Plains 250` en 14. Por eso el contrato es **devolver solo los
     * pares con exactamente una impresión detrás**: un par ambiguo simplemente no
     * sale en el mapa, la fila continúa al paso 3 y se resuelve por nombre con
     * edición asumida **exactamente como hoy**. No hay estado «2b conflictúa», y esa
     * es la regla que impide que este paso rompa nada de lo que ya funcionaba.
     *
     * ## La clave llega YA normalizada, como la del paso 3
     *
     * `name` es `mtg_card.name_normalized`, no el nombre crudo: quien normaliza es
     * el resolvedor con el mismo `NameNormalizer` de siempre, porque las dos partes
     * tienen que medir con la misma vara. La implementación casa además por la
     * **cara frontal** —el mismo prefijo `LIKE 'clave // %'` del paso 3b—, sin lo
     * cual las 932 cartas de doble cara serían 932 fallos: el usuario teclea
     * `Delver of Secrets` y el catálogo guarda `Delver of Secrets // Insectile
     * Aberration`.
     *
     * @param  list<array{name: string, collectorNumber: string}> $pares `name` es la
     *                                                                  clave normalizada
     * @return array<string, array<string, mixed>> 'clave|numero' → impresión, y solo
     *                                             para los pares con UNA detrás
     */
    public function impresionesPorNombreYNumero(array $pares): array;

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
     * Paso 3c — igualdad exacta contra `mtg_printing_localized.name_normalized`:
     * **el mismo paso 3, pero en los otros nueve idiomas**.
     *
     * Existe porque el resolvedor era monolingüe: las 410.604 filas de nombres
     * traducidos no las consultaba nadie, así que fotografiar una Llanura —o
     * importar un CSV en español— devolvía `not_found` sin más explicación.
     *
     * Devuelve **el mismo contrato que el paso 3** —todos los candidatos de cada
     * clave, nunca el primero— y con el mismo significado en `impresiones`: las
     * de la carta entera, no las que estén traducidas a ese idioma. Quien lo
     * implemente por el camino corto contará las filas localizadas y dirá que una
     * carta con 200 impresiones tiene una sola; eso es lo que el escáner
     * convierte en «impresión cierta», y escribiría en la colección una edición
     * inventada.
     *
     * ## Cada candidato trae además `language`, y puede ser null
     *
     * Es la señal del M8: el idioma al que apunta **la clave**, o `null` si
     * apunta a varios. Va en el candidato y no en un método aparte porque el
     * dato ya está en la fila que esta consulta lee, y preguntarlo por separado
     * sería una consulta por lectura en el camino del escáner.
     *
     * **Una clave ambigua no se resuelve por mayoría**: `null` y que el cliente
     * caiga en su ajuste. Ese idioma entra en el `UNIQUE KEY` de la línea de
     * colección, así que adivinarlo mal la parte en dos en silencio.
     *
     * @param  list<string> $claves Claves ya normalizadas, en cualquier idioma
     * @return array<string, list<array<string, mixed>>> clave → candidatos
     */
    public function cartasPorNombreLocalizado(array $claves): array;

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

    /**
     * Paso 5 — el último recurso: candidatos a **distancia de edición** `<= $maxima`
     * del nombre, ya acotados por longitud.
     *
     * Existe por una razón medida: los dos únicos fallos de nombre de las
     * fixturas reales del OCR son erratas de **un solo carácter** («Tlanura»,
     * «Llasura»), y ni la igualdad del paso 3 ni el `FULLTEXT` del 4 rescatan
     * ninguna. Un carácter mal leído tiraba la lectura entera.
     *
     * **Devuelve solo el escalón más cercano**, no todo lo que quepa bajo el
     * umbral: quien llama acepta ÚNICAMENTE si queda un candidato, así que
     * mezclar los de distancia 2 con uno de distancia 1 convertiría un acierto en
     * un empate. Y el empate a la mínima distancia es `ambiguous` con sus
     * candidatos, **jamás el primero**: con dos cartas a la misma distancia no se
     * elige, que es lo que impide que un CSV con un nombre deliberadamente
     * distinto resuelva por parecido.
     *
     * @param  string $nombre Clave ya normalizada
     * @param  int    $maxima Distancia de edición máxima, inclusive
     * @return list<array<string, mixed>> Los empatados a la distancia mínima
     */
    public function cartasPorNombreAproximado(string $nombre, int $maxima): array;
}
