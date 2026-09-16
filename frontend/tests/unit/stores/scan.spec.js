import { beforeEach, describe, expect, it, vi } from 'vitest'
import { createPinia, setActivePinia } from 'pinia'

import { apiCall, imagenBytes } from '@/services/api'
import { MARGEN_MINIMO, NFEATURES, base64DeBloque, descriptoresDe, emparejar } from '@/services/cardOrb'
import { POR_DEFECTO } from '@/constants/collection'
import {
  FUENTES_DE_CERTEZA,
  MAXIMO_FILAS,
  acabadosDe,
  claveDeEscritura,
  claveDeLectura,
  esFuenteDeCerteza,
  finishParaImpresion,
  impresionPorArte,
  MAXIMO_DE_IMPRESIONES_PARA_ORB,
  precioDe,
  useScanStore
} from '@/stores/scan'

import scanFixture from '../../fixtures/scan_resolve.json'
import altaFixture from '../../fixtures/collection_add.json'
import altaDeseoFixture from '../../fixtures/collection_add_wishlist.json'
import altaMazoFixture from '../../fixtures/deck_card_add.json'
import listaMazosFixture from '../../fixtures/deck_list.json'

/**
 * `stores/scan.js` — el escáner por cámara.
 *
 * **Este fichero sustituye a lo que no se puede pulsar.** El `*Hecho cuando:*`
 * del hito exige un móvil con la cámara apuntando a una carta tres veces
 * seguidas, y eso no lo monta Vitest; lo que sí es lógica pura, y lo que decide
 * si la feature sirve o arruina una colección, es **la deduplicación**:
 *
 *  - Tres lecturas iguales son UNA fila y UNA petición (`claveDeLectura`).
 *  - Dos lecturas distintas de la misma carta son UNA fila (`fusionarPorImpresion`).
 *  - Una fila ya escrita NO se vuelve a escribir sola (`escritas`), y sí se
 *    escribe cuando el usuario pide el `+1`.
 *
 * Los tres tests que llevan «HECHO CUANDO» en el nombre son la traducción
 * literal de las tres frases del hito.
 *
 * Y una trampa del contrato que este fichero vigila expresamente, porque el
 * proyecto ya la ha pagado dos veces (`deckId` en `/decks`, `hasNonfoil` en
 * `DeckCardSearch`): **`finishes` habla de `{foil, nonfoil, etched}` y `priceEur`
 * de `{normal, foil, etched}`**. Son dos vocabularios distintos para la misma
 * cosa y leerlos cruzados no da error, da un precio equivocado.
 */

vi.mock('@/services/api', () => ({
  apiCall: vi.fn(),
  catalogGet: vi.fn(),
  imagenBytes: vi.fn()
}))

/**
 * De `cardOrb` se dobla UNA función y el resto es el de verdad.
 *
 * `descriptoresDe()` es lo único que necesita un `Worker`, un `OffscreenCanvas`
 * y el wasm de opencv.js, y jsdom no tiene ninguno de los tres. Todo lo demás
 * —`NFEATURES`, `keypointsDe()`, `base64DeBloque()`— entra sin doblar, que es lo
 * que hace que el payload que se assertea abajo sea el que de verdad sale por la
 * red. **ORB de verdad se prueba en `tests/unit/services/cardOrb.spec.js`**, con
 * opencv.js cargado; aquí lo que se prueba es la orquestación.
 */
vi.mock('@/services/cardOrb', async (importOriginal) => ({
  ...(await importOriginal()),
  descriptoresDe: vi.fn(),
  emparejar: vi.fn()
}))

/** Copia profunda: el store muta lo que recibe y las fixturas son de todos. */
function fixtura(json) {
  return structuredClone(json)
}

/** Los seis veredictos capturados del backend, por su papel en la fixtura. */
const VEREDICTOS = fixtura(scanFixture).data.results
const EXACTA = 0 // Rampant Growth 2X2 155 — foil y nonfoil, paso 2, certeza `corner`
const ASUMIDA = 1 // Lightning Bolt — SOLO nonfoil, assumedPrinting, 71 impresiones, sin certeza
const MISMATCH = 2 // Lim-Dûl's Vault (ICE) 96 — dos candidatos
const NO_ENCONTRADA = 3
const LOCALIZADA = 4 // «Llanura» → Plains por el paso 3c, 910 impresiones, sin certeza
const APROXIMADA = 5 // «Tlanura» → Plains por el paso 5, misma carta y misma falta de certeza

/**
 * De qué veredicto se contesta cada lectura, por orden de llegada.
 *
 * Se resetea en cada test; si se agota, se repite el último — que es lo que hace
 * falta para simular «la misma carta una y otra vez».
 */
let cola = []

let store

/** Una lectura como la que devuelve `parsearLectura()`. */
function lectura(campos = {}) {
  return {
    name: 'Rampant Growth',
    setCode: '2X2',
    collectorNumber: '155',
    rarity: 'common',
    language: 'English',
    ...campos
  }
}

/** Las llamadas a `apiCall` de una acción concreta, en orden. */
function llamadasDe(accion) {
  return apiCall.mock.calls.filter(([a]) => a === accion).map(([, datos]) => datos)
}

/**
 * Detecta una lectura **dos veces**, que es lo que hace falta desde el M3b para
 * que salga del móvil: la primera vuelta solo la apunta y la segunda pregunta.
 *
 * Existe para que los tests que NO prueban el filtro no tengan que saber de él.
 * Los que sí lo prueban llaman a `store.detectar()` directamente y cuentan las
 * vueltas a mano.
 */
async function detectarConfirmando(lectura) {
  await store.detectar(lectura)

  return store.detectar(lectura)
}

beforeEach(() => {
  // Pinia REAL y no `createTestingPinia`: lo que se prueba son las acciones, y
  // con el `stubActions` por defecto no se ejecutaría ninguna. Además el store
  // del escáner escribe A TRAVÉS de los de colección y deseos, así que esos
  // tienen que ser los de verdad para que el payload que se asserta sea el que
  // sale por la red.
  setActivePinia(createPinia())
  store = useScanStore()
  cola = [EXACTA]

  apiCall.mockImplementation(async (accion, datos = {}) => {
    if (accion === 'scan_resolve') {
      return {
        status: 'success',
        message: 'Lecturas resueltas.',
        http_code: 200,
        data: {
          results: datos.lecturas.map((l) => {
            const indice = cola.length > 1 ? cola.shift() : cola[0]

            // El `id` del cliente vuelve INTACTO: es lo que empareja el
            // veredicto con su fila, y el backend no inventa otro.
            return { ...fixtura(VEREDICTOS)[indice], id: l.id }
          })
        }
      }
    }

    if (accion === 'collection_add') return fixtura(altaFixture)
    if (accion === 'deck_card_add') return fixtura(altaMazoFixture)
    if (accion === 'deck_list') return fixtura(listaMazosFixture)
    if (accion === 'deck_get') return { status: 'success', data: { conflicts: [] }, http_code: 200 }

    return { status: 'error', message: `sin doble para ${accion}`, http_code: 500 }
  })
})

describe('las dos traducciones del contrato', () => {
  it('lee `finishes` por sus claves reales (`nonfoil`, no `normal`)', () => {
    expect(acabadosDe({ foil: true, nonfoil: true, etched: false })).toEqual(['normal', 'foil'])
    expect(acabadosDe({ foil: false, nonfoil: true, etched: false })).toEqual(['normal'])
    expect(acabadosDe({ foil: false, nonfoil: false, etched: true })).toEqual(['etched'])
  })

  it('sin `finishes` ofrece los tres antes que dejar la carta sin acabado', () => {
    expect(acabadosDe(null)).toEqual(['normal', 'foil', 'etched'])
    expect(acabadosDe({ foil: false, nonfoil: false, etched: false })).toEqual([
      'normal',
      'foil',
      'etched'
    ])
  })

  it('el acabado de sesión manda SOLO si la impresión admite más de uno', () => {
    const dos = { foil: true, nonfoil: true, etched: false }
    const uno = { foil: false, nonfoil: true, etched: false }

    expect(finishParaImpresion(dos, 'foil')).toBe('foil')
    // El catálogo lo sabe mejor que el ajuste: esta carta no existe en foil.
    expect(finishParaImpresion(uno, 'foil')).toBe('normal')
    // Y si el ajuste pide algo que esa impresión no tiene, se cae al posible.
    expect(finishParaImpresion({ foil: true, nonfoil: false, etched: true }, 'normal')).toBe('foil')
  })

  it('lee `priceEur` por el acabado elegido, no el más alto', () => {
    const precios = { normal: 1.23, foil: 4.56, etched: null }

    expect(precioDe(precios, 'normal')).toBe(1.23)
    expect(precioDe(precios, 'foil')).toBe(4.56)
    expect(precioDe(precios, 'etched')).toBeNull()
    expect(precioDe(null, 'normal')).toBeNull()
  })

  it('la clave de escritura son las CUATRO dimensiones de la línea', () => {
    const fila = { printingUuid: 'u1', finish: 'normal', language: 'English', condition: 'NM' }

    expect(claveDeEscritura(fila)).toBe('u1|normal|English|NM')
    expect(claveDeEscritura({ ...fila, finish: 'foil' })).not.toBe(claveDeEscritura(fila))
    expect(claveDeEscritura({ ...fila, language: 'Spanish' })).not.toBe(claveDeEscritura(fila))
    expect(claveDeEscritura({ ...fila, condition: 'LP' })).not.toBe(claveDeEscritura(fila))
  })

  it('la clave de lectura ignora las mayúsculas que el OCR cambia entre fotogramas', () => {
    expect(claveDeLectura(lectura({ name: 'Rampant GroWth' }))).toBe(
      claveDeLectura(lectura({ name: 'rampant growth' }))
    )
    expect(claveDeLectura(lectura({ collectorNumber: '155' }))).not.toBe(
      claveDeLectura(lectura({ collectorNumber: '156' }))
    )
  })
})

describe('los ajustes de sesión', () => {
  it('arrancan en POR_DEFECTO y con destino colección', () => {
    expect(store.ajustes.destino).toBe('coleccion')
    expect(store.ajustes.finish).toBe(POR_DEFECTO.finish)
    expect(store.ajustes.language).toBe(POR_DEFECTO.language)
    expect(store.ajustes.condition).toBe(POR_DEFECTO.condition)
    expect(store.destinoListo).toBe(true)
  })

  it('«un mazo» sin mazo elegido NO es un destino listo', () => {
    store.fijarDestino('mazo')

    expect(store.destinoEsMazo).toBe(true)
    expect(store.mazoDestino).toBeNull()
    expect(store.destinoListo).toBe(false)

    store.fijarDestino('mazo', 17)

    expect(store.ajustes.destino).toEqual({ mazo: 17 })
    expect(store.destinoListo).toBe(true)
  })

  it('`empezarSesion()` olvida lo escrito pero CONSERVA los ajustes', async () => {
    store.fijarDestino('deseos')
    store.fijarDimension('condition', 'LP')
    await detectarConfirmando(lectura())
    await store.escribir(store.filas[0])

    expect(store.escritas.size).toBe(1)

    store.empezarSesion()

    expect(store.filas).toEqual([])
    expect(store.escritas.size).toBe(0)
    expect(store.ajustes.destino).toBe('deseos')
    expect(store.ajustes.condition).toBe('LP')
  })

  it('`limpiarFilas()` vacía el menú y NO la memoria: la colección no se deshace', async () => {
    await detectarConfirmando(lectura())
    await store.escribir(store.filas[0])

    store.limpiarFilas()

    expect(store.filas).toEqual([])
    expect(store.escritas.size).toBe(1)
  })

  it('cambiar el acabado de sesión no reescribe las filas ya resueltas', async () => {
    await detectarConfirmando(lectura())
    const antes = store.filas[0].finish

    store.fijarDimension('finish', 'foil')

    expect(store.filas[0].finish).toBe(antes)
  })

  /**
   * EL IDIOMA SE RECUERDA ENTRE SESIONES, y los otros dos ajustes NO.
   *
   * No es comodidad: `language` está dentro del `UNIQUE KEY uq_item`, así que un
   * ajuste que se reinicia a `English` al abrir la app parte en dos la colección
   * de quien escanea en español —dos líneas de la misma carta— sin un solo
   * error. Cada `it` de abajo monta una pinia nueva, que es lo que en la app es
   * cerrar y volver a abrir.
   */
  it('el idioma se guarda y sigue puesto en la sesión siguiente', () => {
    store.fijarDimension('language', 'Spanish')

    expect(localStorage.getItem('tcgdesk_scan_language')).toBe('Spanish')

    setActivePinia(createPinia())

    expect(useScanStore().ajustes.language).toBe('Spanish')
  })

  it('pero el acabado y el estado NO se guardan: son de la sesión', () => {
    store.fijarDimension('finish', 'foil')
    store.fijarDimension('condition', 'LP')

    setActivePinia(createPinia())

    const otro = useScanStore()

    expect(otro.ajustes.finish).toBe(POR_DEFECTO.finish)
    expect(otro.ajustes.condition).toBe(POR_DEFECTO.condition)
  })

  it('un idioma guardado que el catálogo no conoce se descarta', () => {
    // Lo escribió otra versión de la app, o una mano. Un idioma que no está en
    // `IDIOMAS` no falla al elegirlo: falla al escribir la carta, con un 422 que
    // el usuario lee como «no se pudo añadir».
    localStorage.setItem('tcgdesk_scan_language', 'Klingon')
    setActivePinia(createPinia())

    expect(useScanStore().ajustes.language).toBe(POR_DEFECTO.language)
  })

  it('y un `localStorage` que lanza NO tumba el escáner', () => {
    // Modo privado, cookies bloqueadas, un webview con el almacenamiento capado:
    // los tres lanzan. Sin el `try/catch`, el store no se puede ni construir y
    // `/scan` se queda en blanco antes de pedir la cámara.
    const leer = vi.spyOn(Storage.prototype, 'getItem').mockImplementation(() => {
      throw new DOMException('denegado', 'SecurityError')
    })
    const escribir = vi.spyOn(Storage.prototype, 'setItem').mockImplementation(() => {
      throw new DOMException('denegado', 'SecurityError')
    })

    setActivePinia(createPinia())

    const otro = useScanStore()

    expect(otro.ajustes.language).toBe(POR_DEFECTO.language)
    expect(() => otro.fijarDimension('language', 'Spanish')).not.toThrow()
    expect(otro.ajustes.language).toBe('Spanish')

    leer.mockRestore()
    escribir.mockRestore()
  })
})

describe('resolver lecturas', () => {
  it('manda `lecturas` al primer nivel, con el id del cliente y el idioma leído', async () => {
    await detectarConfirmando(lectura({ language: 'Spanish' }))

    const [datos] = llamadasDe('scan_resolve')

    expect(datos.lecturas).toHaveLength(1)
    expect(datos.lecturas[0]).toMatchObject({
      id: 'd1',
      name: 'Rampant Growth',
      setCode: '2X2',
      collectorNumber: '155',
      rarity: 'common',
      // El idioma IMPRESO gana al de sesión: está en la carta, el ajuste es un
      // valor por defecto.
      language: 'Spanish',
      finish: 'normal',
      condition: 'NM'
    })
  })

  it('cae al idioma de sesión cuando el OCR no leyó ninguno', async () => {
    store.fijarDimension('language', 'German')
    await detectarConfirmando(lectura({ language: null }))

    expect(llamadasDe('scan_resolve')[0].lecturas[0].language).toBe('German')
  })

  /**
   * ORB PIDE EN EL MISMO IDIOMA QUE SE ESCRIBE, que es el M7 entero.
   *
   * El valor es el de la fila —`lectura.language ?? ajustes.language`—, no el
   * ajuste global a secas: una carta con su código de esquina en español pide
   * referencias españolas aunque el selector diga otra cosa. Sembrar y emparejar
   * en un idioma distinto del que se escribe no da ningún error: solo empareja
   * contra el arte equivocado.
   */
  it('`scan_orb_refs` va en el MISMO idioma que la línea de colección', async () => {
    store.fijarDimension('language', 'German')
    await detectarConfirmando(lectura({ language: 'Spanish' }))

    expect(llamadasDe('scan_orb_refs')[0].language).toBe('Spanish')
    expect(store.filas[0].language).toBe('Spanish')
  })

  it('y cuando la carta no trae idioma, en el del selector', async () => {
    store.fijarDimension('language', 'German')
    await detectarConfirmando(lectura({ language: null }))

    expect(llamadasDe('scan_orb_refs')[0].language).toBe('German')
  })

  it('empareja cada veredicto por su `id` aunque vuelvan desordenados', async () => {
    // La respuesta se devuelve DEL REVÉS a propósito: si el store emparejara
    // por posición en vez de por `id`, este test pondría el Lightning Bolt en
    // la fila del Rampant Growth — que es escribir otra carta en la colección.
    apiCall.mockImplementationOnce(async (accion, datos) => ({
      status: 'success',
      http_code: 200,
      data: {
        results: [
          { ...fixtura(VEREDICTOS)[ASUMIDA], id: datos.lecturas[1].id },
          { ...fixtura(VEREDICTOS)[EXACTA], id: datos.lecturas[0].id }
        ]
      }
    }))

    const lote = [
      lectura(),
      lectura({ name: 'Lightning Bolt', setCode: null, collectorNumber: null })
    ]

    // Dos veces: desde el M3b la primera vuelta solo apunta las dos claves, y es
    // la segunda la que sale a la red con el lote entero.
    await store.resolver(lote)
    await store.resolver(lote)

    const porNombre = Object.fromEntries(store.filas.map((f) => [f.name, f]))

    expect(porNombre['Rampant Growth'].printingUuid).toBe(VEREDICTOS[EXACTA].printingUuid)
    expect(porNombre['Lightning Bolt'].printingUuid).toBe(VEREDICTOS[ASUMIDA].printingUuid)
  })

  it('una lectura vacía no llega a la red', async () => {
    await detectarConfirmando({ name: null, setCode: null, collectorNumber: null })

    expect(llamadasDe('scan_resolve')).toHaveLength(0)
    expect(store.filas).toHaveLength(0)
  })

  it('resuelve el acabado y el precio con el contrato, no con los alias', async () => {
    store.fijarDimension('finish', 'foil')
    await detectarConfirmando(lectura())

    const fila = store.filas[0]

    // 2X2 155 admite foil y nonfoil, así que el ajuste de sesión manda.
    expect(fila.finish).toBe('foil')
    expect(precioDe(fila.priceEur, fila.finish)).toBe(VEREDICTOS[EXACTA].priceEur.foil)
  })

  it('una impresión de un solo acabado ignora el ajuste de sesión', async () => {
    cola = [ASUMIDA]
    store.fijarDimension('finish', 'foil')

    await detectarConfirmando(lectura({ name: 'Lightning Bolt', setCode: null, collectorNumber: null }))

    const fila = store.filas[0]

    expect(fila.finishes).toEqual({ foil: false, nonfoil: true, etched: false })
    expect(fila.finish).toBe('normal')
    // Y el precio es el del acabado que se va a guardar, no el foil que la
    // respuesta trae de todas formas.
    expect(precioDe(fila.priceEur, fila.finish)).toBe(VEREDICTOS[ASUMIDA].priceEur.normal)
  })

  it('un fallo de red retira la fila, para que el bucle pueda reintentarlo', async () => {
    apiCall.mockResolvedValueOnce({ status: 'error', message: 'No hay red.', http_code: 0 })

    await detectarConfirmando(lectura())

    expect(store.filas).toHaveLength(0)
    expect(store.aviso.tipo).toBe('error')

    // Y la misma lectura vuelve a preguntarse: si la clave se hubiera quedado
    // marcada como vista, el reintento no ocurriría nunca.
    await detectarConfirmando(lectura())

    expect(llamadasDe('scan_resolve')).toHaveLength(2)
    expect(store.filas).toHaveLength(1)
  })

  it('no guarda más de MAXIMO_FILAS filas', async () => {
    // Cada una con SU impresión: si todas resolvieran a la misma, la fusión por
    // impresión las dejaría en una sola y el tope no se probaría. Y resueltas y
    // no `not_found`, porque esas se retiran del menú a propósito.
    apiCall.mockImplementation(async (accion, datos = {}) => {
      if (accion !== 'scan_resolve') return { status: 'error', http_code: 500 }

      return {
        status: 'success',
        http_code: 200,
        data: {
          results: datos.lecturas.map((l) => ({
            ...fixtura(VEREDICTOS)[EXACTA],
            id: l.id,
            printingUuid: `uuid-${l.name}`
          }))
        }
      }
    })

    for (let i = 0; i < MAXIMO_FILAS + 5; i += 1) {
      await detectarConfirmando(lectura({ name: `Carta ${i}`, setCode: null, collectorNumber: null }))
    }

    expect(store.filas).toHaveLength(MAXIMO_FILAS)
  })
})

describe('la deduplicación, que es lo que impide triplicar una carta', () => {
  it('la misma lectura tres veces seguidas es UNA fila y UNA petición', async () => {
    await detectarConfirmando(lectura())
    await detectarConfirmando(lectura())
    await detectarConfirmando(lectura())

    expect(llamadasDe('scan_resolve')).toHaveLength(1)
    expect(store.filas).toHaveLength(1)
  })

  it('dos lecturas simultáneas de la misma carta no se preguntan dos veces', async () => {
    // La clave se confirma primero —desde el M3b hace falta verla dos veces— y
    // solo entonces se lanzan dos a la vez, que es lo que este test prueba: la
    // barrera de `enVuelo`, no la del filtro de ruido.
    await store.detectar(lectura())

    await Promise.all([store.detectar(lectura()), store.detectar(lectura())])

    expect(llamadasDe('scan_resolve')).toHaveLength(1)
  })

  it('dos lecturas DISTINTAS que resuelven a la misma impresión se funden', async () => {
    // El bucle lee la esquina en un fotograma y solo el nombre en el siguiente:
    // son dos claves de lectura y una sola carta.
    await detectarConfirmando(lectura())
    await detectarConfirmando(lectura({ setCode: null, collectorNumber: null }))

    expect(llamadasDe('scan_resolve')).toHaveLength(2)
    expect(store.filas).toHaveLength(1)
    expect(store.filas[0].printingUuid).toBe(VEREDICTOS[EXACTA].printingUuid)
  })

  it('HECHO CUANDO: escanear tres veces sin apartar el móvil escribe UNA carta', async () => {
    await detectarConfirmando(lectura())
    await detectarConfirmando(lectura())
    await detectarConfirmando(lectura())

    const fila = store.filas[0]

    await store.escribir(fila)
    // Las vueltas siguientes del bucle vuelven a marcar la fila: no escriben.
    await store.escribir(fila)
    await store.escribir(fila)

    expect(llamadasDe('collection_add')).toHaveLength(1)
    expect(store.estaEscrita(fila)).toBe(true)
    expect(fila.copias).toBe(1)
  })

  it('HECHO CUANDO: pulsar `+1` dos veces deja tres ejemplares escritos', async () => {
    await detectarConfirmando(lectura())
    const fila = store.filas[0]

    await store.escribir(fila)
    await store.sumarUna(fila)
    await store.sumarUna(fila)

    const altas = llamadasDe('collection_add')

    expect(altas).toHaveLength(3)
    expect(altas.every((a) => a.printing_uuid === VEREDICTOS[EXACTA].printingUuid)).toBe(true)
    expect(fila.copias).toBe(3)
  })

  it('una RE-DETECCIÓN de algo ya escrito nace marcada como escrita', async () => {
    await detectarConfirmando(lectura())
    await store.escribir(store.filas[0])

    // Se limpia el menú (o la fila se cayó por el tope) y se vuelve a apuntar.
    store.limpiarFilas()
    await detectarConfirmando(lectura())

    const nueva = store.filas[0]

    expect(nueva.copias).toBe(0)
    // La memoria es del `Set`, no de la fila: por eso la fila nueva ya sale
    // marcada y sin check.
    expect(store.estaEscrita(nueva)).toBe(true)

    await store.escribir(nueva)

    expect(llamadasDe('collection_add')).toHaveLength(1)
  })

  it('cambiar una dimensión antes de confirmar la vuelve a hacer escribible', async () => {
    await detectarConfirmando(lectura())
    const fila = store.filas[0]

    await store.escribir(fila)
    expect(store.estaEscrita(fila)).toBe(true)

    // Una vez escrita no se retoca: corregir una línea ya escrita es `moveLine()`.
    expect(store.cambiarDimension(fila, 'condition', 'LP')).toBe(false)
    expect(fila.condition).toBe('NM')
  })

  it('las dimensiones de la fila viajan en el alta', async () => {
    await detectarConfirmando(lectura())
    const fila = store.filas[0]

    store.cambiarDimension(fila, 'condition', 'LP')
    store.cambiarDimension(fila, 'language', 'Spanish')
    store.cambiarDimension(fila, 'finish', 'foil')

    await store.escribir(fila)

    expect(llamadasDe('collection_add')[0]).toEqual({
      printing_uuid: VEREDICTOS[EXACTA].printingUuid,
      finish: 'foil',
      language: 'Spanish',
      condition: 'LP',
      quantity: 1
    })
  })
})

describe('los tres destinos', () => {
  it('colección: una sola escritura', async () => {
    await detectarConfirmando(lectura())
    await store.escribir(store.filas[0])

    expect(llamadasDe('collection_add')).toHaveLength(1)
    expect(llamadasDe('collection_add')[0].is_wishlist).toBeUndefined()
    expect(llamadasDe('deck_card_add')).toHaveLength(0)
  })

  it('deseos: `collection_add` con `is_wishlist`, que ya existía', async () => {
    apiCall.mockImplementationOnce(async () => ({
      status: 'success',
      data: { results: [{ ...fixtura(VEREDICTOS)[EXACTA], id: 'd1' }] },
      http_code: 200
    }))

    store.fijarDestino('deseos')
    await detectarConfirmando(lectura())

    apiCall.mockImplementationOnce(async () => fixtura(altaDeseoFixture))
    await store.escribir(store.filas[0])

    expect(llamadasDe('collection_add')[0].is_wishlist).toBe(true)
  })

  it('HECHO CUANDO: con destino mazo son DOS escrituras, en este orden', async () => {
    store.fijarDestino('mazo', 17)
    await detectarConfirmando(lectura())
    await store.escribir(store.filas[0])

    // La siembra de descriptores ORB se filtra: NO es una escritura, va suelta
    // fuera del camino de la respuesta y este test mide el orden de lo que se
    // escribe. Que aparezca aquí es la prueba de que se dispara; que no cuente,
    // la de que no es parte de la escritura.
    const acciones = apiCall.mock.calls.map(([a]) => a).filter((a) => a !== 'scan_orb_refs')

    expect(acciones).toEqual(['scan_resolve', 'collection_add', 'deck_card_add'])
    expect(llamadasDe('deck_card_add')[0]).toEqual({
      deck_id: 17,
      printing_uuid: VEREDICTOS[EXACTA].printingUuid,
      finish: 'normal',
      language: 'English',
      condition: 'NM',
      count: 1
    })
  })

  it('sin mazo elegido no escribe NADA, ni siquiera en la colección', async () => {
    store.fijarDestino('mazo')
    await detectarConfirmando(lectura())

    const ok = await store.escribir(store.filas[0])

    expect(ok).toBe(false)
    expect(llamadasDe('collection_add')).toHaveLength(0)
    expect(store.aviso.tipo).toBe('error')
  })

  it('si el mazo falla, la carta SIGUE contando como escrita y se dice', async () => {
    store.fijarDestino('mazo', 17)
    await detectarConfirmando(lectura())

    apiCall.mockImplementation(async (accion) => {
      if (accion === 'collection_add') return fixtura(altaFixture)

      return { status: 'error', message: 'Ese mazo no existe o no es tuyo.', http_code: 404 }
    })

    const fila = store.filas[0]
    const ok = await store.escribir(fila)

    expect(ok).toBe(false)
    expect(fila.error).toContain('mazo')
    // La colección SÍ se escribió: olvidarlo metería una segunda copia en la
    // vuelta siguiente del bucle.
    expect(store.estaEscrita(fila)).toBe(true)
  })

  it('un alta fallida NO se apunta como escrita', async () => {
    await detectarConfirmando(lectura())

    apiCall.mockResolvedValue({ status: 'error', message: 'Sesión caducada.', http_code: 401 })

    const fila = store.filas[0]

    expect(await store.escribir(fila)).toBe(false)
    expect(store.estaEscrita(fila)).toBe(false)
    expect(fila.error).toBeTruthy()
  })

  it('los mazos se piden una sola vez y reutilizando `deck.listar()`', async () => {
    await store.cargarMazos()
    await store.cargarMazos()

    expect(llamadasDe('deck_list')).toHaveLength(1)
  })
})

describe('las dudas', () => {
  it('un conflicto llega con su motivo y sus candidatos, sin impresión', async () => {
    cola = [MISMATCH]
    await detectarConfirmando(lectura({ name: "Lim-Dûl's Vault", setCode: 'ICE', collectorNumber: '96' }))

    const fila = store.filas[0]

    expect(fila.estado).toBe('duda')
    expect(fila.reason).toBe('mismatch')
    expect(fila.candidates).toHaveLength(2)
    expect(fila.printingUuid).toBeNull()
  })

  it('confirmar una duda sin elegir no escribe nada', async () => {
    cola = [MISMATCH]
    await detectarConfirmando(lectura({ name: "Lim-Dûl's Vault" }))

    const fila = store.filas[0]

    expect(await store.escribir(fila)).toBe(false)
    expect(llamadasDe('collection_add')).toHaveLength(0)
    expect(fila.error).toBeTruthy()
  })

  it('elegir un candidato no escribe: solo deja la fila lista', async () => {
    cola = [MISMATCH]
    await detectarConfirmando(lectura({ name: "Lim-Dûl's Vault" }))

    const fila = store.filas[0]

    expect(store.elegirCandidato(fila, 1)).toBe(true)
    expect(llamadasDe('collection_add')).toHaveLength(0)
    expect(fila.printingUuid).toBe(VEREDICTOS[MISMATCH].candidates[1].printingUuid)
    expect(fila.name).toBe('Shyft')

    await store.escribir(fila)

    expect(llamadasDe('collection_add')[0].printing_uuid).toBe(
      VEREDICTOS[MISMATCH].candidates[1].printingUuid
    )
  })

  it('una fila sin impresión no se puede escribir, por muchos candidatos que tenga', async () => {
    // Antes esto se probaba con un `not_found`, pero desde el M3b esas filas se
    // retiran del menú —ver «las filas que el catálogo no reconoció se van
    // solas»—. El `mismatch` sirve igual y es el caso que de verdad queda en
    // pantalla: dos candidatos, ninguno elegido, nada que escribir todavía.
    cola = [MISMATCH]
    await detectarConfirmando(lectura({ name: "Lim-Dûl's Vault", setCode: 'ICE', collectorNumber: '96' }))

    const fila = store.filas[0]

    expect(fila.estado).toBe('duda')
    expect(fila.printingUuid).toBeNull()
    expect(await store.escribir(fila)).toBe(false)
  })

  it('corregir la edición asumida borra los datos de la impresión vieja', async () => {
    cola = [ASUMIDA]
    await detectarConfirmando(lectura({ name: 'Lightning Bolt', setCode: null, collectorNumber: null }))

    const fila = store.filas[0]

    expect(fila.assumedPrinting).toBe(true)
    expect(fila.printingCount).toBe(71)

    store.corregirImpresion(fila, 'otra-impresion-uuid')

    expect(fila.printingUuid).toBe('otra-impresion-uuid')
    expect(fila.impresionAsumida).toBe(VEREDICTOS[ASUMIDA].printingUuid)
    expect(fila.assumedPrinting).toBe(false)
    expect(fila.corregida).toBe(true)
    // Edición, número y precio eran los de la impresión que el resolvedor
    // asumió: enseñarlos ahora sería mentir sobre la que se va a guardar.
    expect(fila.setCode).toBeNull()
    expect(fila.collectorNumber).toBeNull()
    expect(fila.priceEur).toBeNull()

    await store.escribir(fila)

    expect(llamadasDe('collection_add')[0].printing_uuid).toBe('otra-impresion-uuid')
  })
})

describe('la verja de certeza, que es lo único que autoriza a escribir sin preguntar', () => {
  it('HECHO CUANDO: tres vueltas de la misma carta no degradan la certeza que una trajo', async () => {
    // El caso real del bucle: la luz cae bien en la SEGUNDA vuelta y el OCR lee
    // el bloque de la esquina; en la primera y la tercera solo saca el nombre.
    // Son tres claves de lectura distintas —y por tanto tres filas— pero **la
    // misma impresión**, que es lo que las funde en una.
    const sinCerteza = { ...fixtura(VEREDICTOS)[EXACTA], printingCertain: false, certaintySource: null }
    const conEsquina = fixtura(VEREDICTOS)[EXACTA]
    const vueltas = [sinCerteza, conEsquina, sinCerteza]
    let vuelta = 0

    apiCall.mockImplementation(async (accion, datos = {}) => {
      if (accion !== 'scan_resolve') return { status: 'error', http_code: 500 }

      const veredicto = vueltas[vuelta++]

      return {
        status: 'success',
        http_code: 200,
        data: { results: datos.lecturas.map((l) => ({ ...structuredClone(veredicto), id: l.id })) }
      }
    })

    await detectarConfirmando(lectura({ setCode: null, collectorNumber: null }))
    await detectarConfirmando(lectura())
    await detectarConfirmando(lectura({ setCode: null, collectorNumber: null, rarity: 'rare' }))

    // Las tres se funden por impresión, y lo que sobrevive es lo MEJOR que se
    // supo, no lo último: si la certeza se sobrescribiera, la tercera vuelta
    // —que leyó peor— habría borrado el `corner` de la segunda y el modo manos
    // libres dejaría de escribir una carta ya identificada.
    expect(store.filas).toHaveLength(1)
    expect(store.filas[0].printingCertain).toBe(true)
    expect(store.filas[0].certaintySource).toBe('corner')
  })

  it('una fila que nunca cerró la impresión no es cierta, aunque esté resuelta', async () => {
    cola = [ASUMIDA]

    await detectarConfirmando(lectura({ setCode: null, collectorNumber: null }))

    const fila = store.filas[0]

    // Resolver la CARTA no es resolver la IMPRESIÓN: 71 ediciones y el backend
    // asumió una.
    expect(fila.resolved).toBe(true)
    expect(fila.assumedPrinting).toBe(true)
    expect(fila.printingCertain).toBe(false)
    expect(fila.certaintySource).toBeNull()
  })

  it('una carta resuelta por su nombre en español tampoco es cierta', async () => {
    // El paso 3c dice QUÉ carta es, no cuál de sus 910 impresiones tienes en la
    // mano. Que el resolvedor deje de ser monolingüe no relaja la verja.
    cola = [LOCALIZADA]

    await detectarConfirmando(lectura({ name: 'Llanura', setCode: null, collectorNumber: null }))

    expect(store.filas[0].step).toBe('3c')
    expect(store.filas[0].printingCertain).toBe(false)
  })

  it('ni una rescatada de una errata por el paso 5', async () => {
    cola = [APROXIMADA]

    await detectarConfirmando(lectura({ name: 'Tlanura', setCode: null, collectorNumber: null }))

    expect(store.filas[0].step).toBe('5')
    expect(store.filas[0].printingCertain).toBe(false)
  })

  it('una respuesta con `art` se da por cierta venga de donde venga', async () => {
    // La verja no pregunta QUIÉN emitió la fuente, solo si está declarada. Desde
    // el M3 quien la emite de verdad es `emparejarPorArte()` —ver el bloque de
    // abajo—, pero si algún día la emitiera el backend cerraría igual.
    apiCall.mockImplementationOnce(async (accion, datos) => ({
      status: 'success',
      http_code: 200,
      data: {
        results: datos.lecturas.map((l) => ({
          ...fixtura(VEREDICTOS)[ASUMIDA],
          id: l.id,
          printingCertain: true,
          certaintySource: 'art'
        }))
      }
    }))

    await detectarConfirmando(lectura({ setCode: null, collectorNumber: null }))

    expect(store.filas[0].printingCertain).toBe(true)
    expect(store.filas[0].certaintySource).toBe('art')
  })

  it('una fuente que este cliente no conoce NO cierra la impresión', async () => {
    // Un backend futuro que mandara una etiqueta nueva no puede hacer que la app
    // escriba en la colección por un motivo que no sabe explicar.
    apiCall.mockImplementationOnce(async (accion, datos) => ({
      status: 'success',
      http_code: 200,
      data: {
        results: datos.lecturas.map((l) => ({
          ...fixtura(VEREDICTOS)[ASUMIDA],
          id: l.id,
          printingCertain: true,
          certaintySource: 'telepatia'
        }))
      }
    }))

    await detectarConfirmando(lectura({ setCode: null, collectorNumber: null }))

    expect(store.filas[0].printingCertain).toBe(false)
    expect(store.filas[0].certaintySource).toBeNull()
  })

  it('las tres fuentes están declaradas, y `art` es una de ellas', () => {
    expect(Object.keys(FUENTES_DE_CERTEZA)).toEqual(['single', 'corner', 'art'])
    expect(esFuenteDeCerteza('art')).toBe(true)
    expect(esFuenteDeCerteza('telepatia')).toBe(false)
    expect(esFuenteDeCerteza(null)).toBe(false)
  })
})

describe('el filtro de ruido del M3b: una lectura no sale hasta confirmarse', () => {
  it('HECHO CUANDO: la primera vuelta de una lectura nueva NO pregunta, la segunda sí', async () => {
    await store.detectar(lectura())

    expect(llamadasDe('scan_resolve')).toHaveLength(0)
    expect(store.filas).toHaveLength(0)

    await store.detectar(lectura())

    expect(llamadasDe('scan_resolve')).toHaveLength(1)
    expect(store.filas).toHaveLength(1)
  })

  it('HECHO CUANDO: un nombre que cambia cada vuelta no genera NI UNA petición', async () => {
    // Es el ruido real medido en el Realme: trozos de borde y de texto de reglas
    // que cambian con cada fotograma. Ninguno se repite, así que ninguno sale.
    for (const basura of ['Pla', 'Plc', 'Plar', 'PF', 'tab', 'CateSnale Do']) {
      await store.detectar(lectura({ name: basura, setCode: null, collectorNumber: null }))
    }

    expect(llamadasDe('scan_resolve')).toHaveLength(0)
    expect(store.filas).toHaveLength(0)
  })

  it('una vez confirmada, las vueltas siguientes no vuelven a esperar', async () => {
    await store.detectar(lectura())
    await store.detectar(lectura())
    await store.detectar(lectura())
    await store.detectar(lectura())

    // La segunda vuelta preguntó; de la tercera en adelante manda la
    // deduplicación de siempre, no el filtro.
    expect(llamadasDe('scan_resolve')).toHaveLength(1)
    expect(store.filas).toHaveLength(1)
  })

  it('el filtro cuenta por clave, así que dos cartas distintas no se ayudan entre sí', async () => {
    await store.detectar(lectura())
    await store.detectar(lectura({ name: 'Otra Carta', setCode: null, collectorNumber: null }))

    // Cada una lleva una sola vuelta: ninguna está confirmada todavía.
    expect(llamadasDe('scan_resolve')).toHaveLength(0)

    await store.detectar(lectura())

    expect(llamadasDe('scan_resolve')).toHaveLength(1)
  })

  it('empezar sesión y limpiar el menú olvidan lo visto', async () => {
    await store.detectar(lectura())
    store.limpiarFilas()

    // La vuelta que había acumulado se fue con el menú: vuelve a hacer falta
    // verla dos veces.
    await store.detectar(lectura())
    expect(llamadasDe('scan_resolve')).toHaveLength(0)

    await store.detectar(lectura())
    expect(llamadasDe('scan_resolve')).toHaveLength(1)
  })
})

describe('las filas que el catálogo no reconoció se van solas', () => {
  it('una lectura `not_found` no se queda en el menú', async () => {
    cola = [NO_ENCONTRADA]

    await detectarConfirmando(lectura({ name: 'Olin-Gitaxias', setCode: null, collectorNumber: null }))

    // Se preguntó —llegó a la red— y el catálogo dijo que no la conoce; lo que
    // no se queda es la fila, que solo ensuciaría el menú.
    expect(llamadasDe('scan_resolve')).toHaveLength(1)
    expect(store.filas).toHaveLength(0)
  })

  it('y su clave se olvida, para que esa lectura pueda volver a intentarse', async () => {
    cola = [NO_ENCONTRADA]
    await detectarConfirmando(lectura({ name: 'Olin-Gitaxias', setCode: null, collectorNumber: null }))

    // La carta que el catálogo no conocía —una ingesta retrasada— tiene que
    // poder resolverse en el siguiente intento, no quedar marcada para siempre.
    cola = [EXACTA]
    await detectarConfirmando(lectura({ name: 'Olin-Gitaxias', setCode: null, collectorNumber: null }))

    expect(llamadasDe('scan_resolve')).toHaveLength(2)
    expect(store.filas).toHaveLength(1)
  })

  it('un `ambiguous` SÍ se queda: hay candidatos que elegir', async () => {
    cola = [MISMATCH]

    await detectarConfirmando(lectura({ name: "Lim-Dûl's Vault", setCode: 'ICE', collectorNumber: '96' }))

    expect(store.filas).toHaveLength(1)
    expect(store.filas[0].candidates.length).toBeGreaterThan(0)
  })

  it('y una fila resuelta tampoco se toca', async () => {
    await detectarConfirmando(lectura())

    expect(store.filas).toHaveLength(1)
    expect(store.filas[0].estado).toBe('resuelta')
  })
})

describe('el modo manos libres, que es lo único que escribe sin que toques nada', () => {
  it('HECHO CUANDO: una carta CIERTA se escribe sola, sin tocar la pantalla', async () => {
    store.ajustes.manosLibres = true

    await detectarConfirmando(lectura())

    // La fixtura `EXACTA` cerró por el bloque de esquina: `certaintySource:
    // 'corner'`, así que la impresión está cerrada y no hay nada que asumir.
    expect(store.filas[0].printingCertain).toBe(true)
    expect(llamadasDe('collection_add')).toHaveLength(1)
    expect(store.filas[0].escritaSola).toBe(true)
  })

  it('HECHO CUANDO: una carta con la edición ASUMIDA no se escribe sola', async () => {
    store.ajustes.manosLibres = true
    cola = [ASUMIDA]

    await detectarConfirmando(lectura({ name: 'Lightning Bolt', setCode: null, collectorNumber: null }))

    // 71 ediciones posibles: escribirla sería meter en la colección una carta
    // que no tienes. Se queda esperando en el menú.
    expect(store.filas[0].resolved).toBe(true)
    expect(store.filas[0].printingCertain).toBe(false)
    expect(llamadasDe('collection_add')).toHaveLength(0)
    expect(store.filas[0].escritaSola).toBe(false)
  })

  it('HECHO CUANDO: con el conmutador apagado no se escribe NADA solo', async () => {
    await detectarConfirmando(lectura())

    expect(store.filas[0].printingCertain).toBe(true)
    expect(llamadasDe('collection_add')).toHaveLength(0)
  })

  it('HECHO CUANDO: el conmutador arranca apagado y `empezarSesion()` lo APAGA', () => {
    expect(store.ajustes.manosLibres).toBe(false)

    store.ajustes.manosLibres = true
    store.fijarDimension('finish', 'foil')
    store.empezarSesion()

    // Los ajustes se conservan al reentrar en `/scan` a propósito… menos este.
    // Que la app empiece a escribir sola porque lo dejaste encendido la vez
    // anterior es justo el susto que el plan quiere evitar.
    expect(store.ajustes.manosLibres).toBe(false)
    expect(store.ajustes.finish).toBe('foil')
  })

  it('apuntar tres segundos a la misma carta la escribe UNA vez', async () => {
    store.ajustes.manosLibres = true

    await detectarConfirmando(lectura())
    await store.detectar(lectura())
    await store.detectar(lectura())

    expect(llamadasDe('collection_add')).toHaveLength(1)
  })

  it('encender el conmutador barre lo que ya está en pantalla', async () => {
    await detectarConfirmando(lectura())

    expect(llamadasDe('collection_add')).toHaveLength(0)

    await store.fijarManosLibres(true)

    // Lo contrario —que solo valiera para lo siguiente— obligaría a apartar el
    // móvil y volver a apuntar a la misma carta.
    expect(llamadasDe('collection_add')).toHaveLength(1)
  })

  it('una escritura que falla NO se marca, para poder reintentarla', async () => {
    store.ajustes.manosLibres = true
    apiCall.mockImplementation(async (accion, datos = {}) => {
      if (accion === 'scan_resolve') {
        return {
          status: 'success',
          http_code: 200,
          data: { results: datos.lecturas.map((l) => ({ ...fixtura(VEREDICTOS)[EXACTA], id: l.id })) }
        }
      }

      return { status: 'error', message: 'No hay red.', http_code: 0 }
    })

    await detectarConfirmando(lectura())

    expect(store.filas[0].escritaSola).toBe(false)
    expect(store.filas[0].error).toBeTruthy()
  })
})

describe('deshacer, la única red del modo manos libres', () => {
  function conAlta(quantity) {
    return {
      status: 'success',
      http_code: 200,
      data: { item: { id: 4242, name: 'Rampant Growth', quantity } }
    }
  }

  /** Escribe una carta sola y devuelve su fila, con la línea ya guardada. */
  async function escritaSola(quantity = 1) {
    store.ajustes.manosLibres = true

    apiCall.mockImplementation(async (accion, datos = {}) => {
      if (accion === 'scan_resolve') {
        return {
          status: 'success',
          http_code: 200,
          data: { results: datos.lecturas.map((l) => ({ ...fixtura(VEREDICTOS)[EXACTA], id: l.id })) }
        }
      }

      if (accion === 'collection_add') return conAlta(quantity)
      if (accion === 'collection_update_quantity') {
        return {
          status: 'success',
          http_code: 200,
          data:
            datos.quantity === 0
              ? { removed: true }
              : { item: { id: 4242, name: 'Rampant Growth', quantity: datos.quantity } }
        }
      }

      return { status: 'error', http_code: 500 }
    })

    await detectarConfirmando(lectura())

    return store.filas[0]
  }

  it('HECHO CUANDO: deshacer quita la carta y la deja volver a entrar', async () => {
    const fila = await escritaSola(1)

    expect(fila.escritaSola).toBe(true)
    expect(store.estaEscrita(fila)).toBe(true)

    expect(await store.deshacer(fila)).toBe(true)

    // La clave vuelve a la circulación: si se quedara en la memoria, la carta
    // no podría volver a entrar en esta sesión ni a mano.
    expect(store.estaEscrita(fila)).toBe(false)
    expect(fila.escritaSola).toBe(false)
  })

  it('RESTA UNA COPIA en vez de borrar la línea entera', async () => {
    // La carta ya estaba en la colección con dos copias, así que el alta la dejó
    // en tres. Deshacer no puede llevarse las tres.
    const fila = await escritaSola(3)

    await store.deshacer(fila)

    const peticion = llamadasDe('collection_update_quantity')[0]

    expect(peticion).toEqual({ item_id: 4242, quantity: 2 })
    expect(llamadasDe('collection_remove')).toHaveLength(0)
  })

  it('y cuando solo quedaba una, el backend borra la línea', async () => {
    const fila = await escritaSola(1)

    await store.deshacer(fila)

    expect(llamadasDe('collection_update_quantity')[0]).toEqual({ item_id: 4242, quantity: 0 })
  })

  it('una fila sin línea guardada no se puede deshacer desde aquí', async () => {
    await detectarConfirmando(lectura())

    const fila = store.filas[0]

    fila.linea = null

    expect(await store.deshacer(fila)).toBe(false)
    expect(fila.error).toMatch(/colección/i)
  })
})

// ==========================================================================
// La siembra perezosa de descriptores ORB
// ==========================================================================

describe('siembra perezosa de descriptores ORB', () => {
  const ORACLE = '8539f295-5d58-4436-a73a-b9277c4c7795'

  /** Un bloque del tamaño real: 700 keypoints x 40 bytes. */
  function bloque(relleno = 7) {
    const bytes = new Uint8Array(NFEATURES * 40)

    for (let i = 0; i < bytes.length; i++) bytes[i] = (i * relleno) % 256

    return bytes
  }

  /** Las referencias que devolvería `scan_orb_refs` para una carta. */
  function refs(sembradas) {
    return [
      { printingUuid: 'uuid-1', face: 'front', scryfallId: 'sc-1', orb: sembradas ? 'AAA=' : null },
      { printingUuid: 'uuid-2', face: 'front', scryfallId: 'sc-2', orb: sembradas ? 'BBB=' : null },
      { printingUuid: 'uuid-3', face: 'front', scryfallId: 'sc-3', orb: sembradas ? 'CCC=' : null }
    ]
  }

  function conRefs(lista, respuestaDeAlta = '') {
    apiCall.mockImplementation(async (accion) => {
      if (accion === 'scan_orb_refs') {
        return { status: 'success', http_code: 200, data: { refs: lista } }
      }

      if (accion === 'vision_orb_store') return respuestaDeAlta

      return { status: 'error', message: `sin doble para ${accion}`, http_code: 500 }
    })
  }

  beforeEach(() => {
    imagenBytes.mockResolvedValue(new Uint8Array([1, 2, 3, 4]))
    descriptoresDe.mockResolvedValue(bloque())
  })

  it('HECHO CUANDO: una carta que nadie ha escaneado siembra TODAS sus impresiones', async () => {
    conRefs(refs(false))

    expect(await store.sembrarOrbDe(ORACLE)).toBe(3)

    // `language` va SIEMPRE, y con el ajuste de sesión cuando la lectura no
    // trae idioma propio. Es opcional para el backend, pero lo que se pide
    // tiene que ser lo que se escribe.
    expect(llamadasDe('scan_orb_refs')).toEqual([
      { oracleId: ORACLE, language: POR_DEFECTO.language }
    ])
    expect(imagenBytes.mock.calls.map(([id, tamano]) => [id, tamano])).toEqual([
      ['sc-1', 'normal'],
      ['sc-2', 'normal'],
      ['sc-3', 'normal']
    ])

    // EL PAYLOAD ES EL CONTRATO. `orb` en base64 de keypoints*40 bytes,
    // `keypoints` derivado del bloque y NO inventado, y `nfeatures` el de la
    // calibración: el backend devuelve 400 si la longitud no cuadra con los
    // keypoints declarados, y ese 400 sería silencioso desde aquí.
    const altas = llamadasDe('vision_orb_store')

    expect(altas).toHaveLength(3)
    expect(altas[0]).toEqual({
      printingUuid: 'uuid-1',
      face: 'front',
      language: POR_DEFECTO.language,
      nfeatures: NFEATURES,
      keypoints: NFEATURES,
      orb: base64DeBloque(bloque())
    })
  })

  it('HECHO CUANDO: la segunda vez NO se descarga ninguna imagen', async () => {
    // Las tres impresiones vuelven ya sembradas, que es lo que pasa la segunda
    // vez que se escanea la misma carta. No hay nada que bajar ni que subir.
    conRefs(refs(true))

    expect(await store.sembrarOrbDe(ORACLE)).toBe(0)

    expect(imagenBytes).not.toHaveBeenCalled()
    expect(descriptoresDe).not.toHaveBeenCalled()
    expect(llamadasDe('vision_orb_store')).toHaveLength(0)
  })

  it('siembra solo las que faltan cuando el índice está a medias', async () => {
    const mitad = refs(false)

    mitad[0].orb = 'YA-SEMBRADA='

    conRefs(mitad)

    expect(await store.sembrarOrbDe(ORACLE)).toBe(2)
    expect(imagenBytes.mock.calls.map(([id]) => id)).toEqual(['sc-2', 'sc-3'])
  })

  it('un 409 es «ya estaba», no un fallo', async () => {
    // `INSERT IGNORE`: otro escaneo la sembró primero y el primero gana. Es la
    // garantía anti-envenenamiento funcionando.
    conRefs(refs(false), { status: 'error', message: 'Ya está sembrada.', http_code: 409 })

    expect(await store.sembrarOrbDe(ORACLE)).toBe(3)
  })

  it('un 400 del backend sí es un fallo, y no tumba las demás', async () => {
    apiCall.mockImplementation(async (accion, datos) => {
      if (accion === 'scan_orb_refs') {
        return { status: 'success', http_code: 200, data: { refs: refs(false) } }
      }

      if (accion === 'vision_orb_store') {
        return datos.printingUuid === 'uuid-2'
          ? { status: 'error', message: 'El bloque mide…', http_code: 400 }
          : ''
      }

      return { status: 'error', http_code: 500 }
    })

    expect(await store.sembrarOrbDe(ORACLE)).toBe(2)
    expect(llamadasDe('vision_orb_store')).toHaveLength(3)
  })

  it('una imagen que no se puede bajar deja esa impresión sin sembrar y ya está', async () => {
    conRefs(refs(false))
    imagenBytes.mockResolvedValueOnce(null)

    expect(await store.sembrarOrbDe(ORACLE)).toBe(2)
    expect(llamadasDe('vision_orb_store')).toHaveLength(2)
  })

  it('un bloque que no mide keypoints * 40 no se sube: el 400 sería silencioso', async () => {
    conRefs(refs(false))
    descriptoresDe.mockResolvedValue(new Uint8Array(NFEATURES * 32))

    // 700*32 son 22.400 bytes: múltiplo de 40, así que `keypointsDe` dice 560 y
    // el bloque parece válido. Lo que lo caza es el tope `keypoints <= NFEATURES`
    // junto con el hecho de que el backend compara contra los declarados.
    await store.sembrarOrbDe(ORACLE)

    expect(llamadasDe('vision_orb_store')[0].keypoints).toBe(560)

    descriptoresDe.mockResolvedValue(new Uint8Array(41))
    store.orbPedidos = new Set()
    apiCall.mockClear()

    expect(await store.sembrarOrbDe(ORACLE)).toBe(0)
    expect(llamadasDe('vision_orb_store')).toHaveLength(0)
  })

  it('no vuelve a preguntar por la misma carta en la misma sesión', async () => {
    conRefs(refs(true))

    await store.sembrarOrbDe(ORACLE)
    await store.sembrarOrbDe(ORACLE)
    await store.sembrarOrbDe(ORACLE)

    expect(llamadasDe('scan_orb_refs')).toHaveLength(1)
  })

  /**
   * LA CACHÉ ES POR CARTA **Y POR IDIOMA**, y esto es lo que lo fija.
   *
   * `orbRefs` y `orbPedidos` se indexaban solo por `oracleId`. Desde el M7 la
   * respuesta de `scan_orb_refs` depende del idioma que se pide —sirve la fila
   * de ese idioma y cae al inglés si no está sembrada en él—, así que con la
   * clave corta la lectura española se comería las referencias inglesas de la
   * anterior y ORB emparejaría contra el idioma equivocado **sin un solo error
   * visible**. Es exactamente el tipo de cosa que un refactor rompe y sale
   * verde, y por eso está escrito aquí y no solo en un comentario.
   */
  it('la caché NO cruza idiomas: la misma carta en español se vuelve a pedir', async () => {
    conRefs(refs(true))

    await store.sembrarOrbDe(ORACLE, 'English')
    await store.sembrarOrbDe(ORACLE, 'Spanish')
    await store.sembrarOrbDe(ORACLE, 'English')

    expect(llamadasDe('scan_orb_refs')).toEqual([
      { oracleId: ORACLE, language: 'English' },
      { oracleId: ORACLE, language: 'Spanish' }
    ])
  })

  it('y tampoco cruza al revés: lo cacheado en español no sirve al inglés', async () => {
    // Las inglesas vienen sembradas y las españolas no: si la caché cruzara,
    // la segunda llamada creería que no hay nada que sembrar y no bajaría ni
    // una imagen.
    apiCall.mockImplementation(async (accion, datos = {}) => {
      if (accion === 'scan_orb_refs') {
        const sembradas = datos.language === 'English'

        return { status: 'success', http_code: 200, data: { refs: refs(sembradas) } }
      }

      if (accion === 'vision_orb_store') return ''

      return { status: 'error', message: `sin doble para ${accion}`, http_code: 500 }
    })

    expect(await store.sembrarOrbDe(ORACLE, 'English')).toBe(0)
    expect(await store.sembrarOrbDe(ORACLE, 'Spanish')).toBe(3)

    // Y lo sembrado va etiquetado en SU idioma, que es lo que entra en la clave
    // de `mtg_printing_orb`: una siembra española guardada como inglesa
    // envenenaría el índice inglés para siempre (`INSERT IGNORE` no reemplaza).
    expect(llamadasDe('vision_orb_store').map((p) => p.language)).toEqual([
      'Spanish',
      'Spanish',
      'Spanish'
    ])
  })

  /**
   * LA ENMIENDA DEL 2026-09-16: **se siembra con el idioma de la IMAGEN que se
   * bajó, no con el que se pidió**.
   *
   * El backend cae al `scryfallId` inglés cuando MTGJSON no publica la
   * traducción, y eso pasa en el **52 %** del catálogo (57.341 de 110.384
   * impresiones no tienen fila en español). Sellar esa siembra como `Spanish`
   * metería descriptores ingleses en la fila española, y como `mtg_printing_orb`
   * se escribe con `INSERT IGNORE` —para que nadie sobrescriba descriptores
   * buenos— esa fila **bloquearía para siempre** la siembra correcta.
   *
   * Pedir en español una impresión que solo existe en inglés tiene que sembrar
   * una fila `English`.
   */
  it('pedida en español, una impresión que solo existe en inglés se siembra como INGLESA', async () => {
    conRefs([
      { ...refs(false)[0], scryfallLanguage: 'English' },
      { ...refs(false)[1], scryfallLanguage: 'English' }
    ])

    expect(await store.sembrarOrbDe(ORACLE, 'Spanish')).toBe(2)

    // Lo pedido sigue siendo español —la caché va por idioma PEDIDO y eso no
    // cambia—, pero lo sellado es inglés, que es lo que de verdad se bajó.
    expect(llamadasDe('scan_orb_refs')).toEqual([{ oracleId: ORACLE, language: 'Spanish' }])
    expect(llamadasDe('vision_orb_store').map((p) => p.language)).toEqual(['English', 'English'])
  })

  /**
   * Y la mezcla, que es el caso real de una carta cualquiera: unas impresiones
   * traducidas y otras no, en la MISMA respuesta. El idioma se decide por
   * referencia y nunca por la petición.
   */
  it('cada impresión se sella con SU idioma, no con el de la petición', async () => {
    const lista = refs(false)

    lista[0].scryfallLanguage = 'Spanish'
    lista[1].scryfallLanguage = 'English'
    lista[2].scryfallLanguage = 'Spanish'

    conRefs(lista)

    expect(await store.sembrarOrbDe(ORACLE, 'Spanish')).toBe(3)

    expect(llamadasDe('vision_orb_store').map((p) => p.language)).toEqual([
      'Spanish',
      'English',
      'Spanish'
    ])
  })

  /**
   * Un backend anterior a la enmienda no manda el campo, y entonces no hay forma
   * de saber qué imagen sirvió: se sella con lo pedido, que es lo que se hacía
   * antes. Es lo que evita que un despliegue a medias deje de sembrar.
   */
  it('sin `scryfallLanguage` en la respuesta se sella con el idioma pedido, como antes', async () => {
    conRefs(refs(false))

    expect(await store.sembrarOrbDe(ORACLE, 'Spanish')).toBe(3)

    expect(llamadasDe('vision_orb_store').map((p) => p.language)).toEqual([
      'Spanish',
      'Spanish',
      'Spanish'
    ])
  })

  /**
   * LA ENMIENDA DEL 2026-09-16 (2): **LA SIEMBRA CONGELADA**.
   *
   * El cliente sembraba **solo cuando `orb` venía a `null`**. Con el respaldo al
   * inglés del M6, una impresión ya sembrada en inglés contesta **con
   * descriptores** aunque se pida en español, así que el móvil daba la petición
   * por satisfecha, **no sembraba el español nunca** y emparejaba contra el
   * inglés para siempre. Medido en la BD de dev: *Chromatic Lantern* tenía **35
   * filas `English` y 0 `Spanish`** después de escanearla en español, y las 414
   * filas `Spanish` que sí existían eran todas de cartas que **aún no tenían
   * fila inglesa** al escanearse.
   *
   * La regla pasa a mirar los **dos** idiomas que la ref ya traía: `language`
   * (el de los descriptores servidos, que puede ser el respaldo) y
   * `scryfallLanguage` (el de la imagen disponible). Los cuatro tests de abajo
   * son las cuatro filas de la tabla del plan, en orden.
   */
  describe('la siembra congelada: `orb` a null NO es la única razón para sembrar', () => {
    /** Una impresión con los dos idiomas de la ref explícitos. */
    function unaRef({ orb = null, language = 'Spanish', scryfallLanguage = 'Spanish' } = {}) {
      return [
        {
          printingUuid: 'uuid-1',
          face: 'front',
          scryfallId: 'sc-1',
          orb,
          language,
          scryfallLanguage
        }
      ]
    }

    it('CASO 1 — sin sembrar y con imagen española: siembra, y en español', async () => {
      conRefs(unaRef({ orb: null, language: 'Spanish', scryfallLanguage: 'Spanish' }))

      expect(await store.sembrarOrbDe(ORACLE, 'Spanish')).toBe(1)
      expect(llamadasDe('vision_orb_store').map((p) => p.language)).toEqual(['Spanish'])
    })

    /**
     * **ESTE ES EL CASO QUE ESTABA ROTO**, y el único que cambia de respuesta.
     * La ref viene con descriptores ingleses y existe imagen española: hay algo
     * mejor que lo que hay en el índice, así que se siembra.
     */
    it('CASO 2 — sembrada en INGLÉS y con imagen española: SIEMBRA la española', async () => {
      conRefs(unaRef({ orb: 'YA-ESTABA=', language: 'English', scryfallLanguage: 'Spanish' }))

      expect(await store.sembrarOrbDe(ORACLE, 'Spanish')).toBe(1)

      // Y se baja la imagen ESPAÑOLA —el `scryfallId` que mandó el backend— y se
      // sella como española, que es lo que certifica en la lectura siguiente.
      expect(imagenBytes.mock.calls.map(([id]) => id)).toEqual(['sc-1'])
      expect(llamadasDe('vision_orb_store').map((p) => p.language)).toEqual(['Spanish'])
    })

    it('CASO 3 — sembrada en inglés y SIN imagen española: no se baja nada', async () => {
      // Los dos campos valen lo mismo: el backend no ha caído a ningún respaldo,
      // es que esa impresión no existe en español. No hay nada mejor que sembrar.
      conRefs(unaRef({ orb: 'YA-ESTABA=', language: 'English', scryfallLanguage: 'English' }))

      expect(await store.sembrarOrbDe(ORACLE, 'Spanish')).toBe(0)
      expect(imagenBytes).not.toHaveBeenCalled()
      expect(llamadasDe('vision_orb_store')).toHaveLength(0)
    })

    it('CASO 4 — ya sembrada en español: no se baja nada', async () => {
      conRefs(unaRef({ orb: 'YA-ESTABA=', language: 'Spanish', scryfallLanguage: 'Spanish' }))

      expect(await store.sembrarOrbDe(ORACLE, 'Spanish')).toBe(0)
      expect(imagenBytes).not.toHaveBeenCalled()
      expect(llamadasDe('vision_orb_store')).toHaveLength(0)
    })

    /**
     * **Y la mejora se siembra UNA vez, no en cada vuelta del bucle.** La ref
     * cacheada se sella con el idioma que se acaba de subir; sin eso seguiría
     * cumpliendo la condición para siempre y el móvil rebajaría la misma imagen
     * dos veces por segundo para comerse un 409 detrás de otro.
     */
    it('la mejora se siembra UNA vez: la vuelta siguiente ya no baja nada', async () => {
      conRefs(unaRef({ orb: 'YA-ESTABA=', language: 'English', scryfallLanguage: 'Spanish' }))

      expect(await store.sembrarOrbDe(ORACLE, 'Spanish')).toBe(1)
      expect(await store.sembrarOrbDe(ORACLE, 'Spanish')).toBe(0)

      expect(imagenBytes).toHaveBeenCalledTimes(1)
      expect(llamadasDe('vision_orb_store')).toHaveLength(1)
    })

    /**
     * Un backend anterior a la enmienda no manda ninguno de los dos campos: los
     * dos caen al idioma pedido, salen iguales y la regla se queda **exactamente
     * en la de antes**. Es lo que impide que un despliegue a medias se ponga a
     * resembrar el catálogo entero.
     */
    it('sin los dos idiomas en la respuesta, una ref sembrada no se resiembra', async () => {
      conRefs(refs(true))

      expect(await store.sembrarOrbDe(ORACLE, 'Spanish')).toBe(0)
      expect(imagenBytes).not.toHaveBeenCalled()
    })
  })

  it('pero un fallo de red SÍ se reintenta: no puede perder la carta para siempre', async () => {
    apiCall.mockResolvedValueOnce({ status: 'error', message: 'No hay red.', http_code: 0 })

    expect(await store.sembrarOrbDe(ORACLE)).toBe(0)

    conRefs(refs(false))

    expect(await store.sembrarOrbDe(ORACLE)).toBe(3)
  })

  it('empezarSesion() olvida lo pedido', async () => {
    conRefs(refs(true))

    await store.sembrarOrbDe(ORACLE)
    store.empezarSesion()
    await store.sembrarOrbDe(ORACLE)

    expect(llamadasDe('scan_orb_refs')).toHaveLength(2)
  })

  it('HECHO CUANDO: la siembra NO hace esperar al usuario', async () => {
    // La descarga de la imagen se queda colgada PARA SIEMPRE. Si la siembra
    // estuviera en el camino de la respuesta, `detectar()` no volvería nunca y
    // la carta no aparecería en el menú hasta que el CDN contestara.
    let nuncaVuelve = null

    imagenBytes.mockImplementation(() => new Promise((r) => { nuncaVuelve = r }))

    apiCall.mockImplementation(async (accion, datos = {}) => {
      if (accion === 'scan_resolve') {
        return {
          status: 'success',
          http_code: 200,
          data: { results: datos.lecturas.map((l) => ({ ...fixtura(VEREDICTOS)[EXACTA], id: l.id })) }
        }
      }

      if (accion === 'scan_orb_refs') {
        return { status: 'success', http_code: 200, data: { refs: refs(false) } }
      }

      return { status: 'error', http_code: 500 }
    })

    await detectarConfirmando(lectura())

    // La fila está resuelta y pintada mientras la siembra sigue colgada.
    expect(store.filas[0].estado).toBe('resuelta')
    expect(store.filas[0].printingUuid).toBe(VEREDICTOS[EXACTA].printingUuid)

    // **El turno de la cola se espera aparte, y eso es del M4.** Desde que la
    // siembra pasa por `colaDeSiembra`, el primer eslabón arranca una microtarea
    // después de que `detectar()` vuelva. Que la descarga ni siquiera haya
    // empezado cuando la fila ya está en pantalla refuerza lo que este test
    // afirma, pero hay que dejar correr la cola para comprobar que la descarga
    // se pidió y se quedó colgada.
    for (let i = 0; i < 5; i++) await Promise.resolve()

    expect(nuncaVuelve).not.toBeNull()
    expect(llamadasDe('vision_orb_store')).toHaveLength(0)
  })
})

// ==========================================================================
// M3 — el emparejamiento por ORB y la fuente `art`
// ==========================================================================

describe('`impresionPorArte()`: el margen, y nada más que el margen', () => {
  /**
   * Un candidato del ranking, **con el arte aparte**.
   *
   * Desde el M11 el cociente NO se mide sobre el total: el arte empata entre
   * reimpresiones del mismo cuadro y ese empate lo diluye. Aquí los 60 del arte
   * son iguales para todos —que es justo lo que los hace inservibles para
   * decidir— y lo que se mueve es `inliersFueraDelArte`.
   */
  const INLIERS_DEL_ARTE = 60
  const candidato = (printingUuid, fuera) => ({
    printingUuid,
    face: 'front',
    inliers: INLIERS_DEL_ARTE + fuera,
    inliersFueraDelArte: fuera
  })
  const uno = candidato('u1', 70)

  it('certifica en 1,6 y NO en 1,4, que es el listón del plan', () => {
    // 1,5 sobre INLIERS de findHomography, no sobre parejas de Lowe. Los dos
    // números salen del régimen real: por debajo del listón los márgenes se
    // apelotonan entre 1,00 y 1,20 (empate técnico medido), y por encima no hay
    // ni una impresión equivocada en las siete combinaciones del M0(b).
    expect(MARGEN_MINIMO).toBe(1.5)

    expect(impresionPorArte([uno, candidato('u2', 50)])).toBeNull() // 1,40
    expect(impresionPorArte([uno, candidato('u2', 43.75)])).toEqual({
      printingUuid: 'u1',
      face: 'front',
      inliers: 130,
      inliersFueraDelArte: 70,
      margen: 1.6
    })
  })

  it('el empate exacto en 1,5 SÍ certifica: el listón es `>=`', () => {
    expect(impresionPorArte([uno, candidato('u2', 70 / 1.5)])?.margen).toBe(1.5)
  })

  it('el arte NO entra en el cociente, que es todo el M11', () => {
    // Las mismas dos impresiones, y la única diferencia es cuánto arte comparten:
    // 60 inliers de cuadro o 600. El veredicto tiene que ser EL MISMO, porque el
    // arte no distingue una reimpresión de otra. Midiendo sobre el total, el
    // segundo caso daría 670/643 = 1,04 y no certificaría nadie —que es
    // exactamente lo que le pasaba a la `Linterna cromática` de David—.
    const flaco = impresionPorArte([candidato('u1', 70), candidato('u2', 43.75)])
    const gordo = impresionPorArte([
      { printingUuid: 'u1', face: 'front', inliers: 600 + 70, inliersFueraDelArte: 70 },
      { printingUuid: 'u2', face: 'front', inliers: 600 + 43.75, inliersFueraDelArte: 43.75 }
    ])

    expect(flaco?.printingUuid).toBe('u1')
    expect(gordo?.printingUuid).toBe('u1')
    expect(gordo?.margen).toBe(flaco?.margen)
  })

  it('ordena por los de fuera del arte aunque el total diga otra cosa', () => {
    // El ranking le llega ordenado por quien lo emparejó, pero quien decide es
    // esta función: si las dos cifras discrepan, manda la de fuera del arte.
    const veredicto = impresionPorArte([
      { printingUuid: 'el-del-arte', face: 'front', inliers: 500, inliersFueraDelArte: 20 },
      { printingUuid: 'el-del-marco', face: 'front', inliers: 90, inliersFueraDelArte: 60 }
    ])

    expect(veredicto?.printingUuid).toBe('el-del-marco')
    expect(veredicto?.margen).toBe(3)
  })

  it('una sola impresión con descriptores certifica sin segundo contra el que medir', () => {
    const solo = impresionPorArte([uno])

    expect(solo?.printingUuid).toBe('u1')
    expect(solo?.margen).toBe(Infinity)
  })

  it('un segundo que no casó nada tampoco es un segundo', () => {
    expect(impresionPorArte([uno, candidato('u2', 0)])?.margen).toBe(Infinity)
  })

  it('cero inliers en el primero no es un empate: es que no se parece a nada', () => {
    expect(impresionPorArte([candidato('u1', 0)])).toBeNull()
    expect(impresionPorArte([])).toBeNull()
    expect(impresionPorArte(null)).toBeNull()
  })

  it('y un ranking SIN `inliersFueraDelArte` no certifica nada', () => {
    // Un worker viejo, o una respuesta a medias. Recaer en el total sería volver
    // al margen diluido sin un solo aviso, así que cuenta cero y no se escribe.
    expect(impresionPorArte([{ printingUuid: 'u1', face: 'front', inliers: 400 }])).toBeNull()
  })

  it('MENOS DE 4 inliers fuera del arte no certifican, pase lo que pase con el cociente', () => {
    // El suelo de RANSAC: cuatro parejas es el mínimo que define una homografía.
    // Por debajo el cociente es aritmética sobre ruido, que es cómo la franja del
    // marco —1 o 2 inliers sobre 147— coronaba impresiones equivocadas en 2 de 3
    // consultas con márgenes altos.
    expect(impresionPorArte([candidato('u1', 3), candidato('u2', 1)])).toBeNull()
    expect(impresionPorArte([candidato('u1', 3)])).toBeNull()
    expect(impresionPorArte([candidato('u1', 4), candidato('u2', 1)])?.printingUuid).toBe('u1')
  })

  it('el margen NO sabe de `illustration_id`, y es lo que lo hace correcto', () => {
    // El error conceptual que ya tumbó una versión de este hito: lo que ORB
    // separa no es «ilustraciones distintas», es marco, borde, tratamiento y
    // bloque de coleccionista. `WOC 140` y `CMM 926` COMPARTEN ilustración y ORB
    // las separa 2,42x acertando; `WHO 66` y `WHO 671` también la comparten y
    // empatan en 1,18. Los dos pares de abajo son esas dos medidas reales.
    //
    // OJO CON LAS CIFRAS: son los inliers TOTALES del M0(b), que es como se
    // midieron entonces; el reparto por zonas de ese barrido no existe, y
    // rehacerlo sobre varias cartas es lo que el M11 deja pendiente. Aquí entran
    // como el cociente porque **lo que este test afirma es la regla** —decide el
    // margen, no la ilustración compartida— y esa no ha cambiado de listón.
    expect(
      impresionPorArte([
        { printingUuid: 'woc-140', inliersFueraDelArte: 92 },
        { printingUuid: 'cmm-926', inliersFueraDelArte: 38 }
      ])?.printingUuid
    ).toBe('woc-140')

    expect(
      impresionPorArte([
        { printingUuid: 'who-66', inliersFueraDelArte: 97 },
        { printingUuid: 'who-671', inliersFueraDelArte: 82 }
      ])
    ).toBeNull()
  })
})

describe('la fuente `art`: el emparejamiento en la vuelta del escáner', () => {
  const ORACLE = '4457ed35-7c10-48c8-9776-456485fdf070' // el de la fixtura ASUMIDA
  const ASUMIDO = 'be977738-775e-52d6-a151-eaae951762a4'
  const FOTO = 'ZmFrZS1qcGVn'

  /** Un bloque del tamaño real: `keypoints * 40` bytes. */
  function bloque(relleno = 3) {
    const bytes = new Uint8Array(NFEATURES * 40)

    for (let i = 0; i < bytes.length; i++) bytes[i] = (i * relleno) % 256

    return bytes
  }

  /** `scan_orb_refs` con N impresiones ya sembradas. */
  function refsSembradas(cuantas) {
    return Array.from({ length: cuantas }, (_, i) => ({
      printingUuid: i === 0 ? ASUMIDO : `otra-${i}`,
      face: 'front',
      scryfallId: `sc-${i}`,
      orb: base64DeBloque(bloque(i + 1))
    }))
  }

  /** El backend: `scan_resolve` con la fixtura pedida y `scan_orb_refs` con sus refs. */
  function backend(indice, refs) {
    apiCall.mockImplementation(async (accion, datos = {}) => {
      if (accion === 'scan_resolve') {
        return {
          status: 'success',
          http_code: 200,
          data: {
            results: datos.lecturas.map((l) => ({ ...fixtura(VEREDICTOS)[indice], id: l.id }))
          }
        }
      }

      if (accion === 'scan_orb_refs') {
        return { status: 'success', http_code: 200, data: { refs } }
      }

      if (accion === 'collection_add') return fixtura(altaFixture)

      return { status: 'error', message: `sin doble para ${accion}`, http_code: 500 }
    })
  }

  /**
   * Lo que `emparejar()` devolvería con ese margen sobre el primero.
   *
   * **El margen sale de `inliersFueraDelArte`** (M11), y los 60 del arte empatan
   * entre las dos: son los que, contados, hundirían el cociente a 1,08.
   */
  function ranking(margen, ganador = ASUMIDO) {
    return [
      { printingUuid: ganador, face: 'front', inliers: 130, inliersFueraDelArte: 70 },
      { printingUuid: 'otra-1', face: 'front', inliers: 60 + 70 / margen, inliersFueraDelArte: 70 / margen }
    ]
  }

  /**
   * Una vuelta entera con foto, esperando a que ORB termine.
   *
   * ORB va suelto a propósito —`lanzarOrb()` no devuelve promesa—, así que aquí
   * se cede el hilo unas cuantas veces en vez de esperarlo: es la prueba de que
   * la fila no depende de él para aparecer.
   */
  async function unaVueltaCon(lect = lectura({ setCode: null, collectorNumber: null })) {
    await store.detectar(lect, FOTO)
    await store.detectar(lect, FOTO)

    return dejarQueOrbTermine()
  }

  /** Cede el hilo hasta que la cadena suelta de ORB se agota. */
  async function dejarQueOrbTermine() {
    for (let i = 0; i < 20; i++) await new Promise((r) => setTimeout(r, 0))
  }

  beforeEach(() => {
    imagenBytes.mockResolvedValue(new Uint8Array([1, 2, 3, 4]))
    descriptoresDe.mockResolvedValue(bloque(9))
    emparejar.mockResolvedValue([])
  })

  it('HECHO CUANDO: un margen de 1,4 NO certifica y la fila se queda esperando tu toque', async () => {
    backend(ASUMIDA, refsSembradas(3))
    emparejar.mockResolvedValue(ranking(1.4))

    await unaVueltaCon()

    expect(emparejar).toHaveBeenCalledTimes(1)
    expect(store.filas[0].printingCertain).toBe(false)
    expect(store.filas[0].certaintySource).toBeNull()
    // Y sigue siendo una edición asumida, que es lo que saca `PrintingSelect.vue`
    // con sus candidatos: la salida correcta del empate técnico.
    expect(store.filas[0].assumedPrinting).toBe(true)
    expect(store.filas[0].printingCount).toBe(71)
  })

  /**
   * **«NUNCA EMPEORA» SIGUE EN PIE tras la enmienda de la siembra congelada.**
   *
   * Una carta sembrada en inglés que ahora se pide en español devuelve la ref
   * inglesa, y esa ref **se sigue usando para emparejar en esta misma vuelta**:
   * `trabajarOrbDe()` empareja ANTES de sembrar. Lo que la enmienda añade es que
   * además se baje la imagen española, que es la que certificará en la lectura
   * siguiente. Si alguien invirtiera el orden, la carta que ya tenía
   * descriptores se quedaría esperando detrás de una cola de descargas.
   */
  it('la ref inglesa empareja igual en la vuelta en que se siembra la española', async () => {
    const congeladas = refsSembradas(3).map((ref) => ({
      ...ref,
      language: 'English',
      scryfallLanguage: 'Spanish'
    }))

    backend(ASUMIDA, congeladas)
    emparejar.mockResolvedValue(ranking(1.6))

    await unaVueltaCon()

    // Certifica por `art` con lo que YA había en el índice...
    expect(emparejar).toHaveBeenCalledTimes(1)
    expect(store.filas[0].certaintySource).toBe('art')
    // ...y además se baja la imagen española para sembrarla.
    expect(imagenBytes).toHaveBeenCalled()
  })

  it('HECHO CUANDO: un margen de 1,6 SÍ certifica, y la fuente es `art`', async () => {
    backend(ASUMIDA, refsSembradas(3))
    emparejar.mockResolvedValue(ranking(1.6))

    await unaVueltaCon()

    expect(store.filas[0].printingCertain).toBe(true)
    expect(store.filas[0].certaintySource).toBe('art')
    expect(store.filas[0].orbMargen).toBeCloseTo(1.6, 6)
    // Los dos recuentos, porque el margen NO sale del total: 70 de los 130 son
    // de fuera del arte y son los únicos que decidieron.
    expect(store.filas[0].orbInliers).toBe(130)
    expect(store.filas[0].orbInliersFueraDelArte).toBe(70)
    expect(store.filas[0].assumedPrinting).toBe(false)
  })

  it('HECHO CUANDO: una carta con UNA sola impresión con descriptores se certifica sin segundo', async () => {
    backend(ASUMIDA, refsSembradas(1))
    emparejar.mockResolvedValue([
      { printingUuid: ASUMIDO, face: 'front', inliers: 121, inliersFueraDelArte: 61 }
    ])

    await unaVueltaCon()

    expect(store.filas[0].printingCertain).toBe(true)
    expect(store.filas[0].certaintySource).toBe('art')
    expect(store.filas[0].orbMargen).toBe(Infinity)
  })

  it('certificar es también CORREGIR: la edición asumida se cambia por la que dijo ORB', async () => {
    // El resolvedor asumió una de 71 impresiones. Quedarse con la asumida y
    // marcarla cierta sería escribir una edición que no tienes.
    backend(ASUMIDA, refsSembradas(3))
    emparejar.mockResolvedValue(ranking(2.42, 'otra-2'))

    await unaVueltaCon()

    expect(store.filas[0].printingUuid).toBe('otra-2')
    expect(store.filas[0].impresionAsumida).toBe(ASUMIDO)
    expect(store.filas[0].certaintySource).toBe('art')
    // Los tres datos de catálogo eran los de la asumida y ya no son verdad.
    expect(store.filas[0].setCode).toBeNull()
    expect(store.filas[0].collectorNumber).toBeNull()
    expect(store.filas[0].priceEur).toBeNull()
  })

  it('con manos libres, la certificada por arte se escribe SOLA', async () => {
    backend(ASUMIDA, refsSembradas(3))
    emparejar.mockResolvedValue(ranking(1.6))
    store.ajustes.manosLibres = true

    await unaVueltaCon()

    const altas = llamadasDe('collection_add')

    expect(altas).toHaveLength(1)
    expect(altas[0].printing_uuid).toBe(ASUMIDO)
    expect(store.filas[0].escritaSola).toBe(true)
  })

  it('una fila que ya venía cierta por `corner` NI SE EMPAREJA', async () => {
    // ORB **añade** una fuente, no sustituye ninguna: la primera que llega gana.
    backend(EXACTA, refsSembradas(3))
    emparejar.mockResolvedValue(ranking(9))

    await unaVueltaCon(lectura())

    expect(store.filas[0].certaintySource).toBe('corner')
    expect(emparejar).not.toHaveBeenCalled()
    expect(descriptoresDe).not.toHaveBeenCalled()
  })

  it('sin descriptores sembrados no se empareja nada: esa carta espera al siguiente escaneo', async () => {
    backend(
      ASUMIDA,
      refsSembradas(3).map((r) => ({ ...r, orb: null }))
    )

    await unaVueltaCon()

    expect(emparejar).not.toHaveBeenCalled()
    expect(store.filas[0].printingCertain).toBe(false)
    // Pero sí se siembran, que es el M2: la próxima vez habrá contra qué medir.
    expect(llamadasDe('vision_orb_store')).toHaveLength(3)
  })

  it('sin foto no hay emparejamiento, y el escáner funciona igual que antes del M3', async () => {
    backend(ASUMIDA, refsSembradas(3))
    emparejar.mockResolvedValue(ranking(9))

    await detectarConfirmando(lectura({ setCode: null, collectorNumber: null }))
    await dejarQueOrbTermine()

    expect(emparejar).not.toHaveBeenCalled()
    expect(store.filas[0].estado).toBe('resuelta')
  })

  it('ORB no pisa la impresión que el usuario eligió a mano', async () => {
    backend(ASUMIDA, refsSembradas(3))
    // El emparejamiento se retiene hasta que el usuario ya ha corregido.
    let soltar = null

    emparejar.mockImplementation(
      () => new Promise((r) => {
        soltar = () => r(ranking(9, 'otra-2'))
      })
    )

    await store.detectar(lectura({ setCode: null, collectorNumber: null }), FOTO)
    await store.detectar(lectura({ setCode: null, collectorNumber: null }), FOTO)
    await dejarQueOrbTermine()

    store.corregirImpresion(store.filas[0], 'la-que-yo-quiero')
    soltar()
    await dejarQueOrbTermine()

    expect(store.filas[0].printingUuid).toBe('la-que-yo-quiero')
    expect(store.filas[0].printingCertain).toBe(false)
  })

  it('HECHO CUANDO: el emparejamiento NO hace esperar al usuario', async () => {
    // `emparejar()` se queda colgado PARA SIEMPRE. Si estuviera en el camino de
    // la respuesta, la fila no aparecería en el menú hasta que ORB contestara.
    backend(ASUMIDA, refsSembradas(3))

    let nuncaVuelve = null

    emparejar.mockImplementation(() => new Promise((r) => { nuncaVuelve = r }))

    await store.detectar(lectura({ setCode: null, collectorNumber: null }), FOTO)
    await store.detectar(lectura({ setCode: null, collectorNumber: null }), FOTO)
    await dejarQueOrbTermine()

    expect(store.filas[0].estado).toBe('resuelta')
    expect(store.filas[0].printingUuid).toBe(ASUMIDO)
    expect(store.filas[0].printingCertain).toBe(false)
    expect(nuncaVuelve).not.toBeNull()
  })

  it('una referencia con base64 corrupto no tumba el emparejamiento', async () => {
    const refs = refsSembradas(2)

    refs[1].orb = 'esto-no-es-base64-válido'

    backend(ASUMIDA, refs)
    emparejar.mockResolvedValue([
      { printingUuid: ASUMIDO, face: 'front', inliers: 121, inliersFueraDelArte: 61 }
    ])

    await unaVueltaCon()

    // La corrupta se cae y queda UNA sola referencia útil, que certifica.
    expect(emparejar.mock.calls[0][1]).toHaveLength(1)
    expect(store.filas[0].certaintySource).toBe('art')
    // Y NO se vuelve a bajar su imagen: `INSERT IGNORE` no deja reemplazarla.
    expect(imagenBytes).not.toHaveBeenCalled()
  })

  it('el emparejamiento va ANTES que la siembra, que puede ser 36 descargas', async () => {
    const refs = refsSembradas(3)

    refs[2].orb = null

    backend(ASUMIDA, refs)
    emparejar.mockResolvedValue(ranking(1.6))

    await unaVueltaCon()

    expect(emparejar).toHaveBeenCalledBefore(imagenBytes)
    expect(store.filas[0].certaintySource).toBe('art')
  })

  it('se pregunta por las referencias UNA vez y las comparten emparejar y sembrar', async () => {
    backend(ASUMIDA, refsSembradas(3))
    emparejar.mockResolvedValue(ranking(1.4))

    await unaVueltaCon()
    await store.sembrarOrbDe(ORACLE)
    await store.emparejarPorArte(ORACLE, FOTO)

    expect(llamadasDe('scan_orb_refs')).toHaveLength(1)
  })

  it('empezarSesion() olvida también las referencias', async () => {
    backend(ASUMIDA, refsSembradas(3))

    await store.refsOrbDe(ORACLE)
    store.empezarSesion()
    await store.refsOrbDe(ORACLE)

    expect(llamadasDe('scan_orb_refs')).toHaveLength(2)
  })
})

// ==========================================================================
// La cola de siembra, que es la mitad de cliente del 429.
//
// Llegó con el M4 y NO se fue con él: `colaDeSiembra` (`stores/scan.js:423`)
// encadena TODAS las siembras de la sesión, también las del modo de una carta,
// que es el único que queda. El M4 se retiró el 2026-09-16; esto no.
// ==========================================================================

describe('la cola de siembra', () => {
  it('las siembras de nueve cartas NO salen a la vez', async () => {
    // Nueve cartas con una impresión cada una. Sin cola, `lanzarOrb()` dispara
    // nueve cadenas sueltas y el móvil sube nueve ficheros a la vez; con ella
    // hay siempre UNA descarga en vuelo.
    let enVuelo = 0
    let pico = 0

    imagenBytes.mockImplementation(async () => {
      enVuelo++
      pico = Math.max(pico, enVuelo)
      await new Promise((r) => setTimeout(r, 0))
      enVuelo--

      return new Uint8Array([1, 2, 3, 4])
    })
    descriptoresDe.mockResolvedValue(new Uint8Array(NFEATURES * 40))

    apiCall.mockImplementation(async (accion, datos = {}) => {
      if (accion === 'scan_orb_refs') {
        return {
          status: 'success',
          http_code: 200,
          data: {
            refs: [
              {
                printingUuid: `u-${datos.oracleId}`,
                face: 'front',
                scryfallId: `s-${datos.oracleId}`,
                orb: null
              }
            ]
          }
        }
      }

      if (accion === 'vision_orb_store') return ''

      return { status: 'error', http_code: 500 }
    })

    const cartas = Array.from({ length: 9 }, (_, i) => `oracle-${i}`)

    await Promise.all(cartas.map((oracleId) => store.sembrarOrbDe(oracleId)))

    expect(llamadasDe('vision_orb_store')).toHaveLength(9)
    expect(pico).toBe(1)
  })

  it('una siembra que revienta no deja la cola muerta para el resto de la sesión', async () => {
    imagenBytes.mockRejectedValueOnce(new Error('la red se fue'))
    imagenBytes.mockResolvedValue(new Uint8Array([1, 2, 3, 4]))
    descriptoresDe.mockResolvedValue(new Uint8Array(NFEATURES * 40))

    apiCall.mockImplementation(async (accion, datos = {}) => {
      if (accion === 'scan_orb_refs') {
        return {
          status: 'success',
          http_code: 200,
          data: {
            refs: [
              {
                printingUuid: `u-${datos.oracleId}`,
                face: 'front',
                scryfallId: `s-${datos.oracleId}`,
                orb: null
              }
            ]
          }
        }
      }

      if (accion === 'vision_orb_store') return ''

      return { status: 'error', http_code: 500 }
    })

    expect(await store.sembrarOrbDe('oracle-mala')).toBe(0)
    expect(await store.sembrarOrbDe('oracle-buena')).toBe(1)
  })
})

/**
 * **El M8**: el idioma se detecta solo, por el nombre que resolvió.
 *
 * Lo motiva una prueba de campo del 2026-09-16: con el selector en **inglés**,
 * una *Linterna cromática* española no detectaba su edición —ORB sembraba y
 * emparejaba contra las referencias inglesas— y poniendo el selector en español
 * sí. El hito es que el usuario no tenga que saberlo.
 *
 * La cascada tiene cuatro escalones y el cliente solo decide el primero y el
 * último: el código impreso en la esquina manda, y cuando el backend no detecta
 * nada no pasa absolutamente nada — la fila se queda con `ajustes.language`,
 * que es lo de antes del hito. **Ese escalón es el que garantiza que esto no
 * pueda empeorar nada.**
 */
describe('el idioma detectado por el nombre', () => {
  it('lo adopta cuando la carta no traía el código impreso', async () => {
    cola = [LOCALIZADA]

    // El selector dice inglés —lo que David tenía puesto— y la carta es
    // española. El backend lo dedujo del nombre, no del ajuste.
    expect(store.ajustes.language).toBe(POR_DEFECTO.language)
    await detectarConfirmando(lectura({ name: 'Llanura', setCode: null, collectorNumber: null, language: null }))

    expect(VEREDICTOS[LOCALIZADA].language).toBe('Spanish')
    expect(store.filas[0].language).toBe('Spanish')
    // Los DOS sitios: `fila.language` es lo que se escribe en la colección y
    // `fila.lectura.language` lo que lee la cadena de ORB.
    expect(store.filas[0].lectura.language).toBe('Spanish')
  })

  it('NO pisa el código impreso en la esquina, que es lo único impreso en la carta', async () => {
    cola = [LOCALIZADA]

    await detectarConfirmando(lectura({ name: 'Llanura', setCode: null, collectorNumber: null, language: 'German' }))

    expect(store.filas[0].language).toBe('German')
    expect(store.filas[0].lectura.language).toBe('German')
  })

  it('un veredicto sin idioma detectado deja la fila en el ajuste del usuario', async () => {
    // `EXACTA` resolvió por (edición, número): identifica la impresión, pero no
    // dice en qué idioma está impresa. El backend contesta `null` a propósito.
    expect(VEREDICTOS[EXACTA].language).toBeNull()

    store.fijarDimension('language', 'German')
    await detectarConfirmando(lectura({ language: null }))

    expect(store.filas[0].language).toBe('German')
    expect(store.filas[0].lectura.language).toBeNull()
  })

  it('y lo detectado es lo que se escribe en la colección', async () => {
    cola = [LOCALIZADA]

    await detectarConfirmando(lectura({ name: 'Llanura', setCode: null, collectorNumber: null, language: null }))
    await store.escribir(store.filas[0])

    expect(llamadasDe('collection_add')[0].language).toBe('Spanish')
  })

  it('pero a ORB no se le pide nada: una Llanura son 910 impresiones y el M10 la topa', async () => {
    // Este test decía `scan_orb_refs[0].language === 'Spanish'` y se puso rojo al
    // entrar el M10, **con razón**: la fixtura `LOCALIZADA` es una Plains de 910
    // impresiones, o sea justo la carta que el tope existe para excluir. Que el
    // idioma de la fila llegue a ORB lo cubre el test «`scan_orb_refs` va en el
    // MISMO idioma que la línea de colección», con una carta que sí se empareja.
    cola = [LOCALIZADA]

    await detectarConfirmando(lectura({ name: 'Llanura', setCode: null, collectorNumber: null, language: null }))

    expect(store.filas[0].printingCount).toBeGreaterThan(MAXIMO_DE_IMPRESIONES_PARA_ORB)
    expect(llamadasDe('scan_orb_refs')).toHaveLength(0)
    expect(llamadasDe('vision_orb_store')).toHaveLength(0)
  })
})

// ==========================================================================
// M10 · el tope de impresiones
// ==========================================================================

describe('el tope de impresiones (M10)', () => {
  /**
   * POR QUÉ EL TOPE CORTA EN `trabajarOrbDe()` Y NO EN `esEmparejable()`.
   *
   * Ahí corta las TRES cosas de un golpe —la consulta, la siembra y el
   * emparejamiento—. Gatear solo el emparejamiento seguiría bajando hasta 910
   * imágenes por una carta que no va a certificar nunca, que es el gasto que de
   * verdad duele.
   *
   * Medido el 2026-09-16: `ms = −15,0 + 14,713·n`, o sea ~38 s en el Realme con
   * las 910 impresiones de una Plains, contra ~2,9 s con las 71 de una Lightning
   * Bolt. El tope está en 100 para dejar fuera SOLO las 7 cartas del catálogo que
   * pasan de 100 —las tierras básicas— y conservar las 265 del tramo 21-100.
   */
  it('una carta por DEBAJO del tope sí pasa por ORB', async () => {
    // `ASUMIDA` es una Lightning Bolt de 71 impresiones: muy reimpresa, que es
    // el caso para el que ORB existe, y por debajo de 100.
    cola = [ASUMIDA]

    await detectarConfirmando(lectura({ name: 'Lightning Bolt', setCode: null, collectorNumber: null }))

    expect(store.filas[0].printingCount).toBe(71)
    expect(llamadasDe('scan_orb_refs').length).toBeGreaterThan(0)
  })

  it('y el tope es el número, no la carta: 100 exactas pasan, 101 no', () => {
    const store2 = useScanStore()

    store2.filas = [{ oracleId: 'o', printingCount: MAXIMO_DE_IMPRESIONES_PARA_ORB }]
    expect(store2.demasiadasImpresiones('o')).toBe(false)

    store2.filas = [{ oracleId: 'o', printingCount: MAXIMO_DE_IMPRESIONES_PARA_ORB + 1 }]
    expect(store2.demasiadasImpresiones('o')).toBe(true)
  })

  it('un `printingCount` de 0 NO bloquea: es «no se sabe», no «son muchas»', () => {
    const store2 = useScanStore()

    store2.filas = [{ oracleId: 'o', printingCount: 0 }]
    expect(store2.demasiadasImpresiones('o')).toBe(false)
  })
})
