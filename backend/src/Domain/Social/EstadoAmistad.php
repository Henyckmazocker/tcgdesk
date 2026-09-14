<?php

declare(strict_types=1);

namespace App\Domain\Social;

use InvalidArgumentException;

/**
 * En qué punto está una fila de `friendships`: pedida o aceptada. **Dos casos, y
 * que sean dos es la decisión más importante de todo el Plan - Amigos y
 * Seguimiento.**
 *
 *  - **No hay `rejected`.** Rechazar hace `DELETE`, y no es una simplificación:
 *    con el `UNIQUE (user_low, user_high)` simétrico del esquema, una fila
 *    `rejected` **impediría volver a pedir amistad para siempre**. El bloqueo
 *    permanente entraría por la puerta de atrás, sin que nadie lo hubiera
 *    decidido y sin aparecer en ninguna pantalla. Bloquear está explícitamente
 *    fuera del alcance del plan; si alguien echa de menos un `rejected`, lo que
 *    quiere de verdad es eso.
 *  - **`Pendiente` NO es amistad.** Es el fallo que el plan señala como el más
 *    fácil de escribir y el más difícil de ver: si `sonAmigos()` preguntara «¿hay
 *    fila entre estos dos?» en vez de «¿hay fila **aceptada**?», *pedir* amistad
 *    bastaría para ver el nivel `friends` de alguien que no ha dicho que sí. Y
 *    funcionaría perfectamente en toda prueba manual, porque quien prueba
 *    acepta la solicitud. Por eso el estado es un tipo y no un `string` suelto:
 *    una comparación con un literal mal escrito (`'accept'`) devuelve `false` en
 *    silencio; `EstadoAmistad::desde('accept')` revienta.
 *
 * Los dos valores son literalmente los del `ENUM('pending','accepted')` de la
 * migración `20260914_120000_friendships.sql`. Si alguna vez divergen, el
 * `INSERT` empieza a fallar o —peor— MySQL trunca al valor vacío.
 */
enum EstadoAmistad: string
{
    case Pendiente = 'pending';
    case Aceptada  = 'accepted';

    /**
     * Lo que traiga la fila de la base de datos, convertido o reventado.
     *
     * Sin alias y sin defecto, al revés que `Nivel::intentar()`: aquí no hay
     * nada que venga del cliente —el estado no se manda nunca, lo mueven
     * `AceptarAmistad` y el `DEFAULT 'pending'` de la tabla—, así que un valor
     * que no case solo puede ser una base de datos a medio migrar. Caer a un
     * defecto ahí sería elegir entre «todos son amigos» y «nadie lo es» a
     * ciegas, y la primera publica datos.
     *
     * @throws InvalidArgumentException si no corresponde a ningún estado
     */
    public static function desde(mixed $valor): self
    {
        if ($valor instanceof self) {
            return $valor;
        }

        return self::tryFrom(is_scalar($valor) ? (string) $valor : '')
            ?? throw new InvalidArgumentException(
                'Estado de amistad no soportado: ' . (is_scalar($valor) ? (string) $valor : gettype($valor))
            );
    }
}
