<?php

declare(strict_types=1);

namespace App\Domain\Social;

use InvalidArgumentException;

/**
 * Cuánta gente ve una sección del perfil: `nobody`, `friends` o `everyone`.
 *
 * Los tres valores son los del ENUM de las cinco columnas de
 * `user_privacy_settings`, y es objeto de valor por el mismo motivo que
 * `DeckStatus`: **los tres niveles no son simétricos**, y la asimetría no la
 * puede recordar cada `if` por su cuenta.
 *
 *  - `Nadie` no se ve ni con sesión ni sin ella. Es el único que no admite
 *    excepciones salvo la del propio dueño, que `Visibilidad` resuelve antes.
 *  - `Todos` se ve **también sin sesión**: ese es el punto entero del plan.
 *  - `Amigos` es el único que depende de OTRA persona: lo resuelve
 *    `Visibilidad` preguntando a `FriendshipRepositoryInterface::sonAmigos()`,
 *    y solo una amistad `accepted` lo abre. Hasta el M2 del
 *    Plan - Amigos y Seguimiento era inerte —`friendships` no existía— y este
 *    enum lo decía con un método `esInerte()` que ese hito **borró**, porque
 *    dejarlo habría hecho que `Visibilidad` cortara antes de consultar la tabla
 *    y la amistad no se aplicara nunca.
 *
 * Quien traduce esto a un sí o un no es `Visibilidad`, y nadie más. Este enum
 * dice qué significa cada nivel; no decide.
 */
enum Nivel: string
{
    case Nadie  = 'nobody';
    case Amigos = 'friends';
    case Todos  = 'everyone';

    /**
     * Las formas alternativas que llegan de fuera.
     *
     * Corta a propósito, como la de `DeckStatus`: aquí no hay un ecosistema de
     * exportadores que normalizar, solo el panel de privacidad del M6 y la fila
     * de la base de datos. Cualquier otra cosa da error en vez de caer en un
     * defecto — y en un modelo de permisos, caer en un defecto silencioso es
     * exactamente cómo se publica lo que no se quería publicar.
     */
    private const ALIAS = [
        'none'     => 'nobody',
        'private'  => 'nobody',
        'nadie'    => 'nobody',
        'amigos'   => 'friends',
        'public'   => 'everyone',
        'everybody' => 'everyone',
        'todos'    => 'everyone',
    ];

    /**
     * @param  mixed $valor Lo que venga del cliente o de la fila de la BD
     * @throws InvalidArgumentException si no corresponde a ningún nivel
     */
    public static function desde(mixed $valor): self
    {
        return self::tryFrom(self::normalizar($valor))
            ?? throw new InvalidArgumentException(
                'Nivel de privacidad no soportado: ' . (is_scalar($valor) ? (string) $valor : gettype($valor))
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
