<?php

declare(strict_types=1);

namespace App\Domain\Collection;

use InvalidArgumentException;

/**
 * El acabado de un ejemplar: `normal`, `foil` o `etched`.
 *
 * Es un objeto de valor y no un string suelto por la misma razón que
 * `Condition` y `CardLanguage`: **el acabado entra en el `UNIQUE KEY` de
 * `mtg_collection_item`**, así que dos formas distintas de escribir lo mismo
 * ('foil' y 'Foil') no colisionarían y crearían dos filas para la misma carta.
 * Normalizar aquí, una sola vez, es lo que hace idempotente la importación del
 * plan siguiente.
 *
 * **`etched` es un acabado real, no un "foil raro".** Tiene su propia fila en
 * `mtg_price_current` y por tanto su propio precio; tratarlo como foil daría
 * valoraciones falsas de las cartas de Commander Legends en adelante.
 */
enum Finish: string
{
    case Normal = 'normal';
    case Foil   = 'foil';
    case Etched = 'etched';

    /**
     * Las formas alternativas que llegan de fuera.
     *
     * MTGJSON escribe `nonfoil`; Scryfall y los exportadores de Cardmarket usan
     * `non-foil`, `regular` o `etched foil`. Todas apuntan a los mismos tres
     * valores del ENUM y ninguna debe crear una fila aparte.
     */
    private const ALIAS = [
        'nonfoil'     => 'normal',
        'non-foil'    => 'normal',
        'non foil'    => 'normal',
        'regular'     => 'normal',
        'plain'       => 'normal',
        'holo'        => 'foil',
        'holofoil'    => 'foil',
        'etchedfoil'  => 'etched',
        'etched foil' => 'etched',
    ];

    /** El acabado que asume la ficha de catálogo con un solo clic. */
    public static function porDefecto(): self
    {
        return self::Normal;
    }

    /**
     * @param  mixed $valor Lo que venga del cliente o del fichero importado
     * @throws InvalidArgumentException si no corresponde a ningún acabado
     */
    public static function desde(mixed $valor): self
    {
        $normalizado = self::normalizar($valor);

        return self::tryFrom($normalizado)
            ?? throw new InvalidArgumentException(
                'Acabado no soportado: ' . (is_scalar($valor) ? (string) $valor : gettype($valor))
            );
    }

    /** Como `desde()`, pero null en vez de excepción. Para filtros opcionales. */
    public static function intentar(mixed $valor): ?self
    {
        if ($valor === null || $valor === '') {
            return null;
        }

        return self::tryFrom(self::normalizar($valor));
    }

    private static function normalizar(mixed $valor): string
    {
        if ($valor instanceof self) {
            return $valor->value;
        }

        $texto = strtolower(trim((string) (is_scalar($valor) ? $valor : '')));

        return self::ALIAS[$texto] ?? $texto;
    }
}
