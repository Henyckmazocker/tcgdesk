<?php

declare(strict_types=1);

namespace App\Domain\Deck;

use InvalidArgumentException;

/**
 * En qué situación está un mazo: `built`, `building` o `dismantled`.
 *
 * **Los tres estados no son simétricos, y ahí está el motivo de que sea un
 * objeto de valor y no un string suelto:**
 *
 *  - `built` es el único que **consume** colección. Un mazo construido está en
 *    su caja y sus cartas no están disponibles para otro.
 *  - `building` no consume nada: solo sirve para calcular lo que falta.
 *  - `dismantled` es puro archivo — ni consume ni calcula.
 *
 * Escribir `WHERE status != 'dismantled'` por inercia mete los mazos en
 * construcción en el consumo, y entonces la app avisa de conflictos que no
 * existen. La distinción vive aquí para que las consultas puedan preguntar por
 * el caso concreto en vez de por la negación.
 *
 * El valor por defecto es `building` —el mismo que el DEFAULT de la columna—:
 * un mazo recién creado está vacío y todavía no puede estar montado.
 */
enum DeckStatus: string
{
    case Built      = 'built';
    case Building   = 'building';
    case Dismantled = 'dismantled';

    /**
     * Las formas alternativas que llegan de fuera.
     *
     * Corta a propósito: aquí no hay un ecosistema de exportadores como el de
     * `Condition`, así que solo se aceptan las variantes que sí se escriben
     * —las del propio usuario en castellano y el `in_progress` habitual— y
     * cualquier otra cosa da error en vez de caer en el defecto.
     */
    private const ALIAS = [
        'in progress'  => 'building',
        'in_progress'  => 'building',
        'wip'          => 'building',
        'construido'   => 'built',
        'montado'      => 'built',
        'construyendo' => 'building',
        'desmontado'   => 'dismantled',
    ];

    /** Lo que es un mazo recién creado: vacío, luego en construcción. */
    public static function porDefecto(): self
    {
        return self::Building;
    }

    /**
     * @param  mixed $valor Lo que venga del cliente
     * @throws InvalidArgumentException si no corresponde a ningún estado
     */
    public static function desde(mixed $valor): self
    {
        return self::tryFrom(self::normalizar($valor))
            ?? throw new InvalidArgumentException(
                'Estado de mazo no soportado: ' . (is_scalar($valor) ? (string) $valor : gettype($valor))
            );
    }

    /** Como `desde()`, pero null en vez de excepción. Para filtros opcionales. */
    public static function intentar(mixed $valor): ?self
    {
        if ($valor === null || $valor === '') {
            return null;
        }

        return self::tryFrom(self::normalizar($valor));
    }

    /**
     * Si un mazo en este estado **reclama** cartas de la colección.
     *
     * Solo `built`. Es la pregunta que hace el cruce del análisis de
     * disponibilidad, y tenerla aquí evita que cada consulta la reescriba a su
     * manera.
     */
    public function consumeColeccion(): bool
    {
        return $this === self::Built;
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
