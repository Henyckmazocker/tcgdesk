<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Controllers\AuthController;
use App\Domain\GoogleAuthClientInterface;
use App\Domain\GoogleIdentity;
use App\Domain\Model\User;
use App\Domain\Repository\UserRepositoryInterface;
use App\Infrastructure\Auth\JwtService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/** Google falso: devuelve la identidad que se le diga, o null. */
final class FakeGoogleAuthClient implements GoogleAuthClientInterface
{
    public function __construct(private readonly ?GoogleIdentity $identity)
    {
    }

    public function verifyIdToken(string $idToken): ?GoogleIdentity
    {
        return $this->identity;
    }
}

/** Repositorio en memoria; evita MySQL para probar reglas de negocio. */
final class InMemoryUserRepository implements UserRepositoryInterface
{
    /** @var User[] */
    public array $users = [];
    public array $profileUpdates = [];
    private int $nextId = 1;

    public function findByGoogleId(string $googleId): ?User
    {
        foreach ($this->users as $user) {
            if ($user->googleId === $googleId) {
                return $user;
            }
        }
        return null;
    }

    public function findById(int $id): ?User
    {
        return $this->users[$id] ?? null;
    }

    public function findByUsername(string $username): ?User
    {
        foreach ($this->users as $user) {
            // Sin distinguir mayúsculas, como la colación utf8mb4_unicode_ci de
            // la columna. Lo usa la ruta pública de perfil del M3.
            if (mb_strtolower($user->username) === mb_strtolower($username)) {
                return $user;
            }
        }
        return null;
    }

    public function usernameExists(string $username): bool
    {
        return $this->findByUsername($username) !== null;
    }

    public function create(User $user): User
    {
        $id = $this->nextId++;

        $stored = new User(
            id: $id,
            googleId: $user->googleId,
            email: $user->email,
            username: $user->username,
            displayName: $user->displayName,
            avatarUrl: $user->avatarUrl,
            createdAt: '2026-08-11 12:00:00',
        );

        $this->users[$id] = $stored;

        return $stored;
    }

    public function updateProfileFromGoogle(int $id, ?string $displayName, ?string $avatarUrl): void
    {
        $this->profileUpdates[] = ['id' => $id, 'display_name' => $displayName, 'avatar_url' => $avatarUrl];
    }

    /**
     * El buscador del M6 no entra en el alta por ningún lado, así que aquí no
     * hace falta reproducirlo: lo que sí hace falta es no mentir sobre él. Un
     * array vacío es la respuesta honesta —este doble no tiene la privacidad de
     * nadie— y quien prueba el buscador de verdad es `UsuariosFalsos`, que sí la
     * modela con su `LEFT JOIN` y su defecto para el que no tiene fila.
     */
    public function buscarPorPrefijo(string $prefijo, int $excluyendoId, int $limite): array
    {
        return [];
    }
}

/**
 * El alta es de dos pasos justamente para no autogenerar el `username`, que es
 * URL pública y permanente. Estos tests fijan ese contrato.
 */
final class AuthControllerTest extends TestCase
{
    private const IDENTITY_ARGS = ['sub-123', 'david@example.com', 'David', 'https://cdn/avatar.png'];

    protected function setUp(): void
    {
        $_ENV['JWT_SECRET']    = 'un-secreto-de-pruebas-suficientemente-largo-123456';
        $_ENV['JWT_EXPIRATION'] = '3600';
    }

    private function controller(
        ?GoogleIdentity $identity,
        InMemoryUserRepository $repo
    ): AuthController {
        return new AuthController(
            new FakeGoogleAuthClient($identity),
            $repo,
            new JwtService(new NullLogger()),
            new NullLogger()
        );
    }

    private function identity(): GoogleIdentity
    {
        return new GoogleIdentity(...self::IDENTITY_ARGS);
    }

    public function testRejectsARequestWithoutIdToken(): void
    {
        $response = $this->controller($this->identity(), new InMemoryUserRepository())
            ->login(['data' => []]);

        self::assertSame('error', $response['status']);
        self::assertSame(400, $response['http_code']);
    }

    public function testRejectsAnInvalidGoogleTokenWith401(): void
    {
        $response = $this->controller(null, new InMemoryUserRepository())
            ->login(['data' => ['id_token' => 'basura']]);

        self::assertSame(401, $response['http_code']);
    }

    public function testFirstLoginWithoutUsernameDoesNotCreateTheUser(): void
    {
        $repo     = new InMemoryUserRepository();
        $response = $this->controller($this->identity(), $repo)
            ->login(['data' => ['id_token' => 'ok']]);

        self::assertSame('success', $response['status']);
        self::assertTrue($response['data']['needs_username']);
        self::assertSame('david@example.com', $response['data']['google_profile']['email']);

        // Lo que de verdad importa: no hay alta a medias en la base de datos.
        self::assertSame([], $repo->users);
        self::assertArrayNotHasKey('token', $response['data']);
    }

    public function testFirstLoginWithUsernameCreatesTheUserAndIssuesAToken(): void
    {
        $repo     = new InMemoryUserRepository();
        $response = $this->controller($this->identity(), $repo)
            ->login(['data' => ['id_token' => 'ok', 'username' => 'davidca']]);

        self::assertSame('success', $response['status']);
        self::assertCount(1, $repo->users);

        $created = $repo->users[1];
        self::assertSame('davidca', $created->username);
        self::assertSame('sub-123', $created->googleId);
        self::assertSame('david@example.com', $created->email);

        self::assertNotEmpty($response['data']['token']);
        self::assertSame('davidca', $response['data']['user']['username']);

        // El google_id nunca sale al cliente.
        self::assertArrayNotHasKey('google_id', $response['data']['user']);
    }

    public function testTheIssuedTokenCarriesTheUserId(): void
    {
        $repo     = new InMemoryUserRepository();
        $response = $this->controller($this->identity(), $repo)
            ->login(['data' => ['id_token' => 'ok', 'username' => 'davidca']]);

        $payload = (new JwtService(new NullLogger()))->validate($response['data']['token']);

        self::assertNotNull($payload);
        self::assertSame(1, $payload['user_id']);
    }

    public static function invalidUsernames(): array
    {
        return [
            'demasiado corto' => ['ab'],
            'demasiado largo' => [str_repeat('a', 33)],
            'con espacios'    => ['david ca'],
            'con acentos'     => ['davíd'],
            'con arroba'      => ['david@ca'],
        ];
    }

    #[DataProvider('invalidUsernames')]
    public function testRejectsInvalidUsernamesWith422(string $username): void
    {
        $repo     = new InMemoryUserRepository();
        $response = $this->controller($this->identity(), $repo)
            ->login(['data' => ['id_token' => 'ok', 'username' => $username]]);

        self::assertSame(422, $response['http_code']);
        self::assertSame([], $repo->users);
    }

    public function testRejectsATakenUsernameWith409(): void
    {
        $repo = new InMemoryUserRepository();
        $repo->create(new User(null, 'otro-sub', 'otro@example.com', 'davidca'));

        $response = $this->controller($this->identity(), $repo)
            ->login(['data' => ['id_token' => 'ok', 'username' => 'davidca']]);

        self::assertSame(409, $response['http_code']);
        self::assertCount(1, $repo->users);
    }

    public function testReturningUserLogsInWithoutNeedingAUsername(): void
    {
        $repo = new InMemoryUserRepository();
        $repo->create(new User(null, 'sub-123', 'david@example.com', 'davidca'));

        $response = $this->controller($this->identity(), $repo)
            ->login(['data' => ['id_token' => 'ok']]);

        self::assertSame('success', $response['status']);
        self::assertArrayNotHasKey('needs_username', $response['data']);
        self::assertNotEmpty($response['data']['token']);
        self::assertCount(1, $repo->users, 'No debe crear un segundo usuario.');
    }

    public function testReturningUserGetsProfileRefreshedFromGoogle(): void
    {
        $repo = new InMemoryUserRepository();
        $repo->create(new User(null, 'sub-123', 'david@example.com', 'davidca', 'Nombre viejo'));

        $this->controller($this->identity(), $repo)->login(['data' => ['id_token' => 'ok']]);

        self::assertSame(
            [['id' => 1, 'display_name' => 'David', 'avatar_url' => 'https://cdn/avatar.png']],
            $repo->profileUpdates
        );
    }

    public function testCheckAuthWithoutAKnownUserReturns401(): void
    {
        $response = $this->controller($this->identity(), new InMemoryUserRepository())
            ->checkAuth(['user_id' => 99]);

        self::assertSame(401, $response['http_code']);
    }

    public function testCheckAuthReturnsThePublicUser(): void
    {
        $repo = new InMemoryUserRepository();
        $repo->create(new User(null, 'sub-123', 'david@example.com', 'davidca'));

        $response = $this->controller($this->identity(), $repo)
            ->checkAuth(['user_id' => 1, 'auth_method' => 'jwt']);

        self::assertSame('success', $response['status']);
        self::assertSame('davidca', $response['data']['user']['username']);
        self::assertSame('jwt', $response['data']['auth_method']);
    }
}
