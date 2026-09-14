<?php

declare(strict_types=1);

namespace App\Controllers;

use InvalidArgumentException;
use PDOException;
use Psr\Log\LoggerInterface;
use RuntimeException;

abstract class BaseController
{
    /**
     * Create a success response
     */
    protected function successResponse(string $message, ?array $data = null, int $httpCode = 200): array
    {
        return [
            'status'    => 'success',
            'message'   => $message,
            'data'      => $data,
            'http_code' => $httpCode,
        ];
    }

    /**
     * Create an error response
     */
    protected function errorResponse(string $message, int $httpCode = 400): array
    {
        return [
            'status'    => 'error',
            'message'   => $message,
            'data'      => null,
            'http_code' => $httpCode,
        ];
    }

    /**
     * El id del usuario, de donde lo dejó AuthMiddleware y de ningún otro sitio.
     *
     * **Nunca** del cuerpo de la petición: si viniera del payload, cualquiera
     * leería, escribiría o borraría la colección de otro cambiando un número.
     *
     * Si falta es que la ruta se declaró sin `AuthMiddleware`: un fallo de
     * configuración, no una petición mal formada. Revienta en vez de asumir un
     * usuario.
     */
    protected function usuario(array $request): int
    {
        $userId = $request['user_id'] ?? null;

        if (!is_int($userId) && !is_numeric($userId)) {
            throw new RuntimeException(
                'La acción ' . ($request['action'] ?? '?') . ' llegó sin user_id: ¿le falta AuthMiddleware?'
            );
        }

        return (int) $userId;
    }

    /**
     * Validate that required fields are present in input data
     */
    protected function validateRequiredFields(array $data, array $requiredFields): void
    {
        foreach ($requiredFields as $field) {
            if (!isset($data[$field]) || $data[$field] === '' || $data[$field] === null) {
                throw new InvalidArgumentException("Field '{$field}' is required.");
            }
        }
    }

    /**
     * Una violación de clave foránea al escribir una carta —de colección o de
     * mazo— solo puede significar una cosa: el `printing_uuid` que mandó el
     * cliente no está en el catálogo. Es culpa de la petición (422), no del
     * servidor (500).
     *
     * Vive aquí, y no en cada controller, porque las dos copias que había eran
     * idénticas salvo el texto del log; por eso el mensaje es parámetro. Y el
     * logger también lo es porque `BaseController` **no tiene constructor**: si
     * lo pidiera, los seis controllers cambiarían de firma y de registro en
     * `config/container.php` por quince líneas.
     *
     * **`$mensajeDeDuplicado` parte el SQLSTATE 23000 en dos, y es del M2 del
     * Plan - Amigos y Seguimiento.** `23000` no es «clave foránea»: es
     * *violación de restricción de integridad*, y agrupa por lo menos dos cosas
     * que no se responden igual — la foránea rota (`1452`) y la **clave
     * duplicada** (`1062`), que es la que devuelve el `UNIQUE (user_low,
     * user_high)` de `friendships` cuando ya hay una solicitud entre dos
     * personas. Ese caso es un **409**, no un 422, y «esa carta no existe en el
     * catálogo» no significa nada en él.
     *
     * Quien pasa el mensaje declara «mi 23000 es un duplicado», y entonces:
     * un `1062` se traduce a 409 con ese texto, y **cualquier otro 23000 se
     * relanza** en vez de caer al mensaje del catálogo — una foránea rota en una
     * tabla que no tiene `printing_uuid` es un fallo del servidor y tiene que
     * llegar como tal, no disfrazado de petición mal formada.
     *
     * Sin él —los cuatro `CollectionController` y los tres `DeckController` que
     * ya lo llamaban— el comportamiento es **exactamente el de antes**: todo
     * `23000` es el catálogo. Por eso es opcional y va el último: extenderlo así
     * evita la tercera copia del helper, que es lo que el plan pedía no hacer.
     *
     * Cualquier otro SQLSTATE se relanza: un error de base de datos que no
     * sabemos traducir es un 500 de verdad, y tragárselo lo escondería.
     */
    protected function traducirErrorDeBaseDeDatos(
        PDOException $e,
        int $userId,
        LoggerInterface $logger,
        string $mensajeDeLog,
        ?string $mensajeDeDuplicado = null
    ): array {
        if ($e->getCode() === '23000') {
            // El código del DRIVER, no el SQLSTATE: es el único sitio donde
            // MySQL distingue el duplicado (1062) de la foránea rota (1452).
            // `?? null` porque un PDOException construido a mano —los de los
            // tests— no trae `errorInfo`, y ahí no hay nada que distinguir.
            $esDuplicado = ($e->errorInfo[1] ?? null) === 1062;

            if ($mensajeDeDuplicado !== null) {
                if (!$esDuplicado) {
                    throw $e;
                }

                $logger->info($mensajeDeLog, ['user_id' => $userId]);

                return $this->errorResponse($mensajeDeDuplicado, 409);
            }

            $logger->info($mensajeDeLog, ['user_id' => $userId]);

            return $this->errorResponse('Esa carta no existe en el catálogo.', 422);
        }

        // Desbordamiento del SMALLINT UNSIGNED al sumar cantidades.
        if ($e->getCode() === '22003') {
            return $this->errorResponse('Esa cantidad se sale del máximo por línea.', 422);
        }

        throw $e;
    }
}
