<?php

declare(strict_types=1);

namespace App\Router;

use App\Middleware\RateLimitMiddleware;

/**
 * El rate limit de las rutas `GET`, que hoy no pasan por ningún middleware.
 *
 * **El problema que resuelve.** Las tres divergencias `GET` del backend
 * (`CatalogHttpRouter`, `ImageHttpRouter`) se desvían en `public/index.php`
 * **antes** de construir `Application`, así que no ejecutan el pipeline de
 * middlewares: ninguna ruta `GET` está limitada. Para el catálogo de MTGJSON eso
 * es inofensivo —es dato público y reconstruible—, pero el
 * Plan - Perfil Público y Mazos Compartibles abre rutas `GET` sobre **datos
 * personales**, y ahí una ruta sin límite es un scraper de colecciones y un
 * enumerador de usuarios.
 *
 * **Por qué NO hay un limitador nuevo.** `RateLimitMiddleware` no depende de
 * `Application` ni del `ActionRouter`: solo de `FileRateLimitStore` y del logger,
 * y su `handle()` pide un array de petición y un `$next`. Se puede invocar desde
 * un router `GET` tal cual, y eso es lo que hace esta clase: traduce entre el
 * contrato del middleware (`array` con `http_code`) y el de los routers `GET`
 * (`[código, cuerpo]`). Un contador propio habría duplicado el concepto y, sobre
 * todo, habría duplicado el interruptor: `RATE_LIMIT_ENABLED` sigue apagándolo
 * todo desde un solo sitio.
 *
 * **La clave del contador es el GRUPO, no la ruta.** Esto es lo que hay que leer
 * antes de tocar nada: `RateLimitMiddleware` construye la clave con
 * `$request['action']`, así que si aquí se pasara la URI, cada `username` tendría
 * su propio contador y **recorrer un diccionario de nombres no gastaría límite
 * ninguno** — justo el ataque que esto existe para frenar. Por eso todas las
 * rutas de un mismo grupo comparten un contador por IP.
 *
 * `by` es siempre `'ip'`: un desvío anterior a `Application` no tiene sesión
 * arrancada, y una ruta pública se pide sobre todo **sin** cuenta.
 */
final class HttpRateLimitGuard
{
    /**
     * El grupo de las rutas públicas sobre dato de usuario. Un contador por IP
     * para todas ellas juntas.
     */
    public const GRUPO_PUBLICO = 'public_get';

    /**
     * 60 peticiones por minuto y por IP. Es el mismo techo que la variable
     * `RATE_LIMIT_MAX_REQUESTS` da hoy a las acciones `POST`, y aquí se escribe
     * en código a propósito: el techo de las acciones se afloja para una ráfaga
     * de escritura legítima, y este no debería moverse con él. Un humano
     * mirando un perfil no pasa de una decena de peticiones por minuto; 60 deja
     * sitio de sobra y sigue haciendo inviable copiar una colección paginada.
     */
    private const LIMITE_POR_DEFECTO = 60;

    /** Ventana fija, en segundos. */
    private const VENTANA_POR_DEFECTO = 60;

    public function __construct(private readonly RateLimitMiddleware $middleware)
    {
    }

    /**
     * ¿Esta petición se pasa del límite del grupo?
     *
     * Devuelve `null` cuando pasa —el router sigue como si nada— y la respuesta
     * `[429, …]` ya lista cuando no. Las cabeceras `X-RateLimit-*` y
     * `Retry-After` las pone el middleware, igual que en las acciones `POST`.
     *
     * @param  string $grupo            Contador compartido; ver la nota de arriba.
     * @return array{0: int, 1: array<string, mixed>}|null
     */
    public function comprobar(
        string $grupo = self::GRUPO_PUBLICO,
        int $limite = self::LIMITE_POR_DEFECTO,
        int $ventanaSegundos = self::VENTANA_POR_DEFECTO
    ): ?array {
        $this->middleware->setConfig([
            'limit'  => $limite,
            'window' => $ventanaSegundos,
            'by'     => 'ip',
        ]);

        // El `$next` no hace nada: aquí el middleware no envuelve a nadie, se le
        // pregunta. Quien responde de verdad es el router, después.
        $respuesta = $this->middleware->handle(
            ['action' => $grupo],
            static fn (array $peticion): array => ['status' => 'success']
        );

        if (($respuesta['http_code'] ?? 200) !== 429) {
            return null;
        }

        return [429, ['error' => 'rate_limited']];
    }
}
