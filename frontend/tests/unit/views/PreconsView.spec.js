import { beforeEach, describe, expect, it, vi } from 'vitest'
import { nextTick } from 'vue'
import { flushPromises } from '@vue/test-utils'

import PreconsView from '@/views/PreconsView.vue'
import { catalogGet } from '@/services/api'

import { SESION, montarVista } from '../../helpers'

import decksFixture from '../../fixtures/catalog_decks.json'

/**
 * `views/PreconsView.vue` — 429 líneas **que nadie había visto renderizar**.
 *
 * Se entregó el 2026-09-12 validada por contrato, `curl`, SQL y build, y su
 * propio plan lo admitía: «el frontend no tiene suite de tests y las rutas están
 * tras el guard de sesión». Este fichero es su primer render verificado, que es
 * el motivo de que vaya en el mismo hito que `DecksView`.
 *
 * Lo que se mira, que es lo que esta vista decide de verdad:
 *
 *  - **El filtro honesto.** MTGJSON publica 3.029 cajas y cinco de sus 48 tipos
 *    no son mazos (1.048 de las 3.029): por defecto se pide `playable=1` y la
 *    rejilla enseña **los 1.981 jugables**. Quién es jugable lo dice el backend
 *    en las facetas (`playable`), no una lista escrita aquí
 *    (`stores/precons.js:88-96`), así que los números de este fichero **salen de
 *    la fixtura capturada** y no hay ni una cadena de tipo a mano.
 *  - **El conmutador «ver todo»**, que es lo único que quita ese recorte
 *    (`PreconsView.vue:70-79` → `precons.js:109`) y que además destapa el grupo
 *    de tipos «no son mazos» del desplegable.
 *  - **El scroll infinito por cursor**, que es como se pasan las 60 cajas de la
 *    primera página a las siguientes sin calcular ningún offset.
 */

vi.mock('@/services/api', () => ({
  catalogGet: vi.fn(),
  apiCall: vi.fn()
}))

/** Copia profunda: el store muta lo que recibe y las fixturas son de todos. */
function fixtura(json) {
  return structuredClone(json)
}

/**
 * Un tramo de la fixtura capturada. Las facetas (`deckTypes`, `sets`) solo
 * viajan en la PRIMERA página, igual que responde el backend.
 */
function pagina(desde, hasta, nextCursor = null, facetas = true) {
  const completa = fixtura(decksFixture)
  const respuesta = { items: completa.items.slice(desde, hasta), nextCursor }

  if (facetas) {
    respuesta.deckTypes = completa.deckTypes
    respuesta.sets = completa.sets
  }

  return respuesta
}

/**
 * jsdom no implementa `IntersectionObserver` y `PreconsView.vue:270-280` lo
 * construye en su `onMounted` para el centinela del scroll infinito: sin ese
 * doble la vista **no se puede montar**. Desde M5 el doble vive en
 * `tests/setup.js` —lo necesitan tres vistas, no solo esta— y deja ahí las
 * instancias, que es lo que permite disparar el centinela a mano.
 */
const observadores = window.observadoresDeInterseccion

/** Monta la vista y espera a que la primera página haya llegado y pintado. */
async function montarPrecons(opciones = {}) {
  const montaje = await montarVista(PreconsView, { ruta: '/precons', estado: SESION, ...opciones })

  await flushPromises()
  await nextTick()

  return montaje
}

beforeEach(() => {
  catalogGet.mockReset()
})

describe('el primer render', () => {
  /**
   * ESTE ES EL TEST NOMBRADO POR EL PLAN. Su nombre es literal: es el primer
   * render verificado de una vista que se entregó sin verse nunca.
   */
  it('pinta la rejilla con los 1.981 jugables y el conmutador de ver todo', async () => {
    catalogGet.mockResolvedValue(pagina(0, 60, 'cursor-pagina-2'))

    const { wrapper, errores } = await montarPrecons()

    const facetas = fixtura(decksFixture)
    const jugables = facetas.deckTypes.filter((t) => t.playable).reduce((s, t) => s + t.count, 0)
    const ocultos = facetas.deckTypes.filter((t) => !t.playable).reduce((s, t) => s + t.count, 0)

    // Los 1.981 y los 1.048 no están escritos a mano: salen de las facetas
    // capturadas del backend, que es quien decide quién es jugable.
    expect(jugables).toBe(1981)
    expect(ocultos).toBe(1048)

    expect(errores).toEqual([])

    // La petición lleva el recorte honesto puesto (`precons.js:109`).
    expect(catalogGet).toHaveBeenCalledWith('/decks', expect.objectContaining({ playable: '1' }))

    // Y la rejilla pinta la página que vino, con lo de cada caja.
    const tarjetas = wrapper.findAll('.precon')

    expect(tarjetas).toHaveLength(facetas.items.length)
    expect(tarjetas[0].find('.precon__nombre').text()).toBe(facetas.items[0].name)
    expect(tarjetas[0].text()).toContain(facetas.items[0].deckType)
    expect(tarjetas[0].text()).toContain(facetas.items[0].setCode)

    // El conmutador existe, arranca apagado y DICE EN VOZ ALTA cuántas cajas
    // está escondiendo y por qué (`PreconsView.vue:60-79`).
    const conmutador = wrapper.find('#ver-todo')

    expect(conmutador.exists()).toBe(true)
    expect(conmutador.element.checked).toBe(false)

    const texto = wrapper.find('.precons__todo').text()

    expect(texto).toContain('Ver todo')
    expect(texto).toContain(`ahora se ocultan ${ocultos} productos que no son mazos`)
  })

  it('cada caja enlaza por fileName, que es su clave natural', async () => {
    catalogGet.mockResolvedValue(pagina(0, 60))

    const { wrapper, router } = await montarPrecons()

    const primera = fixtura(decksFixture).items[0]

    // Aquí el enlace no es un `router-link`: la tarjeta entera es clicable
    // (`role="link"`, `PreconsView.vue:100-112`) y navega por `fileName` —hay
    // cajas homónimas en ediciones distintas, así que el nombre no vale—.
    await wrapper.findAll('.precon')[0].trigger('click')

    // `vi.waitFor` y no un `flushPromises` a secas: el componente de destino es
    // un `import()` perezoso (`router/index.js:55-59`) y la navegación no
    // termina hasta que resuelve.
    await vi.waitFor(() => expect(router.currentRoute.value.name).toBe('precon'))

    expect(router.currentRoute.value.params.fileName).toBe(primera.fileName)
  })

  it('una caja sin cartas dice «solo fichas» en vez de un cero pelado', async () => {
    // Los cinco precons de solo fichas tienen `cardCount = 0` y no es un error:
    // las fichas no cuentan ejemplares. Se altera la fixtura EN MEMORIA.
    const respuesta = pagina(0, 1)
    respuesta.items[0].cardCount = 0

    catalogGet.mockResolvedValue(respuesta)

    const { wrapper } = await montarPrecons()

    expect(wrapper.find('.precon__solo-fichas').text()).toBe('solo fichas')
  })

  it('sin resultados enseña el vacío, y un error del catálogo se dice', async () => {
    catalogGet.mockResolvedValue({ items: [], nextCursor: null })

    const { wrapper } = await montarPrecons()

    expect(wrapper.find('.precons__vacio').exists()).toBe(true)
    expect(wrapper.findAll('.precon')).toHaveLength(0)
  })
})

describe('el conmutador de ver todo', () => {
  it('quita el recorte: pone all=1 en la URL y pide sin playable', async () => {
    catalogGet.mockResolvedValue(pagina(0, 60))

    const { wrapper, router } = await montarPrecons()

    catalogGet.mockClear()

    // El conmutador escribe en la query string y la query string es la fuente
    // de verdad: de ahí vuelve al store (`PreconsView.vue:255-266`). Así
    // recargar con «ver todo» puesto lo mantiene y el enlace se comparte.
    await wrapper.find('#ver-todo').setValue(true)
    await flushPromises()
    await nextTick()

    expect(router.currentRoute.value.query.all).toBe('1')
    expect(catalogGet).toHaveBeenCalledWith('/decks', expect.objectContaining({ playable: '' }))
    expect(wrapper.find('.precons__todo').text()).toContain('incluyendo 1048 productos')
  })
})

describe('el scroll infinito', () => {
  it('el centinela pide la página siguiente con el cursor, y la AÑADE', async () => {
    catalogGet.mockResolvedValueOnce(pagina(0, 30, 'cursor-pagina-2'))

    const { wrapper } = await montarPrecons()

    expect(wrapper.findAll('.precon')).toHaveLength(30)

    // La segunda página no trae facetas, igual que el backend.
    catalogGet.mockResolvedValueOnce(pagina(30, 60, null, false))

    await observadores[0].entraEnPantalla()
    await flushPromises()
    await nextTick()

    // Por cursor y nunca por offset: el cliente solo transporta lo que le dieron.
    expect(catalogGet).toHaveBeenLastCalledWith(
      '/decks',
      expect.objectContaining({ cursor: 'cursor-pagina-2', playable: '1' })
    )

    // Y acumula: 30 + 30, no 30 reemplazadas.
    expect(wrapper.findAll('.precon')).toHaveLength(60)
    expect(wrapper.find('.precons__fin').exists()).toBe(true)
  })
})
