/**
 * LA TUBERIA ORB, y nada mas que la tuberia ORB.
 *
 * Aqui vive el unico codigo que toca `cv`: extraer descriptores de una imagen en
 * gris y emparejar un bloque contra otros. **No sabe de workers, ni de red, ni
 * de canvas, ni de base64.** Lo unico que recibe de fuera es el propio `cv` y
 * los parametros, los dos por argumento.
 *
 * ESO NO ES PURISMO, ES LO QUE PERMITE PROBARLO DE VERDAD. `cv` entra por
 * parametro porque tiene dos procedencias legitimas y ninguna es un `import`:
 *
 *  - en el movil lo pone `importScripts('opencv.js')` en `self.cv`, que es la
 *    unica forma de cargar el wasm desde `public/` sin empaquetar 10,8 MB en un
 *    chunk (ver `scripts/extraer-opencv-wasm.mjs`);
 *  - en Vitest lo trae `@techstark/opencv-js` desde `node_modules`, **el mismo
 *    paquete y la misma version** de la que sale el fichero de `public/`.
 *
 * Asi que la suite ejecuta ORB **de verdad**, con el wasm de verdad, y no un
 * doble. Ver `tests/unit/services/cardOrb.spec.js`, que explica por que doblar
 * opencv.js aqui habria sido escribir un test que no prueba nada.
 *
 * LAS DOS TRAMPAS QUE HAY QUE TENER DELANTE AL TOCAR ESTE FICHERO:
 *
 * 1. **Cada `Mat`, `KeyPointVector`, `DMatchVector` y `DMatchVectorVector` se
 *    libera a mano.** opencv.js no recolecta los objetos de C++. En un bucle de
 *    camara que da dos vueltas por segundo, olvidarse es quedarse sin memoria en
 *    un minuto y que el webview se muera sin decir nada. Todo lo que se crea se
 *    libera en el mismo `finally` en el que se creo.
 *
 * 2. **El bloque no son solo descriptores.** Son `keypoints * 40` bytes que hay
 *    que partir por el sitio exacto: `keypoints*32` de descriptores (un `Mat`
 *    CV_8U de `keypoints` filas y **32** columnas) seguidos de `keypoints*8` de
 *    coordenadas (2 `float32` little-endian por keypoint). Un `Mat` con la forma
 *    equivocada **empareja sin quejarse y devuelve basura**, y partir el bloque
 *    por el sitio equivocado da exactamente ese sintoma. Por eso se lee y se
 *    escribe con `DataView` y `true` explicito, y no con un `Float32Array` sobre
 *    el buffer: aquel ademas exige alineacion a 4 y la rompe un `byteOffset`
 *    cualquiera.
 */

/** 32 B de descriptor + 8 B de coordenadas (2 float32 LE) por keypoint. */
export const BYTES_POR_KEYPOINT = 40
/** Los descriptores de ORB son de 32 bytes. El `Mat` tiene 32 COLUMNAS. */
export const BYTES_DE_DESCRIPTOR = 32

/**
 * Cuantos keypoints trae un bloque, o `null` si no tiene forma de bloque.
 *
 * Devuelve `null` en vez de lanzar porque quien pregunta suele ser quien decide
 * si tira la referencia: un bloque con longitud que no es multiplo de 40 es una
 * referencia corrupta, no una excepcion del programa.
 */
export function keypointsDe (bloque) {
  if (!bloque || typeof bloque.length !== 'number') return null
  if (bloque.length === 0 || bloque.length % BYTES_POR_KEYPOINT !== 0) return null

  return bloque.length / BYTES_POR_KEYPOINT
}

/**
 * El detector y el emparejador, que se crean UNA vez y se reutilizan.
 *
 * `BFMatcher(NORM_HAMMING, crossCheck = false)`: el `false` no es un descuido.
 * Con `crossCheck` a `true` el emparejador ya filtra por su cuenta y devuelve un
 * solo vecino, con lo que la razon de Lowe —que compara el primero con el
 * segundo— se queda sin segundo. Son dos filtros alternativos y el medido es el
 * de Lowe.
 */
export function crearHerramientas (cv, parametros) {
  return {
    orb: new cv.ORB(parametros.nfeatures, parametros.escalaDePiramide, parametros.niveles),
    bf: new cv.BFMatcher(cv.NORM_HAMMING, false)
  }
}

export function soltarHerramientas (herramientas) {
  if (!herramientas) return
  if (herramientas.orb) herramientas.orb.delete()
  if (herramientas.bf) herramientas.bf.delete()
}

/**
 * Imagen en gris → el bloque de `keypoints * 40` bytes.
 *
 * `gris` es un `cv.Mat` CV_8U que se libera FUERA: esta funcion no es duena de
 * lo que le pasan.
 *
 * @param {object} cv
 * @param {object} orb
 * @param {object} gris  cv.Mat CV_8U
 * @returns {Uint8Array} el bloque, listo para viajar o para guardarse
 */
export function extraerBloque (cv, orb, gris) {
  const keypoints = new cv.KeyPointVector()
  const descriptores = new cv.Mat()
  const sinMascara = new cv.Mat()

  try {
    orb.detectAndCompute(gris, sinMascara, keypoints, descriptores)

    // `descriptores.rows` y no `keypoints.size()`: son el mismo numero, pero el
    // que manda sobre la longitud del bloque es el del `Mat`, que es de donde
    // se copian los bytes. Si algun dia dejaran de coincidir, el bloque seguiria
    // siendo coherente consigo mismo.
    const cuantos = descriptores.rows

    if (cuantos === 0) return new Uint8Array(0)

    const bloque = new Uint8Array(cuantos * BYTES_POR_KEYPOINT)

    bloque.set(descriptores.data.subarray(0, cuantos * BYTES_DE_DESCRIPTOR), 0)

    const vista = new DataView(bloque.buffer)

    for (let i = 0; i < cuantos; i++) {
      const punto = keypoints.get(i).pt
      const donde = cuantos * BYTES_DE_DESCRIPTOR + i * 8

      vista.setFloat32(donde, punto.x, true)
      vista.setFloat32(donde + 4, punto.y, true)
    }

    return bloque
  } finally {
    keypoints.delete()
    descriptores.delete()
    sinMascara.delete()
  }
}

/**
 * Bloque → `{ descriptores: cv.Mat, puntos: Float32Array }`.
 *
 * **Lo que devuelve hay que soltarlo con `soltarReferencia()`.**
 *
 * @returns {{descriptores: object, puntos: Float32Array, keypoints: number}|null}
 *          `null` si el bloque no tiene forma de bloque
 */
export function referenciaDeBloque (cv, bloque) {
  const cuantos = keypointsDe(bloque)

  if (cuantos === null) return null

  const descriptores = new cv.Mat(cuantos, BYTES_DE_DESCRIPTOR, cv.CV_8U)

  descriptores.data.set(bloque.subarray(0, cuantos * BYTES_DE_DESCRIPTOR))

  const puntos = new Float32Array(cuantos * 2)
  const vista = new DataView(bloque.buffer, bloque.byteOffset, bloque.byteLength)

  for (let i = 0; i < cuantos; i++) {
    const donde = cuantos * BYTES_DE_DESCRIPTOR + i * 8

    puntos[2 * i] = vista.getFloat32(donde, true)
    puntos[2 * i + 1] = vista.getFloat32(donde + 4, true)
  }

  return { descriptores, puntos, keypoints: cuantos }
}

export function soltarReferencia (referencia) {
  if (referencia && referencia.descriptores) referencia.descriptores.delete()
}

/**
 * La `ZONA_DE_ARTE` relativa, en pixeles de `ladoDeTrabajo`.
 *
 * **Se lee de `parametros` y no hay valor por defecto**, a proposito: si algun
 * dia la zona dejara de viajar en el mensaje `cargar`, esto revienta en vez de
 * contar el arte en silencio. Un `?? todo` aqui seria volver al margen diluido
 * sin que nadie se entere, que es justo el fallo que el M11 vino a arreglar.
 */
export function limitesDelArte (parametros) {
  const [ancho, alto] = parametros.ladoDeTrabajo
  const zona = parametros.zonaDeArte

  return {
    x0: zona.x[0] * ancho,
    x1: zona.x[1] * ancho,
    y0: zona.y[0] * alto,
    y1: zona.y[1] * alto
  }
}

/** Fuera del rectangulo del arte; el borde cuenta como arte. */
export function estaFueraDelArte (x, y, limites) {
  return x < limites.x0 || x > limites.x1 || y < limites.y0 || y > limites.y1
}

/**
 * Inliers de RANSAC de la consulta contra UNA referencia, con y sin el arte.
 *
 * La tuberia entera, y el orden importa: `knnMatch` con k=2 → razon de Lowe →
 * `findHomography(puntosDeLaConsulta, puntosDeLaReferencia, RANSAC)` → contar la
 * mascara. **El margen del plan esta definido sobre estos inliers**, no sobre
 * las parejas buenas: sin la homografia, dos cartas de la misma edicion con el
 * mismo marco dan casi las mismas parejas.
 *
 * Y desde el M11 se cuentan DOS veces: `inliers` es el total —hace falta para
 * diagnosticar— y `inliersFueraDelArte` deja fuera los que caen en la
 * `ZONA_DE_ARTE`, que es sobre lo que el escaner mide el margen. Dos impresiones
 * de la misma carta con el mismo arte empatan en esa zona y el empate diluye el
 * cociente hasta 1,14; fuera del arte la correcta gana 1,45-2,23.
 *
 * **Se mira la coordenada de la REFERENCIA, no la de la consulta**, porque es la
 * que esta en marco de carta: la consulta, sin rectificar, no lo esta —la carta
 * puede ocupar un tercio del fotograma y estar girada—, asi que su `y = 0,3` no
 * significa «arte». En una consulta rectificada las dos dan lo mismo (medido:
 * 83/60/3/0 contra 83/60/3/1), asi que no se pierde nada usando la unica que
 * siempre esta bien puesta.
 */
export function inliersEntre (cv, bf, consulta, referencia, parametros) {
  const parejas = new cv.DMatchVectorVector()
  const origen = []
  const destino = []

  try {
    bf.knnMatch(consulta.descriptores, referencia.descriptores, parejas, 2)

    for (let i = 0; i < parejas.size(); i++) {
      const par = parejas.get(i)

      if (par.size() >= 2) {
        const mejor = par.get(0)
        const segundo = par.get(1)

        if (mejor.distance < parametros.razonDeLowe * segundo.distance) {
          origen.push(consulta.puntos[2 * mejor.queryIdx], consulta.puntos[2 * mejor.queryIdx + 1])
          destino.push(
            referencia.puntos[2 * mejor.trainIdx],
            referencia.puntos[2 * mejor.trainIdx + 1]
          )
        }
      }

      par.delete()
    }
  } finally {
    parejas.delete()
  }

  const buenas = origen.length / 2

  if (buenas < parametros.minimoDeParejas) return { inliers: 0, inliersFueraDelArte: 0, buenas }

  const mo = cv.matFromArray(buenas, 1, cv.CV_32FC2, origen)
  const md = cv.matFromArray(buenas, 1, cv.CV_32FC2, destino)
  const mascara = new cv.Mat()
  const limites = limitesDelArte(parametros)
  let inliers = 0
  let inliersFueraDelArte = 0

  try {
    const homografia = cv.findHomography(mo, md, cv.RANSAC, parametros.umbralRansac, mascara)

    try {
      if (!homografia.empty()) {
        for (let i = 0; i < mascara.rows; i++) {
          if (!mascara.data[i]) continue

          inliers++

          // `destino` es la referencia, y va en el MISMO orden que la mascara:
          // las dos se llenaron en el bucle de Lowe de arriba.
          if (estaFueraDelArte(destino[2 * i], destino[2 * i + 1], limites)) {
            inliersFueraDelArte++
          }
        }
      }
    } finally {
      homografia.delete()
    }
  } finally {
    mo.delete()
    md.delete()
    mascara.delete()
  }

  return { inliers, inliersFueraDelArte, buenas }
}

/**
 * El bloque de la consulta contra una lista de referencias, ordenado.
 *
 * @param {object} cv
 * @param {object} herramientas       lo que devolvio `crearHerramientas()`
 * @param {Uint8Array} bloqueConsulta
 * @param {Array<{printingUuid: string, face?: string, bloque: Uint8Array}>} referencias
 * @param {object} parametros
 * @returns {Array<{printingUuid: string, face: string, inliers: number,
 *          inliersFueraDelArte: number, buenas: number}>}
 *          **ordenado por `inliersFueraDelArte`**, que es sobre lo que el
 *          escaner mide el margen desde el M11. El total viaja al lado para
 *          diagnosticar, pero no es quien ordena.
 */
export function emparejarBloques (cv, herramientas, bloqueConsulta, referencias, parametros) {
  const consulta = referenciaDeBloque(cv, bloqueConsulta)

  if (consulta === null) return []

  try {
    const salida = []

    for (const referencia of referencias ?? []) {
      const preparada = referenciaDeBloque(cv, referencia.bloque)

      // Una referencia corrupta se SALTA, no tumba el emparejamiento: el resto
      // de impresiones de esa carta siguen siendo utiles y la mala se volvera a
      // sembrar cuando alguien vacie su fila.
      if (preparada === null) continue

      try {
        const medida = inliersEntre(cv, herramientas.bf, consulta, preparada, parametros)

        salida.push({
          printingUuid: referencia.printingUuid,
          face: referencia.face ?? 'front',
          inliers: medida.inliers,
          inliersFueraDelArte: medida.inliersFueraDelArte,
          buenas: medida.buenas
        })
      } finally {
        soltarReferencia(preparada)
      }
    }

    // Ordena por los de FUERA del arte y desempata por el total: el ranking y el
    // margen se miden sobre la misma cifra o el escaner leeria un primero que no
    // es el que gana el cociente.
    salida.sort((a, b) => b.inliersFueraDelArte - a.inliersFueraDelArte || b.inliers - a.inliers)

    return salida
  } finally {
    soltarReferencia(consulta)
  }
}
