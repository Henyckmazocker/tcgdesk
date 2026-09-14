<?php

declare(strict_types=1);

namespace App\Domain\Social;

use InvalidArgumentException;

/**
 * Si te puede encontrar el buscador de usuarios de `/friends`: `nobody` o
 * `everyone`. La sexta columna de `user_privacy_settings` (`show_in_search`).
 *
 * ## POR QUÉ ESTO NO ES UN `Nivel`, Y POR QUÉ NO ES UNA `Seccion`
 *
 * Es la pregunta que decide el M6 entero del Plan - Amigos y Seguimiento, y la
 * respuesta se cerró con David el 2026-09-14. Hay tres razones, de menor a
 * mayor:
 *
 *  1. **Tiene dos valores y no tres.** `friends` como valor válido obligaría a
 *     que cada resultado del buscador resolviera su propio `sonAmigos()`: las
 *     otras cinco secciones resuelven UN dueño por petición —y por eso la caché
 *     de `Visibilidad` es por dueño— mientras que un buscador resuelve N
 *     candidatos y cada fila es un dueño distinto. Con dos valores, el filtro es
 *     una condición del `WHERE` y la búsqueda entera cuesta UNA consulta. Y aquí
 *     `friends` sería casi redundante: a quien ya es tu amigo lo tienes listado
 *     en `/friends` sin buscarlo.
 *  2. **No la resuelve `Visibilidad`.** Las cinco secciones contestan «¿ve ESTE
 *     espectador ESTA sección de ESTE dueño?», y eso lo decide
 *     `Visibilidad::puedeVer()` y nadie más. Esto contesta otra cosa —«¿sales en
 *     una lista?»— que **no depende de quién pregunte**, exactamente igual que
 *     el «¿tiene este perfil cara pública?» de `Seguir`. Un `case` más en
 *     `Seccion` habría hecho llamable `puedeVer(Seccion::Busqueda, …)`, que es
 *     una pregunta sin respuesta correcta.
 *  3. **Y la tercera es la que lo cierra: meterla en `Seccion` habría roto el
 *     M3 en silencio.** `MySqlUserPrivacyRepository::nivelesDe()` recorre
 *     `Seccion::cases()` y promete «siempre todas», y `Seguir::tieneCaraPublica()`
 *     recorre justo ese array buscando **un** `Nivel::Todos` para decidir si un
 *     perfil se puede seguir. Con una sexta entrada que nace en `everyone`,
 *     **todo** perfil habría pasado a tener cara pública —también uno con las
 *     cinco secciones en `nobody`— y el 422 que el M3 fijó como su *Hecho
 *     cuando:* se habría caído sin un solo error. Además, `guardarNiveles()`
 *     construye su `INSERT … ON DUPLICATE KEY UPDATE` sobre ese mismo bucle y
 *     habría intentado escribir `friends` en un ENUM que solo admite dos
 *     valores.
 *
 * Así que vive aparte: su propio objeto de valor, sus dos métodos en
 * `UserPrivacyRepositoryInterface` y su propia clave en la respuesta de
 * `privacy_get`. Lo que comparte con las otras cinco es la fila y el panel, y
 * nada más.
 *
 * ## El defecto está en TRES sitios
 *
 * `porDefecto()` es uno de ellos. Los otros dos son el `DEFAULT 'everyone'` de
 * la migración `20260914_180000_show_in_search.sql` y el
 * `COALESCE(p.show_in_search, 'everyone')` del `WHERE` de
 * `MySqlUserRepository::buscarPorPrefijo()`. **Si se cambia uno, se cambian los
 * tres**, y el motivo es el mismo que el de `Seccion::nivelPorDefecto()`: la
 * ausencia de fila SIGNIFICA los defectos, y quien no tiene fila nunca llega a
 * leer el `DEFAULT` de la columna. Hoy no tiene fila absolutamente nadie.
 *
 * ## Y por qué el defecto es `everyone`
 *
 * Es la única de las seis que nace abierta sin ser una sección de contenido. El
 * `username` **ya es** la URL pública del perfil, así que aparecer en el
 * buscador no publica nada que no estuviera publicado; y un buscador que nace
 * vacío se queda vacío para siempre, porque nadie entra en el panel de
 * privacidad a activar algo cuya existencia desconoce.
 */
enum Descubrimiento: string
{
    case Nadie = 'nobody';
    case Todos = 'everyone';

    /**
     * La columna de `user_privacy_settings` que guarda esto.
     *
     * Constante y no un método como `Seccion::columna()` porque aquí no hay
     * familia que recorrer: es una columna y una sola. La usa
     * `MySqlUserPrivacyRepository` y nadie más — el prefijo `show_` es cosa del
     * esquema y no sale en la respuesta de ninguna acción.
     */
    public const COLUMNA = 'show_in_search';

    /**
     * La clave con la que viaja en el JSON de `privacy_get` y `privacy_set`.
     *
     * `search` y no `show_in_search`, por lo mismo que `collection` y no
     * `show_collection`: dejar salir el nombre físico de la columna ataría el
     * contrato del panel al esquema de la tabla.
     */
    public const CLAVE = 'search';

    /**
     * Las formas alternativas que llegan de fuera, cortas a propósito como las
     * de `Nivel`.
     *
     * Incluyen los dos valores que `Nivel` tiene y este enum no (`friends` y sus
     * alias) **a propósito ausentes**: si el panel mandara `friends` aquí, lo
     * correcto es un 422 y no caer a ninguno de los dos lados. Caer a `everyone`
     * publicaría a quien pidió no salir, y caer a `nobody` escondería a quien no
     * lo pidió; las dos son mentira, así que la respuesta es que no se pueda.
     */
    private const ALIAS = [
        'none'      => 'nobody',
        'private'   => 'nobody',
        'nadie'     => 'nobody',
        'no'        => 'nobody',
        'public'    => 'everyone',
        'everybody' => 'everyone',
        'todos'     => 'everyone',
        'si'        => 'everyone',
        'sí'        => 'everyone',
    ];

    /**
     * Lo que se aplica **cuando el usuario no tiene fila**, que hoy es todo el
     * mundo. Ver la cabecera: está duplicado en la migración y en el `COALESCE`
     * del buscador, y los tres se cambian juntos.
     */
    public static function porDefecto(): self
    {
        return self::Todos;
    }

    /**
     * @param  mixed $valor Lo que venga del cliente o de la fila de la BD
     * @throws InvalidArgumentException si no corresponde a ninguno de los dos
     */
    public static function desde(mixed $valor): self
    {
        return self::tryFrom(self::normalizar($valor))
            ?? throw new InvalidArgumentException(
                'Valor de show_in_search no soportado: '
                . (is_scalar($valor) ? (string) $valor : gettype($valor))
                . '. Solo hay dos: nobody o everyone.'
            );
    }

    /** Como `desde()`, pero null en vez de excepción. Para entradas opcionales. */
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
        $texto = strtolower(trim(preg_replace('/\s+/', ' ', $texto) ?? ''));

        return self::ALIAS[$texto] ?? $texto;
    }
}
