<?php

declare(strict_types=1);

namespace App\Application\UseCase;

use App\Domain\Repository\CollectionRepositoryInterface;

/**
 * Qué impresiones quiere ya el usuario, para el corazón del catálogo.
 *
 * **Existe porque el catálogo no tiene sesión.** `GET /api/catalog/cards` se
 * desvía en `public/index.php:30-43` **antes** de construir `Application`, así
 * que no pasa por `AuthMiddleware` y sus resultados no pueden venir anotados
 * con la lista de deseos de nadie. Anotarlos exigiría meter dato de usuario en
 * la divergencia `GET`, que es justo lo que el `CLAUDE.md` manda discutir antes.
 * La alternativa es esta: el cliente cruza, y para cruzar necesita la lista de
 * uuids deseados una vez.
 *
 * No devuelve líneas ni cantidades **a propósito**. El corazón solo contesta a
 * «¿esta carta está ya en mi lista?», y mandar las filas enteras sería servir
 * la lista de deseos completa cada vez que alguien abre el catálogo.
 *
 * Fino como `ListCollection`: aquí no hay nada que validar —la acción no lleva
 * ni un campo obligatorio— y toda la decisión está en el SQL del repositorio.
 */
class ListWishedPrintings
{
    public function __construct(
        private readonly CollectionRepositoryInterface $coleccion
    ) {
    }

    /**
     * @return array{uuids: list<string>, count: int}
     */
    public function __invoke(int $userId): array
    {
        $uuids = $this->coleccion->wishedPrintingUuids($userId);

        return [
            'uuids' => $uuids,
            'count' => count($uuids),
        ];
    }
}
