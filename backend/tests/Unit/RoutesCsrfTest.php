<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Middleware\AuthMiddleware;
use App\Middleware\CsrfMiddleware;
use PHPUnit\Framework\TestCase;

/**
 * El contrato de M7 sobre config/routes.php.
 *
 * El agujero que cierra el hito no fue que CsrfMiddleware estuviera mal escrito
 * —funcionaba— sino que NINGUNA ruta lo declaraba. Ese fallo es invisible en el
 * código del middleware y no lo detecta ningún test de use case: solo se ve
 * mirando la tabla de rutas. Por eso este test mira la tabla.
 *
 * Si mañana se añade una escritura nueva sin CSRF, esto se pone rojo.
 */
final class RoutesCsrfTest extends TestCase
{
    /** Acciones que cambian estado y, por tanto, exigen token por sesión web. */
    private const ESCRITURAS = [
        'logout',
        'collection_add',
        'collection_update_quantity',
        'collection_change_grade',
        // M2 del plan de deseos: cruza `is_wishlist`, que está dentro de
        // `uq_item`, así que mueve la fila y puede fundir dos. Escritura.
        'collection_fulfill_wish',
        'collection_remove',
        // M5: la única escritura de la importación. `import_preview` no lo es y
        // por eso está en las lecturas de abajo.
        'import_apply',
        // Mazos (M4 del plan de mazos). Siete escrituras: las tres del mazo y
        // las cuatro de sus cartas. `deck_delete` con `with_cards` puede además
        // descontar de la colección, que es la escritura más cara de la app.
        'deck_create',
        'deck_update',
        'deck_delete',
        'deck_card_add',
        'deck_card_set',
        'deck_card_remove',
        'deck_card_change',
        // M5 del plan de precons: el botón de un clic. Escribe en DOS tablas
        // —la colección y el mazo— dentro de una sola transacción, así que es
        // la escritura más cara de la app después de `deck_delete`.
        'precon_add_to_collection',
        // M2 del plan de perfil público: cambia lo que se ve de ti. Es la
        // escritura con más consecuencias de la app aunque solo toque cinco
        // ENUMs — un CSRF que la moviera publicaría la colección de la víctima
        // sin que ella viera nada raro en su pantalla.
        'privacy_set',
        // M4 del mismo plan: el enlace del mazo. Escriben una columna
        // (`mtg_deck.share_token`) y su consecuencia es la misma que la de
        // `privacy_set` — un CSRF que moviera un `deck_share` publicaría el mazo
        // de la víctima sin que ella viera nada raro, y uno que moviera un
        // `deck_unshare` le rompería el enlace que acaba de repartir.
        'deck_share',
        'deck_unshare',
        // M2 del Plan - Amigos y Seguimiento. Las cuatro escriben en
        // `friendships`, y `friend_accept` es la que más consecuencias tiene de
        // toda la app después de `privacy_set`: aceptar una solicitud es lo
        // ÚNICO que abre el nivel `friends` de la privacidad de alguien, así que
        // un CSRF que la moviera haría que la víctima «aceptara» al atacante sin
        // ver nada raro en su pantalla. `friend_remove` es el caso espejo: le
        // rompería una amistad sin que se enterara.
        'friend_request',
        'friend_accept',
        'friend_reject',
        'friend_remove',
        // M3 del mismo plan. Escriben en `user_follow`, que es OTRA tabla y no
        // da ningún acceso: un CSRF que las moviera le pondría o le quitaría a
        // la víctima un marcador, y eso es todo — nadie vería nada de nadie por
        // ello. Llevan CSRF igualmente, porque el criterio de este fichero es si
        // la acción ESCRIBE, y no cuánto duele: la excepción razonada «esta
        // escritura no hace falta protegerla» es como se empieza a no
        // protegerlas.
        'follow_add',
        'follow_remove',
    ];

    /** Acciones de lectura: sin CSRF a propósito, no hay estado que falsificar. */
    private const LECTURAS = [
        'ping',
        'check_auth',
        'collection_list',
        'collection_value',
        // M6 del plan de deseos: el corazón relleno. Devuelve los uuids que ya
        // están en la lista y no escribe una fila, así que va sin CSRF por el
        // mismo criterio que `collection_list`.
        'collection_wished_uuids',
        'collection_sets',
        // Manda un fichero de hasta 24 MB por POST y aun así es lectura: detecta
        // formato, parsea y resuelve contra el catálogo sin escribir una fila.
        'import_preview',
        // Mazos: las tres lecturas. `deck_get` incluye el análisis de
        // disponibilidad y `deck_card_variants` mira la colección para decir qué
        // versiones tienes; ninguna de las dos escribe una fila.
        'deck_list',
        'deck_get',
        'deck_card_variants',
        // M2 del plan de perfil público: los cinco niveles del propio usuario.
        // No escribe una fila —quien la crea es `privacy_set`— así que va sin
        // Csrf por el mismo criterio que `collection_list`.
        'privacy_get',
        // M2 del Plan - Amigos y Seguimiento: tus amigos, tus solicitudes y los
        // contadores. No escribe una fila —las escriben las cuatro de arriba—
        // así que va sin Csrf por el mismo criterio que `collection_list`.
        'friend_list',
        // M3 del mismo plan: a quién sigues y cuánta gente te sigue. No escribe
        // una fila, así que va sin Csrf por el mismo criterio que `friend_list`.
        'follow_list',
        // M6 del mismo plan: el buscador de usuarios de `/friends`. Es lectura
        // —no escribe una fila, filtra `users` por prefijo y por la sexta
        // columna de privacidad— así que va sin Csrf por el mismo criterio que
        // `friend_list`. Lo que sí lleva, y eso no es negociable, es
        // `AuthMiddleware`: un buscador de personas abierto a internet sería el
        // directorio que ese hito existe para no publicar.
        'user_search',
    ];

    /** @return array<string, array> */
    private function rutas(): array
    {
        return require __DIR__ . '/../../config/routes.php';
    }

    /**
     * Los nombres de middleware de una ruta, aplanando los pares
     * [Clase::class, ['config' => ...]] a solo la clase.
     *
     * @return string[]
     */
    private function pila(array $ruta): array
    {
        return array_map(
            static fn ($entrada) => is_array($entrada) ? $entrada[0] : $entrada,
            $ruta['middleware']
        );
    }

    public function testTodaEscrituraDeclaraCsrfMiddleware(): void
    {
        $rutas = $this->rutas();

        foreach (self::ESCRITURAS as $accion) {
            self::assertArrayHasKey($accion, $rutas, "La acción {$accion} ya no existe en routes.php.");
            self::assertContains(
                CsrfMiddleware::class,
                $this->pila($rutas[$accion]),
                "La escritura {$accion} no declara CsrfMiddleware: queda sin proteger por sesión web."
            );
        }
    }

    /**
     * El orden no es cosmético: CsrfMiddleware se salta a sí mismo mirando
     * `auth_method`, y esa marca la pone AuthMiddleware. Declararlo antes lo
     * dejaría ciego y el cliente Capacitor recibiría 403 en cada escritura.
     */
    public function testCsrfVaSiempreDespuesDeAuthMiddleware(): void
    {
        $rutas = $this->rutas();

        foreach (self::ESCRITURAS as $accion) {
            $pila = $this->pila($rutas[$accion]);

            $posAuth = array_search(AuthMiddleware::class, $pila, true);
            $posCsrf = array_search(CsrfMiddleware::class, $pila, true);

            self::assertIsInt($posAuth, "La escritura {$accion} necesita AuthMiddleware para saber cómo se autenticó.");
            self::assertIsInt($posCsrf);
            self::assertLessThan(
                $posCsrf,
                $posAuth,
                "En {$accion}, CsrfMiddleware corre antes que AuthMiddleware y no vería `auth_method`."
            );
        }
    }

    public function testLasLecturasNoLlevanCsrf(): void
    {
        $rutas = $this->rutas();

        foreach (self::LECTURAS as $accion) {
            self::assertArrayHasKey($accion, $rutas);
            self::assertNotContains(
                CsrfMiddleware::class,
                $this->pila($rutas[$accion]),
                "La lectura {$accion} no cambia estado: exigirle CSRF solo añade formas de romperla."
            );
        }
    }

    /**
     * `login` es la excepción documentada: es la acción que EMITE el token, así
     * que pedírselo lo haría imposible de usar y dejaría la app sin entrada.
     */
    public function testLoginNoLlevaCsrfPorqueEsQuienEmiteElToken(): void
    {
        $rutas = $this->rutas();

        self::assertNotContains(CsrfMiddleware::class, $this->pila($rutas['login']));
    }

    /**
     * Red de seguridad: ninguna acción fuera de las dos listas de arriba. Si
     * alguien añade una acción nueva, este test obliga a clasificarla como
     * lectura o escritura en vez de que se cuele sin decidirlo.
     */
    public function testNoHayAccionesSinClasificar(): void
    {
        $conocidas = array_merge(self::ESCRITURAS, self::LECTURAS, ['login']);
        $sobran    = array_diff(array_keys($this->rutas()), $conocidas);

        self::assertSame(
            [],
            array_values($sobran),
            'Acción nueva sin clasificar como lectura o escritura: decide si necesita CSRF.'
        );
    }
}
