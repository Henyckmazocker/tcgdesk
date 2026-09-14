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
 *
 * El M6 del Plan - Colección y Vistas añade un segundo desvío por el mismo
 * motivo y con la misma forma: GET /api/images/* devuelve los BYTES de un JPEG,
 * y un endpoint que responde JSON no puede servirlo sin base64 y sin renunciar
 * al caché del navegador. Sigue siendo **solo GET y solo lectura** de un recurso
 * estático; cualquier escritura sigue siendo una acción POST.
 *
 * Y el M3 del Plan - Perfil Público y Mazos Compartibles añade el tercero y
 * último, que es el único de los tres que NO sirve dato público: GET
 * /api/public/* devuelve el perfil de una persona según lo que ella haya
 * decidido enseñar. Sigue siendo solo GET y solo lectura, y por eso va aquí
 * arriba con los otros dos; lo que lo distingue —que se limita y que resuelve
 * al espectador sin AuthMiddleware— está en PublicHttpRouter y en su `if`.
 */

require_once __DIR__ . '/../bootstrap.php';

use App\Application;
use App\Router\CatalogHttpRouter;
use App\Router\ImageHttpRouter;
use App\Router\PublicHttpRouter;

try {
    // La divergencia, entera: un `if` y una clase. Se desvía ANTES de construir
    // Application porque el catálogo no necesita sesión, ni CSRF, ni el pipeline
    // de middlewares de las acciones — es lectura pública y cacheable.
    $metodo = $_SERVER['REQUEST_METHOD'] ?? '';
    $uri    = $_SERVER['REQUEST_URI'] ?? '/';

    if (CatalogHttpRouter::atiende($metodo, $uri)) {
        $containerFactory = require __DIR__ . '/../config/container.php';

        $containerFactory()
            ->get(CatalogHttpRouter::class)
            ->handle($uri);

        exit;
    }

    // Las imágenes cacheadas de la colección: la copia local si existe, un 302 al
    // CDN de Scryfall si todavía no. Mismo criterio que arriba — se desvía antes
    // de Application porque una imagen de carta no necesita sesión, ni CSRF, ni
    // el pipeline de middlewares.
    if (ImageHttpRouter::atiende($metodo, $uri)) {
        $containerFactory = require __DIR__ . '/../config/container.php';

        $containerFactory()
            ->get(ImageHttpRouter::class)
            ->handle($uri, $metodo);

        exit;
    }

    // El perfil público: la CUARTA divergencia GET, y la primera que no sirve
    // dato reconstruible sino la colección de una persona. Aprobada en el
    // Plan - Perfil Público y Mazos Compartibles (sección «La cuarta divergencia
    // GET»), con router propio y no dos casos más en CatalogHttpRouter: mezclar
    // rutas que comprueban permisos con las que no haría que la próxima ruta de
    // catálogo naciera con la duda de si tiene que comprobar algo.
    //
    // Mismo desvío antes de Application y por el mismo motivo —quien abre el
    // enlace no tiene cuenta—, con dos diferencias que se ven desde aquí: este
    // router SÍ se limita (HttpRateLimitGuard, lo primero de su handle()) y
    // resuelve al espectador él mismo, porque sin Application no hay
    // AuthMiddleware ni sesión arrancada.
    //
    // Va el último de los tres desvíos a propósito: el catálogo y las imágenes
    // son lo que se pide en cada tirón del scroll, y siguen sin limitar.
    if (PublicHttpRouter::atiende($metodo, $uri)) {
        $containerFactory = require __DIR__ . '/../config/container.php';

        $containerFactory()
            ->get(PublicHttpRouter::class)
            ->handle($uri);

        exit;
    }

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

    // Mismo criterio que Application::opcionesDeJson(): el sangrado solo sirve
    // para leer el error a ojo, y en producción no lo lee nadie. Aquí NO se
    // añaden UNESCAPED_SLASHES ni UNESCAPED_UNICODE aunque las lleve la otra
    // salida: este fallback siempre escapó, y el #14 cambia el sangrado, no el
    // escapado de una respuesta que hoy está bien.
    $opcionesDeJson = ($_ENV['APP_ENV'] ?? 'development') !== 'production'
        ? JSON_PRETTY_PRINT
        : 0;

    error_log('Bootstrap Error: ' . $e->getMessage());
    echo json_encode($response, $opcionesDeJson);
    exit(1);
}
