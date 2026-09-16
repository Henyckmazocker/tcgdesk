/**
 * ORB: descriptores de una carta y emparejamiento contra sus impresiones.
 *
 * **Este es el unico sitio donde se escriben los parametros de ORB.** Lo
 * importan las dos puntas —el escaner, para la foto, y la siembra, para las
 * referencias que baja del backend— y por eso viven aqui: cambiarlos en un sitio
 * los cambia en los dos, que es la leccion que dejo el dHash. Aquel acabo con la
 * reduccion a 9x8 escrita a mano dos veces, y cambiar solo el algoritmo de
 * reescalado movia el hash **7 bits** contra un umbral de decision de 4.
 *
 * El worker NO tiene valores por defecto: los recibe en el mensaje `cargar`. Un
 * worker con su propia copia de los numeros es la misma desincronizacion, solo
 * que entre hilos.
 *
 * QUE HACE Y QUE NO. Esto extrae descriptores y cuenta inliers. **No decide si
 * una impresion esta certificada**: el `MARGEN_MINIMO` se publica aqui porque es
 * un parametro de ORB, pero quien lo aplica es el escaner, y eso es del M3. Este
 * fichero devuelve un ranking, no un veredicto.
 *
 * COMO LLEGA OPENCV. Ni un byte de opencv.js entra en este modulo: vive en
 * `workers/orb.worker.js`, que lo carga con `importScripts` desde
 * `public/opencv/opencv.js` —generado por `scripts/extraer-opencv-wasm.mjs`
 * desde el paquete de npm—. Las dos razones estan medidas en el M0(a):
 * `detectAndCompute` y `knnMatch` bloquean el hilo en el que corren, y el objeto
 * `cv` es un **thenable** que cuelga el motor si se resuelve una promesa con el.
 */

import { keypointsDe } from './orbNucleo'
import OrbWorker from '@/workers/orb.worker.js?worker'

/** Fijado por el M0(b) el 2026-09-15: con 300 el acierto cae a 7/8. */
export const NFEATURES = 700
/** El valor con el que se midio 8 de 8. */
export const RAZON_DE_LOWE = 0.78
/** inliers del primero / inliers del segundo. **Lo aplica el M3, no este fichero.** */
export const MARGEN_MINIMO = 1.5

/**
 * LA RESOLUCION ES UN PARAMETRO, no un detalle de implementacion, y por eso vive
 * aqui al lado de NFEATURES: el M0(b) midio que la foto y la referencia tienen
 * que llegar al emparejamiento A LA MISMA escala. Con referencias `small`
 * (146x204) y la consulta al recorte de 488x680 el acierto se desploma de 8/8 a
 * 4/8 — y NO por falta de informacion, porque reescalando la consulta a 146x204
 * vuelve a 8/8 con el mejor margen de toda la tabla. La razon 488/146 = 3,34x
 * roza el alcance de la piramide de ORB (1,2^7 = 3,58) y los descriptores dejan
 * de casar.
 *
 * Es la misma leccion del dHash —dos implementaciones que se desincronizan— pero
 * en el eje de la escala en vez del algoritmo, y por eso el parametro se escribe
 * en vez de quedar implicito. `[488, 680]` = el tamano `normal` de Scryfall.
 */
export const LADO_DE_TRABAJO = [488, 680]

/**
 * LA ZONA DEL ARTE, que es lo que el margen NO cuenta. Del M11, 2026-09-16.
 *
 * Relativa a `LADO_DE_TRABAJO`, y aqui arriba con `NFEATURES` y `MARGEN_MINIMO`
 * por lo de siempre: cambiarla en un sitio la cambia en los dos.
 *
 * POR QUE SE EXCLUYE EL ARTE. Medido sobre `Chromatic Lantern`: su confundidor
 * `C16 247` es la MISMA carta con el MISMO arte de Jung Park, marco 2015 contra
 * el 2003 de `RTR 226`. El reparto de inliers por zona dice quien discrimina y
 * quien diluye:
 *
 *   arte     83/85, 91/88, 116/109  -> EMPATE PERFECTO, el diluyente
 *   reglas   60/42, 35/24,  44/21   -> discrimina
 *   titulo    3/1,  19/5,   23/9    -> discrimina
 *   marco     1/1,   2/1,    2/1    -> ruido
 *
 * Margen total 1,14; margen fuera del arte 1,45 / 1,87 / 2,23, coronando a
 * `RTR 226` en las tres. **La franja del marco NO sirve** aunque sea la que el
 * sentido comun senala: ORB no pone casi nada ahi y un ranking por ella corona
 * impresiones equivocadas en 2 de 3 consultas. Se excluye el arte y se cuenta
 * todo lo demas, ni mas ni menos.
 *
 * Y esto esta medido sobre UNA carta, adoptado a sabiendas: si en otras resulta
 * peor, se descubrira escribiendo ediciones equivocadas.
 */
export const ZONA_DE_ARTE = { y: [0.115, 0.530], x: [0.075, 0.925] }

/** Los tres de la piramide y el RANSAC, tambien del M0(b). */
export const ESCALA_DE_PIRAMIDE = 1.2
export const NIVELES_DE_PIRAMIDE = 8
export const UMBRAL_RANSAC = 6.0
/** Menos de 4 parejas no definen una homografia: `findHomography` ni se llama. */
export const MINIMO_DE_PAREJAS = 4

/** Lo que viaja al worker en el mensaje `cargar`. Una sola copia de los numeros. */
export const PARAMETROS = Object.freeze({
  nfeatures: NFEATURES,
  razonDeLowe: RAZON_DE_LOWE,
  ladoDeTrabajo: LADO_DE_TRABAJO,
  escalaDePiramide: ESCALA_DE_PIRAMIDE,
  niveles: NIVELES_DE_PIRAMIDE,
  umbralRansac: UMBRAL_RANSAC,
  minimoDeParejas: MINIMO_DE_PAREJAS,
  zonaDeArte: ZONA_DE_ARTE
})

export { keypointsDe }

// ---------------------------------------------------------------------------
// base64 ⇄ bytes
//
// LOS DESCRIPTORES NO SON TEXTO. Viajan en base64 dentro del JSON porque el
// contrato del backend los manda inline —28.000 B por cara, ~118 KB para las
// 3,15 impresiones de media—, pero lo que se empareja son bytes.
//
// Se trocea a proposito: `String.fromCharCode(...bytes)` con 28.000 argumentos
// desborda la pila de llamadas, y el sintoma es un `RangeError` que aparece solo
// con las cartas de muchos keypoints.
// ---------------------------------------------------------------------------

const TROZO = 8192

export function base64DeBloque (bloque) {
  let texto = ''

  for (let i = 0; i < bloque.length; i += TROZO) {
    texto += String.fromCharCode.apply(null, bloque.subarray(i, i + TROZO))
  }

  return btoa(texto)
}

export function bloqueDeBase64 (texto) {
  const crudo = atob(texto)
  const bytes = new Uint8Array(crudo.length)

  for (let i = 0; i < crudo.length; i++) bytes[i] = crudo.charCodeAt(i)

  return bytes
}

// ---------------------------------------------------------------------------
// El hilo
// ---------------------------------------------------------------------------

/**
 * La fabrica del worker, sustituible **solo por la suite**.
 *
 * Y la sustitucion que la suite hace es la del HILO, no la de opencv.js: el
 * doble de `tests/unit/services/cardOrb.spec.js` ejecuta el mismo
 * `services/orbNucleo.js` con el mismo `@techstark/opencv-js` del
 * `package.json`. Doblar opencv.js habria dejado este fichero **sin cubrir de
 * verdad**, que es exactamente lo que dejo pasar el bug del `deckId` de
 * `/decks`: un test verde sobre un doble que no se parece al original.
 *
 * Lo que jsdom no tiene y por eso se dobla: `Worker`, `OffscreenCanvas` y
 * `createImageBitmap`. Ninguno de los tres es ORB.
 */
let fabricaDeWorker = () => new OrbWorker()

export function usarHiloDeOrb (fabrica) {
  fabricaDeWorker = fabrica || (() => new OrbWorker())
  olvidarElHilo()
}

let worker = null
let cargando = null
let siguienteId = 1
const pendientes = new Map()

function olvidarElHilo () {
  if (worker && typeof worker.terminate === 'function') worker.terminate()
  worker = null
  cargando = null
  pendientes.clear()
}

/** Suelta el worker. La usa el escaner al salir de la pantalla, y la suite. */
export function soltarOrb () {
  olvidarElHilo()
}

function arrancarWorker () {
  if (worker) return

  worker = fabricaDeWorker()

  worker.onmessage = (evento) => {
    const { id, ok } = evento.data
    const cita = pendientes.get(id)

    if (!cita) return

    pendientes.delete(id)

    if (ok) cita.resolver(evento.data)
    else cita.rechazar(new Error(evento.data.error))
  }

  worker.onerror = (e) => {
    // Un worker que revienta se lleva por delante todo lo que estuviera
    // esperando: sin esto, la siembra se queda colgada para siempre y el
    // escaner no se entera de nada.
    const fallo = new Error((e && e.message) || 'el worker de ORB murio')

    pendientes.forEach((cita) => cita.rechazar(fallo))
    pendientes.clear()
    worker = null
    cargando = null
  }
}

function pedir (mensaje, transferibles = []) {
  arrancarWorker()

  const id = siguienteId++

  return new Promise((resolver, rechazar) => {
    pendientes.set(id, { resolver, rechazar })
    worker.postMessage({ id, ...mensaje }, transferibles)
  })
}

/**
 * La URL de `opencv.js`, ABSOLUTA y no relativa.
 *
 * El worker se sirve desde `assets/`, asi que una ruta relativa se resolveria
 * contra la carpeta del chunk y no contra la del documento. `BASE_URL` vale
 * `'./'` en el build `mobile` —Capacitor sirve desde `capacitor://` y una ruta
 * absoluta deja la app en blanco sin un solo error en consola—, asi que se
 * compone contra `location.href` en vez de darla por hecha.
 */
function urlDeOpenCv () {
  const base = (import.meta.env && import.meta.env.BASE_URL) || '/'

  return new URL(`${base}opencv/opencv.js`, window.location.href).href
}

/**
 * Carga opencv.js en el worker, una sola vez.
 *
 * La promesa se memoriza: la siembra pide descriptores de varias impresiones
 * seguidas y sin esto cada una mandaria su propio `cargar`.
 */
function asegurarOpenCv () {
  if (!cargando) {
    cargando = pedir({ tipo: 'cargar', url: urlDeOpenCv(), parametros: PARAMETROS })
      .catch((e) => {
        // Un fallo de carga NO se memoriza: la siguiente carta vuelve a
        // intentarlo. Si se quedara pegado, un tropiezo de red al arrancar
        // dejaria ORB muerto hasta reiniciar la app.
        cargando = null
        throw e
      })
  }

  return cargando
}

// ---------------------------------------------------------------------------
// El contrato
// ---------------------------------------------------------------------------

/**
 * Bytes de una imagen (JPEG) → el bloque de `keypoints * 40` bytes.
 *
 * Acepta base64 —que es lo que devuelve SIEMPRE `captureSample()`, nunca una
 * ruta— o los bytes tal cual, que es como llega la referencia de
 * `GET /api/images/{scryfallId}`. Las dos acaban en el mismo sitio: el worker
 * las reescala a `LADO_DE_TRABAJO`, las pasa a gris y les mete ORB.
 *
 * @param {string|ArrayBuffer|Uint8Array} imagen
 * @returns {Promise<Uint8Array>} el bloque; `keypointsDe()` dice cuantos trae
 */
export async function descriptoresDe (imagen) {
  await asegurarOpenCv()

  const bytes = imagen instanceof Uint8Array
    ? imagen
    : (typeof imagen === 'string' ? bloqueDeBase64(imagen) : new Uint8Array(imagen))

  // Se transfiere una COPIA: quien llamo puede seguir usando sus bytes (la
  // siembra, por ejemplo, no espera que le vacien el JPEG que acaba de bajar).
  const copia = bytes.slice()
  const respuesta = await pedir({ tipo: 'extraer', imagen: copia.buffer }, [copia.buffer])

  return new Uint8Array(respuesta.bloque)
}

/**
 * El bloque de la foto contra los bloques de las impresiones candidatas.
 *
 * @param {Uint8Array} consulta
 * @param {Array<{printingUuid: string, face?: string, orb?: string, bloque?: Uint8Array}>} referencias
 *        tal como vienen de `scan_orb_refs` (`orb` en base64) o ya en bytes
 * @returns {Promise<Array<{printingUuid: string, face: string, inliers: number,
 *          inliersFueraDelArte: number}>>} ordenado por `inliersFueraDelArte`.
 *          **Sin veredicto**: el margen lo aplica el M3 sobre esa misma cifra.
 */
export async function emparejar (consulta, referencias) {
  const utiles = (referencias ?? [])
    .map((r) => ({
      printingUuid: r.printingUuid,
      face: r.face ?? 'front',
      bloque: r.bloque instanceof Uint8Array
        ? r.bloque
        : (typeof r.orb === 'string' && r.orb !== '' ? bloqueDeBase64(r.orb) : null)
    }))
    .filter((r) => keypointsDe(r.bloque) !== null)

  if (!consulta || keypointsDe(consulta) === null || utiles.length === 0) return []

  await asegurarOpenCv()

  const copiaConsulta = consulta.slice()
  const copias = utiles.map((r) => ({ ...r, bloque: r.bloque.slice() }))

  const respuesta = await pedir(
    {
      tipo: 'emparejar',
      consulta: copiaConsulta.buffer,
      referencias: copias.map((r) => ({
        printingUuid: r.printingUuid,
        face: r.face,
        bloque: r.bloque.buffer
      }))
    },
    [copiaConsulta.buffer, ...copias.map((r) => r.bloque.buffer)]
  )

  return respuesta.ranking
}

export default {
  descriptoresDe,
  emparejar,
  NFEATURES,
  RAZON_DE_LOWE,
  MARGEN_MINIMO,
  LADO_DE_TRABAJO,
  ZONA_DE_ARTE
}
