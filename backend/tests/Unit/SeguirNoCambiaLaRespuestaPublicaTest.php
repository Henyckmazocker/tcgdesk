<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Application\UseCase\AddCardToDeck;
use App\Application\UseCase\AddToCollection;
use App\Application\UseCase\CreateDeck;
use App\Application\UseCase\GetDeck;
use App\Application\UseCase\GetSharedDeck;
use App\Application\UseCase\ListCollection;
use App\Application\UseCase\ListDecks;
use App\Application\UseCase\ListSetProgress;
use App\Application\UseCase\Seguir;
use App\Application\UseCase\ValueCollection;
use App\Controllers\FollowController;
use App\Domain\Repository\FollowRepositoryInterface;
use App\Domain\Repository\FriendshipRepositoryInterface;
use App\Domain\Social\Nivel;
use App\Domain\Social\Seccion;
use App\Domain\Social\Visibilidad;
use App\Infrastructure\Auth\EspectadorActual;
use App\Infrastructure\Auth\JwtService;
use App\Infrastructure\RateLimit\FileRateLimitStore;
use App\Middleware\RateLimitMiddleware;
use App\Router\HttpRateLimitGuard;
use App\Router\PublicHttpRouter;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use ReflectionClass;
use ReflectionNamedType;
use Tests\Unit\Doubles\AmistadesFalsas;
use Tests\Unit\Doubles\ColeccionFalsa;
use Tests\Unit\Doubles\MazosFalsos;
use Tests\Unit\Doubles\PrivacidadFalsa;
use Tests\Unit\Doubles\SeguimientosFalsos;
use Tests\Unit\Doubles\UsuariosFalsos;

/**
 * **El *Hecho cuando:* del M3 del Plan - Amigos y Seguimiento, fijado en la
 * suite:** seguir a alguien no cambia **ni un campo** de lo que su perfil te
 * enseña.
 *
 * Se comprueba de las dos maneras que hacen falta, porque ninguna sola bastaría:
 *
 *  1. **Por comportamiento, comparando bytes.** Se piden las cinco rutas de
 *     `/api/public/user/X` con la sesión del espectador abierta, se sigue a esa
 *     persona **de verdad** —el use case real, sobre el mismo doble que usaría
 *     el controller— y se vuelven a pedir las cinco. Las respuestas tienen que
 *     ser idénticas **byte a byte**, no «equivalentes»: es la palabra que usa el
 *     plan y la única comparación que no deja hueco para un campo nuevo. Se
 *     repite con el perfil abierto, con el perfil en `friends` y con el perfil
 *     cerrado, porque el fallo que se teme —que seguir abra algo— solo se vería
 *     en los dos últimos.
 *  2. **Por estructura, con reflexión.** Lo anterior pasaría en verde también el
 *     día en que alguien metiera el puerto de seguimiento en `Visibilidad` «sin
 *     usarlo todavía», que es exactamente como empezó el M0 con el de amistad. Y
 *     ese es el paso que hay que impedir, no el siguiente: cuando el puerto ya
 *     está en el constructor, escribir el `OR` es una línea. Así que se afirma
 *     que **ni `Visibilidad` ni `PublicHttpRouter` reciben
 *     `FollowRepositoryInterface`**, y que el camino inverso tampoco existe —el
 *     `FollowController` no recibe el puerto de amistad ni `Visibilidad`—.
 *
 * Y de propina la **prueba del seguidor** que el plan pide en su sección de
 * verificación: la respuesta que ve un seguidor con sesión es la misma que la de
 * un desconocido sin sesión ninguna. Seguir no es un grado intermedio entre
 * anónimo y amigo; **no es nada**.
 */
final class SeguirNoCambiaLaRespuestaPublicaTest extends TestCase
{
    private const DUENYO = 'vecina';

    private UsuariosFalsos $usuarios;

    private PrivacidadFalsa $privacidad;

    private AmistadesFalsas $amistades;

    private SeguimientosFalsos $seguimientos;

    private ColeccionFalsa $coleccion;

    private MazosFalsos $mazos;

    private int $duenyoId;

    private int $seguidorId;

    private string $directorio;

    /** @var array<string, mixed> */
    private array $entornoPrevio = [];

    private ?string $ipPrevia = null;

    protected function setUp(): void
    {
        $this->directorio = sys_get_temp_dir() . '/tcgdesk-seguir-' . bin2hex(random_bytes(6));

        foreach (['RATE_LIMIT_ENABLED', 'JWT_SECRET'] as $clave) {
            $this->entornoPrevio[$clave] = $_ENV[$clave] ?? null;
        }

        // Lo que aquí se mira es la respuesta, no el límite: el límite tiene su
        // propio test en `PublicHttpRouterTest`.
        $_ENV['RATE_LIMIT_ENABLED'] = 'false';
        $_ENV['JWT_SECRET']         = str_repeat('secreto-de-test-', 2);

        $this->ipPrevia         = $_SERVER['REMOTE_ADDR'] ?? null;
        $_SERVER['REMOTE_ADDR'] = '203.0.113.9';

        $this->usuarios     = new UsuariosFalsos();
        $this->privacidad   = new PrivacidadFalsa();
        $this->amistades    = new AmistadesFalsas();
        $this->seguimientos = new SeguimientosFalsos();
        $this->coleccion    = new ColeccionFalsa();
        $this->mazos        = new MazosFalsos();

        $this->duenyoId   = $this->usuarios->alta(self::DUENYO, 'La Vecina', 'https://example.test/v.png');
        $this->seguidorId = $this->usuarios->alta('henyckma', 'David');

        // Algo que enseñar en las cuatro secciones, para que «no cambia nada» no
        // sea trivialmente cierto sobre respuestas vacías.
        $anadir = new AddToCollection($this->coleccion);
        $anadir($this->duenyoId, ['printing_uuid' => 'u-sol-ring', 'quantity' => 2, 'notes' => 'comprada por 3 €']);
        $anadir($this->duenyoId, ['printing_uuid' => 'u-deseada', 'quantity' => 1, 'is_wishlist' => true]);

        $mazoId = (int) (new CreateDeck($this->mazos))($this->duenyoId, [
            'name'   => 'Atraxa',
            'status' => 'built',
        ])['deck']['id'];

        (new AddCardToDeck($this->mazos))($this->duenyoId, [
            'deck_id'       => $mazoId,
            'printing_uuid' => 'u-sol-ring',
            'count'         => 1,
        ]);

        $this->coleccion->progresoSets = [[
            'setCode'        => 'C21',
            'setName'        => 'Commander 2021',
            'releaseDate'    => '2021-04-23',
            'totalSetSize'   => 100,
            'ownedPrintings' => 25,
            'items'          => 25,
            'copies'         => 30,
            'valueEur'       => 123.45,
        ]];
        $this->coleccion->setsDelCatalogo = 868;

        $_GET     = [];
        $_SESSION = [];
        $_COOKIE  = [];
        http_response_code(200);
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

        $_GET     = [];
        $_SESSION = [];
        $_COOKIE  = [];
    }

    // =====================================================================
    // 1. Por comportamiento: las mismas cinco respuestas, byte a byte
    // =====================================================================

    /**
     * El perfil abierto de par en par. Es el caso en el que seguir tiene
     * sentido, y donde más campos hay que comparar.
     */
    public function testConElPerfilEnEveryoneSeguirNoCambiaNiUnByte(): void
    {
        $this->privacidad->todasEn($this->duenyoId, Nivel::Todos);

        $this->compararAntesYDespuesDeSeguir();
    }

    /**
     * Con el perfil en `friends`, que es donde el fallo haría daño: si seguir se
     * colara como amistad, aquí aparecerían de golpe la colección y el valor.
     *
     * Se sigue montando el seguimiento aunque `follow_add` rechazaría este
     * perfil con un 422 —no tiene ninguna sección en `everyone`—, porque lo que
     * se prueba no es la puerta de entrada sino que **una fila en `user_follow`
     * no abre nada**, la haya puesto quien la haya puesto y como haya llegado.
     */
    public function testConElPerfilEnFriendsSeguirSigueSinAbrirAbsolutamenteNada(): void
    {
        $this->privacidad->todasEn($this->duenyoId, Nivel::Amigos);

        $antes = $this->capturar($this->seguidorId);

        $this->seguimientos->siguiendo($this->seguidorId, $this->duenyoId);

        self::assertSame($antes, $this->capturar($this->seguidorId));

        // Y sigue siendo un 403 en las cuatro secciones, no un 403 «distinto».
        foreach ($antes as $uri => $respuesta) {
            if ($uri !== '/api/public/user/' . self::DUENYO) {
                self::assertStringContainsString('not_visible', $respuesta, $uri . ' tenía que seguir cerrada.');
            }
        }
    }

    /** Y con el perfil entero en `nobody`, por si acaso. */
    public function testConElPerfilEnNobodySeguirTampocoCambiaNada(): void
    {
        $this->privacidad->todasEn($this->duenyoId, Nivel::Nadie);

        $antes = $this->capturar($this->seguidorId);

        $this->seguimientos->siguiendo($this->seguidorId, $this->duenyoId);

        self::assertSame($antes, $this->capturar($this->seguidorId));
    }

    /**
     * El caso mixto, que es el realista: `collection` abierta y el resto cerrado.
     * Aquí `follow_add` sí deja seguir, y aun así el mapa `visible` del perfil
     * tiene que salir idéntico.
     */
    public function testConUnPerfilMixtoElMapaVisibleEsElMismoAntesYDespues(): void
    {
        $this->privacidad->todasEn($this->duenyoId, Nivel::Nadie);
        $this->privacidad->pon($this->duenyoId, Seccion::Coleccion, Nivel::Todos);
        $this->privacidad->pon($this->duenyoId, Seccion::Valor, Nivel::Amigos);

        $this->compararAntesYDespuesDeSeguir();
    }

    /**
     * **La prueba del seguidor, literal del plan:** la respuesta que ve un
     * seguidor con sesión es byte a byte la de un desconocido sin sesión
     * ninguna. Seguir no es un grado intermedio; no es nada.
     */
    public function testLaRespuestaParaUnSeguidorEsLaDeUnDesconocidoSinSesion(): void
    {
        $this->privacidad->todasEn($this->duenyoId, Nivel::Todos);
        $this->privacidad->pon($this->duenyoId, Seccion::Valor, Nivel::Amigos);

        $this->seguir();

        self::assertSame(
            $this->capturar(null),
            $this->capturar($this->seguidorId),
            'Un seguidor tiene que ver exactamente lo mismo que alguien que pasaba por ahí.'
        );
    }

    // =====================================================================
    // 2. Por estructura: el puerto no llega a donde se deciden los permisos
    // =====================================================================

    /**
     * **El paso que hay que impedir, y no el siguiente.** Cuando el puerto ya
     * está en el constructor «sin usarlo todavía», escribir el `OR` que une las
     * dos tablas es una línea — y así empezó, legítimamente, el de amistad en el
     * M0. Aquí no hay ningún M0 que justificarlo: seguir no decide nada.
     */
    public function testVisibilidadNoRecibeElPuertoDeSeguimiento(): void
    {
        self::assertNotContains(
            FollowRepositoryInterface::class,
            $this->tiposDelConstructor(Visibilidad::class),
            'Si user_follow entra en Visibilidad, `friends` pasa a significar «cualquiera que pulse seguir».'
        );
    }

    public function testElRouterPublicoNoRecibeElPuertoDeSeguimiento(): void
    {
        self::assertNotContains(
            FollowRepositoryInterface::class,
            $this->tiposDelConstructor(PublicHttpRouter::class),
            'El router público compone la respuesta: con esto a mano, «un campito de seguidores» es un renglón.'
        );
    }

    /** Y el camino inverso tampoco: seguir no llega a la amistad ni a los permisos. */
    public function testElControllerDeSeguirNoRecibeNiLaAmistadNiVisibilidad(): void
    {
        $tipos = $this->tiposDelConstructor(FollowController::class);

        self::assertNotContains(FriendshipRepositoryInterface::class, $tipos);
        self::assertNotContains(Visibilidad::class, $tipos);
    }

    /**
     * Los dos puertos siguen siendo dos, y el de permisos sigue siendo el de
     * amistad: `Visibilidad` recibe la privacidad y las amistades, y nada más.
     */
    public function testVisibilidadSigueRecibiendoExactamenteLosDosPuertosDeSiempre(): void
    {
        $tipos = $this->tiposDelConstructor(Visibilidad::class);

        self::assertCount(2, $tipos);
        self::assertContains(FriendshipRepositoryInterface::class, $tipos);
    }

    // =====================================================================

    /**
     * Captura las cinco rutas, sigue de verdad, y vuelve a capturarlas.
     */
    private function compararAntesYDespuesDeSeguir(): void
    {
        $antes = $this->capturar($this->seguidorId);

        $this->seguir();

        self::assertTrue(
            $this->seguimientos->hayMarcador($this->seguidorId, $this->duenyoId),
            'El marcador tiene que estar puesto de verdad, o esto no prueba nada.'
        );

        self::assertSame(
            $antes,
            $this->capturar($this->seguidorId),
            'Seguir a alguien no puede cambiar ni un byte de lo que su perfil enseña.'
        );
    }

    /** Seguir de verdad, con el use case real y el mismo doble del controller. */
    private function seguir(): void
    {
        (new Seguir($this->seguimientos, $this->usuarios, $this->privacidad))(
            $this->seguidorId,
            ['username' => self::DUENYO]
        );
    }

    /**
     * Las cinco rutas del perfil, en crudo. **Cadenas y no arrays
     * decodificados**: la comparación del plan es byte a byte, y un `json_decode`
     * borraría diferencias de orden o de tipo.
     *
     * @return array<string, string> uri → cuerpo, con el código HTTP delante
     */
    private function capturar(?int $espectadorId): array
    {
        $base = '/api/public/user/' . self::DUENYO;

        $capturas = [];

        foreach ([$base, $base . '/collection', $base . '/decks', $base . '/sets', $base . '/wishlist'] as $uri) {
            $_SESSION = $espectadorId === null ? [] : ['user_data' => ['id' => $espectadorId]];

            http_response_code(200);

            ob_start();
            $this->router()->handle($uri);
            $cuerpo = (string) ob_get_clean();

            // El código va dentro de la cadena a propósito: un 403 que se
            // convirtiera en 200 con el mismo cuerpo sería justo el fallo que
            // este test busca, y comparando solo el cuerpo se escaparía.
            $capturas[$uri] = http_response_code() . ' ' . $cuerpo;
        }

        $_SESSION = [];

        return $capturas;
    }

    private function router(): PublicHttpRouter
    {
        $logger = new NullLogger();

        return new PublicHttpRouter(
            new HttpRateLimitGuard(
                new RateLimitMiddleware(new FileRateLimitStore($this->directorio, $logger), $logger)
            ),
            new EspectadorActual(new JwtService($logger), $logger),
            $this->usuarios,
            // Los DOS puertos de siempre. Que aquí no quepa el de seguimiento no
            // es una omisión del test: es el hito.
            new Visibilidad($this->privacidad, $this->amistades),
            new ListCollection($this->coleccion),
            new ListDecks($this->mazos),
            new ListSetProgress($this->coleccion),
            new ValueCollection($this->coleccion),
            new GetSharedDeck($this->mazos, new GetDeck($this->mazos)),
            $logger
        );
    }

    /**
     * Los tipos de los parámetros del constructor de una clase.
     *
     * @param  class-string $clase
     * @return list<string>
     */
    private function tiposDelConstructor(string $clase): array
    {
        $constructor = (new ReflectionClass($clase))->getConstructor();

        self::assertNotNull($constructor, $clase . ' tiene que seguir teniendo constructor.');

        return array_map(
            static function ($parametro): string {
                $tipo = $parametro->getType();

                return $tipo instanceof ReflectionNamedType ? $tipo->getName() : '';
            },
            $constructor->getParameters()
        );
    }
}
