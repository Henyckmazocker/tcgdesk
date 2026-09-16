import { describe, expect, it } from 'vitest'
import { nextTick } from 'vue'

import CardImage from '@/components/CardImage.vue'
import { imagenDeCarta } from '@/services/scryfall'

import { RETARDO_HOVER, SESION, hoverDeRaton, montarVista, pulsacionLarga } from '../../helpers'

import cartaFixture from '../../fixtures/catalog_card.json'

/**
 * `components/CardImage.vue` — la miniatura de una carta.
 *
 * Tiene más lógica de la que parece, y toda sale de dos fallos reales:
 *
 *  - **El hueco va SIEMPRE debajo y la imagen encima cuando existe.** La primera
 *    versión ocultaba la imagen con `opacity: 0` hasta el evento `load`, y ese
 *    evento **no llega** si el navegador ya la tiene en caché o si Vue recicla el
 *    nodo al hacer scroll: la imagen estaba cargada y no se veía
 *    (`CardImage.vue:4-8`).
 *  - **El fallo se olvida al cambiar de carta.** El scroll infinito recicla
 *    nodos, así que una carta sin imagen dejaría marcadas de por vida todas las
 *    que pasaran por ese nodo (`CardImage.vue:39-44`).
 *
 * Se monta con el mismo helper que las vistas —`montarVista`— para que haya un
 * solo sitio que sepa montar en esta suite, aunque este componente no necesite
 * ni router ni Pinia. **No se dobla `@/services/api`**: aquí no hay red, solo la
 * composición de una URL a partir del `scryfallId` que MTGJSON ya dio.
 */

const SCRYFALL_ID = cartaFixture.scryfallId

function montarImagen(props = {}, opciones = {}) {
  return montarVista(CardImage, {
    ruta: '/catalog',
    estado: SESION,
    props: { scryfallId: SCRYFALL_ID, nombre: cartaFixture.name, tamano: 'small', ...props },
    ...opciones
  })
}

describe('la URL', () => {
  it('apunta al backend propio y no al CDN de Scryfall', async () => {
    const { wrapper, errores } = await montarImagen()

    const img = wrapper.find('img')

    expect(errores).toEqual([])
    // La decisión de servir la copia local o redirigir al CDN no la puede tomar
    // el navegador —no sabe qué hay bajo `storage/`—, así que la toma
    // `GET /api/images/{scryfall_id}` (`services/scryfall.js:1-21`).
    expect(img.attributes('src')).toBe(imagenDeCarta(SCRYFALL_ID, 'small'))
    expect(img.attributes('src')).toContain(`/api/images/${SCRYFALL_ID}?size=small`)
    expect(img.attributes('src')).not.toContain('scryfall.io')
  })

  it('el alt es el nombre de la carta, y la carga es perezosa', async () => {
    const { wrapper } = await montarImagen()

    const img = wrapper.find('img')

    // Sin `loading="lazy"`, una colección en rejilla son miles de peticiones al
    // servidor de golpe (`CollectionView.vue:136-138`).
    expect(img.attributes('alt')).toBe(cartaFixture.name)
    expect(img.attributes('loading')).toBe('lazy')
    expect(img.attributes('decoding')).toBe('async')
  })

  it('sin scryfallId no hay imagen: se queda el hueco con el nombre', async () => {
    // No todas las cartas traen `scryfallId` (`services/scryfall.js:29-35`).
    const { wrapper } = await montarImagen({ scryfallId: null })

    expect(wrapper.find('img').exists()).toBe(false)
    expect(wrapper.find('.carta-img__hueco span').text()).toBe(cartaFixture.name)
  })
})

describe('cuando la imagen no carga', () => {
  it('un error del <img> deja el hueco con el nombre, no un icono roto', async () => {
    const { wrapper } = await montarImagen()

    await wrapper.find('img').trigger('error')
    await nextTick()

    expect(wrapper.find('img').exists()).toBe(false)
    expect(wrapper.find('.carta-img__hueco span').text()).toBe(cartaFixture.name)
  })

  it('cambiar de carta OLVIDA el fallo de la anterior', async () => {
    const { wrapper } = await montarImagen()

    await wrapper.find('img').trigger('error')
    await nextTick()

    expect(wrapper.find('img').exists()).toBe(false)

    // El scroll infinito recicla nodos: sin el `watch` de `CardImage.vue:42-44`,
    // esta carta heredaría el fallo de la que ocupó el nodo antes.
    await wrapper.setProps({ scryfallId: '177ee102-d981-4fc3-9f09-9dd07755f22c' })
    await nextTick()

    expect(wrapper.find('img').exists()).toBe(true)
    expect(wrapper.find('img').attributes('src'))
      .toContain('177ee102-d981-4fc3-9f09-9dd07755f22c')
  })
})

/**
 * **La ampliación de la carta** (M1 + M2 del *Plan - Vista Ampliada de la Carta*).
 *
 * Lo que se puede afirmar aquí y lo que no tiene una frontera muy concreta:
 * `vitest.config.js:32` lleva `css: false`, así que la suite **no compila los
 * `<style>` de los SFC**. Ningún test puede decir nada de la posición real, del
 * tamaño, del volteo contra el borde ni del `z-index` — eso es verificación a ojo
 * en el navegador. Lo que sí se prueba es lo que existe en el DOM: **si el
 * overlay está o no está, cuándo aparece, cuándo se va y de dónde sale su
 * imagen**.
 *
 * Y una trampa que hace pasar los tests en verde en falso: el overlay es un
 * `<Teleport to="body">` (`CardImage.vue:36-40`), así que **NO cuelga del
 * wrapper**. Se busca con `document.querySelector('.carta-zoom')`, igual que los
 * overlays de PrimeVue que avisa el `CLAUDE.md` del repo. Por eso estos montajes
 * llevan `attachTo` (`helpers.js`): la raíz vive en el DOM de verdad, como en la
 * app.
 *
 * Los `PointerEvent` los disparan `hoverDeRaton()` y `pulsacionLarga()`
 * (`tests/helpers.js`), que también manejan los temporizadores falsos: el porqué
 * —`trigger()` de VTU no puede asignar `clientX` en jsdom 30— está escrito allí y
 * no se repite aquí.
 */
describe('la ampliación', () => {
  /** El overlay teletransportado, o `null`. NUNCA `wrapper.find()`. */
  const zoom = () => document.querySelector('.carta-zoom')

  const montarAmpliable = (props = {}) => montarImagen(props, { attachTo: document.body })

  /** La raíz `.carta-img`, que es donde `onMounted` registra los listeners. */
  const raizDe = (wrapper) => wrapper.find('.carta-img')

  it('con ampliable: false no se amplía NUNCA, ni con el ratón ni con el dedo', async () => {
    // No es un `if` dentro de cada manejador: con `ampliable: false` la guarda
    // del principio de `onMounted` (`CardImage.vue:361-366`) hace que no se
    // registre ni un listener. Es lo que pide `CardView.vue:32`, la única
    // instancia de la app que se desactiva.
    const { wrapper } = await montarAmpliable({ ampliable: false })

    await hoverDeRaton(raizDe(wrapper))

    expect(zoom()).toBeNull()

    await pulsacionLarga(raizDe(wrapper))

    // En plural y no `toBeNull()`: si un test anterior hubiera dejado su overlay
    // colgando del `body`, este recuento lo caza.
    expect(document.querySelectorAll('.carta-zoom')).toHaveLength(0)
  })

  it('antes de los 350 ms no hay nada: barrer una rejilla no dispara diez ampliaciones', async () => {
    const { wrapper } = await montarAmpliable()

    await hoverDeRaton(raizDe(wrapper), { espera: RETARDO_HOVER - 50 })

    expect(zoom()).toBeNull()
  })

  it('tras el pointerenter del ratón y 350 ms hay un .carta-zoom en el body, a ?size=normal', async () => {
    const { wrapper } = await montarAmpliable()

    await hoverDeRaton(raizDe(wrapper))

    const overlay = zoom()

    expect(overlay).not.toBeNull()

    // Fuera del árbol del componente a propósito: `.carta-img` tiene
    // `overflow: hidden` y las tablas de PrimeVue recortan otra vez, así que un
    // overlay que naciera dentro se cortaría (`CardImage.vue:24-35`).
    expect(overlay.parentElement).toBe(document.body)
    expect(raizDe(wrapper).element.contains(overlay)).toBe(false)

    // La miniatura sigue pidiendo `small` y la ampliación pide `normal`: son dos
    // URLs contra el mismo endpoint, sin tocar `mtg_image_cache`.
    expect(overlay.querySelector('img').getAttribute('src'))
      .toBe(imagenDeCarta(SCRYFALL_ID, 'normal'))
    expect(overlay.querySelector('img').getAttribute('src')).toContain('?size=normal')
    expect(wrapper.find('.carta-img img').attributes('src')).toContain('?size=small')

    // Decorativo: el nombre ya lo anuncia el `alt` de la miniatura y el texto de
    // la fila, así que repetirlo es ruido para un lector de pantalla.
    expect(overlay.getAttribute('aria-hidden')).toBe('true')
    expect(overlay.querySelector('img').getAttribute('alt')).toBe('')
  })

  it('sacar el ratón lo DESMONTA: el nodo deja de existir, no es que se oculte', async () => {
    // `v-if` y no `v-show` (`CardImage.vue:30-31`): una tabla de 100 filas son
    // 100 `CardImage` y cero overlays mientras nadie apunte a ninguno.
    const { wrapper } = await montarAmpliable()

    const salir = await hoverDeRaton(raizDe(wrapper))

    expect(zoom()).not.toBeNull()

    await salir()

    expect(zoom()).toBeNull()
  })

  it('una pulsación táctil de 450 ms la abre, y soltar el dedo la cierra', async () => {
    const { wrapper } = await montarAmpliable()

    // `soltar: false` deja el dedo abajo: soltarlo cierra la ampliación, así que
    // mirando solo el final no se distingue «se abrió y se cerró» de «no se abrió
    // nunca».
    const soltar = await pulsacionLarga(raizDe(wrapper), { soltar: false })

    expect(zoom()).not.toBeNull()

    await soltar()

    expect(zoom()).toBeNull()
  })

  it('un pointermove de 20 px a mitad de la pulsación la aborta: eso es scroll', async () => {
    // Sin el aborto, recorrer con el dedo una tabla de 100 filas abre una
    // ampliación por fila (`CardImage.vue:244-265`). 20 px pasa de los 10 de
    // `TOLERANCIA_ARRASTRE`.
    const { wrapper } = await montarAmpliable()

    await pulsacionLarga(raizDe(wrapper), { arrastre: 20, soltar: false })

    expect(zoom()).toBeNull()
  })

  it('al desmontar el componente no queda un .carta-zoom colgando del body', async () => {
    /**
     * Un `Teleport` a `body` no se va solo: lo que lo retira es el desmontaje del
     * componente (`onBeforeUnmount` cierra además la ampliación,
     * `CardImage.vue:388-405`). Si no ocurriera, una ampliación sobreviviría a la
     * navegación en la app —y, aquí dentro, el overlay de un test lo encontraría
     * el siguiente y pasaría en verde en falso—.
     *
     * Es el test que demuestra que la limpieza entre tests de
     * `enableAutoUnmount(afterEach)` (`tests/setup.js`) tiene algo que hacer.
     */
    const { wrapper } = await montarAmpliable()

    await hoverDeRaton(raizDe(wrapper))

    expect(document.querySelectorAll('.carta-zoom')).toHaveLength(1)

    wrapper.unmount()
    await nextTick()

    expect(document.querySelectorAll('.carta-zoom')).toHaveLength(0)
  })
})
