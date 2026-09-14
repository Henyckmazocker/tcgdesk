<?php

declare(strict_types=1);

namespace App\Infrastructure\Auth;

/**
 * Quién está mirando: un usuario ya identificado y por qué vía.
 *
 * Es un objeto de valor mínimo a propósito. **No es el usuario**: no trae ni
 * nombre, ni correo, ni privacidad — solo el `id`, que es lo único que necesitan
 * los dos consumidores (`AuthMiddleware` para poblar `$request['user_id']`, y
 * `PublicHttpRouter` para preguntarle a `Visibilidad`). Cargar aquí la fila
 * entera del usuario obligaría a leer `users` en cada petición pública, que es
 * justo lo que una ruta abierta a internet no debe hacer de balde.
 *
 * Que exista el tipo, en vez de devolver un `?int` suelto, es lo que permite
 * distinguir **`null` = nadie** de **un id = alguien**, sin el `0` ambiguo de en
 * medio. En un modelo de permisos, un `0` que se cuela como id de usuario es la
 * diferencia entre «anónimo» y «el usuario 0».
 */
final class Espectador
{
    /** Cómo se identificó: `session` (navegador) o `jwt` (Capacitor). */
    public const POR_SESION = 'session';
    public const POR_JWT    = 'jwt';

    public function __construct(
        public readonly int $id,
        public readonly string $metodo
    ) {
    }
}
