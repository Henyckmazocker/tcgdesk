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
     * Conteo por tabla del catálogo, para comprobar la idempotencia.
     *
     * @return array<string, int> nombre de tabla → filas
     */
    public function contadores(): array;
}
