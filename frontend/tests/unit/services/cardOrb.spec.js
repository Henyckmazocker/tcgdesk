/**
 * `services/cardOrb.js` + `services/orbNucleo.js`, **con opencv.js de verdad**.
 *
 * ## LA DECISIÓN QUE EL PLAN PIDE QUE SE ESCRIBA: opencv.js NO SE DOBLA
 *
 * El plan avisa de que en Vitest hay que elegir entre cargar opencv.js o
 * doblarlo, y de que doblarlo deja `cardOrb.js` **sin cubrir de verdad** — que
 * es exactamente lo que dejó pasar el bug del `deckId` de `/decks`: una suite
 * verde sobre un doble que no se parecía al original.
 *
 * Aquí se carga de verdad. `@techstark/opencv-js` es el mismo paquete del
 * `package.json` del que `scripts/extraer-opencv-wasm.mjs` saca el fichero que
 * se sirve en el móvil, así que la suite ejecuta **el mismo wasm, la misma
 * versión y el mismo `orbNucleo.js`** que el APK. Cuesta ~150 ms de arranque y
 * paga por sí solo el fallo que ningún doble encontraría: un `cv.Mat` con la
 * forma equivocada **empareja sin quejarse y devuelve basura**, y eso solo lo ve
 * quien empareja de verdad.
 *
 * **Lo que sí se dobla es el HILO, y solo el hilo.** jsdom no tiene `Worker`, ni
 * `OffscreenCanvas`, ni `createImageBitmap`: ninguno de los tres es ORB. El
 * doble de abajo habla el mismo protocolo que `workers/orb.worker.js` y llama al
 * mismo núcleo; lo único que sustituye es el decodificador de imagen del
 * navegador, y por eso las láminas de prueba entran como **PGM** (`P5`, píxeles
 * en crudo) en vez de como JPEG.
 *
 * ## POR QUÉ LAS LÁMINAS SON SINTÉTICAS Y NO HAY FIXTURA DE IMAGEN
 *
 * Por lo mismo que `recorte_37x53.ppm`: las imágenes de Scryfall son copyright
 * de Wizards y [[TCGDesk/Fuentes de Datos]] prohíbe guardar derivados
 * (`ScryfallImageDownloader.php:20-28`). Las de aquí se generan con aritmética
 * entera y `% 256`, así que salen iguales en cualquier máquina y no pesan un
 * byte en el repo. Están medidas: a 488x680 ORB **satura los 700 keypoints** con
 * ellas, igual que con las cartas reales del M0(b).
 *
 * ## LA TRAMPA QUE COSTÓ UNA TARDE, Y QUE ESTE FICHERO TAMBIÉN TIENE QUE ESQUIVAR
 *
 * `cv` es un THENABLE: el `Module` de Emscripten define `then`. Un `await cv` o
 * un `resolve(cv)` meten al motor en un bucle infinito de microtareas —100 % de
 * CPU, memoria plana, ni el depurador interrumpe—. Por eso `opencvListo()` de
 * abajo resuelve con `true` y nunca con `cv`.
 */

import { createRequire } from 'node:module'

import { afterAll, beforeAll, beforeEach, describe, expect, it } from 'vitest'

import {
  LADO_DE_TRABAJO,
  MARGEN_MINIMO,
  MINIMO_DE_PAREJAS,
  NFEATURES,
  PARAMETROS,
  RAZON_DE_LOWE,
  ZONA_DE_ARTE,
  base64DeBloque,
  bloqueDeBase64,
  descriptoresDe,
  emparejar,
  keypointsDe,
  soltarOrb,
  usarHiloDeOrb
} from '@/services/cardOrb'

import {
  BYTES_POR_KEYPOINT,
  crearHerramientas,
  emparejarBloques,
  estaFueraDelArte,
  extraerBloque,
  limitesDelArte,
  referenciaDeBloque,
  soltarHerramientas,
  soltarReferencia
} from '@/services/orbNucleo'

import { impresionPorArte } from '@/stores/scan'

/**
 * OPENCV SE CARGA CON `require`, NO CON `import`, Y NO ES UN CAPRICHO.
 *
 * `import cv from '@techstark/opencv-js'` **cuelga Vitest para siempre**,
 * medido: el fichero se queda a 0 bytes de salida y el proceso hay que matarlo.
 * Es la misma trampa que costo el M0(a), por otra puerta — el transformador de
 * modulos de Vite hace `await` sobre los exports del modulo CommonJS, y esos
 * exports SON el `Module` de Emscripten, que define `then`. O sea: un thenable
 * esperado, que se resuelve consigo mismo, para siempre.
 *
 * `createRequire` no espera nada: devuelve el objeto tal cual. Y por lo mismo,
 * `opencvListo()` de aqui abajo resuelve con `true` y nunca con `cv`.
 */
const cv = createRequire(import.meta.url)('@techstark/opencv-js')

const [ANCHO, ALTO] = LADO_DE_TRABAJO

/** Espera al runtime del wasm. **Resuelve con `true`, nunca con `cv`.** */
function opencvListo() {
  return new Promise((resolve) => {
    if (cv.Mat) {
      resolve(true)

      return
    }

    const sondeo = setInterval(() => {
      if (!cv.Mat) return
      clearInterval(sondeo)
      resolve(true)
    }, 10)

    cv.onRuntimeInitialized = () => {
      clearInterval(sondeo)
      resolve(true)
    }
  })
}

/**
 * Una lámina sintética con detalle en cada píxel.
 *
 * Todo enteros y todo `% 256`: la misma en cualquier máquina. La `semilla` mueve
 * a la vez la fase de las bandas y la textura de los bloques, así que dos
 * semillas distintas son dos imágenes que ORB no confunde.
 */
function lamina(semilla) {
  const px = new Uint8Array(ANCHO * ALTO)

  for (let y = 0; y < ALTO; y++) {
    for (let x = 0; x < ANCHO; x++) {
      const bandas = (x * 7 + y * 3 + semilla * 29) % 61 < 30 ? 40 : 210
      const textura = ((x >> 3) * 13) ^ ((y >> 3) * 5) ^ (semilla * 17)

      px[y * ANCHO + x] = (bandas + (textura % 256)) >> 1
    }
  }

  return px
}

/**
 * La misma lámina «fotografiada»: un píxel de desplazamiento, menos contraste y
 * algo de velo. Es lo que separa «la misma impresión» de «el mismo fichero».
 */
function comoUnaFoto(px) {
  const salida = new Uint8Array(ANCHO * ALTO)

  for (let y = 0; y < ALTO; y++) {
    for (let x = 0; x < ANCHO; x++) {
      const sx = Math.min(ANCHO - 1, x + 1)
      const sy = Math.min(ALTO - 1, y + 1)

      salida[y * ANCHO + x] = Math.min(255, Math.round((px[sy * ANCHO + sx] * 9) / 10) + 8)
    }
  }

  return salida
}

/** Píxeles en gris → PGM binario (`P5`), que es lo que el hilo falso decodifica. */
function pgm(px) {
  const cabecera = new TextEncoder().encode(`P5\n${ANCHO} ${ALTO}\n255\n`)
  const salida = new Uint8Array(cabecera.length + px.length)

  salida.set(cabecera, 0)
  salida.set(px, cabecera.length)

  return salida
}

function matDePgm(bytes) {
  const texto = new TextDecoder('latin1').decode(bytes.subarray(0, 32))
  const cabecera = texto.match(/^P5\s+(\d+)\s+(\d+)\s+255\s/)

  if (!cabecera) throw new Error('el hilo falso solo entiende PGM P5')

  const ancho = Number(cabecera[1])
  const alto = Number(cabecera[2])
  const mat = new cv.Mat(alto, ancho, cv.CV_8U)

  mat.data.set(bytes.subarray(cabecera[0].length, cabecera[0].length + ancho * alto))

  return mat
}

/**
 * El doble del hilo: mismo protocolo que `workers/orb.worker.js` y **el mismo
 * núcleo**. Lo único que no es igual es que aquí la imagen llega ya decodificada
 * (PGM) porque jsdom no tiene `createImageBitmap`.
 */
class HiloFalsoDeOrb {
  constructor() {
    this.onmessage = null
    this.onerror = null
    this.herramientas = null
    this.parametros = null
    this.terminado = false
  }

  postMessage(mensaje) {
    Promise.resolve().then(() => {
      if (this.terminado) return

      try {
        this.onmessage({ data: { id: mensaje.id, ok: true, ...this.atender(mensaje) } })
      } catch (e) {
        this.onmessage({ data: { id: mensaje.id, ok: false, error: String(e.message || e) } })
      }
    })
  }

  atender(mensaje) {
    if (mensaje.tipo === 'cargar') {
      this.parametros = mensaje.parametros
      this.herramientas = crearHerramientas(cv, this.parametros)

      return {}
    }

    if (mensaje.tipo === 'extraer') {
      const gris = matDePgm(new Uint8Array(mensaje.imagen))

      try {
        const bloque = extraerBloque(cv, this.herramientas.orb, gris)

        return { bloque: bloque.buffer }
      } finally {
        gris.delete()
      }
    }

    if (mensaje.tipo === 'emparejar') {
      const referencias = mensaje.referencias.map((r) => ({
        printingUuid: r.printingUuid,
        face: r.face,
        bloque: new Uint8Array(r.bloque)
      }))

      return {
        ranking: emparejarBloques(
          cv,
          this.herramientas,
          new Uint8Array(mensaje.consulta),
          referencias,
          this.parametros
        )
      }
    }

    if (mensaje.tipo === 'soltar') {
      soltarHerramientas(this.herramientas)
      this.herramientas = null

      return {}
    }

    throw new Error(`orden desconocida: ${mensaje.tipo}`)
  }

  terminate() {
    this.terminado = true
    soltarHerramientas(this.herramientas)
    this.herramientas = null
  }
}

let herramientas = null

beforeAll(async () => {
  await opencvListo()
  herramientas = crearHerramientas(cv, PARAMETROS)
}, 60000)

afterAll(() => {
  soltarHerramientas(herramientas)
  soltarOrb()
})

beforeEach(() => {
  usarHiloDeOrb(() => new HiloFalsoDeOrb())
})

describe('parámetros', () => {
  it('son los que fijó el M0(b) y viajan enteros al worker', () => {
    expect(NFEATURES).toBe(700)
    expect(RAZON_DE_LOWE).toBe(0.78)
    expect(MARGEN_MINIMO).toBe(1.5)
    expect(LADO_DE_TRABAJO).toEqual([488, 680])
    expect(MINIMO_DE_PAREJAS).toBe(4)
    // Relativa, nunca en píxeles: la convierte quien conoce `LADO_DE_TRABAJO`.
    expect(ZONA_DE_ARTE).toEqual({ y: [0.115, 0.53], x: [0.075, 0.925] })

    // El worker NO tiene copia propia: si algún parámetro dejara de viajar, allí
    // valdría `undefined` y ORB correría con otra calibración sin decir nada.
    expect(PARAMETROS).toEqual({
      nfeatures: 700,
      razonDeLowe: 0.78,
      ladoDeTrabajo: [488, 680],
      escalaDePiramide: 1.2,
      niveles: 8,
      umbralRansac: 6.0,
      minimoDeParejas: 4,
      // La zona del arte VIAJA, que es lo que el M11 aprendió otra vez del
      // dHash: un worker con su propia copia mediría el margen sobre otra franja
      // y certificaría ediciones distintas sin que nadie lo notara.
      zonaDeArte: { y: [0.115, 0.53], x: [0.075, 0.925] }
    })
  })
})

describe('el bloque de descriptores', () => {
  it('mide keypoints * 40 y satura en NFEATURES sobre una lámina 488x680', () => {
    const gris = new cv.Mat(ALTO, ANCHO, cv.CV_8U)

    gris.data.set(lamina(1))

    try {
      const bloque = extraerBloque(cv, herramientas.orb, gris)

      expect(keypointsDe(bloque)).toBe(NFEATURES)
      expect(bloque.length).toBe(NFEATURES * BYTES_POR_KEYPOINT)
      expect(bloque.length).toBe(NFEATURES * 40)
    } finally {
      gris.delete()
    }
  })

  it('se parte en 32 columnas de descriptor y 2 float32 LE de coordenadas', () => {
    const gris = new cv.Mat(ALTO, ANCHO, cv.CV_8U)

    gris.data.set(lamina(1))

    let bloque = null

    try {
      bloque = extraerBloque(cv, herramientas.orb, gris)
    } finally {
      gris.delete()
    }

    const referencia = referenciaDeBloque(cv, bloque)

    try {
      // LA FORMA DEL `Mat` ES EL FALLO MÁS CARO DEL HITO: uno con las columnas
      // equivocadas empareja sin quejarse y devuelve basura.
      expect(referencia.descriptores.rows).toBe(NFEATURES)
      expect(referencia.descriptores.cols).toBe(32)
      expect(referencia.descriptores.type()).toBe(cv.CV_8U)

      // Y las coordenadas tienen que caer DENTRO de la lámina. Si el bloque se
      // partiera por el sitio equivocado, aquí saldrían números absurdos.
      expect(referencia.puntos.length).toBe(NFEATURES * 2)

      for (let i = 0; i < NFEATURES; i++) {
        expect(referencia.puntos[2 * i]).toBeGreaterThanOrEqual(0)
        expect(referencia.puntos[2 * i]).toBeLessThan(ANCHO)
        expect(referencia.puntos[2 * i + 1]).toBeGreaterThanOrEqual(0)
        expect(referencia.puntos[2 * i + 1]).toBeLessThan(ALTO)
      }
    } finally {
      soltarReferencia(referencia)
    }
  })

  it('un bloque con longitud que no es múltiplo de 40 no es un bloque', () => {
    expect(keypointsDe(new Uint8Array(0))).toBeNull()
    expect(keypointsDe(new Uint8Array(32))).toBeNull()
    expect(keypointsDe(new Uint8Array(699 * 32))).toBeNull()
    expect(keypointsDe(null)).toBeNull()
    expect(keypointsDe(new Uint8Array(40))).toBe(1)

    // Y LA LONGITUD SOLA NO ES UNA PRUEBA, que es la razón de que el backend
    // valide contra los `keypoints` DECLARADOS y no contra el módulo 40:
    // `700 * 32` —el contrato viejo, el que se enmendó— son 22.400 bytes, que
    // son exactamente 560 keypoints del contrato nuevo. Pasa la forma y son
    // descriptores sin coordenadas.
    expect(keypointsDe(new Uint8Array(700 * 32))).toBe(560)

    // Y no se convierte en un `Mat` a la fuerza: devolver `null` es lo que hace
    // que `emparejar()` se salte esa referencia en vez de emparejar basura.
    expect(referenciaDeBloque(cv, new Uint8Array(41))).toBeNull()
  })
})

describe('base64', () => {
  it('va y vuelve intacto con un bloque del tamaño real (28.000 B)', () => {
    const bloque = new Uint8Array(NFEATURES * BYTES_POR_KEYPOINT)

    for (let i = 0; i < bloque.length; i++) bloque[i] = (i * 31) % 256

    // 28.000 bytes de un tirón desbordan la pila con
    // `String.fromCharCode(...bytes)`, y el fallo solo aparece con las cartas de
    // muchos keypoints. Por eso va troceado, y por eso este test usa el tamaño
    // de producción y no diez bytes.
    const texto = base64DeBloque(bloque)

    expect(texto).toMatch(/^[A-Za-z0-9+/]+=*$/)
    expect(bloqueDeBase64(texto)).toEqual(bloque)
  })
})

describe('emparejar', () => {
  /** Los tres bloques del caso: la foto, su impresión y otra distinta. */
  function bloquesDelCaso() {
    const hacer = (px) => {
      const gris = new cv.Mat(ALTO, ANCHO, cv.CV_8U)

      gris.data.set(px)

      try {
        return extraerBloque(cv, herramientas.orb, gris)
      } finally {
        gris.delete()
      }
    }

    const suya = lamina(1)

    return {
      consulta: hacer(comoUnaFoto(suya)),
      suya: hacer(suya),
      otra: hacer(lamina(2))
    }
  }

  it('HECHO CUANDO: el par conocido gana, y con margen', () => {
    const { consulta, suya, otra } = bloquesDelCaso()

    const ranking = emparejarBloques(
      cv,
      herramientas,
      consulta,
      [
        { printingUuid: 'otra-impresion', bloque: otra },
        { printingUuid: 'su-impresion', bloque: suya }
      ],
      PARAMETROS
    )

    expect(ranking).toHaveLength(2)
    expect(ranking[0].printingUuid).toBe('su-impresion')
    expect(ranking[0].inliers).toBeGreaterThan(ranking[1].inliers)

    // El margen del plan, sobre INLIERS de RANSAC y no sobre parejas buenas.
    // Aquí se comprueba que el par conocido lo supera de sobra; **quién aplica
    // el listón es el M3**, no este fichero.
    expect(ranking[0].inliers / Math.max(1, ranking[1].inliers))
      .toBeGreaterThanOrEqual(MARGEN_MINIMO)
  })

  it('dos referencias indistinguibles empatan: margen 1,0 y NADIE certifica', () => {
    const { consulta, suya } = bloquesDelCaso()

    // El empate técnico del plan, en su forma más pura: la misma referencia bajo
    // dos uuid. Es el caso cuya salida correcta es `PrintingSelect.vue`, y lo que
    // este hito tiene que garantizar es que `emparejar()` lo DEVUELVE como
    // empate en vez de inventarse un ganador.
    const ranking = emparejarBloques(
      cv,
      herramientas,
      consulta,
      [
        { printingUuid: 'una', bloque: suya },
        { printingUuid: 'otra', bloque: suya.slice() }
      ],
      PARAMETROS
    )

    expect(ranking[0].inliers).toBe(ranking[1].inliers)
    expect(ranking[0].inliers / ranking[1].inliers).toBeLessThan(MARGEN_MINIMO)
  })

  it('se salta las referencias corruptas en vez de emparejar basura', () => {
    const { consulta, suya } = bloquesDelCaso()

    const ranking = emparejarBloques(
      cv,
      herramientas,
      consulta,
      [
        { printingUuid: 'corrupta', bloque: new Uint8Array(1234) },
        { printingUuid: 'buena', bloque: suya }
      ],
      PARAMETROS
    )

    expect(ranking.map((r) => r.printingUuid)).toEqual(['buena'])
  })
})

// ==========================================================================
// M11 — el margen se mide FUERA DEL ARTE
// ==========================================================================

/**
 * Los keypoints de un bloque, sueltos. **Leer y volver a escribir un bloque es
 * lo que permite montar el caso del M11 sin una foto**: una impresión que
 * comparte el arte con otra y no comparte el marco es, en descriptores,
 * exactamente eso —los mismos keypoints en la zona del cuadro y otros distintos
 * alrededor—. Con láminas enteras no sale: cambiar un tercio de la imagen mueve
 * también qué keypoints del arte sobreviven al `retainBest` de los 700, y el
 * empate del arte —que es LO QUE HAY QUE REPRODUCIR— se deshace solo.
 */
function keypointsDelBloque(bloque) {
  const cuantos = keypointsDe(bloque)
  const vista = new DataView(bloque.buffer, bloque.byteOffset, bloque.byteLength)
  const salida = []

  for (let i = 0; i < cuantos; i++) {
    salida.push({
      descriptor: bloque.subarray(i * 32, (i + 1) * 32),
      x: vista.getFloat32(cuantos * 32 + i * 8, true),
      y: vista.getFloat32(cuantos * 32 + i * 8 + 4, true)
    })
  }

  return salida
}

/** Y al revés: keypoints sueltos → un bloque con la forma del contrato. */
function bloqueDeKeypoints(keypoints) {
  const cuantos = keypoints.length
  const bloque = new Uint8Array(cuantos * BYTES_POR_KEYPOINT)
  const vista = new DataView(bloque.buffer)

  keypoints.forEach((k, i) => {
    bloque.set(k.descriptor, i * 32)
    vista.setFloat32(cuantos * 32 + i * 8, k.x, true)
    vista.setFloat32(cuantos * 32 + i * 8 + 4, k.y, true)
  })

  return bloque
}

describe('el margen se mide FUERA DEL ARTE (M11)', () => {
  /** El bloque de una lámina, a 488x680 y con el mismo ORB de producción. */
  function bloqueDe(px) {
    const gris = new cv.Mat(ALTO, ANCHO, cv.CV_8U)

    gris.data.set(px)

    try {
      return extraerBloque(cv, herramientas.orb, gris)
    } finally {
      gris.delete()
    }
  }

  const caeEnElArte = (k) => !estaFueraDelArte(k.x, k.y, limitesDelArte(PARAMETROS))

  it('la zona se convierte con LADO_DE_TRABAJO, no con la imagen de entrada', () => {
    const limites = limitesDelArte(PARAMETROS)

    expect(limites).toEqual({
      x0: 0.075 * ANCHO,
      x1: 0.925 * ANCHO,
      y0: 0.115 * ALTO,
      y1: 0.53 * ALTO
    })

    // El borde cuenta como arte; lo de fuera, fuera. Un keypoint en el bloque de
    // coleccionista (abajo) y otro en el título (arriba) son los dos de fuera.
    expect(estaFueraDelArte(ANCHO / 2, ALTO / 2, limites)).toBe(false)
    expect(estaFueraDelArte(limites.x0, limites.y0, limites)).toBe(false)
    expect(estaFueraDelArte(ANCHO / 2, 0.05 * ALTO, limites)).toBe(true)
    expect(estaFueraDelArte(ANCHO / 2, 0.95 * ALTO, limites)).toBe(true)
    expect(estaFueraDelArte(0.02 * ANCHO, ALTO / 2, limites)).toBe(true)
  })

  /**
   * `RTR 226` contra `C16 247`, en miniatura.
   *
   * La misma carta con el MISMO arte y distinto marco: el confundidor lleva los
   * keypoints del cuadro IDÉNTICOS —no parecidos: los mismos bytes— y los de
   * fuera cambiados de la mitad inferior para abajo, que es donde el marco 2015
   * coloca el texto de otra manera.
   */
  function elCasoDeLaLinternaCromatica() {
    const suya = lamina(1)
    const consulta = bloqueDe(comoUnaFoto(suya))
    const rtr = bloqueDe(suya)
    const propios = keypointsDelBloque(rtr)
    const ajenos = keypointsDelBloque(bloqueDe(lamina(2)))
    const CORTE = 0.75 * ALTO

    const c16 = bloqueDeKeypoints([
      ...propios.filter(caeEnElArte), //                        el arte, clavado
      ...propios.filter((k) => !caeEnElArte(k) && k.y < CORTE), // marco compartido
      ...ajenos.filter((k) => !caeEnElArte(k) && k.y >= CORTE) //  y lo que cambia
    ])

    return { consulta, rtr, c16 }
  }

  it('HECHO CUANDO: dos que empatan en el arte y difieren fuera de él SE SEPARAN', () => {
    const { consulta, rtr, c16 } = elCasoDeLaLinternaCromatica()

    const ranking = emparejarBloques(
      cv,
      herramientas,
      consulta,
      [
        { printingUuid: 'c16-247', bloque: c16 },
        { printingUuid: 'rtr-226', bloque: rtr }
      ],
      PARAMETROS
    )

    const [rtr226, c16247] = ['rtr-226', 'c16-247'].map((uuid) =>
      ranking.find((r) => r.printingUuid === uuid)
    )
    const enElArte = (r) => r.inliers - r.inliersFueraDelArte

    // 1. EL ARTE EMPATA, que es el diluyente entero. Ninguna de las dos corona a
    //    la otra por el cuadro, en ningún sentido —y aquí el confundidor incluso
    //    saca alguno más, igual que `C16 247` sacaba 85 contra 83—.
    expect(enElArte(rtr226)).toBeGreaterThan(20)
    expect(enElArte(c16247)).toBeGreaterThan(20)
    expect(enElArte(rtr226) / enElArte(c16247)).toBeLessThan(MARGEN_MINIMO)
    expect(enElArte(c16247) / enElArte(rtr226)).toBeLessThan(MARGEN_MINIMO)

    // 2. Y POR ESO EL TOTAL NO CERTIFICA: el empate del arte hunde el cociente
    //    por debajo del listón con la impresión correcta delante. Es el 1,14 de
    //    la medida real, que es el fallo que este hito vino a arreglar.
    expect(rtr226.inliers).toBeGreaterThan(c16247.inliers)
    expect(rtr226.inliers / c16247.inliers).toBeLessThan(MARGEN_MINIMO)

    // 3. FUERA DEL ARTE SE SEPARAN, y con eso ya hay veredicto. En la medida real
    //    fueron 1,45 / 1,87 / 2,23, coronando a `RTR 226` en las tres.
    expect(rtr226.inliersFueraDelArte / c16247.inliersFueraDelArte)
      .toBeGreaterThanOrEqual(MARGEN_MINIMO)

    // 4. Y el ranking sale ordenado por esa cifra, no por el total.
    expect(ranking[0].printingUuid).toBe('rtr-226')
    expect(impresionPorArte(ranking)?.printingUuid).toBe('rtr-226')
  })

  it('HECHO CUANDO: menos de 4 inliers fuera del arte NO certifican, pase lo que pase con el cociente', () => {
    // EL ARTEFACTO QUE ESTO TAPA, medido: rankeando por la franja «marco + borde
    // + coleccionista» —donde ORB pone 1 o 2 inliers sobre 147— ganaban
    // impresiones EQUIVOCADAS en 2 de 3 consultas, con 3 inliers y margen alto.
    // Cuatro es el mínimo que define una homografía; por debajo, el cociente es
    // aritmética sobre ruido.
    expect(
      impresionPorArte([
        { printingUuid: 'j25-151', inliers: 91, inliersFueraDelArte: 3 },
        { printingUuid: 'rtr-226', inliers: 147, inliersFueraDelArte: 1 }
      ])
    ).toBeNull()

    // Ni siquiera cuando NO hay segundo contra el que medir y el margen es
    // infinito: el suelo se mira antes que el cociente.
    expect(impresionPorArte([{ printingUuid: 'j25-151', inliers: 91, inliersFueraDelArte: 3 }]))
      .toBeNull()

    // Con 4 y el mismo cociente, sí. El listón no se ha movido: 4 / 1 = 4,0.
    expect(
      impresionPorArte([
        { printingUuid: 'j25-151', inliers: 91, inliersFueraDelArte: 4 },
        { printingUuid: 'rtr-226', inliers: 147, inliersFueraDelArte: 1 }
      ])?.printingUuid
    ).toBe('j25-151')

    expect(MINIMO_DE_PAREJAS).toBe(4)
  })
})

describe('el contrato de cardOrb', () => {
  it('descriptoresDe() devuelve el bloque de una imagen', async () => {
    const bloque = await descriptoresDe(pgm(lamina(1)))

    expect(bloque).toBeInstanceOf(Uint8Array)
    expect(keypointsDe(bloque)).toBe(NFEATURES)
  })

  it('descriptoresDe() acepta también base64, que es lo que da captureSample()', async () => {
    const enBytes = await descriptoresDe(pgm(lamina(1)))
    const enBase64 = await descriptoresDe(base64DeBloque(pgm(lamina(1))))

    expect(enBase64).toEqual(enBytes)
  })

  it('emparejar() lee las referencias tal como las manda `scan_orb_refs`', async () => {
    const consulta = await descriptoresDe(pgm(comoUnaFoto(lamina(1))))
    const suya = await descriptoresDe(pgm(lamina(1)))
    const otra = await descriptoresDe(pgm(lamina(2)))

    // `orb` en base64 y `face`, que es literalmente la forma de la respuesta del
    // backend. Si `cardOrb` esperara bytes, la siembra del M2 y el
    // emparejamiento del M3 hablarían idiomas distintos.
    const ranking = await emparejar(consulta, [
      { printingUuid: 'otra-impresion', face: 'front', orb: base64DeBloque(otra) },
      { printingUuid: 'su-impresion', face: 'front', orb: base64DeBloque(suya) }
    ])

    expect(ranking[0].printingUuid).toBe('su-impresion')
    expect(ranking[0].face).toBe('front')
  })

  it('emparejar() sin referencias sembradas devuelve vacío y NO toca el hilo', async () => {
    const consulta = await descriptoresDe(pgm(lamina(1)))

    expect(await emparejar(consulta, [])).toEqual([])
    expect(await emparejar(consulta, [{ printingUuid: 'x', orb: null }])).toEqual([])
    expect(await emparejar(null, [{ printingUuid: 'x', orb: base64DeBloque(consulta) }])).toEqual([])
  })
})
