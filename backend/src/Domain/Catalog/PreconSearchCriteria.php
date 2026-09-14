<?php

declare(strict_types=1);

namespace App\Domain\Catalog;

use InvalidArgumentException;

/**
 * Los filtros de una búsqueda en el catálogo de precons, ya validados.
 *
 * Hermano de `SearchCriteria` y no una ampliación suya: aquello filtra
 * impresiones por rareza, color y precio y ordena por seis criterios; esto
 * filtra 3.029 cajas por tipo, edición y nombre y **ordena siempre igual**.
 * Meter los dos en una clase obligaría a que cada filtro comprobara a cuál de
 * las dos búsquedas pertenece.
 *
 * Mismo criterio que el hermano en lo que sí importa: **normaliza y acota en vez
 * de rechazar**. Un `limit` de 5.000 se recorta a 100 y un `type` desconocido
 * simplemente no devuelve nada, porque los 48 tipos los publica MTGJSON y una
 * lista blanca copiada a mano se desincronizaría sola — es el mismo motivo por
 * el que las stopwords se leen de `information_schema`.
 */
final class PreconSearchCriteria
{
    public const LIMITE_MAXIMO = 100;

    public function __construct(
        public readonly ?string $q = null,
        public readonly ?string $deckType = null,
        public readonly ?string $setCode = null,
        public readonly int $limit = 60,
        public readonly ?string $cursor = null,
        public readonly bool $soloJugables = false
    ) {
        if ($this->limit < 1 || $this->limit > self::LIMITE_MAXIMO) {
            throw new InvalidArgumentException("Límite fuera de rango: {$this->limit}");
        }
    }

    /**
     * @param array<string, mixed> $peticion Filtros tal como llegan de la query string
     */
    public static function desdePeticion(array $peticion): self
    {
        $texto = static fn (string $clave): ?string => isset($peticion[$clave]) && is_scalar($peticion[$clave])
            && trim((string) $peticion[$clave]) !== ''
                ? trim((string) $peticion[$clave])
                : null;

        return new self(
            q:        $texto('q'),
            // `deck_type` se compara tal cual porque así viene de MTGJSON
            // ('Commander Deck', con la mayúscula y el espacio). La colación es
            // `utf8mb4_unicode_ci`, así que la comparación ya ignora caja.
            deckType: $texto('type'),
            setCode:  $texto('set') !== null ? strtoupper((string) $texto('set')) : null,
            limit:    isset($peticion['limit']) && is_numeric($peticion['limit'])
                ? max(1, min(self::LIMITE_MAXIMO, (int) $peticion['limit']))
                : 60,
            cursor:   $texto('cursor'),
            // `playable=1` deja fuera los tipos que no son un mazo
            // (`PreconPlayability::NO_JUGABLES`). **El defecto es false**: la
            // ruta sigue devolviendo las 3.029 cajas a quien no pida nada, que
            // es lo que devolvía antes de existir este filtro. Quien decide
            // enseñar solo mazos es la vista, y lo pide explícitamente.
            soloJugables: isset($peticion['playable'])
                && in_array((string) $peticion['playable'], ['1', 'true'], true),
        );
    }

    /** ¿Hay término de búsqueda por nombre? */
    public function tieneTexto(): bool
    {
        return $this->q !== null && $this->q !== '';
    }

    /** Copia con otro cursor, para pedir la página siguiente. */
    public function conCursor(?string $cursor): self
    {
        return new self(
            $this->q,
            $this->deckType,
            $this->setCode,
            $this->limit,
            $cursor,
            $this->soloJugables
        );
    }
}
