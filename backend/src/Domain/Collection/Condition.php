<?php

declare(strict_types=1);

namespace App\Domain\Collection;

use InvalidArgumentException;

/**
 * El estado físico de un ejemplar, con la escala de Cardmarket.
 *
 * **Este es el objeto de valor que justifica los tres.** El `UNIQUE KEY` de
 * `mtg_collection_item` incluye `condition_grade`, así que si un exportador
 * escribe `Near Mint` y otro `NM`, la importación crea dos filas para la misma
 * carta en el mismo estado y la colección queda partida. Aquí se normaliza una
 * sola vez y lo que llega a la base de datos es siempre el código corto del
 * ENUM.
 *
 * La escala es la de Cardmarket porque es la fuente de precios del proyecto:
 * M(int) · NM(ear Mint) · EX(cellent) · G(oo)D · L(ightly) P(layed) ·
 * P(oor)L(ayed) · PO(or).
 */
enum Condition: string
{
    case Mint         = 'M';
    case NearMint     = 'NM';
    case Excellent    = 'EX';
    case Good         = 'GD';
    case LightPlayed  = 'LP';
    case Played       = 'PL';
    case Poor         = 'PO';

    /**
     * Nombres largos y códigos de otras escalas → código de Cardmarket.
     *
     * Las claves van en minúsculas y sin espacios de sobra; `normalizar()` deja
     * la entrada en esa forma antes de buscar.
     *
     * Las variantes en `snake_case` no son decorativas: **es literalmente lo
     * que exporta ManaBox** (`near_mint`, `light_played`), y es la causa
     * conocida de los errores *"Could not parse card condition"* al importar su
     * CSV en otras apps.
     */
    private const ALIAS = [
        'mint'           => 'M',
        'mt'             => 'M',
        'gem mint'       => 'M',
        'gem_mint'       => 'M',
        'near mint'      => 'NM',
        'nearmint'       => 'NM',
        'near_mint'      => 'NM',
        'excellent'      => 'EX',
        'slightly played' => 'EX',
        'slightly_played' => 'EX',
        'sp'             => 'EX',
        'good'           => 'GD',
        'g'              => 'GD',
        'light played'   => 'LP',
        'light_played'   => 'LP',
        'lightly played' => 'LP',
        'lightly_played' => 'LP',
        'played'         => 'PL',
        'moderately played' => 'PL',
        'moderately_played' => 'PL',
        'mp'             => 'PL',
        'heavily played' => 'PL',
        'heavily_played' => 'PL',
        'hp'             => 'PL',
        'poor'           => 'PO',
        'damaged'        => 'PO',
        'dmg'            => 'PO',
        // Moxfield y Archidekt exportan la condición en códigos de una o dos
        // letras, y 'D' es su «Damaged». Sin este alias caería en el
        // `strtoupper` de abajo, no casaría con ningún caso y toda carta
        // dañada importada de esas dos apps saldría a conflicto.
        'd'              => 'PO',
    ];

    /** Lo que asume el botón "Añadir" de la ficha de catálogo. */
    public static function porDefecto(): self
    {
        return self::NearMint;
    }

    /**
     * @param  mixed $valor Lo que venga del cliente o del fichero importado
     * @throws InvalidArgumentException si no corresponde a ningún estado
     */
    public static function desde(mixed $valor): self
    {
        $normalizado = self::normalizar($valor);

        return self::tryFrom($normalizado)
            ?? throw new InvalidArgumentException(
                'Estado no soportado: ' . (is_scalar($valor) ? (string) $valor : gettype($valor))
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

    /** El nombre largo, para la interfaz. */
    public function nombre(): string
    {
        return match ($this) {
            self::Mint        => 'Mint',
            self::NearMint    => 'Near Mint',
            self::Excellent   => 'Excellent',
            self::Good        => 'Good',
            self::LightPlayed => 'Light Played',
            self::Played      => 'Played',
            self::Poor        => 'Poor',
        };
    }

    private static function normalizar(mixed $valor): string
    {
        if ($valor instanceof self) {
            return $valor->value;
        }

        $texto = is_scalar($valor) ? (string) $valor : '';

        // Espacios internos colapsados: 'Near   Mint' es 'near mint'.
        $texto = strtolower(trim(preg_replace('/\s+/', ' ', $texto) ?? ''));

        // El alias manda sobre el código corto: 'mint' es M, no una M suelta mal
        // escrita. Si no hay alias, se prueba tal cual en MAYÚSCULAS ('nm' → NM).
        return self::ALIAS[$texto] ?? strtoupper($texto);
    }
}
