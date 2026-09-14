<?php

declare(strict_types=1);

namespace Tests\Unit\Doubles;

use App\Domain\Collection\CollectionCriteria;
use App\Domain\Collection\CollectionItem;
use App\Domain\Collection\Condition;
use App\Domain\Repository\CollectionRepositoryInterface;
use InvalidArgumentException;

/**
 * Colección de mentira, en memoria.
 *
 * No es un mock que solo cuenta llamadas: **reproduce el `UNIQUE KEY` de seis
 * columnas y la suma del `ON DUPLICATE KEY UPDATE`**. Sin eso, un test de
 * `AddToCollection` no probaría lo único que hay que probar de ese use case —que
 * añadir dos veces suma en vez de duplicar— y pasaría igual con un repositorio
 * roto.
 */
class ColeccionFalsa implements CollectionRepositoryInterface
{
    /** @var array<int, array<string, mixed>> id → fila */
    public array $filas = [];

    /** Lo que devuelve allLines(); si es null, se derivan de las filas. */
    public ?array $lineas = null;

    public ?CollectionCriteria $ultimosCriterios = null;

    public ?int $ultimoUserId = null;

    public ?bool $ultimoWishlist = null;

    public ?string $nextCursor = null;

    /**
     * Lo que devuelve setProgress(): el GROUP BY ya hecho, que en la vida real
     * hace MySQL. Se inyecta tal cual en vez de derivarlo de las filas porque
     * lo que hay que probar del use case no es la agregación —esa es del
     * repositorio— sino la DIVISIÓN: el porcentaje, el orden y qué se enseña
     * cuando `total_set_size` viene a NULL.
     *
     * @var list<array<string, mixed>>
     */
    public array $progresoSets = [];

    /** Las 868 ediciones del catálogo, para el "23 de 868". */
    public int $setsDelCatalogo = 0;

    private int $siguienteId = 1;

    /** @param list<array<string, mixed>>|null $lineas Para los tests de valoración */
    public function __construct(?array $lineas = null)
    {
        $this->lineas = $lineas;
    }

    public function upsert(CollectionItem $item): int
    {
        $fila  = $item->aFila();
        $clave = $this->clave($fila);

        foreach ($this->filas as $id => $existente) {
            if ($this->clave($existente) === $clave) {
                // quantity = quantity + VALUES(quantity)
                $this->filas[$id]['quantity'] += $fila['quantity'];
                $this->filas[$id]['notes']     = $fila['notes'] ?? $existente['notes'];

                return $id;
            }
        }

        $id                = $this->siguienteId++;
        $this->filas[$id]  = $fila + ['id' => $id];

        return $id;
    }

    /**
     * El lote va por el MISMO `upsert()` de arriba, que es lo que hay que probar:
     * si la importación tuviera camino propio, el `UNIQUE KEY` dejaría de ser lo
     * que impide que reimportar duplique.
     */
    public function upsertLote(array $items): array
    {
        $insertadas   = 0;
        $actualizadas = 0;
        $ejemplares   = 0;

        foreach ($items as $item) {
            $antes = count($this->filas);

            $this->upsert($item);

            if (count($this->filas) > $antes) {
                $insertadas++;
            } else {
                $actualizadas++;
            }

            $ejemplares += $item->quantity;
        }

        return [
            'inserted'      => $insertadas,
            'updated'       => $actualizadas,
            'totalQuantity' => $ejemplares,
        ];
    }

    public function changeQuantity(int $userId, int $itemId, int $quantity): ?array
    {
        if ($quantity <= 0) {
            $this->remove($userId, $itemId);

            return null;
        }

        if (!$this->esSuya($userId, $itemId)) {
            return null;
        }

        $this->filas[$itemId]['quantity'] = $quantity;

        return $this->findById($userId, $itemId);
    }

    /**
     * El movimiento dentro del `UNIQUE KEY`, con las tres cosas que lo hacen
     * distinto de un `UPDATE` y que el doble tiene que reproducir o los tests no
     * probarían nada: la **fusión** cuando el destino ya existe, el **borrado**
     * del origen cuando se agota, y que un movimiento **total a destino libre**
     * conserva el `id` —contrato con la vista, y lo único que impide resolverlo
     * todo creando fila nueva—.
     *
     * Lo que el doble NO reproduce, porque es de MySQL y no del contrato: los
     * bloqueos. El `FOR UPDATE`, el gap lock y el `ON DUPLICATE KEY UPDATE` del
     * repositorio real no tienen equivalente en memoria.
     */
    public function moveLine(int $userId, int $itemId, array $destino, ?int $quantity = null): ?array
    {
        if (!$this->esSuya($userId, $itemId)) {
            return null;
        }

        $origen         = $this->filas[$itemId];
        $cantidadOrigen = (int) $origen['quantity'];
        $aMover         = $quantity ?? $cantidadOrigen;

        // `quantity` es SMALLINT UNSIGNED en la tabla real: mover más de lo que
        // hay no es un movimiento raro, es una sentencia que revienta.
        if ($aMover <= 0 || $aMover > $cantidadOrigen) {
            throw new InvalidArgumentException(
                "No se pueden mover {$aMover} ejemplares de una línea que tiene {$cantidadOrigen}."
            );
        }

        $enDestino = $origen;

        if (isset($destino['condition'])) {
            $enDestino['condition_grade'] = $destino['condition']->value;
        }

        if (isset($destino['isWishlist'])) {
            $enDestino['is_wishlist'] = $destino['isWishlist'] ? 1 : 0;
        }

        // Ya está donde se le pide: el único caso en el que `origen` e `item`
        // son la misma fila.
        if ($this->clave($enDestino) === $this->clave($origen)) {
            $linea = $this->findById($userId, $itemId);

            return ['item' => $linea, 'merged' => false, 'origen' => $linea];
        }

        foreach ($this->filas as $id => $fila) {
            if ($id !== $itemId && $this->clave($fila) === $this->clave($enDestino)) {
                // Destino ocupado: las dos filas son la misma carta, se suman.
                $this->filas[$id]['quantity'] += $aMover;

                return [
                    'item'   => $this->findById($userId, $id),
                    'merged' => true,
                    'origen' => $this->descontarOrigen($userId, $itemId, $cantidadOrigen - $aMover),
                ];
            }
        }

        if ($aMover === $cantidadOrigen) {
            // Destino libre y movimiento total: la fila se reescribe en el sitio
            // y CONSERVA su id. En la combinación de partida no queda nada.
            $this->filas[$itemId] = $enDestino;

            return ['item' => $this->findById($userId, $itemId), 'merged' => false, 'origen' => null];
        }

        // Destino libre y movimiento parcial: la línea se parte en dos.
        $nuevoId               = $this->siguienteId++;
        $enDestino['id']       = $nuevoId;
        $enDestino['quantity'] = $aMover;
        $this->filas[$nuevoId] = $enDestino;

        return [
            'item'   => $this->findById($userId, $nuevoId),
            'merged' => false,
            'origen' => $this->descontarOrigen($userId, $itemId, $cantidadOrigen - $aMover),
        ];
    }

    /**
     * Cambiar de estado es mover la línea entera, igual que en el repositorio
     * real: si el doble tuviera su propia copia de la fusión, un fallo en una de
     * las dos pasaría los tests de la otra.
     */
    public function changeGrade(int $userId, int $itemId, Condition $condicion): ?array
    {
        return $this->moveLine($userId, $itemId, ['condition' => $condicion]);
    }

    /**
     * Lo que quede en el origen, o null si se agotó: a cero se BORRA, porque una
     * fila con `quantity = 0` seguiría contando como carta única.
     *
     * @return array<string, mixed>|null
     */
    private function descontarOrigen(int $userId, int $itemId, int $restante): ?array
    {
        if ($restante <= 0) {
            unset($this->filas[$itemId]);

            return null;
        }

        $this->filas[$itemId]['quantity'] = $restante;

        return $this->findById($userId, $itemId);
    }

    public function remove(int $userId, int $itemId): bool
    {
        if (!$this->esSuya($userId, $itemId)) {
            return false;
        }

        unset($this->filas[$itemId]);

        return true;
    }

    public function findById(int $userId, int $itemId): ?array
    {
        return $this->esSuya($userId, $itemId) ? $this->aContrato($this->filas[$itemId]) : null;
    }

    public function search(int $userId, CollectionCriteria $criterios): array
    {
        $this->ultimoUserId     = $userId;
        $this->ultimosCriterios = $criterios;

        $items = [];

        foreach ($this->filas as $fila) {
            if ($fila['user_id'] === $userId && (bool) $fila['is_wishlist'] === $criterios->isWishlist) {
                $items[] = $this->aContrato($fila);
            }
        }

        return ['items' => $items, 'nextCursor' => $this->nextCursor];
    }

    public function wishedPrintingUuids(int $userId): array
    {
        $this->ultimoUserId = $userId;

        $uuids = [];

        // El DISTINCT del SQL, a mano: la misma impresión puede estar deseada
        // en varias líneas y el corazón es uno solo. Si el doble devolviera
        // duplicados, un use case que los arrastrara pasaría en verde.
        foreach ($this->filas as $fila) {
            if ($fila['user_id'] === $userId && (int) $fila['is_wishlist'] === 1) {
                $uuids[$fila['printing_uuid']] = true;
            }
        }

        return array_keys($uuids);
    }

    public function allLines(int $userId, bool $isWishlist): array
    {
        $this->ultimoUserId   = $userId;
        $this->ultimoWishlist = $isWishlist;

        if ($this->lineas !== null) {
            return $this->lineas;
        }

        $items = [];

        foreach ($this->filas as $fila) {
            if ($fila['user_id'] === $userId && (bool) $fila['is_wishlist'] === $isWishlist) {
                $items[] = $this->aContrato($fila);
            }
        }

        return $items;
    }

    public function setProgress(int $userId, bool $isWishlist): array
    {
        $this->ultimoUserId   = $userId;
        $this->ultimoWishlist = $isWishlist;

        return ['sets' => $this->progresoSets, 'catalogSets' => $this->setsDelCatalogo];
    }

    /** El UNIQUE KEY uq_item, tal cual. */
    private function clave(array $fila): string
    {
        return implode('|', [
            $fila['user_id'],
            $fila['printing_uuid'],
            $fila['finish'],
            $fila['language'],
            $fila['condition_grade'],
            $fila['is_wishlist'],
        ]);
    }

    private function esSuya(int $userId, int $itemId): bool
    {
        return isset($this->filas[$itemId]) && $this->filas[$itemId]['user_id'] === $userId;
    }

    /** @return array<string, mixed> */
    private function aContrato(array $fila): array
    {
        return [
            'id'           => $fila['id'],
            'printingUuid' => $fila['printing_uuid'],
            'finish'       => $fila['finish'],
            'language'     => $fila['language'],
            'condition'    => $fila['condition_grade'],
            'quantity'     => $fila['quantity'],
            'isWishlist'   => (bool) $fila['is_wishlist'],
            'notes'        => $fila['notes'],
            'priceEur'     => null,
            'lineValue'    => 0.0,
        ];
    }
}
