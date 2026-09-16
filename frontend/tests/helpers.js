import { mount } from '@vue/test-utils'
import { nextTick } from 'vue'
import { createTestingPinia } from '@pinia/testing'
import PrimeVue from 'primevue/config'
import Aura from '@primevue/themes/aura'
import { vi } from 'vitest'

import router from '@/router'

/**
 * El único sitio que sabe montar una vista de este proyecto.
 *
 * Existe para que los ~40 tests no repitan el arranque, pero sobre todo por una
 * línea: el `await router.isReady()`. Las rutas de `router/index.js:13-70` son
 * todas `import()` perezosos, así que sin esa espera el componente todavía no ha
 * resuelto y el test asserta contra un wrapper vacío —en verde y en falso—.
 */

/**
 * El mismo preset Aura que instala `main.js:18-28`, copiado literal.
 *
 * Se monta PrimeVue DE VERDAD y no stubs: `RouterLinkStub` no resuelve la ruta,
 * y el fallo que motivó esta suite (`/decks` con un `params.id` a `undefined`)
 * lo produce el router al resolver, no el componente al pintar. M0 midió el
 * coste: 101 ms de montaje, muy por debajo del umbral de 2 s.
 */
export const OPCIONES_PRIMEVUE = {
  ripple: true,
  theme: {
    preset: Aura,
    options: {
      prefix: 'p',
      cssLayer: false,
      darkModeSelector: '.app-dark'
    }
  }
}

/**
 * Estado inicial de Pinia con sesión iniciada, para el parámetro `estado`.
 *
 * Casi toda ruta es privada y el guard de `router/index.js:83-96` manda a
 * `/login` a quien no tenga sesión, así que sin sembrar esto el test navega a
 * otro sitio sin decir nada. `isAuthenticated` es estado plano de
 * `stores/auth.js:17`, no un getter derivado: sembrarlo directo basta.
 *
 * Úsese como `estado: SESION` o `estado: { ...SESION, deck: { … } }`.
 */
export const SESION = {
  auth: {
    isAuthenticated: true,
    user: { id: 1, username: 'tester', display_name: 'Tester' }
  }
}

/** El `router.onError` del montaje anterior, para no acumularlos en el singleton. */
let quitarOnError = null

/**
 * Monta una vista con el andamiaje real de la app: router de verdad ya navegado,
 * Pinia fresca sembrada y PrimeVue con el preset de producción.
 *
 * La red NO se toca aquí: la frontera de mock es `@/services/api` y la declara
 * cada spec con su `vi.mock('@/services/api')` —la única excepción es
 * `tests/unit/services/api.spec.js`, que mockea `axios` porque prueba justo esa
 * capa—.
 *
 * @param {object} Componente          El componente de vista a montar.
 * @param {object} [opciones]
 * @param {string} [opciones.ruta='/'] Ruta a la que navegar ANTES de montar.
 * @param {object} [opciones.estado]   `initialState` de Pinia, por store.
 *                                     Véase `SESION`.
 * @param {Element|string} [opciones.attachTo] Ancla en el DOM real. Necesario
 *   para lo que PrimeVue teletransporta fuera del wrapper (`Popover` de
 *   `AddToCollectionButton.vue:34`).
 * @param {object} [opciones.global]   Se funde con el `global` del montaje
 *   (plugins, stubs, provide… se añaden a los de aquí, no los sustituyen).
 * @returns {Promise<{wrapper: object, router: object, pinia: object, errores: Error[]}>}
 *   `errores` es el array VIVO de los errores capturados; véase abajo.
 */
export async function montarVista(Componente, opciones = {}) {
  const { ruta = '/', estado = {}, attachTo, global: globalUsuario = {}, ...resto } = opciones

  /**
   * La Pinia va PRIMERO y no es cosmético: el guard llama a `useAuthStore()`
   * durante la navegación, así que tiene que haber una Pinia activa antes del
   * `push`. `createTestingPinia` la deja activa al crearla.
   *
   * `stubActions: false` porque con el valor por defecto (`true`) las acciones
   * no se ejecutan y una vista que llama a `listar()` no cargaría nada: se
   * estaría comprobando que un espía fue llamado y nada más.
   *
   * `createSpy: vi.fn` es obligatorio mientras `globals` esté desactivado en
   * `vitest.config.js` (lo midió M0).
   */
  const pinia = createTestingPinia({
    createSpy: vi.fn,
    stubActions: false,
    initialState: estado
  })

  /**
   * Las rutas se IMPORTAN de `@/router`, nunca se redeclaran aquí: una copia de
   * las rutas en los tests se desincroniza sola y entonces el test valida una
   * app que no existe.
   */
  await router.push(ruta)
  await router.isReady()

  /**
   * Los errores del router y del render llegan de forma asíncrona —M0 lo
   * comprobó: al alterar la fixture, lo que falló primero fue el recuento de
   * mazos, no el error— y por defecto se pierden en la consola. Aquí se
   * recogen y se EXPONEN, para que un test pueda escribir
   * `expect(errores).toHaveLength(0)` o assertar sobre el mensaje.
   */
  const errores = []

  if (quitarOnError) quitarOnError()
  quitarOnError = router.onError((error) => errores.push(error))

  const wrapper = mount(Componente, {
    ...resto,
    ...(attachTo ? { attachTo } : {}),
    global: {
      ...globalUsuario,
      plugins: [
        pinia,
        router,
        [PrimeVue, OPCIONES_PRIMEVUE],
        ...(globalUsuario.plugins ?? [])
      ],
      // `global.config` se vuelca sobre `app.config` (VTU lo hace en
      // `createInstance`). Ojo: los errores lanzados DURANTE el montaje los
      // relanza el propio VTU, así que esos llegan como excepción de
      // `montarVista`; aquí se recogen los posteriores, que son los asíncronos.
      config: {
        errorHandler: (error) => errores.push(error),
        ...(globalUsuario.config ?? {})
      }
    }
  })

  return { wrapper, router, pinia, errores }
}

/* ── Gestos de puntero sobre `CardImage` ──────────────────────────────────── */

/**
 * Los dos retardos que `CardImage.vue` espera antes de ampliar: 450 ms con el
 * dedo (`RETARDO_PULSACION`) y 350 ms con el ratón (`RETARDO_HOVER`). Se repiten
 * aquí a propósito: el componente no los exporta y un test que los importara del
 * SFC estaría afirmando el valor contra sí mismo.
 */
export const RETARDO_PULSACION = 450
export const RETARDO_HOVER = 350

/**
 * **Los `PointerEvent` se disparan con `dispatchEvent` y NO con el `trigger()` de
 * `@vue/test-utils`**, y no es una manía: `trigger` asigna las propiedades extra
 * DESPUÉS de construir el evento, y en jsdom 30 `clientX` y `clientY` son
 * captadores de solo lectura heredados de `MouseEvent.prototype`, así que la
 * asignación **lanza** (`Cannot set property clientX`). Por el constructor entran
 * sin problema. `CardImage` escucha con `addEventListener` sobre su raíz, así que
 * un evento nativo le llega igual.
 */
function dispararPuntero(elemento, tipo, extra = {}) {
  return elemento.dispatchEvent(
    new PointerEvent(tipo, {
      bubbles: true,
      cancelable: true,
      clientX: 0,
      clientY: 0,
      ...extra
    })
  )
}

/** El nodo real detrás de un `DOMWrapper` de VTU, o el nodo tal cual. */
const nodoDe = (objetivo) => objetivo.element ?? objetivo

/**
 * Una pulsación larga en táctil sobre `objetivo`, entera: `pointerdown` con
 * `pointerType: 'touch'`, el reloj adelantado hasta pasar los 450 ms y
 * `pointerup`. Con `arrastre` en píxeles, mete un `pointermove` a mitad del
 * camino — que es como se prueba el aborto por scroll.
 *
 * Los temporizadores falsos se encienden y se apagan aquí dentro: fuera de este
 * helper hacen falta los de verdad, porque el `flushPromises` de VTU espera a un
 * `setTimeout` y con el reloj congelado no vuelve nunca.
 *
 * Con `soltar: false` el gesto se queda **vivo**, sin el `pointerup` final, y lo
 * que se devuelve es la función que lo suelta. Es la única forma de mirar la
 * ampliación mientras está abierta: soltar el dedo la cierra, así que un test que
 * solo mire el final no distingue «se abrió y se cerró» de «no se abrió nunca».
 *
 * @param {object|Element} objetivo  Un `DOMWrapper` de VTU o un nodo del DOM.
 * @param {object} [opciones]
 * @param {number} [opciones.arrastre=0]  Píxeles que se desplaza el dedo.
 * @param {boolean} [opciones.soltar=true]  `false` deja el dedo abajo.
 * @returns {Promise<Function|undefined>} Con `soltar: false`, la función que
 *   dispara el `pointerup` pendiente.
 */
export async function pulsacionLarga(objetivo, { arrastre = 0, soltar = true } = {}) {
  const elemento = nodoDe(objetivo)

  const disparar = (tipo, extra = {}) =>
    dispararPuntero(elemento, tipo, { pointerType: 'touch', ...extra })

  vi.useFakeTimers()

  try {
    disparar('pointerdown')

    if (arrastre) {
      vi.advanceTimersByTime(RETARDO_PULSACION / 2)
      disparar('pointermove', { clientX: arrastre })
    }

    vi.advanceTimersByTime(RETARDO_PULSACION)
  } finally {
    vi.useRealTimers()
  }

  await nextTick()

  const soltarDedo = async () => {
    disparar('pointerup')
    await nextTick()
  }

  if (!soltar) {
    return soltarDedo
  }

  await soltarDedo()
}

/**
 * El hermano del anterior para el ratón: el `pointerenter` con
 * `pointerType: 'mouse'` —el único que `CardImage.vue` atiende en
 * `alEntrarPuntero()`— y el reloj adelantado `espera` ms, 350 por defecto. Lo que
 * devuelve es la función que saca el cursor (`pointerleave`), que es lo que
 * cierra la ampliación.
 *
 * Vale lo dicho arriba sobre `dispatchEvent`, y una cosa más: `pointerenter` y
 * `pointerleave` **no burbujean** —tampoco en un navegador—, así que el objetivo
 * tiene que ser la **raíz** del componente (`.carta-img`), que es donde
 * `onMounted` registró los listeners, y no el `<img>` de dentro.
 *
 * @param {object|Element} objetivo  La raíz `.carta-img`, no la imagen.
 * @param {object} [opciones]
 * @param {number} [opciones.espera=RETARDO_HOVER]  ms que el cursor se queda.
 * @returns {Promise<Function>} La función que dispara el `pointerleave`.
 */
export async function hoverDeRaton(objetivo, { espera = RETARDO_HOVER } = {}) {
  const elemento = nodoDe(objetivo)

  const disparar = (tipo) =>
    dispararPuntero(elemento, tipo, { bubbles: false, pointerType: 'mouse' })

  vi.useFakeTimers()

  try {
    disparar('pointerenter')
    vi.advanceTimersByTime(espera)
  } finally {
    vi.useRealTimers()
  }

  await nextTick()

  return async () => {
    disparar('pointerleave')
    await nextTick()
  }
}
