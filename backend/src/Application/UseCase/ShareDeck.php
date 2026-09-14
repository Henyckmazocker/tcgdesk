<?php

declare(strict_types=1);

namespace App\Application\UseCase;

use App\Domain\Repository\DeckRepositoryInterface;
use InvalidArgumentException;

/**
 * **Compartir un mazo por enlace**: generar su `share_token`, o regenerarlo.
 *
 * Lo único que hay que entender de este use case cabe en la primera línea del
 * método: el token son **32 bytes de `random_bytes()` en hexadecimal**, 64
 * caracteres, y no un `id`. Es la mitigación que el plan pone por escrito frente
 * al enumerado, y es **independiente del rate limit**: aunque el límite no
 * existiera, no hay diccionario que recorra 2^256. Un token derivado del
 * `deck_id`, o un contador, o un `uniqid()` —que es el reloj en hexadecimal—
 * convertirían la ruta pública en una lista de todos los mazos de la app.
 *
 * `random_bytes()` y no `rand()` ni `mt_rand()`: es el generador
 * criptográficamente seguro de PHP y **lanza** si el sistema no puede darle
 * entropía, en vez de devolver algo predecible. Ese fallo es un 500 a propósito:
 * un enlace público generado con entropía dudosa es peor que no generarlo.
 *
 * **Llamarlo otra vez REGENERA e invalida el anterior**, y esa es la operación
 * que se pidió: `mtg_deck.share_token` es una columna, no una tabla de enlaces,
 * así que el valor viejo desaparece al escribir el nuevo y deja de resolver.
 * Quien tuviera el enlace antiguo pasa a ver un 404, igual que con
 * `UnshareDeck`. No se guarda historial de tokens revocados justamente porque
 * guardarlos sería mantener vivo lo que el usuario quiso matar.
 *
 * El `user_id` viene de `AuthMiddleware` y nunca del cuerpo: compartir un mazo
 * es un acto sobre *tu* mazo, y `mtg_deck.id` es un autoincremental global.
 */
class ShareDeck
{
    /**
     * 32 bytes, los que el plan fija. En hexadecimal son los 64 caracteres que
     * mide `mtg_deck.share_token CHAR(64)`: si alguien sube este número, la
     * columna trunca **en silencio** y dos tokens distintos podrían colisionar.
     */
    public const BYTES_DEL_TOKEN = 32;

    /**
     * La ruta del frontend que abre el enlace, **relativa a propósito**.
     *
     * El backend no sabe en qué origen vive el frontend —en dev son dos puertos
     * distintos (`8094` y `8899`) y en producción será otro dominio— y lo único
     * que tendría a mano para adivinarlo es la cabecera `Host` de la petición,
     * que la manda el cliente y se puede falsificar: componer ahí la URL
     * absoluta sería dejar que un atacante eligiera el dominio del enlace que el
     * usuario va a copiar y pegar. Quien conoce su propio origen es el navegador,
     * así que el M6 compone la absoluta con `window.location.origin`.
     *
     * Lleva `/#/` porque el router del frontend es `createWebHashHistory`.
     */
    public const RUTA_PUBLICA = '/#/shared/deck/';

    public function __construct(
        private readonly DeckRepositoryInterface $mazos
    ) {
    }

    /**
     * @param  array<string, mixed> $peticion Payload del cliente
     * @return array{shareToken: string, url: string}|null null si el mazo no
     *         existe o no es de este usuario
     */
    public function __invoke(int $userId, array $peticion): ?array
    {
        $deckId = isset($peticion['deck_id']) && is_numeric($peticion['deck_id'])
            ? (int) $peticion['deck_id']
            : throw new InvalidArgumentException('Falta el deck_id del mazo.');

        $token = bin2hex(random_bytes(self::BYTES_DEL_TOKEN));

        // El token se genera antes de saber si el mazo es suyo y se tira si no
        // lo es: generar es gratis, y consultarlo primero no cambiaría nada
        // —quien decide es el `WHERE user_id` del repositorio—.
        if (!$this->mazos->fijarShareToken($userId, $deckId, $token)) {
            return null;
        }

        return [
            'shareToken' => $token,
            'url'        => self::RUTA_PUBLICA . $token,
        ];
    }
}
