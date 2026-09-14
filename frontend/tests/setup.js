import { afterEach, beforeEach, vi } from 'vitest'
import { enableAutoUnmount } from '@vue/test-utils'

/**
 * Arranque común de TODOS los ficheros de test (`setupFiles` de
 * `vitest.config.js`). Se ejecuta una vez por fichero, antes que el propio test.
 *
 * Aquí va solo lo que jsdom no trae y el código de producción da por hecho, más
 * la limpieza entre tests. Lo que sabe montar un componente vive en
 * `tests/helpers.js`; lo que dobla la red lo declara cada spec con
 * `vi.mock('@/services/api')`.
 */

/**
 * jsdom no implementa `matchMedia` y el `Select` de PrimeVue lo llama al montar
 * (`node_modules/primevue/select/Select.vue`). Sin esto, ninguna vista con un
 * desplegable —CatalogView, CollectionView, PreconsView— se puede montar.
 */
if (!window.matchMedia) {
  window.matchMedia = vi.fn().mockImplementation((query) => ({
    matches: false,
    media: query,
    onchange: null,
    addListener: vi.fn(),
    removeListener: vi.fn(),
    addEventListener: vi.fn(),
    removeEventListener: vi.fn(),
    dispatchEvent: vi.fn()
  }))
}

/**
 * Ídem con `ResizeObserver`, que usa el `Popover`
 * (`node_modules/primevue/popover/Popover.vue`) — el de
 * `AddToCollectionButton.vue:34`, que además teletransporta su contenido fuera
 * del wrapper.
 */
if (!window.ResizeObserver) {
  window.ResizeObserver = class {
    observe() {}
    unobserve() {}
    disconnect() {}
  }
}

/**
 * Y el tercero, por el mismo motivo que los dos de arriba: jsdom tampoco trae
 * `IntersectionObserver` y **tres vistas lo construyen en su `onMounted`** para
 * el centinela del scroll infinito —`PreconsView.vue:273`, `CatalogView.vue:259`
 * y `CollectionView.vue:368`—, así que sin él esas vistas **no se pueden
 * montar**. M4 lo tuvo local en `PreconsView.spec.js` y dejó la decisión de
 * subirlo a M5; sube aquí porque lo necesitan tres, igual que `matchMedia` lo
 * pide todo desplegable y `ResizeObserver` todo `Popover`.
 *
 * Las instancias se guardan en `window.observadoresDeInterseccion` para poder
 * disparar el centinela a mano, que es lo que hace el navegador al llegar al
 * final de la rejilla. El array **es siempre el mismo objeto** —se vacía, no se
 * sustituye—, así que un spec puede quedárselo en una constante de módulo.
 */
const observadoresDeInterseccion = []

window.observadoresDeInterseccion = observadoresDeInterseccion

if (!window.IntersectionObserver) {
  window.IntersectionObserver = class {
    constructor(callback, opciones) {
      this.callback = callback
      this.opciones = opciones
      this.observados = []
      observadoresDeInterseccion.push(this)
    }

    observe(elemento) { this.observados.push(elemento) }
    unobserve() {}
    disconnect() { this.desconectado = true }

    /** Lo que hace el navegador cuando el centinela entra en pantalla. */
    entraEnPantalla() { return this.callback([{ isIntersecting: true }]) }
  }
}

beforeEach(() => {
  // Cada test monta su vista y construye su propio observador: sin vaciarlo,
  // `observadoresDeInterseccion[0]` sería el del test anterior, ya desmontado.
  observadoresDeInterseccion.length = 0

  /**
   * jsdom mantiene el mismo `localStorage` durante todo el fichero y
   * `services/api.js:35,41,44` guarda ahí el JWT: sin esto, el token que deja
   * un test se lo encuentra el siguiente.
   */
  localStorage.clear()
  sessionStorage.clear()
})

/**
 * Desmonta lo montado al acabar cada test. No es solo higiene de memoria: lo
 * que PrimeVue teletransporta al `document.body` (overlays del `Popover`, del
 * `Select`, del `Dialog`) NO cuelga del wrapper y sobreviviría al test,
 * haciendo que un `document.querySelector` del siguiente encuentre el overlay
 * del anterior.
 */
enableAutoUnmount(afterEach)
