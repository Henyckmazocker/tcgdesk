import { defineStore } from 'pinia'

import { Haptics, ImpactStyle, NotificationType } from '@capacitor/haptics'

import { apiCall, imagenBytes } from '@/services/api'
import {
  MARGEN_MINIMO,
  MINIMO_DE_PAREJAS,
  NFEATURES,
  base64DeBloque,
  bloqueDeBase64,
  descriptoresDe,
  emparejar,
  keypointsDe
} from '@/services/cardOrb'
import { ACABADOS, IDIOMAS, POR_DEFECTO } from '@/constants/collection'
import { lecturaVacia } from '@/services/scanParser'
import { useCollectionStore } from '@/stores/collection'
import { useDeckStore } from '@/stores/deck'
import { useWishlistStore } from '@/stores/wishlist'

/**
 * El estado del escáner: los ajustes de sesión, las filas detectadas y —lo
 * único que de verdad hay que entender de este fichero— **la memoria de lo
 * escrito**.
 *
 * El bucle de `/scan` da unas tres vueltas por segundo y no sabe distinguir
 * «otra carta» de «la misma carta otra vez»: dejar el móvil apuntando tres
 * segundos son nueve lecturas de la misma carta. Sin memoria, eso son nueve
 * `collection_add` y una carta con cantidad 9 que el usuario no ha pedido. Dos
 * barreras lo impiden, y son distintas a propósito:
 *
 *  1. **La deduplicación de LECTURAS** (`claveDeLectura`), que es lo que hace
 *     que nueve lecturas iguales sean UNA fila del menú y UNA sola llamada a
 *     `scan_resolve`. Actúa antes de la red.
 *  2. **La memoria de lo ESCRITO** (`escritas`), un `Set` de claves
 *     `printingUuid|finish|language|condition` —las cuatro dimensiones que
 *     forman la identidad de una línea de colección—. Actúa antes de escribir,
 *     y es la que sobrevive a que la fila se haya ido de la lista: volver a
 *     apuntar a una carta ya registrada la enseña **marcada como escrita y sin
 *     check**, con un `+1` para el caso legítimo de tener dos copias.
 *
 * La segunda no se puede sustituir por la primera: dos lecturas distintas
 * —`155/331 C` en un fotograma y solo el nombre en el siguiente— resuelven a la
 * misma impresión, y es ahí donde la primera barrera no llega.
 *
 * **Escribir nunca corrige**: aquí no hay `moveLine()` ni nada que se le
 * parezca. El escáner registra **antes** de que exista la línea; cambiar una
 * línea ya escrita es asunto de `/collection`.
 */

/**
 * Tope de filas vivas en el menú.
 *
 * Es el mismo 60 de `ScanController::MAXIMO_LECTURAS` a propósito: lo que no
 * cabe en una petición tampoco tiene sentido tenerlo en pantalla. Barrer un
 * binder durante diez minutos llenaría la memoria del móvil de filas que nadie
 * va a mirar, así que las más viejas se caen. Lo escrito **no** se cae con
 * ellas: vive en `escritas`, que no tiene tope.
 */
export const MAXIMO_FILAS = 60

/**
 * Cuántas vueltas seguidas tiene que verse una lectura antes de preguntar por
 * ella. **Son 2, y es el filtro de ruido del M3b** — ver `estaConfirmada()`.
 *
 * Subirlo a 3 quitaría algo más de basura y añadiría medio segundo de espera a
 * CADA carta, que es justo lo que el M5 va a cronometrar contra el teclado.
 */
export const VUELTAS_PARA_CONFIRMAR = 2

/**
 * Por encima de estas impresiones, ORB **ni consulta, ni siembra, ni empareja**.
 *
 * El plan justifica ORB con que «los candidatos son 3,15 de media», y es cierto
 * para el 99,2 % del catálogo. La cola es lo que nadie miró: `Plains` tiene
 * **910** impresiones e `Island` **913**.
 *
 * Medido el 2026-09-16 con la tubería real: `ms = −15,0 + 14,713·n` (R² 0,9996),
 * que extrapolado al Realme (×2,79, del M0) son ~0,9 s con 20 referencias, ~2,9 s
 * con 71, ~4,1 s con 100 y **~38 s con 910**.
 *
 * **EL CRITERIO NO SON LOS 800 ms DEL M0**, y conviene que esté dicho porque es
 * el error que casi fija este número en 20: aquel listón medía el camino
 * SÍNCRONO, y el M3 movió ORB a un worker y lo lanzó **sin `await`**
 * (`lanzarOrb()`, y `cardOrb.js` importa `orb.worker.js?worker`). ORB no bloquea
 * ni el bucle de la cámara ni la UI. Lo que cuesta un emparejamiento largo es
 * **ocupar el worker** —las cartas siguientes hacen cola— y tardar en certificar,
 * y el M3 ya medía que `art` llega ~2 s después.
 *
 * Por eso el tope es **100 y no 20**: deja fuera exactamente las **7** cartas
 * patológicas del catálogo —las tierras básicas, las únicas que pasan de 100— y
 * conserva las **265** del tramo 21-100, que incluyen una *Lightning Bolt* de 71
 * impresiones, o sea justo el caso muy reimpreso para el que ORB existe.
 *
 * Bajar `NFEATURES` no era alternativa: el acierto se cae antes que el coste
 * —a 200 el margen pasa de 2,19 a 1,04 y deja de certificar— y la calibración
 * de 700 se hizo con OCHO fotos, no con una.
 */
export const MAXIMO_DE_IMPRESIONES_PARA_ORB = 100

/** Los tres destinos posibles, para el desplegable y para `fijarDestino()`. */
export const DESTINOS = [
  { label: 'Mi colección', value: 'coleccion' },
  { label: 'Lista de deseos', value: 'deseos' },
  { label: 'Un mazo…', value: 'mazo' }
]

/**
 * LA VERJA DE CERTEZA: las fuentes por las que una IMPRESIÓN puede darse por
 * cerrada, que es lo único que autoriza a escribir sin preguntar.
 *
 * Es una **lista de fuentes y no una condición**, y esa es toda la gracia: el
 * Plan - Reconocimiento de la Impresión por su Arte añadió la tercera sin tocar
 * una línea de aquí.
 *
 * **`art` se emite desde el M3 del 2026-09-15** y ya no es una rama muerta: la
 * pone `emparejarPorArte()` cuando el margen de inliers pasa de `MARGEN_MINIMO`.
 * Las tres cierran igual de bien y **no hay orden entre ellas**: la primera que
 * llega gana (ver `acumularCerteza()`). Por eso `single` y `corner` siguen
 * ganando sin que ORB llegue a emparejar — ORB **añade** una fuente, no
 * sustituye ninguna.
 *
 * Resolver la CARTA no es resolver la IMPRESIÓN: los pasos 3, 3c, 4 y 5 dicen
 * qué carta es, y cuando esa carta tiene siete ediciones el backend asume una.
 * Escribirla sola metería en la colección una edición inventada.
 */
export const FUENTES_DE_CERTEZA = {
  single: 'la carta tiene una sola impresión — no hay nada que asumir',
  corner: 'alguna vuelta trajo (setCode, nº) y el resolvedor cerró por 1, 2 o 2b',
  art: 'ORB identificó la impresión por su arte con margen suficiente'
}

/** ¿Esta fuente es una de las que cierran la impresión? */
export function esFuenteDeCerteza(fuente) {
  return Object.prototype.hasOwnProperty.call(FUENTES_DE_CERTEZA, fuente)
}

/**
 * EL VEREDICTO DE ORB: el ranking de `cardOrb.emparejar()` → una impresión, o
 * `null` si no hay margen para decidir.
 *
 * **El margen se mide sobre INLIERS de `findHomography`, no sobre parejas de
 * Lowe**, y por eso el binario del backend trae las coordenadas además de los
 * descriptores. Sin homografía, dos impresiones de la misma edición con el mismo
 * marco dan casi las mismas parejas y el margen no distingue nada.
 *
 * **Y desde el M11 se mide sobre los inliers de FUERA DEL ARTE**
 * (`inliersFueraDelArte`), no sobre el total. El arte es el diluyente: una
 * reimpresión con el mismo cuadro empata ahí clavada —83/85, 91/88, 116/109 en
 * las tres consultas medidas— y ese empate hunde el cociente total a 1,14, por
 * debajo del listón, con la impresión correcta delante. Contando solo reglas,
 * título y marco, `RTR 226` corona a `C16 247` por 1,45 / 1,87 / 2,23 en las
 * tres. `MARGEN_MINIMO` NO cambia: cambia sobre qué se mide, no el listón.
 *
 * Tres formas de no certificar y dos de hacerlo:
 *
 *  1. `fuera[0] / fuera[1] >= MARGEN_MINIMO` (1,5).
 *  2. **Una sola impresión con descriptores**: no hay segundo contra el que
 *     medir, así que no hay margen que exigir.
 *
 * ...pero **por debajo de `MINIMO_DE_PAREJAS` (4) inliers fuera del arte no se
 * certifica, pase lo que pase con el cociente**. Cuatro es el mínimo que define
 * una homografía, y por debajo el cociente es aritmética sobre ruido: es
 * exactamente así como la franja del marco —1 o 2 inliers sobre 147— coronaba
 * impresiones equivocadas con márgenes altos en 2 de 3 consultas.
 *
 * Y **1,5 no separa «arte distinto» de «arte compartido»**, que es el error
 * conceptual que ya tumbó una versión de este hito: lo que ORB separa es marco,
 * borde, tratamiento y bloque de coleccionista. `WOC 140` y `CMM 926` comparten
 * `illustration_id` y ORB las separa **2,42×** acertando; `WHO 66` y `WHO 671`
 * también lo comparten y empatan en 1,18. La condición es el margen y nada más.
 *
 * Lo que sostiene el 1,5 es la medida directa del M0(b): sobre las 8 fotos con
 * verdad de campo y en las siete combinaciones barridas, de todo lo que supera
 * 1,5 **nada es la impresión equivocada**. Por debajo los márgenes se apelotonan
 * entre 1,00 y 1,20, que es indistinguibilidad de verdad.
 *
 * @param {Array<{printingUuid: string, face?: string, inliers: number,
 *        inliersFueraDelArte: number}>} ranking tal como sale de `emparejar()`
 * @returns {{printingUuid: string, face: string, inliers: number,
 *          inliersFueraDelArte: number, margen: number}|null}
 */
export function impresionPorArte(ranking, margenMinimo = MARGEN_MINIMO) {
  // Un ranking sin `inliersFueraDelArte` —un worker viejo, una respuesta a
  // medias— cuenta CERO y no certifica nada. Cayendo del lado de no escribir:
  // recaer en el total sería volver al margen diluido sin un solo aviso.
  const fueraDelArte = (r) =>
    typeof r?.inliersFueraDelArte === 'number' && r.inliersFueraDelArte > 0
      ? r.inliersFueraDelArte
      : 0

  // Se ORDENA aquí, y no se da por hecho el orden de quien llama: el ranking y el
  // margen tienen que salir de la misma cifra.
  const orden = (ranking ?? [])
    .filter((r) => r?.printingUuid)
    .slice()
    .sort((a, b) => fueraDelArte(b) - fueraDelArte(a))

  if (orden.length === 0) return null

  const primera = orden[0]
  const suyos = fueraDelArte(primera)

  // EL SUELO DE RANSAC, y es lo primero que se mira: cero inliers fuera del arte
  // es «no se parece a nada», y uno, dos o tres son ruido con cociente. El
  // cociente sobre tres inliers es como la franja del marco coronaba `J25 151`.
  if (suyos < MINIMO_DE_PAREJAS) return null

  const segunda = orden[1]
  const delSegundo = segunda ? fueraDelArte(segunda) : 0
  // `Infinity` en los dos casos en que no hay segundo contra el que medir: una
  // sola impresión con descriptores, o una segunda que no casó nada fuera del
  // arte —que es exactamente lo mismo: no hay con quién confundirla—.
  const margen = delSegundo > 0 ? suyos / delSegundo : Infinity

  if (margen < margenMinimo) return null

  return {
    printingUuid: primera.printingUuid,
    face: primera.face ?? 'front',
    inliers: primera.inliers,
    inliersFueraDelArte: suyos,
    margen
  }
}

/**
 * base64 → bloque, sin lanzar.
 *
 * Una fila corrupta del índice —base64 que `atob` no acepta— no puede tumbar el
 * escaneo entero: se trata como «esa impresión no tiene descriptores útiles» y
 * el resto de impresiones de la carta siguen sirviendo.
 */
function bloqueDeRef(orb) {
  if (typeof orb !== 'string' || orb === '') return null

  try {
    return bloqueDeBase64(orb)
  } catch {
    return null
  }
}

/**
 * La clave de la memoria de lo escrito.
 *
 * **Las cuatro dimensiones y no solo el uuid**, porque son exactamente las que
 * forman el `UNIQUE KEY uq_item` de `mtg_collection_item` (salvo el usuario):
 * la misma carta en foil, en alemán o en LP es otra línea de la colección y
 * escribirla otra vez es legítimo. Con la clave recortada al uuid, cambiar el
 * acabado de una fila y confirmarla no escribiría nada.
 */
export function claveDeEscritura(fila) {
  return [fila?.printingUuid, fila?.finish, fila?.language, fila?.condition].join('|')
}

/**
 * La clave con la que dos lecturas del OCR se consideran «la misma carta».
 *
 * Es lo leído, no lo resuelto: se calcula antes de preguntarle al backend, que
 * es donde tiene que actuar para ahorrar la petición. Va en minúsculas porque
 * el OCR alterna mayúsculas en el nombre entre fotogramas (`ScoTT` / `Scott`) y
 * eso no es otra carta.
 */
export function claveDeLectura(lectura) {
  return [lectura?.name, lectura?.setCode, lectura?.collectorNumber, lectura?.language]
    .map((x) => (x == null ? '' : String(x).trim().toLowerCase()))
    .join('|')
}

/**
 * Los acabados que el catálogo admite para esta impresión, en el vocabulario de
 * `ACABADOS`.
 *
 * **La traducción de nombres es obligatoria y es la trampa del contrato**:
 * `finishes` habla de `{foil, nonfoil, etched}` y la colección de
 * `normal|foil|etched`. Leer `finishes.normal` devuelve `undefined` y deja de
 * ofrecer el acabado que la carta sí tiene, sin dar ningún error.
 */
export function acabadosDe(finishes) {
  if (!finishes) return ACABADOS.map((a) => a.value)

  const admitido = {
    normal: finishes.nonfoil,
    foil: finishes.foil,
    etched: finishes.etched
  }

  const posibles = ACABADOS.map((a) => a.value).filter((valor) => admitido[valor])

  // Si el catálogo no marca ninguno —dato incompleto—, se ofrecen los tres
  // antes que dejar la fila sin acabado posible y por tanto sin poder escribirse.
  return posibles.length > 0 ? posibles : ACABADOS.map((a) => a.value)
}

/**
 * El acabado con el que se va a escribir la carta.
 *
 * **El ajuste de sesión solo manda cuando la impresión admite más de uno.** Si
 * el catálogo dice que esa impresión solo existe en `nonfoil`, ese gana aunque
 * la sesión diga «foil»: el catálogo lo sabe mejor que el ajuste, y guardar un
 * foil que no se imprimió nunca valora la colección con un precio que no
 * existe. Es la misma regla por la que el plan descartó deducir el acabado del
 * brillo de la foto.
 */
export function finishParaImpresion(finishes, finishDeSesion) {
  const posibles = acabadosDe(finishes)

  if (posibles.length === 1) return posibles[0]

  return posibles.includes(finishDeSesion) ? finishDeSesion : posibles[0]
}

/**
 * El precio en euros del acabado elegido, o `null`.
 *
 * Segunda mitad de la trampa del contrato: `priceEur` sí habla de
 * `{normal, foil, etched}` —al revés que `finishes`—, así que las dos
 * traducciones son distintas y no se pueden compartir.
 */
export function precioDe(priceEur, finish) {
  if (!priceEur) return null

  const valor = priceEur[finish]

  return typeof valor === 'number' ? valor : null
}

/**
 * La clave de `localStorage` donde se recuerda el idioma del escáner.
 *
 * **Solo el idioma sobrevive a la sesión**, y no es comodidad: `language` viaja
 * dentro del `UNIQUE KEY uq_item` de la línea de colección, así que un ajuste
 * que se reinicia a `English` cada vez que se abre la app parte en dos la
 * colección de quien escanea en español —dos líneas de la misma carta, sin un
 * solo error por ninguna parte—. `finish` y `condition` están en esa misma
 * clave pero se eligen carta a carta; manos libres no se recuerda **a
 * propósito** (el porqué, en `ajustesPorDefecto()`).
 */
const CLAVE_IDIOMA = 'tcgdesk_scan_language'

/**
 * El idioma recordado, o el de siempre.
 *
 * **`localStorage` puede lanzar** —modo privado, cookies bloqueadas, un webview
 * con el almacenamiento capado— y ahí no hay nada que hacer salvo seguir: un
 * ajuste que no se puede leer es `POR_DEFECTO.language`, nunca una excepción que
 * se lleva por delante el escáner entero antes de pintar la primera fila.
 *
 * Y lo leído **se valida contra `IDIOMAS`**: lo escribió otra versión de esta
 * app y puede ser cualquier cosa. Un idioma que el catálogo no conoce no falla
 * al elegirlo, falla al escribir la carta —`CardLanguage` contesta 422 y el
 * usuario lee «no se pudo añadir»—, así que se descarta aquí, que es donde
 * todavía se puede.
 */
function idiomaRecordado() {
  try {
    const guardado = localStorage.getItem(CLAVE_IDIOMA)

    if (IDIOMAS.some((idioma) => idioma.value === guardado)) return guardado
  } catch {
    // Sin almacenamiento se arranca en el idioma de siempre y la sesión funciona
    // igual: lo único que se pierde es la memoria entre sesiones.
  }

  return POR_DEFECTO.language
}

/** Recuerda el idioma. Si el almacenamiento no deja, no pasa nada más. */
function recordarIdioma(valor) {
  try {
    localStorage.setItem(CLAVE_IDIOMA, valor)
  } catch {
    // No poder recordarlo no puede impedir cambiarlo: el ajuste de ESTA sesión
    // ya está puesto cuando se llega aquí.
  }
}

/**
 * La clave de las dos memorias de ORB: **la carta Y el idioma**.
 *
 * El idioma no es decoración. `scan_orb_refs` contesta con las referencias del
 * idioma que se le pide —y cae al inglés cuando esa impresión no está sembrada
 * en él—, así que la misma carta pedida en dos idiomas son dos respuestas
 * distintas. Con la clave puesta solo en `oracleId`, la segunda lectura se
 * comería las referencias cacheadas de la primera y ORB emparejaría contra el
 * idioma equivocado **sin un solo error visible**, que es exactamente el fallo
 * que este plan existe para matar.
 *
 * El separador es un `\n`: no aparece ni en un uuid ni en un nombre de idioma de
 * MTGJSON, así que dos pares distintos no pueden colapsar en la misma clave.
 */
function claveOrb(oracleId, idioma) {
  return `${oracleId}\n${idioma}`
}

/**
 * ¿Hay que sembrar esta impresión?
 *
 * **No basta con mirar si `orb` vino a `null`**, que era la regla de antes y es
 * lo que congeló el índice en el primer idioma que tocara cada carta. Con el
 * respaldo al inglés del M6, una impresión ya sembrada en inglés contesta **con
 * descriptores** aunque se pida en español, así que el cliente daba la petición
 * por satisfecha y **no sembraba el español nunca**. Medido el 2026-09-16:
 * *Chromatic Lantern* tenía 35 filas `English` y 0 `Spanish` después de
 * escanearla en español.
 *
 * La segunda condición es la que descongela: `language` es el idioma de los
 * descriptores **servidos** y `scryfallLanguage` el de la imagen **disponible**
 * para esa impresión. Que difieran significa exactamente «me han dado el
 * respaldo inglés y existe algo mejor», y entonces se siembra lo mejor. Cuando
 * no hay nada mejor —la impresión no está publicada en ese idioma— los dos
 * campos valen lo mismo y aquí no se baja ni una imagen.
 *
 * La ref inglesa **se sigue usando para emparejar en esta misma vuelta**
 * (`trabajarOrbDe()` empareja antes de sembrar): esto no quita nada, solo añade
 * la siembra que certifica en la lectura siguiente.
 */
function necesitaSiembra(ref) {
  if (!ref?.scryfallId) return false

  return !ref.sembrado || ref.language !== ref.scryfallLanguage
}

/** Los valores de sesión de arranque: los mismos del camino de un clic. */
function ajustesPorDefecto() {
  return {
    destino: 'coleccion',
    finish: POR_DEFECTO.finish,
    /** **El único ajuste que se recuerda entre sesiones.** Ver `CLAVE_IDIOMA`. */
    language: idiomaRecordado(),
    condition: POR_DEFECTO.condition,
    /**
     * El modo manos libres. **Arranca APAGADO y no se recuerda entre sesiones.**
     *
     * Es el único ajuste que no persiste ni cuando el resto sí, y es deliberado:
     * que la app empiece a escribir sola en tu colección porque la dejaste
     * encendida hace tres semanas es exactamente el susto que no se quiere. El
     * resto de ajustes solo cambian cómo se escribe una carta que tú confirmas;
     * este cambia QUIÉN confirma.
     */
    manosLibres: false
  }
}

/**
 * ¿La siembra de una impresión salió bien?
 *
 * Tres respuestas cuentan como «sí» y ninguna es un descuido:
 *
 *  - **La cadena vacía.** Es el éxito, y hay que comprobarlo contra el servidor
 *    de verdad para creérselo: `vision_orb_store` devuelve **204 No Content** y
 *    Apache le quita el cuerpo, así que `apiCall` no devuelve un objeto con
 *    `status: 'success'` sino `''` (verificado con `curl -i` contra el backend
 *    de dev el 2026-09-15). Mirar solo `status === 'success'` contaría **toda
 *    siembra buena como fallida**, en silencio y para siempre.
 *  - **409** es `INSERT IGNORE` diciendo «ya estaba»: otro escaneo la sembró
 *    primero y el primero gana. Es la garantía anti-envenenamiento del índice
 *    funcionando, no un error.
 *  - Un `status: 'success'` explícito, por si algún día el 204 lleva cuerpo.
 *
 * Lo que NO cuenta: un `status: 'error'` con 400 (el bloque no medía
 * `keypoints * 40`, o esa impresión no existe) y el `http_code: 0` que `apiCall`
 * devuelve cuando no hubo respuesta.
 */
/**
 * ¿Le queda algo que ganar a esta fila con ORB?
 *
 * Las cuatro condiciones son cuatro renuncias distintas y ninguna sobra:
 *
 *  - `oracleId` — las referencias se piden por carta, no por impresión.
 *  - `printingUuid` — una `not_found` o un `mismatch` sin elegir no tienen
 *    impresión que certificar; su trabajo pendiente es del resolvedor o tuyo.
 *  - `printingCertain` — `single` y `corner` ya cerraron; ORB no contradice.
 *  - `corregida` / `eleccion` — lo que el usuario eligió a mano no se pisa.
 */
function esEmparejable(fila, oracleId) {
  return Boolean(
    fila &&
      fila.oracleId === oracleId &&
      fila.printingUuid &&
      !fila.printingCertain &&
      !fila.corregida &&
      fila.eleccion === null
  )
}

function siembraAceptada(respuesta) {
  if (respuesta === '' || respuesta === null || respuesta === undefined) return true

  return respuesta.status === 'success' || respuesta.http_code === 409 || respuesta.http_code === 204
}

export const useScanStore = defineStore('scan', {
  state: () => ({
    /**
     * Los ajustes de sesión. `destino` toma `'coleccion'`, `'deseos'` o
     * `{ mazo: <id> }`, que es la forma que escribió el plan: un objeto y no una
     * cadena porque el destino «mazo» lleva un dato dentro.
     */
    ajustes: ajustesPorDefecto(),

    /** Los ajustes empiezan plegados: la pantalla es la cámara, no un formulario. */
    ajustesAbiertos: false,

    /** Una fila por detección, de la más reciente a la más vieja. */
    filas: [],

    /**
     * La memoria de lo escrito en esta sesión, por clave de escritura.
     *
     * Un `Set` y no una lista: lo único que se pregunta es si una clave está.
     * No se vacía al limpiar el menú —solo al empezar sesión—, porque limpiar
     * la lista no deshace lo que ya está en la colección.
     */
    escritas: new Set(),

    /** Lecturas con `scan_resolve` en vuelo, para no preguntar dos veces lo mismo. */
    enVuelo: new Set(),

    /**
     * Cuántas vueltas seguidas se ha visto cada clave de lectura, para el filtro
     * de ruido de `estaConfirmada()`.
     *
     * Un `Map` y no un `Set` porque lo que se pregunta es **cuántas**, no si
     * está. Se vacía al empezar sesión y al limpiar el menú, igual que `enVuelo`:
     * lo que sobrevive a un «Limpiar» es lo escrito, no lo visto.
     */
    vistas: new Map(),

    /** Último aviso del escáner. No es el `Toast` de PrimeVue: no está registrado. */
    aviso: null,

    /** Contador de ids de cliente. El `id` vuelve intacto y empareja veredictos. */
    contador: 0,

    /**
     * Las cartas cuya siembra de descriptores ORB ya se intentó en esta sesión.
     *
     * Por `oracleId` y no por impresión: `scan_orb_refs` contesta por carta, así
     * que una sola consulta dice qué impresiones faltan. Sin este `Set`, las dos
     * vueltas por segundo del bucle preguntarían por la misma carta una y otra
     * vez — y `scan_orb_refs` tiene 300/min, pero `vision_orb_store` hereda el
     * global de 60/min.
     *
     * **La clave lleva también el idioma** (`claveOrb()`), igual que la de
     * `orbRefs` y por lo mismo: sin él, la carta que ya se preguntó en inglés
     * se quedaría sin consulta y sin caché al volver a leerse en español, o sea
     * sin ORB para el resto de la sesión.
     *
     * **Se vacía al empezar sesión**, no al limpiar el menú: lo que recuerda es
     * trabajo hecho contra el servidor, no lo que se ve en pantalla.
     */
    orbPedidos: new Set(),

    /**
     * Las referencias ORB de cada carta, por carta **e idioma**, ya en bytes.
     *
     * Existe porque **el M3 tiene dos consumidores de la misma respuesta**: la
     * siembra, que mira las que faltan, y el emparejamiento, que mira las que
     * hay. Sin este caché la segunda lectura de la misma carta no tendría contra
     * qué emparejar —`orbPedidos` impide repetir la consulta— y `art` no llegaría
     * nunca en la misma sesión.
     *
     * Se guarda el bloque en bytes y **no el base64**: ocupa la mitad y evita
     * decodificar 28 KB por impresión en cada vuelta del bucle.
     *
     * Y una impresión recién sembrada se anota aquí con el bloque que se acaba de
     * extraer de su propia imagen de referencia: es el mismo binario que acaba de
     * subirse, así que la carta que disparó la siembra puede certificarse en la
     * lectura siguiente en vez de esperar a la próxima sesión.
     *
     * **La clave es `claveOrb(oracleId, idioma)` y no el `oracleId` a secas.**
     * Desde el M7 la respuesta depende del idioma que se pide, así que indexar
     * solo por carta serviría a la lectura española las referencias inglesas que
     * dejó la anterior: ORB emparejaría contra el idioma equivocado sin un solo
     * error visible. Es el tipo de cosa que un refactor rompe y sale verde, y por
     * eso hay un test que lo fija.
     */
    orbRefs: new Map(),

    /**
     * LA COLA DE SIEMBRA: una sola fila para todas las cartas de la sesión.
     *
     * Es una promesa que se va encadenando, no una lista: lo único que hace
     * falta es un sitio al que atar el siguiente turno. Ver
     * `sembrarLasQueFaltan()` para el porqué —el pico de ~28 `vision_orb_store`
     * que una página de binder dispara de golpe— y `config/routes.php` para la
     * otra mitad de la respuesta, que es del servidor.
     *
     * **No se reinicia con la sesión.** Reiniciarla mientras hay una siembra en
     * vuelo dejaría dos cadenas corriendo a la vez, que es justo lo que existe
     * para impedir.
     */
    colaDeSiembra: Promise.resolve()
  }),

  getters: {
    /** ¿El destino es un mazo? Lo dice la forma del valor, no una bandera aparte. */
    destinoEsMazo: (state) =>
      typeof state.ajustes.destino === 'object' && state.ajustes.destino !== null,

    /** El id del mazo destino, o `null`. */
    mazoDestino: (state) =>
      typeof state.ajustes.destino === 'object' && state.ajustes.destino !== null
        ? state.ajustes.destino.mazo
        : null,

    /**
     * ¿Se puede escribir ya?
     *
     * «Un mazo» sin mazo elegido **no** es un destino: escribir entonces metería
     * la carta en la colección y perdería la mitad del encargo sin decir nada.
     */
    destinoListo() {
      return !this.destinoEsMazo || this.mazoDestino != null
    },

    /** ¿Esta fila ya está escrita? Lo decide el `Set`, nunca un campo de la fila. */
    estaEscrita: (state) => (fila) =>
      Boolean(fila?.printingUuid) && state.escritas.has(claveDeEscritura(fila)),

    /** Cuántas cartas se han escrito en esta sesión, contando las copias. */
    totalEscrito: (state) => state.filas.reduce((suma, f) => suma + f.copias, 0)
  },

  actions: {
    // ======================================================================
    // Sesión y ajustes
    // ======================================================================

    /**
     * Arranca una sesión de escaneo limpia: sin filas y **sin memoria**.
     *
     * Los ajustes NO se tocan: el destino y las tres dimensiones son lo único
     * que el usuario eligió a mano, y hacerle repetirlo cada vez que entra a
     * `/scan` es exactamente lo que el plan llamó «ajustes de sesión» para
     * evitar.
     */
    empezarSesion() {
      this.filas = []
      this.escritas = new Set()
      this.enVuelo = new Set()
      this.vistas = new Map()
      this.orbPedidos = new Set()
      this.orbRefs = new Map()
      // **Manos libres se apaga SIEMPRE al abrir**, y es la excepción a la regla
      // de que los ajustes se conservan. Los demás solo cambian cómo se escribe
      // una carta que tú confirmas; este cambia quién confirma, así que
      // encenderlo tiene que ser una decisión de ahora y no la resaca de la
      // sesión anterior.
      this.ajustes.manosLibres = false
      this.aviso = null
      this.contador = 0
    },

    /**
     * Enciende o apaga el modo manos libres.
     *
     * Al ENCENDERLO se barre lo que ya está en pantalla: si acabas de apuntar a
     * tres cartas y las tres quedaron ciertas, encender el conmutador las
     * escribe. Lo contrario —que solo valiera para lo siguiente— obligaría a
     * apartar el móvil y volver a apuntar a lo mismo.
     */
    fijarManosLibres(valor) {
      this.ajustes.manosLibres = Boolean(valor)

      if (this.ajustes.manosLibres) return this.escribirLasCiertas()
    },

    /** Limpia el menú **sin olvidar lo escrito**: la colección no se deshace. */
    limpiarFilas() {
      this.filas = []
      this.enVuelo = new Set()
      this.vistas = new Map()
    },

    /**
     * Fija el destino desde el desplegable.
     *
     * @param {'coleccion'|'deseos'|'mazo'|{mazo: number}} valor
     * @param {number|null} deckId id del mazo cuando `valor` es `'mazo'`
     */
    fijarDestino(valor, deckId = null) {
      if (valor === 'mazo' || (valor && typeof valor === 'object')) {
        const id = typeof valor === 'object' ? valor.mazo : deckId

        this.ajustes.destino = { mazo: id ?? this.mazoDestino ?? null }
        return
      }

      this.ajustes.destino = valor
    },

    /**
     * Cambia una de las tres dimensiones de sesión.
     *
     * **No reescribe las filas ya pintadas**, y es deliberado: una fila ya tiene
     * su acabado resuelto contra el catálogo de SU impresión, y pisarlo desde
     * aquí volvería a ofrecer un foil que esa carta no tiene. El ajuste manda
     * sobre lo que se detecte a partir de ahora; lo de antes se retoca fila a
     * fila, que es justo lo que ofrece el menú.
     */
    fijarDimension(campo, valor) {
      if (!['finish', 'language', 'condition'].includes(campo)) return

      this.ajustes[campo] = valor

      // **Solo el idioma se recuerda**, y se recuerda aquí porque este es el
      // único sitio por el que cambia el ajuste global. `finish` y `condition`
      // se quedan en la sesión a propósito: ver `CLAVE_IDIOMA`.
      if (campo === 'language') recordarIdioma(valor)
    },

    /**
     * Los mazos donde se puede escribir.
     *
     * Reutiliza `deck.listar()` en vez de llamar a `deck_list` por su cuenta: es
     * la misma lista de `/decks` y duplicar la llamada es duplicar el día que
     * cambie su contrato. Se pide una sola vez por sesión.
     */
    async cargarMazos() {
      const mazos = useDeckStore()

      if (mazos.mazos.length === 0 && !mazos.cargandoLista) {
        await mazos.listar()
      }

      return mazos.mazos
    },

    // ======================================================================
    // Detección y resolución
    // ======================================================================

    /**
     * Una lectura del bucle en vivo. El modo binder mandará varias de golpe.
     *
     * `foto` es el JPEG de ESA vuelta (base64, tal como lo devuelve
     * `captureSample()`), y es lo único que ORB necesita de la cámara. Va por
     * parámetro y **no al estado**: a dos vueltas por segundo, guardarla sería
     * acumular ~150 KB por fila en la memoria del móvil para algo que solo se usa
     * en los pocos milisegundos siguientes. Sin ella el escáner funciona
     * exactamente como antes del M3, que es lo que pasa en la web y en los tests
     * que no la pasan.
     */
    async detectar(lectura, foto = null) {
      return this.resolver([lectura], foto)
    },

    /**
     * Manda las lecturas a `scan_resolve` y pinta los veredictos.
     *
     * Tres cosas que no se ven en la firma:
     *
     *  - **`lecturas` va al primer nivel del payload**, no dentro de `data`:
     *    `api.js` manda `{action, ...payload}` y `ActionRouter` mete el cuerpo
     *    entero bajo `data`. Anidarlo aquí lo dejaría en `data.data.lecturas` y
     *    el controller contestaría «llegó vacío».
     *  - **El `id` lo pone el cliente y vuelve intacto.** Es lo único que
     *    empareja cada veredicto con su fila; el orden no vale, porque el
     *    usuario puede haber tocado tres cosas mientras la petición volvía.
     *  - **Una lectura ya en la lista o ya en vuelo no se vuelve a preguntar.**
     *    Es la primera de las dos barreras contra las tres vueltas por segundo.
     *  - **Y una lectura sin confirmar tampoco sale**, desde el M3b: ver
     *    `estaConfirmada()`.
     */
    async resolver(lecturas, foto = null, opciones = {}) {
      const nuevas = []

      for (const lectura of lecturas ?? []) {
        if (!lectura || lecturaVacia(lectura)) continue

        const clave = claveDeLectura(lectura)

        if (this.enVuelo.has(clave)) continue
        if (this.filas.some((f) => f.claveLectura === clave)) continue
        if (!opciones.sinFiltroDeRuido && !this.estaConfirmada(clave)) continue

        this.enVuelo.add(clave)
        nuevas.push({ clave, lectura })
      }

      if (nuevas.length === 0) return []

      const filas = nuevas.map(({ clave, lectura }) => this.crearFila(clave, lectura))

      this.filas = [...filas, ...this.filas].slice(0, MAXIMO_FILAS)

      const respuesta = await apiCall('scan_resolve', {
        lecturas: filas.map((fila) => ({
          id: fila.id,
          name: fila.lectura.name,
          setCode: fila.lectura.setCode,
          collectorNumber: fila.lectura.collectorNumber,
          rarity: fila.lectura.rarity,
          // El idioma leído de la carta gana al de sesión: está impreso en la
          // esquina y el ajuste es solo un valor por defecto. Lo que no se leyó
          // cae al ajuste, nunca a un idioma inventado.
          language: fila.lectura.language ?? this.ajustes.language,
          finish: this.ajustes.finish,
          condition: this.ajustes.condition
        }))
      })

      for (const { clave } of nuevas) this.enVuelo.delete(clave)

      if (respuesta?.status !== 'success') {
        // La fila se RETIRA en vez de quedarse con un error pegado: el bucle
        // sigue vivo y volverá a leer esa misma carta dentro de un tercio de
        // segundo, así que dejarla marcaría su clave como vista y el reintento
        // no volvería a ocurrir nunca.
        const ids = new Set(filas.map((f) => f.id))

        this.filas = this.filas.filter((f) => !ids.has(f.id))
        this.aviso = {
          tipo: 'error',
          texto: respuesta?.message || 'No se pudo consultar el catálogo.'
        }

        return []
      }

      // Una respuesta buena borra el aviso de la anterior: dejarlo puesto haría
      // que un fallo de red de hace un minuto pareciera el de ahora.
      this.aviso = null

      const resultados = respuesta.data?.results ?? []

      for (const veredicto of resultados) {
        const fila = this.filas.find((f) => f.id === veredicto.id)

        if (fila) this.aplicarVeredicto(fila, veredicto)
      }

      this.fusionarPorImpresion()
      this.retirarNoReconocidas()
      await this.escribirLasCiertas()

      // ORB VA AQUÍ Y VA SIN `await`, y las dos cosas son el hito entero.
      //
      // «Aquí» = **después de que llegue la respuesta de `scan_resolve`** y de
      // que la fila esté resuelta y escrita si tocaba. Lo dice el plan y tiene
      // consecuencia: `single` y `corner` ya han cerrado la impresión cuando ORB
      // llega, así que esas filas ni se emparejan. ORB **añade** una fuente, no
      // sustituye ninguna.
      //
      // «Sin `await`» = ni el emparejamiento ni la siembra hacen esperar al
      // usuario. La fila ya está en pantalla y resuelta; si los descriptores aún
      // no están sembrados, esa carta se resuelve **como se resolvería sin ORB**
      // —por nombre, esperando tu toque— y ORB llegará en la lectura siguiente.
      // Meter esto en el camino de la respuesta convertiría el primer escaneo de
      // cada carta en varios segundos de espera: sembrar son una consulta, una
      // descarga de imagen y una subida **por impresión** (3,15 de media, y 36
      // en una *Prairie Stream*).
      this.lanzarOrb(resultados, foto)

      return resultados
    },

    // ======================================================================
    // ORB: el emparejamiento (M3) y la siembra perezosa (M2)
    // ======================================================================

    /**
     * Dispara el trabajo de ORB de las cartas de esta tanda. **No devuelve
     * promesa.**
     *
     * Que no la devuelva es deliberado: quien llama no puede esperarla ni por
     * accidente. El `catch` vacío tampoco es pereza — un rechazo en una promesa
     * suelta sube a `unhandledrejection` y en el webview eso es un aviso rojo
     * por cada carta que se escanea sin red.
     */
    lanzarOrb(resultados, foto = null) {
      // EL IDIOMA VA EMPAREJADO CON LA CARTA, no suelto: es **el mismo que se va
      // a escribir en la colección** —`crearFila()` lo dejó en la fila como
      // `lectura.language ?? ajustes.language`—, y sembrar o emparejar en un
      // idioma distinto del que se escribe es la incoherencia que el M7 mata.
      // Una carta con su código de esquina en español pide referencias españolas
      // aunque el ajuste global diga otra cosa.
      const porCarta = new Map()

      for (const resultado of resultados ?? []) {
        const oracleId = resultado?.oracleId

        if (typeof oracleId !== 'string' || !oracleId) continue
        if (porCarta.has(oracleId)) continue

        // Por `id` primero y por `oracleId` después: entre la respuesta y aquí
        // han pasado `fusionarPorImpresion()` y `retirarNoReconocidas()`, así que
        // la fila de este veredicto puede haberse fundido con otra.
        const fila =
          this.filas.find((f) => f.id === resultado.id) ??
          this.filas.find((f) => f.oracleId === oracleId)

        porCarta.set(oracleId, fila?.language ?? this.ajustes.language)
      }

      for (const [oracleId, idioma] of porCarta) {
        this.trabajarOrbDe(oracleId, foto, idioma).catch(() => {})
      }
    },

    /**
     * ¿Esta carta tiene demasiadas impresiones para que ORB valga la pena?
     *
     * Mira `printingCount`, que es **entre cuántas impresiones se eligió** y por
     * tanto exactamente cuántas referencias habría que emparejar. Un `0` es «no
     * se sabe» y no bloquea: sin candidatos no hay nada que emparejar de todos
     * modos.
     */
    demasiadasImpresiones(oracleId) {
      return this.filas.some(
        (fila) =>
          fila.oracleId === oracleId && fila.printingCount > MAXIMO_DE_IMPRESIONES_PARA_ORB
      )
    },

    /**
     * Todo lo que ORB hace por una carta, **y en este orden**.
     *
     * Emparejar va ANTES que sembrar y no es un detalle: emparejar son unos
     * milisegundos contra lo que ya está en el índice, y sembrar son hasta 36
     * descargas de imagen (*Prairie Stream*). Al revés, la carta que ya tenía
     * descriptores se quedaría esperando detrás de la cola de descargas de una
     * impresión que no le sirve a nadie todavía.
     */
    async trabajarOrbDe(oracleId, foto = null, idioma = null) {
      // EL TOPE VA AQUÍ Y NO EN `esEmparejable()`, y la diferencia importa: aquí
      // corta de un golpe las TRES cosas —la consulta, la siembra y el
      // emparejamiento—, mientras que gatear solo el emparejamiento seguiría
      // bajando 910 imágenes por una carta que no va a certificar nunca.
      if (this.demasiadasImpresiones(oracleId)) return 0

      const lengua = idioma ?? this.ajustes.language
      const referencias = await this.refsOrbDe(oracleId, lengua)

      if (referencias === null) return 0

      await this.emparejarPorArte(oracleId, foto, lengua)

      return this.sembrarLasQueFaltan(referencias, lengua)
    },

    /**
     * Las referencias ORB de una carta, de la caché o de `scan_orb_refs`.
     *
     * **Una sola consulta por carta y por sesión**, que es lo que `orbPedidos`
     * garantiza: el bucle da dos vueltas por segundo y `scan_orb_refs` tiene
     * 300/min. Un fallo se olvida para que la próxima vuelta lo reintente —un
     * 429 o un corte de red no pueden dejar esa carta sin ORB para toda la
     * sesión—; un éxito se queda en `orbRefs` y lo comparten el emparejamiento y
     * la siembra.
     *
     * @returns {Promise<Array|null>} `null` si no hay nada con lo que trabajar
     */
    async refsOrbDe(oracleId, idioma = null) {
      if (!oracleId) return null

      const lengua = idioma ?? this.ajustes.language
      const clave = claveOrb(oracleId, lengua)

      if (this.orbRefs.has(clave)) return this.orbRefs.get(clave)
      if (this.orbPedidos.has(clave)) return null

      this.orbPedidos.add(clave)

      // `language` es OPCIONAL para el backend y su ausencia significa `English`
      // (así sigue funcionando el APK viejo), pero desde aquí se manda siempre:
      // lo que el escáner sabe del idioma de esta carta es exactamente lo que va
      // a escribir en la colección, y callárselo sería pedir referencias de un
      // idioma y guardar la carta en otro.
      const respuesta = await apiCall('scan_orb_refs', { oracleId, language: lengua })

      if (respuesta?.status !== 'success') {
        this.orbPedidos.delete(clave)

        return null
      }

      // `sembrado` sale de que el backend mandara `orb`, y NO de que el bloque
      // sea utilizable: son dos preguntas distintas. Una fila del índice con
      // base64 corrupto ya está sembrada —`INSERT IGNORE` no deja reemplazarla—
      // así que volver a bajar su imagen sería descargar para nada.
      const referencias = (respuesta.data?.refs ?? []).map((ref) => ({
        printingUuid: ref?.printingUuid ?? null,
        face: ref?.face ?? 'front',
        scryfallId: ref?.scryfallId ?? null,
        // **El idioma de los descriptores que vienen en `orb`**, que no es
        // siempre el que se pidió: `refsDe()` cae a la fila inglesa cuando esa
        // impresión no está sembrada en el idioma pedido. Junto con
        // `scryfallLanguage` es lo que `necesitaSiembra()` compara para
        // descongelar el índice.
        //
        // El `?? lengua` es para un backend anterior a la enmienda: sin el campo
        // los dos idiomas salen iguales y la regla se queda en la de antes.
        language: ref?.language ?? lengua,
        // **El idioma de la IMAGEN que se va a bajar, que NO es siempre el que
        // se pidió.** El backend cae al `scryfallId` inglés cuando MTGJSON no
        // publica la traducción, y eso pasa en el 52 % del catálogo (57.341 de
        // 110.384 impresiones no tienen fila en español). Con esto se sella la
        // siembra; sellarla con `lengua` subiría descriptores ingleses
        // etiquetados como españoles y el `INSERT IGNORE` del backend los
        // dejaría ahí para siempre.
        //
        // El `?? lengua` es para un backend anterior a la enmienda: sin el
        // campo no hay forma de saberlo, y lo que queda es lo de antes.
        scryfallLanguage: ref?.scryfallLanguage ?? lengua,
        sembrado: Boolean(ref?.orb),
        bloque: bloqueDeRef(ref?.orb)
      }))

      this.orbRefs.set(clave, referencias)

      return referencias
    },

    /**
     * EL EMPAREJAMIENTO, que es el hito entero: la foto de esta vuelta contra
     * los descriptores de las impresiones de esta carta.
     *
     * **Solo mira las filas que no venían certificadas.** Una que ya cerró por
     * `single` o por `corner` no se toca: la primera fuente que llega gana y ORB
     * no contradice a ninguna.
     *
     * **Y no pisa lo que el usuario eligió** (`corregida`, `eleccion`): elegir a
     * mano en `PrintingSelect.vue` es la salida del caso que ORB no sabe
     * resolver, y sobrescribirla convertiría esa salida en un adorno.
     *
     * Si no hay descriptores todavía —la carta la está sembrando esta misma
     * vuelta— aquí no pasa nada y la fila se queda como la dejó el resolvedor.
     * ORB llegará en la lectura siguiente.
     *
     * @returns {Promise<object|null>} el veredicto de `impresionPorArte()`
     */
    async emparejarPorArte(oracleId, foto = null, idioma = null) {
      if (!oracleId || !foto) return null
      if (!this.filas.some((fila) => esEmparejable(fila, oracleId))) return null

      // El mismo idioma que pidió `trabajarOrbDe()`, o la caché no acierta y
      // esto dispararía una segunda consulta por la misma carta.
      const referencias = await this.refsOrbDe(oracleId, idioma ?? this.ajustes.language)
      const utiles = (referencias ?? []).filter((ref) => keypointsDe(ref.bloque) !== null)

      if (utiles.length === 0) return null

      let veredicto = null

      try {
        // El worker es el mismo que usa la siembra y la foto es la de ESTA
        // vuelta: 64 ms de extracción medidos en el Realme, fuera del hilo de la
        // UI y fuera del camino de la respuesta.
        const consulta = await descriptoresDe(foto)

        veredicto = impresionPorArte(await emparejar(consulta, utiles))
      } catch {
        // Un worker muerto, un wasm que no cargó o una foto que no decodifica
        // dejan la fila como estaba. El escáner sigue funcionando por nombre.
        return null
      }

      if (veredicto === null) return null

      // Se vuelven a leer las filas: entre el `await` y aquí el bucle ha dado
      // otra vuelta y la lista puede haber cambiado.
      for (const fila of this.filas) {
        if (esEmparejable(fila, oracleId)) this.certificarPorArte(fila, veredicto)
      }

      await this.escribirLasCiertas()

      return veredicto
    },

    /**
     * Una fila pasa a `certaintySource: 'art'`, con la impresión que dijo ORB.
     *
     * **Certificar es también corregir.** El resolvedor ASUME una impresión
     * cuando la carta tiene varias, y ORB acaba de decir cuál es de verdad:
     * quedarse con la asumida y marcarla cierta sería escribir en la colección
     * una edición que no tienes, que es justo lo que la verja existe para
     * impedir. Los tres datos de catálogo de la asumida —edición, número y
     * precio— se van con ella porque han dejado de ser verdad, exactamente con
     * el mismo criterio que `corregirImpresion()`.
     */
    certificarPorArte(fila, veredicto) {
      if (!fila || !veredicto?.printingUuid || fila.printingCertain) return false

      if (fila.printingUuid !== veredicto.printingUuid) {
        fila.impresionAsumida = fila.impresionAsumida ?? fila.printingUuid
        fila.printingUuid = veredicto.printingUuid
        fila.setCode = null
        fila.collectorNumber = null
        fila.priceEur = null
      }

      fila.assumedPrinting = false
      fila.orbInliers = veredicto.inliers
      fila.orbInliersFueraDelArte = veredicto.inliersFueraDelArte
      fila.orbMargen = veredicto.margen

      this.acumularCerteza(fila, 'art')

      return fila.printingCertain
    },

    /**
     * Siembra los descriptores de las impresiones de una carta que aún no los
     * tengan.
     *
     * El camino completo, que es el que el M2 exigió medir:
     *
     *   `scan_orb_refs(oracleId)` → una fila por impresión, con `orb` a `null`
     *   las que nadie ha sembrado → por cada una, `GET /api/images/{scryfallId}`
     *   (que `mtg_image_cache` baja del CDN si hace falta) → `descriptoresDe()`
     *   → `vision_orb_store`.
     *
     * **Si no falta ninguna, no se baja ni una imagen**: la respuesta de
     * `scan_orb_refs` ya trae los descriptores y aquí no queda nada que hacer.
     */
    async sembrarOrbDe(oracleId, idioma = null) {
      const lengua = idioma ?? this.ajustes.language
      const referencias = await this.refsOrbDe(oracleId, lengua)

      if (referencias === null) return 0

      return this.sembrarLasQueFaltan(referencias, lengua)
    },

    /**
     * Va **en serie**, impresión a impresión y CARTA A CARTA: cada vuelta se
     * lleva una imagen entera por la red del móvil, y paralelizar para que una
     * carta se vuelva rápida medio segundo antes no compra nada.
     *
     * ## LA COLA GLOBAL ES DEL M4, Y ES LA MITAD DE CLIENTE DEL 429
     *
     * Hasta aquí la serie era **por carta**: `lanzarOrb()` dispara una cadena
     * por `oracleId` sin `await`, así que con una carta a la vez daba igual. El
     * modo página manda **nueve**, y nueve cadenas sueltas son nueve descargas y
     * nueve subidas a la vez — un pico de ~28 `vision_orb_store` en unos pocos
     * segundos (3,15 impresiones de media por carta, medido).
     *
     * `colaDeSiembra` encadena todas las siembras de la sesión en una sola fila,
     * sea cual sea la carta. Los ~28 POST siguen siendo 28, pero salen repartidos
     * a lo largo de lo que tarden 28 descargas de imagen en vez de de golpe, que
     * es lo que separa un pico de una racha. **No sustituye al límite propio de
     * la ruta** —dos páginas seguidas siguen pasando de 60 en un minuto y por eso
     * `routes.php` declara 300— pero es lo que impide que el móvil se ponga a
     * subir nueve ficheros a la vez por la misma antena.
     *
     * Y la cola **no se puede romper**: el `catch` de `sembrarImpresion()` ya
     * traga todo, pero el eslabón se ata igualmente con un `catch` propio. Una
     * cadena de promesas rota deja la siembra muerta para el resto de la sesión.
     */
    async sembrarLasQueFaltan(referencias, idioma = null) {
      const lengua = idioma ?? this.ajustes.language
      const faltan = (referencias ?? []).filter(necesitaSiembra)

      if (faltan.length === 0) return 0

      const turno = this.colaDeSiembra.then(async () => {
        let sembradas = 0

        for (const ref of faltan) {
          if (await this.sembrarImpresion(ref, lengua)) sembradas++
        }

        return sembradas
      })

      this.colaDeSiembra = turno.catch(() => {})

      return turno
    },

    /**
     * Una impresión: baja su imagen, le pasa ORB y la sube.
     *
     * **Un 409 no es un fallo.** Es `INSERT IGNORE` diciendo «ya estaba»: otro
     * cliente la sembró entre la consulta y ahora, y quien sembró primero gana.
     * Es la garantía anti-envenenamiento del índice, no un error que reportar.
     *
     * Nada de esto lanza hacia arriba: una imagen que no se baja, un worker que
     * se muere o un 400 del backend dejan esa impresión sin sembrar y ya está.
     * La carta se resuelve igual por nombre.
     */
    async sembrarImpresion(ref, idioma = null) {
      try {
        const jpeg = await imagenBytes(ref.scryfallId, 'normal')

        if (!jpeg || jpeg.length === 0) return false

        const bloque = await descriptoresDe(jpeg)
        const keypoints = keypointsDe(bloque)

        // `keypoints * 40` bytes o el backend devuelve 400, y con razón: un
        // bloque que no es múltiplo de 40 es relleno o son descriptores sin sus
        // coordenadas, y sin coordenadas no hay homografía ni margen.
        if (keypoints === null || keypoints < 1 || keypoints > NFEATURES) return false

        const alta = await apiCall('vision_orb_store', {
          printingUuid: ref.printingUuid,
          face: ref.face ?? 'front',
          // El idioma entra en la clave de la siembra, así que esto decide si la
          // referencia que se acaba de extraer se guarda como española o como
          // inglesa. Va el de la **IMAGEN QUE SE HA BAJADO** (`scryfallLanguage`
          // de la ref), nunca el que se pidió: el backend cae al `scryfallId`
          // inglés cuando MTGJSON no publica la traducción, y sellar eso como
          // `Spanish` metería descriptores ingleses en la fila española. Con el
          // `INSERT IGNORE` del backend —que existe para que nadie sobrescriba
          // descriptores buenos— esa mentira **no se puede deshacer**, y son el
          // 52 % de las impresiones.
          language: ref.scryfallLanguage ?? idioma ?? this.ajustes.language,
          nfeatures: NFEATURES,
          keypoints,
          orb: base64DeBloque(bloque)
        })

        if (!siembraAceptada(alta)) return false

        // Se anota en la referencia el bloque que se acaba de subir. Es el mismo
        // binario que el backend guardó —misma imagen, mismo código, mismos
        // parámetros: esa es toda la gracia de que la visión viva solo en el
        // móvil—, así que la carta que disparó la siembra puede certificarse por
        // `art` en la lectura siguiente en vez de esperar a la próxima sesión.
        ref.sembrado = true
        ref.bloque = bloque
        // **Y el idioma con el que se acaba de sellar la fila.** Sin esto, una
        // ref que llegó en inglés teniendo imagen española seguiría cumpliendo
        // `necesitaSiembra()` en cada vuelta del bucle, y el móvil rebajaría la
        // misma imagen dos veces por segundo para que el backend la rechazase
        // con un 409.
        ref.language = ref.scryfallLanguage ?? ref.language

        return true
      } catch {
        return false
      }
    },

    /** La fila que se pinta mientras el backend contesta. */
    crearFila(claveLectura, lectura) {
      this.contador += 1

      return {
        id: `d${this.contador}`,
        claveLectura,
        lectura,
        /** `pendiente` → `resuelta` | `duda`. Es lo que pinta el menú. */
        estado: 'pendiente',
        resolved: false,
        printingUuid: null,
        oracleId: null,
        name: lectura.name,
        setCode: lectura.setCode,
        collectorNumber: lectura.collectorNumber,
        finishes: null,
        priceEur: null,
        assumedPrinting: false,
        printingCount: 0,
        step: null,
        reason: null,
        candidates: [],
        /**
         * ¿Está cerrada la IMPRESIÓN, y por qué? La verja del modo manos libres.
         *
         * **Se acumula entre vueltas y nunca se degrada**: ver `aplicarVeredicto()`.
         */
        printingCertain: false,
        certaintySource: null,
        /**
         * Lo que midió ORB cuando la fuente fue `art`. **No decide nada**: está
         * para que una certificación por arte se pueda explicar sin volver a
         * emparejar, que con un umbral de 1,5 sobre inliers es la diferencia
         * entre depurar y adivinar.
         *
         * Van los dos recuentos porque `orbMargen` NO sale de `orbInliers`: desde
         * el M11 el cociente se mide sobre los de fuera del arte, y ver el total
         * al lado es lo que enseña cuánto diluye el cuadro.
         */
        orbInliers: null,
        orbInliersFueraDelArte: null,
        orbMargen: null,
        /** Las tres dimensiones editables de ESTA fila. */
        finish: this.ajustes.finish,
        language: lectura.language ?? this.ajustes.language,
        condition: this.ajustes.condition,
        /** El candidato elegido de una duda, por índice. */
        eleccion: null,
        /** La impresión que trajo el veredicto, si luego se corrigió a mano. */
        impresionAsumida: null,
        /** ¿La impresión la eligió el usuario? Mantiene el desplegable a la vista. */
        corregida: false,
        /** Copias escritas desde esta fila (el `+1` la sube). */
        copias: 0,
        /** La línea de colección que dejó el alta, para poder deshacerla. */
        linea: null,
        /** ¿La escribió el modo manos libres? Es lo que saca el botón deshacer. */
        escritaSola: false,
        escribiendo: false,
        error: null
      }
    },

    /** Veredicto del backend → fila del menú. */
    aplicarVeredicto(fila, veredicto) {
      fila.resolved = Boolean(veredicto.resolved)
      fila.printingUuid = veredicto.printingUuid ?? null
      fila.oracleId = veredicto.oracleId ?? null
      fila.name = veredicto.name ?? fila.name
      fila.setCode = veredicto.setCode ?? null
      fila.collectorNumber = veredicto.collectorNumber ?? null
      fila.finishes = veredicto.finishes ?? null
      fila.priceEur = veredicto.priceEur ?? null
      fila.assumedPrinting = Boolean(veredicto.assumedPrinting)
      fila.printingCount = veredicto.printingCount ?? 0
      fila.step = veredicto.step ?? null
      fila.reason = veredicto.reason ?? null
      fila.candidates = veredicto.candidates ?? []
      fila.estado = fila.resolved ? 'resuelta' : 'duda'

      this.adoptarIdiomaDetectado(fila, veredicto.language)

      // ## LA CERTEZA SE ACUMULA, NUNCA SE DEGRADA
      //
      // El bucle da dos vueltas por segundo y cada una es una lectura distinta
      // de la misma carta: la primera puede traer el bloque de la esquina y la
      // siguiente solo el nombre, según cómo caiga la luz. Si la certeza se
      // sobrescribiera, una vuelta peor **borraría** lo que ya se sabía y el
      // modo manos libres dejaría de escribir una carta que ya estaba
      // identificada — un parpadeo que además dependería del azar del fotograma.
      //
      // Así que solo suma: en cuanto una vuelta cierra la impresión, la fila se
      // queda cerrada. **La primera fuente que llega gana**, y no hay orden entre
      // ellas: las tres cierran igual de bien y ninguna contradice a otra.
      this.acumularCerteza(fila, veredicto.certaintySource)

      // El acabado se resuelve AQUÍ y no al escribir: es lo que el menú enseña
      // y lo que decide qué precio se pinta.
      fila.finish = finishParaImpresion(fila.finishes, this.ajustes.finish)
    },

    /**
     * El idioma que detectó el backend por el NOMBRE, si no había uno mejor.
     *
     * ## La cascada del M8, y su orden
     *
     * 1. **El código impreso en la esquina** (`idiomaDesdeCodigoImpreso()`, que
     *    ya rellenó `lectura.language` en el parser). Manda, y esta función no
     *    lo toca: es lo único impreso en la carta física.
     * 2. **`veredicto.language`**, que el backend deduce del índice en el que
     *    casó el nombre — `Spanish` si la clave localizada apunta a un solo
     *    idioma, `English` si casó en el índice inglés.
     * 3. **`null`** —clave ambigua, o una lectura resuelta sin que ningún nombre
     *    casara— y entonces no se toca nada: la fila se queda con
     *    `ajustes.language`, que es lo que hacía antes del hito.
     *
     * Lo que lo motiva es una prueba de campo: con el selector en **inglés**,
     * una *Linterna cromática* española no detectaba su edición porque ORB
     * sembraba y emparejaba contra las referencias **inglesas**. El idioma entra
     * además en el `UNIQUE KEY` de `mtg_collection_item`, así que escribirla con
     * el idioma del selector parte la colección en dos sin un solo error.
     *
     * **Se escriben los dos sitios a propósito.** `fila.language` es la
     * dimensión editable —lo que se escribe en la colección— y
     * `fila.lectura.language` es lo que lee la cadena de ORB
     * (`lanzarOrb → refsOrbDe → sembrarImpresion`) y lo que se mandaría en una
     * resolución posterior. Dejar uno de los dos sin poner es exactamente la
     * incoherencia que el M7 vino a quitar: sembrar en un idioma y escribir en
     * otro.
     */
    adoptarIdiomaDetectado(fila, detectado) {
      if (!fila?.lectura || !detectado) return false
      // El código impreso ya lo dijo: no se pisa.
      if (fila.lectura.language) return false

      fila.lectura.language = detectado
      fila.language = detectado

      return true
    },

    /**
     * Las filas que el catálogo no reconoció se van del menú. **Solo esas.**
     *
     * Medido en el Realme el 2026-09-15: el OCR lee variantes estables del mismo
     * error —*Jin-Gitaxias* generó también `Olin-Gitaxias` y `lin-Gitaxias`— y el
     * filtro de repetición no las mata, porque no son ruido aleatorio: son el
     * mismo fallo de lectura repetido, y se leen igual vuelta tras vuelta.
     *
     * Ya no hacen daño —una fila `not_found` no se puede escribir— pero llenan el
     * menú del historial de erratas del OCR en vez de lo que hay sobre la mesa.
     * Así que se retiran, y con ellas **su clave de lectura**: si esa misma
     * lectura vuelve a llegar, se vuelve a preguntar. Eso es deliberado y es la
     * diferencia con dejarlas puestas: una carta que el catálogo no conocía
     * porque la ingesta iba retrasada tiene que poder resolverse en el siguiente
     * intento.
     *
     * **`ambiguous` y `mismatch` NO se tocan**: esas sí son trabajo pendiente del
     * usuario —hay candidatos que elegir— y borrarlas sería esconder una carta
     * que el escáner sí reconoció.
     */
    retirarNoReconocidas() {
      const sobran = this.filas.filter(
        (fila) => fila.estado === 'duda' && fila.reason === 'not_found'
      )

      if (sobran.length === 0) return

      for (const fila of sobran) this.vistas.delete(fila.claveLectura)

      const ids = new Set(sobran.map((f) => f.id))

      this.filas = this.filas.filter((f) => !ids.has(f.id))
    },

    /**
     * ¿Se ha visto esta lectura lo bastante como para molestar al backend?
     *
     * ## LA TERCERA BARRERA, Y LA ÚNICA QUE MATA EL RUIDO DE VERDAD
     *
     * Las otras dos —`claveDeLectura()` y `enVuelo`— impiden **repetir** una
     * pregunta. Esta impide **hacerla**, y existe por lo que se midió en el
     * Realme el 2026-09-15 sobre 21 cartas: el OCR no falla solo leyendo mal un
     * nombre, **inventa cartas que no están sobre la mesa**. Una sola carta
     * física llegó a generar siete filas —`PI`, `Pla`, `Plc`, `Plo`,
     * `Put a +l+1`, *Song of Freyalise*, `tab`—, todas de trozos del borde, del
     * texto de reglas o de la carta de al lado asomando por arriba.
     *
     * La asimetría que lo resuelve es simple: **el ruido no se repite y una carta
     * sí**. Un fragmento de borde cambia con cada fotograma —`Pla`, `Plc`,
     * `Plar`—, mientras que el título de la carta que tienes delante se lee igual
     * vuelta tras vuelta. Así que una lectura nueva no sale a la red en su
     * primera vuelta: espera a verse **dos veces**.
     *
     * El precio son ~550 ms de retraso en la primera carta y **ni uno** en las
     * siguientes vueltas, porque a partir de la segunda la clave ya está
     * confirmada. A cambio se va la mayor parte de las peticiones basura, que es
     * también la mayor parte de las peticiones.
     *
     * Lo que NO se hace aquí es subir el mínimo de longitud del parser: perdería
     * cartas de nombre corto (*Fog*) y no mataría `CateSnale Do` ni
     * `Put a +l+1`, que son largas y también son basura. La repetición sí las
     * mata, porque no se repiten.
     */
    estaConfirmada(clave) {
      const vistas = (this.vistas.get(clave) ?? 0) + 1

      this.vistas.set(clave, vistas)

      return vistas >= VUELTAS_PARA_CONFIRMAR
    },

    /**
     * Suma una fuente de certeza a una fila, si la hay y si aún no estaba cierta.
     *
     * Una fuente desconocida **no** cierra nada: si un backend futuro empezara a
     * mandar una etiqueta que este cliente no conoce, tratarla como cierta sería
     * escribir en la colección por un motivo que la app no sabe explicar. Esto es
     * justo lo que deja entrar a `art` sin tocar nada el día que ORB la emita —
     * está en `FUENTES_DE_CERTEZA` desde hoy—, y lo que deja fuera cualquier otra
     * cosa.
     */
    acumularCerteza(fila, fuente) {
      if (fila.printingCertain) return
      if (!esFuenteDeCerteza(fuente)) return

      fila.printingCertain = true
      fila.certaintySource = fuente
    },

    /**
     * Dos filas distintas que resolvieron a la misma impresión son **una**.
     *
     * Es la barrera que la deduplicación de lecturas no puede cubrir: el bucle
     * lee `155/331 C` en un fotograma y solo el nombre en el siguiente, y las
     * dos lecturas son distintas pero la carta es la misma. Sin esto, el menú
     * enseñaría dos filas de la misma carta y el usuario marcaría las dos.
     *
     * Se conserva la fila **más antigua** (la de más abajo en la lista), que es
     * la que el usuario pudo ya haber tocado.
     *
     * **Y aquí es donde la certeza se acumula de verdad**, no en
     * `aplicarVeredicto()`. Cada vuelta del bucle con una lectura distinta crea
     * una fila NUEVA —`claveDeLectura()` incluye el setCode y el número—, así
     * que la vuelta que por fin lee el bloque de la esquina no actualiza la fila
     * vieja: nace aparte y muere aquí, fusionada. Descartarla sin quedarse su
     * certeza sería tirar justo la vuelta buena, que es la que el modo manos
     * libres está esperando.
     */
    fusionarPorImpresion() {
      const vistas = new Map()
      const supervivientes = []

      // De la más vieja a la más nueva para quedarse con la primera.
      for (const fila of [...this.filas].reverse()) {
        if (!fila.printingUuid) {
          supervivientes.push(fila)
          continue
        }

        const clave = claveDeEscritura(fila)
        const anterior = vistas.get(clave)

        if (anterior) {
          // La duplicada se va, pero lo que sabía se queda.
          this.acumularCerteza(anterior, fila.certaintySource)
          continue
        }

        vistas.set(clave, fila)
        supervivientes.push(fila)
      }

      this.filas = supervivientes.reverse()
    },

    // ======================================================================
    // Las dudas
    // ======================================================================

    /**
     * Elegir uno de los candidatos de un conflicto.
     *
     * Elegir NO escribe: deja la fila con impresión y su check disponible, que
     * es el mismo trato que recibe una fila resuelta. La regla de oro del plan
     * —nada se escribe sin que el resolvedor esté seguro o el usuario haya
     * elegido— se cumple aquí.
     */
    elegirCandidato(fila, indice) {
      const candidato = fila?.candidates?.[indice]

      if (!candidato?.printingUuid) return false

      fila.eleccion = indice
      fila.printingUuid = candidato.printingUuid
      fila.oracleId = candidato.oracleId ?? fila.oracleId
      fila.name = candidato.name ?? fila.name
      fila.setCode = candidato.setCode ?? null
      fila.assumedPrinting = Boolean(candidato.assumedPrinting)
      fila.printingCount = candidato.printingCount ?? 0
      // El candidato no trae `finishes`: viene de los pasos por nombre, que
      // identifican la CARTA. Sin dato de catálogo manda el ajuste de sesión,
      // que es lo que `finishParaImpresion()` hace con `finishes` a null.
      fila.finish = finishParaImpresion(fila.finishes, this.ajustes.finish)
      fila.error = null

      return true
    },

    /**
     * Cambiar a mano la impresión de una edición asumida (`PrintingSelect`).
     *
     * Anula el `printingUuid` que trajo el veredicto y **apaga la marca de
     * asumida**: ya no lo es, la eligió el usuario.
     *
     * Y borra los tres datos de catálogo de la impresión vieja —edición, número
     * y precio—, que **han dejado de ser verdad**: son los de la que el
     * resolvedor asumió, no los de la que el usuario acaba de elegir. Enseñar el
     * precio de otra impresión es exactamente el fallo silencioso que este
     * desplegable existe para evitar; pedirlos otra vez sería una petición por
     * corrección y el dato ya no hace falta para escribir.
     *
     * `impresionAsumida` conserva la original porque es la que le dice al
     * desplegable de qué carta ofrecer ediciones, y `corregida` es lo que hace
     * que el desplegable siga en pantalla tras elegir: si dependiera de
     * `assumedPrinting`, se esfumaría en el momento de usarlo.
     */
    corregirImpresion(fila, printingUuid) {
      if (!printingUuid || printingUuid === fila.printingUuid) return false

      fila.impresionAsumida = fila.impresionAsumida ?? fila.printingUuid
      fila.printingUuid = printingUuid
      fila.assumedPrinting = false
      fila.corregida = true
      fila.setCode = null
      fila.collectorNumber = null
      fila.priceEur = null
      fila.error = null

      return true
    },

    // ======================================================================
    // La escritura
    // ======================================================================

    /**
     * EL MODO MANOS LIBRES: escribe sola lo que la verja dejó cierto.
     *
     * Solo entra por aquí lo que cumple **las tres condiciones a la vez**, y
     * ninguna sobra:
     *
     *  1. **El conmutador está encendido** — y arranca apagado en cada sesión.
     *  2. **`printingCertain`** — la impresión está cerrada por una fuente
     *     conocida (`single`, `corner`, y `art` cuando el plan de ORB la emita).
     *     Sin esto se escribiría la edición que el backend ASUMIÓ, que es meter
     *     en la colección una carta que no tienes.
     *  3. **La clave no está escrita ya** — lo comprueba `escribir()`, que es
     *     quien guarda la memoria. Tres segundos apuntando son ~6 vueltas y una
     *     sola escritura.
     *
     * Va **en serie y no con `Promise.all`**: con destino mazo cada carta son dos
     * peticiones encadenadas, y lanzar cinco cartas a la vez son diez peticiones
     * compitiendo por el mismo `mtg_deck`. El bucle de la cámara no espera a
     * esto —la resolución va suelta desde el M3—, así que el tiempo que tarde no
     * frena el escaneo.
     */
    async escribirLasCiertas() {
      if (!this.ajustes.manosLibres) return

      for (const fila of [...this.filas]) {
        if (!fila.printingCertain) continue
        if (fila.escribiendo || fila.escritaSola) continue
        if (this.estaEscrita(fila)) continue

        const ok = await this.escribir(fila, { sola: true })

        // Solo se marca cuando de verdad se escribió: una fila que falló tiene
        // que poder volver a intentarse en la vuelta siguiente.
        if (ok) fila.escritaSola = true
      }
    },

    /**
     * La vibración, que es lo que permite barrer un montón SIN MIRAR la pantalla.
     *
     * Dos patrones distintos y esa es toda la función: uno dice «entró» y el otro
     * «esta te espera en el menú». Con la cámara en una mano y la carta en la
     * otra, la muñeca es el único canal libre.
     *
     * **Nunca lanza.** En el navegador de escritorio el plugin no existe y en un
     * móvil con la vibración desactivada falla en silencio; un escáner que se
     * cae porque no puede vibrar sería absurdo.
     */
    async vibrar(tipo) {
      try {
        if (tipo === 'escrita') {
          await Haptics.notification({ type: NotificationType.Success })
        } else {
          await Haptics.impact({ style: ImpactStyle.Light })
        }
      } catch {
        // Sin vibración se escanea igual: es un aviso, no un requisito.
      }
    },

    /**
     * DESHACER una carta que se escribió sola. **No es opcional.**
     *
     * Es la única red del modo manos libres: sin esto, una carta escrita por
     * error hay que ir a buscarla a `/collection` sabiendo cuál fue, y con el
     * móvil en la mano y quince cartas por delante eso no pasa.
     *
     * **Borra y devuelve la clave a la circulación**, que es la mitad que se
     * olvida: si la clave se quedara en `escritas`, la carta no podría volver a
     * entrar nunca en esta sesión — ni a mano.
     *
     * Deshacer **no es `moveLine()`**: aquí se borra la línea entera. Cambiar la
     * edición de algo ya escrito es asunto de `/collection`, y el escáner no
     * tiene por qué saber hacerlo.
     */
    async deshacer(fila) {
      if (!fila?.printingUuid || fila.escribiendo) return false

      // Sin la línea no hay `item_id` que tocar: es el caso de una fila que
      // vino de una sesión anterior o de un destino que no es la colección.
      if (!fila.linea?.id) {
        fila.error = 'Esta carta hay que quitarla desde tu colección.'

        return false
      }

      fila.escribiendo = true
      fila.error = null

      const coleccion = useCollectionStore()

      // RESTA UNA COPIA, no borra la línea. El plan decía `collection_remove` a
      // secas, y eso solo es correcto cuando la carta no estaba ya en la
      // colección: con tres copias previas, «deshacer» se habría llevado las
      // cuatro. `cambiarCantidad()` ya maneja el caso de llegar a cero —el
      // backend borra la línea y devuelve `removed`—, así que los dos casos
      // salen por el mismo sitio.
      const ok = await coleccion.cambiarCantidad(fila.linea, fila.linea.quantity - 1)

      fila.escribiendo = false

      if (!ok) {
        fila.error = coleccion.aviso?.texto || 'No se pudo deshacer.'

        return false
      }

      this.escritas.delete(claveDeEscritura(fila))
      fila.copias = Math.max(0, fila.copias - 1)
      fila.escritaSola = false

      return true
    },

    /**
     * Confirmar una fila: **la escritura inmediata del plan**.
     *
     * Marcar el check es la carta escrita. Ni diálogo, ni segundo paso: es la
     * misma promesa del botón «Añadir» del catálogo, que es lo que hace que
     * vaciar un binder sea viable.
     *
     * Con destino «mazo» son **DOS escrituras y no una** —`mtg_deck` no
     * referencia la colección, el consumo se calcula—: primero `collection_add`
     * y luego `deck_card_add`. Si la segunda falla, la fila queda marcada como
     * escrita igualmente y con el error a la vista: la primera SÍ ocurrió, y
     * olvidarla haría que la siguiente vuelta del bucle metiera una segunda
     * copia en la colección.
     *
     * @param {object} fila
     * @param {{extra?: boolean, sola?: boolean}} opciones `extra` es el `+1`:
     *   escribe aunque la clave ya esté en la memoria, que es el caso legítimo de
     *   tener dos copias. `sola` la marca el modo manos libres, y solo cambia dos
     *   cosas: que vibre y que la fila recuerde la línea para poder deshacerla.
     * @returns {Promise<boolean>}
     */
    async escribir(fila, { extra = false, sola = false } = {}) {
      if (!fila || fila.escribiendo) return false

      if (!fila.printingUuid) {
        fila.error = 'Elige antes cuál de las cartas es.'
        return false
      }

      if (!this.destinoListo) {
        this.aviso = { tipo: 'error', texto: 'Elige a qué mazo van las cartas.' }
        return false
      }

      const clave = claveDeEscritura(fila)

      // LA MEMORIA. Sin `extra`, una clave ya escrita no se vuelve a escribir:
      // es lo que impide que tres segundos apuntando tripliquen la carta.
      if (!extra && this.escritas.has(clave)) return false

      fila.escribiendo = true
      fila.error = null
      this.aviso = null

      const opciones = {
        finish: fila.finish,
        language: fila.language,
        condition: fila.condition,
        quantity: 1
      }

      const coleccion = useCollectionStore()
      const deseos = useWishlistStore()
      const aDeseos = this.ajustes.destino === 'deseos'

      // Se reutilizan los dos stores en vez de llamar a `collection_add` por
      // nuestra cuenta: son los mismos que usan el catálogo y la ficha, y una
      // tercera llamada al mismo endpoint es una tercera que se desincroniza.
      const ok = aDeseos
        ? await deseos.desear(fila.printingUuid, opciones)
        : await coleccion.anadir(fila.printingUuid, opciones)

      if (!ok) {
        fila.escribiendo = false
        // El mensaje concreto lo dejó el store que falló; leerlo de ahí evita
        // inventar un texto peor que el del backend.
        fila.error = (aDeseos ? deseos.aviso : coleccion.aviso)?.texto || 'No se pudo escribir.'

        return false
      }

      // Escrito: la clave entra en la memoria ANTES de la segunda escritura,
      // por lo dicho en el docblock.
      this.escritas.add(clave)
      fila.copias += 1

      // La línea que acaba de quedar en la colección, con su `id` y su cantidad
      // ya sumada. Es lo único que permite deshacer **restando una copia** en vez
      // de borrar la línea entera: si ya tenías tres, deshacer no puede llevarse
      // las cuatro.
      if (!aDeseos) fila.linea = coleccion.ultimoAnadido ?? fila.linea ?? null

      if (sola) await this.vibrar('escrita')

      let todoBien = true

      if (this.destinoEsMazo) {
        todoBien = await this.anadirAlMazo(fila)
      }

      fila.escribiendo = false

      return todoBien
    },

    /** El `+1` de una carta que ya está escrita: la segunda copia de verdad. */
    sumarUna(fila) {
      return this.escribir(fila, { extra: true })
    },

    /**
     * La segunda escritura del destino «mazo».
     *
     * Va por `apiCall` y no por `deck.anadirConOpciones()` porque aquel escribe
     * **en el mazo abierto** (`this.mazo?.id`) y lo refresca entero: aquí no hay
     * mazo abierto, hay un id elegido en un desplegable, y pedir el mazo
     * completo tras cada carta escaneada sería una petición de más por carta.
     */
    async anadirAlMazo(fila) {
      const respuesta = await apiCall('deck_card_add', {
        deck_id: this.mazoDestino,
        printing_uuid: fila.printingUuid,
        finish: fila.finish,
        language: fila.language,
        condition: fila.condition,
        count: 1
      })

      if (respuesta?.status !== 'success') {
        fila.error = respuesta?.message || 'Se guardó en la colección, pero no en el mazo.'
        return false
      }

      return true
    },

    /**
     * Cambiar una de las tres dimensiones de una fila concreta.
     *
     * Solo antes de confirmar: una vez escrita, corregirla sería mover una línea
     * de colección y eso es `moveLine()`, no esto. Se impide aquí y no solo en
     * la plantilla para que el store no dependa de que la vista deshabilite el
     * control.
     */
    cambiarDimension(fila, campo, valor) {
      if (!fila || !['finish', 'language', 'condition'].includes(campo)) return false
      if (this.estaEscrita(fila)) return false

      fila[campo] = valor
      fila.error = null

      return true
    }
  }
})
