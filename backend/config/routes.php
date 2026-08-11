<?php

declare(strict_types=1);

use App\Controllers\AuthController;
use App\Controllers\PingController;
use App\Middleware\AuthMiddleware;
use App\Middleware\LoggingMiddleware;
use App\Middleware\RateLimitMiddleware;
use App\Middleware\ValidationMiddleware;

/**
 * Configuración de rutas de acción.
 *
 * Estructura de cada entrada:
 *   'controller' => [ControllerClass::class, 'methodName']   ← FQCN, lo resuelve el contenedor
 *   'middleware' => [MiddlewareClass::class, ...]            ← pila, en orden de ejecución
 *
 * Los middlewares con configuración van como par:
 *   [RateLimitMiddleware::class, ['limit' => 5, 'window' => 300, 'by' => 'ip']]
 *   [ValidationMiddleware::class, ['required' => ['id_token']]]
 *
 * Rate limiting: TODA ruta recibe un RateLimitMiddleware por defecto
 * (configurado por las env RATE_LIMIT_*) que aplica ActionRouter
 * automáticamente. Declararlo aquí explícitamente sobreescribe ese defecto.
 *
 * Añadir un endpoint = tocar este fichero y el controller. Nada más: el
 * ActionRouter resuelve el controller por FQCN y no hay que registrarlo.
 */
return [
    // ========================================================================
    // SALUD — público, sin autenticación
    // ========================================================================
    'ping' => [
        'controller' => [PingController::class, 'ping'],
        'middleware' => [LoggingMiddleware::class],
    ],

    // ========================================================================
    // AUTH — público: es lo que crea la sesión
    // ========================================================================
    'login' => [
        'controller' => [AuthController::class, 'login'],
        'middleware' => [
            // Límite estricto contra fuerza bruta: 10 intentos / 5 min por IP.
            [RateLimitMiddleware::class, ['limit' => 10, 'window' => 300, 'by' => 'ip']],
            LoggingMiddleware::class,
            [ValidationMiddleware::class, ['required' => ['id_token']]],
        ],
    ],

    'logout' => [
        'controller' => [AuthController::class, 'logout'],
        'middleware' => [LoggingMiddleware::class],
    ],

    // ========================================================================
    // AUTH — protegidas: exigen sesión o Bearer token
    // ========================================================================
    'check_auth' => [
        'controller' => [AuthController::class, 'checkAuth'],
        'middleware' => [
            LoggingMiddleware::class,
            AuthMiddleware::class,
        ],
    ],
];
