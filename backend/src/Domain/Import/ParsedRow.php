<?php

declare(strict_types=1);

namespace App\Domain\Import;

use App\Domain\Deck\Board;

/**
 * Una línea de un fichero importado, ya normalizada y todavía sin resolver.
 *
 * Es el idioma común entre los parsers y el resolvedor: a partir de aquí da
 * exactamente igual si el fichero venía de ManaBox, de Moxfield o de una lista
 * pegada a mano.
 *
 * **`scryfallId` gana sobre todo lo demás.** Si viene, la resolución es un
 * `JOIN` exacto contra `mtg_printing.scryfall_id` (36 caracteres, `UNIQUE`,
 * poblado en las 110.384 filas) y no hay ambigüedad posible de edición ni de
 * reimpresión. El resto de campos son el plan B del resolvedor.
 *
 * ## Filas inválidas
 *
 * La regla de oro del plan es que **lo que no case va a conflicto, nunca a un
 * valor por defecto**: caer a `NM` cuando el fichero decía otra cosa falsearía
 * la valoración de la colección al alza sin que nadie se entere.
 *
 * Como el objeto «conflicto» todavía no existe (llega con el resolvedor), aquí
 * se representa con las dos piezas que la previsualización va a necesitar:
 *
 *  - `$errores` — mapa `campo => motivo`. Vacío significa fila válida.
 *  - los campos afectados conservan **el valor crudo** del fichero, sin
 *    normalizar, para poder enseñárselo al usuario tal cual venía.
 *  - `$crudo` — el registro original completo, `cabecera => valor`.
 *
 * Una fila inválida **no se descarta**: viaja por el pipeline como cualquier
 * otra y es la previsualización la que decide qué hacer con ella. El resolvedor
 * solo tiene que mirar `esValida()` para mandarla a conflicto con
 * `reason: "invalid"`.
 *
 * ## `board`: la zona del mazo, cuando el fichero la dice
 *
 * Solo la escribe una decklist pegada —`PlainTextParser` la saca de las
 * cabeceras `Deck` / `Sideboard` / `Commander` / `Companion`—; los tres CSV no
 * traen zonas y se quedan con el defecto. **A la colección no le afecta**:
 * `mtg_collection_item` no tiene `board` y una carta es la misma esté en el
 * main o en el side. Viaja aquí porque es lo único que puede repartir las
 * zonas cuando la importación crea además el mazo (M7).
 *
 * Los nombres de las propiedades del contrato están en inglés porque los fija
 * el plan de forma literal; lo añadido por encima sigue la convención en
 * español del resto del repositorio.
 */
final class ParsedRow
{
    /**
     * @param string                $finish    normal | foil | etched (crudo si hay error)
     * @param string                $language  forma larga de MTGJSON: 'English', 'Spanish', …
     * @param string                $condition M, NM, EX, GD, LP, PL, PO (crudo si hay error)
     * @param int                   $sourceLine Línea física del fichero, 1-indexada
     * @param array<string, string> $errores   campo => motivo; vacío si la fila es válida
     * @param array<string, string> $crudo     El registro original, cabecera => valor
     * @param string                $board     Zona del mazo: main | side | commander | …
     */
    public function __construct(
        public readonly ?string $scryfallId,
        public readonly ?string $name,
        public readonly ?string $setCode,
        public readonly ?string $collectorNumber,
        public readonly string $finish,
        public readonly string $language,
        public readonly string $condition,
        public readonly int $quantity,
        public readonly int $sourceLine,
        public readonly array $errores = [],
        public readonly array $crudo = [],
        // El defecto sale del enum y no de un literal suelto: los siete valores
        // los fija `mtg_deck_card.board` y una lista repetida se desincroniza.
        // Es `Board::Main->value` y no `Board::porDefecto()` porque el valor por
        // defecto de un parámetro tiene que ser una expresión constante.
        public readonly string $board = Board::Main->value,
    ) {
    }

    /** Si es false, la fila va a conflicto: no se importa sin intervención. */
    public function esValida(): bool
    {
        return $this->errores === [];
    }

    /** Motivos concatenados, para el log y para la previsualización. */
    public function motivoDeError(): ?string
    {
        if ($this->errores === []) {
            return null;
        }

        $partes = [];
        foreach ($this->errores as $campo => $motivo) {
            $partes[] = $campo . ': ' . $motivo;
        }

        return implode('; ', $partes);
    }
}
