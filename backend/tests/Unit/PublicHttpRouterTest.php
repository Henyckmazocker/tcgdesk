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
use App\Application\UseCase\ShareDeck;
use App\Application\UseCase\UnshareDeck;
use App\Application\UseCase\ValueCollection;
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
use Tests\Unit\Doubles\AmistadesFalsas;
use Tests\Unit\Doubles\ColeccionFalsa;
use Tests\Unit\Doubles\MazosFalsos;
use Tests\Unit\Doubles\PrivacidadFalsa;
use Tests\Unit\Doubles\UsuariosFalsos;

/**
 * Las seis rutas públicas: la primera puerta de TCGDesk hacia fuera —cinco de
 * perfil y la del enlace de un mazo—.
 *
 * Lo que se protege, por orden de importancia — y el orden importa porque aquí
 * **todos los fallos son silenciosos**: una respuesta con datos de más parece
 * perfectamente correcta.
 *
 *  1. **Que `nobody` y `friends` cierran de verdad, sin sesión.** Es el
 *     *Hecho cuando:* del hito y la mitad del plan: `everyone` abre, `nobody`
 *     da 403 `not_visible`, y `friends` da 403 **también**, porque la tabla
 *     `friendships` no existe y el plan lo quiere fail-closed.
 *  2. **Que el `email` no sale por ningún sitio.** El plan: el perfil enseña
 *     `display_name` y `avatar_url`, y el correo **jamás**.
 *  3. **Que una sección no se cuela por la puerta de al lado.** Que
 *     `?is_wishlist=1` en la ruta de colección no sirva la lista de deseos con
 *     el permiso de la colección; que los precios no viajen cuando `value` está
 *     cerrado; que las notas privadas no viajen nunca; y que `missingCount` —lo
 *     que a un mazo le falta **de tu colección**— no viaje con los mazos.
 *  4. **Que el dueño se ve a sí mismo** aunque lo tenga todo en `nobody`, o
 *     cerrar una sección parecería que se han borrado los datos.
 *  5. **Que las rutas están limitadas**, que es la condición que el 🔴 del plan
 *     pone para poder exponer nada de esto.
 *  6. **Que el mazo compartido no lleva el cruce con la colección**, y que su
 *     token inválido es **404 y no 403** —al revés que las cinco de arriba—.
 *     Es la sexta ruta, la única que no pregunta a `Visibilidad`: compartir un
 *     mazo es un acto explícito sobre ese mazo, así que el enlace abre con el
 *     perfil abierto o cerrado, y lo que lo deja del lado seguro es que el
 *     cruce con la colección no viaja y que el token no se enumera.
 *
 * Las cabeceras no se pueden inspeccionar bajo el SAPI de CLI —mismo límite que
 * `CatalogHttpRouterTest`—, así que el `Cache-Control: no-store` se comprueba
 * con `curl` contra el contenedor y aquí se mira el código y el cuerpo.
 */
final class PublicHttpRouterTest extends TestCase
{
    private const DUENYO   = 'henyckma';
    private const EXTRANYO = 'otro';

    private UsuariosFalsos $usuarios;

    private PrivacidadFalsa $privacidad;

    /**
     * Vacío a propósito: aquí no hay ninguna amistad montada, así que el nivel
     * `friends` sigue respondiendo que no a todo el mundo y estos tests siguen
     * probando lo que probaban. Desde el M2 del Plan - Amigos y Seguimiento
     * `Visibilidad` **exige** el puerto —ya no es opcional, porque un defecto
     * haría que PHP-DI lo saltara en producción—, así que hay que pasárselo.
     * Quien prueba la amistad de verdad es `VisibilidadTest`.
     */
    private AmistadesFalsas $amistades;

    private ColeccionFalsa $coleccion;

    private MazosFalsos $mazos;

    private int $duenyoId;

    private int $extranyoId;

    private int $mazoId;

    private string $directorio;

    /** @var array<string, mixed> */
    private array $entornoPrevio = [];

    private ?string $ipPrevia = null;

    protected function setUp(): void
    {
        $this->directorio = sys_get_temp_dir() . '/tcgdesk-publico-' . bin2hex(random_bytes(6));

        foreach (['RATE_LIMIT_ENABLED', 'JWT_SECRET'] as $clave) {
            $this->entornoPrevio[$clave] = $_ENV[$clave] ?? null;
        }

        // Apagado por defecto: lo que estos tests miran son los permisos. El
        // límite tiene su propio test, que lo enciende.
        $_ENV['RATE_LIMIT_ENABLED'] = 'false';
        $_ENV['JWT_SECRET']         = str_repeat('secreto-de-test-', 2);

        $this->ipPrevia         = $_SERVER['REMOTE_ADDR'] ?? null;
        $_SERVER['REMOTE_ADDR'] = '203.0.113.9';

        $this->usuarios   = new UsuariosFalsos();
        $this->privacidad = new PrivacidadFalsa();
        $this->amistades  = new AmistadesFalsas();
        $this->coleccion  = new ColeccionFalsa();
        $this->mazos      = new MazosFalsos();

        $this->duenyoId   = $this->usuarios->alta(self::DUENYO, 'David Carvajal', 'https://example.test/a.png');
        $this->extranyoId = $this->usuarios->alta(self::EXTRANYO, 'Otra Persona');

        $anadir = new AddToCollection($this->coleccion);
        $anadir($this->duenyoId, ['printing_uuid' => 'u-sol-ring', 'quantity' => 2, 'notes' => 'comprada por 3 €']);
        $anadir($this->duenyoId, ['printing_uuid' => 'u-deseada', 'quantity' => 1, 'is_wishlist' => true]);

        $this->mazoId = (int) (new CreateDeck($this->mazos))($this->duenyoId, [
            'name'   => 'Atraxa',
            'status' => 'built',
            'notes'  => 'la lista buena, no la de los torneos',
        ])['deck']['id'];

        // Dos zonas y un token: el mazo compartido del M4 se enseña «con sus
        // cartas, zonas y valor», así que hacen falta las dos.
        $anadirAlMazo = new AddCardToDeck($this->mazos);
        $anadirAlMazo($this->duenyoId, [
            'deck_id'       => $this->mazoId,
            'printing_uuid' => 'u-sol-ring',
            'count'         => 1,
        ]);
        $anadirAlMazo($this->duenyoId, [
            'deck_id'       => $this->mazoId,
            'printing_uuid' => 'u-swords',
            'count'         => 2,
            'board'         => 'side',
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
    // 1. El *Hecho cuando:* del hito: los tres niveles, sin sesión
    // =====================================================================

    public function testConEveryoneUnAnonimoRecibeLaColeccion(): void
    {
        $this->privacidad->pon($this->duenyoId, Seccion::Coleccion, Nivel::Todos);

        $cuerpo = $this->pedir('/api/public/user/' . self::DUENYO . '/collection');

        self::assertSame(200, http_response_code());
        self::assertCount(1, $cuerpo['items']);
        self::assertSame('u-sol-ring', $cuerpo['items'][0]['printingUuid']);
    }

    public function testConNobodyUnAnonimoRecibe403NotVisible(): void
    {
        $this->privacidad->pon($this->duenyoId, Seccion::Coleccion, Nivel::Nadie);

        $cuerpo = $this->pedir('/api/public/user/' . self::DUENYO . '/collection');

        self::assertSame(403, http_response_code());
        self::assertSame(['error' => 'not_visible'], $cuerpo);
    }

    /**
     * `friends` es hoy tan cerrado como `nobody`, y así tiene que seguir hasta
     * que exista `friendships`. Un `true` provisional «que ya arreglaremos»
     * publica la colección de todo el mundo.
     */
    public function testConFriendsTambienEs403PorqueLaAmistadNoExisteTodavia(): void
    {
        $this->privacidad->pon($this->duenyoId, Seccion::Coleccion, Nivel::Amigos);

        $cuerpo = $this->pedir('/api/public/user/' . self::DUENYO . '/collection');

        self::assertSame(403, http_response_code());
        self::assertSame(['error' => 'not_visible'], $cuerpo);

        // Y tampoco se abre para otro usuario con sesión: `friends` es fail-closed
        // para todo el mundo menos para el dueño.
        $_SESSION['user_data']['id'] = $this->extranyoId;

        $this->pedir('/api/public/user/' . self::DUENYO . '/collection');

        self::assertSame(403, http_response_code());
    }

    public function testElDuenyoSeVeASiMismoAunqueLoTengaTodoEnNobody(): void
    {
        $this->privacidad->todasEn($this->duenyoId, Nivel::Nadie);

        $_SESSION['user_data']['id'] = $this->duenyoId;

        $cuerpo = $this->pedir('/api/public/user/' . self::DUENYO . '/collection');

        self::assertSame(200, http_response_code());
        self::assertCount(1, $cuerpo['items']);

        $perfil = $this->pedir('/api/public/user/' . self::DUENYO);

        self::assertSame(
            [true, true, true, true, true],
            array_values($perfil['visible']),
            'Cerrar una sección no puede escondérsela a su dueño'
        );
    }

    // =====================================================================
    // 2. El correo, que no sale
    // =====================================================================

    public function testElPerfilEnsenaNombreYAvatarYJamasElCorreo(): void
    {
        $crudo = $this->pedirCrudo('/api/public/user/' . self::DUENYO);

        self::assertStringNotContainsString('@example.test', $crudo);
        self::assertStringNotContainsString('email', $crudo);

        $cuerpo = json_decode($crudo, true);

        self::assertSame(
            ['username' => self::DUENYO, 'displayName' => 'David Carvajal', 'avatarUrl' => 'https://example.test/a.png'],
            $cuerpo['user']
        );
    }

    public function testNingunaSeccionFiltraElCorreo(): void
    {
        $this->privacidad->todasEn($this->duenyoId, Nivel::Todos);

        foreach (['', '/collection', '/decks', '/sets', '/wishlist'] as $sufijo) {
            $crudo = $this->pedirCrudo('/api/public/user/' . self::DUENYO . $sufijo);

            self::assertStringNotContainsString('@example.test', $crudo, "La ruta '{$sufijo}' filtra el correo");
        }
    }

    // =====================================================================
    // 3. Lo que no se cuela por la puerta de al lado
    // =====================================================================

    /**
     * La colección y los deseos son **dos listas con dos niveles distintos**.
     * Aceptar `is_wishlist` de la query string serviría la segunda con el
     * permiso de la primera.
     */
    public function testLaRutaDeColeccionNoSirveLosDeseosAunqueSePidaPorLaUrl(): void
    {
        $this->privacidad
            ->pon($this->duenyoId, Seccion::Coleccion, Nivel::Todos)
            ->pon($this->duenyoId, Seccion::Deseos, Nivel::Nadie);

        $_GET   = ['is_wishlist' => '1'];
        $cuerpo = $this->pedir('/api/public/user/' . self::DUENYO . '/collection?is_wishlist=1');

        self::assertSame(200, http_response_code());
        self::assertFalse($this->coleccion->ultimosCriterios?->isWishlist);
        self::assertSame(['u-sol-ring'], array_column($cuerpo['items'], 'printingUuid'));
    }

    public function testLaRutaDeDeseosPideLaOtraListaYSeCierraPorSuPropiaSeccion(): void
    {
        // Por defecto `wishlist` nace en `friends`: cerrada.
        $this->pedir('/api/public/user/' . self::DUENYO . '/wishlist');
        self::assertSame(403, http_response_code());

        $this->privacidad->pon($this->duenyoId, Seccion::Deseos, Nivel::Todos);

        $cuerpo = $this->pedir('/api/public/user/' . self::DUENYO . '/wishlist');

        self::assertSame(200, http_response_code());
        self::assertTrue($this->coleccion->ultimosCriterios?->isWishlist);
        self::assertSame(['u-deseada'], array_column($cuerpo['items'], 'printingUuid'));
    }

    /**
     * Las notas de una línea son las notas privadas de su dueño («comprada por
     * 3 €»). No tienen nivel propio y **no salen nunca**: el plan cita el
     * `show_notes` de LibraryVue —que nace a 0— como el criterio de lo sensible.
     */
    public function testLasNotasPrivadasNoViajanNiConLaSeccionAbierta(): void
    {
        $this->privacidad->todasEn($this->duenyoId, Nivel::Todos);

        $crudo = $this->pedirCrudo('/api/public/user/' . self::DUENYO . '/collection');

        self::assertStringNotContainsString('comprada por 3', $crudo);
        self::assertArrayNotHasKey('notes', json_decode($crudo, true)['items'][0]);

        $crudoMazos = $this->pedirCrudo('/api/public/user/' . self::DUENYO . '/decks');

        self::assertStringNotContainsString('la lista buena', $crudoMazos);
        self::assertArrayNotHasKey('notes', json_decode($crudoMazos, true)['decks'][0]);
    }

    /**
     * `missingCount` es lo que a ese mazo le falta **de tu colección**. El plan
     * lo excluye del mazo compartido del M4 con el mismo argumento, y aquí vale
     * igual: es dato de la colección colado con el permiso de los mazos.
     */
    public function testLosMazosNoLlevanElCruceConLaColeccion(): void
    {
        $this->privacidad->todasEn($this->duenyoId, Nivel::Todos);

        $cuerpo = $this->pedir('/api/public/user/' . self::DUENYO . '/decks');

        self::assertSame(200, http_response_code());
        self::assertSame('Atraxa', $cuerpo['decks'][0]['name']);
        self::assertArrayNotHasKey('missingCount', $cuerpo['decks'][0]);
    }

    /**
     * `show_value` es información patrimonial y nace en `friends`. Con la
     * colección abierta pero el valor cerrado, los precios por línea **no
     * viajan**: publicarlos es publicar el total, itemizado.
     */
    public function testConValueCerradoLaColeccionViajaSinPrecios(): void
    {
        $this->privacidad
            ->pon($this->duenyoId, Seccion::Coleccion, Nivel::Todos)
            ->pon($this->duenyoId, Seccion::Valor, Nivel::Nadie);

        $item = $this->pedir('/api/public/user/' . self::DUENYO . '/collection')['items'][0];

        self::assertArrayNotHasKey('priceEur', $item);
        self::assertArrayNotHasKey('lineValue', $item);
        self::assertSame(2, $item['quantity'], 'Cuántas tienes no es cuánto vale: la carta sigue saliendo');

        $this->privacidad->pon($this->duenyoId, Seccion::Valor, Nivel::Todos);

        $abierto = $this->pedir('/api/public/user/' . self::DUENYO . '/collection')['items'][0];

        self::assertArrayHasKey('priceEur', $abierto);
    }

    public function testElValorDeLaColeccionSoloViajaEnElPerfilSiValueEstaAbierto(): void
    {
        // El defecto de `value` es `friends`, así que la clave ni aparece.
        $perfil = $this->pedir('/api/public/user/' . self::DUENYO);

        self::assertFalse($perfil['visible']['value']);
        self::assertArrayNotHasKey('value', $perfil);

        $this->privacidad->pon($this->duenyoId, Seccion::Valor, Nivel::Todos);

        $abierto = $this->pedir('/api/public/user/' . self::DUENYO);

        self::assertTrue($abierto['visible']['value']);
        self::assertArrayHasKey('valueEur', $abierto['value']);
    }

    public function testElProgresoPorEdicionEscondeElDineroPeroNoElProgreso(): void
    {
        $this->privacidad
            ->pon($this->duenyoId, Seccion::Sets, Nivel::Todos)
            ->pon($this->duenyoId, Seccion::Valor, Nivel::Nadie);

        $cuerpo = $this->pedir('/api/public/user/' . self::DUENYO . '/sets');

        self::assertSame(200, http_response_code());
        // Con delta porque JSON no distingue 25.0 de 25, igual que en CatalogHttpRouterTest.
        self::assertEqualsWithDelta(25.0, $cuerpo['sets'][0]['percent'], 0.001);
        self::assertArrayNotHasKey('valueEur', $cuerpo['sets'][0]);
        self::assertSame(868, $cuerpo['totals']['catalogSets']);
    }

    // =====================================================================
    // 4. El perfil, los códigos y las rutas
    // =====================================================================

    /**
     * El perfil dice **sí o no**, nunca el nivel. «Esto está en `friends`» sería
     * un mapa de qué secciones vale la pena volver a intentar.
     */
    public function testElPerfilDiceQueSeVeYNoConQueNivel(): void
    {
        $crudo  = $this->pedirCrudo('/api/public/user/' . self::DUENYO);
        $cuerpo = json_decode($crudo, true);

        self::assertSame(
            ['collection' => true, 'value' => false, 'decks' => true, 'sets' => true, 'wishlist' => false],
            $cuerpo['visible'],
            'Los cinco defectos, con `value` y `wishlist` cerrados'
        );

        foreach (['nobody', 'friends', 'everyone'] as $nivel) {
            self::assertStringNotContainsString($nivel, $crudo);
        }
    }

    /**
     * 404 para el usuario, 403 para la sección. Si una sección cerrada
     * devolviera 404, cerrarla diría «este usuario no existe» — y el `username`
     * es público por diseño.
     */
    public function testUnUsuarioQueNoExisteEs404EnTodasLasRutas(): void
    {
        foreach (['', '/collection', '/decks', '/sets', '/wishlist'] as $sufijo) {
            $cuerpo = $this->pedir('/api/public/user/fantasma' . $sufijo);

            self::assertSame(404, http_response_code(), "La ruta '{$sufijo}'");
            self::assertSame(['error' => 'user_not_found'], $cuerpo);
        }
    }

    public function testElUsernameNoDistingueMayusculasYLlegaDescodificado(): void
    {
        $cuerpo = $this->pedir('/api/public/user/' . rawurlencode(strtoupper(self::DUENYO)));

        self::assertSame(200, http_response_code());
        self::assertSame(self::DUENYO, $cuerpo['user']['username']);
    }

    // =====================================================================
    // 4b. El enlace del mazo (M4): la sexta ruta, y la única que no
    //     pregunta a `Visibilidad`
    // =====================================================================

    /**
     * El *Hecho cuando:* del hito, primera mitad: **sin una sola credencial**,
     * el token devuelve el mazo con sus cartas, sus zonas y su valor.
     */
    public function testUnAnonimoConElTokenRecibeElMazoConSusCartasZonasYValor(): void
    {
        $cuerpo = $this->pedir('/api/public/deck/' . $this->compartir());

        self::assertSame(200, http_response_code());
        self::assertSame('Atraxa', $cuerpo['deck']['name']);
        self::assertSame(['main', 'side'], array_keys($cuerpo['boards']));
        self::assertSame(['u-sol-ring'], array_column($cuerpo['boards']['main'], 'printingUuid'));
        self::assertSame(2, $cuerpo['boards']['side'][0]['count']);
        self::assertCount(2, $cuerpo['cards']);
        self::assertArrayHasKey('valueEur', $cuerpo);
        self::assertArrayHasKey('valueEur', $cuerpo['deck']);
    }

    /**
     * El *Hecho cuando:*, segunda mitad y la que de verdad se puede romper: **ni
     * un solo campo del cruce con la colección**.
     *
     * Se mira sobre el JSON en crudo y no clave a clave a propósito: una lista
     * de `assertArrayNotHasKey` solo prueba los campos que a uno se le ocurren
     * hoy, y el cruce entra por una llamada (`AnalyzeDeckAvailability`) que
     * añadiría los suyos de golpe.
     */
    public function testElMazoCompartidoNoLlevaNiUnCampoDelCruceConLaColeccion(): void
    {
        $crudo = $this->pedirCrudo('/api/public/deck/' . $this->compartir());

        foreach (
            ['missingCount', 'missing', 'missingValueEur', 'conflicts', 'availability',
                'overallocated', 'inCollection', 'claimed', 'free'] as $campo
        ) {
            self::assertStringNotContainsString(
                $campo,
                $crudo,
                "El mazo compartido lleva '{$campo}': es dato de tu colección colado por la puerta de al lado"
            );
        }

        $cuerpo = json_decode($crudo, true);

        self::assertSame(['deck', 'boards', 'cards', 'valueEur', 'legality'], array_keys($cuerpo));
    }

    /** Las notas del mazo son las de su dueño, y no viajan ni compartiéndolo. */
    public function testElMazoCompartidoNoLlevaLasNotasPrivadas(): void
    {
        $crudo = $this->pedirCrudo('/api/public/deck/' . $this->compartir());

        self::assertStringNotContainsString('la lista buena', $crudo);
        self::assertArrayNotHasKey('notes', json_decode($crudo, true)['deck']);
    }

    /**
     * **404 y no 403**, y el mismo 404 para todo: un token bien formado que no
     * existe, uno con otra pinta y una cadena vacía. Un 403 confirmaría que el
     * token existe, que es justo lo que quiere saber el que va probando.
     *
     * Es la regla contraria a la de las secciones del perfil, que sí son 403:
     * allí el usuario existe de todos modos porque su `username` es público.
     */
    public function testUnTokenQueNoLlevaANingunMazoEsSiempreElMismo404(): void
    {
        $this->compartir();

        foreach ([str_repeat('a', 64), 'no-es-un-token', str_repeat('b', 63), '9007'] as $token) {
            $cuerpo = $this->pedir('/api/public/deck/' . $token);

            self::assertSame(404, http_response_code(), "El token '{$token}'");
            self::assertSame(['error' => 'deck_not_found'], $cuerpo);
        }
    }

    /**
     * El `deck_id` no abre nada: el enlace lleva token, y la ruta no acepta
     * otra cosa. Es lo que impide leer el mazo de otro cambiando un número, que
     * es el motivo por el que los veinte métodos del repositorio llevan
     * `user_id` y este camino no.
     */
    public function testElIdDelMazoNoSirveComoEnlace(): void
    {
        $this->compartir();

        $cuerpo = $this->pedir('/api/public/deck/' . $this->mazoId);

        self::assertSame(404, http_response_code());
        self::assertSame(['error' => 'deck_not_found'], $cuerpo);
    }

    /** La otra mitad del *Hecho cuando:*: `deck_unshare` lo convierte en 404. */
    public function testDejarDeCompartirConvierteElEnlaceEn404(): void
    {
        $token = $this->compartir();

        $this->pedir('/api/public/deck/' . $token);
        self::assertSame(200, http_response_code());

        (new UnshareDeck($this->mazos))($this->duenyoId, ['deck_id' => $this->mazoId]);

        $cuerpo = $this->pedir('/api/public/deck/' . $token);

        self::assertSame(404, http_response_code());
        self::assertSame(['error' => 'deck_not_found'], $cuerpo);
    }

    /**
     * Regenerar **invalida el anterior**. `mtg_deck.share_token` es una columna
     * y no una tabla de enlaces: el valor viejo desaparece al escribir el nuevo.
     */
    public function testRegenerarElEnlaceMataElAnterior(): void
    {
        $viejo = $this->compartir();
        $nuevo = $this->compartir();

        self::assertNotSame($viejo, $nuevo);

        $this->pedir('/api/public/deck/' . $nuevo);
        self::assertSame(200, http_response_code());

        $this->pedir('/api/public/deck/' . $viejo);
        self::assertSame(404, http_response_code());
    }

    /**
     * **El mazo compartido no pregunta a `Visibilidad`**, y esta es la
     * diferencia entera con las otras cinco rutas: compartir un mazo es un acto
     * explícito sobre *ese* mazo. Con las cinco secciones en `nobody` —el perfil
     * cerrado a cal y canto, `decks` incluida— el enlace **sigue abriendo**, y
     * el valor en euros sigue viajando aunque `value` esté cerrado: el precio es
     * dato del catálogo de MTGJSON, no de tu colección.
     */
    public function testElEnlaceFuncionaConElPerfilCerradoDelTodo(): void
    {
        $this->privacidad->todasEn($this->duenyoId, Nivel::Nadie);

        $token  = $this->compartir();
        $cuerpo = $this->pedir('/api/public/deck/' . $token);

        self::assertSame(200, http_response_code());
        self::assertSame('Atraxa', $cuerpo['deck']['name']);
        self::assertArrayHasKey('valueEur', $cuerpo['deck']);

        // Y la sección `decks` del perfil sigue cerrada: abrir un mazo no abre
        // la lista de los demás.
        $this->pedir('/api/public/user/' . self::DUENYO . '/decks');
        self::assertSame(403, http_response_code());
    }

    /**
     * La legalidad viaja —es dato del catálogo y es un aviso, no un bloqueo—
     * pero sin `formatosDisponibles`: esos 21 formatos llenan el desplegable
     * **del dueño** para reasignar el formato, y quien abre un enlace no edita.
     */
    public function testLaLegalidadViajaSinElDesplegableDelDuenyo(): void
    {
        $cuerpo = $this->pedir('/api/public/deck/' . $this->compartir());

        self::assertArrayHasKey('legality', $cuerpo);
        self::assertSame(3, $cuerpo['legality']['size'], 'Los tokens fuera, el resto cuenta');
        self::assertArrayNotHasKey('formatosDisponibles', $cuerpo['legality']);
    }

    /**
     * **El token son 32 bytes de `random_bytes()` en hex, no un id.** Es la
     * mitigación que el plan pone por escrito frente al enumerado, y la única
     * que no depende del rate limit.
     */
    public function testElTokenSonSesentaYCuatroHexYNoSeRepite(): void
    {
        $tokens = [];

        for ($i = 0; $i < 5; $i++) {
            $token = $this->compartir();

            self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $token);

            $tokens[] = $token;
        }

        self::assertCount(5, array_unique($tokens));
    }

    /** Compartir el mazo de `setUp()` y devolver su token recién generado. */
    private function compartir(): string
    {
        return (new ShareDeck($this->mazos))(
            $this->duenyoId,
            ['deck_id' => $this->mazoId]
        )['shareToken'];
    }

    public function testSoloAtiendeGetYSoloBajoApiPublic(): void
    {
        self::assertTrue(PublicHttpRouter::atiende('GET', '/api/public/user/david'));
        self::assertTrue(PublicHttpRouter::atiende('GET', '/api/public/user/david/collection?cursor=x'));

        self::assertFalse(PublicHttpRouter::atiende('POST', '/api/public/user/david'));
        self::assertFalse(PublicHttpRouter::atiende('GET', '/api/catalog/cards'));
        self::assertFalse(PublicHttpRouter::atiende('GET', '/index.php'));
    }

    /**
     * Se pagina por cursor y nunca por offset: el cursor que llega por la URL
     * tiene que bajar hasta los criterios, y el siguiente subir a la respuesta.
     */
    public function testLaColeccionPublicaSePaginaPorCursor(): void
    {
        $this->privacidad->pon($this->duenyoId, Seccion::Coleccion, Nivel::Todos);
        $this->coleccion->nextCursor = 'el-siguiente';

        $_GET   = ['cursor' => 'el-anterior', 'limit' => '9999'];
        $cuerpo = $this->pedir('/api/public/user/' . self::DUENYO . '/collection?cursor=el-anterior&limit=9999');

        self::assertSame('el-anterior', $this->coleccion->ultimosCriterios?->cursor);
        self::assertSame(200, $this->coleccion->ultimosCriterios?->limit, 'El límite se acota, como en /catalog');
        self::assertSame('el-siguiente', $cuerpo['nextCursor']);
    }

    // =====================================================================
    // 5. El límite, que es la condición para exponer nada de esto
    // =====================================================================

    public function testLaRafagaSeCortaAntesDeTocarLaBaseDeDatos(): void
    {
        $_ENV['RATE_LIMIT_ENABLED'] = 'true';

        $guardia = $this->guardia();

        // Las 60 del minuto, gastadas en el mismo grupo que usa el router.
        for ($i = 0; $i < 60; $i++) {
            $guardia->comprobar(HttpRateLimitGuard::GRUPO_PUBLICO);
        }

        $lecturasPrevias = $this->privacidad->lecturas;

        $cuerpo = $this->pedir('/api/public/user/' . self::DUENYO, $guardia);

        self::assertSame(429, http_response_code());
        self::assertSame(['error' => 'rate_limited'], $cuerpo);
        self::assertSame(
            $lecturasPrevias,
            $this->privacidad->lecturas,
            'El límite corta ANTES de consultar la privacidad'
        );
    }

    // =====================================================================

    private function guardia(): HttpRateLimitGuard
    {
        $logger = new NullLogger();

        return new HttpRateLimitGuard(
            new RateLimitMiddleware(new FileRateLimitStore($this->directorio, $logger), $logger)
        );
    }

    private function router(?HttpRateLimitGuard $guardia = null): PublicHttpRouter
    {
        $logger = new NullLogger();

        return new PublicHttpRouter(
            $guardia ?? $this->guardia(),
            new EspectadorActual(new JwtService($logger), $logger),
            $this->usuarios,
            new Visibilidad($this->privacidad, $this->amistades),
            new ListCollection($this->coleccion),
            new ListDecks($this->mazos),
            new ListSetProgress($this->coleccion),
            new ValueCollection($this->coleccion),
            new GetSharedDeck($this->mazos, new GetDeck($this->mazos)),
            $logger
        );
    }

    /** @return array<string, mixed> */
    private function pedir(string $uri, ?HttpRateLimitGuard $guardia = null): array
    {
        return json_decode($this->pedirCrudo($uri, $guardia), true);
    }

    private function pedirCrudo(string $uri, ?HttpRateLimitGuard $guardia = null): string
    {
        http_response_code(200);

        ob_start();
        $this->router($guardia)->handle($uri);

        return (string) ob_get_clean();
    }
}
