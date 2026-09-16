# CLAUDE.md

Guía para Claude Code al trabajar en este repositorio (checkout **dev**, rama `dev`).

> Documentación en español por convención del proyecto (igual que libraryVue / statCoin / spoticlone).
> Nombres de clases, comandos y términos técnicos se dejan tal cual.

## 🧠 Brain

Spec y contexto del proyecto en el segundo cerebro:
`/home/david/Documents/workspace/Brain/03 - Proyectos/TCGDesk.md`. Léela para el panorama
(decisiones, estado, roadmap); este `CLAUDE.md` cubre el detalle técnico del repo.

Sub-notas útiles según lo que toques: `TCGDesk/Base de Datos.md` (esquema objetivo de las tres zonas),
`TCGDesk/Decisiones Técnicas.md` (el porqué de todo lo raro que hay aquí) y `TCGDesk/Fuentes de
Datos.md` (MTGJSON, Scryfall y por qué Cardmarket está descartado).

## Qué es TCGDesk

App web + móvil para **registrar, valorar y organizar una colección de cartas de TCG**, empezando por
Magic: The Gathering. La apuesta que lo define: **el catálogo entero y los precios viven en local**;
ninguna petición de usuario sale a internet.

Stack: **Docker · PHP 8.2 (clean architecture / hexagonal, PHP-DI 7) · MySQL 8 · Vue 3.5 (Vite) +
Pinia + PrimeVue 4 · Capacitor 8 (Android)**.

Deriva del patrón compartido con [libraryVue], statCoin, trackit, spoticlone y galleryVue: endpoint
único + router de acciones + middleware declarativo. **Con cuatro divergencias deliberadas** — ver
abajo, porque si vienes de otro de esos repos las vas a leer como errores y no lo son.

## Puertos

| Servicio | Dev (host) | Prod |
|---|---|---|
| Frontend | `8094` | *(pendiente — el `8092` reservado está ocupado)* |
| Backend | `8899` | interno, no expuesto |
| MySQL | `3312` (db `tcgdesk_db`) | `3313` (db `tcgdesk_db_prod`) |

Producción todavía no existe: no hay `tcgdesk_prod` ni Cloudflare Tunnel. Se monta cuando haya algo
que desplegar.

## Comandos

Todo corre en Docker. **No hay PHP en el host**; no lo busques.

```bash
./dev-setup.sh              # levanta los tres contenedores (pide claves solo si faltan)
./dev-setup.sh --migrate    # aplica migraciones pendientes sin resetear la BD
./dev-setup.sh --stop       # para
./dev-setup.sh --logs       # logs en vivo
./dev-setup.sh --reset      # recrea contenedores Y VOLÚMENES (borra la BD)
./dev-setup.sh --mobile     # compila el APK, pone `adb reverse` e INSTALA en el móvil

# URLs (dev)
#   Frontend  http://localhost:8094
#   Backend   http://localhost:8899/index.php   (endpoint ÚNICO; la acción va en el body)
#   MySQL     localhost:3312  (db tcgdesk_db, user tcgdesk_user)

# Tests del backend — SIEMPRE dentro del contenedor
docker compose exec backend composer test

# Tests del frontend — al revés: SIEMPRE en el host, con nvm (Node 22)
cd frontend && npm test              # vitest run — 1.017 tests en 37 ficheros, ~19 s
cd frontend && npm run test:watch    # vitest en modo vigilancia
cd frontend && npm run test:coverage # umbrales incluidos; sale con código != 0 si no pasan

# Capa CLI
docker compose exec backend php bin/tcgdesk           # lista comandos
docker compose exec backend php bin/tcgdesk hello

# Ingesta — SIEMPRE con -u www-data (ver trampas)
docker compose exec -u www-data backend php bin/tcgdesk catalog:import   # ~3,5 min
docker compose exec -u www-data backend php bin/tcgdesk prices:sync      # ~18 s, diario
docker compose exec -u www-data backend php bin/tcgdesk prices:seed      # 90 días, una vez
#   --force-download vuelve a bajar AllPrices.json.gz. OBLIGATORIO para rellenar un hueco del
#   histórico: sin él reutiliza el .gz de disco, que trae la ventana de 90 días DE ENTONCES, y
#   con INSERT IGNORE no inserta nada pareciendo que funcionó.
docker compose exec -u www-data backend php bin/tcgdesk prices:health    # 0 si el histórico está al día
docker compose exec -u www-data backend php bin/tcgdesk images:cache     # imágenes de la colección
docker compose exec -u www-data backend php bin/tcgdesk decks:import      # ~1,5 min, los 3.029 precons

# Índice de nombres del resolvedor — LAS DOS columnas `name_normalized`
#   `mtg_card` (34.992 cartas, el nombre en inglés) y `mtg_printing_localized` (410.604 filas en
#   diez idiomas, que es lo que hace que una «Llanura» se resuelva). `catalog:import` las mantiene
#   al día por su cuenta; esto es el backfill.
docker compose exec -u www-data backend php bin/tcgdesk catalog:normalize              # ~20 s las dos
docker compose exec -u www-data backend php bin/tcgdesk catalog:normalize --all        # si cambia NameNormalizer
docker compose exec -u www-data backend php bin/tcgdesk catalog:normalize --batch=2000 # lotes más grandes
#   Devuelve 1 si al terminar queda alguna fila a NULL: una fila sin clave no resuelve por nombre y
#   no protesta nadie.

# Id de Scryfall por idioma — es lo que hace que ORB siembre la referencia del idioma de la carta
#   Sale de `foreignData[].identifiers.scryfallId`, que la ingesta TIRABA hasta el 2026-09-16.
#   `catalog:import` lo mantiene al día (MISMO enganche frágil que `name_normalized`); esto es el
#   backfill de las 410.604 filas ya existentes. Devuelve 1 si queda alguna que debería tener id.
docker compose exec -u www-data backend php bin/tcgdesk catalog:localized-ids

# `catalog:hash` y `vision:refs` EXISTIERON y se borraron el 2026-09-15 con el índice visual.
#   No los busques: la vía del hash perceptual está medida y muerta (una foto real queda a 11-12
#   bits de su propia referencia contra un margen de 6). El porqué entero, con las siete tandas de
#   medidas, está en el Log del «Plan - Escáner de Cartas por Cámara» del Brain.

# Móvil (Capacitor) — esto sí en el host, con nvm
cd frontend && npm run build:mobile && npx cap sync android
```

## Arquitectura

### Backend: endpoint único + router de acciones + middleware declarativo

- **Una sola URL** (`backend/public/index.php`). El cliente manda `POST` con JSON
  `{ "action": "...", ...payload }`.
- `config/routes.php` mapea cada acción → `[Controller::class, 'método']` + su pila de middleware.
  `ActionRouter` ejecuta la pila y despacha.
- Middlewares en `src/Middleware/`: `Logging`, `Auth`, `Csrf`, `Validation`, `RateLimit`. El
  `RateLimitMiddleware` se aplica **por defecto a toda ruta**; declararlo explícitamente en la ruta
  sobreescribe los valores por defecto.
- Dominio en `src/Domain/**` (interfaces de repositorio + modelos), persistencia `MySql*Repository`
  con PDO, registro de interfaz → implementación en `config/container.php`.

### Divergencia 1 — añadir un endpoint son DOS sitios, no tres

En libraryVue el `ActionRouter` lleva dos `match` gigantes (acción → llamada al método, nombre →
instancia de controller), y su propio `CLAUDE.md` avisa de que añadir un endpoint obliga a tocar tres
sitios. **Aquí no.** La ruta declara el FQCN y el router lo resuelve del contenedor:

```php
'ping' => [
    'controller' => [PingController::class, 'ping'],
    'middleware' => [LoggingMiddleware::class],
],
```

**Añadir un endpoint = `config/routes.php` + el método del controller.** `ActionRouter` no se toca.
El controller recibe el `$request` entero, con el `user_id` que le haya puesto `AuthMiddleware`.

### Divergencia 2 — capa CLI `bin/tcgdesk`

Ningún otro repo del workspace la tiene. Aquí hay que ingerir 105.788 printings y sincronizar precios
a diario desde cron; meterlo por HTTP es frágil (timeouts, `memory_limit` de la petición).

La clave de que no sea un apaño: **no tiene bootstrap propio**. Monta el mismo `config/container.php`
que `public/index.php`, así que los use cases de ingesta son use cases normales y testeables.

```
config/commands.php          declara los comandos por FQCN
src/Cli/CommandInterface     getName() · getDescription() · run(array $args): int
src/Cli/CommandRegistry      resuelve por nombre, instancia perezosamente
```

`run()` devuelve el **código de salida del proceso**, y no es decorativo: es lo que mira el cron. Un
comando que falla y devuelve `0` deja el catálogo desactualizado sin que nadie se entere. El registry
captura las excepciones, las loguea y devuelve `1`.

**Desde el 2026-09-14 el cron existe de verdad** (`crontab -l`): `prices-sync.sh` a las 15:30 CEST e
`images-cache.sh` a las 4:15. Y hay **ocho** comandos (2026-09-15) — esta cifra se ha quedado corta
cuatro veces y larga una, así que cuéntalos con `bin/tcgdesk` en vez de leerla. Llegaron a ser diez:
`catalog:hash` y `vision:refs` entraron con el índice visual y **se borraron el mismo día**, cuando la
vía del hash perceptual se midió contra fotos reales y no la pasó. El último que queda es
`prices:health`, que compara
`MAX(price_date)` de `mtg_price_daily` con hoy y sale con `1` si el desfase pasa de dos días. Va
enganchado al final de `prices-sync.sh`, que manda el código del sync si el sync falló y el de la
salud si el sync fue bien — un job que termina en 0 sobre una tabla parada es exactamente el fallo
que costó 33 días de histórico. **Los logs del cron van al home, NO a `/var/log`**: bash abre el
redirect antes de ejecutar el comando, así que un destino no escribible no «pierde el log», impide
que el job corra.

### Divergencia 3 — rutas `GET` para lectura de catálogo

El catálogo, con filtros y scroll infinito, necesita URLs paginables y cacheables. **Solo el catálogo
y solo lectura** va por `GET /api/catalog/*`, y la divergencia entera son un `if` en
`public/index.php` y la clase `src/Router/CatalogHttpRouter.php`. El desvío ocurre **antes** de
construir `Application`, porque el catálogo no necesita sesión, ni CSRF, ni el pipeline de
middlewares de las acciones.

```
GET /api/catalog/sets                   → las 868 ediciones
GET /api/catalog/cards?q=&set=&rarity=&colors=&price_min=&price_max=&sort=&cursor=&limit=
GET /api/catalog/cards/{uuid}           → ficha + legalidades + idiomas + histórico de precio
                                          404 {"error":"printing_not_found"}
GET /api/catalog/cards/{uuid}/printings?cursor=&limit=
                                        → todas las ediciones de esa carta, de la más nueva a la
                                          más vieja, con el MISMO contrato que /cards
```

**La sexta rama va ANTES que la de la ficha en el `match`**, y no por estilo: `([^/]+)` no casa con
barras, así que hoy el orden da igual, pero el día que alguien relaje ese patrón a `(.+)` la ficha se
come la ruta nueva y contesta `printing_not_found` sin que nadie entienda por qué. **Su 404 es del
`uuid`, no de la carta**: una impresión que existe pero nunca se reimprimió devuelve **200 con un
solo item**. Y **`limit` lo acota el router** (1..100, 60 por defecto), al revés que las demás, que lo
acotan en su `SearchCriteria`: esta ruta no tiene criteria y `ValidationMiddleware` no la ve, así que
lo que no acote el router no lo acota nadie.

**Se pagina por cursor, nunca por offset**: `LIMIT 60 OFFSET 50000` obliga a MySQL a recorrer y tirar
50.000 filas en cada tirón del scroll. El cursor es opaco y solo el cliente lo transporta.

**Cualquier escritura sigue siendo una acción `POST`.** Esta divergencia no se extiende… salvo una
vez más, y conviene saberlo: `src/Router/ImageHttpRouter.php` sirve las imágenes cacheadas por el
mismo mecanismo (`GET /api/images/{scryfall_id}` → 200 con el fichero local y caché de un año, 302 al
CDN de Scryfall mientras no exista). Mismo criterio —lectura de un recurso estático, sin sesión ni
CSRF— y un binario no cabe en una respuesta JSON.

**Y el tercer caso ya llegó, discutido antes como pedía esta nota.** El 2026-09-11 se sumaron los
precons, con la discusión hecha en el plan y no a posteriori: cumplen los tres criterios del desvío
original (lectura de dato público y reconstruible, sin sesión ni CSRF, y una lista de 3.029 que
quiere paginarse por cursor y compartirse por URL). **No hay router nuevo**: son dos casos más en el
`match (true)` de `CatalogHttpRouter` y `public/index.php` no se tocó.

```
GET /api/catalog/decks?type=&set=&q=&playable=&cursor=&limit=
GET /api/catalog/decks/{fileName}       → 404 {"error":"precon_not_found"}
```

**Meter el precon en la colección sigue siendo `POST`** (`precon_add_to_collection`, con
`Logging → Auth → Csrf → Validation`): la divergencia es **solo de lectura** y no se extiende. Eran
**tres** casos en `CatalogHttpRouter`; hoy son **cuatro rutas `GET`** contando el router público de
abajo. Un quinto se discute antes.
>
> **Y el 2026-09-14 entró un caso más SIN abrir divergencia**: `GET /api/catalog/cards/{uuid}/printings`,
> la sexta ruta de `CatalogHttpRouter`. Cumple los tres criterios de siempre —catálogo público,
> reconstruible, sin sesión ni CSRF— así que es un caso más del mismo `match`, no un desvío nuevo:
> **siguen siendo cuatro divergencias `GET`**, no cinco.

> **Y el cuarto se discutió y se RECHAZÓ**, el 2026-09-12, al pintar el corazón relleno del catálogo.
> Hacía falta saber qué impresiones están en tu lista de deseos mientras miras `/catalog`, y lo
> directo habría sido anotar la respuesta de `GET /api/catalog/cards`. **No se hizo, y el motivo vale
> para cualquier caso futuro:** esa ruta se desvía antes de construir `Application`, así que no tiene
> sesión — anotarla habría significado meterle autenticación a un endpoint cuyo valor entero es ser
> público y cacheable, y servir dato de usuario por una vía pensada para dato reconstruible. En su
> lugar el cruce lo hace el **cliente**: `collection_wished_uuids` es una acción `POST` normal que
> devuelve los `printing_uuid` deseados, y el store del frontend guarda el `Set`. **Si vuelves a
> necesitar «el catálogo pero sabiendo algo de mí», la respuesta es esta, no una ruta `GET` nueva.**

### Divergencia 4 — el router público, que NO es dato reconstruible

El 2026-09-13 el perfil público abrió `src/Router/PublicHttpRouter.php` con un tercer `if` en
`public/index.php`. Cumple tres de los cuatro criterios del desvío del catálogo —lectura, sin sesión
ni CSRF, URL paginable y compartible— y **falla el cuarto: sirve la colección de una persona, no
dato público y reconstruible**. Por eso tiene **router propio** y no dos casos más en
`CatalogHttpRouter`: aquel sirve MTGJSON sin comprobar nada, y mezclar ahí rutas que consultan
permisos haría que la próxima ruta de catálogo naciera con la duda de si tiene que comprobar algo.

```
GET /api/public/user/{username}                     → perfil + mapa `visible` de secciones
GET /api/public/user/{username}/{collection|decks|sets|wishlist}?cursor=
                                                    → 403 {"error":"not_visible"} si no se ve
GET /api/public/deck/{token}                        → 404 si el token no vale
```

Cinco cosas que hay que saber antes de tocar nada de esto:

- **`Visibilidad::puedeVer()` es el único sitio donde se decide si algo se ve** (`src/Domain/Social/`).
  Ningún use case consulta `user_privacy_settings` por su cuenta, y no es purismo: cinco
  comprobaciones repartidas por cinco use cases es cómo se acaba filtrando una sección. **`friends`
  nació devolviendo siempre `false`** —no existía `friendships`— y su rama va **antes** que la de
  `everyone` para que nada llegue a un `true` por defecto. **Desde el 2026-09-14 esa rama pregunta
  por una amistad `accepted`** y `Nivel::esInerte()` **se borró**: dejarlo habría cortado el
  `match(true)` antes de la consulta, con la suite en verde y el nivel sin servir para nada. Ver
  «Amistad, seguimiento y buscador», abajo.
- **404 y no 403 para el token de mazo**, al revés que para las secciones del perfil: un 403
  confirmaría que el token existe. En el perfil sí es 403, porque el `username` es público por diseño.
- **La respuesta pública se compone por lista blanca de campos**, nunca «la respuesta interna menos
  unos cuantos». La colección lleva `notes` y `priceEur` por línea, los mazos `missingCount` y los
  sets `valueEur`: publicar la sección tal cual abre el **valor** y las notas privadas con el permiso
  de la **colección**. Y el `email` no sale en ninguna ruta pública, nunca.
- **`src/Router/HttpRateLimitGuard.php` limita las rutas públicas a 60/min por IP.** Hasta entonces
  **ninguna ruta `GET` estaba limitada**: los desvíos ocurren antes de construir `Application` y se
  saltan el pipeline. Reutiliza el `RateLimitMiddleware` de siempre, así que `RATE_LIMIT_ENABLED`
  sigue siendo el único interruptor. **El contador es por GRUPO de rutas, no por URI**: el middleware
  construye la clave con `$request['action']`, y pasarle la URI daría un contador por `username` —
  recorrer un diccionario de nombres no gastaría límite ninguno. **El catálogo y las imágenes siguen
  sin limitar**, y así tienen que seguir: estrangularlos rompe el scroll infinito.
- **`src/Infrastructure/Auth/EspectadorActual.php`** resuelve el espectador (cookie de sesión o
  `Bearer`) fuera del pipeline, y `AuthMiddleware` la consume desde el 2026-09-13. Abre la sesión en
  `read_and_close`, sin `Set-Cookie`. **Fallar la resolución no falla la petición**: una cookie
  caducada o un Bearer basura son un anónimo, nunca un 401 — un 401 rompería el enlace compartido.

**Escribir sigue siendo `POST`**: `privacy_get`, `privacy_set` (edición **parcial**), `deck_share` y
`deck_unshare`. Y en el frontend, **`publicGet` en `services/api.js`** para estas rutas: sin cookie
—no viaja en cross-origin sin `withCredentials`— pero **con `Authorization: Bearer` si hay JWT**, que
es lo único que funciona igual en web y en Capacitor.

### Amistad, seguimiento y buscador — y **ninguna divergencia nueva**

El 2026-09-14 entraron nueve acciones (`friend_request`, `friend_accept`, `friend_reject`,
`friend_remove`, `friend_list`, `follow_add`, `follow_remove`, `follow_list`, `user_search`) y
**todas son `POST` normales**: siguen siendo cuatro divergencias `GET`, no cinco. `user_search` fue
el caso que había que discutir, y la respuesta ya estaba escrita arriba —el rechazo del «corazón
relleno»—: devuelve usuarios filtrados por su privacidad, o sea dato **no** reconstruible.

Seis cosas que hay que saber antes de tocar esto, y **las cuatro primeras salen verdes si se
rompen**:

- **`pending` NO es amistad.** `sonAmigos()` pregunta por `status = 'accepted'` y por nada más. Es el
  fallo que funciona perfectamente en toda prueba manual, porque quien prueba acepta la solicitud.
- **Solo el `addressee` acepta o rechaza** (403 si lo intenta quien pidió). Es una línea y es toda la
  seguridad de la amistad: sin ella, cualquiera acepta sus propias solicitudes.
- **`user_follow` no aparece en `Visibilidad`, ni en `FriendshipRepositoryInterface`, ni en ninguna
  consulta que decida permisos**, y no puede aparecer: el día que alguna una las dos tablas con un
  `OR`, el nivel `friends` pasa a significar «cualquiera que pulse seguir». Son dos puertos separados
  a propósito (`FollowRepositoryInterface`).
- **`show_in_search` NO es una `Seccion`**, vive en `Domain\Social\Descubrimiento` y tiene **dos**
  valores. Meterla en `Seccion` habría hecho seguible un perfil con las cinco secciones en `nobody`,
  porque `Seguir::tieneCaraPublica()` recorre `nivelesDe()` buscando **un** `everyone` — sin un solo
  error. Y `guardarNiveles()` habría intentado escribir `friends` en un ENUM que no lo tiene.
- **Quien filtre por `show_in_search` usa `LEFT JOIN` + `COALESCE`.** La ausencia de fila en
  `user_privacy_settings` **significa los defectos** y hoy la tabla está vacía: con un `JOIN` interno,
  el buscador devuelve **cero resultados siempre**, en silencio. El defecto está duplicado en tres
  sitios (el `DEFAULT` de la columna, el `COALESCE`, y `Descubrimiento::porDefecto()`): si se cambia
  uno, se cambian los tres.
- **El mínimo de 3 caracteres de `user_search` no basta por sí solo.** `%` y `_` son comodines de
  `LIKE`: `q="%%%"` mide tres, pasa la validación y devolvería la tabla `users` entera. El patrón se
  escapa en `BuscarUsuarios::escaparComodines()`, y hay dos tests que se ponen rojos si desaparece.
  La búsqueda es **por prefijo** (`LIKE 'x%'`, que usa el índice); una subcadena sería full scan y
  haría enumerable el directorio desde cualquier letra interior.

Y dos decisiones de esquema que **no se «corrigen» por inercia**: las generadas de `friendships` son
**`VIRTUAL` y no `STORED`** —MySQL 8.0.44 rechaza una FK con `ON DELETE CASCADE` sobre la columna base
de una `STORED` (`ERROR 1215`), y sí indexa las virtuales en un `UNIQUE`—, y **no existe el estado
`rejected`**: rechazar hace `DELETE`, porque con el `UNIQUE` simétrico una fila rechazada impediría
volver a pedir para siempre.

En el frontend, el estado de la relación con una persona **lo cruza el cliente** (`relacionCon()` en
`stores/friends.js`) sobre `friend_list` + `follow_list`: ninguna acción contesta «¿qué soy yo de
esta persona?», y anotar la ruta pública con dato de sesión es lo que se rechazó arriba. Devuelve
**dos campos independientes** —`amistad` y `siguiendo`— porque las dos relaciones son ortogonales, y
eso es lo que impide pintar dos botones contradictorios.

### CSRF: lo declara la ruta, y ahora sí lo declara

`CsrfMiddleware` existía desde el primer día pero **ninguna ruta lo usaba** hasta el 2026-09-10. Lo
llevan las cuatro escrituras de colección y `logout`; **`login` no, a propósito**: es quien *emite* el
token, así que exigírselo pediría un secreto que aún no existe. El orden de la pila es
`RateLimit → Logging → Auth → Csrf → Validation`, y **Auth antes que Csrf no es negociable**: el
middleware se salta a sí mismo cuando la autenticación fue por JWT, y esa marca la pone `Auth`. Sin
ella, Capacitor —que no tiene cookie— comería 403.

### Frontend (Vue 3 + PrimeVue)

- `createWebHashHistory`, no history mode: con Capacitor la app se sirve desde `capacitor://` y el
  history de HTML5 no resuelve rutas.
- **Toda la I/O de red pasa por `src/services/api.js`.** No instancies axios suelto ni escribas la URL
  a mano en una vista.
- El token CSRF se manda **siempre que exista**; quién lo exige lo decide `CsrfMiddleware` en
  `routes.php`. No repliques la lista de «acciones protegidas» de libraryVue: se desincroniza sola.
- El alta de usuario es de **dos pasos**: `login` con el ID token responde `needs_username` y no crea
  nada; el `username` es la URL pública futura y no se deriva del email.
- **Hay suite de tests** (`frontend/tests/`, Vitest + `@vue/test-utils`), y monta con **router real y
  PrimeVue real**, no con stubs. La frontera de mock es `@/services/api` —la misma que declara este
  fichero—, salvo `api.spec.js`, que es el único que dobla axios. Las **fixturas se capturan del
  backend de dev, nunca se escriben a mano**: `frontend/tests/fixtures/README.md` dice con qué
  comando se regeneró cada una.

### Base de datos

`docker/database/init.sql` solo se ejecuta sobre un volumen virgen. **Todo cambio posterior es una
migración** en `docker/database/migrations/`, sin excepción — libraryVue rompió esta disciplina y su
`init.sql` ya no describe su esquema real.

Tres zonas con ciclos de vida distintos (detalle en `TCGDesk/Base de Datos.md`):

| Zona | Prefijo | ¿Reconstruible? |
|---|---|---|
| Catálogo | `mtg_*` | **Sí**, con `catalog:import` (110.384 printings, ~3,5 min) y `decks:import` (3.029 precons, ~1,5 min). No se respalda |
| Precios | `mtg_price_*` | **No.** MTGJSON solo guarda 90 días; lo perdido no vuelve |
| Usuario | `users`, `mtg_collection_*`, `mtg_deck*` | **No.** Es el dato de valor |

`utf8mb4_unicode_ci` en servidor, tabla y conexión. No es opcional: los nombres de carta japoneses y
los símbolos de maná lo exigen, y una colación desalineada rompe los `JOIN` por nombre.

## Variables de entorno

`.env` (raíz, para docker-compose): `GOOGLE_CLIENT_ID`, `MYSQL_ROOT_PASSWORD`, `MYSQL_PASSWORD`,
`DB_PASSWORD`.
`backend/.env.docker-development`: lo anterior más `JWT_SECRET`, `LOG_LEVEL`, `RATE_LIMIT_*`.

**`GOOGLE_CLIENT_ID` es la única credencial externa del proyecto.** No hay claves de API porque no hay
APIs: MTGJSON se descarga sin clave y las imágenes de Scryfall se componen desde el `scryfallId`.

Los `.env` están gitignored y contienen secretos reales. Las plantillas son los `*.example`.

## Trampas que ya nos han costado tiempo

- **`docker compose restart` NO recarga el entorno.** Reutiliza el contenedor. Si cambias una variable
  en `.env`, hace falta `docker compose up -d <servicio>`, que lo recrea.
- **`firebase/php-jwt` va en `^7.0`.** Composer **rechaza** toda la rama 6.x por el aviso
  `PKSA-y2cr-5h3j-g3ys` y el contenedor entra en bucle de reinicio. Es lo que libraryVue silencia con
  `"audit": {"ignore": [...]}`; aquí se sube la versión en vez de silenciar.
- **Las middlewares devuelven `http_code`, no `code`.** `Application::run()` solo lee `http_code`; en
  libraryVue todos los 401 y 403 salen al cliente convertidos en 400 por este motivo.
- **CORS sí se usa en dev**, en `backend/public/.htaccess`, reflejando el origen si casa con
  `localhost`/`127.0.0.1`/`capacitor://localhost`. No hay proxy en `vite.config.js` y no debe haberlo:
  Capacitor no atraviesa el devServer, va directo a `10.0.2.2:8899`. En producción se sustituye por el
  dominio fijo en Nginx.
- **El healthcheck del backend usa `POST {"action":"ping"}`**, no un `GET` a `index.php`: un GET sin
  acción devuelve 400 por diseño y `curl -sf` lo trata como fallo.
- **Android necesita DOS clientes OAuth**: el de tipo *web* (el de `.env`, que valida el backend como
  `aud`) y uno de tipo *Android* con el package name y el SHA-1 del keystore. `cap sync` pasa sin el
  segundo, pero `GoogleAuth.signIn()` falla.
- **El build de Android necesita JDK 21** porque `@capacitor/android@8.3.1` fija
  `JavaVersion.VERSION_21`; con el 17 Gradle muere con `invalid source release: 21`. **Desde el
  2026-09-16 no hay que compilar a mano ni clavar `JAVA_HOME`**: `./dev-setup.sh --mobile` busca el
  JDK 21, compila, pone `adb reverse tcp:8899` —que **no persiste** entre reconexiones— e instala en
  el dispositivo. Si no encuentra JDK 21 lo dice con la lista literal de dónde buscó. **Y elige
  dispositivo sin adivinar**: el móvil de David aparece dos veces en `adb devices` (Wi-Fi y USB), así
  que con varios del mismo `model:` coge el primero diciéndolo, y con modelos distintos **no adivina**.
  `ADB_SERIAL` manda sobre todo.
- **`cv` de opencv.js es un THENABLE, y eso cuelga el proceso PARA SIEMPRE.** El `Module` de Emscripten
  define `then`, así que un `await cv` o un `resolve(cv)` meten al motor en un bucle infinito de
  microtareas: **100 % de CPU, memoria PLANA y ni el depurador interrumpe**. Costó una tarde en el
  móvil y volvió a morder en Vitest por otra puerta —`import cv from '@techstark/opencv-js'` deja el
  fichero de test a 0 bytes—. En tests se carga con `createRequire`, **nunca con `import`**, y
  `opencvListo()` resuelve con `true`, nunca con `cv`. Si algún día ves CPU al 100 % con la RSS quieta,
  es esto y no un cálculo pesado.
- **El margen de ORB se mide FUERA DEL ARTE, no sobre los inliers totales.** Dos reimpresiones de la
  misma carta **empatan clavado en la ilustración** (medido: 83 contra 85 inliers) y eso ahoga la
  señal; lo que discrimina es el cuadro de reglas y el título, porque cada marco los coloca distinto.
  Por eso `ZONA_DE_ARTE` se **excluye** del recuento. ⚠️ Y la zona que el sentido común señalaría
  —marco, borde, bloque de coleccionista— **está medida y NO sirve**: ORB pone ahí 1 o 2 inliers y un
  ranking por esa franja **corona impresiones equivocadas**. Por debajo de **4** inliers fuera del arte
  no se certifica, pase lo que pase con el cociente: es el suelo de RANSAC.
- **Por encima de 100 impresiones, ORB ni consulta ni siembra ni empareja.** `Plains` tiene **910** e
  `Island` 913, y emparejar cuesta `ms = −15,0 + 14,713·n` (R² 0,9996) → **~38 s en el Realme**. El
  tope deja fuera **7 cartas de 34.992**. El criterio **no** son los 800 ms del bucle: ORB va en un
  worker y **sin `await`**, así que no lo bloquea — lo que cuesta es **ocupar el worker**.
- **La caché `orbRefs` se indexa por `(oracleId, idioma)`, no por `oracleId`.** Con la clave corta, la
  misma carta escaneada en dos idiomas sirve las referencias cacheadas del primero y ORB empareja
  contra el arte equivocado **sin un solo error visible**.
- **`language` y `scryfallLanguage` NO son lo mismo en la respuesta de `scan_orb_refs`.** El primero es
  el idioma de los descriptores servidos; el segundo, el de la **imagen** que se manda. El cliente
  sella la fila con **el segundo**: si sellara con el idioma que pidió, guardaría descriptores ingleses
  etiquetados como españoles y el `INSERT IGNORE` bloquearía **para siempre** la siembra correcta.
  Afecta al **52 %** del catálogo, que es el que no tiene fila en español.
- **El filtro de nombre del parser acepta CJK y cirílico, y UN SOLO carácter es un nombre.** Exigía
  `/[a-zà-ÿ]/i`, así que `クローンの軍勢` no era candidato y el escáner acababa tomando por nombre la
  línea del artista. Y el mínimo de dos caracteres es correcto para un alfabeto y **falso para el
  japonés**: hay **1.630 filas** con nombre de un carácter y son las tierras básicas —山 418, 島 411,
  沼 401, 森 400—, o sea lo que más se escanea.
- **`npm install` a secas FALLA en `frontend/`.** `@codetrix-studio/capacitor-google-auth` declara un
  peer de `@capacitor/core@^6` y este repo va por el **8**, así que npm aborta por conflicto de peers.
  Se instala con `npm install --legacy-peer-deps`. La app funciona —el plugin es compatible de hecho,
  solo no ha actualizado su rango—, pero cualquiera que clone y haga `npm install` se estrella.
- **El APK filtra a `arm64-v8a`** (`android/app/build.gradle`, bloque `ndk`). Lo trajo el escáner: ML
  Kit empaqueta `libmlkit_google_ocr_pipeline.so` en cuatro ABIs y son **39,14 MiB**, de los que 28,6
  son arquitecturas que ningún móvil moderno ejecuta; sin el filtro el APK de debug pasa de 5,7 MB a
  **51,1 MB**, y con él se queda en **27,5 MB** (2026-09-16, con opencv.js dentro). La consecuencia que hay que recordar: **el APK no
  corre en un emulador x86**. En dispositivo físico, igual que siempre.
- **El frontend dev es el `8094`, no el 8099.** El 8099 lo tenía `bingoSorpresa` desde antes y no se
  detectó al reservar puertos: bingo se levanta con `npm run serve` (no con Docker), así que su
  puerto **no aparece en `ss -ltn`** salvo que esté corriendo. Al elegir puerto en este workspace, no
  basta con mirar los sockets: hay que mirar también la config del dev server de los proyectos que no
  usan contenedor —`vue.config.js` en los que siguen en Vue CLI, como el propio bingo, y
  `vite.config.js` en los que ya están en Vite—.
- **Un `.tar.gz` se lee en streaming; un `.zip` NO.** Un ZIP guarda su índice al final del fichero y
  leerlo exige `seek`, así que `AllDeckFiles.zip` (259 MB) es inservible aquí aunque sea el que la web
  de MTGJSON enseña primero. `TarGzReader` abre `compress.zlib://` y **parsea a mano las cabeceras tar
  de 512 bytes**; `PharData` tampoco sirve, porque es un Phar y necesita `seek`. Y ojo con la
  **cabecera PAX** que precede a cada fichero: sin leerle el `path`, la cabecera `ustar` trae
  **mutilado** el nombre de los 11 mazos no ASCII y se ingieren 3.018 de 3.029 **sin quejarse**.
- **`gc_collect_cycles()` por mazo en `decks:import` NO es una optimización.** json-machine deja unos
  0,6 MB de ciclos por fichero parseado: sin esa llamada el pico pasa de 13 MB a ~1,8 GB proyectados
  sobre los 3.029 mazos, y no cabe ni con `memory_limit` de 512M. Si alguien la quita, la ingesta
  muere.
- **`mtg_precon_card` no tiene FK a `mtg_printing`, y es deliberado.** Los precons y el catálogo se
  ingieren por separado; con FK, la ingesta entera fallaría porque MTGJSON publicase un `uuid` que
  nuestro `AllPrintings` aún no tiene. Hoy son **254 filas huérfanas**, que `decks:import` cuenta e
  **imprime al terminar** — ruidoso, nunca silencioso.
- **`board` en `mtg_precon_card` no lleva `companion`**, pero `mtg_deck_card` **sí**. Al copiar de una
  a otra (`ImportPreconToCollection`), el mapeo tiene que ser explícito.
- **`backend/storage/` está ignorado entero.** Ahí viven los 177 MB de `AllPrintings.json.gz` y los
  149 MB de `AllPrices.json.gz`; enumerar subcarpetas ya falló una vez. **`MtgJsonDownloader` reutiliza
  lo que encuentre**, así que un fichero viejo ahí dentro es una ingesta que parece correr y no trae
  nada nuevo: `prices:sync` fuerza la descarga siempre, `prices:seed` solo con `--force-download`.
- **La CLI se ejecuta con `-u www-data`, NUNCA como root.** Monolog crea el log del día con el owner
  de quien lanza el comando. Si lo lanza root, Apache —que es `www-data`— no puede escribir en él y
  **toda petición HTTP devuelve 500**, healthcheck incluido. Si ya pasó:
  `docker compose exec -u root backend chown -R www-data:www-data storage/`.
- **`memory_limit` real del contenedor: 128M.** La `ENV PHP_MEMORY_LIMIT=512M` del Dockerfile **no la
  lee nadie** en la imagen `php:8.2-apache`. `bin/tcgdesk` lo sube a 512M por su cuenta porque la
  ingesta hace pico de 130 MB; el streaming sigue siendo obligatorio de todos modos.
- **La búsqueda resuelve cada `MATCH` una sola vez en un CTE** y luego une por `oracle_id`. Con un
  `EXISTS (SELECT … MATCH …)` correlacionado, MySQL lo evalúa una vez por printing: medido, `bosque`
  tardaba **125 segundos** en vez de 14 ms.
- **Con `ATTR_EMULATE_PREPARES = false`, MySQL no admite reutilizar un marcador nombrado** en dos
  puntos de la misma sentencia. Hay que repetir el valor con nombres distintos.
- **Las stopwords de InnoDB dejan búsquedas en cero silencioso.** `the` está en la lista y mide 3
  caracteres, así que `+the*` no casa nada: `Jace, the Mind Sculptor` no se encontraba. La lista se
  lee de `information_schema.INNODB_FT_DEFAULT_STOPWORD`, nunca se copia a mano.
- **El japonés, el chino y el coreano necesitan el índice `ngram`** (`mtg_printing_localized.name_cjk`):
  el parser por defecto tokeniza por espacios y esos idiomas no los usan.
- **`gd` sigue en la imagen y ya no lo usa nadie.** Entró el 2026-09-15
  (`docker/backend/Dockerfile.backend.dev:8-26`) para decodificar JPEG en PHP, que era lo que
  necesitaba el hash perceptual. **El hash se borró ese mismo día** y con él `Domain/Vision/`,
  `catalog:hash`, `vision:refs`, `frontend/src/services/cardHash.js` y la tabla `mtg_printing_hash`.
  La extensión se queda porque quitarla exige reconstruir la imagen y no molesta; si alguien la ve y
  se pregunta para qué está, la respuesta es **para nada, hoy**.
- **La vía visual por hash está MEDIDA y MUERTA: no la reimplementes.** El dHash de 64 bits acertaba
  entre imágenes de Scryfall —299 de 300, 0 falsos positivos en 44.850 pares— y **no reconocía ni una
  foto real**: una toma del móvil queda a **11-12 bits** de su propia referencia contra un margen
  mediano de **6**, y sobre 8 cartas la correcta salió en los puestos **#5 a #3218**. No es cuestión
  de umbral: el error de la foto dobla la distancia mínima entre cartas distintas, y a 256 bits sube
  en la misma proporción. Las siete tandas de medidas están en el Log del «Plan - Escáner de Cartas
  por Cámara» del Brain. La vía que **sí** funciona, medida el mismo día, es **ORB** (8 de 8 en el
  puesto 1), y va en un plan aparte.
- **El resolvedor tiene SIETE pasos y dos son nuevos, y los dos sirven también a `/import`.** El
  **3c** busca el nombre en `mtg_printing_localized.name_normalized` —410.604 filas, diez idiomas—
  porque hasta el 2026-09-15 los pasos 3 y 4 solo miraban `mtg_card`, que guarda el inglés: una
  «Llanura» salía `not_found` en el escáner **y en un CSV**. El **5** resuelve por distancia de
  edición ≤ 2, que es lo que rescata una errata de un carácter del OCR («Tlanura»). Dos reglas que
  no se relajan: el 5 **solo mira los `not_found` del 4** —un empate del `FULLTEXT` no baja ahí, o
  convertiría un empate honesto en una resolución falsa— y **no busca por debajo de 6 caracteres**,
  porque el OCR lee fragmentos de borde (`Pla`, `PF`, `tab`) y a distancia 2 un trozo de tres letras
  alcanza media docena de cartas reales.
- **MTGJSON trae nombres localizados CRUZADOS, y el paso 3c los desempata por dominancia.** Medido:
  «Llanura» apunta a *Plains* con 407 filas y a *Swamp* con **4**, todas del set `INV`; «Isla» a
  *Island* con 405 y a *Plains* con 4. Sin desempate, **tres de las cinco tierras básicas españolas
  no se resolverían nunca**. La carta mayoritaria gana **solo si supera a la siguiente por ≥ 10
  veces**; de las 298 claves con más de una carta detrás, 11 son dato sucio y **136 son empates
  reales que siguen dando `ambiguous`**.
- **`printingCount === 1` NO significa «la carta tiene una sola impresión».** Significa **entre
  cuántas se eligió**, y vale 1 también cuando la lectura ya traía la impresión
  (`ResolveCards.php:141`). Por eso `ScanController::fuenteDeCerteza()` mira **primero el paso y
  después el recuento**: al revés, una *Lightning Bolt* de 130 ediciones leída por su esquina se
  reportaría como `single`. El booleano saldría igual; la fuente mentiría.
- **`scan_resolve` declara su propio límite, 300/min, y no es un afloje.** No declaraba ninguno y
  heredaba el global de `ActionRouter` (**60/min**), así que el bucle de la cámara —~2 vueltas por
  segundo— empezaba a comer **429 a los treinta segundos**. El síntoma es «el escáner deja de
  reconocer al rato» y no se parece en nada a la causa. Hay un test que lo fija.
- **El bucle del escáner se para con la pantalla apagada, y el rearranque hay que ESPERARLO.**
  `cancelado = true` no para el bucle: se lo pide, y la vuelta en curso todavía tiene que terminar su
  captura y su OCR. Rearrancar en ese hueco deja `bucleVivo` a `true`, `arrancarBucle()` vuelve sin
  hacer nada y un instante después el bucle viejo sale solo: **cámara viva y bucle muerto**, que en
  pantalla es una previsualización que se ve y no reconoce nada.
- **Los dobles de Capacitor en los tests necesitan LATENCIA.** El bucle es un `while` que encadena
  promesas; con dobles que resuelven en el mismo tick gira sin ceder el hilo y **mata un worker de
  Vitest con `SIGABRT`** tras dos minutos. Con **1 ms** en `captureSample()` y `processImage()`,
  `ScanView.spec.js` pasa en 1,16 s.
- **El paso 2b del resolvedor CEDE EL TURNO, no conflictúa, y solo actúa sin `setCode`.** Resuelve
  `(nombre, número)` sin edición —el caso de las Secret Lair, que no lo imprimen—, pero solo devuelve
  los pares con **exactamente una** impresión detrás (`HAVING COUNT(*) = 1`): un `Plains 250`, que vive
  en 14 ediciones, no sale en el mapa y sigue al paso 3 resolviéndose **exactamente como antes**. Esa
  es la regla que impide que rompa `/import`. Y su clave **solo** se construye cuando `setCode` es
  `null` o vacío: si el par `(set, número)` existía y no casó, insistir por nombre y número resolvería
  a otra impresión de la misma carta y taparía un desacuerdo que hoy sale a la luz.
- **`name_normalized` NO es `name` en minúsculas.** Conserva los blancos `_____` —si los tratas como
  puntuación, `_____ Goblin` de Unfinity colapsa a la clave `goblin` y se apropia de lo que el usuario
  teclea— y el ` // ` de las dobles cara, con reintento aparte por la cara frontal. **No hay
  `ext/intl` en el contenedor**: `Normalizer::normalize()` no existe y la transliteración es tabla
  propia en `NameNormalizer`. La columna la puebla `catalog:normalize` y **la mantiene al día
  `catalog:import`** (`MtgJsonMapper.php:137`): si tocas la ingesta, no le quites ese enganche o cada
  reimportación dejará las cartas nuevas fuera del índice, en silencio.
- **`mtg_format` tiene el MISMO enganche frágil, y va al FINAL de `catalog:import`.** Es el
  `SELECT DISTINCT format FROM mtg_legality` congelado en una tabla de 21 filas, y lo reconstruye
  `CatalogImportCommand.php:112-121` **cuando la ingesta ya terminó** — nunca al empezar, porque
  `mtg_legality` se llena durante el bucle y un `DISTINCT` al principio leería la tanda anterior, o
  nada en una instalación nueva. Si alguien reordena ese comando y se lleva el enganche por delante,
  el desplegable de formatos de `/deck/:id` se queda congelado **sin quejarse**, exactamente como
  pasó con `name_normalized`. Por eso el comando imprime `formatos N` en sus totales: una ingesta que
  diga `formatos 0` se ve. **Sin FK a `mtg_legality`**, por lo mismo que `mtg_precon_card` no la
  tiene. Y no «simplifiques» `formatosConocidos()` volviendo al `DISTINCT` directo: son **62,8 ms
  contra 0,08 ms**, y ese era el problema entero.
- **Resolver por `(set_code, collector_number)` sin comparar el nombre mete cartas equivocadas.**
  Pasó: `1 Lim-Dûl's Vault (ICE) 96` resolvía a *Shyft*, porque `ICE 96` **es** *Shyft*. Cuando la
  línea trae nombre **y** par, los dos tienen que concordar; si no, es conflicto `mismatch` con los
  dos candidatos. La comparación va por `name_normalized`, nunca literal.
- **`PlainTextParser` va SIEMPRE el último de `ParserRegistry`** (`config/container.php`): es el único
  que aceptaría casi cualquier cosa. Y su `supports()` no puede ser `return true` — exige que ≥50 % de
  las líneas útiles lleven cantidad explícita, o reclamaría CSV ajenos y prosa.

- **Un mazo NO referencia la colección: la cruza.** No hay ninguna FK de `mtg_deck_card` a
  `mtg_collection_item`, y no se te ocurra añadirla. Ese `id` es inestable —`changeGrade()` funde
  filas y borra el origen, `changeQuantity(0)` borra— así que la FK haría que **cambiar una carta de
  NM a LP vacíe el mazo en silencio**. El consumo es un `LEFT JOIN` por las cinco dimensiones con
  `is_wishlist = 0` y `board <> 'tokens'`, y repite el `user_id` con dos nombres de marcador.
- **`not_legal` no existe en `mtg_legality`: es la AUSENCIA de fila.** La columna es
  `enum('legal','not_legal','restricted','banned')` pero solo se ingieren `legal`, `banned` y
  `restricted`. Por eso todo cruce de legalidad va con **`LEFT JOIN`**: con un `JOIN` a secas, las
  cartas no legales **desaparecen de la lista** en vez de marcarse, que es el fallo contrario al que
  buscas.
- **Los `board = 'tokens'` no cuentan para nada**: ni consumen colección, ni suman al valor, ni cuentan
  para el tamaño mínimo del mazo. En PHP, `Board::esPoseible()`; en SQL, `board <> 'tokens'`.
- **Los tres estados de `mtg_deck.status` no son simétricos.** Solo `built` consume colección;
  `building` calcula lo que falta; `dismantled` es archivo. Escribir `WHERE status != 'dismantled'`
  por inercia mete los `building` en el consumo y la app avisa de conflictos que no existen.
- **El análisis de sobreasignación INFORMA, no actúa.** Nada desmonta un mazo solo. Un `UPDATE` sobre
  `mtg_deck.status` disparado por una condición calculada es un cambio que luego nadie sabe explicar.
- **Los tests del frontend corren en el HOST con nvm, no en el contenedor** — justo al revés que los
  del backend. El servicio `frontend` de `docker-compose.yml` monta por volumen solo `src/`,
  `public/` y tres ficheros de config, así que `tests/` ni siquiera está dentro. Son `npm test`,
  `npm run test:watch` y `npm run test:coverage`, y este último **exige umbrales**: 80 % de líneas y
  funciones en `src/stores/**` y `src/services/**`, 60 % en `src/views/**` (asimétrico a propósito:
  las vistas son ~6.000 líneas con mucho markup y pedirles 80 % empuja a escribir tests de adorno).
  El umbral es **agregado por carpeta**, no por fichero. Fuera de alcance, con el porqué escrito en
  `vitest.config.js`: `composables/useGoogleAuth.js` y `views/LoginView.vue` —hablan con dos SDK de
  terceros, y un test contra un doble de un SDK prueba el doble—, y `login()` de `stores/auth.js`,
  que deja ese fichero al 76,56 % **a sabiendas**: es el único endpoint no capturable (exige un ID
  token firmado por Google, `GoogleAuthClient.php:62-66`) y sin fixtura real solo quedaba
  inventársela. Eso **no se rellena con tests de adorno**.
- **Un `*Hecho cuando:*` de vista validado por JSON no valida la vista.** `/decks` se caía con
  cualquier conflicto real porque leía los alias SQL `deckId`/`deckName`/`reclamado` en vez del
  contrato (`id`/`name`/`claimed`), y **un `router-link` con un `params` obligatorio a `undefined`
  lanza** y tumba el render entero. Hoy eso lo caza `tests/unit/views/DecksView.spec.js`, pero solo
  porque la suite monta con el **router real**: `RouterLinkStub` no resuelve la ruta y el test
  habría pasado en verde. Si estubeas el router, dejas de probar lo único que importa.
- **Los overlays de PrimeVue teletransportan fuera del wrapper.** `Popover`, `Select` y `Dialog`
  cuelgan del `document.body`, no de `wrapper.html()`: hay que buscarlos ahí o el test pasa en verde
  en falso —mirar el `.p-select-label` dice cuál quedó seleccionado, no qué opciones se ofrecían—.

## Buenos comportamientos en este repo

- **Endpoint nuevo = `routes.php` + controller.** Nada más.
- **Comando CLI nuevo = `config/commands.php` + la clase.** Devuelve el código de salida correcto.
- **Ingesta nueva de MTGJSON = streaming, siempre.** `MtgJsonDownloader` baja a `storage/mtgjson/` y
  reutiliza lo ya bajado; el recorrido va con json-machine sobre `compress.zlib://`. Ningún
  `json_decode()` del fichero entero: `AllPrintings` pasa de 1 GB en claro y `AllDeckFiles` de 816 MB.
- **Acción que escribe = `CsrfMiddleware` en su pila**, siempre después de `AuthMiddleware`.
- **Cambio de esquema = migración datada.** Nunca editar `init.sql`.
- **Cambiar una columna que está dentro de un `UNIQUE KEY` MUEVE la fila, no la edita**, y el destino
  puede estar ocupado: hay que fundir sumando dentro de una transacción. **Lo que hay que copiar es
  `MySqlCollectionRepository::moveLine()`**, que desde el 2026-09-12 es el único sitio donde vive esa
  lógica —`changeGrade()` es una llamada suya—. Y **nunca** lo resuelvas desde la interfaz con un
  `remove` + un `add`: son dos peticiones y la segunda puede no llegar.
  > ⚠️ **El `SELECT ... FOR UPDATE` NO protege el hueco cuando el destino no existe.** Toma un *gap
  > lock*, y los gap locks de InnoDB son **compartidos**: dos sesiones lo obtienen a la vez y chocan
  > al insertar. Medido contra la BD de dev el 2026-09-12: `ERROR 1213 Deadlock` y la transacción
  > perdedora revertida entera. El `UNIQUE KEY` impide el duplicado, así que no se corrompe nada,
  > pero una de las dos operaciones muere. Sobre un destino que **sí existe** es bloqueo de fila y
  > serializa bien. Por eso `moveLine()` tiene tres caminos: `UPDATE` sobre el destino si está
  > ocupado; `UPDATE` en el sitio si está libre y el movimiento es **total** (así la fila conserva su
  > `id`, que es contrato con la vista y lo afirma `ChangeItemGradeTest.php:44`); e
  > `INSERT ... ON DUPLICATE KEY UPDATE` si está libre y el movimiento es **parcial**, porque el
  > `ODKU` toma bloqueo exclusivo desde el principio. **`ChangeDeckCardIdentity` sigue con el patrón
  > viejo y conserva el agujero**: dos movimientos totales simultáneos al mismo destino libre dan
  > deadlock. Se dejó así a sabiendas.
- **Formato de importación nuevo = una clase que extiende `CsvCollectionParser` + registro en
  `container.php`.** Los tres CSV miden ~70 líneas cada uno porque el lector común vive en la base; si
  el tuyo se va a 300, lo estás duplicando. Cada `supports()` debe reclamar **solo** su formato: hay
  una matriz en `ParserRegistryTest` que exige la diagonal.
- **Resolución e importación van en lote**, nunca una consulta por fila: 20.000 líneas son una
  petición, no 20.000.
- **Depende de interfaces de repositorio**; registra las nuevas en `config/container.php`.
- **PHPUnit dentro del contenedor**; `failOnWarning` y `failOnRisky` están activos.
- **Trabaja en la rama `dev`.**

## Verificación end-to-end

1. `./dev-setup.sh` → tres contenedores, sin warnings.
2. `curl -X POST localhost:8899/index.php -d '{"action":"ping"}'` → `status: success`.
3. `docker compose exec backend php bin/tcgdesk hello` → código de salida `0`.
4. `http://localhost:8094` → entrar con Google y llegar a `/`.
5. `docker compose exec backend composer test` → verde.
6. `cd frontend && npm run test:coverage` **en el host** → verde y **código de salida `0`** (es el
   código lo que dice si los umbrales pasan, no el texto del informe).
