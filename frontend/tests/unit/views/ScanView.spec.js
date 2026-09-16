import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import ScanView from '@/views/ScanView.vue'
import { SCRIPT_OCR_POR_DEFECTO, scriptDeOcr } from '@/constants/collection'
import { useScanStore } from '@/stores/scan'

import { SESION, montarVista } from '../../helpers'

/**
 * `views/ScanView.vue` — la pantalla del escáner.
 *
 * **Nace con el M3 y es el único fichero grande del escáner que no tenía
 * suite**, precisamente el que el M3 reescribe. Lo que aquí se prueba no es el
 * OCR —eso vive en `services/scanParser.js` y tiene el suyo— sino las tres cosas
 * que rompen la pantalla entera y que ningún otro test puede ver:
 *
 *  1. **El arranque.** Sesión limpia, fondo transparente y cámara en marcha.
 *  2. **El rechazo de permiso.** Una pantalla negra sin explicación es el bug
 *     que más tiempo cuesta de todos, y el plugin rechaza la promesa igual si el
 *     permiso se deniega que si la cámara falla por otra cosa: lo único que los
 *     distingue es el mensaje.
 *  3. **El desmontaje.** `onUnmounted` es el ÚNICO gancho por el que se sale de
 *     aquí —el botón de atrás de Android no dispara ningún otro—, así que si no
 *     devuelve el fondo, **la app entera se queda transparente** y parece rota
 *     desde cualquier otra vista.
 *
 * Los tres plugins de Capacitor se doblan porque en jsdom no existen. No es una
 * concesión: lo que se prueba es el contrato de la vista CON ellos —qué llama,
 * en qué orden y qué hace cuando fallan—, que es exactamente lo que se rompe al
 * tocarla. Todo lo demás se monta de verdad con `montarVista()`: router real y
 * PrimeVue real, como manda la suite de este proyecto.
 */

/**
 * Los dobles tienen latencia A PROPÓSITO.
 *
 * El bucle es un `while` que encadena promesas, así que con dobles que resuelven
 * en el mismo tick gira tan rápido que no deja avanzar nada más: jsdom se queda
 * sin margen y el worker de Vitest muere con `SIGABRT` — pasó, y costó dos
 * minutos de ejecución antes de morirse. Un milisegundo de espera basta para que
 * el bucle ceda el hilo, que es lo que hace una cámara de verdad y mucho más
 * despacio (366 ms por vuelta, medidos en el Realme).
 */
const tic = () => new Promise((r) => setTimeout(r, 1))

const camara = {
  start: vi.fn(async () => {}),
  stop: vi.fn(async () => {}),
  captureSample: vi.fn(async () => {
    await tic()

    return { value: 'BASE64FALSO' }
  })
}

const mlkit = {
  processImage: vi.fn(async () => {
    await tic()

    return { blocks: [] }
  })
}

const ficheros = {
  writeFile: vi.fn(async () => ({ uri: 'file:///tmp/x.jpg' })),
  deleteFile: vi.fn(async () => {}),
  rmdir: vi.fn(async () => {})
}

vi.mock('@capacitor-community/camera-preview', () => ({
  CameraPreview: {
    start: (...args) => camara.start(...args),
    stop: (...args) => camara.stop(...args),
    captureSample: (...args) => camara.captureSample(...args)
  }
}))

vi.mock('@capacitor-mlkit/text-recognition', () => ({
  TextRecognition: { processImage: (...args) => mlkit.processImage(...args) }
}))

vi.mock('@capacitor/filesystem', () => ({
  Filesystem: {
    writeFile: (...args) => ficheros.writeFile(...args),
    deleteFile: (...args) => ficheros.deleteFile(...args),
    rmdir: (...args) => ficheros.rmdir(...args)
  },
  Directory: { Cache: 'CACHE' }
}))

vi.mock('@/services/api', () => ({ apiCall: vi.fn(async () => ({ status: 'success', data: { results: [] } })) }))

/**
 * Monta `/scan` con el andamiaje real y espera a que el arranque asíncrono haya
 * corrido: `onMounted` dispara `arrancarCamara()` sin aguardarlo.
 */
async function montar(estado = {}) {
  const { wrapper } = await montarVista(ScanView, {
    ruta: '/scan',
    estado: { ...SESION, ...estado }
  })

  await vi.waitFor(() => expect(camara.start).toHaveBeenCalled())
  await wrapper.vm.$nextTick()

  return wrapper
}

beforeEach(() => {
  vi.clearAllMocks()
  camara.start.mockImplementation(async () => {})
  camara.stop.mockImplementation(async () => {})
})

afterEach(() => {
  // El fondo es global: si un test lo deja tocado, ensucia a los siguientes
  // exactamente igual que ensuciaría la app.
  document.documentElement.style.background = ''
  document.body.style.background = ''
})

describe('el arranque', () => {
  it('empieza sesión limpia, pone el fondo transparente y arranca la cámara', async () => {
    // Se siembra por `initialState` y no tocando el store después: la Pinia la
    // crea `montarVista()`, así que antes de montar no hay ninguna activa.
    const wrapper = await montar({
      scan: { filas: [{ id: 'viejo' }], escritas: new Set(['algo']) }
    })

    const store = useScanStore()

    // Sesión limpia: entrar a `/scan` no arrastra lo de la vez anterior.
    expect(store.filas).toEqual([])
    expect(store.escritas.size).toBe(0)

    // El fondo transparente no es estilo: sin él la previsualización va DETRÁS
    // del webview y no se ve nada, que parece que el plugin no arrancó.
    expect(document.body.style.background).toBe('transparent')

    // Cámara trasera y `toBack`, que es lo que la pone detrás del webview.
    expect(camara.start).toHaveBeenCalledWith(
      expect.objectContaining({ position: 'rear', toBack: true })
    )

    wrapper.unmount()
  })

  it('los ajustes de sesión SOBREVIVEN al arranque: son lo único que el usuario eligió', async () => {
    const wrapper = await montar({
      scan: { ajustes: { destino: 'coleccion', finish: 'foil', language: 'Spanish', condition: 'NM' } }
    })

    const store = useScanStore()

    // `empezarSesion()` vacía filas y memoria, pero NO los ajustes: hacer repetir
    // el destino y las tres dimensiones cada vez que se entra a `/scan` es
    // exactamente lo que el plan llamó «ajustes de sesión» para evitar.
    expect(store.ajustes.finish).toBe('foil')
    expect(store.ajustes.language).toBe('Spanish')

    wrapper.unmount()
  })
})

describe('el rechazo de permiso', () => {
  it('pinta la explicación cuando el mensaje del plugin habla de permiso', async () => {
    camara.start.mockImplementation(async () => {
      throw new Error('Camera permission denied')
    })

    const wrapper = await montar()
    await wrapper.vm.$nextTick()

    expect(wrapper.text()).toMatch(/permiso/i)

    wrapper.unmount()
  })

  it('un fallo que NO es de permiso no se pinta como si lo fuera', async () => {
    // El plugin rechaza igual en los dos casos y el mensaje es lo único que los
    // separa. Importa porque la salida del usuario es distinta: una se arregla
    // en Ajustes del móvil y la otra no.
    camara.start.mockImplementation(async () => {
      throw new Error('Camera is already running')
    })

    const wrapper = await montar()
    await wrapper.vm.$nextTick()

    expect(wrapper.text()).toContain('Camera is already running')
    expect(wrapper.text()).not.toMatch(/ve a ajustes/i)

    wrapper.unmount()
  })
})

describe('el desmontaje, que es por donde se sale con el botón de atrás', () => {
  it('para la cámara, DEVUELVE el fondo y vacía los temporales', async () => {
    const wrapper = await montar()

    expect(document.body.style.background).toBe('transparent')

    wrapper.unmount()
    await vi.waitFor(() => expect(camara.stop).toHaveBeenCalled())

    // Si esto no se deshace, la app entera se queda transparente: `App.vue` son
    // veinte líneas y no sabe nada de la cámara.
    expect(document.body.style.background).not.toBe('transparent')
    expect(document.documentElement.style.background).not.toBe('transparent')

    // Y los JPEG: a tres vueltas por segundo son 180 ficheros por minuto.
    await vi.waitFor(() => expect(ficheros.rmdir).toHaveBeenCalled())
  })

  it('devuelve el fondo aunque parar la cámara falle', async () => {
    camara.stop.mockImplementation(async () => {
      throw new Error('nada que parar')
    })

    const wrapper = await montar()

    wrapper.unmount()
    await vi.waitFor(() => expect(camara.stop).toHaveBeenCalled())

    expect(document.body.style.background).not.toBe('transparent')
  })
})

describe('con la pantalla apagada no se escanea', () => {
  /** Apaga o enciende la «pantalla»: es lo que hace el webview de Capacitor. */
  async function visibilidad(oculto) {
    Object.defineProperty(document, 'hidden', { value: oculto, configurable: true })
    document.dispatchEvent(new Event('visibilitychange'))
    await new Promise((r) => setTimeout(r, 0))
  }

  it('al ocultarse la vista, para la cámara', async () => {
    const wrapper = await montar()

    expect(camara.stop).not.toHaveBeenCalled()

    await visibilidad(true)

    // Medido con `adb` el 2026-09-15: sin esto el bucle seguía capturando
    // fotogramas negros con el móvil en reposo, ~3 por segundo, escribiendo un
    // JPEG cada vez. Ni un dato útil y toda la batería.
    await vi.waitFor(() => expect(camara.stop).toHaveBeenCalled())

    await visibilidad(false)
    wrapper.unmount()
  })

  it('y al volver la rearranca', async () => {
    const wrapper = await montar()

    await visibilidad(true)
    await vi.waitFor(() => expect(camara.stop).toHaveBeenCalled())

    camara.start.mockClear()
    await visibilidad(false)

    await vi.waitFor(() => expect(camara.start).toHaveBeenCalled())

    wrapper.unmount()
  })

  it('el listener no sobrevive a la vista', async () => {
    const wrapper = await montar()

    wrapper.unmount()
    await vi.waitFor(() => expect(camara.stop).toHaveBeenCalled())

    camara.start.mockClear()
    await visibilidad(false)

    // Vive en `document`, así que sin quitarlo estaría rearrancando la cámara
    // desde una pantalla que ya no existe.
    expect(camara.start).not.toHaveBeenCalled()

    await visibilidad(false)
  })
})

describe('la foto de la vuelta llega al store, que es de lo que vive ORB', () => {
  it('`detectar()` recibe el mismo base64 que devolvió `captureSample()`', async () => {
    // EL CABLE DEL M3, y no hay otro sitio donde se vea. `cardOrb.emparejar()`
    // necesita los píxeles de ESTA vuelta, y lo único que los tiene es la vista:
    // si este argumento se pierde, la fuente `art` no se emite nunca y todo lo
    // demás —el margen, la verja, la siembra— sigue pasando en verde.
    mlkit.processImage.mockImplementation(async () => {
      await tic()

      return { blocks: [{ lines: [{ text: 'Rampant Growth', boundingBox: { top: 10 } }] }] }
    })

    const wrapper = await montar()
    const store = useScanStore()
    const detectar = vi.spyOn(store, 'detectar')

    await vi.waitFor(() => expect(detectar).toHaveBeenCalled())

    const [leida, foto] = detectar.mock.calls[0]

    expect(leida.name).toBe('Rampant Growth')
    expect(foto).toBe('BASE64FALSO')

    wrapper.unmount()
  })
})

// ==========================================================================
// M9 — el OCR deja de leer solo alfabeto latino
// ==========================================================================

/**
 * **El script del OCR sale del SELECTOR de idioma.**
 *
 * Hasta el M9 era la constante `SCRIPT_LATINO = 'LATIN'`, y era el único eslabón
 * roto del japonés: el paso 3c del resolvedor ya casa `隊商の夜番` con *Caravan
 * Vigil* y el M8 ya detecta el idioma, pero el modelo latino no leía un solo
 * carácter que mandarle. Los cinco modelos ya viajan dentro del APK, así que
 * activarlos no suma un byte.
 *
 * **Y tiene que salir del selector, no del idioma detectado**: el OCR ocurre
 * ANTES de la resolución, así que cuando hay que elegir el modelo la detección
 * del M8 todavía no existe. Este es el único punto de toda la cadena donde el
 * selector es insustituible, y por eso se fija aquí.
 */
describe('el script del OCR sale del selector de idioma', () => {
  /**
   * **Hay que esperar a que muera el bucle del test anterior.** `cancelado` no
   * para el bucle: se lo pide, y la vuelta en curso todavía tiene que terminar
   * su OCR. Esa vuelta rezagada deja una llamada a `processImage()` con el
   * idioma del test de antes, y mirar `calls[0]` sin más lee esa —es un fallo
   * que aparece solo al correr el fichero entero, nunca al correr el test solo—.
   */
  async function bucleEnSilencio() {
    let previas = -1

    while (previas !== mlkit.processImage.mock.calls.length) {
      previas = mlkit.processImage.mock.calls.length
      await new Promise((r) => setTimeout(r, 5))
    }

    mlkit.processImage.mockClear()
  }

  /** Monta el escáner con el selector puesto y devuelve el `script` de su primera vuelta. */
  async function scriptCon(language) {
    await bucleEnSilencio()

    const wrapper = await montar({
      scan: { ajustes: { destino: 'coleccion', finish: 'normal', language, condition: 'NM' } }
    })

    await vi.waitFor(() => expect(mlkit.processImage).toHaveBeenCalled())

    const { script } = mlkit.processImage.mock.calls[0][0]

    wrapper.unmount()

    return script
  }

  it('HECHO CUANDO: con el selector en japonés, `processImage()` recibe `JAPANESE`', async () => {
    expect(await scriptCon('Japanese')).toBe('JAPANESE')
  })

  it('HECHO CUANDO: con el selector en una lengua latina recibe `LATIN`', async () => {
    expect(await scriptCon('Spanish')).toBe('LATIN')
  })

  it('y el chino tradicional también tiene su modelo, que es `CHINESE`', async () => {
    expect(await scriptCon('Chinese Traditional')).toBe('CHINESE')
  })

  /**
   * El mapa entero, sin montar la vista once veces. Vive junto a `IDIOMAS` por
   * el mismo motivo que `IDIOMA_POR_CODIGO_IMPRESO`: sus claves son `value`s de
   * esa lista, así que el día que la lista cambie esto tiene que cambiar al lado.
   */
  describe('`scriptDeOcr()`, el mapa', () => {
    it('los cuatro idiomas no latinos tienen modelo propio', () => {
      expect(scriptDeOcr('Japanese')).toBe('JAPANESE')
      expect(scriptDeOcr('Korean')).toBe('KOREAN')
      expect(scriptDeOcr('Chinese Simplified')).toBe('CHINESE')
      expect(scriptDeOcr('Chinese Traditional')).toBe('CHINESE')
    })

    it('las ocho lenguas latinas, y `Phyrexian`, caen al latino', () => {
      const latinas = [
        'English',
        'Spanish',
        'French',
        'German',
        'Italian',
        'Portuguese (Brazil)',
        'Russian',
        'Phyrexian'
      ]

      for (const idioma of latinas) expect(scriptDeOcr(idioma)).toBe('LATIN')
    })

    /**
     * **`LATIN` por defecto y no un error.** Un idioma nuevo en `IDIOMAS` que
     * nadie mapeara aquí tiene que seguir leyéndose en latino —que es lo que
     * hacía el escáner entero hasta el M9— en vez de dejar el escáner mudo.
     */
    it('un idioma desconocido, o ninguno, se lee en latino', () => {
      expect(scriptDeOcr('Klingon')).toBe(SCRIPT_OCR_POR_DEFECTO)
      expect(scriptDeOcr(null)).toBe('LATIN')
      expect(scriptDeOcr(undefined)).toBe('LATIN')
      expect(scriptDeOcr('')).toBe('LATIN')
    })
  })
})
