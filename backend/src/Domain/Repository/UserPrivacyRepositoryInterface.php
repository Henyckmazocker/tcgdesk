<?php

declare(strict_types=1);

namespace App\Domain\Repository;

use App\Domain\Social\Descubrimiento;
use App\Domain\Social\Nivel;

/**
 * Los niveles de privacidad de un usuario: qué se ve de él y cuánto.
 *
 * **Este puerto no responde «¿puede verlo?», responde «¿qué tiene puesto?».**
 * La decisión es de `App\Domain\Social\Visibilidad` y de nadie más: cinco
 * comprobaciones repartidas por cinco use cases es cómo se acaba filtrando una
 * sección. Quien consulte este repositorio directamente para decidir si enseña
 * algo se está saltando esa regla.
 *
 * Puerto aparte de `UserRepositoryInterface` aunque la tabla cuelgue de
 * `users.id`: son dos agregados con ciclos de vida distintos —la fila de
 * privacidad puede no existir y el usuario sí— y mezclarlos obligaría a que
 * todo el que lee un usuario arrastrase sus permisos.
 */
interface UserPrivacyRepositoryInterface
{
    /**
     * Los cinco niveles del usuario, **siempre los cinco**.
     *
     * Un usuario sin fila en `user_privacy_settings` no es un error ni un null:
     * es un usuario con los valores por defecto, y así lo devuelve. De ahí que
     * no haya `?array` ni un `existe()` — el alta de usuario no crea la fila y
     * ningún llamador debería tener que saberlo.
     *
     * La clave es `Seccion->value` (`collection`, `value`, `decks`, `sets`,
     * `wishlist`), no el nombre de la columna, y no es `Seccion` porque un enum
     * no puede ser clave de array en PHP.
     *
     * @return array<string, Nivel>
     */
    public function nivelesDe(int $userId): array;

    /**
     * Guardar **solo las secciones que cambian**, dejando las demás como estén.
     *
     * Es una edición parcial igual que `deck_update`: el panel del M6 manda el
     * selector que el usuario acaba de tocar y ninguno más. Lo que no puede
     * pasar —y es exactamente lo que el hito exige comprobar— es que cambiar
     * `value` devuelva las otras cuatro secciones al defecto: quien puso su
     * colección en `nobody` la tendría publicada otra vez sin haber tocado ese
     * selector.
     *
     * `$cambios` viene indexado por `Seccion->value` y ya convertido a `Nivel`,
     * nunca con texto crudo del cliente: quien traduce lo que llegó por HTTP es
     * el use case, y traducirlo antes de aquí es lo que garantiza que un nivel
     * inventado sea un 422 y no una columna escrita a medias. Un array vacío no
     * es un error, es un no-op — pero el use case lo rechaza antes, porque un
     * `privacy_set` que no cambia nada es una petición sin sentido.
     *
     * **Guardar `friends` es válido**, aunque hoy `Visibilidad` no se lo conceda
     * a nadie: lo inerte es resolverlo, no almacenarlo. El día que exista
     * `friendships` esas filas empiezan a significar algo sin migrar nada.
     *
     * Devuelve **los cinco niveles ya guardados**, no los que se pidieron, por
     * el mismo motivo por el que `nivelesDe()` devuelve siempre cinco: el
     * llamador no tiene que recomponer el estado juntando lo que mandó con lo
     * que creía que había.
     *
     * @param  array<string, Nivel> $cambios sección → nivel, solo las que cambian
     * @return array<string, Nivel> las cinco, tal como quedaron
     */
    public function guardarNiveles(int $userId, array $cambios): array;

    /**
     * ¿Sale este usuario en el buscador de `/friends`?
     *
     * **Dos métodos aparte de los dos de arriba, y no una sexta clave dentro de
     * `nivelesDe()`.** La columna vive en la misma fila, pero no es una sección
     * de contenido y su valor no es un `Nivel`: son dos valores, no tres, y
     * quien lo explica entero es `App\Domain\Social\Descubrimiento`. Meterla
     * en aquel array habría roto `Seguir::tieneCaraPublica()`, que lo recorre
     * buscando un `everyone` para decidir si un perfil se puede seguir — con una
     * sexta entrada abierta por defecto, TODO perfil habría pasado a tener cara
     * pública y el 422 del M3 se habría caído en silencio.
     *
     * Igual que `nivelesDe()`: **un usuario sin fila no es un error ni un null**,
     * es un usuario con el defecto, y hoy eso es todo el mundo. Por eso el tipo
     * de retorno no es anulable.
     */
    public function descubrimientoDe(int $userId): Descubrimiento;

    /**
     * Guardar si sales en el buscador, **sin tocar las otras cinco columnas**.
     *
     * Es una edición parcial por lo mismo que `guardarNiveles()`: el panel manda
     * el selector que el usuario acaba de mover y ninguno más. Cuando la fila no
     * existe —el caso de todo el mundo hoy— la crea, y las otras cinco columnas
     * se quedan con el `DEFAULT` del esquema, que es **el mismo** que
     * `Seccion::nivelPorDefecto()` devuelve para cada una: un usuario que solo
     * toque este selector responde exactamente lo mismo que antes de tocarlo en
     * las otras cinco secciones.
     *
     * Devuelve lo que quedó en la tabla, no lo que se pidió, por lo mismo que
     * `guardarNiveles()`: si el ENUM rechazara un valor, la mezcla mentiría.
     */
    public function guardarDescubrimiento(int $userId, Descubrimiento $valor): Descubrimiento;
}
