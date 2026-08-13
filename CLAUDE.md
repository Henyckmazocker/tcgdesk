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

Stack: **Docker · PHP 8.2 (clean architecture / hexagonal, PHP-DI 7) · MySQL 8 · Vue 3.5 (Vue CLI) +
Pinia + PrimeVue 4 · Capacitor 8 (Android)**.

Deriva del patrón compartido con [libraryVue], statCoin, trackit, spoticlone y galleryVue: endpoint
único + router de acciones + middleware declarativo. **Con tres divergencias deliberadas** — ver
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

# URLs (dev)
#   Frontend  http://localhost:8094
#   Backend   http://localhost:8899/index.php   (endpoint ÚNICO; la acción va en el body)
#   MySQL     localhost:3312  (db tcgdesk_db, user tcgdesk_user)

# Tests — SIEMPRE dentro del contenedor
docker compose exec backend composer test

# Capa CLI
docker compose exec backend php bin/tcgdesk           # lista comandos
docker compose exec backend php bin/tcgdesk hello

# Ingesta — SIEMPRE con -u www-data (ver trampas)
docker compose exec -u www-data backend php bin/tcgdesk catalog:import   # ~3,5 min
docker compose exec -u www-data backend php bin/tcgdesk prices:sync      # ~18 s, diario
docker compose exec -u www-data backend php bin/tcgdesk prices:seed      # 90 días, una vez

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
```

**Se pagina por cursor, nunca por offset**: `LIMIT 60 OFFSET 50000` obliga a MySQL a recorrer y tirar
50.000 filas en cada tirón del scroll. El cursor es opaco y solo el cliente lo transporta.

**Cualquier escritura sigue siendo una acción `POST`.** Esta divergencia no se extiende.

### Frontend (Vue 3 + PrimeVue)

- `createWebHashHistory`, no history mode: con Capacitor la app se sirve desde `capacitor://` y el
  history de HTML5 no resuelve rutas.
- **Toda la I/O de red pasa por `src/services/api.js`.** No instancies axios suelto ni escribas la URL
  a mano en una vista.
- El token CSRF se manda **siempre que exista**; quién lo exige lo decide `CsrfMiddleware` en
  `routes.php`. No repliques la lista de «acciones protegidas» de libraryVue: se desincroniza sola.
- El alta de usuario es de **dos pasos**: `login` con el ID token responde `needs_username` y no crea
  nada; el `username` es la URL pública futura y no se deriva del email.

### Base de datos

`docker/database/init.sql` solo se ejecuta sobre un volumen virgen. **Todo cambio posterior es una
migración** en `docker/database/migrations/`, sin excepción — libraryVue rompió esta disciplina y su
`init.sql` ya no describe su esquema real.

Tres zonas con ciclos de vida distintos (detalle en `TCGDesk/Base de Datos.md`):

| Zona | Prefijo | ¿Reconstruible? |
|---|---|---|
| Catálogo | `mtg_*` | **Sí**, con `catalog:import` (110.384 printings, ~3,5 min). No se respalda |
| Precios | `mtg_price_*` | **No.** MTGJSON solo guarda 90 días; lo perdido no vuelve |
| Usuario | `users`, `mtg_collection_*` | **No.** Es el dato de valor |

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
  `localhost`/`127.0.0.1`/`capacitor://localhost`. No hay proxy en `vue.config.js` y no debe haberlo:
  Capacitor no atraviesa el devServer, va directo a `10.0.2.2:8899`. En producción se sustituye por el
  dominio fijo en Nginx.
- **El healthcheck del backend usa `POST {"action":"ping"}`**, no un `GET` a `index.php`: un GET sin
  acción devuelve 400 por diseño y `curl -sf` lo trata como fallo.
- **Android necesita DOS clientes OAuth**: el de tipo *web* (el de `.env`, que valida el backend como
  `aud`) y uno de tipo *Android* con el package name y el SHA-1 del keystore. `cap sync` pasa sin el
  segundo, pero `GoogleAuth.signIn()` falla.
- **El frontend dev es el `8094`, no el 8099.** El 8099 lo tenía `bingoSorpresa` desde antes y no se
  detectó al reservar puertos: bingo se levanta con `npm run serve` (no con Docker), así que su
  puerto **no aparece en `ss -ltn`** salvo que esté corriendo. Al elegir puerto en este workspace, no
  basta con mirar los sockets: hay que mirar también los `vue.config.js` de los proyectos que no usan
  contenedor.
- **`backend/storage/` está ignorado entero.** Ahí viven los 177 MB de `AllPrintings.json.gz` y los
  149 MB de `AllPrices.json.gz`; enumerar subcarpetas ya falló una vez.
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

## Buenos comportamientos en este repo

- **Endpoint nuevo = `routes.php` + controller.** Nada más.
- **Comando CLI nuevo = `config/commands.php` + la clase.** Devuelve el código de salida correcto.
- **Cambio de esquema = migración datada.** Nunca editar `init.sql`.
- **Depende de interfaces de repositorio**; registra las nuevas en `config/container.php`.
- **PHPUnit dentro del contenedor**; `failOnWarning` y `failOnRisky` están activos.
- **Trabaja en la rama `dev`.**

## Verificación end-to-end

1. `./dev-setup.sh` → tres contenedores, sin warnings.
2. `curl -X POST localhost:8899/index.php -d '{"action":"ping"}'` → `status: success`.
3. `docker compose exec backend php bin/tcgdesk hello` → código de salida `0`.
4. `http://localhost:8094` → entrar con Google y llegar a `/`.
5. `docker compose exec backend composer test` → verde.
