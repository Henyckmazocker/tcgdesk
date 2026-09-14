import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { nextTick } from 'vue'
import { flushPromises } from '@vue/test-utils'

import CardView from '@/views/CardView.vue'
import { catalogGet } from '@/services/api'

import { SESION, montarVista } from '../../helpers'

import cartaFixture from '../../fixtures/catalog_card.json'

/**
 * `views/CardView.vue` — la ficha de una impresión.
 *
 * Junto con `ImportView`, es **una de las dos vistas que llaman a la capa de red
 * desde la propia vista** en vez de por un store: `CardView.vue:177` hace el
 * `catalogGet('/cards/{uuid}')` a mano. No es un descuido —la ficha es de
 * lectura pura y no hay nada que compartir entre pantallas—, pero significa que
 * aquí el doble de `@/services/api` es lo único que corta la red.
 *
 * Lo que se mira es lo que esta vista decide:
 *
 *  - **Un acabado sin precio no se pinta**, y si no hay ninguno se dice «Sin
 *    precio en Cardmarket» en vez de un 0 € (`CardView.vue:128-134`).
 *  - **El histórico viene mezclado por acabado y la gráfica es una por acabado**
 *    (`CardView.vue:137-149`): dos series sobre un mismo eje serían dos precios
 *    distintos dibujados como si fueran el mismo.
 *  - **La legalidad se ordena poniendo delante dónde SÍ se puede jugar**
 *    (`CardView.vue:151-156`), que es lo que se mira.
 *  - **404 y error del servidor no son lo mismo**: uno dice que esa carta no
 *    existe y el otro que no se ha podido cargar (`CardView.vue:179-185`).
 */

/**
 * Se dobla solo la I/O. El resto del módulo se conserva porque `CardImage`
 * compone la URL con `API_BASE` (`services/scryfall.js:23,42`) y un doble sin
 * esa constante llena el render de errores ajenos a esta vista.
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

/** `Sol Ring` de LTC, capturada del backend real. */
const UUID = cartaFixture.uuid

async function montarFicha(respuesta = fixtura(cartaFixture), uuid = UUID) {
  catalogGet.mockResolvedValue(respuesta)

  const montaje = await montarVista(CardView, { ruta: `/card/${uuid}`, estado: SESION })

  await flushPromises()
  await nextTick()

  return montaje
}

beforeEach(() => {
  catalogGet.mockReset()
})

describe('el primer render', () => {
  it('monta sin lanzar y pide la ficha por su uuid, con el uuid escapado', async () => {
    const { wrapper, errores } = await montarFicha()

    const carta = fixtura(cartaFixture)

    expect(errores).toEqual([])
    // Por la ruta GET del catálogo, no por el endpoint único: la ficha no
    // necesita sesión ni CSRF (divergencia 3 del `CLAUDE.md`).
    expect(catalogGet).toHaveBeenCalledWith(`/cards/${encodeURIComponent(UUID)}`)

    expect(wrapper.find('.ficha__titulo').text()).toBe(carta.name)
    expect(wrapper.find('.ficha__tipo').text()).toBe(carta.typeLine)
    expect(wrapper.find('.ficha__edicion').text())
      .toBe(`${carta.setName} · nº ${carta.collectorNumber}`)
    expect(wrapper.find('.ficha__texto').text()).toBe(carta.oracleText)
  })

  it('el botón de añadir va ARRIBA, antes del texto y de los precios', async () => {
    const { wrapper } = await montarFicha()

    // Es la acción de esta pantalla: bajarla al final la convertiría en algo
    // que hay que buscar (`CardView.vue:44-49`).
    const html = wrapper.html()

    expect(wrapper.find('.ficha__anadir').exists()).toBe(true)
    expect(html.indexOf('ficha__anadir')).toBeLessThan(html.indexOf('ficha__texto'))
    expect(html.indexOf('ficha__anadir')).toBeLessThan(html.indexOf('ficha__precios'))
  })

  it('solo se pintan los acabados que tienen precio, con su etiqueta traducida', async () => {
    const { wrapper } = await montarFicha()

    const precios = fixtura(cartaFixture).priceEur
    const conPrecio = Object.entries(precios).filter(([, valor]) => valor !== null)
    const pintados = wrapper.findAll('.ficha__precio')

    // La capturada trae `etched: null`: ese no sale, y no como «0,00 €».
    expect(precios.etched).toBeNull()
    expect(pintados).toHaveLength(conPrecio.length)
    expect(pintados.map((p) => p.text())).toEqual([
      `Normal: ${precios.normal.toFixed(2)} €`,
      `Foil: ${precios.foil.toFixed(2)} €`
    ])
  })

  it('el histórico se parte en una gráfica por acabado, no en una mezclada', async () => {
    const { wrapper } = await montarFicha()

    const historico = fixtura(cartaFixture).priceHistory
    const acabados = [...new Set(historico.map((p) => p.finish))]

    expect(acabados.length).toBeGreaterThan(1)
    expect(wrapper.findAll('.sparkline')).toHaveLength(acabados.length)

    // Cada `figure` lleva su etiqueta, que es lo que distingue las dos series.
    const cabeceras = wrapper.findAll('.sparkline__cabecera').map((c) => c.text())

    expect(cabeceras.some((t) => t.startsWith('Foil'))).toBe(true)
    expect(cabeceras.some((t) => t.startsWith('Normal'))).toBe(true)
  })

  it('los idiomas salen con su recuento y la legalidad pone delante donde se juega', async () => {
    const { wrapper } = await montarFicha()

    const carta = fixtura(cartaFixture)

    expect(wrapper.find('.ficha__seccion h2').text()).toBe('Precio')
    expect(wrapper.findAll('.ficha__idiomas li')).toHaveLength(carta.localizedNames.length)
    expect(wrapper.text()).toContain(`Nombres (${carta.localizedNames.length} idiomas)`)

    // Primero donde se PUEDE jugar. El resto conserva el orden en que vino,
    // porque el `sort` de JS es estable.
    const legales = Object.entries(carta.legalities)
      .filter(([, estado]) => estado === 'legal')
      .map(([formato]) => formato)

    const etiquetas = wrapper.findAll('.ficha__legalidades .p-tag').map((t) => t.text())

    expect(etiquetas.slice(0, legales.length)).toEqual(legales)
    expect(etiquetas).toHaveLength(Object.keys(carta.legalities).length)
  })
})

describe('lo que no es el camino feliz', () => {
  it('una carta sin precio en ningún acabado lo dice, y no pinta un 0 €', async () => {
    const carta = fixtura(cartaFixture)

    carta.priceEur = { normal: null, foil: null, etched: null }
    carta.priceHistory = []

    const { wrapper } = await montarFicha(carta)

    expect(wrapper.find('.ficha__sin-precio').text()).toBe('Sin precio en Cardmarket.')
    expect(wrapper.findAll('.ficha__precio')).toHaveLength(0)
    expect(wrapper.findAll('.sparkline')).toHaveLength(0)
  })

  it('un printing_not_found dice que esa carta no existe, sin botón de añadir', async () => {
    // El backend contesta `404 {"error":"printing_not_found"}` tal cual
    // (`CatalogHttpRouter`), así que la respuesta NO trae `status`.
    const { wrapper, errores } = await montarFicha({ error: 'printing_not_found' })

    expect(errores).toEqual([])
    expect(wrapper.find('.ficha__error').text()).toContain('Esta carta no existe en el catálogo.')
    expect(wrapper.find('.ficha__anadir').exists()).toBe(false)
  })

  it('cualquier otro error se distingue del 404 y dice que no se pudo cargar', async () => {
    const { wrapper } = await montarFicha({ error: 'algo_se_rompio' })

    expect(wrapper.find('.ficha__error').text()).toContain('No se pudo cargar la carta.')
  })
})

describe('navegar de una carta a otra', () => {
  it('cambiar el uuid de la ruta vuelve a pedir la ficha, sin remontar la vista', async () => {
    const { wrapper, router } = await montarFicha()

    const otra = fixtura(cartaFixture)

    otra.uuid = '00cf70ec-98f3-5e3e-928a-a7866a1d0c54'
    otra.name = 'Stomping Ground'

    catalogGet.mockResolvedValue(otra)

    // El `watch` sobre `route.params.uuid` (`CardView.vue:190`) es lo que hace
    // que la ficha siga a la ruta: sin él, ir de una carta a otra dejaría la
    // anterior en pantalla.
    await router.push({ name: 'card', params: { uuid: otra.uuid } })
    await flushPromises()
    await nextTick()

    expect(catalogGet).toHaveBeenLastCalledWith(`/cards/${encodeURIComponent(otra.uuid)}`)
    expect(wrapper.find('.ficha__titulo').text()).toBe('Stomping Ground')
  })
})

describe('el botón de volver', () => {
  let historia

  afterEach(() => historia?.mockRestore())

  it('sin historial detrás lleva al catálogo, y no a una pantalla en blanco', async () => {
    // `window.history.length` decide entre `router.back()` y el catálogo
    // (`CardView.vue:163-170`). Se fija a 1 para probar la rama determinista:
    // la de `back()` depende del historial que haya dejado el propio test.
    historia = vi.spyOn(window.history, 'length', 'get').mockReturnValue(1)

    const { wrapper, router } = await montarFicha()

    await wrapper.find('.ficha__bar button').trigger('click')
    await vi.waitFor(() => expect(router.currentRoute.value.name).toBe('catalog'))
  })
})
