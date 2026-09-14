<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Application\UseCase\AddToCollection;
use App\Application\UseCase\ChangeItemGrade;
use App\Application\UseCase\FulfillWish;
use App\Application\UseCase\ListCollection;
use App\Application\UseCase\ListSetProgress;
use App\Application\UseCase\ListWishedPrintings;
use App\Application\UseCase\RemoveFromCollection;
use App\Application\UseCase\UpdateQuantity;
use App\Application\UseCase\ValueCollection;
use InvalidArgumentException;
use PDOException;
use Psr\Log\LoggerInterface;

/**
 * Las nueve acciones de la colección.
 *
 * **Todas son privadas**: llevan `AuthMiddleware` en su pila de `routes.php`, y
 * el `user_id` se lee de `$request['user_id']` —que es donde lo deja ese
 * middleware— y **nunca** del cuerpo de la petición. Si viniera del payload,
 * cualquiera podría leer, editar o borrar la colección de otro con solo cambiar
 * un número.
 *
 * El controller es fino a propósito: valida lo mínimo, delega en el use case y
 * traduce las excepciones del dominio a códigos HTTP. Toda la lógica vive en
 * `src/Application/UseCase`, que es lo que se puede probar con un doble.
 */
class CollectionController extends BaseController
{
    public function __construct(
        private readonly AddToCollection $anadir,
        private readonly UpdateQuantity $actualizar,
        private readonly ChangeItemGrade $cambiarEstado,
        private readonly FulfillWish $cumplirDeseo,
        private readonly RemoveFromCollection $quitar,
        private readonly ListCollection $listar,
        private readonly ValueCollection $valorar,
        private readonly ListSetProgress $progresoDeEdiciones,
        private readonly ListWishedPrintings $impresionesDeseadas,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Añadir ejemplares. Repetir la llamada SUMA a la línea existente.
     */
    public function add(array $request): array
    {
        $userId = $this->usuario($request);

        try {
            $resultado = ($this->anadir)($userId, $request['data'] ?? []);
        } catch (InvalidArgumentException $e) {
            return $this->errorResponse($e->getMessage(), 422);
        } catch (PDOException $e) {
            return $this->traducirErrorDeBaseDeDatos(
                $e,
                $userId,
                $this->logger,
                'Alta rechazada: el printing no existe'
            );
        }

        $this->logger->info('Carta añadida a la colección', [
            'user_id' => $userId,
            'item_id' => $resultado['id'],
        ]);

        return $this->successResponse('Carta añadida a la colección.', $resultado);
    }

    /**
     * Fijar la cantidad de una línea. **Cero borra la línea.**
     */
    public function updateQuantity(array $request): array
    {
        $userId = $this->usuario($request);

        try {
            $resultado = ($this->actualizar)($userId, $request['data'] ?? []);
        } catch (InvalidArgumentException $e) {
            return $this->errorResponse($e->getMessage(), 422);
        } catch (PDOException $e) {
            return $this->traducirErrorDeBaseDeDatos(
                $e,
                $userId,
                $this->logger,
                'Alta rechazada: el printing no existe'
            );
        }

        if ($resultado === null) {
            return $this->errorResponse('Esa línea no está en tu colección.', 404);
        }

        return $this->successResponse(
            $resultado['removed'] ? 'Línea eliminada de la colección.' : 'Cantidad actualizada.',
            $resultado
        );
    }

    /**
     * Cambiar el estado físico de una línea. Puede FUNDIRLA con otra.
     *
     * `condition_grade` está dentro del `UNIQUE KEY`, así que esto no es un
     * `UPDATE` de una columna cualquiera: si el usuario ya tenía esa misma carta
     * en el estado de destino, las dos líneas se funden sumando cantidades y el
     * `id` que devuelve la respuesta **no es el que mandó el cliente**. La vista
     * necesita mirarlo para no quedarse con una línea que ya no existe.
     */
    public function changeGrade(array $request): array
    {
        $userId = $this->usuario($request);

        try {
            $resultado = ($this->cambiarEstado)($userId, $request['data'] ?? []);
        } catch (InvalidArgumentException $e) {
            return $this->errorResponse($e->getMessage(), 422);
        } catch (PDOException $e) {
            return $this->traducirErrorDeBaseDeDatos(
                $e,
                $userId,
                $this->logger,
                'Alta rechazada: el printing no existe'
            );
        }

        if ($resultado === null) {
            return $this->errorResponse('Esa línea no está en tu colección.', 404);
        }

        if ($resultado['merged']) {
            $this->logger->info('Dos líneas fundidas al cambiar de estado', [
                'user_id' => $userId,
                'item_id' => $resultado['item']['id'] ?? null,
            ]);
        }

        return $this->successResponse(
            $resultado['merged']
                ? 'Estado actualizado: la línea se ha unido a la que ya tenías en ese estado.'
                : 'Estado actualizado.',
            $resultado
        );
    }

    /**
     * Cumplir un deseo: pasarlo de la lista de deseos a la colección.
     *
     * Es el hermano de `changeGrade()` sobre la otra columna que el usuario
     * cruza del `UNIQUE KEY`, `is_wishlist`, con un caso que aquel no tiene: el
     * movimiento **parcial**. Deseabas cuatro y compras una, así que la
     * respuesta lleva `origen` —el deseo tal como quedó, o null si se agotó— y
     * la vista tiene que mirarlo: el `id` de la línea de deseo puede haber
     * dejado de existir.
     *
     * Pedirlo sobre una línea que NO es un deseo es 422 y no un 200 sin efecto:
     * significa que la vista ofreció el botón donde no debía.
     */
    public function fulfillWish(array $request): array
    {
        $userId = $this->usuario($request);

        try {
            $resultado = ($this->cumplirDeseo)($userId, $request['data'] ?? []);
        } catch (InvalidArgumentException $e) {
            return $this->errorResponse($e->getMessage(), 422);
        } catch (PDOException $e) {
            return $this->traducirErrorDeBaseDeDatos(
                $e,
                $userId,
                $this->logger,
                'Alta rechazada: el printing no existe'
            );
        }

        if ($resultado === null) {
            return $this->errorResponse('Esa línea no está en tu colección.', 404);
        }

        $this->logger->info('Deseo cumplido', [
            'user_id' => $userId,
            'item_id' => $resultado['item']['id'] ?? null,
            'merged'  => $resultado['merged'],
        ]);

        return $this->successResponse(
            $resultado['merged']
                ? 'Deseo cumplido: se ha unido a la línea que ya tenías en tu colección.'
                : 'Deseo cumplido: la carta ya está en tu colección.',
            $resultado
        );
    }

    /**
     * Quitar una línea entera.
     */
    public function remove(array $request): array
    {
        $userId = $this->usuario($request);

        try {
            $borrada = ($this->quitar)($userId, $request['data'] ?? []);
        } catch (InvalidArgumentException $e) {
            return $this->errorResponse($e->getMessage(), 422);
        }

        if (!$borrada) {
            return $this->errorResponse('Esa línea no está en tu colección.', 404);
        }

        return $this->successResponse('Línea eliminada de la colección.');
    }

    /**
     * La colección con filtros, orden y paginación por cursor.
     */
    public function list(array $request): array
    {
        $userId = $this->usuario($request);

        try {
            $resultado = ($this->listar)($userId, $request['data'] ?? []);
        } catch (InvalidArgumentException $e) {
            return $this->errorResponse($e->getMessage(), 422);
        }

        return $this->successResponse('Colección.', $resultado);
    }

    /**
     * Cuánto vale la colección, con sus desgloses. Se recalcula siempre.
     */
    public function value(array $request): array
    {
        $userId = $this->usuario($request);

        return $this->successResponse('Valoración de la colección.', ($this->valorar)($userId, $request['data'] ?? []));
    }

    /**
     * Cuánto llevas de cada edición, con su porcentaje de completado.
     *
     * Es **tu** progreso, no un dato del catálogo: por eso va por el endpoint
     * único con `AuthMiddleware` y no por las rutas GET de catálogo, y por eso
     * el `user_id` sale del request y no del payload.
     */
    public function sets(array $request): array
    {
        $userId = $this->usuario($request);

        return $this->successResponse(
            'Progreso por edición.',
            ($this->progresoDeEdiciones)($userId, $request['data'] ?? [])
        );
    }

    /**
     * Qué impresiones están ya en tu lista de deseos: **el corazón relleno**.
     *
     * Es una lectura y no lleva CSRF, igual que `collection_list`: no hay
     * estado que falsificar. Tampoco lleva `ValidationMiddleware`, porque no
     * tiene ni un campo —ni siquiera opcional—: la única entrada es el
     * `user_id` que deja `AuthMiddleware`.
     *
     * Vive aquí y no colgando del catálogo porque **es dato de usuario**. Las
     * rutas `GET /api/catalog/*` se desvían antes de construir `Application` y
     * no tienen sesión (`public/index.php:30-43`), así que el cruce lo hace el
     * cliente: pide esta lista una vez y pinta sus 60 corazones con ella.
     */
    public function wishedUuids(array $request): array
    {
        $userId = $this->usuario($request);

        return $this->successResponse(
            'Impresiones en tu lista de deseos.',
            ($this->impresionesDeseadas)($userId)
        );
    }
}
