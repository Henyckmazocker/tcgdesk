<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Domain\GoogleAuthClientInterface;
use App\Domain\Model\User;
use App\Domain\Repository\UserRepositoryInterface;
use App\Infrastructure\Auth\JwtService;
use PDOException;
use Psr\Log\LoggerInterface;

/**
 * Login con Google, alta en el primer acceso y sesión.
 *
 * El alta es de DOS pasos a propósito. El plan avisa de que
 * «el `username` de `users` se pide en el primer login; si se genera automático a
 * partir del email tendrás `david-carvajal-abellan` como URL pública para
 * siempre». Así que:
 *
 *   1. `login` con el ID token → si el usuario no existe, NO se crea: se
 *      responde `needs_username` con el perfil ya verificado.
 *   2. `login` con el ID token **y** `username` → se crea la cuenta.
 *
 * El ID token se revalida en el paso 2; no se guarda estado de registro entre
 * ambas llamadas.
 */
class AuthController extends BaseController
{
    private const USERNAME_PATTERN = '/^[a-zA-Z0-9_-]{3,32}$/';

    public function __construct(
        private readonly GoogleAuthClientInterface $google,
        private readonly UserRepositoryInterface $users,
        private readonly JwtService $jwt,
        private readonly LoggerInterface $logger
    ) {
    }

    public function login(array $request): array
    {
        $idToken = $request['data']['id_token'] ?? '';

        if (!is_string($idToken) || $idToken === '') {
            return $this->errorResponse('Falta el id_token de Google.', 400);
        }

        $identity = $this->google->verifyIdToken($idToken);

        if ($identity === null) {
            return $this->errorResponse('El token de Google no es válido.', 401);
        }

        $user = $this->users->findByGoogleId($identity->googleId);

        // --- Usuario ya conocido: entra ---
        if ($user !== null) {
            $this->users->updateProfileFromGoogle($user->id, $identity->displayName, $identity->avatarUrl);
            return $this->startSession($user, 'login');
        }

        // --- Primer acceso: hace falta que elija username ---
        $username = $request['data']['username'] ?? null;

        if ($username === null || $username === '') {
            return $this->successResponse('Elige un nombre de usuario para completar el registro.', [
                'needs_username' => true,
                'google_profile' => [
                    'email'        => $identity->email,
                    'display_name' => $identity->displayName,
                    'avatar_url'   => $identity->avatarUrl,
                ],
            ]);
        }

        if (!is_string($username) || preg_match(self::USERNAME_PATTERN, $username) !== 1) {
            return $this->errorResponse(
                'El nombre de usuario debe tener entre 3 y 32 caracteres: letras, números, guion y guion bajo.',
                422
            );
        }

        if ($this->users->usernameExists($username)) {
            return $this->errorResponse('Ese nombre de usuario ya está cogido.', 409);
        }

        try {
            $user = $this->users->create(new User(
                id:          null,
                googleId:    $identity->googleId,
                email:       $identity->email,
                username:    $username,
                displayName: $identity->displayName,
                avatarUrl:   $identity->avatarUrl,
            ));
        } catch (PDOException $e) {
            // Carrera entre el usernameExists de arriba y este INSERT: el UNIQUE
            // de la tabla es la única garantía real, así que el 409 sale de aquí.
            if ($e->getCode() === '23000') {
                $this->logger->info('Alta rechazada por clave duplicada', ['username' => $username]);
                return $this->errorResponse('Ese nombre de usuario ya está cogido.', 409);
            }
            throw $e;
        }

        $this->logger->info('Usuario dado de alta', ['user_id' => $user->id, 'username' => $user->username]);

        return $this->startSession($user, 'registro');
    }

    public function logout(array $request): array
    {
        $_SESSION = [];

        if (session_status() === PHP_SESSION_ACTIVE) {
            session_destroy();
        }

        return $this->successResponse('Sesión cerrada.');
    }

    /**
     * Quién soy. Protegida por AuthMiddleware, así que si llega aquí hay user_id.
     */
    public function checkAuth(array $request): array
    {
        $userId = $request['user_id'] ?? null;
        $user   = $userId !== null ? $this->users->findById((int) $userId) : null;

        if ($user === null) {
            return $this->errorResponse('Usuario no encontrado.', 401);
        }

        return $this->successResponse('Autenticado.', [
            'user'        => $user->toArray(),
            'csrf_token'  => $_SESSION['csrf_token'] ?? null,
            'auth_method' => $request['auth_method'] ?? null,
        ]);
    }

    /**
     * Deja la sesión web lista y emite el JWT que usa el cliente móvil.
     */
    private function startSession(User $user, string $event): array
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            // Rotar el id de sesión al autenticar corta el session fixation.
            session_regenerate_id(true);

            $_SESSION['user_data']  = ['id' => $user->id, 'username' => $user->username];
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }

        $this->logger->info('Sesión iniciada', ['user_id' => $user->id, 'event' => $event]);

        return $this->successResponse('Sesión iniciada.', [
            'user'       => $user->toArray(),
            'token'      => $this->jwt->issue($user->id),
            'csrf_token' => $_SESSION['csrf_token'] ?? null,
        ]);
    }
}
