<?php

declare(strict_types=1);

namespace App\Domain\Deck;

use App\Domain\Collection\CardLanguage;
use App\Domain\Collection\Condition;
use App\Domain\Collection\Finish;
use InvalidArgumentException;

/**
 * Una línea de mazo, ya validada: las seis columnas del `UNIQUE KEY
 * uq_deck_card` más la cantidad.
 *
 * **Reutiliza `Finish`, `CardLanguage` y `Condition` de la colección, no los
 * duplica.** Son exactamente los mismos valores —los ENUM de `mtg_deck_card`
 * son copia de los de `mtg_collection_item`— y ya traen su `desde()`, que lanza
 * en vez de caer en el defecto. Un segundo juego de enums con los mismos casos
 * garantizaría que un día uno acepte un alias que el otro no, y entonces el
 * cruce mazo ↔ colección de M3 dejaría de casar filas que son la misma carta.
 *
 * Lo que sí es propio del mazo es `board`: la zona de juego. Está DENTRO de
 * `uq_deck_card` a propósito —la misma carta en el main y en el side son dos
 * líneas legítimas— y `tokens` no cuenta para nada (ver `Board::esPoseible()`).
 *
 * `is_wishlist` **no** está aquí: un mazo pide cartas, no las desea. Cuando el
 * cruce con la colección busca la línea equivalente, filtra por
 * `is_wishlist = 0` — sin ese filtro una carta que *quieres* contaría como
 * carta que *tienes* y el mazo diría que está completo.
 */
final class DeckCard
{
    /**
     * La columna es `SMALLINT UNSIGNED` (máximo 65.535) y el alta **suma**, así
     * que un tope generoso por operación deja margen a las sumas sucesivas sin
     * que MySQL aborte por desbordamiento. Mismo criterio que
     * `CollectionItem::CANTIDAD_MAXIMA`.
     */
    public const CANTIDAD_MAXIMA = 9999;

    public function __construct(
        public readonly int $deckId,
        public readonly string $printingUuid,
        public readonly Finish $finish,
        public readonly CardLanguage $language,
        public readonly Condition $condition,
        public readonly Board $board,
        public readonly int $count = 1
    ) {
        if ($this->printingUuid === '') {
            throw new InvalidArgumentException('Falta el printing_uuid de la carta.');
        }

        // Aquí el mínimo es 1 y no 0: esto es un ALTA. Poner una línea a cero es
        // otra operación (`ChangeDeckCardCount`), y allí el 0 significa borrarla.
        if ($this->count < 1 || $this->count > self::CANTIDAD_MAXIMA) {
            throw new InvalidArgumentException(
                'La cantidad debe estar entre 1 y ' . self::CANTIDAD_MAXIMA . ", y llegó {$this->count}."
            );
        }
    }

    /**
     * @param  array<string, mixed> $peticion Payload del cliente, sin el deck_id
     * @throws InvalidArgumentException si algo no es del dominio
     */
    public static function desdePeticion(int $deckId, array $peticion): self
    {
        return new self(
            deckId:       $deckId,
            printingUuid: trim((string) ($peticion['printing_uuid'] ?? '')),
            // Ausente = el valor por defecto; presente pero inválido = error.
            // Un acabado que no existe es un fallo del cliente, no algo a
            // adivinar: guardarlo como 'normal' haría que el mazo reclamara una
            // carta distinta de la que el usuario quiso meter.
            finish:    isset($peticion['finish'])   ? Finish::desde($peticion['finish'])         : Finish::porDefecto(),
            language:  isset($peticion['language']) ? CardLanguage::desde($peticion['language']) : CardLanguage::porDefecto(),
            condition: self::condicion($peticion),
            board:     isset($peticion['board'])    ? Board::desde($peticion['board'])           : Board::porDefecto(),
            count:     isset($peticion['count']) && is_numeric($peticion['count'])
                ? (int) $peticion['count']
                : 1,
        );
    }

    /**
     * La misma línea, en otro mazo.
     *
     * Existe para la importación de M7: las líneas se validan **antes** de abrir
     * la transacción —un acabado inventado tiene que dar 422 sin haber escrito
     * nada— y en ese momento el mazo todavía no existe, porque su `id` lo pone
     * el `AUTO_INCREMENT` dentro de la transacción. Devuelve una copia: la clase
     * es de solo lectura y `deckId` está dentro de `uq_deck_card`.
     */
    public function enElMazo(int $deckId): self
    {
        return new self(
            $deckId,
            $this->printingUuid,
            $this->finish,
            $this->language,
            $this->condition,
            $this->board,
            $this->count
        );
    }

    /**
     * Las columnas tal como van a `mtg_deck_card`.
     *
     * @return array<string, mixed>
     */
    public function aFila(): array
    {
        return [
            'deck_id'         => $this->deckId,
            'printing_uuid'   => $this->printingUuid,
            'finish'          => $this->finish->value,
            'language'        => $this->language->value,
            'condition_grade' => $this->condition->value,
            'board'           => $this->board->value,
            'count'           => $this->count,
        ];
    }

    /**
     * El estado físico, que llega con **dos nombres** según quién pregunte.
     *
     * Los contratos de mazo lo llaman `condition_grade` —igual que la columna—
     * y los de colección lo llaman `condition`. Se aceptan los dos porque el
     * frontend comparte el mismo selector entre las dos pantallas, y porque
     * rechazar el que no toca daría un 422 imposible de entender.
     *
     * @param array<string, mixed> $peticion
     */
    private static function condicion(array $peticion): Condition
    {
        $valor = $peticion['condition_grade'] ?? $peticion['condition'] ?? null;

        return $valor !== null ? Condition::desde($valor) : Condition::porDefecto();
    }
}
