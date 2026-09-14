<?php

declare(strict_types=1);

namespace App\Application\UseCase;

use App\Domain\Repository\UserRepositoryInterface;
use InvalidArgumentException;

/**
 * Buscar personas por el principio de su `username`, desde `/friends`.
 *
 * Es el M6 del Plan - Amigos y Seguimiento y **la puerta de entrada que a las
 * cinco acciones de amistad les faltaba**: `friend_request` va por nombre
 * exacto, así que hasta aquí solo se llegaba a alguien sabiéndoselo de memoria.
 *
 * ## Por qué esto es una acción `POST` y no una ruta `GET`
 *
 * Es la cuarta vez que el proyecto se hace esta pregunta y la respuesta ya
 * estaba escrita en el `CLAUDE.md` del repo, en el rechazo del «corazón relleno»
 * del catálogo: *«si vuelves a necesitar el catálogo pero sabiendo algo de mí,
 * la respuesta es una acción `POST` normal, no una ruta `GET` nueva»*. Los tres
 * criterios del desvío `GET` fallan los tres aquí: esto devuelve dato de
 * usuarios **filtrado por la privacidad de cada uno**, así que no es
 * reconstruible; cambia en cuanto alguien toca su panel, así que no es cacheable;
 * y no tiene ningún sentido compartirlo por URL.
 *
 * ## Los cuatro noes que este use case escribe a mano
 *
 *  - **Menos de tres caracteres: 422.** Sin este mínimo, `q=a` devuelve el
 *    censo. No lo puede hacer `ValidationMiddleware`, que solo mira si el campo
 *    está; y devolver una lista vacía en vez de un error sería mentir —hay
 *    resultados, simplemente no se van a enseñar—.
 *  - **Más de 32: 422.** `users.username` es `VARCHAR(32)`, así que un prefijo
 *    más largo no puede casar nada. Se dice en vez de devolver cero resultados,
 *    que se leería como «no hay nadie con ese nombre».
 *  - **Prefijo y no subcadena.** `LIKE 'juan%'` usa el índice del `UNIQUE` de
 *    `users.username`; `LIKE '%juan%'` es un full scan y, peor, haría el
 *    directorio enumerable desde cualquier letra interior — con tres letras
 *    cualesquiera se barrería a casi todo el mundo.
 *  - **Los comodines del cliente se escapan aquí.** `%` y `_` son comodines de
 *    `LIKE`, así que `q = '%%%'` mide tres caracteres, pasa el mínimo y
 *    devolvería **el censo entero**; `q = '___'` haría lo mismo con todos los
 *    nombres de tres letras. Escaparlos es lo único que convierte el mínimo de
 *    tres caracteres en una condición de verdad.
 *
 * ## Lo que este use case NO hace
 *
 * **No mira la privacidad.** El filtro por `show_in_search` va en el `WHERE` de
 * `buscarPorPrefijo()`, y tiene que ir ahí: un buscador resuelve N candidatos y
 * cada uno es un dueño distinto, así que preguntar por cada uno sería una
 * consulta por resultado — que es exactamente la razón por la que esa columna
 * tiene dos valores y no los tres de `Nivel`. Está explicado entero en
 * `App\Domain\Social\Descubrimiento`.
 *
 * **Y no dice en qué relación estás con nadie.** Ni amistad, ni seguimiento: la
 * respuesta es la misma para todo el mundo salvo por quién la pide (que se
 * excluye a sí mismo). Quien cruza el resultado con tus listas es el cliente,
 * con `relacionCon()` de `stores/friends.js`, que es el mismo precedente del M5.
 * Traerlo del backend habría obligado a leer `friendships` aquí — y este use
 * case no conoce ese repositorio ni tiene por dónde llegar a él.
 */
class BuscarUsuarios
{
    /**
     * El mínimo del hito: dos letras son un 422 y tres son una búsqueda.
     *
     * Con el escapado de comodines de abajo, esto significa de verdad «tres
     * caracteres de un nombre», y no «tres caracteres cualesquiera».
     */
    public const MINIMO = 3;

    /** `users.username` es `VARCHAR(32)`: más largo no casa nada. */
    public const MAXIMO = 32;

    /**
     * Cuántos resultados como mucho.
     *
     * Lo fija el servidor y **no se acepta del cliente**: un `limit` por el
     * cuerpo sería el otro extremo del mismo agujero que el mínimo de tres
     * caracteres cierra. Veinte caben en la pantalla y son suficientes para
     * encontrar a alguien cuyo nombre ya se está escribiendo; quien no aparezca
     * se encuentra escribiendo una letra más.
     */
    public const LIMITE = 20;

    public function __construct(
        private readonly UserRepositoryInterface $usuarios
    ) {
    }

    /**
     * @param  array<string, mixed> $peticion Payload del cliente
     * @return array{users: list<array{username: string, displayName: ?string, avatarUrl: ?string}>}
     * @throws InvalidArgumentException si `q` falta, se queda corto o se pasa de largo
     */
    public function __invoke(int $userId, array $peticion): array
    {
        $q = isset($peticion['q']) && is_string($peticion['q'])
            ? trim($peticion['q'])
            : throw new InvalidArgumentException('Falta lo que hay que buscar.');

        // `mb_strlen` y no `strlen`: un nombre con acentos mide en caracteres y
        // no en bytes, y contar bytes dejaría pasar «ñu» (3 bytes, 2 letras).
        if (mb_strlen($q) < self::MINIMO) {
            throw new InvalidArgumentException(
                'Escribe al menos ' . self::MINIMO . ' caracteres para buscar a alguien.'
            );
        }

        if (mb_strlen($q) > self::MAXIMO) {
            throw new InvalidArgumentException(
                'Un nombre de usuario no pasa de ' . self::MAXIMO . ' caracteres.'
            );
        }

        return ['users' => $this->usuarios->buscarPorPrefijo(
            $this->escaparComodines($q),
            $userId,
            self::LIMITE
        )];
    }

    /**
     * Dejar `%`, `_` y `\` sin poderes de comodín.
     *
     * **Es lo que hace que el mínimo de tres caracteres signifique algo.** Sin
     * esto, `q = '%%%'` mide tres, pasa la comprobación de arriba y `LIKE '%%%%'`
     * devuelve la tabla `users` entera; y `q = '___'` devuelve a todo el que
     * tenga un nombre de tres letras. El buscador que este hito puso una
     * puerta de privacidad para no ser, vamos.
     *
     * La barra invertida va **la primera** y no es un detalle de estilo: es el
     * carácter de escape de `LIKE` en MySQL, así que escaparla después
     * duplicaría las barras que las dos sustituciones siguientes acaban de
     * poner y el patrón buscaría literalmente `\%`.
     *
     * No hace falta cláusula `ESCAPE`: `\` es el escape por defecto de MySQL. Y
     * el valor viaja como parámetro enlazado, así que aquí no hay ningún
     * escapado de literal SQL de por medio — esto es escapado del PATRÓN, que es
     * otra capa y la que PDO no cubre.
     */
    private function escaparComodines(string $q): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $q);
    }
}
