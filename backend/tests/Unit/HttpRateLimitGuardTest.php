<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Infrastructure\RateLimit\FileRateLimitStore;
use App\Middleware\RateLimitMiddleware;
use App\Router\HttpRateLimitGuard;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Que una ruta `GET` se pueda limitar reutilizando el middleware de siempre.
 *
 * Es el M0 del Plan - Perfil Público y Mazos Compartibles, y lo que protege, por
 * orden de importancia:
 *
 *  1. **Que la ráfaga se corta.** Si esto deja de devolver 429, las rutas del
 *     perfil público quedan abiertas a copiar colecciones enteras, y el fallo es
 *     silencioso: todo responde 200, que es lo que parece correcto.
 *  2. **Que el contador es del GRUPO y no de la URL.** El middleware construye
 *     la clave con `$request['action']`; si alguien le pasara la URI, cada
 *     `username` tendría contador propio y **enumerar usuarios no gastaría
 *     límite**. Aquí se comprueba que dos grupos distintos no se mezclan, que es
 *     la otra cara de lo mismo.
 *  3. **Que separa por IP**, porque un contador global convertiría a cualquier
 *     visitante en una denegación de servicio para todos los demás.
 *  4. **Que `RATE_LIMIT_ENABLED` sigue apagándolo todo desde un solo sitio.**
 *     Duplicar el interruptor es cómo se acaba con un límite apagado a medias.
 *
 * Las cabeceras `X-RateLimit-*` no se pueden inspeccionar bajo el SAPI de CLI,
 * así que lo que se comprueba es el veredicto: `null` pasa, `[429, …]` corta.
 */
final class HttpRateLimitGuardTest extends TestCase
{
    private string $directorio;

    /** @var array<string, mixed> */
    private array $entornoPrevio = [];

    private ?string $ipPrevia = null;

    protected function setUp(): void
    {
        $this->directorio = sys_get_temp_dir() . '/tcgdesk-ratelimit-' . bin2hex(random_bytes(6));

        foreach (['RATE_LIMIT_ENABLED', 'RATE_LIMIT_MAX_REQUESTS', 'RATE_LIMIT_WINDOW_MINUTES'] as $clave) {
            $this->entornoPrevio[$clave] = $_ENV[$clave] ?? null;
        }

        $this->ipPrevia          = $_SERVER['REMOTE_ADDR'] ?? null;
        $_ENV['RATE_LIMIT_ENABLED'] = 'true';
        $this->desdeIp('203.0.113.7');
    }

    protected function tearDown(): void
    {
        foreach ($this->entornoPrevio as $clave => $valor) {
            if ($valor === null) {
                unset($_ENV[$clave]);
            } else {
                $_ENV[$clave] = $valor;
            }
        }

        if ($this->ipPrevia === null) {
            unset($_SERVER['REMOTE_ADDR']);
        } else {
            $_SERVER['REMOTE_ADDR'] = $this->ipPrevia;
        }

        foreach (glob($this->directorio . '/*.json') ?: [] as $fichero) {
            @unlink($fichero);
        }

        @rmdir($this->directorio);
    }

    /** Las peticiones vienen de esta IP a partir de ahora. */
    private function desdeIp(string $ip): void
    {
        $_SERVER['REMOTE_ADDR'] = $ip;
    }

    private function guardia(): HttpRateLimitGuard
    {
        $logger = new NullLogger();

        return new HttpRateLimitGuard(
            new RateLimitMiddleware(new FileRateLimitStore($this->directorio, $logger), $logger)
        );
    }

    public function testPorDebajoDelLimiteDejaPasar(): void
    {
        $guardia = $this->guardia();

        for ($i = 0; $i < 3; $i++) {
            self::assertNull($guardia->comprobar(HttpRateLimitGuard::GRUPO_PUBLICO, 3, 60));
        }
    }

    /**
     * La que sobra corta, y corta con la forma que un router `GET` sabe
     * responder: `[código, cuerpo]`, no el array de las acciones `POST`.
     */
    public function testLaPeticionQueSePasaDevuelve429(): void
    {
        $guardia = $this->guardia();

        for ($i = 0; $i < 3; $i++) {
            $guardia->comprobar(HttpRateLimitGuard::GRUPO_PUBLICO, 3, 60);
        }

        self::assertSame(
            [429, ['error' => 'rate_limited']],
            $guardia->comprobar(HttpRateLimitGuard::GRUPO_PUBLICO, 3, 60)
        );
    }

    /**
     * El *Hecho cuando:* del hito, sin levantar Apache: 100 peticiones seguidas
     * con el techo real de 60/min cortan **antes** de la 100. La primera que
     * corta es la 61.
     */
    public function testCienPeticionesSeguidasCortanAntesDeLaCien(): void
    {
        $guardia  = $this->guardia();
        $primera  = null;

        for ($i = 1; $i <= 100; $i++) {
            if ($guardia->comprobar() !== null && $primera === null) {
                $primera = $i;
            }
        }

        self::assertNotNull($primera, 'Cien peticiones seguidas no han dado un solo 429');
        self::assertSame(61, $primera);
    }

    /**
     * Cada grupo lleva su propio contador: es lo que permitirá que el catálogo
     * tenga un techo distinto (o ninguno) sin tocar el de las rutas públicas.
     */
    public function testCadaGrupoTieneSuPropioContador(): void
    {
        $guardia = $this->guardia();

        for ($i = 0; $i < 4; $i++) {
            $guardia->comprobar(HttpRateLimitGuard::GRUPO_PUBLICO, 3, 60);
        }

        self::assertNotNull($guardia->comprobar(HttpRateLimitGuard::GRUPO_PUBLICO, 3, 60));
        self::assertNull($guardia->comprobar('otro_grupo', 3, 60));
    }

    public function testElLimiteEsPorIp(): void
    {
        $guardia = $this->guardia();

        for ($i = 0; $i < 4; $i++) {
            $guardia->comprobar(HttpRateLimitGuard::GRUPO_PUBLICO, 3, 60);
        }

        self::assertNotNull($guardia->comprobar(HttpRateLimitGuard::GRUPO_PUBLICO, 3, 60));

        $this->desdeIp('203.0.113.8');
        self::assertNull($guardia->comprobar(HttpRateLimitGuard::GRUPO_PUBLICO, 3, 60));
    }

    /** Un solo interruptor, el de siempre. */
    public function testConElRateLimitApagadoNoCortaNada(): void
    {
        $_ENV['RATE_LIMIT_ENABLED'] = 'false';

        $guardia = $this->guardia();

        for ($i = 0; $i < 20; $i++) {
            self::assertNull($guardia->comprobar(HttpRateLimitGuard::GRUPO_PUBLICO, 3, 60));
        }
    }
}
