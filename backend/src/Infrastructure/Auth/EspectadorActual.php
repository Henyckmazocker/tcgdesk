<?php

declare(strict_types=1);

namespace App\Infrastructure\Auth;

use Psr\Log\LoggerInterface;

/**
 * Quién está haciendo esta petición, si es que hay alguien.
 *
 * **Por qué existe.** Esto vivía dentro de `AuthMiddleware` y solo servía para
 * decidir un 401. El Plan - Perfil Público y Mazos Compartibles necesita la
 * misma respuesta en un sitio donde no hay pipeline: `PublicHttpRouter` se
 * desvía en `public/index.php` **antes** de construir `Application`, así que no
 * tiene `AuthMiddleware`, ni sesión arrancada, ni nada. El plan lo dice con
 * todas las letras: *«es código que hoy vive en el middleware y hay que poder
 * reutilizarlo sin arrastrar el pipeline entero»*. Esta clase es ese código, y
 * `AuthMiddleware` pasó a ser quien la usa para decidir el 401.
 *
 * **La diferencia que lo cambia todo: aquí NO identificarse no es un error.**
 * En el middleware, no resolver a nadie es un 401. En una ruta pública es lo
 * normal —quien abre el enlace compartido no tiene cuenta—, así que esto
 * devuelve `null` y nunca lanza: una cookie caducada, un `Bearer` basura o un
 * JWT expirado son **`null`, jamás un 401**. Una ruta pública que responde 401
 * por una cookie vieja rompe el enlace que el plan existe para poder mandar.
 *
 * **La sesión.** `Application::bootstrap()` la arranca con `session_name()` y
 * cinco `ini_set`; el router público no pasa por ahí, así que si hay cookie hay
 * que abrirla aquí — con el MISMO `session_name`, o se leería una sesión
 * distinta y siempre vacía. Se abre en modo `read_and_close` y con las cookies
 * de sesión apagadas, para no tocar nada del visitante: ver `abrirLaSesion()`.
 */
final class EspectadorActual
{
    /**
     * El mismo nombre que pone `Application::bootstrap()`. Está duplicado a
     * sabiendas y es el único acoplamiento de esta clase con aquel método: si
     * alguien lo cambia allí y no aquí, **la sesión se lee vacía y todo el mundo
     * pasa a ser anónimo** —fail-closed, pero silencioso—, así que este comentario
     * es la señal.
     */
    public const NOMBRE_DE_SESION = 'TCGDESK_SESSION';

    /**
     * Un id de sesión de PHP: alfanumérico, coma y guión, y como mucho 128
     * caracteres. Se comprueba ANTES de dárselo a `session_id()` porque un id
     * con caracteres ilegales hace que `session_start()` emita un warning, y un
     * warning en una ruta pública lo provoca cualquiera mandando una cookie a
     * mano.
     */
    private const ID_DE_SESION = '/^[A-Za-z0-9,\-]{1,128}$/';

    /** La sesión se intenta abrir UNA vez por petición, haya o no haya datos. */
    private bool $sesionIntentada = false;

    public function __construct(
        private readonly JwtService $jwt,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * El espectador de esta petición, o `null` si no hay nadie identificado.
     *
     * La sesión va **antes** que el `Bearer`, igual que en `AuthMiddleware`: es
     * el caso del navegador, que es el que manda en las dos rutas donde esto se
     * usa, y evita validar un JWT que no hacía falta.
     */
    public function resolver(): ?Espectador
    {
        return $this->desdeLaSesion() ?? $this->desdeElBearer();
    }

    /** El usuario de la cookie de sesión, si la hay y sigue viva. */
    private function desdeLaSesion(): ?Espectador
    {
        if (!isset($_SESSION['user_data']['id'])) {
            $this->abrirLaSesion();
        }

        $id = $_SESSION['user_data']['id'] ?? null;

        return $id === null ? null : new Espectador((int) $id, Espectador::POR_SESION);
    }

    /**
     * Abrir la sesión del visitante **sin dejarle ni un rastro**.
     *
     * Tres decisiones, y las tres son para que una ruta pública no tenga efectos
     * secundarios sobre quien solo estaba mirando:
     *
     *  - **`read_and_close`**: se lee y se cierra sin reescribir el fichero, así
     *    que ni se toca la fecha de la sesión ni se retiene el bloqueo mientras
     *    el router consulta la base de datos.
     *  - **`use_cookies = 0` y el id puesto a mano**: PHP no manda ningún
     *    `Set-Cookie`. Sin esto, un visitante con una cookie caducada se llevaría
     *    una sesión nueva de regalo por leer un perfil ajeno.
     *  - **`use_strict_mode = 0`**: el modo estricto existe para impedir la
     *    fijación de sesión al **escribir**, y aquí no se escribe nunca. Con él
     *    puesto, un id desconocido haría que PHP generase uno nuevo; sin él, un
     *    id desconocido abre una sesión vacía y el visitante se queda en `null`,
     *    que es exactamente lo que debe pasar.
     *
     * Si no hay cookie, no se arranca nada: un anónimo no necesita sesión y
     * crearle una en cada petición llenaría el disco a base de ficheros vacíos.
     */
    private function abrirLaSesion(): void
    {
        if ($this->sesionIntentada || session_status() === PHP_SESSION_ACTIVE) {
            return;
        }

        $this->sesionIntentada = true;

        $id = $_COOKIE[self::NOMBRE_DE_SESION] ?? null;

        if (!is_string($id) || preg_match(self::ID_DE_SESION, $id) !== 1) {
            return;
        }

        // `headers_sent()` es la misma guarda que usa Application: sin ella,
        // arrancar una sesión después de haber escrito algo es un warning.
        if (headers_sent()) {
            return;
        }

        session_name(self::NOMBRE_DE_SESION);
        session_id($id);

        session_start([
            'read_and_close'  => true,
            'use_cookies'     => '0',
            'use_strict_mode' => '0',
            // Que no toque el `Cache-Control` de la respuesta: quien decide si
            // esto se cachea es el router, y para dato de usuario la respuesta
            // es que no.
            'cache_limiter'   => '',
        ]);
    }

    /**
     * El usuario del `Authorization: Bearer <jwt>`, para Capacitor.
     *
     * Los tres sitios de donde se saca la cabecera son los mismos que miraba
     * `AuthMiddleware`, y no sobra ninguno: Apache la pasa por
     * `HTTP_AUTHORIZATION` o por `REDIRECT_HTTP_AUTHORIZATION` según el SAPI y
     * el `mod_rewrite`, y con algunas configuraciones solo aparece en
     * `getallheaders()`.
     */
    private function desdeElBearer(): ?Espectador
    {
        $cabecera = $_SERVER['HTTP_AUTHORIZATION']
            ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION']
            ?? '';

        if (!is_string($cabecera) || $cabecera === '') {
            $cabecera = '';
        }

        if ($cabecera === '' && function_exists('getallheaders')) {
            $cabeceras = array_change_key_case(getallheaders() ?: [], CASE_LOWER);
            $cabecera  = (string) ($cabeceras['authorization'] ?? '');
        }

        if (!str_starts_with($cabecera, 'Bearer ')) {
            return null;
        }

        // `validate()` ya devuelve null para un token caducado, manipulado o
        // ilegible. Aquí eso NO es un error: es un visitante anónimo.
        $payload = $this->jwt->validate(substr($cabecera, 7));

        if ($payload === null || !isset($payload['user_id'])) {
            return null;
        }

        $this->logger->debug('Espectador resuelto por JWT', [
            'user_id' => $payload['user_id'],
        ]);

        return new Espectador((int) $payload['user_id'], Espectador::POR_JWT);
    }
}
