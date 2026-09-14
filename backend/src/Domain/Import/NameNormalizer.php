<?php

declare(strict_types=1);

namespace App\Domain\Import;

/**
 * Convierte un nombre de carta —el del catálogo o el que teclea el usuario— en
 * la **clave exacta** con la que se comparan los dos: `mtg_card.name_normalized`.
 *
 * Es la respuesta del plan a que MySQL no tenga búsqueda por similitud: en vez de
 * medir parecidos, se lleva a las dos partes a la misma forma canónica y se exige
 * igualdad. `Lim-Dûl's Vault` y `Lim-Dul's Vault` normalizan a
 * `lim dul s vault`; a partir de ahí no hay nada que adivinar.
 *
 * ## Las tres reglas que no son obvias
 *
 * 1. **Los blancos `_____` se conservan.** Es la causa del único fallo silencioso
 *    que midió M0: tratándolos como puntuación, `_____ Goblin` (Unfinity)
 *    colapsaba a la clave `goblin` y se apropiaba de lo que el usuario teclea al
 *    escribir «Goblin» —y la clave `goblin` tiene una sola carta detrás, así que
 *    la regla de «colisión → conflicto» no lo salvaba por sí sola—. Conservados,
 *    teclear `Goblin` no casa por el paso 3, cae al paso 4 y sale conflicto.
 *    Las 15 cartas con blancos siguen en el índice y siguen siendo importables
 *    tecleando su nombre real, por Scryfall ID o por set + número.
 *
 * 2. **El separador ` // ` sobrevive.** Las 501 de 501 cartas
 *    `transform`/`modal_dfc` guardan las dos caras en `name` y el usuario teclea
 *    solo la frontal. Manteniéndolo, el paso 3b del resolvedor es un prefijo
 *    sobre el mismo índice (`... LIKE 'delver of secrets // %'`) en vez de una
 *    segunda columna.
 *
 * 3. **La transliteración es una tabla propia.** No hay `ext/intl` en el
 *    contenedor: `Normalizer::normalize()` no existe. La tabla cubre los 97
 *    nombres no-ASCII del catálogo sin dejar un carácter fuera. `Æ` no aparece en
 *    este catálogo (MTGJSON trae ya `Aether Vial`), pero el mapeo se queda por si
 *    un usuario teclea la grafía antigua.
 *
 * No tiene estado ni dependencias: lo usan el resolvedor, el backfill
 * (`catalog:normalize`) y la ingesta (`MtgJsonMapper`), y los tres tienen que
 * producir exactamente la misma clave o la columna deja de servir para nada.
 */
final class NameNormalizer
{
    /** Separador canónico entre caras, ya normalizado. */
    public const SEPARADOR = ' // ';

    /**
     * Transliteración explícita, con las claves ya en minúsculas porque se
     * aplica DESPUÉS de `mb_strtolower()`.
     *
     * @var array<string, string>
     */
    private const TRANSLITERACION = [
        'æ' => 'ae', 'œ' => 'oe', 'ß' => 'ss', 'ø' => 'o', 'þ' => 'th', 'ð' => 'd', 'ł' => 'l',
        'á' => 'a', 'à' => 'a', 'â' => 'a', 'ä' => 'a', 'ã' => 'a', 'å' => 'a', 'ā' => 'a', 'ă' => 'a', 'ą' => 'a',
        'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e', 'ē' => 'e', 'ĕ' => 'e', 'ė' => 'e', 'ę' => 'e', 'ě' => 'e',
        'í' => 'i', 'ì' => 'i', 'î' => 'i', 'ï' => 'i', 'ī' => 'i', 'į' => 'i', 'ı' => 'i',
        'ó' => 'o', 'ò' => 'o', 'ô' => 'o', 'ö' => 'o', 'õ' => 'o', 'ō' => 'o', 'ő' => 'o',
        'ú' => 'u', 'ù' => 'u', 'û' => 'u', 'ü' => 'u', 'ū' => 'u', 'ů' => 'u', 'ű' => 'u', 'ų' => 'u',
        'ñ' => 'n', 'ń' => 'n', 'ň' => 'n', 'ç' => 'c', 'ć' => 'c', 'č' => 'c',
        'ś' => 's', 'š' => 's', 'ş' => 's', 'ź' => 'z', 'ż' => 'z', 'ž' => 'z',
        'ý' => 'y', 'ÿ' => 'y', 'ř' => 'r', 'ť' => 't', 'ď' => 'd', 'ğ' => 'g',
        '®' => '', '™' => '', '©' => '', '…' => ' ', '—' => ' ', '–' => ' ',
    ];

    /** Apóstrofes: se BORRAN, no pasan a espacio (`Thrór's Map` → `thrors map`). */
    private const APOSTROFES = ["'", "\u{2019}", "\u{2018}", "\u{02bc}", '`', '´'];

    /**
     * Clave completa del nombre, con todas sus caras.
     *
     * @return string Cadena vacía si no queda nada normalizable (un nombre que
     *                era solo puntuación); nunca se devuelve NULL para que la
     *                columna distinga «no indexada» (NULL) de «no queda nada».
     */
    public function normalizar(string $nombre): string
    {
        $caras = $this->partirEnCaras($nombre);

        $normalizadas = [];
        foreach ($caras as $cara) {
            $clave = $this->normalizarCara($cara);
            if ($clave !== '') {
                $normalizadas[] = $clave;
            }
        }

        return implode(self::SEPARADOR, $normalizadas);
    }

    /**
     * Clave de la **cara frontal**: lo que el usuario teclea de una carta de
     * doble cara. Es la mitad izquierda de `normalizar()`, y de un nombre de una
     * sola cara es la clave entera.
     */
    public function caraFrontal(string $nombre): string
    {
        return $this->normalizarCara($this->partirEnCaras($nombre)[0] ?? '');
    }

    /** ¿La clave ya normalizada tiene más de una cara? */
    public function tieneVariasCaras(string $clave): bool
    {
        return str_contains($clave, self::SEPARADOR);
    }

    /**
     * Parte por `//` tolerando que el usuario no ponga espacios alrededor.
     *
     * @return list<string>
     */
    private function partirEnCaras(string $nombre): array
    {
        return preg_split('/\s*\/\/\s*/u', trim($nombre)) ?: [];
    }

    private function normalizarCara(string $cara): string
    {
        $clave = mb_strtolower(trim($cara), 'UTF-8');
        $clave = strtr($clave, self::TRANSLITERACION);
        $clave = str_replace(self::APOSTROFES, '', $clave);

        // Todo lo que no sea letra, número, guion bajo o espacio pasa a espacio.
        // El guion bajo está en la lista A PROPÓSITO: es el blanco de las Un-sets.
        $clave = preg_replace('/[^\p{L}\p{N}_\s]+/u', ' ', $clave) ?? '';

        return preg_replace('/\s+/u', ' ', trim($clave)) ?? '';
    }
}
