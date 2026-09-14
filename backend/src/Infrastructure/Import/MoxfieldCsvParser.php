<?php

declare(strict_types=1);

namespace App\Infrastructure\Import;

/**
 * Lee el CSV que exporta **Moxfield**, que es de donde salen las colecciones de
 * quien ya construye mazos en la web.
 *
 * Cabecera del formato:
 *
 * ```
 * Count, Tradelist Count, Name, Edition, Condition, Language, Foil, Tags,
 * Last Modified, Collector Number, Alter, Proxy, Purchase Price, Scryfall ID
 * ```
 *
 * Es el CSV que más se aparta del vocabulario común, y son tres cosas:
 *
 *  - la cantidad se llama **`Count`**, y `Tradelist Count` —cuántas de esas
 *    ofreces en cambio— **no es cantidad**: sumarla duplicaría la colección;
 *  - la edición se llama **`Edition`** y trae el código, no el nombre;
 *  - **`Foil` viene vacío cuando la carta no es foil**, en vez de decir
 *    `normal`. Como una celda vacía es «el fichero no afirma nada», cae sola al
 *    acabado por defecto, que es exactamente `normal`.
 *
 * La condición viene en los códigos propios de Moxfield (`M`, `NM`, `LP`, `MP`,
 * `HP`, `D`), no en el `snake_case` de ManaBox. Los mapea `Condition`.
 */
final class MoxfieldCsvParser extends CsvCollectionParser
{
    private const NOMBRE = 'moxfield';

    public function getName(): string
    {
        return self::NOMBRE;
    }

    /**
     * `Tradelist Count` es de Moxfield y de nadie más del ecosistema de Magic
     * (ManaBox exporta `Quantity` y Archidekt `Quantity` + `Finish`).
     *
     * @return string[]
     */
    protected function columnasHuella(): array
    {
        return ['tradelist count'];
    }

    /**
     * `Count` + `Edition` no los tiene ninguno de los otros dos: ManaBox usa
     * `Quantity`/`Set Code` y Archidekt `Quantity`/`Set Code`, así que la
     * detección sigue siendo inequívoca con los tres registrados.
     *
     * @return string[]
     */
    protected function columnasObligatorias(): array
    {
        return ['count', 'name', 'edition'];
    }

    /** @return array<string, string[]> */
    protected function mapaDeColumnas(): array
    {
        return [
            'quantity' => ['count'],
            'setCode'  => ['edition'],
            'finish'   => ['foil'],
        ];
    }
}
