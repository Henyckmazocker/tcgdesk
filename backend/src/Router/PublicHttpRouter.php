<?php

declare(strict_types=1);

namespace App\Router;

use App\Application\UseCase\GetSharedDeck;
use App\Application\UseCase\ListCollection;
use App\Application\UseCase\ListDecks;
use App\Application\UseCase\ListSetProgress;
use App\Application\UseCase\ValueCollection;
use App\Domain\Model\User;
use App\Domain\Repository\UserRepositoryInterface;
use App\Domain\Social\Seccion;
use App\Domain\Social\Visibilidad;
use App\Infrastructure\Auth\EspectadorActual;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Las rutas `GET /api/public/*`: el perfil de alguien, visto desde fuera.
 *
 * **La cuarta divergencia `GET`, y la primera que NO sirve dato público.** El
 * `CLAUDE.md` pide discutir cada caso nuevo antes de abrirlo; este se discutió y
 * se aprobó en el Plan - Perfil Público y Mazos Compartibles, sección «La cuarta
 * divergencia `GET`». Cumple tres de los cuatro criterios del desvío original
 * —lectura, sin sesión ni CSRF, y una URL que se pagina y se comparte— y
 * **incumple el cuarto**: esto no es el catálogo de MTGJSON, es la colección de
 * una persona.
 *
 * **Por eso es un router nuevo y `CatalogHttpRouter` no se toca.** Aquel sirve
 * dato reconstruible sin comprobar nada, y meter aquí dentro rutas que consultan
 * permisos haría que la próxima ruta de catálogo naciera con la duda de si tiene
 * que comprobar algo. Son dos cosas distintas y se leen distinto.
 *
 * De ese cuarto criterio incumplido salen las tres cosas que este router hace y
 * los otros dos no:
 *
 *  1. **Se limita.** `HttpRateLimitGuard` lo primero de `handle()`, antes de
 *     tocar la base de datos. Sin límite, esto es un scraper de colecciones y un
 *     enumerador de usuarios; el catálogo y las imágenes siguen sin limitar a
 *     propósito, porque el scroll infinito de `/catalog` los pide a puñados.
 *  2. **Resuelve al espectador él mismo.** El desvío ocurre antes de construir
 *     `Application`, así que aquí no hay `AuthMiddleware` ni sesión arrancada:
 *     lo hace `EspectadorActual`, el mismo código que usa el middleware. Y **no
 *     identificarse no es un error**: un anónimo es `null` y ve lo que sea
 *     `everyone`. Una cookie caducada tampoco es un 401 — sería romper el enlace
 *     compartido justo a quien tuvo cuenta alguna vez.
 *  3. **No se cachea nada.** `CatalogHttpRouter` pone cinco minutos de
 *     `Cache-Control: public`; aquí eso pondría la colección de alguien en un
 *     proxy compartido, y además sobreviviría al `privacy_set` que la cerró.
 *
 * **La regla de oro del plan, que es donde esto se rompe si se hace mal:**
 * ningún método de aquí consulta `user_privacy_settings`. Todos preguntan a
 * `Visibilidad::puedeVer()`, que es el único sitio donde vive la decisión.
 * Cinco comprobaciones repartidas por cinco rutas es cómo se acaba filtrando una
 * sección, y el fallo es silencioso: una respuesta con datos de más parece
 * perfectamente correcta.
 *
 * **La sexta ruta —el mazo compartido— es la excepción a la regla de oro, y la
 * única.** `GET /api/public/deck/{token}` no pregunta a `Visibilidad` porque ahí
 * quien autoriza es el token: compartir un mazo es un acto explícito sobre *ese*
 * mazo, no una sección del perfil, y hacerlo depender de `show_decks` rompería
 * en silencio los enlaces ya repartidos el día que su dueño cerrara el perfil.
 * Lo que la pone del lado seguro es la otra mitad: **el cruce con la colección
 * no viaja** —`missingCount`, `conflicts`, qué copias tienes libres—, y su fallo
 * es **404 y no 403**, al revés que las secciones de arriba.
 *
 * Lo que NO entra aquí:
 *  - **Escribir.** Sigue siendo una acción `POST` con su pila (`privacy_set`,
 *    `deck_share`, `deck_unshare`).
 */
final class PublicHttpRouter
{
    /**
     * Lo que de un usuario es público **por diseño**, y nada más.
     *
     * El plan es explícito: el perfil enseña `display_name` y `avatar_url`, y el
     * `email` **jamás**. Por eso esto se compone a mano y no con
     * `User::toArray()`, que sí lo lleva: un día alguien añade una columna a
     * `users`, `toArray()` la arrastra y aparece en una ruta abierta a internet
     * sin que nadie haya decidido publicarla.
     *
     * @return array<string, mixed>
     */
    private static function usuarioPublico(User $usuario): array
    {
        return [
            'username'    => $usuario->username,
            'displayName' => $usuario->displayName,
            'avatarUrl'   => $usuario->avatarUrl,
        ];
    }

    /**
     * Las columnas de una línea de colección que se publican.
     *
     * **Lista blanca y no lista negra, y eso no es manía.** El contrato de
     * `MySqlCollectionRepository::COLUMNAS` crece: el día que le añadan una
     * columna, una lista negra la publicaría sola y en silencio. Con la blanca,
     * lo que pasa es que el campo nuevo no sale hasta que alguien decida que sí
     * — que es el error que se ve y no el que se filtra.
     *
     * Lo que queda fuera y por qué:
     *  - **`notes`**: son las notas privadas de la línea («comprada en X por Y»).
     *    El plan cita justamente el `show_notes` de LibraryVue —que nace a 0—
     *    como el criterio de lo sensible.
     *  - **`id` e `isWishlist`**: fontanería de la tabla. El `id` de una línea es
     *    además inestable (`changeGrade()` funde filas), así que publicarlo sería
     *    prometer algo que no se cumple.
     *  - **`priceEur` y `lineValue`**: son la sección `value` y entran aparte.
     */
    private const CAMPOS_DE_CARTA = [
        'printingUuid', 'oracleId', 'name', 'setCode', 'setName', 'releaseDate',
        'collectorNumber', 'rarity', 'manaCost', 'colors', 'colorIdentity',
        'typeLine', 'scryfallId', 'finish', 'language', 'condition', 'quantity',
    ];

    /** El precio de una línea: sale solo si la sección `value` es visible. */
    private const CAMPOS_DE_VALOR_DE_CARTA = ['priceEur', 'lineValue'];

    /**
     * Lo que se publica de un mazo: en la lista del perfil y en el enlace
     * compartido, que comparten forma a propósito —el mismo mazo no puede tener
     * dos contratos según por dónde se mire—.
     *
     * Fuera: **`notes`** (mismo motivo que en la colección: son las notas
     * privadas de su dueño, «la lista buena, no la de los torneos») y
     * **`missingCount`**, que es lo que a ese mazo le falta **de tu colección**.
     * El plan lo dice del mazo compartido y vale igual en la lista del perfil: *«eso es dato de tu colección
     * colado por la puerta de al lado»*. Se quita siempre, no solo cuando la
     * colección está cerrada, porque un número que a veces sale y a veces no es
     * él mismo una respuesta sobre la colección.
     */
    private const CAMPOS_DE_MAZO = [
        'id', 'name', 'status', 'format', 'cards', 'cardLines', 'createdAt', 'updatedAt',
    ];

    /**
     * Lo que vale un mazo.
     *
     * En la lista del perfil es la sección `value` y se añade solo si está
     * abierta; **en el mazo compartido se añade siempre**, porque ahí el
     * *Hecho cuando:* del hito pide el mazo «con sus cartas, zonas y valor» y el
     * precio es dato del catálogo, no de tu colección.
     */
    private const CAMPOS_DE_VALOR_DE_MAZO = ['valueEur'];

    /**
     * Las columnas de una carta **del mazo compartido**.
     *
     * No es `CAMPOS_DE_CARTA`: una línea de mazo no tiene `quantity` sino
     * `count`, y tiene `board` —la zona—, que en la colección no existe. Y los
     * dos campos de dinero entran **siempre**, sin mirar la sección `value`: el
     * precio de una carta es dato del catálogo de MTGJSON, y el
     * *Hecho cuando:* del hito pide el mazo «con sus cartas, zonas y valor».
     *
     * Lo que no está aquí y por qué: **`id` y `deckId`**, que son la fontanería
     * de `mtg_deck_card` y no le hacen falta a quien solo mira. El cruce con la
     * colección **no hace falta quitarlo**, y eso es lo importante: no viene.
     * `GetSharedDeck` llama a `GetDeck`, que no lo calcula; quien lo añade es
     * `DeckController::get()` con una llamada aparte a
     * `AnalyzeDeckAvailability`, y por este camino esa llamada no ocurre nunca.
     * Esta lista blanca es el segundo cierre, no el primero.
     */
    private const CAMPOS_DE_CARTA_DE_MAZO = [
        'printingUuid', 'oracleId', 'name', 'setCode', 'setName', 'releaseDate',
        'collectorNumber', 'rarity', 'manaCost', 'colors', 'colorIdentity',
        'typeLine', 'scryfallId', 'finish', 'language', 'condition', 'board',
        'count', 'priceEur', 'lineValue',
    ];

    /**
     * El bloque de legalidad, que es **aviso y no bloqueo** y sale entero salvo
     * una clave.
     *
     * Es dato del catálogo —`mtg_legality`, de MTGJSON— así que no hay nada
     * privado que recortar. Fuera queda `formatosDisponibles`: son los 21
     * formatos que llenan el desplegable **del dueño** para reasignar el
     * formato del mazo, y quien abre un enlace no edita nada.
     */
    private const CAMPOS_DE_LEGALIDAD = [
        'format', 'known', 'statuses', 'banned', 'restricted', 'notLegal',
        'size', 'minSize', 'belowMinimum',
    ];

    /** Y lo que se publica del progreso por edición. */
    private const CAMPOS_DE_SET = [
        'setCode', 'setName', 'releaseDate', 'totalSetSize',
        'ownedPrintings', 'items', 'copies', 'percent', 'complete',
    ];

    /** Cuánto dinero llevas en esa edición: sección `value`. */
    private const CAMPOS_DE_VALOR_DE_SET = ['valueEur'];

    public function __construct(
        private readonly HttpRateLimitGuard $limite,
        private readonly EspectadorActual $espectador,
        private readonly UserRepositoryInterface $usuarios,
        private readonly Visibilidad $visibilidad,
        private readonly ListCollection $coleccion,
        private readonly ListDecks $mazos,
        private readonly ListSetProgress $sets,
        private readonly ValueCollection $valor,
        private readonly GetSharedDeck $mazoCompartido,
        private readonly LoggerInterface $logger
    ) {
    }

    /** ¿Esta petición es del perfil público? Lo decide public/index.php con esto. */
    public static function atiende(string $metodo, string $uri): bool
    {
        return $metodo === 'GET' && str_starts_with(self::ruta($uri), '/api/public');
    }

    public function handle(string $uri): void
    {
        // El límite, lo PRIMERO: antes de mirar la ruta y antes de tocar la base
        // de datos. Es la única línea que el M0 dejó pendiente de portar, y el
        // contador es del grupo entero —no de la URI—, o recorrer un diccionario
        // de `username` no gastaría límite ninguno.
        $limitado = $this->limite->comprobar(HttpRateLimitGuard::GRUPO_PUBLICO);

        if ($limitado !== null) {
            $this->responder($limitado[0], $limitado[1]);
            return;
        }

        $ruta = self::ruta($uri);

        try {
            // Quién mira. `null` es «nadie», y es el caso normal aquí.
            $quien = $this->espectador->resolver()?->id;

            $respuesta = match (true) {
                (bool) preg_match('#^/api/public/user/([^/]+)$#', $ruta, $m)
                    => $this->perfil(rawurldecode($m[1]), $quien),
                (bool) preg_match('#^/api/public/user/([^/]+)/collection$#', $ruta, $m)
                    => $this->coleccionDe(rawurldecode($m[1]), $quien),
                (bool) preg_match('#^/api/public/user/([^/]+)/decks$#', $ruta, $m)
                    => $this->mazosDe(rawurldecode($m[1]), $quien),
                (bool) preg_match('#^/api/public/user/([^/]+)/sets$#', $ruta, $m)
                    => $this->setsDe(rawurldecode($m[1]), $quien),
                (bool) preg_match('#^/api/public/user/([^/]+)/wishlist$#', $ruta, $m)
                    => $this->deseosDe(rawurldecode($m[1]), $quien),

                // La sexta, y la única que no lleva `username`: aquí el que
                // autoriza es el token, y por eso `$quien` ni se usa.
                (bool) preg_match('#^/api/public/deck/([^/]+)$#', $ruta, $m)
                    => $this->mazoDelEnlace(rawurldecode($m[1])),

                default => [404, ['error' => 'not_found']],
            };
        } catch (Throwable $e) {
            $this->logger->error('Public HTTP falló', [
                'uri'             => $uri,
                'message'         => $e->getMessage(),
                'exception_class' => get_class($e),
            ]);

            $respuesta = [500, ['error' => 'internal_error']];
        }

        [$codigo, $cuerpo] = $respuesta;

        $this->responder($codigo, $cuerpo);
    }

    /**
     * El perfil: quién es, y **qué secciones tiene abiertas para quien mira**.
     *
     * Ese mapa `visible` es lo que permite a la vista del M5 pintar «esta
     * sección es privada» en vez de esconderla sin más —una sección que
     * desaparece parece un fallo de carga—, y lo que evita que el cliente pida
     * cuatro rutas para descubrir que tres dan 403.
     *
     * **No publica los niveles, solo el sí o el no.** Decir «esto está en
     * `friends`» sería un mapa de qué secciones vale la pena volver a intentar
     * después de hacerse amigo; lo mismo que ya razona `PrivacyController` al no
     * tener una lectura de la privacidad ajena.
     *
     * El **valor** de la colección viaja aquí, y solo el total: es la sección
     * `value`, no tiene ruta propia, y el desglose por edición y el top de cartas
     * caras son el panel del dueño, no el escaparate. Nace en `friends`, así que
     * por defecto esta clave **no sale**.
     *
     * @return array{0: int, 1: array<string, mixed>}
     */
    private function perfil(string $username, ?int $espectadorId): array
    {
        $duenyo = $this->usuarios->findByUsername($username);

        if ($duenyo === null || $duenyo->id === null) {
            return [404, ['error' => 'user_not_found']];
        }

        $visible = [];

        foreach (Seccion::cases() as $seccion) {
            $visible[$seccion->value] = $this->visibilidad->puedeVer($duenyo->id, $espectadorId, $seccion);
        }

        $cuerpo = [
            'user'    => self::usuarioPublico($duenyo),
            'visible' => $visible,
        ];

        if ($visible[Seccion::Valor->value]) {
            $cuerpo['value'] = ($this->valor)($duenyo->id)['totals'];
        }

        return [200, $cuerpo];
    }

    /**
     * La colección, paginada **por cursor**.
     *
     * Los filtros llegan por la query string y los acota `CollectionCriteria`,
     * igual que en `/catalog`: lo que llega de una URL es una sugerencia. La
     * única clave que **no** se acepta del cliente es `is_wishlist`, y no es
     * cosmético — son dos listas distintas con dos niveles de privacidad
     * distintos, así que dejar pasar un `?is_wishlist=1` por aquí sería servir la
     * lista de deseos con el permiso de la colección.
     *
     * @return array{0: int, 1: array<string, mixed>}
     */
    private function coleccionDe(string $username, ?int $espectadorId): array
    {
        return $this->lista($username, $espectadorId, Seccion::Coleccion, false);
    }

    /**
     * La lista de deseos, con la misma forma y la misma paginación.
     *
     * Se sirve con `ListCollection` y no con `ListWishedPrintings` a propósito:
     * aquel devuelve solo `printing_uuid` —existe para pintar el corazón del
     * catálogo con la lista propia— y una lista de uuids no se puede enseñar a
     * nadie. Aquí hace falta la carta entera, y el mismo contrato
     * `items` + `nextCursor` que la colección, para que la vista del M5 sea el
     * mismo componente y el scroll el mismo código.
     *
     * @return array{0: int, 1: array<string, mixed>}
     */
    private function deseosDe(string $username, ?int $espectadorId): array
    {
        return $this->lista($username, $espectadorId, Seccion::Deseos, true);
    }

    /**
     * Lo común de las dos listas de cartas: resolver, preguntar, filtrar.
     *
     * @return array{0: int, 1: array<string, mixed>}
     */
    private function lista(string $username, ?int $espectadorId, Seccion $seccion, bool $deseos): array
    {
        [$duenyo, $negado] = $this->resolver($username, $espectadorId, $seccion);

        if ($negado !== null) {
            return $negado;
        }

        $resultado = ($this->coleccion)(
            $duenyo->id,
            array_replace($_GET, ['is_wishlist' => $deseos])
        );

        $conValor = $this->visibilidad->puedeVer($duenyo->id, $espectadorId, Seccion::Valor);

        return [200, [
            'items' => array_map(
                fn (array $linea): array => $this->recorte(
                    $linea,
                    self::CAMPOS_DE_CARTA,
                    $conValor ? self::CAMPOS_DE_VALOR_DE_CARTA : []
                ),
                $resultado['items']
            ),
            'nextCursor' => $resultado['nextCursor'],
        ]];
    }

    /**
     * Los mazos. Sin paginar, como `deck_list`: una persona tiene mazos, no un
     * catálogo.
     *
     * @return array{0: int, 1: array<string, mixed>}
     */
    private function mazosDe(string $username, ?int $espectadorId): array
    {
        [$duenyo, $negado] = $this->resolver($username, $espectadorId, Seccion::Mazos);

        if ($negado !== null) {
            return $negado;
        }

        // El `status` sí se acepta de la URL: es un filtro de vista, y
        // `ListDecks` lo lee con `intentar()` — un estado que no se entiende deja
        // de filtrar en vez de reventar la pantalla.
        $resultado = ($this->mazos)($duenyo->id, ['status' => $_GET['status'] ?? null]);

        $conValor = $this->visibilidad->puedeVer($duenyo->id, $espectadorId, Seccion::Valor);

        return [200, [
            'decks' => array_map(
                fn (array $mazo): array => $this->recorte(
                    $mazo,
                    self::CAMPOS_DE_MAZO,
                    $conValor ? self::CAMPOS_DE_VALOR_DE_MAZO : []
                ),
                $resultado['decks']
            ),
        ]];
    }

    /**
     * El progreso por edición. Los totales no llevan dinero, así que salen
     * enteros; el `valueEur` de cada fila sí es la sección `value`.
     *
     * @return array{0: int, 1: array<string, mixed>}
     */
    private function setsDe(string $username, ?int $espectadorId): array
    {
        [$duenyo, $negado] = $this->resolver($username, $espectadorId, Seccion::Sets);

        if ($negado !== null) {
            return $negado;
        }

        // `is_wishlist` a false y no lo que diga la URL, por el mismo motivo que
        // en la colección: el progreso de la lista de deseos es otra sección.
        $resultado = ($this->sets)($duenyo->id, ['is_wishlist' => false]);

        $conValor = $this->visibilidad->puedeVer($duenyo->id, $espectadorId, Seccion::Valor);

        return [200, [
            'sets' => array_map(
                fn (array $fila): array => $this->recorte(
                    $fila,
                    self::CAMPOS_DE_SET,
                    $conValor ? self::CAMPOS_DE_VALOR_DE_SET : []
                ),
                $resultado['sets']
            ),
            'totals' => $resultado['totals'],
        ]];
    }

    /**
     * **El mazo que hay detrás de un enlace compartido**, entero menos una cosa.
     *
     * Las tres decisiones de esta ruta, que son el hito:
     *
     *  1. **No pregunta a `Visibilidad`.** Es la única de este router que no lo
     *     hace, y a propósito: compartir un mazo es un acto explícito sobre ese
     *     mazo, así que se enseña con el perfil abierto o cerrado. Ni siquiera
     *     mira el `status`: un mazo desmontado que sigue compartido sigue
     *     enseñándose, porque el enlace lo mata `deck_unshare` y nada más.
     *  2. **404 y nunca 403**, y el mismo 404 para todo: token mal formado,
     *     token que nunca existió, token revocado y mazo borrado. Un 403
     *     confirmaría que el token existe, que es justo lo que quiere saber el
     *     que va probando. Es la regla contraria a la de las secciones del
     *     perfil (403), y la diferencia está en que allí el usuario existe de
     *     todos modos: su `username` es público por diseño.
     *  3. **El cruce con la colección no viaja.** `missingCount`, `conflicts`,
     *     qué copias tienes libres: eso es dato de tu colección colado por la
     *     puerta de al lado. No viene de `GetSharedDeck` y además no está en
     *     ninguna de las tres listas blancas de abajo, que es lo que hace que
     *     tampoco viaje el campo que a alguien se le ocurra añadir mañana.
     *
     * **El valor en euros sí viaja**, y no es una excepción a lo anterior: el
     * precio de una carta es dato del catálogo de MTGJSON, público y
     * reconstruible. Lo privado es cruzarlo con *tu* colección, no el precio.
     *
     * Se devuelven **`boards` y `cards`**: las mismas líneas dos veces, una
     * agrupada por zona y otra en plano, tal y como las arma `GetDeck` para la
     * ficha del dueño. Recortar aquí una de las dos obligaría a la vista del M5
     * a ser distinta de `DeckView` por un motivo que no es de producto.
     *
     * @return array{0: int, 1: array<string, mixed>}
     */
    private function mazoDelEnlace(string $token): array
    {
        $mazo = ($this->mazoCompartido)($token);

        if ($mazo === null) {
            return [404, ['error' => 'deck_not_found']];
        }

        $carta = fn (array $linea): array => $this->recorte($linea, self::CAMPOS_DE_CARTA_DE_MAZO, []);

        return [200, [
            'deck'   => $this->recorte(
                $mazo['deck'],
                self::CAMPOS_DE_MAZO,
                self::CAMPOS_DE_VALOR_DE_MAZO
            ),
            'boards' => array_map(
                static fn (array $zona): array => array_map($carta, $zona),
                $mazo['boards']
            ),
            'cards'    => array_map($carta, $mazo['cards']),
            'valueEur' => $mazo['valueEur'],
            'legality' => $this->recorte($mazo['legality'], self::CAMPOS_DE_LEGALIDAD, []),
        ]];
    }

    /**
     * Los dos pasos que preceden a cualquier sección: existe el usuario, y
     * ¿puede verla quien pregunta?
     *
     * Devuelve `[usuario, null]` cuando se puede seguir y `[null, respuesta]`
     * cuando no, para que cada ruta se lea como «resuelvo o devuelvo» y no como
     * cuatro `if` anidados que es donde se cuela un `return` que faltaba.
     *
     * **404 para el usuario, 403 para la sección.** No es una inconsistencia: si
     * una sección cerrada devolviera 404, cerrarla diría «este usuario no
     * existe», y el `username` es público por diseño —está en la URL que su dueño
     * reparte—. Quien esconde el 403 del que enumera es el rate limit, no el
     * código de respuesta.
     *
     * @return array{0: User|null, 1: array{0: int, 1: array<string, mixed>}|null}
     */
    private function resolver(string $username, ?int $espectadorId, Seccion $seccion): array
    {
        $duenyo = $this->usuarios->findByUsername($username);

        if ($duenyo === null || $duenyo->id === null) {
            return [null, [404, ['error' => 'user_not_found']]];
        }

        if (!$this->visibilidad->puedeVer($duenyo->id, $espectadorId, $seccion)) {
            return [null, [403, ['error' => 'not_visible']]];
        }

        return [$duenyo, null];
    }

    /**
     * Deja de una fila **solo** los campos permitidos, en el orden de la lista.
     *
     * `array_intersect_key` y no un `unset` de lo prohibido: lo que no esté
     * declarado no sale, aunque el repositorio lo añada mañana.
     *
     * @param  array<string, mixed> $fila
     * @param  list<string>         $campos
     * @param  list<string>         $extra   Campos de la sección `value`, si toca
     * @return array<string, mixed>
     */
    private function recorte(array $fila, array $campos, array $extra): array
    {
        return array_intersect_key($fila, array_flip([...$campos, ...$extra]));
    }

    /** La ruta sin query string ni barra final. */
    private static function ruta(string $uri): string
    {
        $ruta = parse_url($uri, PHP_URL_PATH) ?: '/';

        return rtrim($ruta, '/') ?: '/';
    }

    /** @param array<string|int, mixed> $cuerpo */
    private function responder(int $codigo, array $cuerpo): void
    {
        http_response_code($codigo);
        header('Content-Type: application/json');

        // **Nada de esto se cachea, ni siquiera lo que salió bien.** Es la
        // diferencia con `CatalogHttpRouter`, que da cinco minutos de
        // `Cache-Control: public`: allí el dato es de MTGJSON y aquí es de una
        // persona. Un perfil en la caché de un proxy compartido es la misma fuga
        // que el rate limit intenta evitar, y sobreviviría al `privacy_set` que
        // cerró la sección.
        header('Cache-Control: no-store');

        echo json_encode($cuerpo, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
