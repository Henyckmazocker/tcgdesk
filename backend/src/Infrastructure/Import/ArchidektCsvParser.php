<?php

declare(strict_types=1);

namespace App\Infrastructure\Import;

/**
 * Lee el CSV que exporta **Archidekt**.
 *
 * Cabecera del formato:
 *
 * ```
 * Quantity, Name, Finish, Condition, Language, Set Code, Set Name,
 * Collector Number, Scryfall ID
 * ```
 *
 * Es el más parecido al vocabulario común: solo cambia una cosa de verdad, y es
 * que **el acabado se llama `Finish`** (`Normal` / `Foil` / `Etched`) en vez de
 * `Foil`. Justo eso es lo que lo distingue de ManaBox, que exporta `Foil`.
 *
 * Sobre la edición se aceptan **las dos grafías**: `Set Code` / `Set Name` y
 * `Edition Code` / `Edition Name`. Las exportaciones de Archidekt han usado
 * ambas según la versión, y como las columnas se buscan por nombre, admitir las
 * dos sale gratis y evita que una actualización de la app rompa la importación
 * en silencio (la columna dejaría de encontrarse y el `setCode` llegaría a
 * null, sin un solo error).
 *
 * La condición viene en los códigos propios de Archidekt (`NM`, `LP`, `MP`,
 * `HP`, `D`); los mapea `Condition`.
 */
final class ArchidektCsvParser extends CsvCollectionParser
{
    private const NOMBRE = 'archidekt';

    public function getName(): string
    {
        return self::NOMBRE;
    }

    /**
     * Columnas de las exportaciones largas de Archidekt. Ninguna otra app del
     * plan las escribe.
     *
     * @return string[]
     */
    protected function columnasHuella(): array
    {
        return ['edition code', 'multiverse id', 'mtgo card id'];
    }

    /**
     * `Finish` es la que decide: ManaBox llama `Foil` a esa columna y Moxfield
     * ni siquiera trae `Quantity`.
     *
     * @return string[]
     */
    protected function columnasObligatorias(): array
    {
        return ['quantity', 'name', 'finish', 'scryfall id'];
    }

    /** @return array<string, string[]> */
    protected function mapaDeColumnas(): array
    {
        return [
            'setCode' => ['set code', 'edition code'],
            'finish'  => ['finish'],
        ];
    }
}
