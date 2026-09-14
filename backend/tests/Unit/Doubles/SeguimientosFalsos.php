<?php

declare(strict_types=1);

namespace Tests\Unit\Doubles;

use App\Domain\Repository\FollowRepositoryInterface;

/**
 * Seguimientos de mentira, en memoria.
 *
 * Reproduce las tres cosas que `MySqlFollowRepository` promete y que un doble
 * ingenuo dejaría pasar en verde:
 *
 *  1. **La clave es ASIMÉTRICA.** `PRIMARY KEY (follower_id, followed_id)`: A→B
 *     y B→A son dos hechos legítimos que conviven, al revés que en
 *     `friendships`, donde `AmistadesFalsas` guarda la pareja ordenada `(min,
 *     max)` porque el `UNIQUE` por columnas generadas prohíbe la fila cruzada.
 *     Un doble que copiara aquel `min`/`max` haría pasar en verde una
 *     implementación en la que dejar de seguir a alguien te borra a ti de sus
 *     seguidos.
 *  2. **Seguir es idempotente y NO revienta.** Donde `crearSolicitud()` lanza un
 *     `PDOException` con `1062` —y `AmistadesFalsas` lo imita con un error de
 *     driver de verdad—, aquí el `ON DUPLICATE KEY UPDATE` de la consulta real
 *     absorbe el duplicado y la operación devuelve `false` («ya estaba»). Si
 *     este doble lanzara, el 200 idempotente de `follow_add` estaría probado
 *     contra algo que la base de datos no hace.
 *  3. **El `JOIN users` es lista blanca: ni `email` ni `id`.** Igual que la
 *     consulta real, que no los selecciona. Lo que no se trae no se publica por
 *     descuido.
 *
 * `$preguntas` cuenta las consultas de lectura, por el mismo motivo que
 * `$lecturas` en `PrivacidadFalsa`.
 */
class SeguimientosFalsos implements FollowRepositoryInterface
{
    /**
     * Los marcadores, con la MISMA asimetría de la `PRIMARY KEY`: la clave es
     * `"seguidor:seguido"` y no la pareja ordenada.
     *
     * @var array<string, array{seguidor: int, seguido: int, desde: string}>
     */
    public array $filas = [];

    /**
     * Lo que el `JOIN users` sacaría de cada persona. **Sin `email`**, igual que
     * la consulta real.
     *
     * @var array<int, array{username: string, displayName: ?string, avatarUrl: ?string}>
     */
    private array $personas = [];

    /** Cuántas veces se ha leído la tabla. */
    public int $preguntas = 0;

    /** Para que las fechas de `since` sean distintas y el orden se pueda probar. */
    private int $reloj = 0;

    /** Lo que el `JOIN users` sacaría de esta persona. */
    public function persona(int $id, string $username, ?string $displayName = null, ?string $avatarUrl = null): self
    {
        $this->personas[$id] = [
            'username'    => $username,
            'displayName' => $displayName,
            'avatarUrl'   => $avatarUrl,
        ];

        return $this;
    }

    /** Marcador ya puesto, para montar el estado de partida de un test. */
    public function siguiendo(int $seguidor, int $seguido): self
    {
        $this->seguir($seguidor, $seguido);

        return $this;
    }

    /** ¿Está puesto este marcador? Solo para los tests: el puerto no lo expone. */
    public function hayMarcador(int $seguidor, int $seguido): bool
    {
        return isset($this->filas[self::clave($seguidor, $seguido)]);
    }

    /**
     * @inheritDoc
     */
    public function seguir(int $seguidor, int $seguido): bool
    {
        $clave = self::clave($seguidor, $seguido);

        // Ya estaba: ni error ni fila nueva, y **la fecha no se toca** — el
        // `ON DUPLICATE KEY UPDATE created_at = created_at` de la consulta real
        // no la mueve, así que el marcador conserva el día que se puso.
        if (isset($this->filas[$clave])) {
            return false;
        }

        $this->filas[$clave] = [
            'seguidor' => $seguidor,
            'seguido'  => $seguido,
            'desde'    => sprintf('2026-09-14 12:00:%02d', $this->reloj++),
        ];

        return true;
    }

    /**
     * @inheritDoc
     */
    public function dejarDeSeguir(int $seguidor, int $seguido): bool
    {
        $clave = self::clave($seguidor, $seguido);

        if (!isset($this->filas[$clave])) {
            return false;
        }

        unset($this->filas[$clave]);

        return true;
    }

    /**
     * @inheritDoc
     */
    public function seguidosDe(int $userId): array
    {
        $this->preguntas++;

        $seguidos = [];

        foreach ($this->filas as $fila) {
            if ($fila['seguidor'] !== $userId) {
                continue;
            }

            $persona = $this->personas[$fila['seguido']] ?? [
                'username'    => 'usuario' . $fila['seguido'],
                'displayName' => null,
                'avatarUrl'   => null,
            ];

            $seguidos[] = $persona + ['since' => $fila['desde']];
        }

        // El más reciente primero, como el `ORDER BY f.created_at DESC`.
        usort($seguidos, static fn (array $a, array $b): int => $b['since'] <=> $a['since']);

        return $seguidos;
    }

    /**
     * @inheritDoc
     */
    public function contarSeguidoresDe(int $userId): int
    {
        $this->preguntas++;

        $seguidores = 0;

        foreach ($this->filas as $fila) {
            if ($fila['seguido'] === $userId) {
                $seguidores++;
            }
        }

        return $seguidores;
    }

    /**
     * La clave, **sin ordenar los ids**: es la `PRIMARY KEY (follower_id,
     * followed_id)` traída a memoria. Que esto no sea `min`/`max` es la mitad de
     * lo que este doble prueba.
     */
    private static function clave(int $seguidor, int $seguido): string
    {
        return $seguidor . ':' . $seguido;
    }
}
