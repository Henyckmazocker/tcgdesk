<?php

declare(strict_types=1);

namespace App\Domain\Repository;

/**
 * Escritura de precios.
 *
 * A diferencia del catálogo, esto **no se puede reconstruir**: MTGJSON solo
 * retiene 90 días de histórico y lo que no se capture a tiempo se pierde para
 * siempre. De ahí que `mtg_price_daily` solo se inserte, nunca se actualice: un
 * precio ya registrado para un día es un hecho histórico, no un dato mutable.
 */
interface PriceRepositoryInterface
{
    /**
     * Inserta en el histórico, ignorando lo que ya esté.
     *
     * @param  list<array<string, mixed>> $filas
     * @return int Filas nuevas realmente insertadas
     */
    public function insertarHistorico(array $filas): int;

    /**
     * Reescribe el precio vigente por printing y acabado.
     *
     * @param  list<array<string, mixed>> $filas
     * @return int Filas enviadas
     */
    public function reemplazarVigentes(array $filas): int;

    /**
     * Los uuid de printing que existen en el catálogo.
     *
     * Hace falta porque `AllPricesToday` trae precios de cartas que nuestro
     * catálogo no tiene —MTGO, productos que MTGJSON lista aparte—, y esas filas
     * violarían la clave foránea de `mtg_price_daily`.
     *
     * @return array<string, true> uuid → true
     */
    public function uuidsConocidos(): array;

    /** @return array<string, int> tabla → filas */
    public function contadores(): array;
}
