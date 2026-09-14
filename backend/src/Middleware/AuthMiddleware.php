<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Infrastructure\Auth\Espectador;
use App\Infrastructure\Auth\EspectadorActual;
use Psr\Log\LoggerInterface;

/**
 * Authentication Middleware
 * Verifies that user is authenticated before proceeding.
 * Supports two methods:
 *   1. PHP session cookie (web browser)
 *   2. Authorization: Bearer <jwt> header (mobile / Capacitor)
 *
 * **Quién identifica al usuario ya no es este middleware**: es
 * `EspectadorActual`, y aquí solo se traduce su respuesta a «sigue» o «401».
 * El motivo es del Plan - Perfil Público y Mazos Compartibles: `PublicHttpRouter`
 * se desvía en `public/index.php` antes de construir `Application`, así que no
 * puede pasar por esta pila y necesita exactamente la misma resolución —cookie
 * de sesión o `Bearer`— sin el 401 del final. Duplicarla habría sido tener dos
 * ideas distintas de quién eres según por qué puerta entres.
 *
 * Lo que este middleware sigue decidiendo, y no se ha movido:
 *  - Que **no** identificarse aquí es un 401. En la ruta pública es `null` y ya.
 *  - Que `auth_method` viaje en la petición: `CsrfMiddleware` se salta a sí mismo
 *    cuando la autenticación fue por JWT, y esa marca la pone este middleware.
 */
class AuthMiddleware implements MiddlewareInterface
{
    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly EspectadorActual $espectador
    ) {
    }

    public function handle(array $request, callable $next): array
    {
        $quien = $this->espectador->resolver();

        if ($quien !== null) {
            $request['user_id']     = $quien->id;
            $request['auth_method'] = $quien->metodo;

            if ($quien->metodo === Espectador::POR_JWT) {
                $this->logger->debug('User authenticated via JWT', [
                    'user_id' => $quien->id,
                    'action'  => $request['action'] ?? 'unknown',
                ]);
            }

            return $next($request);
        }

        // --- Authentication failed ---
        $this->logger->warning('Authentication failed - No user session', [
            'action' => $request['action'] ?? 'unknown',
            'ip'     => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
        ]);

        // 'http_code', NO 'code' como en LibraryVue: Application::run() solo lee
        // 'http_code', así que allí este 401 sale al cliente convertido en 400.
        return [
            'status'    => 'error',
            'message'   => 'Authentication required. Please log in.',
            'http_code' => 401,
        ];
    }
}
