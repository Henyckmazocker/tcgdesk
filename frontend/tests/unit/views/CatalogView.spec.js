import { beforeEach, describe, expect, it, vi } from 'vitest'
import { nextTick } from 'vue'
import { flushPromises } from '@vue/test-utils'

import CatalogView from '@/views/CatalogView.vue'
import { catalogGet } from '@/services/api'

import { SESION, montarVista } from '../../helpers'

import cartasFixture from '../../fixtures/catalog_cards.json'
import setsFixture from '../../fixtures/catalog_sets.json'

/**
 * `views/CatalogView.vue` — la rejilla de las 110.384 cartas.
 *
 * Lo que se mira, que es lo que esta vista decide:
 *
 *  - **La query string es la fuente de verdad**, no el estado local: los filtros
 *    se escriben en la URL y de ahí vuelven al store (`CatalogView.vue:213-251`).
 *    Es lo que hace que recargar conserve la búsqueda y que el enlace se comparta.
 *  - **El botón de añadir está en la REJILLA y no solo en la ficha**
 *    (`CatalogView.vue:112-123`): entrar y volver por cada carta es justo lo que
 *    haría insoportable registrar diez.
 *  - **El scroll infinito va por cursor**, nunca por offset, y **acumula**.
 *  - **Un precio ausente no se pinta como 0 €**: se calla (`CatalogView.vue:202-206`).
 *
 * El doble de `IntersectionObserver` vive en `tests/setup.js` desde M5: lo
 * exigen tres vistas —esta en `CatalogView.vue:259`— y sin él ninguna monta.
 */

/**
 * Se dobla solo la I/O y se conserva el resto del módulo: `CardImage` compone
 * la URL de cada miniatura con `API_BASE` (`services/scryfall.js:23,42`).
 */
vi.mock('@/services/api', async (importarOriginal) => ({
  ...(await importarOriginal()),
  catalogGet: vi.fn(),
  apiCall: vi.fn()
}))

/** Copia profunda: el store muta lo que recibe y las fixturas son de todos. */
function fixtura(json) {
  return structuredClone(json)
}

/** Las instancias del doble del centinela, que deja `tests/setup.js`. */
const observadores = window.observadoresDeInterseccion

/**
 * Un doble que responde según la RUTA GET. La vista pide `/sets` y `/cards` en
 * el mismo `onMounted` (`CatalogView.vue:253-255`), así que una cola de
 * `mockResolvedValueOnce` se rompería al cambiar el orden.
 */
function respondeSegunRuta(cartas = fixtura(cartasFixture)) {
  catalogGet.mockImplementation((ruta) =>
    Promise.resolve(ruta === '/sets' ? fixtura(setsFixture) : cartas)
  )
}

/** Un tramo de la página capturada, para el scroll infinito. */
function pagina(desde, hasta, nextCursor = null) {
  return { items: fixtura(cartasFixture).items.slice(desde, hasta), nextCursor }
}

async function montarCatalogo(opciones = {}) {
  const montaje = await montarVista(CatalogView, { ruta: '/catalog', estado: SESION, ...opciones })

  await flushPromises()
  await nextTick()

  return montaje
}

beforeEach(() => {
  catalogGet.mockReset()
  respondeSegunRuta()
})

describe('el primer render', () => {
  it('monta sin lanzar, carga las ediciones y pinta la primera página', async () => {
    const { wrapper, errores } = await montarCatalogo()

    const cartas = fixtura(cartasFixture).items

    expect(errores).toEqual([])
    expect(catalogGet).toHaveBeenCalledWith('/sets')
    // 60 por página, que es el tamaño con el que se capturó la fixtura.
    expect(catalogGet).toHaveBeenCalledWith('/cards', expect.objectContaining({ limit: 60 }))

    const tarjetas = wrapper.findAll('.carta')

    expect(tarjetas).toHaveLength(cartas.length)
    expect(tarjetas[0].find('.carta__nombre').text()).toBe(cartas[0].name)
    expect(tarjetas[0].find('.carta__meta').text())
      .toContain(`${cartas[0].setCode} · ${cartas[0].collectorNumber}`)
  })

  it('cada tarjeta lleva su botón de añadir, y el enlace va en la IMAGEN', async () => {
    const { wrapper, router } = await montarCatalogo()

    const cartas = fixtura(cartasFixture).items
    const primera = wrapper.findAll('.carta')[0]

    // Un botón dentro de algo con `role="link"` ni se tabula bien ni se pulsa
    // sin abrir la ficha: por eso el enlace es la imagen y no el `<article>`
    // entero (`CatalogView.vue:89-94`).
    expect(primera.find('.carta__imagen').attributes('role')).toBe('link')
    expect(primera.find('.carta__pie .anadir').exists()).toBe(true)
    expect(wrapper.findAll('.anadir')).toHaveLength(cartas.length)

    await primera.find('.carta__imagen').trigger('click')

    // `vi.waitFor` y no un `flushPromises`: el destino es un `import()` perezoso.
    await vi.waitFor(() => expect(router.currentRoute.value.name).toBe('card'))
    expect(router.currentRoute.value.params.uuid).toBe(cartas[0].uuid)
  })

  it('una carta sin precio en ningún acabado no pinta un 0 €: no pinta nada', async () => {
    const cartas = fixtura(cartasFixture)

    // La primera de la capturada ya viene con los tres a null (es de un set sin
    // precios todavía); la segunda se le pone uno para contrastar.
    expect(cartas.items[0].priceEur).toEqual({ normal: null, foil: null, etched: null })
    cartas.items[1].priceEur = { normal: 3.5, foil: null, etched: null }

    respondeSegunRuta(cartas)

    const { wrapper } = await montarCatalogo()

    const tarjetas = wrapper.findAll('.carta')

    expect(tarjetas[0].find('.carta__precio').exists()).toBe(false)
    expect(tarjetas[1].find('.carta__precio').text()).toBe('3.50 €')
  })
})

describe('los filtros', () => {
  it('la query string es la fuente de verdad: al entrar con filtros, se piden', async () => {
    const { wrapper } = await montarCatalogo({ ruta: '/catalog?q=bolt&rarity=rare&sort=price_asc' })

    // Los filtros no se quedan en el componente: `desdeQuery` los vuelca al
    // store y de ahí salen en la petición (`stores/catalog.js:138-149`).
    expect(catalogGet).toHaveBeenCalledWith(
      '/cards',
      expect.objectContaining({ q: 'bolt', rarity: 'rare', sort: 'price_asc', limit: 60 })
    )

    // Y el contador del botón de filtros cuenta los activos SIN el `sort` por
    // defecto, que no es un filtro (`stores/catalog.js:48-51`).
    expect(wrapper.find('.catalogo__bar .p-badge').text()).toBe('3')
  })

  it('aplicar escribe la búsqueda en la URL y vuelve a pedir con ella', async () => {
    const { wrapper, router } = await montarCatalogo()

    await wrapper.find('.catalogo__bar button:last-child').trigger('click')
    await nextTick()

    expect(wrapper.find('.catalogo__filtros').exists()).toBe(true)

    await wrapper.find('.catalogo__buscador input').setValue('sol ring')
    catalogGet.mockClear()

    await wrapper.find('.catalogo__acciones button').trigger('click')
    await flushPromises()
    await nextTick()

    expect(router.currentRoute.value.query.q).toBe('sol ring')
    expect(catalogGet).toHaveBeenCalledWith('/cards', expect.objectContaining({ q: 'sol ring' }))
  })

  it('limpiar deja la URL sin query y el buscador en blanco', async () => {
    const { wrapper, router } = await montarCatalogo({ ruta: '/catalog?q=bolt&rarity=rare' })

    await wrapper.find('.catalogo__bar button:last-child').trigger('click')
    await nextTick()

    expect(wrapper.find('.catalogo__buscador input').element.value).toBe('bolt')

    await wrapper.findAll('.catalogo__acciones button')[1].trigger('click')
    await flushPromises()
    await nextTick()

    expect(router.currentRoute.value.query).toEqual({})
    expect(wrapper.find('.catalogo__buscador input').element.value).toBe('')
  })
})

describe('el scroll infinito', () => {
  it('el centinela pide la siguiente con el cursor que le dieron, y la AÑADE', async () => {
    catalogGet.mockImplementation((ruta) =>
      Promise.resolve(ruta === '/sets' ? fixtura(setsFixture) : pagina(0, 30, 'eyJvIjozMH0'))
    )

    const { wrapper } = await montarCatalogo()

    expect(wrapper.findAll('.carta')).toHaveLength(30)

    catalogGet.mockImplementation((ruta) =>
      Promise.resolve(ruta === '/sets' ? fixtura(setsFixture) : pagina(30, 60, null))
    )

    await observadores[0].entraEnPantalla()
    await flushPromises()
    await nextTick()

    // Por cursor y nunca por offset: `LIMIT 60 OFFSET 50000` obligaría a MySQL a
    // recorrer y tirar 50.000 filas en cada tirón (`CLAUDE.md`, divergencia 3).
    expect(catalogGet).toHaveBeenLastCalledWith(
      '/cards',
      expect.objectContaining({ cursor: 'eyJvIjozMH0', limit: 60 })
    )

    // Acumula: 30 + 30, no 30 reemplazadas.
    expect(wrapper.findAll('.carta')).toHaveLength(60)
    expect(wrapper.find('.catalogo__fin').text()).toBe('No hay más resultados')
  })
})

describe('cuando el catálogo no devuelve nada', () => {
  it('sin resultados sale el vacío y ni una tarjeta', async () => {
    respondeSegunRuta({ items: [], nextCursor: null })

    const { wrapper } = await montarCatalogo()

    expect(wrapper.find('.catalogo__vacio').text()).toContain('Ninguna carta coincide')
    expect(wrapper.findAll('.carta')).toHaveLength(0)
  })

  it('un error de la ruta GET se dice y deja la rejilla vacía', async () => {
    respondeSegunRuta({ error: 'algo_se_rompio' })

    const { wrapper, errores } = await montarCatalogo()

    expect(errores).toEqual([])
    expect(wrapper.find('.catalogo__error').text()).toContain('No se pudo cargar el catálogo.')
    expect(wrapper.findAll('.carta')).toHaveLength(0)
  })
})
