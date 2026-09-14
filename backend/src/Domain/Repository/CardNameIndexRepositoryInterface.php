<?php

declare(strict_types=1);

namespace App\Domain\Repository;

/**
 * Mantenimiento del índice de nombres normalizados (`mtg_card.name_normalized`).
 *
 * Es el único puerto de este proyecto que **escribe en el catálogo sin ser la
 * ingesta**, y por eso está separado de `CatalogRepositoryInterface`: lo usa un
 * solo comando, `catalog:normalize`, y ninguna petición de usuario puede ni
 * nombrarlo. El catálogo sigue siendo de solo lectura desde el punto de vista de
 * la app.
 *
 * La columna la calcula PHP y no SQL porque no hay `ext/intl` en el contenedor
 * (`Normalizer::normalize()` no existe) y porque la regla no es «quitar la
 * puntuación»: los blancos `_____` se conservan. Ver `NameNormalizer`.
 */
interface CardNameIndexRepositoryInterface
{
    /** Cartas todavía fuera del índice de nombres. Cero es el estado sano. */
    public function contarSinNormalizar(): int;

    /**
     * Un lote de cartas a normalizar, paginado por `oracle_id`.
     *
     * Pagina por cursor y no por OFFSET por lo mismo que el catálogo: 34.992
     * filas son 70 lotes, y con OFFSET el último obliga a MySQL a recorrer y
     * tirar las 34.492 anteriores.
     *
     * @param  string $desdeOracleId Exclusivo; cadena vacía para empezar
     * @param  bool   $todas         false = solo las que están a NULL
     * @return list<array{oracleId: string, name: string}> En orden de oracle_id
     */
    public function lotePorNormalizar(string $desdeOracleId, int $limite, bool $todas): array;

    /**
     * @param  array<string, string> $porOracleId oracle_id → clave normalizada
     * @return int Filas enviadas
     */
    public function escribirClaves(array $porOracleId): int;
}
