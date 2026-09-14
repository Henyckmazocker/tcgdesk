<?php

declare(strict_types=1);

namespace App\Application\UseCase;

use App\Domain\Deck\DeckStatus;
use App\Domain\Repository\DeckRepositoryInterface;

/**
 * Listar los mazos del usuario, opcionalmente filtrados por estado.
 *
 * Fino a propósito, igual que `ListCollection`: valida el filtro y delega. Sin
 * paginación, y no por descuido —una persona tiene mazos, no un catálogo: veinte
 * filas, no 110.384—.
 *
 * El filtro va con `intentar()` y no con `desde()` porque **esto es una
 * lectura**: un `status` que no se entiende no debe reventar la pantalla de
 * mazos, solo dejar de filtrar. La regla del repo es la contraria en las
 * escrituras, donde un valor que no se entiende jamás puede caer en el defecto.
 *
 * Y lista **con lo que le falta a cada mazo**, porque `missingCount` es parte
 * del contrato de `deck_list` desde el primer día y calcularlo es una decisión
 * de negocio, no de transporte. Antes lo hacía el controller recorriendo los
 * mazos en un `foreach`; ahora son dos consultas fijas, mires 2 mazos o 40.
 */
class ListDecks
{
    public function __construct(
        private readonly DeckRepositoryInterface $mazos
    ) {
    }

    /**
     * @param  array<string, mixed> $peticion Payload del cliente
     * @return array{decks: list<array<string, mixed>>, count: int}
     */
    public function __invoke(int $userId, array $peticion): array
    {
        $estado = DeckStatus::intentar($peticion['status'] ?? null);

        $mazos = $this->mazos->allByUser($userId, $estado);

        // `faltantesDeTodos()` se pregunta **entero y una sola vez**, sin
        // filtrarlo por el estado del listado: es una consulta agregada, y
        // recortarla al filtro no ahorraría nada y sí obligaría a repetirla
        // cada vez que la pantalla cambia de pestaña.
        //
        // El `?? 0` no es defensivo: es el contrato. Los mazos **sin cartas no
        // aparecen** en ese array —no tienen ni una línea que cruzar—, y sin el
        // defecto la lista enseñaría un hueco justo en el mazo recién creado.
        $faltantes = $this->mazos->faltantesDeTodos($userId);

        foreach ($mazos as $i => $mazo) {
            $mazos[$i]['missingCount'] = $faltantes[$mazo['id']] ?? 0;
        }

        return ['decks' => $mazos, 'count' => count($mazos)];
    }
}
