<?php

declare(strict_types=1);

namespace App\Infrastructure\Import;

/**
 * Lee el CSV que exporta **ManaBox**, el escaneador de móvil del que salen las
 * importaciones grandes de verdad (miles de cartas escaneadas con la cámara).
 *
 * Conoce un formato externo, así que vive en infraestructura, igual que
 * `Infrastructure/Mtgjson`. El dominio solo ve `ParsedRow[]`.
 *
 * Cabecera documentada del formato:
 *
 * ```
 * Name, Set Code, Set Name, Collector Number, Foil, Rarity, Quantity,
 * ManaBox ID, Scryfall ID, Purchase Price, Misprint, Alt Art [, Condition, Language]
 * ```
 *
 * `Condition` y `Language` **no están en la cabecera que documenta el plan**,
 * pero las exporta la app y son justo lo que este proyecto no quiere tirar; por
 * eso son opcionales, como todo lo demás: las columnas se buscan por nombre.
 *
 * Sus dos particularidades frente a los otros dos CSV:
 *
 *  - el acabado se llama **`Foil`** (no `Finish`) y vale `normal`, `foil` o
 *    `etched`;
 *  - la condición viene en **`snake_case`** (`near_mint`, `light_played`), que
 *    es la causa conocida de los *"Could not parse card condition"* al importar
 *    su CSV en Moxfield.
 *
 * Todo lo demás —BOM, CRLF, número de línea física, la regla de «lo que no case
 * va a conflicto»— lo resuelve `CsvCollectionParser`.
 */
final class ManaBoxCsvParser extends CsvCollectionParser
{
    private const NOMBRE = 'manabox';

    public function getName(): string
    {
        return self::NOMBRE;
    }

    /**
     * `ManaBox ID` no la exporta ninguna otra app.
     *
     * @return string[]
     */
    protected function columnasHuella(): array
    {
        return ['manabox id'];
    }

    /**
     * Moxfield usa `Count`/`Edition` y Archidekt `Finish`, así que ni siquiera
     * el juego de columnas obligatorias los confunde con este: la exigencia de
     * `foil` **y** `quantity` **y** `set code` no la cumple ninguno de los dos.
     *
     * @return string[]
     */
    protected function columnasObligatorias(): array
    {
        return ['name', 'set code', 'collector number', 'foil', 'quantity'];
    }
}
