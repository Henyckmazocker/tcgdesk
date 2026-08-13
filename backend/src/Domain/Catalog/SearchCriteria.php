<?php

declare(strict_types=1);

namespace App\Domain\Catalog;

use InvalidArgumentException;

/**
 * Los filtros de una búsqueda en el catálogo, ya validados.
 *
 * Se construye desde la petición con `desdePeticion()`, que **normaliza y acota**
 * en vez de rechazar: un `limit` de 5.000 se recorta a 100 y una rareza
 * desconocida se ignora. Lo que llega de la red es sugerencia, no orden — y así
 * el repositorio recibe siempre valores en los que puede confiar, que es lo que
 * permite interpolarlos en el SQL cuando PDO no admite marcador (ORDER BY, LIMIT).
 */
final class SearchCriteria
{
    public const RAREZAS = ['common', 'uncommon', 'rare', 'mythic', 'special', 'bonus'];

    public const ORDENES = ['relevance', 'name', 'release', 'rarity', 'price_asc', 'price_desc'];

    public const LIMITE_MAXIMO = 100;

    /**
     * Órdenes cuyo cursor puede ser una comparación de columna indexable.
     *
     * Los demás (`relevance`, `rarity`, `price_*`) ordenan por una expresión
     * calculada o por una columna con NULL, donde la comparación de tuplas ni se
     * indexa ni se comporta: ahí el cursor guarda la posición. Es aceptable
     * porque son los órdenes que se usan sobre un resultado ya filtrado, no
     * paseando el catálogo entero.
     */
    public const ORDENES_CON_CURSOR_DE_COLUMNA = ['name', 'release'];

    public function __construct(
        public readonly ?string $q = null,
        public readonly ?string $setCode = null,
        public readonly ?string $rarity = null,
        public readonly ?string $colors = null,
        public readonly ?float $priceMin = null,
        public readonly ?float $priceMax = null,
        public readonly string $sort = 'relevance',
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

        $rareza = $texto('rarity') !== null ? strtolower($texto('rarity')) : null;
        $orden  = $texto('sort')   !== null ? strtolower($texto('sort'))   : 'relevance';

        // Solo letras de color, en mayúsculas y sin repetir: 'wubrg' → 'WUBRG'.
        $colores = $texto('colors');
        if ($colores !== null) {
            $colores = implode('', array_unique(str_split(
                preg_replace('/[^WUBRGwubrg]/', '', $colores) ?? ''
            ) ?: []));
            $colores = strtoupper($colores) ?: null;
        }

        return new self(
            q:        $texto('q'),
            setCode:  $texto('set') !== null ? strtoupper($texto('set')) : null,
            // Un valor desconocido se ignora en vez de romper la búsqueda: viene
            // de una query string que cualquiera puede teclear a mano.
            rarity:   in_array($rareza, self::RAREZAS, true) ? $rareza : null,
            colors:   $colores,
            priceMin: isset($peticion['price_min']) && is_numeric($peticion['price_min'])
                ? (float) $peticion['price_min']
                : null,
            priceMax: isset($peticion['price_max']) && is_numeric($peticion['price_max'])
                ? (float) $peticion['price_max']
                : null,
            sort:     in_array($orden, self::ORDENES, true) ? $orden : 'relevance',
            limit:    isset($peticion['limit']) && is_numeric($peticion['limit'])
                ? max(1, min(self::LIMITE_MAXIMO, (int) $peticion['limit']))
                : 60,
            cursor:   $texto('cursor'),
        );
    }

    /** ¿El cursor de este orden puede compararse por columna? */
    public function usaCursorDeColumna(): bool
    {
        return in_array($this->sort, self::ORDENES_CON_CURSOR_DE_COLUMNA, true);
    }

    /**
     * Copia con otro cursor, para pedir la página siguiente.
     */
    public function conCursor(?string $cursor): self
    {
        return new self(
            $this->q,
            $this->setCode,
            $this->rarity,
            $this->colors,
            $this->priceMin,
            $this->priceMax,
            $this->sort,
            $this->limit,
            $cursor
        );
    }

    /** ¿Hay término de búsqueda? Sin él, ordenar por relevancia no significa nada. */
    public function tieneTexto(): bool
    {
        return $this->q !== null && $this->q !== '';
    }

    /** Los colores como letras sueltas, para el filtro por identidad. */
    public function coloresComoLetras(): array
    {
        return $this->colors === null || $this->colors === '' ? [] : str_split($this->colors);
    }

    public function filtraPorPrecio(): bool
    {
        return $this->priceMin !== null || $this->priceMax !== null;
    }
}
