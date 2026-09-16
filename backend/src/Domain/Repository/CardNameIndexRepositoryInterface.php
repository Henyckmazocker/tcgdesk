<?php

declare(strict_types=1);

namespace App\Domain\Repository;

/**
 * Mantenimiento del índice de nombres normalizados. **Son dos tablas**:
 * `mtg_card.name_normalized` (el nombre en inglés) y
 * `mtg_printing_localized.name_normalized` (el mismo nombre en los otros nueve
 * idiomas), y las dos las rellena el mismo comando, `catalog:normalize`.
 *
 * Es el único puerto de este proyecto que **escribe en el catálogo sin ser la
 * ingesta**, y por eso está separado de `CatalogRepositoryInterface`: lo usa un
 * solo comando, y ninguna petición de usuario puede ni nombrarlo. El catálogo
 * sigue siendo de solo lectura desde el punto de vista de la app.
 *
 * La columna la calcula PHP y no SQL porque no hay `ext/intl` en el contenedor
 * (`Normalizer::normalize()` no existe) y porque la regla no es «quitar la
 * puntuación»: los blancos `_____` se conservan. Ver `NameNormalizer`.
 *
 * **La clave de las dos tablas se calcula con la MISMA función**, y eso no es
 * comodidad: el resolvedor busca el nombre leído en las dos, así que una
 * normalización distinta a cada lado haría que la misma carta se encontrase en
 * inglés y no en español, sin error por ningún lado.
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

    /** Nombres localizados todavía fuera del índice. Cero es el estado sano. */
    public function contarLocalizadosSinNormalizar(): int;

    /**
     * Un lote de nombres localizados a normalizar, paginado por la PK completa.
     *
     * El cursor son **dos** columnas porque la PK de `mtg_printing_localized` es
     * `(printing_uuid, language)`: una misma impresión tiene hasta diez filas y
     * paginar solo por el uuid se dejaría nueve por el camino, o repetiría el
     * lote entero sin avanzar nunca. Se compara como tupla —
     * `(printing_uuid, language) > (?, ?)`— que es lo que usa el índice de la PK.
     *
     * @param  string $desdeUuid   Exclusivo junto con el idioma; vacío para empezar
     * @param  string $desdeIdioma Exclusivo; vacío para empezar
     * @param  bool   $todas       false = solo las que están a NULL
     * @return list<array{printingUuid: string, language: string, name: string}>
     */
    public function loteLocalizadoPorNormalizar(
        string $desdeUuid,
        string $desdeIdioma,
        int $limite,
        bool $todas
    ): array;

    /**
     * @param  list<array{printingUuid: string, language: string, clave: string}> $filas
     * @return int Filas enviadas
     */
    public function escribirClavesLocalizadas(array $filas): int;
}
