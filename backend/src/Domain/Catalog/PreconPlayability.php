<?php

declare(strict_types=1);

namespace App\Domain\Catalog;

/**
 * Qué tipos de `mtg_precon` son un mazo que se juega y cuáles son un producto.
 *
 * MTGJSON publica 3.029 «mazos» en 48 tipos, y **los tres más numerosos no son
 * mazos**: 739 *Secret Lair Drop* (una tirada de cartas sueltas), 197 *MTGO
 * Redemption* (el canje de una edición digital entera) y 89 *Bundle Land Pack*
 * (las tierras básicas de una caja). Listarlos junto a los 190 *Commander Deck*
 * haría creer que hay 3.029 mazos que jugar, y el que busca el precon que acaba
 * de comprar tendría que cruzar 1.048 productos para llegar.
 *
 * **Es una lista de EXCLUSIÓN, y por eso no contradice el "la lista de tipos no
 * se copia a mano".** Los 48 tipos siguen saliendo de un `GROUP BY` sobre la
 * tabla (`PreconRepositoryInterface::facetas()`); lo único escrito aquí son los
 * cinco que se sabe que no son mazos. **Un tipo que MTGJSON invente mañana se
 * considera jugable**, o sea, sale por defecto: equivocarse enseñando de más es
 * recuperable —el usuario lo ve y lo filtra—, equivocarse escondiendo deja cajas
 * invisibles sin que nadie se entere.
 *
 * Vive en el dominio y no en el `.vue` porque la misma clasificación la usan la
 * consulta —el filtro `playable=1` de la lista— y el desplegable de la vista, que
 * la recibe marcada en las facetas. En dos sitios se desincronizaría.
 *
 * Lo que **no** está aquí, a propósito, aunque se parezca: *Deck Builder's
 * Toolkit* (120) y *Box Set* (71) traen decklists de verdad —«Blue and Black
 * Pirates», «Beginner Box - Allies»— y *Sample Deck* (50) son mazos de muestra
 * jugables. Ante la duda, jugable.
 */
final class PreconPlayability
{
    /**
     * Tipos que NO son un mazo, con el motivo de cada uno.
     *
     * @var list<string>
     */
    public const NO_JUGABLES = [
        'Secret Lair Drop',           // una tirada de cartas sueltas, no una lista
        'MTGO Redemption',            // el canje físico de una edición digital entera
        'Bundle Land Pack',           // las tierras básicas que vienen en la caja
        'Welcome Booster',            // un sobre de bienvenida, no un mazo
        'San Diego Comic Con Promos', // sets de promos de la convención
    ];

    /** ¿Este `deck_type` es un mazo que se juega? Lo desconocido, sí. */
    public static function esJugable(string $tipo): bool
    {
        return !in_array($tipo, self::NO_JUGABLES, true);
    }
}
