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

    /**
     * El día más reciente que hay en el histórico, `YYYY-MM-DD`, o `null` si
     * `mtg_price_daily` está vacía.
     *
     * Es un método de lectura en un puerto que hasta ahora solo escribía, y
     * está aquí y no en el puerto de catálogo por lo mismo que lo está
     * `contadores()`: quien pregunta por la salud del histórico pregunta por la
     * tabla que este puerto es el único que toca. Lo consume `prices:health`,
     * que compara esta fecha con hoy — si el sync diario deja de correr, el
     * único síntoma es que este valor se queda quieto, y estuvo 33 días
     * quieto sin que nadie lo mirara.
     */
    public function ultimaFechaHistorico(): ?string;
}
