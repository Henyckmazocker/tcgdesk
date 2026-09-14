# Fixtures — respuestas capturadas del backend real

**Estas fixtures NO se escriben a mano.** Son la respuesta literal del backend de dev, guardada
tal cual (sobre `{status, message, data, http_code}` incluido) y pasada por `jq .` para que se
lea. El bug que motivó la suite de tests nació de alguien que miró el SQL en vez del contrato:
una fixture redactada a mano traería el mismo error y el test certificaría el fallo.

Si una fixture se queda desfasada, **se vuelve a capturar**, no se edita.

---

## Cómo autenticarse para capturar (lo que cuesta)

Las **5 rutas GET** del catálogo no necesitan sesión (`backend/public/index.php:33-42` las desvía
a `CatalogHttpRouter` antes de construir `Application`): son `curl` directo.

Las **~21 acciones POST** pasan por `AuthMiddleware`, y `login` exige un ID token de Google real
que no se puede fabricar. La salida es **forjar una sesión de PHP en el contenedor**: el
middleware solo mira `$_SESSION['user_data']['id']` (`AuthMiddleware.php:28-32`) y `CsrfMiddleware`
solo compara con `$_SESSION['csrf_token']`.

```bash
cd /home/david/Documents/workspace/tcgdesk

# 1. Forjar la sesión (ojo: SIEMPRE -u www-data, o Monolog deja el log del día
#    con owner root y toda petición HTTP pasa a devolver 500)
docker compose exec -T -u www-data backend php -r '
session_name("TCGDESK_SESSION");
session_id("m2fixturessid0000000000000000dev");
session_start();
$_SESSION["user_data"]  = ["id" => 1, "username" => "Henyckma"];
$_SESSION["csrf_token"] = "m2fixturecsrf0000000000000000000000000000000000000000000000dev00";
session_write_close();'
```

`session.use_strict_mode` está a `1`, así que el fichero de sesión tiene que existir **antes** de
mandar la cookie; por eso se crea con `session_start()` y no a mano.

```bash
# 2. Los dos ayudantes que usan todos los comandos de abajo
SID=m2fixturessid0000000000000000dev
CSRF=m2fixturecsrf0000000000000000000000000000000000000000000000dev00

post() { curl -s -X POST http://localhost:8899/index.php \
           -H 'Content-Type: application/json' -b "TCGDESK_SESSION=$SID" -d "$1"; }
get()  { curl -s "http://localhost:8899/api/catalog$1"; }
```

Las sesiones forjadas **se borran al terminar** (`docker compose exec backend rm -f /tmp/sess_m2*`).

---

## Estado de la BD del que salieron (2026-09-12)

Catálogo completo (110.384 printings, 868 ediciones, 3.029 precons, 155.627 precios), usuario 1
con 185 líneas de colección y **dos mazos `built`**: `17` «Tom bombadil» y `21` «Food and
Fellowship».

**El conflicto de sobreasignación se provocó a propósito**, y no inventando mazos sino quitando
una copia: `Arcane Signet` (`99443bab-…`, item `6399`) estaba x2 en la colección y lo reclaman los
dos mazos construidos con 1 cada uno. Bajarlo a x1 deja `claimed: 2` contra `inCollection: 1`.
Esa misma llamada es la fixture `collection_update_quantity.json`:

```bash
post "{\"action\":\"collection_update_quantity\",\"item_id\":6399,\"quantity\":1,\"csrf_token\":\"$CSRF\"}"
```

Las escrituras que ensuciarían la colección de David (`precon_add_to_collection`, `import_apply`,
las altas y bajas de colección y los mazos de usar y tirar) se capturaron con un **usuario de usar
y tirar** creado por SQL y **borrado al terminar** (las FK de `mtg_collection_item` y `mtg_deck`
van con `ON DELETE CASCADE`, así que no queda rastro):

```sql
INSERT INTO users (google_id, email, username, display_name, avatar_url)
VALUES ('m2-fixtures-throwaway-google-id', 'fixtures@example.invalid', 'fixturas',
        'Usuaria de Fixturas', 'https://example.invalid/avatar.png');
-- … capturar … --
DELETE FROM users WHERE id = <id>;   -- CASCADE se lleva colección y mazos
```

---

## Las 5 rutas `GET` (`catalogGet`, sin sesión)

| Fichero | Ruta | Comando |
|---|---|---|
| `catalog_cards.json` | `/cards` | `get '/cards?sort=relevance&limit=60'` |
| `catalog_card.json` | `/cards/{uuid}` | `get '/cards/a4649be8-4284-5371-831f-2e3ee32ec988'` |
| `catalog_sets.json` | `/sets` | `get '/sets'` |
| `catalog_decks.json` | `/decks` | `get '/decks?playable=1&limit=60'` |
| `catalog_deck.json` | `/decks/{fileName}` | `get '/decks/RidersOfRohan_LTC'` |

- `catalog_cards.json` es **la primera llamada literal de `CatalogView`**: `stores/catalog.js:68`
  manda `{...filtros, limit: 60}` y `catalogGet` descarta las claves vacías (`api.js:128-130`),
  así que de los filtros por defecto solo sobrevive `sort: 'relevance'`. Consecuencia que conviene
  saber: esa página sale entera de los sets más nuevos (`TRK`/`TRC`) y **todos sus `priceEur`
  vienen a `null`**. Para el camino de precios está `catalog_card.json`, que sí trae
  `priceEur`, 178 puntos de `priceHistory`, 7 legalidades y 6 nombres localizados.
- `catalog_card.json` es un `Sol Ring` de LTC a propósito: es la carta del conflicto y la que usan
  varias fixtures de mazo, así que los tests pueden cruzarlas.
- `catalog_deck.json` es un precon de 100 cartas con zonas `commander` y `main`. El primer
  candidato (`AragornAtHelmSDeep_LTC`) se descartó: es un *Box Set* de 6 cartas y no ejercita nada.
- `catalog_decks.json` trae también `deckTypes` y `sets`, que solo viajan en la primera página
  (`stores/precons.js:145-153`), y el `nextCursor` opaco.

---

## Las 6 rutas `GET` públicas (`publicGet`, sin cookie) — 2026-09-13

El perfil público y el mazo compartido, capturados contra el backend de dev con el usuario real
`Henyckma` (`users.id = 1`). Son `curl` directo: no necesitan sesión, y ese es el punto entero.

```bash
pub() { curl -s "localhost:8899/api/public$1" | jq .; }
```

| Fichero | Ruta | Comando |
|---|---|---|
| `public_profile.json` | `/user/{username}` | `pub /user/Henyckma` |
| `public_collection.json` | `…/collection` | `pub /user/Henyckma/collection` |
| `public_decks.json` | `…/decks` | `pub /user/Henyckma/decks` |
| `public_sets.json` | `…/sets` | `pub /user/Henyckma/sets` |
| `public_not_visible.json` | `…/wishlist` | `pub /user/Henyckma/wishlist` → **403** |
| `public_user_not_found.json` | `/user/{username}` | `pub /user/NoExisteEsteUsuario` → **404** |
| `public_deck_not_found.json` | `/deck/{token}` | `pub /deck/deadbeef` → **404** |

**`public_profile.json` es el perfil por DEFECTO**, y por eso es la fixtura importante: con
`user_privacy_settings` a 0 filas manda `Seccion::nivelPorDefecto()`, así que `value` y `wishlist`
nacen en `friends` —inerte hasta el Plan - Amigos— y para un anónimo vienen a `false`. Es el caso
que ejercita el estado vacío honesto, y de ahí sale el 403 de `public_not_visible.json`.

**Ni un `email` en ninguna de las siete.** El backend compone estas respuestas por lista blanca, y
`public_collection.json` no trae `priceEur` porque `visible.value` es `false`: no es un filtro que
recorte, es que el campo no se añade.

### Las dos que exigieron tocar la BD (y se restauró)

`public_profile_todo.json` y `public_collection_valor.json` son las mismas dos rutas con las cinco
secciones a `everyone`, que es lo que hace aparecer el bloque `value` del perfil y el `priceEur` /
`lineValue` de cada línea. Hubo que escribir la fila y **borrarla después**: la tabla vuelve a 0.

```bash
# Escribir, capturar y BORRAR. La tabla tiene que quedarse en 0 filas.
docker compose exec -T mysql mysql -u root -p"$MYSQL_ROOT_PASSWORD" tcgdesk_db -e "
  INSERT INTO user_privacy_settings (user_id, show_collection, show_value, show_decks,
                                     show_sets, show_wishlist)
  VALUES (1,'everyone','everyone','everyone','everyone','everyone');"
pub /user/Henyckma            > public_profile_todo.json
pub /user/Henyckma/collection > public_collection_valor.json
docker compose exec -T mysql mysql -u root -p"$MYSQL_ROOT_PASSWORD" tcgdesk_db -e "
  DELETE FROM user_privacy_settings WHERE user_id = 1;"
```

`public_deck.json` es lo mismo con `mtg_deck.share_token`, que **las cuatro filas tienen a NULL** y
así se quedaron: se le puso un token de 32 bytes al mazo 17 —«Tom bombadil», un Commander de 100
cartas con `main` y `planes`—, se capturó y se devolvió a `NULL`.

```bash
TOKEN=$(python3 -c "import secrets; print(secrets.token_hex(32))")
docker compose exec -T mysql mysql -u root -p"$MYSQL_ROOT_PASSWORD" tcgdesk_db -e "
  UPDATE mtg_deck SET share_token = '$TOKEN' WHERE id = 17;"
pub /deck/$TOKEN > public_deck.json
docker compose exec -T mysql mysql -u root -p"$MYSQL_ROOT_PASSWORD" tcgdesk_db -e "
  UPDATE mtg_deck SET share_token = NULL WHERE id = 17;"
```

Lo que esa fixtura prueba de un vistazo, y por lo que se eligió ese mazo: **96 líneas, 100
ejemplares y 160,18 €, y ni un campo del cruce con la colección** —`missingCount`, `conflicts`,
`availability`, `claimed`, `notes`— porque compartir un mazo no comparte tu inventario. El recuento
por zona son 99 copias en 95 líneas, así que una vista que sumara líneas en vez de copias se
delata.

---

## Las acciones `POST` (`apiCall`, con la sesión forjada)

### Sesión

| Fichero | Comando |
|---|---|
| `check_auth.json` | `post '{"action":"check_auth"}'` |
| `logout.json` | `post '{"action":"logout","csrf_token":"…"}'` — con una sesión forjada **aparte**, porque la destruye |
| `login.json` | ❌ **NO CAPTURADA** — ver abajo |

### Colección

| Fichero | Comando |
|---|---|
| `collection_list.json` | `post '{"action":"collection_list","sort":"price_desc","limit":60}'` |
| `collection_value.json` | `post '{"action":"collection_value"}'` |
| `collection_sets.json` | `post '{"action":"collection_sets"}'` |
| `collection_add.json` | `post '{"action":"collection_add","printing_uuid":"22cadebf-…","quantity":2,"finish":"normal","language":"English","condition":"NM","csrf_token":"…"}'` |
| `collection_update_quantity.json` | `post '{"action":"collection_update_quantity","item_id":6399,"quantity":1,"csrf_token":"…"}'` |
| `collection_change_grade.json` | `post '{"action":"collection_change_grade","item_id":<el de add>,"condition":"LP","csrf_token":"…"}'` |
| `collection_remove.json` | `post '{"action":"collection_remove","item_id":<el de add>,"csrf_token":"…"}'` |

`collection_list` va con `sort: 'price_desc'` y `limit: 60` porque es lo que manda
`stores/collection.js:101` con los filtros por defecto (`filtrosVacios()`, `:23-35`).

Las tres de alta/cambio/baja son la misma línea de `Jin-Gitaxias // The Great Synthesis`
(`22cadebf-…`, 8,49 €) recorrida entera: se añade, se le cambia el estado a `LP` y se borra. Se
eligió una carta **con precio** a propósito: el primer intento usó un printing sin precio y
`lineValue` salía a 0, que no ejercita nada.

`collection_remove.json` devuelve `data: null`. No es un fallo de captura: es el contrato.

### Lista de deseos

| Fichero | Comando |
|---|---|
| `collection_add_wishlist.json` | `post '{"action":"collection_add","printing_uuid":"a50dbd25-…","is_wishlist":true,"csrf_token":"…"}'` |
| `collection_list_wishlist.json` | `post '{"action":"collection_list","is_wishlist":true,"sort":"price_desc","limit":60}'` |
| `collection_fulfill_wish.json` | `post '{"action":"collection_fulfill_wish","item_id":6882,"quantity":1,"csrf_token":"…"}'` |
| `collection_fulfill_wish_total.json` | `post '{"action":"collection_fulfill_wish","item_id":6884,"csrf_token":"…"}'` |

Capturadas el **2026-09-12** con la sesión forjada de un **usuario de usar y tirar** (`9003`,
`fixturasm3`) **borrado al terminar**, porque David no tiene ninguna lista de deseos y llenársela
para una fixtura sería exactamente ensuciar la colección que el resto de esta carpeta evita.
Comprobado al acabar: `mtg_collection_item` vuelve a sus 185 filas del usuario 1.

`collection_add_wishlist.json` es **la llamada literal del corazón** (`stores/wishlist.js`,
`desear()`): solo `printing_uuid` + `is_wishlist`, sin las cuatro dimensiones, porque las pone el
backend. Vuelve con **`isWishlist: true`** — el flag del **contrato** es camelCase
(`MySqlCollectionRepository.php:46`); el del *payload de entrada* es `is_wishlist`. Confundirlos
deja la vista leyendo `undefined`, así que hay un test que lo fija.

`collection_list_wishlist.json` trae **4 deseos** ordenados por precio, y entre ellos la carta que
acababa de meter `collection_add_wishlist.json` (`a50dbd25-…`, *Starfield of Nyx*): eso es lo que
permite a `WishlistView.spec.js` probar el ciclo **corazón → `/wishlist`** sin inventarse el enlace
entre las dos. Uno de los deseos es de **4 ejemplares** (*Jin-Gitaxias*, item `6882`) a propósito:
es el único que ejercita el **movimiento parcial**, que es el caso nuevo de todo el plan.

Las dos de cumplir son **las dos formas de la respuesta**, y las dos hacen falta porque la vista
decide con ellas si quita la línea o solo le baja el número:

- `collection_fulfill_wish.json` — **parcial, destino libre**: se querían 4 y se cumple 1, así que
  `origen` es la línea de deseo **tal como quedó** (`quantity: 3`, `isWishlist: true`) y `merged`
  es `false`. El `item` es una línea de colección **nueva**, con otro `id`.
- `collection_fulfill_wish_total.json` — **total, con fusión**: se quería 1 de algo que ya se tenía
  x2, así que `origen` es **`null`** —el deseo se agotó y el backend lo borró— y `merged` es `true`
  sobre la línea de colección que ya existía, ahora con 3.

El **422** no se guarda como fixtura, igual que el resto de errores de esta suite: se construye en
el `.spec.js`. Se comprobó contra el backend de dev que la acción lo devuelve sobre una línea que
no es un deseo (`"Esa línea ya está en tu colección: no hay ningún deseo que cumplir."`).

#### El corazón relleno (M6)

| Fichero | Comando |
|---|---|
| `collection_wished_uuids.json` | `post '{"action":"collection_wished_uuids"}'` |

**Sin un solo campo en el payload, y sin `csrf_token`**: la acción no tiene ninguno —la única
entrada es el `user_id` que pone `AuthMiddleware`— y es una **lectura**, así que su ruta no lleva
`CsrfMiddleware` (`config/routes.php`, misma pila que `collection_list`). Comprobado al capturarla:
el mismo `curl` sin cookie devuelve **401**, y con cookie y sin token CSRF devuelve **200**.

Capturada el **2026-09-12** con la sesión forjada de otro **usuario de usar y tirar** (`9006`,
`fixturasm6`) **borrado al terminar** (`DELETE FROM users WHERE id = 9006`, que arrastra sus
líneas por `ON DELETE CASCADE`).

La lista de ese usuario se sembró por `INSERT` directo con **las mismas cuatro impresiones de
`collection_list_wishlist.json`**, y eso es lo que da valor a la fixtura: una de ellas es el **Sol
Ring de `catalog_card.json`** (`a4649be8-…`), así que el test puede comprobar que la carta que está
en `/wishlist` sale con el corazón **relleno** en la ficha sin inventarse el enlace entre fixturas.

Y se sembraron **seis líneas para cuatro uuids**, a propósito:

- **dos** del mismo Sol Ring (`normal`/`English`/`NM` y `foil`/`Japanese`/`LP`), que es lo que
  ejercita el `SELECT DISTINCT`: el corazón habla de la **impresión**, no de la línea, y la fixtura
  vuelve con **cuatro** uuids y no con cinco;
- **una** con `is_wishlist = 0` (`Stomping Ground`, `00cf70ec-…`) que **no sale**: una carta que ya
  tienes no puede pintarse como deseada. Ese mismo uuid es el que los tests usan como «la que no
  está en la lista», y está en `catalog_cards.json`.

#### El valor de la lista y su progreso por edición (M4)

| Fichero | Comando |
|---|---|
| `collection_value_wishlist.json` | `post '{"action":"collection_value","is_wishlist":true}'` |
| `collection_sets_wishlist.json` | `post '{"action":"collection_sets","is_wishlist":true}'` |

Son **las mismas dos acciones** de `collection_value.json` y `collection_sets.json` con la única
diferencia que importa: la bandera. El payload de entrada va en snake_case (`is_wishlist`) y el
contrato de vuelta en camelCase (`isWishlist`) — es la misma trampa que fija
`collection_add_wishlist.json`, y por eso hay un test que comprueba que los `topCards` de esta
fixtura vienen todos con `isWishlist: true`.

Capturadas el **2026-09-12** con la sesión forjada de otro **usuario de usar y tirar** (`9004`,
`fixturasm4`) **borrado al terminar**: `mtg_collection_item` vuelve a sus 185 filas del usuario 1 y
a cero filas con `is_wishlist = 1`. La lista se sembró por `INSERT` directo sobre ese usuario
—el corazón de uno en uno por HTTP habrían sido 18 peticiones para el mismo estado—: son
**18 líneas / 26 ejemplares** en cinco
ediciones (CMM, LTR, APC, WOC, MOM) y cuatro rarezas, con **una sin precio** a propósito —para que
`itemsWithoutPrice` valga 1 y no 0— y **17 con precio**, que es lo que hace que el `topCards` de 10
esté de verdad recortado: con diez o menos, el `array_slice` no se ejercitaría.

**El total está cuadrado contra SQL, y ahí está el valor de esta fixtura.** Comprobar que la vista
pinta lo que trae el JSON solo prueba que la fixtura es la fixtura; el número se contrastó a mano
contra la misma suma hecha en la BD:

```sql
SELECT ROUND(SUM(ci.quantity * pc.price_eur), 2)
  FROM mtg_collection_item ci
  LEFT JOIN mtg_price_current pc
        ON pc.printing_uuid = ci.printing_uuid AND pc.finish = ci.finish
 WHERE ci.user_id = 9004 AND ci.is_wishlist = 1;   -- 743.31
```

MySQL dio **743,31 €** y `collection_value` devolvió **743.31**. Ese es el número que
`WishlistView.spec.js` exige ver en pantalla, y está escrito a mano en el spec para que cambiar la
fixtura sin rehacer la comprobación ponga el test en rojo.

`collection_sets_wishlist.json` trae **`percent` en las cinco ediciones**, y no es un descuido de
captura: `collection_sets` divide igual sin saber de qué conjunto habla. Es justo lo que hace que
el test del hito pruebe algo — si el backend no mandara porcentajes, comprobar que `/sets` no los
pinta en modo deseos sería comprobar el vacío.

### Mazos

| Fichero | Comando |
|---|---|
| `deck_list.json` | `post '{"action":"deck_list"}'` |
| `deck_get.json` | `post '{"action":"deck_get","deck_id":17}'` |
| `deck_get_faltantes.json` | `post '{"action":"deck_get","deck_id":9005}'` — con el usuario de usar y tirar `9005` (ver abajo) |
| `deck_card_variants.json` | `post '{"action":"deck_card_variants","deck_id":17,"card_id":1335}'` |
| `deck_create.json` | `post '{"action":"deck_create","name":"Mazo de pruebas","format":"commander","status":"building","csrf_token":"…"}'` |
| `deck_update.json` | `post '{"action":"deck_update","deck_id":<nuevo>,"name":"Mazo de pruebas (renombrado)","format":"modern","notes":"Notas del mazo","csrf_token":"…"}'` |
| `deck_card_add.json` | `post '{"action":"deck_card_add","deck_id":<nuevo>,"printing_uuid":"a4649be8-…","count":2,"finish":"normal","language":"English","condition":"NM","board":"main","csrf_token":"…"}'` |
| `deck_card_set.json` | `post '{"action":"deck_card_set","deck_id":<nuevo>,"card_id":<el de add>,"count":3,"csrf_token":"…"}'` |
| `deck_card_change.json` | `post '{"action":"deck_card_change","deck_id":<nuevo>,"card_id":<el de add>,"finish":"foil","language":"English","condition_grade":"LP","board":"sideboard","csrf_token":"…"}'` |
| `deck_card_remove.json` | `post '{"action":"deck_card_remove","deck_id":<nuevo>,"card_id":<el de add>,"csrf_token":"…"}'` |
| `deck_delete.json` | `post '{"action":"deck_delete","deck_id":<nuevo>,"with_cards":false,"csrf_token":"…"}'` |

**`deck_get.json` es la fixture crítica de todo el plan.** Es el mazo 17 y trae
`conflicts.length === 1`, con `conflicts[0].decks` = `[{id: 21, …}, {id: 17, …}]`. Las claves son
`id` / `name` / `claimed` —el **contrato**, mapeado en `MySqlDeckRepository.php:1001-1003`—, **no**
los alias del SQL `deckId` / `deckName` / `reclamado` de `:976-978`, que es exactamente lo que
tumbaba `/decks`. La prueba del fallo histórico consiste en cambiar `id` por `deckId` aquí dentro
y comprobar que `DecksView.spec.js` se pone en rojo.

> **Su bloque `legality` se recapturó el 2026-09-12** (el `formatosDisponibles` de los 21 formatos
> que estrena el desplegable), y **solo ese bloque**: el `deck_get` entero de hoy vuelve con
> `conflicts: []`, porque el conflicto de la fixture se provocó bajando a 1 la cantidad de
> `Arcane Signet` y esa copia ya está repuesta en la colección de David. Volver a provocarlo sería
> escribir en su base de datos. El bloque pegado es **literal** el que devolvió el backend, no una
> lista escrita a mano —los formatos salen de `mtg_format`, que puebla `catalog:import`—.
>
> `deck_get_faltantes.json` se queda **sin** `formatosDisponibles`: su usuario `9005` está borrado
> y no se puede recapturar sin crear otro. No hace falta: `stores/deck.js` funde lo que llega sobre
> `LEGALIDAD_VACIA`, así que a la vista le llega la lista vacía en vez de `undefined` — y esa
> fixture no prueba el desplegable, prueba el botón de deseos.

**`deck_list.json` trae los dos mazos en `status: 'built'`**, y eso también es condición de
cierre: `cargarConflictos()` busca el primer construido y **se rinde devolviendo `conflicts: []`
si no encuentra ninguno** (`stores/deck.js:276-281`) sin llegar a llamar a `deck_get`. Con una
lista sin ningún `built`, el test de regresión pasaría en verde sin haber probado nada. El primer
`built` de la lista es el `17`, que es justo el mazo de `deck_get.json`: las dos fixtures encajan.

**`deck_get_faltantes.json` es la fixture del botón «mandar lo que falta a deseos»**, y existe
porque `deck_get.json` viene con `missing: 0`: el mazo 17 de David está completo y con él ese
botón no llega a pintarse. Se capturó sobre un mazo `built` de un usuario desechable (`9005`,
borrado al terminar) montado **a propósito** para que ninguna de sus cuatro dimensiones sea la de
por defecto (`normal` / `English` / `NM`):

| Línea | `finish` | `language` | `condition` | Por qué |
|---|---|---|---|---|
| `Arcane Signet` (SLD) | `etched` | `Spanish` | `EX` | las tres cambiadas a la vez |
| `Lightning Bolt` (2X2) | `normal` | `Japanese` | `LP` | idioma y estado |
| `Sol Ring` (LTC) | `foil` | `English` | `NM` | **el acabado**, que es el caso del plan |

Trae `missing: 3` en tres líneas de `availability`, y dos cosas más que la hacen valer:

- **La cuarta línea del mazo es un `board: "tokens"` que el usuario tampoco tiene, y NO sale en
  `availability`**: `faltantes()` filtra `board <> 'tokens'` (`MySqlDeckRepository.php:660`). Es
  lo que permite comprobar que un token nunca se manda a deseos.
- **El usuario tenía ese mismo `Sol Ring` en `normal`** y la línea `foil` sale igualmente con
  `inCollection: 0`. Ese señuelo es la demostración medida de que tener la versión equivocada no
  cierra el hueco — y por tanto de que un deseo con los valores por defecto tampoco lo cerraría.

`deck_card_change.json` enseña una trampa del contrato que conviene no olvidar: se manda
`board: "sideboard"` y vuelve `board: "side"`. Y **`deck_card_variants` no ofrece otros printings**,
solo otras combinaciones de acabado / idioma / estado del mismo printing; por eso la fixture se
capturó sobre `Jin-Gitaxias` (`22cadebf-…`), la única carta de la colección con dos variantes
—`normal` libre y `foil` reclamada—, y no sobre una con una sola.

#### El enlace público del mazo (M6)

| Fichero | Comando |
|---|---|
| `deck_share.json` | `post '{"action":"deck_share","deck_id":17,"csrf_token":"…"}'` |
| `deck_unshare.json` | `post '{"action":"deck_unshare","deck_id":17,"csrf_token":"…"}'` |

Capturadas el **2026-09-13** con la sesión forjada del usuario 1 sobre el mazo `17`, y
**restaurando**: las cuatro `mtg_deck.share_token` vuelven a `NULL`.

**Solo `deck_id` viaja en las dos.** El token lo genera el backend con `random_bytes(32)` y
`deck_unshare` revoca el que haya sin preguntar cuál es: pedirlo obligaría a la interfaz a
conocerlo para poder matarlo, que es lo contrario de lo que hace falta cuando el enlace se te fue
de las manos.

**La `url` que vuelve es RELATIVA** (`/#/shared/deck/<token>`, `ShareDeck::RUTA_PUBLICA`), y eso
es contrato, no un descuido de captura: el backend no sabe en qué origen vive el frontend y lo
único que tendría para adivinarlo es la cabecera `Host`, que la manda el cliente. La absoluta la
compone `stores/deck.js` con `window.location.origin`.

Y lo que se comprobó al capturarlas, que es lo que ningún test del frontend puede comprobar: con
el token en pie, `curl localhost:8899/api/public/deck/<token>` → **200**; después del
`deck_unshare`, el **mismo** token → **404**. El enlace muere de verdad.

#### La privacidad (M6)

| Fichero | Comando |
|---|---|
| `privacy_get.json` | `post '{"action":"privacy_get"}'` |
| `privacy_set.json` | `post '{"action":"privacy_set","value":"nobody","csrf_token":"…"}'` |

Capturadas el **2026-09-13** con la sesión forjada del usuario 1 y **restaurando**:
`user_privacy_settings` vuelve a **0 filas**.

`privacy_get.json` es **la privacidad de quien no ha tocado nada**, que hoy es todo el mundo: sin
fila manda `Seccion::nivelPorDefecto()` y vuelven los cinco defectos —`collection`/`decks`/`sets`
en `everyone`, y **`value` y `wishlist` en `friends`**, que es información patrimonial y lo que
alguien cruzaría para regatearte—. Las claves son las **de sección** (`collection`, `value`…) y
nunca los nombres de columna (`show_value`): el prefijo `show_` es cosa del esquema.

`privacy_set.json` es **la edición parcial** y por eso se capturó cambiando **una sola** sección:
vuelven las cinco tal como quedaron, con `value` en `nobody` y **las otras cuatro intactas**. Esa
respuesta es lo que el panel repinta sin recomponer nada.

El **422** no se guarda como fixtura, igual que el resto de errores de esta suite: se construye en
el `.spec.js`. Comprobado contra el backend de dev en el M2 que lo devuelven un nivel inventado y
una petición sin ninguna sección.

### Amigos y seguimiento (M4 del Plan - Amigos y Seguimiento)

| Fichero | Comando |
|---|---|
| `friend_list.json` | `post '{"action":"friend_list"}'` — usuario `9101`, con una de cada |
| `friend_list_vacio.json` | `post '{"action":"friend_list"}'` — usuario `9106`, sin ninguna relación |
| `follow_list.json` | `post '{"action":"follow_list"}'` — usuario `9101` |
| `follow_list_vacio.json` | `post '{"action":"follow_list"}'` — usuario `9106` |
| `friend_accept.json` | `post '{"action":"friend_accept","friendship_id":7,"csrf_token":"…"}'` |
| `friend_reject.json` | `post '{"action":"friend_reject","friendship_id":9,"csrf_token":"…"}'` |
| `friend_remove.json` | `post '{"action":"friend_remove","friendship_id":8,"csrf_token":"…"}'` — sobre una **`pending` que envió el propio usuario**, que es la enmienda del 2026-09-14 |
| `follow_remove.json` | `post '{"action":"follow_remove","username":"otroseguido","csrf_token":"…"}'` |

Capturadas el **2026-09-14** con la sesión forjada de **seis usuarios de usar y tirar**
(`9101`-`9106`) **borrados al terminar**. David solo tiene una cuenta y estas dos tablas son
relaciones **entre dos personas**: con un usuario no hay ni una fila que capturar, y meterle
amistades reales a su cuenta sería ensuciar exactamente lo que el resto de esta carpeta evita.
Comprobado al acabar: `users` vuelve a 1 fila y `friendships`, `user_follow` y
`user_privacy_settings` a **cero**.

```sql
INSERT INTO users (id, google_id, email, username, display_name, avatar_url) VALUES
 (9101,'m4-fixtures-9101','m4-9101@example.invalid','amistadesm4','Yo, la de las fixturas','https://example.invalid/avatar/amistadesm4.png'),
 (9102,'m4-fixtures-9102','m4-9102@example.invalid','amigaaceptada','Amiga Aceptada','https://example.invalid/avatar/amigaaceptada.png'),
 (9103,'m4-fixtures-9103','m4-9103@example.invalid','pidiomeamistad','Quien Me Pidio Amistad',NULL),
 (9104,'m4-fixtures-9104','m4-9104@example.invalid','lepediamistad','A Quien Le Pedi','https://example.invalid/avatar/lepediamistad.png'),
 (9105,'m4-fixtures-9105','m4-9105@example.invalid','perfilseguido','Perfil Que Sigo','https://example.invalid/avatar/perfilseguido.png'),
 (9106,'m4-fixtures-9106','m4-9106@example.invalid','otroseguido',NULL,NULL);
INSERT INTO friendships (requester_id, addressee_id, status) VALUES
 (9101,9102,'accepted'),   -- amiga aceptada
 (9103,9101,'pending'),    -- solicitud RECIBIDA por 9101
 (9101,9104,'pending');    -- solicitud ENVIADA por 9101
INSERT INTO user_follow (follower_id, followed_id) VALUES
 (9101,9105),(9101,9106),(9102,9101),(9105,9101);
-- … capturar … --
DELETE FROM users WHERE id BETWEEN 9101 AND 9106;   -- CASCADE se lleva las cuatro tablas
```

**`friend_list.json` trae UNA de cada, y ahí está su valor**: un amigo, una solicitud recibida y
una enviada. Las dos `pending` son el caso que separa las dos listas —quién pidió es lo único que
decide en cuál cae, y es también lo único que decide quién puede aceptarla—, así que una fixtura
con solo una de las dos no distinguiría «aceptar» de «retirar». Y los `counts` vienen **1/1/1** a
propósito: un contador que sumara las enviadas daría 2 y el test lo vería.

**Los dos `null` de `pidiomeamistad` y `otroseguido` son deliberados**: son el caso que rompe una
vista que dé por hecho que todo el mundo tiene foto y nombre visible.

**Ni un `email` ni un `id` de usuario en las ocho.** Los dos listados se componen por lista blanca
con un `JOIN users` que ni siquiera los selecciona (`MySqlFriendshipRepository::listarDe()` y
`MySqlFollowRepository`), y hay un test por listado que lo afirma: si algún día llegara alguno de
los dos, el fallo está en el backend. Los correos `@example.invalid` de arriba **no aparecen en
ninguna fixtura**, precisamente por eso.

**Los errores no se guardan como fixtura**, igual que en el resto de esta suite: se construyen en
el `.spec.js`. Se comprobaron contra el backend de dev al capturar, y estos son los literales:

| Caso | Respuesta |
|---|---|
| `friend_accept` sobre una fila que no es tuya | **404** `"Esa amistad no existe."` |
| `friend_accept` siendo el `requester` | **403** `"Esa solicitud no es tuya: solo puede aceptarla quien la recibió."` |

**`friend_remove.json` se capturó sobre una solicitud `pending` que el propio usuario había
enviado**, y no sobre una amistad aceptada: es la enmienda del 2026-09-14 al plan —`friend_reject`
es solo del destinatario, así que sin esto quien envía una solicitud no puede retirarla—. La
respuesta es idéntica en los dos casos (`data: null`), y eso también es contrato.

### Pedir amistad y seguir (M5 del Plan - Amigos y Seguimiento)

| Fichero | Comando |
|---|---|
| `friend_request.json` | `post '{"action":"friend_request","username":"apedir","csrf_token":"…"}'` — usuario `9201` |
| `follow_add.json` | `post '{"action":"follow_add","username":"aseguir","csrf_token":"…"}'` — usuario `9201` |

Capturadas el **2026-09-14** con la sesión forjada de **tres usuarios de usar y tirar**
(`9201`-`9203`) **borrados al terminar**, por lo mismo que las ocho del M4: estas dos acciones
crean una relación **entre dos personas** y con una sola cuenta no hay nada que capturar.
Comprobado al acabar: `users` vuelve a 1 fila y `friendships`, `user_follow` y
`user_privacy_settings` a **cero**.

```sql
INSERT INTO users (id, google_id, email, username, display_name, avatar_url) VALUES
 (9201,'m5-fixtures-9201','m5-9201@example.invalid','relacionesm5','Yo, la del M5','https://example.invalid/avatar/relacionesm5.png'),
 (9202,'m5-fixtures-9202','m5-9202@example.invalid','apedir','A Quien Pido Amistad','https://example.invalid/avatar/apedir.png'),
 (9203,'m5-fixtures-9203','m5-9203@example.invalid','aseguir','A Quien Sigo',NULL);
-- … capturar … --
DELETE FROM users WHERE id BETWEEN 9201 AND 9203;   -- CASCADE se lleva las dos tablas
```

**Ninguna fila de `user_privacy_settings` hizo falta para el `follow_add` que sale bien**, y eso
también es contrato: sin fila, `Seccion::nivelesPorDefecto()` deja `collection`, `decks` y `sets`
en `everyone`, así que un usuario recién creado **ya tiene cara pública** y se le puede seguir.
La fila solo se insertó para provocar el 422 de abajo, y se fue con el `DELETE`.

**`friend_request.json` es un 201 y `follow_add.json` un 200, y la diferencia es deliberada**: el
primero crea una fila que no existía y deja algo esperando respuesta, y el segundo es
**idempotente** —seguir a quien ya sigues vuelve a contestar 200, sin duplicar fila ni mover
`created_at`—. Un 201 la primera vez y un 200 la segunda obligaría al cliente a dibujar dos
botones para el mismo estado; comprobado repitiendo la llamada al capturar.

`friend_request` devuelve la fila entera (`friendshipId` y la persona) **y `stores/friends.js` la
mete en `enviadas` con eso**, sin volver a pedir `friend_list`. `avatarUrl: null` en
`follow_add.json` es deliberado, igual que en las del M4: es el caso que rompe una vista que dé
por hecho que todo el mundo tiene foto.

**Ni un `email` ni un `id` de usuario en las dos.** Los dos `user` se componen por lista blanca en
`PedirAmistad` y `Seguir`, y los correos `@example.invalid` de arriba no aparecen en ninguna.

**Los errores no se guardan como fixtura**, igual que en el resto de esta suite: se construyen en
el `.spec.js`. Se comprobaron contra el backend de dev al capturar, y estos son los literales:

| Caso | Respuesta |
|---|---|
| `friend_request` repetido sobre la misma pareja | **409** `"Ya existe una solicitud con esa persona."` |
| `friend_request` a un nombre que no existe | **404** `"No hay nadie con ese nombre de usuario."` |
| `friend_request` a uno mismo | **422** `"No puedes pedirte amistad a ti mismo."` |
| `follow_add` a uno mismo | **422** `"No puedes seguirte a ti mismo."` |
| `follow_add` sobre un perfil con las cinco secciones en `nobody` | **422** `"Ese perfil no enseña nada públicamente: seguirlo sería un marcador a una página vacía."` |
| cualquiera de las dos sin sesión | **401** `"Authentication required. Please log in."` |

El **409** es el que más importa de los seis: es lo que ve el cliente cuando sus listas están
viejas —esa persona te pidió amistad mientras mirabas otra pantalla— y por eso `pedir()` recarga
al recibirlo en vez de dejar el botón donde estaba.

### Buscar usuarios y la sexta columna de privacidad (M6 del Plan - Amigos y Seguimiento)

| Fichero | Comando |
|---|---|
| `user_search.json` | `post '{"action":"user_search","q":"busca"}'` — usuario `9301` |
| `user_search_vacio.json` | `post '{"action":"user_search","q":"buscaoculto"}'` — usuario `9301` |
| `privacy_get.json` | **RECAPTURADA**: `post '{"action":"privacy_get"}'` — usuario `9305`, sin fila |
| `privacy_set.json` | **RECAPTURADA**: `post '{"action":"privacy_set","value":"nobody","csrf_token":"…"}'` — usuario `9305` |

Capturadas el **2026-09-14** con la sesión forjada de **cinco usuarios de usar y tirar**
(`9301`-`9305`) **borrados al terminar**. Con una sola cuenta no hay a quién buscar, y los tres
estados de privacidad que este hito tiene que distinguir exigen tres personas distintas:

```sql
INSERT INTO users (id, google_id, email, username, display_name, avatar_url) VALUES
 (9301,'m6-9301','m6-9301@example.invalid','buscadorm6','Yo, el del M6',NULL),
 (9302,'m6-9302','m6-9302@example.invalid','buscable','Sin Fila Ninguna','https://example.invalid/avatar/buscable.png'),
 (9303,'m6-9303','m6-9303@example.invalid','buscaoculto','Con Fila En Nobody',NULL),
 (9304,'m6-9304','m6-9304@example.invalid','buscavisible','Con Fila En Everyone',NULL),
 (9305,'m6-9305','m6-9305@example.invalid','privacidadm6','Usuaria de Privacidad','https://example.invalid/avatar/privacidadm6.png');
INSERT INTO user_privacy_settings (user_id, show_in_search) VALUES (9303,'nobody'),(9304,'everyone');
-- … capturar … --
DELETE FROM users WHERE id BETWEEN 9301 AND 9305;   -- CASCADE se lleva user_privacy_settings
```

**`user_search.json` trae a `buscable` (SIN fila en `user_privacy_settings`) y a `buscavisible`
(con fila en `everyone`), y NO trae a `buscaoculto` (con fila en `nobody`).** Los dos primeros son
el contrato que un `INNER JOIN` rompería en silencio: la ausencia de fila **significa** el defecto,
que aquí es `everyone`, y hoy no hay ni una fila en toda la tabla — con un `JOIN` interno esta
fixtura habría salido vacía y nadie se habría enterado de nada. Tampoco sale `buscadorm6`, que es
quien busca: uno no se encuentra a sí mismo.

**`user_search_vacio.json` es la fixtura que más dice, y es un 200.** Se capturó buscando
`buscaoculto` **entero**: esa persona existe, y la respuesta es idéntica —byte a byte— a la de
buscar un nombre que no existe. No hay 404 y no lo habrá: un 404 haría distinguible «aquí no hay
nadie» de «aquí hay alguien que no quiere salir», que es exactamente lo que la sexta columna
existe para que no se pueda saber.

**`privacy_get.json` y `privacy_set.json` se volvieron a capturar, no se editaron.** Desde el M6
las dos traen una clave `search` **al lado de `privacy`, no dentro**: `show_in_search` no es una
sección de contenido y su valor no es un `Nivel` —tiene dos valores y no tres—, así que colarla
en ese mapa habría roto `Seguir::tieneCaraPublica()`, que lo recorre buscando un `everyone` para
decidir si un perfil se puede seguir. El usuario `9305` no tenía fila, así que `privacy_get`
devuelve los cinco defectos **y `search: "everyone"`**, que es como nace todo el mundo. Y
`privacy_set.json` es el resultado de cambiar **solo `value`**: `search` sigue en `everyone`, y eso
también es contrato — mover una sección no toca la sexta columna.

**Ni un `email` ni un `id` en las dos del buscador.** La lista blanca la compone el `SELECT` de
`MySqlUserRepository::buscarPorPrefijo()`, así que el correo no llega ni a salir de MySQL; los
`@example.invalid` de arriba no aparecen en ninguna.

**Los errores no se guardan como fixtura**, igual que en el resto de esta suite. Se comprobaron
contra el backend de dev al capturar, y estos son los literales:

| Caso | Respuesta |
|---|---|
| `user_search` con `q` de dos caracteres | **422** `"Escribe al menos 3 caracteres para buscar a alguien."` |
| `user_search` con `q` de 33 caracteres | **422** `"Un nombre de usuario no pasa de 32 caracteres."` |
| `user_search` **sin** `q` | **400** `"Missing required fields: q"` (lo da `ValidationMiddleware`, no el use case) |
| `user_search` con `q = "%%%"` o `q = "___"` | **200** con `users: []` — los comodines de `LIKE` van escapados |
| `user_search` sin sesión | **401** `"Authentication required. Please log in."` |
| `privacy_set` con `search: "friends"` | **422** `"Valor de show_in_search no soportado: friends. Solo hay dos: nobody o everyone."` |
| `privacy_set` sin ninguna clave | **422** `"privacy_set necesita al menos una sección: collection, value, decks, sets, wishlist; o «search»."` |

El **422 del mínimo** es el que más importa de los siete: es el *Hecho cuando:* del hito, y el
cliente **no lo replica** —`stores/friends.js` manda lo que haya y enseña ese mensaje tal cual—,
así que si algún día el backend bajara el mínimo, la interfaz se enteraría sola.

### Precons e importación

| Fichero | Comando |
|---|---|
| `precon_add_to_collection.json` | `post '{"action":"precon_add_to_collection","file_name":"RidersOfRohan_LTC","csrf_token":"…"}'` — usuario `9007` |
| `precon_add_to_collection_deseos.json` | `post '{"action":"precon_add_to_collection","file_name":"RidersOfRohan_LTC","is_wishlist":true,"csrf_token":"…"}'` — usuario `9007` |
| `import_preview.json` | `post` con `{"action":"import_preview","content":<la lista de abajo>,"filename":"lista-de-prueba.txt"}` |
| `import_apply.json` | `post` con `{"action":"import_apply","rows":<las de preview>,"deck":{"name":"Mazo importado de prueba","status":"building","format":"commander"}}` |
| `import_apply_deseos.json` | `post` con `{"action":"import_apply","is_wishlist":true,"rows":<las 3 líneas de `deck_get_faltantes.json` con sus cuatro dimensiones>}` — usuario `9005` |

Las dos de importación **las llama la vista, no un store** (`views/ImportView.vue:691` y `:745`),
así que el mock de M5 va contra `@/services/api` directamente.

La lista de prueba de `import_preview` son 20 líneas de texto plano elegidas para que el
`PlainTextParser` las reclame (≥50 % con cantidad explícita) y para que salgan **los dos sabores
de conflicto**:

```
4 Lightning Bolt          ← y 15 líneas más que resuelven limpias
1 Esta Carta No Existe    ← conflicto reason: "not_found",  candidates: []
1 Lim-Dûl's Vault (ICE) 96 ← conflicto reason: "mismatch",  candidates: 2
SIDEBOARD:                ← reparte board: "side"
2 Negate
```

`ICE 96` **es** *Shyft*, no *Lim-Dûl's Vault*: es el caso de la trampa del `CLAUDE.md` sobre
resolver por `(set_code, collector_number)` sin comparar el nombre.

El cuerpo de `import_apply` se construyó desde `import_preview.json`: las 16 filas resueltas más
el primer candidato del conflicto `mismatch`, mapeadas con `aFilaDelContrato()`
(`ImportView.vue:577-588`) → `{printingUuid, finish, language, condition, quantity, board}`.
**No hizo falta recortar nada**: el plan avisaba de que `import_apply` mete ~1.200 líneas de
golpe, pero la respuesta son 367 bytes porque el backend solo devuelve el recuento
(`inserted` / `updated` / `totalQuantity` / `deck`), no las filas.

**`precon_add_to_collection_deseos.json` es la misma acción con `is_wishlist: true`** (M7): la
caja entera a la lista de deseos en vez de a la colección. Las dos se capturaron el **2026-09-12**
con la sesión forjada de un **usuario de usar y tirar** (`9007`, `m7desechable`) **borrado al
terminar** (`DELETE FROM users WHERE id = 9007`, que arrastra sus líneas y sus mazos por cascada),
y **en este orden, que es lo que las hace valer**:

1. Se **desea** la caja → `precon_add_to_collection_deseos.json`. En SQL: **87 filas, todas con
   `is_wishlist = 1` y ninguna con 0**, y el mazo `9009` en `building`. Un `deck_get` sobre él
   devolvió `missing: 100` y `deck_list[].missingCount: 100`: el mazo **pide sus 100 cartas**,
   que es exactamente la verdad —desearlas no es tenerlas—.
2. Se **compra** la caja de verdad → `precon_add_to_collection.json` (recapturada: el contrato
   ganó `data.isWishlist` y la vista lo lee para saber a dónde enlazar). `inserted: 87`,
   `updated: 0`: la compra **no pisa el deseo**, escribe sus propias líneas, porque `is_wishlist`
   está dentro del `UNIQUE KEY`.
3. Se compra **otra vez**: `inserted: 0`, `updated: 87` y la tabla en 87 filas con 200 ejemplares,
   con las 87 del deseo intactas en sus 100. **Suma y no duplica.**

**`import_apply_deseos.json` es la misma acción con `is_wishlist: true`**, y es la respuesta de
las **dos** puertas de entrada masivas: la casilla de `/import` y el botón «mandar lo que falta a
deseos» de `/deck/:id` mandan exactamente esta llamada. Se captura aparte de `import_apply.json`
porque aquella lleva `data.deck` y esta no —ni lo lleva ni debe—, y porque el mensaje del backend
es el del lote sin mazo. Que las tres líneas caen en `is_wishlist = 1` con **sus cuatro
dimensiones intactas** se comprobó en SQL sobre las filas escritas:

```
6907  21046320-…  etched  Spanish   EX  1  is_wishlist=1
6908  0aa4288b-…  normal  Japanese  LP  1  is_wishlist=1
6909  a4649be8-…  foil    English   NM  1  is_wishlist=1
```

Y el ciclo entero, medido contra la BD de dev antes de borrar el usuario `9005`: con los tres
deseos puestos el mazo seguía en `missing: 3` —querer no es tener—, y tras tres
`collection_fulfill_wish` bajó a `missing: 0` y `deck_list[].missingCount: 0`.

---

## Lo que se recortó a mano (y es lo único)

`check_auth.json` y los dos `public_profile*.json`, y **conservando la forma** (mismas claves,
mismos tipos):

| Clave | Antes | Ahora |
|---|---|---|
| `data.user.email` | el de David | `fixturas@example.invalid` |
| `data.user.username` | el suyo | `fixturas` |
| `data.user.display_name` | el suyo | `Usuaria de Fixturas` |
| `data.user.avatar_url` | su foto de `lh3.googleusercontent.com` | `https://example.invalid/avatar/fixturas.png` |
| `data.csrf_token` | el forjado para capturar | 64 hex, que es lo que emite `bin2hex(random_bytes(32))` |

Y lo mismo, con las claves en camelCase que usa el router público, en `public_profile.json` y
`public_profile_todo.json`: `user.username` → `fixturas`, `user.displayName` → `Usuaria de
Fixturas`, `user.avatarUrl` → `https://example.invalid/avatar/fixturas.png`. Son las **únicas** dos
de las nueve públicas que traían algo — el resto son cartas, mazos y ediciones—, y `email` no
aparece en ninguna porque el backend no lo manda nunca.

Ninguna otra fixture traía nada: el resto de las acciones no devuelve datos personales. Se
comprobó con un barrido de correos, URLs de `googleusercontent` y cadenas con forma de JWT sobre
los 33 ficheros de entonces (27 en la captura original + las 4 de la lista de deseos y las 2 de su valor, que
además salieron de usuarios de usar y tirar y no traían nada de David que recortar).

**Esta carpeta va al repo.** Nunca se commitea aquí un correo, un token ni un `google_profile`
reales.

---

## ❌ `login.json` — la que falta, y por qué

`login` es la única acción que **no se puede capturar** desde aquí. No es un descuido: es el único
sitio del backend donde la entrada tiene que venir firmada por un tercero.

`AuthController::login()` entrega el `id_token` a `GoogleAuthClient::verifyIdToken()`, que valida
la firma contra el JWKS de Google (`GoogleAuthClient.php:62-66`) y además exige `iss` de Google,
`aud` igual a nuestro `GOOGLE_CLIENT_ID` y `email_verified: true`. Sin un ID token de Google de
verdad, la respuesta es siempre `401 "El token de Google no es válido."`, que no es la forma que
consume `stores/auth.js:47-56`.

**No se ha escrito a mano, a propósito**: una fixture inventada es exactamente lo que esta carpeta
existe para impedir.

Faltan por tanto **dos** formas, las dos ramas de `login`:

1. **Sesión iniciada** — `{user, token, csrf_token}`, lo que lee `applySession()` (`auth.js:126-140`).
2. **Alta a medias** — `{needs_username: true, google_profile: {email, display_name, avatar_url}}`,
   lo que lee `auth.js:47-53` y lo que M3 tiene que probar («`login()` con `needs_username` **no**
   debe autenticar»).

**Cómo capturarla cuando se pueda** (por orden de preferencia):

- **Entrar de verdad una vez por el navegador** en `http://localhost:8094` con las devtools
  abiertas, y copiar el cuerpo de la respuesta de `login` de la pestaña de red. Es una captura
  real. Hay que recortarle el `email`, el `google_profile`, el `avatar_url` y **el `token`**, que
  es un JWT de verdad: se sustituye por uno de pega con las tres partes separadas por puntos.
  Para la rama 2 hace falta una cuenta de Google que **no** esté dada de alta todavía.
- Un ayudante de dev en el backend que acepte un `id_token` de pega cuando `APP_ENV=development`.
  Es cambiar `backend/`, o sea **otro plan**, y no es evidente que valga la pena abrir un agujero
  de autenticación por una fixture.

Mientras tanto, los tests de `auth.js` que dependan de `login` tendrán que construirse el objeto
en el propio `.spec.js` **y decir en un comentario que no está capturado**, para que nadie lo
confunda con contrato verificado.
