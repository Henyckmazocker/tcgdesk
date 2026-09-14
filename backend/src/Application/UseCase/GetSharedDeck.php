<?php

declare(strict_types=1);

namespace App\Application\UseCase;

use App\Domain\Repository\DeckRepositoryInterface;

/**
 * **El mazo que hay detrás de un enlace compartido.**
 *
 * Es el único camino de lectura de toda la app en el que **quien autoriza es el
 * token y no el usuario**, y por eso está aislado en un use case propio en vez
 * de ser un parámetro más de `GetDeck`: la regla de que todo lo de mazos lleva
 * `user_id` se rompe aquí exactamente una vez, y conviene que se vea desde el
 * nombre del fichero.
 *
 * La resolución tiene dos pasos y el orden importa:
 *
 *  1. El token dice **de quién** es el mazo (`findByShareToken()`, el único
 *     método del repositorio sin `user_id`).
 *  2. Con ese `user_id` se lee el mazo por la puerta de siempre (`GetDeck`), que
 *     sí filtra por usuario.
 *
 * Así el mazo compartido no tiene una consulta propia que pudiera divergir de la
 * del dueño: **es literalmente el mismo mazo que ve él**, y lo que lo separa de
 * la respuesta privada es la lista blanca de `PublicHttpRouter` —el cruce con la
 * colección no entra aquí porque `GetDeck` no lo trae: lo añade
 * `DeckController::get()` llamando aparte a `AnalyzeDeckAvailability`, y ese
 * segundo paso **no se da nunca por este camino**—.
 *
 * **Se enseña entero e independientemente de la privacidad del perfil.** No
 * pregunta a `Visibilidad` y no debe: compartir un mazo es un acto explícito
 * sobre *ese* mazo, y hacerlo depender de `show_decks` significaría que cerrar
 * el perfil rompe en silencio los enlaces ya repartidos. Tampoco mira el
 * `status`: un mazo desmontado que sigue compartido sigue enseñándose, porque
 * el enlace lo mata `deck_unshare` y nada más.
 *
 * **El `null` es siempre el mismo `null`**: token mal formado, token que nunca
 * existió, token revocado o mazo borrado se responden igual, y con 404. Es la
 * diferencia con las secciones del perfil, que dan 403: allí el usuario existe
 * de todos modos —su `username` es público por diseño—, y aquí un 403 sería
 * decir «este token existe, pero», que es justo lo que el que prueba quiere
 * saber.
 */
class GetSharedDeck
{
    /**
     * El formato del token: 64 caracteres hexadecimales, los 32 bytes de
     * `ShareDeck`.
     *
     * Filtrar por la forma antes de consultar no es seguridad —quien decide es
     * el índice único— sino no mandar a la base de datos lo que no puede casar.
     * Acepta mayúsculas porque un token se copia y se pega a mano y la columna
     * es `utf8mb4_unicode_ci`, que ya compara sin distinguirlas: rechazar aquí
     * lo que MySQL sí resolvería daría un 404 incomprensible.
     */
    private const FORMA_DEL_TOKEN = '/^[0-9a-f]{64}$/i';

    public function __construct(
        private readonly DeckRepositoryInterface $mazos,
        private readonly GetDeck $ver
    ) {
    }

    /**
     * @return array{deck: array<string, mixed>, boards: array<string, list<array<string, mixed>>>,
     *               cards: list<array<string, mixed>>, valueEur: float,
     *               legality: array<string, mixed>}|null
     *         null si ese enlace no lleva a ningún mazo, por el motivo que sea
     */
    public function __invoke(string $token): ?array
    {
        if (preg_match(self::FORMA_DEL_TOKEN, $token) !== 1) {
            return null;
        }

        $donde = $this->mazos->findByShareToken($token);

        if ($donde === null) {
            return null;
        }

        // El `deck_id` no viene del cliente: sale del token. Es lo que hace que
        // aquí no haga falta comprobar nada más — nadie puede pedir el mazo 9007
        // de otro, porque el número no es suyo para elegirlo.
        return ($this->ver)($donde['userId'], ['deck_id' => $donde['deckId']]);
    }
}
