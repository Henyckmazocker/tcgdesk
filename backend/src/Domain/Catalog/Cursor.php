<?php

declare(strict_types=1);

namespace App\Domain\Catalog;

/**
 * El cursor de paginación del catálogo.
 *
 * Existe para no paginar por offset: `LIMIT 60 OFFSET 50000` sobre 110.384
 * printings obliga a MySQL a recorrer y tirar 50.000 filas en cada tirón del
 * scroll infinito, y el coste crece según bajas.
 *
 * Es opaco a propósito —base64url de un JSON— para que el cliente no construya
 * cursores a mano y quedemos atados a su formato. Todo lo que venga mal formado
 * se trata como "empieza por el principio" en vez de como un error: un cursor
 * caducado o manipulado no debe romper una vista de catálogo.
 */
final class Cursor
{
    /** @param array<string, mixed> $datos */
    public static function codificar(array $datos): string
    {
        return rtrim(strtr(base64_encode(json_encode($datos, JSON_UNESCAPED_UNICODE)), '+/', '-_'), '=');
    }

    /**
     * @return array<string, mixed>|null null si no hay cursor o no es válido
     */
    public static function decodificar(?string $cursor): ?array
    {
        if ($cursor === null || $cursor === '') {
            return null;
        }

        $json = base64_decode(strtr($cursor, '-_', '+/'), true);

        if ($json === false) {
            return null;
        }

        $datos = json_decode($json, true);

        return is_array($datos) ? $datos : null;
    }

    /**
     * Cursor por valor de columna: el que evita el offset de verdad.
     *
     * @param scalar|null $valor Valor de la columna de orden en la última fila
     */
    public static function porColumna(string|int|float|null $valor, string $uuid): string
    {
        return self::codificar(['v' => $valor, 'u' => $uuid]);
    }

    /** Cursor por posición, para los órdenes que no se pueden comparar por tupla. */
    public static function porPosicion(int $offset): string
    {
        return self::codificar(['o' => $offset]);
    }

    /** @param array<string, mixed>|null $datos */
    public static function offsetDe(?array $datos): int
    {
        return isset($datos['o']) && is_int($datos['o']) && $datos['o'] >= 0 ? $datos['o'] : 0;
    }
}
