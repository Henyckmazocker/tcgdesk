<?php

declare(strict_types=1);

namespace App\Application\UseCase;

use App\Domain\Repository\CollectionRepositoryInterface;

/**
 * Cuánto llevas de cada edición.
 *
 * El porcentaje es el del plan, literal:
 * `COUNT(DISTINCT printing) / mtg_set.total_set_size`. Lo que aporta este use
 * case es **la división**, y con ella los dos casos que revientan una pantalla
 * si se hacen en el SQL sin pensarlos:
 *
 * - **`total_set_size` puede ser NULL** —la columna es nullable— y también
 *   podría ser 0. Dividir daría `NULL` o una división por cero, y en pantalla un
 *   `NaN` o un "0 %" falso. Aquí el porcentaje se queda a `null` a propósito, y
 *   la vista dice "tamaño desconocido": es la misma regla que el precio ausente
 *   del plan, "sin precio" y jamás "0 €".
 * - **El porcentaje puede pasar de 100.** Si una edición declara menos cartas de
 *   las que el catálogo le cuenta, el número sale por encima; no se recorta,
 *   porque recortarlo escondería el dato raro. Es la barra la que se queda en su
 *   ancho.
 *
 * Solo aparecen las ediciones en las que hay algo. Las 868 del catálogo con un
 * 0 % no son progreso, son ruido — pero el recuento total sí se devuelve, que es
 * lo que convierte "23 ediciones" en "23 de 868".
 */
class ListSetProgress
{
    public function __construct(
        private readonly CollectionRepositoryInterface $coleccion
    ) {
    }

    /**
     * @param  array<string, mixed> $peticion
     * @return array<string, mixed>
     */
    public function __invoke(int $userId, array $peticion = []): array
    {
        $esDeseos = filter_var($peticion['is_wishlist'] ?? false, FILTER_VALIDATE_BOOL);

        $progreso = $this->coleccion->setProgress($userId, $esDeseos);

        $filas = array_map([$this, 'conPorcentaje'], $progreso['sets']);

        usort($filas, static function (array $a, array $b): int {
            // Las ediciones sin tamaño declarado van al final: no es que lleves
            // un 0 %, es que no se sabe de cuánto. A igualdad, más cartas
            // primero, y por nombre para que el orden sea total y estable.
            $porA = $a['percent'] ?? -1.0;
            $porB = $b['percent'] ?? -1.0;

            return [$porB, $b['ownedPrintings'], $a['setName']]
                <=> [$porA, $a['ownedPrintings'], $b['setName']];
        });

        return [
            'sets'   => $filas,
            'totals' => [
                'catalogSets'    => $progreso['catalogSets'],
                'startedSets'    => count($filas),
                'completedSets'  => count(array_filter($filas, static fn (array $f): bool => $f['complete'])),
                'unsizedSets'    => count(array_filter($filas, static fn (array $f): bool => $f['percent'] === null)),
                'ownedPrintings' => array_sum(array_column($filas, 'ownedPrintings')),
                'totalCopies'    => array_sum(array_column($filas, 'copies')),
            ],
        ];
    }

    /**
     * @param  array<string, mixed> $fila
     * @return array<string, mixed>
     */
    private function conPorcentaje(array $fila): array
    {
        $tamano = $fila['totalSetSize'] ?? null;
        $tengo  = (int) ($fila['ownedPrintings'] ?? 0);

        // Sin tamaño (NULL) o con tamaño 0 no hay porcentaje que dar. Ni se
        // divide ni se inventa un cero.
        $porcentaje = ($tamano === null || (int) $tamano <= 0)
            ? null
            : round(($tengo / (int) $tamano) * 100, 1);

        return array_replace($fila, [
            'percent'  => $porcentaje,
            'complete' => $porcentaje !== null && $tengo >= (int) $tamano,
        ]);
    }
}
