#!/usr/bin/env node
/**
 * Saca el wasm de `@techstark/opencv-js` a un fichero aparte.
 *
 * POR QUE EXISTE ESTE PASO, que es la pregunta que se hace todo el mundo al
 * verlo: el paquete de npm trae **un solo fichero** con el `opencv_js.wasm`
 * incrustado como data-URI en base64. Servido asi, el webview tiene que
 * decodificar ~10,6 MB de base64 antes de poder compilar nada, y eso son **~340
 * ms mas de carga** (medido el 2026-09-15: 778/794 ms con el base64 dentro
 * contra 432/554 ms con el wasm aparte).
 *
 * Y las dos alternativas obvias son peores:
 *
 *  - **Commitear el `.wasm`** al repo: 8 MB de binario en el historial de git
 *    para siempre, y una version de OpenCV que ya no la declara nadie.
 *  - **Bajarlo del CDN en tiempo de ejecucion**: este proyecto entero se define
 *    por que ninguna peticion de usuario sale a internet.
 *
 * Asi que la version vive en `package.json` —declarada y actualizable— y el
 * fichero servible se **genera** desde `node_modules` en el build. Lo generado
 * va a `.gitignore`: se reconstruye con `npm run opencv:wasm`.
 *
 * DOS SUSTITUCIONES, Y LAS DOS TIENEN QUE ENCONTRAR SU LITERAL O ESTO FALLA
 * RUIDOSAMENTE. Un script de extraccion que no encuentra lo que busca y sigue
 * adelante deja un `opencv.js` que carga a medias y falla mucho mas tarde, en
 * el movil, sin mensaje.
 *
 *  1. `wasmBinaryFile="data:application/octet-stream;base64,…"` →
 *     `wasmBinaryFile="opencv_js.wasm"`. Emscripten le pasa esa ruta por
 *     `locateFile()` en cuanto deja de ser una data-URI, que es lo que deja al
 *     worker decidir de donde se baja.
 *
 *  2. La cola del envoltorio UMD:
 *
 *         if (typeof Module === 'undefined')
 *           var Module = {};
 *         return cv(Module);
 *
 *     **Ese `var` es una trampa y hay que quitarla.** `var Module` se iza al
 *     principio de la funcion del envoltorio, asi que `typeof Module` vale
 *     SIEMPRE `'undefined'` ahi dentro y el `Module` global —el que el worker
 *     deja puesto con su `locateFile` y su `onRuntimeInitialized`— **nunca
 *     llega a Emscripten**. El sintoma es un 404 de `opencv_js.wasm` pedido
 *     contra la carpeta del chunk del worker (`assets/`) en vez de contra
 *     `public/opencv/`, y una promesa de carga que no se resuelve jamas.
 *     (El `var` lo pone el propio paquete a proposito, para que Webpack no
 *     reviente al asignar una global implicita en modo estricto; aqui no hay
 *     Webpack y lo que hace falta es justo lo contrario.)
 *
 * Es idempotente: si el sello coincide con el origen, no reescribe nada.
 */

import { createHash } from 'node:crypto'
import { existsSync, mkdirSync, readFileSync, statSync, writeFileSync } from 'node:fs'
import { dirname, resolve } from 'node:path'
import { fileURLToPath } from 'node:url'

const RAIZ = resolve(dirname(fileURLToPath(import.meta.url)), '..')
const PAQUETE = resolve(RAIZ, 'node_modules/@techstark/opencv-js')
const ORIGEN = resolve(PAQUETE, 'dist/opencv.js')
const CARPETA = resolve(RAIZ, 'public/opencv')
const GLUE = resolve(CARPETA, 'opencv.js')
const WASM = resolve(CARPETA, 'opencv_js.wasm')
const SELLO = resolve(CARPETA, '.sello.json')

const DATA_URI = /wasmBinaryFile="data:application\/octet-stream;base64,([A-Za-z0-9+/=]+)"/
const COLA_UMD = "  if (typeof Module === 'undefined')\n    var Module = {};\n  return cv(Module);"
const COLA_NUEVA = '  // Parcheado por scripts/extraer-opencv-wasm.mjs: sin esto el `var Module`\n'
  + '  // izado tapa el Module global del worker y el wasm se pide donde no esta.\n'
  + '  return cv(globalThis.Module || {});'

function morir (mensaje) {
  console.error(`\n✗ extraer-opencv-wasm: ${mensaje}\n`)
  process.exit(1)
}

if (!existsSync(ORIGEN)) {
  morir(
    `no existe ${ORIGEN}.\n`
    + '  Instala las dependencias primero: npm install --legacy-peer-deps'
  )
}

const version = JSON.parse(readFileSync(resolve(PAQUETE, 'package.json'), 'utf8')).version
const origen = readFileSync(ORIGEN, 'latin1')
const huella = createHash('sha256').update(origen, 'latin1').digest('hex')

if (existsSync(SELLO) && existsSync(GLUE) && existsSync(WASM)) {
  const sello = JSON.parse(readFileSync(SELLO, 'utf8'))

  if (sello.huella === huella) {
    console.log(
      `opencv.js ${version} ya extraido (glue ${statSync(GLUE).size} B, `
      + `wasm ${statSync(WASM).size} B).`
    )
    process.exit(0)
  }
}

const encontrado = origen.match(DATA_URI)

if (!encontrado) {
  morir(
    'no aparece `wasmBinaryFile="data:application/octet-stream;base64,…"` en\n'
    + `  ${ORIGEN} (version ${version}).\n`
    + '  El paquete ha cambiado de forma de empaquetar el wasm — la rama 5.x lo\n'
    + '  incrusta como cadena binaria cruda, no como base64. Hay que revisar\n'
    + '  este script antes de subir de version.'
  )
}

if (!origen.includes(COLA_UMD)) {
  morir(
    'no aparece la cola del envoltorio UMD que hay que parchear en\n'
    + `  ${ORIGEN} (version ${version}).\n`
    + '  Sin ese parche el Module global del worker no llega a Emscripten y el\n'
    + '  wasm se pide contra la carpeta equivocada. Revisa este script.'
  )
}

const wasm = Buffer.from(encontrado[1], 'base64')

const glue = origen
  .replace(encontrado[0], 'wasmBinaryFile="opencv_js.wasm"')
  .replace(COLA_UMD, COLA_NUEVA)

mkdirSync(CARPETA, { recursive: true })
writeFileSync(WASM, wasm)
writeFileSync(GLUE, glue, 'latin1')
writeFileSync(SELLO, `${JSON.stringify({ version, huella, glue: glue.length, wasm: wasm.length }, null, 2)}\n`)

console.log(
  `opencv.js ${version} extraido → public/opencv/: `
  + `glue ${glue.length} B + wasm ${wasm.length} B `
  + `(el paquete son ${origen.length} B en un solo fichero).`
)
