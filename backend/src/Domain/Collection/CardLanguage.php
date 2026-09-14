<?php

declare(strict_types=1);

namespace App\Domain\Collection;

use InvalidArgumentException;

/**
 * El idioma de un ejemplar, con el **nombre largo de MTGJSON**.
 *
 * `'Spanish'`, nunca `'es'`. No es cosmético: es exactamente la forma que
 * guarda `mtg_printing_localized.language`, y esa tabla es con la que hay que
 * casar para enseñar el nombre traducido de la carta. Si aquí se guardara el
 * código ISO, el `JOIN` no casaría ni una fila y nadie vería un error: solo
 * faltarían los nombres traducidos.
 *
 * Y como el idioma entra en el `UNIQUE KEY` de `mtg_collection_item`, 'es',
 * 'Spanish' y 'spanish' crearían **tres filas** para la misma carta. Aquí se
 * normaliza una vez y se acabó.
 *
 * La lista sale del catálogo real: los diez idiomas de imprenta más los siete
 * "idiomas de sabor" que MTGJSON trae en cartas sueltas (el fenicio de las
 * cartas phyrexianas, el quenya de El Señor de los Anillos, y los cinco de las
 * reimpresiones antiguas). `English` no aparece en `mtg_printing_localized`
 * —es el nombre base de `mtg_card`— pero es el valor por defecto de la columna.
 */
enum CardLanguage: string
{
    case English            = 'English';
    case Spanish            = 'Spanish';
    case French             = 'French';
    case German             = 'German';
    case Italian            = 'Italian';
    case Portuguese         = 'Portuguese (Brazil)';
    case Japanese           = 'Japanese';
    case Korean             = 'Korean';
    case Russian            = 'Russian';
    case ChineseSimplified  = 'Chinese Simplified';
    case ChineseTraditional = 'Chinese Traditional';
    case Phyrexian          = 'Phyrexian';
    case Quenya             = 'Quenya';
    case AncientGreek       = 'Ancient Greek';
    case Arabic             = 'Arabic';
    case Hebrew             = 'Hebrew';
    case Latin              = 'Latin';
    case Sanskrit           = 'Sanskrit';

    /**
     * Códigos y formas cortas → nombre largo de MTGJSON.
     *
     * Los códigos son los de Scryfall, que es lo que traen los exportadores de
     * colección más habituales; las claves van en minúsculas.
     */
    private const ALIAS = [
        'en'                  => 'English',
        'eng'                 => 'English',
        'es'                  => 'Spanish',
        'sp'                  => 'Spanish',
        'castellano'          => 'Spanish',
        'fr'                  => 'French',
        'de'                  => 'German',
        'it'                  => 'Italian',
        'pt'                  => 'Portuguese (Brazil)',
        'pt-br'               => 'Portuguese (Brazil)',
        'portuguese'          => 'Portuguese (Brazil)',
        'portuguese (brazil)' => 'Portuguese (Brazil)',
        'ja'                  => 'Japanese',
        'jp'                  => 'Japanese',
        'ko'                  => 'Korean',
        'ru'                  => 'Russian',
        'zhs'                 => 'Chinese Simplified',
        'cs'                  => 'Chinese Simplified',
        'zht'                 => 'Chinese Traditional',
        'ct'                  => 'Chinese Traditional',
        'ph'                  => 'Phyrexian',
        'grc'                 => 'Ancient Greek',
        'ar'                  => 'Arabic',
        'he'                  => 'Hebrew',
        'la'                  => 'Latin',
        'sa'                  => 'Sanskrit',
    ];

    /** Lo que asume el botón "Añadir" de la ficha de catálogo. */
    public static function porDefecto(): self
    {
        return self::English;
    }

    /**
     * @param  mixed $valor Lo que venga del cliente o del fichero importado
     * @throws InvalidArgumentException si no es un idioma del catálogo
     */
    public static function desde(mixed $valor): self
    {
        $normalizado = self::normalizar($valor);

        return self::tryFrom($normalizado)
            ?? throw new InvalidArgumentException(
                'Idioma no soportado: ' . (is_scalar($valor) ? (string) $valor : gettype($valor))
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

        $texto = is_scalar($valor) ? (string) $valor : '';
        $texto = trim(preg_replace('/\s+/', ' ', $texto) ?? '');
        $clave = strtolower($texto);

        if (isset(self::ALIAS[$clave])) {
            return self::ALIAS[$clave];
        }

        // 'chinese traditional' → 'Chinese Traditional'. ucwords deja intactos
        // los paréntesis de 'Portuguese (Brazil)', que ya cubre el alias.
        return ucwords($clave);
    }
}
