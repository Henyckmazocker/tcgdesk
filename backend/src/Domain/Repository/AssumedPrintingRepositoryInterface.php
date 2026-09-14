<?php

declare(strict_types=1);

namespace App\Domain\Repository;

/**
 * La consulta que cierra el hueco que dejó el resolvedor: **qué impresión se
 * asume cuando el fichero identifica la carta pero no la edición**.
 *
 * Los pasos 3 y 4 de `CardResolver` resuelven la CARTA —el usuario tecleó
 * `4 Lightning Bolt` y nada más—, así que `CardResolution::$printingUuid` queda a
 * null en cuanto esa carta tiene más de una impresión. Mandar esas líneas a
 * conflicto convertiría una lista de 300 cartas pegada de una web en 300
 * elecciones manuales, que es justo lo que la importación existe para evitar.
 *
 * La decisión (plan, «La edición asumida», 2026-09-10) es elegir **la más barata
 * por precio de Cardmarket**, y **la más antigua** si ninguna de sus impresiones
 * cotiza. Barata y no reciente a propósito: si se acierta, se acierta; si no, la
 * colección queda valorada **de menos**, nunca inflada.
 *
 * ## Por qué el acabado forma parte de la pregunta
 *
 * Porque el precio se une por `(printing, finish)` y no solo por printing: un
 * foil vale otra cosa que su versión normal, y `etched` otra distinta. Preguntar
 * «la impresión más barata de esta carta» sin decir el acabado es lo que en el
 * Plan - Colección y Vistas infló una colección de prueba en 500 € valorando
 * foils a precio de no-foil.
 *
 * ## Y por qué va en lote
 *
 * Por lo mismo que el resolvedor: un fichero de texto plano de 20.000 líneas sin
 * edición dejaría 20.000 filas esperando impresión, y una consulta por fila es un
 * timeout con otro nombre.
 */
interface AssumedPrintingRepositoryInterface
{
    /**
     * La impresión asumida de cada carta, para el lote entero.
     *
     * @param  list<array{oracleId: string, finish: string}> $peticiones
     * @return array<string, array{printingUuid: string, setCode: string,
     *                             collectorNumber: string, priceEur: float|null,
     *                             printingCount: int}>
     *         Clave `oracleId|finish`, y solo para las cartas que tienen alguna
     *         impresión en el catálogo.
     */
    public function masBaratasPorCarta(array $peticiones): array;
}
