<?php

declare(strict_types=1);

namespace App\Application\UseCase;

use App\Domain\Catalog\PreconSearchCriteria;
use App\Domain\Repository\PreconRepositoryInterface;

/**
 * Buscar mazos preconstruidos en el catálogo local.
 *
 * Existe como use case y no como método del router por lo mismo que
 * `SearchCards`: el router traduce HTTP y nada más, y esta misma búsqueda la va
 * a querer M5 —el botón de un clic— para localizar la caja que se importa. La
 * consulta duplicada en dos sitios es lo que garantiza que se desincronicen.
 *
 * Nada de red, nada de sesión: son 3.029 filas de la zona 1, dato público y
 * reconstruible. Por eso la ruta puede ser un `GET` cacheable.
 */
class SearchPrecons
{
    public function __construct(
        private readonly PreconRepositoryInterface $precons
    ) {
    }

    /**
     * Las facetas —los 48 tipos y las 295 ediciones que existen— viajan **solo
     * en la primera página**, la que llega sin `cursor`. Son ~12 KB que la vista
     * necesita una vez para poblar los desplegables; repetirlos en cada tirón
     * del scroll infinito los mandaría cincuenta veces por recorrido completo
     * para que el cliente los tirase cuarenta y nueve.
     *
     * @param  array<string, mixed> $peticion Filtros tal como llegan del cliente
     * @return array{items: list<array<string, mixed>>, nextCursor: string|null, count: int,
     *               deckTypes?: list<array{type: string, count: int, playable: bool}>,
     *               sets?: list<array{code: string, name: string|null, count: int}>}
     */
    public function __invoke(array $peticion): array
    {
        $criterios = PreconSearchCriteria::desdePeticion($peticion);
        $resultado = $this->precons->buscar($criterios);

        $salida = [
            'items'      => $resultado['items'],
            'nextCursor' => $resultado['nextCursor'],
            'count'      => count($resultado['items']),
        ];

        if ($criterios->cursor === null) {
            $facetas             = $this->precons->facetas();
            $salida['deckTypes'] = $facetas['types'];
            $salida['sets']      = $facetas['sets'];
        }

        return $salida;
    }
}
