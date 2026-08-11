<?php

declare(strict_types=1);

namespace App\Controllers;

/**
 * Health check del backend.
 *
 * Es la acción que verifica que la pila entera responde: Apache → index.php →
 * bootstrap → contenedor DI → ActionRouter → pila de middleware → controller.
 * No toca la base de datos a propósito: si `ping` falla, el problema está en el
 * andamiaje, no en MySQL.
 */
class PingController extends BaseController
{
    public function ping(array $request): array
    {
        return $this->successResponse('pong', [
            'app'       => 'TCGDesk',
            'env'       => $_ENV['APP_ENV'] ?? 'unknown',
            'timestamp' => date('c'),
        ]);
    }
}
