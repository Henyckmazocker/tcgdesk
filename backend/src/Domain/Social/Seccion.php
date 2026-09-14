<?php

declare(strict_types=1);

namespace App\Domain\Social;

use InvalidArgumentException;

/**
 * Qué parte del perfil se está mirando: las cinco de `user_privacy_settings`.
 *
 * Es el argumento que distingue una llamada a `Visibilidad::puedeVer()` de
 * otra, y por eso es un enum y no un string: **con un string, un `'colection'`
 * mal escrito no casaría ninguna columna y habría que decidir en tiempo de
 * ejecución si eso significa que sí o que no**. Cualquiera de las dos
 * respuestas está mal; lo correcto es que no se pueda escribir.
 *
 * El valor NO es el nombre de la columna. La columna se llama `show_collection`
 * y la sección se llama `collection`: el prefijo `show_` es cosa del esquema, y
 * dejarlo salir en la respuesta de `privacy_get` o en la URL del perfil ataría
 * el contrato público al nombre físico de la tabla. La traducción de una cosa a
 * la otra vive en `columna()` y solo la usa `MySqlUserPrivacyRepository`.
 */
enum Seccion: string
{
    case Coleccion = 'collection';
    case Valor     = 'value';
    case Mazos     = 'decks';
    case Sets      = 'sets';
    case Deseos    = 'wishlist';

    /**
     * Los nombres de columna, aceptados como entrada.
     *
     * Es lo que trae una fila de `user_privacy_settings` tal cual la lee PDO, y
     * lo que manda el panel del M6 si alguien decide copiar el nombre de la
     * columna al formulario. No hay más alias: aquí no hay ficheros de terceros
     * que normalizar, y un alias inventado en un modelo de permisos es una
     * sección que se enseña por confundir su nombre con el de otra.
     */
    private const ALIAS = [
        'show_collection' => 'collection',
        'show_value'      => 'value',
        'show_decks'      => 'decks',
        'show_sets'       => 'sets',
        'show_wishlist'   => 'wishlist',
    ];

    /**
     * @param  mixed $valor Lo que venga del cliente o de la fila de la BD
     * @throws InvalidArgumentException si no corresponde a ninguna sección
     */
    public static function desde(mixed $valor): self
    {
        return self::tryFrom(self::normalizar($valor))
            ?? throw new InvalidArgumentException(
                'Sección de perfil no soportada: ' . (is_scalar($valor) ? (string) $valor : gettype($valor))
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

    /** La columna de `user_privacy_settings` que guarda el nivel de esta sección. */
    public function columna(): string
    {
        return 'show_' . $this->value;
    }

    /**
     * El nivel que se aplica **cuando el usuario no tiene fila**.
     *
     * Está duplicado respecto al `DEFAULT` de la migración
     * (`20260913_120000_public_profile.sql`) y no hay forma de evitarlo: quien no
     * tiene fila nunca llega a leer el defecto de la columna, y crear la fila en
     * el alta solo para eso obligaría a meter mano en el registro de usuarios y
     * dejaría a los usuarios ya existentes sin ella igualmente. **Si se cambia
     * un defecto allí, se cambia aquí**; lo que los mantiene honestos es
     * `MySqlUserPrivacyRepository`, que lee las dos cosas del mismo sitio en
     * cuanto la fila existe.
     *
     * `Valor` y `Deseos` nacen en `friends` a propósito, y eso no se
     * «uniformiza»: cuánto dinero tienes en cartas es información patrimonial, y
     * lo que te falta es por dónde te va a regatear un desconocido.
     */
    public function nivelPorDefecto(): Nivel
    {
        return match ($this) {
            self::Coleccion => Nivel::Todos,
            self::Valor     => Nivel::Amigos,
            self::Mazos     => Nivel::Todos,
            self::Sets      => Nivel::Todos,
            self::Deseos    => Nivel::Amigos,
        };
    }

    /**
     * Los cinco niveles por defecto, indexados por sección.
     *
     * Lo que devuelve el repositorio cuando no hay fila, y la base sobre la que
     * se pisan las columnas cuando sí la hay. La clave es `Seccion->value`
     * porque un enum no puede ser clave de array en PHP.
     *
     * @return array<string, Nivel>
     */
    public static function nivelesPorDefecto(): array
    {
        $niveles = [];

        foreach (self::cases() as $seccion) {
            $niveles[$seccion->value] = $seccion->nivelPorDefecto();
        }

        return $niveles;
    }
}
