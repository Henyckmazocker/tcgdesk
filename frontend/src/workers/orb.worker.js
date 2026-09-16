/**
 * EL WORKER DE ORB. Heredado del spike del M0(a) —`src/spike/orb.worker.js`—,
 * que se borra con su hito; esto es su version de produccion.
 *
 * AQUI DENTRO VIVE TODO OPENCV. El hilo principal no carga `opencv.js`, no
 * conoce `cv` y no toca un `Mat` en su vida: solo manda ordenes y recibe bytes.
 * No es una preferencia de estilo, son dos cosas medidas en el Realme:
 *
 * 1. Cargar `opencv.js` en el hilo de la UI congela la pantalla mientras se
 *    instancia el wasm. Un escaner cuya camara se queda tiesa no sirve aunque
 *    tarde 450 ms.
 * 2. `detectAndCompute` y `knnMatch` son llamadas SINCRONAS a wasm: en el hilo
 *    principal bloquean el render entero mientras corren. Con el worker, durante
 *    6,7 s de emparejamiento la camara pinto **402 cuadros** (hueco maximo 25
 *    ms).
 *
 * LA TRAMPA QUE COSTO EL M0(a), y que por eso esta escrita aqui arriba: el
 * objeto que `opencv.js` deja en `cv` es el `Module` de Emscripten, y ese Module
 * TIENE UN METODO `then`:
 *
 *     Module["then"] = function (func) { if (calledRun) { func(Module) } ... }
 *
 * O sea: `cv` es un THENABLE. Hacer `resolve(cv)` o `await cv` mete al motor en
 * el procedimiento de resolucion de promesas, que llama a `cv.then(resolver)`,
 * que llama a `resolver(Module)`, que vuelve a ver un thenable, que vuelve a
 * llamar a `then`... para siempre. El sintoma es un 100 % de CPU con la memoria
 * COMPLETAMENTE PLANA y un hilo que ni el depurador puede interrumpir, que es
 * exactamente lo que parecia «el wasm no compila». **Nunca se resuelve una
 * promesa con `cv`**: aqui se resuelve con `true` y `cv` se lee de `self`.
 *
 * ES UN WORKER CLASICO a proposito (Vite lo empaqueta con `?worker`, sin
 * `type: 'module'`): `importScripts` no existe en los worker de tipo modulo, y
 * `opencv.js` es un script UMD que hay que cargar en tiempo de ejecucion desde
 * `public/opencv/`, no un modulo que Vite pueda empaquetar. Empaquetarlo seria
 * meter los 10,8 MB con el wasm en base64 dentro del chunk, que es justo lo que
 * `scripts/extraer-opencv-wasm.mjs` existe para evitar.
 *
 * QUE CRUZA `postMessage`: numeros, cadenas y `ArrayBuffer`s (transferidos, no
 * copiados). Los `Mat` NO cruzan: no son serializables y su memoria vive en el
 * heap de wasm de este worker.
 *
 * LOS PARAMETROS NO ESTAN AQUI. Llegan en el mensaje `cargar` desde
 * `services/cardOrb.js`, que es el unico sitio donde se escriben. Un worker con
 * sus propios valores por defecto es la misma desincronizacion que costo el
 * dHash, solo que entre hilos.
 */

import {
  crearHerramientas,
  emparejarBloques,
  extraerBloque,
  soltarHerramientas
} from '@/services/orbNucleo'

let cv = null
let herramientas = null
let parametros = null

/**
 * Carga `opencv.js` con `importScripts`.
 *
 * `self.Module` se pone ANTES de `importScripts` porque Emscripten lo lee al
 * evaluar el script: es la unica forma de colocar `locateFile`, y hace falta
 * porque en un worker `scriptDirectory` sale de `self.location.href` —la carpeta
 * del chunk del worker, `assets/`— y no de donde esta el `.js` de OpenCV. Sin
 * `locateFile`, el `opencv_js.wasm` se pide en `assets/` y da 404.
 *
 * Para que ese `self.Module` LLEGUE a Emscripten hace falta ademas el parche que
 * `scripts/extraer-opencv-wasm.mjs` aplica a la cola del envoltorio UMD: el
 * paquete trae `var Module = {}` ahi, y ese `var` izado tapa la global. Si algun
 * dia vuelve el 404 del wasm, el culpable es ese parche, no esta funcion.
 *
 * `onRuntimeInitialized` tambien va dentro del `Module` por el mismo motivo. El
 * sondeo esta ademas del callback porque entre el retorno de `importScripts` y
 * la inicializacion del runtime hay una carrera real, y perderla seria colgarse
 * para siempre sin mensaje.
 */
function cargarOpenCv (urlDeOpencv) {
  return new Promise((resolve, reject) => {
    let resuelto = false

    const listo = () => {
      if (resuelto || !self.cv || !self.cv.Mat) return
      resuelto = true
      clearInterval(sondeo)
      // `resolve(true)`, NUNCA `resolve(self.cv)`: ver el comentario de cabecera.
      resolve(true)
    }

    self.Module = {
      locateFile: (fichero) => new URL(fichero, urlDeOpencv).href,
      onRuntimeInitialized: listo
    }

    const sondeo = setInterval(listo, 20)

    try {
      self.importScripts(urlDeOpencv)
    } catch (e) {
      clearInterval(sondeo)
      reject(new Error(`no se pudo cargar ${urlDeOpencv}: ${e && e.message ? e.message : e}`))

      return
    }

    listo()
  })
}

/**
 * JPEG → `cv.Mat` en gris, SIEMPRE a `LADO_DE_TRABAJO`.
 *
 * LA ESCALA MANDA, y es una decision medida del M0(b), no una comodidad: la foto
 * y la referencia tienen que llegar al emparejamiento a la MISMA escala o el
 * acierto cae de 8/8 a 4/8. Por eso reescala aqui todo lo que entra, venga de la
 * camara o del CDN.
 *
 * `OffscreenCanvas` + `drawImage` y no el `resizeWidth` de `createImageBitmap`:
 * el remuestreo tiene que ser EL MISMO que el del canvas del navegador, que es
 * con el que se calibraron los inliers. Cambiar de filtro cambia los numeros.
 *
 * El `Mat` que devuelve lo libera quien lo pide.
 */
async function grisDe (bytes) {
  const [ancho, alto] = parametros.ladoDeTrabajo
  const lamina = await createImageBitmap(new Blob([bytes]))
  const lienzo = new OffscreenCanvas(ancho, alto)
  const pincel = lienzo.getContext('2d')

  pincel.drawImage(lamina, 0, 0, ancho, alto)
  lamina.close()

  const datos = pincel.getImageData(0, 0, ancho, alto)
  const rgba = cv.matFromImageData(datos)
  const gris = new cv.Mat()

  try {
    cv.cvtColor(rgba, gris, cv.COLOR_RGBA2GRAY, 0)
  } finally {
    rgba.delete()
  }

  return gris
}

/**
 * EL PROTOCOLO. Cada mensaje lleva su `id` y vuelve con el mismo: quien llama no
 * empareja respuestas por orden de llegada.
 */
self.onmessage = async (evento) => {
  const { id, tipo } = evento.data

  try {
    if (tipo === 'cargar') {
      parametros = evento.data.parametros

      const t0 = performance.now()

      await cargarOpenCv(evento.data.url)
      cv = self.cv
      herramientas = crearHerramientas(cv, parametros)

      self.postMessage({ id, ok: true, ms: Math.round(performance.now() - t0) })

      return
    }

    if (tipo === 'extraer') {
      const t0 = performance.now()
      const gris = await grisDe(new Uint8Array(evento.data.imagen))

      try {
        const bloque = extraerBloque(cv, herramientas.orb, gris)

        // El bloque se TRANSFIERE, no se copia: son 28 KB por carta y el bucle
        // de la camara pasa por aqui dos veces por segundo.
        self.postMessage(
          { id, ok: true, bloque: bloque.buffer, ms: Math.round(performance.now() - t0) },
          [bloque.buffer]
        )
      } finally {
        gris.delete()
      }

      return
    }

    if (tipo === 'emparejar') {
      const t0 = performance.now()
      const referencias = evento.data.referencias.map((r) => ({
        printingUuid: r.printingUuid,
        face: r.face,
        bloque: new Uint8Array(r.bloque)
      }))

      const ranking = emparejarBloques(
        cv,
        herramientas,
        new Uint8Array(evento.data.consulta),
        referencias,
        parametros
      )

      self.postMessage({ id, ok: true, ranking, ms: Math.round(performance.now() - t0) })

      return
    }

    if (tipo === 'soltar') {
      soltarHerramientas(herramientas)
      herramientas = null
      self.postMessage({ id, ok: true })

      return
    }

    throw new Error(`orden desconocida: ${tipo}`)
  } catch (e) {
    self.postMessage({ id, ok: false, error: String(e && e.message ? e.message : e) })
  }
}
