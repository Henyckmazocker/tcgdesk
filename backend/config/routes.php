<?php

declare(strict_types=1);

use App\Controllers\AuthController;
use App\Controllers\CollectionController;
use App\Controllers\DeckController;
use App\Controllers\FollowController;
use App\Controllers\FriendController;
use App\Controllers\ImportController;
use App\Controllers\PingController;
use App\Controllers\PreconController;
use App\Controllers\PrivacyController;
use App\Controllers\UserSearchController;
use App\Middleware\AuthMiddleware;
use App\Middleware\CsrfMiddleware;
use App\Middleware\LoggingMiddleware;
use App\Middleware\RateLimitMiddleware;
use App\Middleware\ValidationMiddleware;

/**
 * Configuración de rutas de acción.
 *
 * Estructura de cada entrada:
 *   'controller' => [ControllerClass::class, 'methodName']   ← FQCN, lo resuelve el contenedor
 *   'middleware' => [MiddlewareClass::class, ...]            ← pila, en orden de ejecución
 *
 * Los middlewares con configuración van como par:
 *   [RateLimitMiddleware::class, ['limit' => 5, 'window' => 300, 'by' => 'ip']]
 *   [ValidationMiddleware::class, ['required' => ['id_token']]]
 *
 * Rate limiting: TODA ruta recibe un RateLimitMiddleware por defecto
 * (configurado por las env RATE_LIMIT_*) que aplica ActionRouter
 * automáticamente. Declararlo aquí explícitamente sobreescribe ese defecto.
 *
 * Añadir un endpoint = tocar este fichero y el controller. Nada más: el
 * ActionRouter resuelve el controller por FQCN y no hay que registrarlo.
 *
 * ORDEN DE LA PILA — dónde va CsrfMiddleware y por qué (M7)
 * --------------------------------------------------------
 * El orden declarado es el orden de ejecución (ActionRouter lo respeta y
 * MiddlewarePipeline lo envuelve como una cebolla). Para las escrituras es:
 *
 *     RateLimit → Logging → Auth → Csrf → Validation → controller
 *
 * - **RateLimit** va primero (lo inyecta el router por defecto): descartar una
 *   petición abusiva antes de tocar sesión o base de datos es el único orden
 *   que tiene sentido.
 * - **Logging** antes que Auth para que el rechazo también quede registrado.
 * - **Auth ANTES que Csrf, y esto no es negociable**: CsrfMiddleware se salta a
 *   sí mismo cuando `auth_method === 'jwt'`, y quien pone `auth_method` en el
 *   request es AuthMiddleware. Declarar Csrf antes de Auth lo dejaría sin ese
 *   dato y exigiría token de sesión al cliente Capacitor, que no tiene cookie
 *   que proteger: cada escritura del móvil sería un 403. Además así el que no
 *   está autenticado recibe 401 (no hay sesión que falsificar) y solo el que sí
 *   lo está llega a la comprobación del token.
 * - **Csrf ANTES que Validation**: un token robado o ausente no merece que se
 *   inspeccione el payload, y así el 403 no queda tapado por un 400.
 *
 * Las LECTURAS (`ping`, `check_auth`, `collection_list`, `collection_value`,
 * `collection_sets`, `import_preview`, `deck_list`, `deck_get` y
 * `deck_card_variants`) no llevan Csrf: no cambian estado, y un CSRF que no
 * puede escribir nada no es un ataque. `login` es el caso aparte y está
 * explicado en su propia entrada.
 */
return [
    // ========================================================================
    // SALUD — público, sin autenticación
    // ========================================================================
    'ping' => [
        'controller' => [PingController::class, 'ping'],
        'middleware' => [LoggingMiddleware::class],
    ],

    // ========================================================================
    // AUTH — público: es lo que crea la sesión
    // ========================================================================
    // `login` es la ÚNICA escritura sin CsrfMiddleware, y es a propósito: es la
    // acción que *emite* el token (`AuthController::startSession()` lo genera al
    // rotar el id de sesión), así que exigirlo aquí pediría un secreto que
    // todavía no existe y dejaría la app sin poder entrar — ni por web ni por
    // móvil. Lo que la protege no es el token sino el `id_token` de Google del
    // payload: un tercero no puede fabricar uno válido para la víctima, la
    // cookie va `SameSite` y el rate limit por IP corta el resto.
    'login' => [
        'controller' => [AuthController::class, 'login'],
        'middleware' => [
            // Límite estricto contra fuerza bruta: 10 intentos / 5 min por IP.
            [RateLimitMiddleware::class, ['limit' => 10, 'window' => 300, 'by' => 'ip']],
            LoggingMiddleware::class,
            [ValidationMiddleware::class, ['required' => ['id_token']]],
        ],
    ],

    // `logout` SÍ es una escritura: destruye la sesión. Sin CSRF, cualquier web
    // puede echar de la app al que la visite. Gana también AuthMiddleware, que
    // antes no tenía, porque es el que decide entre cookie y Bearer: sin él,
    // `auth_method` llegaría vacío a CsrfMiddleware y el cliente Capacitor
    // —que no manda token CSRF ni lo necesita— no podría cerrar sesión nunca.
    // El precio es que cerrar sesión sin sesión ya no responde 200 sino 401; el
    // store del frontend limpia su estado igual, pase lo que pase.
    'logout' => [
        'controller' => [AuthController::class, 'logout'],
        'middleware' => [
            LoggingMiddleware::class,
            AuthMiddleware::class,
            CsrfMiddleware::class,
        ],
    ],

    // ========================================================================
    // AUTH — protegidas: exigen sesión o Bearer token
    // ========================================================================
    'check_auth' => [
        'controller' => [AuthController::class, 'checkAuth'],
        'middleware' => [
            LoggingMiddleware::class,
            AuthMiddleware::class,
        ],
    ],

    // ========================================================================
    // COLECCIÓN — todas privadas
    // ========================================================================
    // Las ocho llevan AuthMiddleware, y no solo para exigir sesión: es ese
    // middleware el que pone el `user_id` en el request. El controller lo lee de
    // ahí y NUNCA del cuerpo de la petición — si el user_id viniera del payload,
    // cualquiera leería o borraría la colección de otro cambiando un número.
    //
    // Van por el endpoint único con POST, incluidas las de lectura: la
    // divergencia de rutas GET es solo para el catálogo (que no necesita sesión
    // y se cachea) y no se extiende a datos de usuario.

    'collection_add' => [
        'controller' => [CollectionController::class, 'add'],
        'middleware' => [
            LoggingMiddleware::class,
            AuthMiddleware::class,
            CsrfMiddleware::class,
            [ValidationMiddleware::class, ['required' => ['printing_uuid']]],
        ],
    ],

    'collection_update_quantity' => [
        'controller' => [CollectionController::class, 'updateQuantity'],
        'middleware' => [
            LoggingMiddleware::class,
            AuthMiddleware::class,
            CsrfMiddleware::class,
            // `quantity` no va en la lista: ValidationMiddleware rechaza el '' y
            // el ausente, pero también trataría el 0 como falta — y el 0 es
            // justamente la petición legítima de "bórrala". Lo valida el use case.
            [ValidationMiddleware::class, ['required' => ['item_id']]],
        ],
    ],

    // `condition` sí va en la lista de required, al revés que la `quantity` de
    // arriba: aquí no hay ningún valor legítimo que ValidationMiddleware pueda
    // confundir con una ausencia, y sin estado no hay nada que cambiar.
    'collection_change_grade' => [
        'controller' => [CollectionController::class, 'changeGrade'],
        'middleware' => [
            LoggingMiddleware::class,
            AuthMiddleware::class,
            CsrfMiddleware::class,
            [ValidationMiddleware::class, ['required' => ['item_id', 'condition']]],
        ],
    ],

    // La pila es la de `collection_change_grade` al pie de la letra: cumplir un
    // deseo es la otra escritura que mueve una fila dentro de `uq_item`.
    //
    // `quantity` NO va en required —es opcional, y por defecto se cumple un solo
    // ejemplar—, y aunque fuera obligatoria tampoco podría ir: ValidationMiddleware
    // trata el 0 como campo ausente, la misma trampa anotada en
    // `collection_update_quantity` y en `deck_card_set`. Quien la valida es el use
    // case, que además distingue el 0 (422) del null explícito, que significa
    // «la fila entera». `condition` tampoco: si no viene, el deseo conserva el
    // estado que se deseaba.
    'collection_fulfill_wish' => [
        'controller' => [CollectionController::class, 'fulfillWish'],
        'middleware' => [
            LoggingMiddleware::class,
            AuthMiddleware::class,
            CsrfMiddleware::class,
            [ValidationMiddleware::class, ['required' => ['item_id']]],
        ],
    ],

    'collection_remove' => [
        'controller' => [CollectionController::class, 'remove'],
        'middleware' => [
            LoggingMiddleware::class,
            AuthMiddleware::class,
            CsrfMiddleware::class,
            [ValidationMiddleware::class, ['required' => ['item_id']]],
        ],
    ],

    'collection_list' => [
        'controller' => [CollectionController::class, 'list'],
        'middleware' => [
            LoggingMiddleware::class,
            AuthMiddleware::class,
        ],
    ],

    // El corazón relleno del catálogo (M6 del plan de deseos). Es una LECTURA,
    // así que NO lleva CsrfMiddleware —el criterio del repo es el mismo que en
    // `collection_list`: sin escritura no hay estado que falsificar—, y tampoco
    // ValidationMiddleware, porque no tiene ningún campo: la única entrada es
    // el `user_id` que deja AuthMiddleware.
    //
    // Existe porque `GET /api/catalog/cards` se desvía antes de construir
    // Application (`public/index.php:30-43`) y no tiene sesión: el catálogo no
    // puede venir anotado con la lista de deseos de nadie, así que el cruce lo
    // hace el cliente con esta lista.
    'collection_wished_uuids' => [
        'controller' => [CollectionController::class, 'wishedUuids'],
        'middleware' => [
            LoggingMiddleware::class,
            AuthMiddleware::class,
        ],
    ],

    'collection_value' => [
        'controller' => [CollectionController::class, 'value'],
        'middleware' => [
            LoggingMiddleware::class,
            AuthMiddleware::class,
        ],
    ],

    // El progreso por edición es dato de USUARIO, no de catálogo: aunque
    // enseñe las ediciones, lo que dice es cuántas de sus cartas tienes tú.
    // Por eso va por aquí con AuthMiddleware y no por las rutas GET públicas
    // de /api/catalog/sets.
    // ========================================================================
    // IMPORTACIÓN — las dos mitades del alto obligatorio
    // ========================================================================
    // `import_preview` es una LECTURA aunque mande un fichero de 5 MB por POST:
    // detecta el formato, parsea y resuelve contra el catálogo, y no escribe una
    // sola fila. Por eso no lleva Csrf, igual que `collection_list`. Lo que la
    // protege es AuthMiddleware, que además es quien pone el `user_id` del log.
    //
    // Rate limit: se queda con el DEFECTO (60 peticiones por minuto y por IP,
    // de las env RATE_LIMIT_*), y es lo correcto para esta acción precisamente
    // porque el coste está en el tamaño del cuerpo y no en el número de
    // llamadas: un ManaBox de 20.000 líneas es UNA petición, no 20.000. Por eso
    // mismo `import_apply` manda todas sus filas de una vez en lugar de trocear
    // el lote — trocear convertiría una importación en 40 peticiones y sí
    // chocaría contra el límite, además de romper la transacción única que
    // impide una importación a medias.
    'import_preview' => [
        'controller' => [ImportController::class, 'preview'],
        'middleware' => [
            LoggingMiddleware::class,
            AuthMiddleware::class,
            [ValidationMiddleware::class, ['required' => ['content']]],
        ],
    ],

    // `import_apply` SÍ escribe: es la única acción de este plan que toca
    // `mtg_collection_item`, y lleva CsrfMiddleware DESPUÉS de AuthMiddleware
    // como las cuatro escrituras de colección.
    //
    // `rows` no va en la lista de `required` de ValidationMiddleware por lo
    // mismo que la `quantity` de `collection_update_quantity`: el middleware
    // trataría el array vacío como una ausencia y devolvería un 400 genérico,
    // cuando lo que hay que decir es "no hay ninguna fila que importar". Lo
    // valida el use case, que además puede señalar QUÉ fila del lote falla.
    'import_apply' => [
        'controller' => [ImportController::class, 'apply'],
        'middleware' => [
            LoggingMiddleware::class,
            AuthMiddleware::class,
            CsrfMiddleware::class,
        ],
    ],

    'collection_sets' => [
        'controller' => [CollectionController::class, 'sets'],
        'middleware' => [
            LoggingMiddleware::class,
            AuthMiddleware::class,
        ],
    ],

    // ========================================================================
    // MAZOS — todas privadas
    // ========================================================================
    // Las doce llevan AuthMiddleware por lo mismo que las de colección, y con
    // un motivo extra: `mtg_deck.id` es un BIGINT autoincremental GLOBAL, así
    // que el `user_id` que ese middleware pone en el request es lo único que
    // impide leer o vaciar el mazo de otro cambiando un número. El controller lo
    // lee de ahí y NUNCA del cuerpo de la petición.
    //
    // Tres son lectura (`deck_list`, `deck_get` y `deck_card_variants`, que
    // incluyen el análisis de disponibilidad) y no llevan Csrf, igual que
    // `collection_list`. Las nueve restantes escriben y lo llevan siempre
    // DESPUÉS de AuthMiddleware, que es quien pone el `auth_method` del que
    // depende CsrfMiddleware para saltarse a sí mismo con el cliente Capacitor.
    //
    // Ninguna de las doce sirve el mazo compartido: eso es una ruta GET pública
    // sin sesión (`GET /api/public/deck/{token}`, en PublicHttpRouter). Aquí
    // solo se decide SI se comparte, con `deck_share` y `deck_unshare`.

    'deck_list' => [
        'controller' => [DeckController::class, 'list'],
        'middleware' => [
            LoggingMiddleware::class,
            AuthMiddleware::class,
        ],
    ],

    'deck_get' => [
        'controller' => [DeckController::class, 'get'],
        'middleware' => [
            LoggingMiddleware::class,
            AuthMiddleware::class,
            [ValidationMiddleware::class, ['required' => ['deck_id']]],
        ],
    ],

    'deck_create' => [
        'controller' => [DeckController::class, 'create'],
        'middleware' => [
            LoggingMiddleware::class,
            AuthMiddleware::class,
            CsrfMiddleware::class,
            [ValidationMiddleware::class, ['required' => ['name']]],
        ],
    ],

    // Solo `deck_id` es obligatorio: la edición es PARCIAL a propósito y el
    // botón «desmontar» de M5 manda únicamente `status`. Exigir aquí `name`
    // obligaría a ese clic a reenviar el nombre para no borrarlo.
    'deck_update' => [
        'controller' => [DeckController::class, 'update'],
        'middleware' => [
            LoggingMiddleware::class,
            AuthMiddleware::class,
            CsrfMiddleware::class,
            [ValidationMiddleware::class, ['required' => ['deck_id']]],
        ],
    ],

    // `with_cards` NO va en la lista de required aunque el contrato lo declare:
    // es un booleano y `false` —«borra el mazo pero no me toques la colección»—
    // es el valor legítimo por defecto, que ValidationMiddleware confundiría con
    // una ausencia. Mismo problema que el 0 de `count`. Lo resuelve el use case,
    // que ante la duda elige `false`: borrar un mazo se deshace a mano, vaciar
    // la colección del usuario no.
    'deck_delete' => [
        'controller' => [DeckController::class, 'delete'],
        'middleware' => [
            LoggingMiddleware::class,
            AuthMiddleware::class,
            CsrfMiddleware::class,
            [ValidationMiddleware::class, ['required' => ['deck_id']]],
        ],
    ],

    'deck_card_add' => [
        'controller' => [DeckController::class, 'cardAdd'],
        'middleware' => [
            LoggingMiddleware::class,
            AuthMiddleware::class,
            CsrfMiddleware::class,
            [ValidationMiddleware::class, ['required' => ['deck_id', 'printing_uuid']]],
        ],
    ],

    // `count` no va en la lista, exactamente por lo mismo que la `quantity` de
    // `collection_update_quantity`: ValidationMiddleware trata el 0 como campo
    // ausente y devolvería un 400, cuando `count: 0` es la petición legítima de
    // «quítala del mazo». Lo valida el use case, que además distingue el 0 de
    // una cantidad fuera de rango.
    'deck_card_set' => [
        'controller' => [DeckController::class, 'cardSet'],
        'middleware' => [
            LoggingMiddleware::class,
            AuthMiddleware::class,
            CsrfMiddleware::class,
            [ValidationMiddleware::class, ['required' => ['deck_id', 'card_id']]],
        ],
    ],

    'deck_card_remove' => [
        'controller' => [DeckController::class, 'cardRemove'],
        'middleware' => [
            LoggingMiddleware::class,
            AuthMiddleware::class,
            CsrfMiddleware::class,
            [ValidationMiddleware::class, ['required' => ['deck_id', 'card_id']]],
        ],
    ],

    // Los cuatro campos que puede cambiar son opcionales —se manda el que se
    // toca—, así que ninguno va en `required`: que no venga ninguno no es un
    // campo ausente sino una petición sin sentido, y eso lo dice el use case con
    // un 422 explicativo en vez de un 400 genérico.
    'deck_card_change' => [
        'controller' => [DeckController::class, 'cardChange'],
        'middleware' => [
            LoggingMiddleware::class,
            AuthMiddleware::class,
            CsrfMiddleware::class,
            [ValidationMiddleware::class, ['required' => ['deck_id', 'card_id']]],
        ],
    ],

    // LECTURA: dice qué versiones de esa carta tienes en la colección para que
    // el cambio sea un desplegable y no un formulario. No escribe nada, así que
    // no lleva Csrf.
    'deck_card_variants' => [
        'controller' => [DeckController::class, 'cardVariants'],
        'middleware' => [
            LoggingMiddleware::class,
            AuthMiddleware::class,
            [ValidationMiddleware::class, ['required' => ['deck_id', 'card_id']]],
        ],
    ],

    // ESCRITURAS del M4 del Plan - Perfil Público: el enlace del mazo.
    //
    // Son las dos únicas acciones de los mazos que abren algo hacia fuera, y
    // por eso llevan Csrf con más motivo que las demás: un CSRF que moviera un
    // `deck_share` publicaría el mazo de la víctima sin que ella viera nada raro
    // en su pantalla —es el mismo argumento que `privacy_set`—. Y con Auth
    // delante, como siempre, porque es quien pone el `auth_method` del que
    // depende Csrf para saltarse a sí mismo con el cliente Capacitor.
    //
    // **RateLimitMiddleware no se declara y sí se aplica**: el ActionRouter se
    // lo pone por defecto a toda ruta, y declararlo aquí solo serviría para
    // sobreescribir los valores de las RATE_LIMIT_*. No hay motivo para que
    // compartir un mazo tenga un techo distinto del resto de escrituras; el
    // límite que de verdad importa en este plan es el de la ruta GET pública,
    // que vive en HttpRateLimitGuard.
    //
    // Solo `deck_id` es obligatorio en las dos: no hay nada más que mandar. El
    // token NO viene del cliente ni en `deck_share` —lo genera el servidor con
    // `random_bytes()`— ni en `deck_unshare`, que revoca el que haya sin
    // preguntar cuál es: pedirlo obligaría a la UI a conocerlo para poder
    // matarlo, que es exactamente lo contrario de lo que hace falta cuando lo
    // que quieres es cortar un enlace que se te fue de las manos.
    'deck_share' => [
        'controller' => [DeckController::class, 'share'],
        'middleware' => [
            LoggingMiddleware::class,
            AuthMiddleware::class,
            CsrfMiddleware::class,
            [ValidationMiddleware::class, ['required' => ['deck_id']]],
        ],
    ],

    'deck_unshare' => [
        'controller' => [DeckController::class, 'unshare'],
        'middleware' => [
            LoggingMiddleware::class,
            AuthMiddleware::class,
            CsrfMiddleware::class,
            [ValidationMiddleware::class, ['required' => ['deck_id']]],
        ],
    ],

    // ========================================================================
    // PRECONS — la única acción de escritura del catálogo de precons
    // ========================================================================
    // Leer precons va por las dos rutas GET de CatalogHttpRouter (la tercera
    // divergencia, aprobada el 2026-09-10: lectura pública, paginable y
    // cacheable). **Meter la caja en tu colección NO**: es dato de usuario, y la
    // divergencia es solo de lectura y no se extiende. Así que vuelve aquí, al
    // endpoint único por POST, con la misma pila que el resto de escrituras y
    // con Csrf DESPUÉS de Auth por el motivo de siempre.
    //
    // Escribe en DOS tablas —`mtg_collection_item` y `mtg_deck`/`mtg_deck_card`—
    // dentro de una sola transacción, como `import_apply`.
    //
    // `deck_status` no va en `required` aunque el contrato lo declare: es
    // opcional y su defecto es `built` (acabas de comprar la caja y la tienes
    // montada). Solo `file_name` es obligatorio, que es la clave natural del
    // precon: hay cajas homónimas en ediciones distintas.
    //
    // Y desde M7 acepta `is_wishlist`, también opcional: la caja entera a la
    // lista de deseos. **No es una acción nueva** —no hay operación nueva, es
    // esta misma con las cartas cayendo en el otro conjunto del `UNIQUE KEY`—,
    // igual que `import_apply` lleva la bandera del lote en vez de tener un
    // gemelo. Con ella puesta el mazo nace `building` y no `built`, porque
    // `built` consume colección y un mazo construido con cartas que solo se
    // desean dispararía conflictos de sobreasignación falsos.
    'precon_add_to_collection' => [
        'controller' => [PreconController::class, 'addToCollection'],
        'middleware' => [
            LoggingMiddleware::class,
            AuthMiddleware::class,
            CsrfMiddleware::class,
            [ValidationMiddleware::class, ['required' => ['file_name']]],
        ],
    ],

    // ========================================================================
    // PRIVACIDAD — qué se ve de ti, y quién lo ve
    // ========================================================================
    // Las dos son sobre UNO MISMO y llevan AuthMiddleware: el `user_id` que ese
    // middleware pone en el request es el único dueño que se edita aquí. La
    // privacidad de OTRA persona no se lee por ninguna acción —sería un mapa de
    // qué secciones vale la pena volver a intentar—: se resuelve en el router
    // público del M3 a través de `Visibilidad`.
    //
    // `privacy_get` es lectura y va sin Csrf, por lo mismo que `collection_list`.
    // `privacy_set` escribe y lo lleva SIEMPRE después de Auth, que es quien
    // pone el `auth_method` del que depende Csrf para saltarse a sí mismo con el
    // cliente Capacitor.

    'privacy_get' => [
        'controller' => [PrivacyController::class, 'get'],
        'middleware' => [
            LoggingMiddleware::class,
            AuthMiddleware::class,
        ],
    ],

    // ValidationMiddleware va SIN `required`, y no es un descuido: la edición es
    // PARCIAL —se manda solo el selector que el usuario ha movido—, así que
    // ninguna de las cinco secciones es obligatoria y exigir una obligaría al
    // panel a reenviar las otras cuatro, que es justo el bug que este hito
    // evita. Lo que sí se valida es el VALOR, y eso no lo puede hacer un
    // middleware que solo mira si el campo está: `Nivel::desde()` rechaza un
    // nivel inventado con un 422 en vez de dejarlo caer al defecto.
    'privacy_set' => [
        'controller' => [PrivacyController::class, 'set'],
        'middleware' => [
            LoggingMiddleware::class,
            AuthMiddleware::class,
            CsrfMiddleware::class,
            ValidationMiddleware::class,
        ],
    ],

    // ========================================================================
    // AMISTAD — M2 del Plan - Amigos y Seguimiento
    // ========================================================================
    // Las cinco son sobre UNO MISMO y llevan AuthMiddleware: el `user_id` que
    // ese middleware pone en el request es el único lado de la amistad que se
    // mueve aquí. Si viniera del cuerpo, cambiar un número dejaría a cualquiera
    // ACEPTAR la solicitud de otro — y aceptar es lo único que abre el nivel
    // `friends` de la privacidad de esa persona.
    //
    // Las cuatro primeras escriben y llevan Csrf SIEMPRE después de Auth, que es
    // quien pone el `auth_method` del que depende Csrf para saltarse a sí mismo
    // con el cliente Capacitor. `friend_list` es lectura y va sin Csrf, por lo
    // mismo que `collection_list` y `privacy_get`.
    //
    // RateLimitMiddleware no se declara y sí se aplica: el ActionRouter se lo
    // pone por defecto a toda ruta. No hay motivo para que pedir amistad tenga
    // un techo distinto del resto de escrituras.

    // `username` y NO un id de usuario, y es una decisión del plan: `username` es
    // `NOT NULL UNIQUE` y es la URL pública del perfil, así que hay que conocerlo
    // para escribirlo; con ids numéricos por el cuerpo, `friend_request` sería un
    // censo recorrible de quién existe. Los otros dos noes —422 al pedírtela a ti
    // mismo, 409 si ya hay fila— los traduce FriendController.
    'friend_request' => [
        'controller' => [FriendController::class, 'request'],
        'middleware' => [
            LoggingMiddleware::class,
            AuthMiddleware::class,
            CsrfMiddleware::class,
            [ValidationMiddleware::class, ['required' => ['username']]],
        ],
    ],

    // El 403 al `requester` que intenta aceptar su propia solicitud es el
    // *Hecho cuando:* del hito y la única línea de seguridad de la amistad. NO
    // vive aquí —un middleware no puede saberlo— sino en `AceptarAmistad`, sobre
    // la fila ya leída. Esta ruta solo garantiza que llega autenticado.
    'friend_accept' => [
        'controller' => [FriendController::class, 'accept'],
        'middleware' => [
            LoggingMiddleware::class,
            AuthMiddleware::class,
            CsrfMiddleware::class,
            [ValidationMiddleware::class, ['required' => ['friendship_id']]],
        ],
    ],

    // Rechazar BORRA la fila: no hay `rejected` en el ENUM, porque con el UNIQUE
    // simétrico una fila rechazada impediría volver a pedir amistad para siempre.
    // Solo el destinatario.
    'friend_reject' => [
        'controller' => [FriendController::class, 'reject'],
        'middleware' => [
            LoggingMiddleware::class,
            AuthMiddleware::class,
            CsrfMiddleware::class,
            [ValidationMiddleware::class, ['required' => ['friendship_id']]],
        ],
    ],

    // Deshacer también borra, y lo puede hacer CUALQUIERA DE LOS DOS: una
    // amistad se concede desde un lado pero se rompe desde ambos. Quita el acceso
    // al nivel `friends` de golpe y retroactivamente — lo que se corta es la
    // próxima lectura de `Visibilidad`.
    'friend_remove' => [
        'controller' => [FriendController::class, 'remove'],
        'middleware' => [
            LoggingMiddleware::class,
            AuthMiddleware::class,
            CsrfMiddleware::class,
            [ValidationMiddleware::class, ['required' => ['friendship_id']]],
        ],
    ],

    // Lectura pura: amigos, solicitudes recibidas, enviadas y los tres
    // contadores. Sin Csrf y sin ValidationMiddleware, porque no tiene ni un
    // campo: la única entrada es el `user_id` que deja AuthMiddleware.
    'friend_list' => [
        'controller' => [FriendController::class, 'list'],
        'middleware' => [
            LoggingMiddleware::class,
            AuthMiddleware::class,
        ],
    ],

    // ========================================================================
    // SEGUIR — M3 del Plan - Amigos y Seguimiento
    // ========================================================================
    // Otro controller y otra tabla que las cinco de arriba, y eso es el hito
    // entero: SEGUIR NO DA NINGÚN ACCESO. Un marcador unilateral sobre un perfil
    // público, sin permiso que pedir y sin estados. Después de `follow_add`, la
    // respuesta de `GET /api/public/user/X` es byte a byte la misma que antes,
    // porque quien la compone —`PublicHttpRouter` preguntando a `Visibilidad`—
    // no conoce `user_follow` y no puede conocerla. El día que la conozca, el
    // nivel `friends` pasará a significar «cualquiera que pulse seguir».
    //
    // Las dos primeras escriben y llevan Csrf SIEMPRE después de Auth, que es
    // quien pone el `auth_method` del que depende Csrf para saltarse a sí mismo
    // con el cliente Capacitor. `follow_list` es lectura y va sin Csrf, por lo
    // mismo que `friend_list` y `collection_list`.
    //
    // RateLimitMiddleware no se declara y sí se aplica: el ActionRouter se lo
    // pone por defecto a toda ruta.

    // `username` y NO un id, igual que `friend_request` y por el mismo motivo:
    // es la URL pública del perfil, así que hay que conocerlo para escribirlo.
    // Los dos noes los traduce FollowController, y los dos son 422: seguirte a
    // ti mismo, y seguir un perfil SIN NINGUNA SECCIÓN EN `everyone` —un
    // marcador a una página vacía—. Seguir a quien ya sigues NO es un 409: no
    // hay conflicto que resolver, así que es idempotente y devuelve 200.
    'follow_add' => [
        'controller' => [FollowController::class, 'add'],
        'middleware' => [
            LoggingMiddleware::class,
            AuthMiddleware::class,
            CsrfMiddleware::class,
            [ValidationMiddleware::class, ['required' => ['username']]],
        ],
    ],

    // Idéntica, y con una diferencia deliberada dentro: quitar el marcador NO
    // comprueba la privacidad del seguido. Si esa persona cerró su perfil
    // después de que la siguieras, exigir aquí la condición de entrada te
    // dejaría con un marcador imposible de soltar.
    'follow_remove' => [
        'controller' => [FollowController::class, 'remove'],
        'middleware' => [
            LoggingMiddleware::class,
            AuthMiddleware::class,
            CsrfMiddleware::class,
            [ValidationMiddleware::class, ['required' => ['username']]],
        ],
    ],

    // Lectura pura: a quién sigues, y CUÁNTA gente te sigue. El contador es un
    // número y nunca una lista —quién te sigue no se publica, porque seguir es
    // un acto unilateral que el seguido no autoriza—, y su dueño no puede
    // impedir que suba: la herramienta para no ser seguido es bajar las
    // secciones a `friends` o `nobody`. Sin Csrf y sin ValidationMiddleware,
    // porque no tiene ni un campo.
    'follow_list' => [
        'controller' => [FollowController::class, 'list'],
        'middleware' => [
            LoggingMiddleware::class,
            AuthMiddleware::class,
        ],
    ],

    // ========================================================================
    // BUSCAR USUARIOS — M6 del Plan - Amigos y Seguimiento
    // ========================================================================
    // La puerta de entrada que a las cinco acciones de amistad les faltaba:
    // `friend_request` va por nombre EXACTO, así que hasta este hito solo se
    // llegaba a alguien sabiéndose su `username` de memoria.
    //
    // **Es una acción `POST` y NO una ruta `GET`**, y es la cuarta vez que este
    // proyecto se hace la pregunta. La respuesta ya estaba escrita en el
    // `CLAUDE.md` del repo al rechazar el «corazón relleno» del catálogo: «si
    // vuelves a necesitar el catálogo pero sabiendo algo de mí, la respuesta es
    // una acción POST normal, no una ruta GET nueva». Los tres criterios del
    // desvío `GET` fallan aquí: esto devuelve dato de usuarios **filtrado por la
    // privacidad de cada uno** (no reconstruible), cambia en cuanto alguien toca
    // su panel (no cacheable) y no tiene sentido compartirlo por URL.
    //
    // **Con `AuthMiddleware`, y esto no es negociable**: un buscador de personas
    // abierto a internet es exactamente el directorio que este hito existe para
    // no publicar. **Sin `Csrf`**: es lectura, como `privacy_get`, `friend_list`
    // y `collection_list` — no escribe una fila, así que no hay estado que
    // falsificar.
    //
    // `required: ['q']` da el 400 de «no mandaste nada». **El 422 de «menos de
    // tres caracteres» NO puede darlo este middleware**, que solo mira si el
    // campo está: vive en `BuscarUsuarios`, junto al escapado de `%` y `_` que
    // es lo que impide que `q = '%%%'` mida tres y devuelva el censo entero.
    //
    // Y el `RateLimitMiddleware` va **declarado a mano**, que es lo que lo
    // separa de las ocho rutas de relación: el mínimo de tres caracteres impide
    // pedir el censo de una vez, y el límite es lo que impide reconstruirlo
    // recorriendo el alfabeto a fuerza de peticiones. 20 por minuto es holgado
    // para quien está escribiendo un nombre y estrecho para quien recorre
    // `aaa`, `aab`, `aac`… `by => 'ip'` y no `'user'` porque **esta entrada de
    // la pila se ejecuta ANTES que `AuthMiddleware`** —el orden de este fichero
    // es el de ejecución y RateLimit va primero a propósito, para descartar una
    // petición abusiva antes de tocar la sesión o la base de datos—, así que
    // `user_id` todavía no existe y `'user'` caería al IP igualmente.
    'user_search' => [
        'controller' => [UserSearchController::class, 'search'],
        'middleware' => [
            [RateLimitMiddleware::class, ['limit' => 20, 'window' => 60, 'by' => 'ip']],
            LoggingMiddleware::class,
            AuthMiddleware::class,
            [ValidationMiddleware::class, ['required' => ['q']]],
        ],
    ],

];
