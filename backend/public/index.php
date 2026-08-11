<?php

declare(strict_types=1);

/**
 * Entrypoint HTTP de TCGDesk.
 *
 * Patrón heredado: endpoint ÚNICO por POST con la acción en el body.
 *
 * El desvío de GET /api/catalog/* a un CatalogHttpRouter (divergencia 4b de
 * [[TCGDesk/Decisiones Técnicas]]) va JUSTO AQUÍ, antes de instanciar
 * Application, para que la divergencia quede contenida en un `if` y una clase
 * en vez de repartida por el backend. Lo añade el Plan - Mirror del Catálogo MTG.
 */

require_once __DIR__ . '/../bootstrap.php';

use App\Application;

try {
    (new Application())->run();
} catch (\Throwable $e) {
    http_response_code(500);
    header('Content-Type: application/json');

    $response = [
        'status'  => 'error',
        'message' => 'Application initialization failed',
    ];

    if (($_ENV['APP_ENV'] ?? 'development') === 'development') {
        $response['debug'] = [
            'message' => $e->getMessage(),
            'file'    => $e->getFile(),
            'line'    => $e->getLine(),
        ];
    }

    error_log('Bootstrap Error: ' . $e->getMessage());
    echo json_encode($response, JSON_PRETTY_PRINT);
    exit(1);
}
