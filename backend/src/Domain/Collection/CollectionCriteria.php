<?php

declare(strict_types=1);

namespace App\Domain\Collection;

use InvalidArgumentException;

/**
 * Los filtros y el orden de una consulta a la colección, ya validados.
 *
 * Mismo criterio que `SearchCriteria` del catálogo: `desdePeticion()`
 * **normaliza y acota** en vez de rechazar —un `limit` de 5.000 se recorta, una
 * rareza desconocida se ignora—, porque lo que llega de una query string es una
 * sugerencia. Eso es lo que permite al repositorio interpolar el `ORDER BY` y el
 * `LIMIT` en el SQL, donde PDO no admite marcador.
 *
 * La única excepción es `is_wishlist`, que no es un filtro cosmético sino **dos
 * listas distintas**: la colección y la lista de deseos comparten tabla y se
 * separan por esta bandera.
 */
final class CollectionCriteria
{
    public const RAREZAS = ['common', 'uncommon', 'rare', 'mythic', 'special', 'bonus'];

    /**
     * `price_desc` es el orden por defecto porque es el de la consulta central
     * del plan: entras a ver tus joyas primero.
     */
    public const ORDENES = ['name', 'release', 'rarity', 'price_asc', 'price_desc', 'quantity', 'added'];

    public const LIMITE_MAXIMO = 200;

    public function __construct(
        public readonly bool $isWishlist = false,
        public readonly ?string $setCode = null,
        public readonly ?string $rarity = null,
        public readonly ?string $colors = null,
        public readonly ?Finish $finish = null,
        public readonly ?CardLanguage $language = null,
        public readonly ?Condition $condition = null,
        public readonly ?float $priceMin = null,
        public readonly ?float $priceMax = null,
        public readonly string $sort = 'price_desc',
        public readonly int $limit = 60,
        public readonly ?string $cursor = null
    ) {
        if (!in_array($this->sort, self::ORDENES, true)) {
            throw new InvalidArgumentException("Orden no soportado: {$this->sort}");
        }

        if ($this->rarity !== null && !in_array($this->rarity, self::RAREZAS, true)) {
            throw new InvalidArgumentException("Rareza no soportada: {$this->rarity}");
        }

        if ($this->limit < 1 || $this->limit > self::LIMITE_MAXIMO) {
            throw new InvalidArgumentException("Límite fuera de rango: {$this->limit}");
        }
    }

    /**
     * @param array<string, mixed> $peticion
     */
    public static function desdePeticion(array $peticion): self
    {
        $texto = static fn (string $clave): ?string => isset($peticion[$clave]) && trim((string) $peticion[$clave]) !== ''
            ? trim((string) $peticion[$clave])
            : null;

        $rareza = $texto('rarity') !== null ? strtolower((string) $texto('rarity')) : null;
        $orden  = $texto('sort')   !== null ? strtolower((string) $texto('sort'))   : 'price_desc';

        $colores = $texto('colors');
        if ($colores !== null) {
            $colores = implode('', array_unique(str_split(
                preg_replace('/[^WUBRGwubrg]/', '', $colores) ?? ''
            ) ?: []));
            $colores = strtoupper($colores) ?: null;
        }

        return new self(
            isWishlist: filter_var($peticion['is_wishlist'] ?? false, FILTER_VALIDATE_BOOL),
            setCode:    $texto('set') !== null ? strtoupper((string) $texto('set')) : null,
            rarity:     in_array($rareza, self::RAREZAS, true) ? $rareza : null,
            colors:     $colores,
            // `intentar()` y no `desde()`: un filtro que no se entiende se
            // ignora, igual que la rareza. Es una vista, no una escritura.
            finish:    Finish::intentar($texto('finish')),
            language:  CardLanguage::intentar($texto('language')),
            condition: Condition::intentar($texto('condition')),
            priceMin:  isset($peticion['price_min']) && is_numeric($peticion['price_min'])
                ? (float) $peticion['price_min']
                : null,
            priceMax:  isset($peticion['price_max']) && is_numeric($peticion['price_max'])
                ? (float) $peticion['price_max']
                : null,
            sort:      in_array($orden, self::ORDENES, true) ? $orden : 'price_desc',
            limit:     isset($peticion['limit']) && is_numeric($peticion['limit'])
                ? max(1, min(self::LIMITE_MAXIMO, (int) $peticion['limit']))
                : 60,
            cursor:    $texto('cursor'),
        );
    }

    /** Los colores como letras sueltas, para el filtro por identidad. */
    public function coloresComoLetras(): array
    {
        return $this->colors === null || $this->colors === '' ? [] : str_split($this->colors);
    }
}
