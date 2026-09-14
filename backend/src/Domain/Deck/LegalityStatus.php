<?php

declare(strict_types=1);

namespace App\Domain\Deck;

/**
 * El estatus de una carta en un formato, tal y como lo trae MTGJSON.
 *
 * Los cuatro valores son los del ENUM `mtg_legality.status`, pero **la ingesta
 * solo escribe tres**: `SELECT DISTINCT status FROM mtg_legality` devuelve
 * `legal`, `banned` y `restricted`, y nada más. `not_legal` **no es un valor que
 * nadie escriba: es la AUSENCIA de fila**. `mtg_legality` solo trae los formatos
 * en los que la carta *tiene* estatus; si no hay fila para ese formato, la carta
 * no es legal ahí.
 *
 * De ahí salen las dos reglas del hito de legalidad:
 *
 *  - **El cruce va con `LEFT JOIN`, jamás con `JOIN`.** Con un `JOIN` a secas las
 *    cartas no legales **desaparecen de la lista** en vez de marcarse, que es
 *    justo el fallo contrario al que el aviso busca. La ausencia entra aquí como
 *    un `null` y sale convertida en `NotLegal` (ver `desdeFila()`).
 *  - **`restricted` también se avisa.** Sale del mismo `LEFT JOIN` y callarlo
 *    diría «legal» de una carta que no lo es sin más —*Sol Ring* en Vintage—.
 *    **Se marca y ya**: la regla de «máximo 1 copia» no se implementa, porque
 *    eso sería validar y esto solo avisa.
 *
 * Y la regla que este enum no tiene: **nada de esto bloquea nada**. Un mazo
 * ilegal es un mazo que existe, se guarda igual y se enseña igual; lo único que
 * cambia es que lleva una marca.
 */
enum LegalityStatus: string
{
    case Legal      = 'legal';
    case NotLegal   = 'not_legal';
    case Restricted = 'restricted';
    case Banned     = 'banned';

    /**
     * Lo que devuelve el `LEFT JOIN`, convertido en estatus.
     *
     * **`null` es `not_legal`**, y es el caso normal, no el raro: de los 21
     * formatos que conoce `mtg_legality`, una carta cualquiera tiene fila en
     * unos pocos. Un valor desconocido cae también en `not_legal` en vez de
     * lanzar: el catálogo se reingiere entero cada mes y un estatus nuevo de
     * MTGJSON no puede tumbar la ficha de un mazo, solo marcarla de más.
     */
    public static function desdeFila(?string $status): self
    {
        return $status === null ? self::NotLegal : (self::tryFrom($status) ?? self::NotLegal);
    }

    /**
     * Si hay algo que decirle al usuario sobre esta carta.
     *
     * Todo lo que no sea `legal`: `banned`, `restricted` y `not_legal`. Lo legal
     * no se marca —marcar las 100 cartas de un Commander legal sería ruido, no
     * aviso—.
     */
    public function esAviso(): bool
    {
        return $this !== self::Legal;
    }
}
