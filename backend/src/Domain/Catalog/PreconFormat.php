<?php

declare(strict_types=1);

namespace App\Domain\Catalog;

/**
 * Qué formato de juego tiene el mazo que sale de comprar un precon, deducido de
 * su `deck_type`.
 *
 * **La regla es «solo cuando sea obvio; NULL cuando no».** `mtg_deck.format` es
 * `VARCHAR(24) NULL` y «sin formato» es un mazo perfectamente válido: el formato
 * solo sirve para el aviso de tamaño mínimo (`Deck::tamanoMinimo()`) y para el
 * cruce de legalidad, así que **inventarse uno es peor que no poner ninguno** —un
 * formato equivocado hace que la app avise de ilegalidades que no existen, y
 * dejarlo a NULL no avisa de nada—.
 *
 * Al revés que `PreconPlayability`, esto es una lista de **inclusión**: lo que no
 * esté escrito aquí sale sin formato. Los 48 `deck_type` siguen saliendo del
 * `GROUP BY` de la tabla; aquí solo están los tres que se pueden traducir sin
 * adivinar, y **el valor tiene que existir en `mtg_legality.format`** (los
 * nombres de MTGJSON en minúsculas) o el cruce de legalidad no casaría ni una
 * fila y nadie vería un error, solo faltarían los avisos.
 *
 * Tres tipos que parecen traducibles y **no lo son**, por si a alguien le tienta
 * añadirlos:
 *
 *  - **`Duel Deck` (52) y `MTGO Duel Deck` (2) NO son el formato `duel`.** Son la
 *    línea de producto de dos mazos enfrentados (*Elves vs. Goblins*); `duel` en
 *    `mtg_legality` es Duel Commander, que no tiene nada que ver. Traducirlo
 *    marcaría 54 mazos con un formato de cien cartas y un comandante.
 *  - **`Historic Brawl Precon Deck` (5)** no tiene formato propio en
 *    `mtg_legality`: están `brawl` y `standardbrawl`, y elegir uno es decidir.
 *  - **`Planechase Deck` (12) y `Archenemy Deck` (8)** son variantes de juego,
 *    no formatos: `mtg_legality` no los conoce.
 */
final class PreconFormat
{
    /**
     * `deck_type` → `mtg_legality.format`.
     *
     * @var array<string, string>
     */
    public const FORMATOS = [
        'Commander Deck'      => 'commander', // los 190 del catálogo
        'MTGO Commander Deck' => 'commander', // el mismo producto, en digital
        'Brawl Deck'          => 'brawl',
    ];

    /** El formato del mazo, o null si el tipo no lo dice a las claras. */
    public static function deDeckType(?string $deckType): ?string
    {
        if ($deckType === null) {
            return null;
        }

        return self::FORMATOS[$deckType] ?? null;
    }
}
