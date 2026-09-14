<?php

declare(strict_types=1);

namespace App\Domain\Deck;

use InvalidArgumentException;

/**
 * La zona del mazo en la que vive una línea.
 *
 * Los siete valores son los del ENUM `mtg_deck_card.board`: `planes`, `schemes`
 * y `tokens` están porque **MTGJSON los trae** y el criterio del plan es
 * fidelidad al origen; `companion` está porque MTGJSON *no* lo trae pero las
 * decklists pegadas sí lo escriben —`PlainTextParser.php:87` ya reconoce esa
 * cabecera—. `displayCommander` queda fuera a propósito: es cosmético (qué
 * carta enseña la caja del precon), no una zona de juego.
 *
 * Es objeto de valor y no un string por lo mismo que `Finish` o `Condition`:
 * **`board` está DENTRO de `uq_deck_card`**, así que `'Sideboard'` y `'side'`
 * crearían dos líneas para la misma carta en la misma zona.
 *
 * Y hay una regla de negocio que solo se puede preguntar aquí: **`tokens` no
 * cuenta para nada** —ni consume colección, ni suma al valor, ni cuenta para el
 * tamaño mínimo del mazo—. Un token no es una carta que se posea de forma
 * significativa. Ver `esPoseible()`.
 */
enum Board: string
{
    case Main      = 'main';
    case Side      = 'side';
    case Commander = 'commander';
    case Companion = 'companion';
    case Planes    = 'planes';
    case Schemes   = 'schemes';
    case Tokens    = 'tokens';

    /**
     * Lo que escriben MTGJSON y las decklists pegadas.
     *
     * `mainBoard` / `sideBoard` son literalmente las claves de MTGJSON —el
     * `normalizar()` de abajo las deja en `mainboard` / `sideboard`—, y `deck`,
     * `sideboard`, `commander` y `companion` son las cuatro cabeceras que
     * `PlainTextParser::CABECERAS` ya reconoce hoy y tira a la basura. Cuando
     * M7 deje de tirarlas, entrarán por aquí.
     */
    private const ALIAS = [
        'deck'      => 'main',
        'maindeck'  => 'main',
        'main deck' => 'main',
        'mainboard' => 'main',
        'main board' => 'main',
        'sideboard' => 'side',
        'side board' => 'side',
        'sb'        => 'side',
        'plane'     => 'planes',
        'scheme'    => 'schemes',
        'token'     => 'tokens',
    ];

    /** Donde va una carta si nadie dice lo contrario. */
    public static function porDefecto(): self
    {
        return self::Main;
    }

    /**
     * @param  mixed $valor Lo que venga del cliente o de la decklist pegada
     * @throws InvalidArgumentException si no corresponde a ninguna zona
     */
    public static function desde(mixed $valor): self
    {
        return self::tryFrom(self::normalizar($valor))
            ?? throw new InvalidArgumentException(
                'Zona de mazo no soportada: ' . (is_scalar($valor) ? (string) $valor : gettype($valor))
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

    /**
     * Si una carta de esta zona es una carta que **se posee**.
     *
     * Todas menos `tokens`. De aquí salen las tres consecuencias que el plan
     * repite: los tokens no consumen colección, no suman al valor del mazo y no
     * cuentan para el tamaño mínimo. Un token se genera, no se compra.
     */
    public function esPoseible(): bool
    {
        return $this !== self::Tokens;
    }

    private static function normalizar(mixed $valor): string
    {
        if ($valor instanceof self) {
            return $valor->value;
        }

        $texto = is_scalar($valor) ? (string) $valor : '';
        $texto = strtolower(trim(preg_replace('/\s+/', ' ', $texto) ?? ''));

        return self::ALIAS[$texto] ?? $texto;
    }
}
