import { beforeEach, describe, expect, it, vi } from 'vitest'
import { nextTick } from 'vue'
import { flushPromises } from '@vue/test-utils'

import CollectionView from '@/views/CollectionView.vue'
import { apiCall, catalogGet } from '@/services/api'

import { SESION, montarVista } from '../../helpers'

import coleccionFixture from '../../fixtures/collection_list.json'
import setsFixture from '../../fixtures/catalog_sets.json'

/**
 * `views/CollectionView.vue` — tus cartas, en rejilla o en tabla.
 *
 * **Ojo al copiar de aquí a un test de mazos y viceversa**: `stores/collection.js`
 * y `stores/deck.js` comparten los nombres `hayMas`, `vacio`, `estaGuardando` y
 * `estaAnadiendo` con semántica distinta —uno indexa por `item.id` de línea de
 * colección y el otro por `cardId` de línea de mazo—. Aquí se prueba el de
 * colección, y los ids que se usan son los de `collection_list.json`.
 *
 * Lo que se mira:
 *
 *  - **La query string manda**, filtros y modo de vista incluidos: recargar
 *    conserva lo que había y el enlace se comparte (`CollectionView.vue:342-358`).
 *  - **Rejilla y tabla enseñan lo MISMO dato** por dos caminos distintos, y las
 *    dos llevan `CollectionControls`: la edición en línea no depende del modo.
 *  - **Un `null` no es un cero.** Una carta que no cotiza dice «sin precio» y
 *    **sigue apareciendo** (`CollectionView.vue:292-298`).
 *  - **El scroll infinito por cursor**, que acumula y no reemplaza.
 *
 * El doble de `IntersectionObserver` vive en `tests/setup.js` desde M5
 * (`CollectionView.vue:368` es una de las tres vistas que lo exigen).
 */

/**
 * Se dobla solo la I/O y se conserva el resto del módulo: `CardImage` compone
 * la URL de cada miniatura con `API_BASE` (`services/scryfall.js:23,42`).
 */
vi.mock('@/services/api', async (importarOriginal) => ({
  ...(await importarOriginal()),
  apiCall: vi.fn(),
  catalogGet: vi.fn()
}))

/** Copia profunda: el store muta lo que recibe y las fixturas son de todos. */
function fixtura(json) {
  return structuredClone(json)
}

/** Las instancias del doble del centinela, que deja `tests/setup.js`. */
const observadores = window.observadoresDeInterseccion

/** Un tramo de la página capturada, con la envoltura del endpoint único. */
function pagina(desde, hasta, nextCursor = null) {
  const completa = fixtura(coleccionFixture)
  const items = completa.data.items.slice(desde, hasta)

  return {
    status: 'success',
    message: 'Colección.',
    data: { items, nextCursor, count: items.length },
    http_code: 200
  }
}

function respondeColeccion(respuesta = fixtura(coleccionFixture)) {
  apiCall.mockImplementation(() => Promise.resolve(respuesta))
}

async function montarColeccion(opciones = {}) {
  const montaje = await montarVista(CollectionView, {
    ruta: '/collection',
    estado: SESION,
    ...opciones
  })

  await flushPromises()
  await nextTick()

  return montaje
}

beforeEach(() => {
  apiCall.mockReset()
  catalogGet.mockReset()
  catalogGet.mockResolvedValue(fixtura(setsFixture))
  respondeColeccion()
})

describe('el primer render', () => {
  it('monta sin lanzar, pide la colección y las ediciones, y pinta la rejilla', async () => {
    const { wrapper, errores } = await montarColeccion()

    const items = fixtura(coleccionFixture).data.items

    expect(errores).toEqual([])
    expect(apiCall).toHaveBeenCalledWith('collection_list', expect.objectContaining({ limit: 60 }))
    // Las ediciones son para el desplegable del filtro y salen por la ruta GET
    // del catálogo, que no necesita sesión (`CollectionView.vue:363`).
    expect(catalogGet).toHaveBeenCalledWith('/sets')

    const tarjetas = wrapper.findAll('.carta')

    expect(tarjetas).toHaveLength(items.length)
    expect(tarjetas[0].find('.carta__nombre').text()).toBe(items[0].name)
    expect(tarjetas[0].find('.carta__meta').text())
      .toContain(`${items[0].setCode} · ${items[0].collectorNumber} · ${items[0].language}`)

    // Cada carta trae sus controles de edición en línea, en los dos modos.
    expect(wrapper.findAll('.controles')).toHaveLength(items.length)
  })

  it('el precio ausente dice «sin precio» y la carta NO desaparece de la lista', async () => {
    const lista = fixtura(coleccionFixture)

    lista.data.items[0].priceEur = null
    lista.data.items[0].lineValue = 0

    respondeColeccion(lista)

    const { wrapper } = await montarColeccion()

    const primera = wrapper.findAll('.carta')[0]

    expect(primera.find('.carta__precio').text()).toBe('sin precio')
    expect(primera.find('.carta__precio--sin').exists()).toBe(true)
    expect(wrapper.findAll('.carta')).toHaveLength(lista.data.items.length)
  })

  it('un acabado que no sea normal se marca sobre la imagen', async () => {
    const lista = fixtura(coleccionFixture)

    lista.data.items[0].finish = 'foil'

    respondeColeccion(lista)

    const { wrapper } = await montarColeccion()

    // «Foil», no `foil`: la etiqueta sale de `constants/collection.js:81-83`.
    expect(wrapper.findAll('.carta')[0].find('.carta__acabado').text()).toBe('Foil')
    expect(wrapper.findAll('.carta')[1].find('.carta__acabado').exists()).toBe(false)
  })

  it('la imagen abre la ficha de la IMPRESIÓN que tienes, no del oracle', async () => {
    const { wrapper, router } = await montarColeccion()

    const primera = fixtura(coleccionFixture).data.items[0]

    await wrapper.findAll('.carta')[0].find('.carta__imagen').trigger('click')

    await vi.waitFor(() => expect(router.currentRoute.value.name).toBe('card'))
    expect(router.currentRoute.value.params.uuid).toBe(primera.printingUuid)
  })
})

describe('el modo tabla', () => {
  it('entrar con view=table pinta la tabla y no la rejilla, con el mismo dato', async () => {
    const { wrapper } = await montarColeccion({ ruta: '/collection?view=table' })

    const items = fixtura(coleccionFixture).data.items

    expect(wrapper.find('.coleccion__tabla').exists()).toBe(true)
    expect(wrapper.find('.coleccion__rejilla').exists()).toBe(false)

    const filas = wrapper.findAll('.p-datatable-tbody > tr')

    expect(filas).toHaveLength(items.length)
    expect(filas[0].find('.tabla__nombre').text()).toBe(items[0].name)
    expect(filas[0].text()).toContain(`${items[0].lineValue.toFixed(2)} €`)
    // La edición en línea sigue estando: no depende del modo de vista.
    expect(filas[0].find('.controles').exists()).toBe(true)
  })
})

describe('los filtros', () => {
  it('al entrar con filtros en la URL, se piden al backend tal cual', async () => {
    const { wrapper } = await montarColeccion({
      ruta: '/collection?set=LTC&condition=NM&sort=name'
    })

    expect(apiCall).toHaveBeenCalledWith(
      'collection_list',
      expect.objectContaining({ set: 'LTC', condition: 'NM', sort: 'name', limit: 60 })
    )

    // El contador NO cuenta el `sort` por defecto, pero este no lo es: son tres.
    expect(wrapper.find('.coleccion__acciones-bar .p-badge').text()).toBe('3')
  })

  it('limpiar vacía la URL y vuelve a pedir sin ningún filtro', async () => {
    const { wrapper, router } = await montarColeccion({ ruta: '/collection?set=LTC' })

    // Por `aria-label` y no por posición: el `SelectButton` de rejilla/tabla
    // vive en esa misma barra y sus opciones también son `<button>`.
    await wrapper.find('button[aria-label="Filtros"]').trigger('click')
    await nextTick()

    apiCall.mockClear()

    await wrapper.findAll('.coleccion__acciones button')[1].trigger('click')
    await flushPromises()
    await nextTick()

    expect(router.currentRoute.value.query).toEqual({})
    expect(apiCall).toHaveBeenCalledWith(
      'collection_list',
      expect.objectContaining({ set: '', sort: 'price_desc' })
    )
  })
})

describe('el scroll infinito', () => {
  it('el centinela pide la siguiente página con el cursor, y la AÑADE', async () => {
    apiCall.mockResolvedValueOnce(pagina(0, 30, 'eyJvIjozMH0'))

    const { wrapper } = await montarColeccion()

    expect(wrapper.findAll('.carta')).toHaveLength(30)

    apiCall.mockResolvedValueOnce(pagina(30, 60, null))

    await observadores[0].entraEnPantalla()
    await flushPromises()
    await nextTick()

    expect(apiCall).toHaveBeenLastCalledWith(
      'collection_list',
      expect.objectContaining({ cursor: 'eyJvIjozMH0', limit: 60 })
    )

    expect(wrapper.findAll('.carta')).toHaveLength(60)
    expect(wrapper.find('.coleccion__fin').text()).toBe('No hay más cartas')
  })
})

describe('cuando no hay nada que enseñar', () => {
  it('sin cartas y sin filtros invita al catálogo', async () => {
    respondeColeccion(pagina(0, 0))

    const { wrapper } = await montarColeccion()

    expect(wrapper.find('.coleccion__vacio').text()).toContain('Tu colección está vacía')
  })

  it('sin cartas PERO con filtros, el mensaje es otro: no coinciden', async () => {
    // Distinguirlos importa: «no tienes nada» y «tu filtro no casa» piden cosas
    // distintas del usuario (`CollectionView.vue:119-124`).
    respondeColeccion(pagina(0, 0))

    const { wrapper } = await montarColeccion({ ruta: '/collection?set=LTC' })

    expect(wrapper.find('.coleccion__vacio').text()).toContain('coincide con esos filtros')
  })

  it('un error del backend se dice y deja la lista vacía', async () => {
    respondeColeccion({ status: 'error', message: 'No autorizado.', http_code: 401 })

    const { wrapper, errores } = await montarColeccion()

    expect(errores).toEqual([])
    expect(wrapper.find('.coleccion__error').text()).toContain('No autorizado.')
    expect(wrapper.findAll('.carta')).toHaveLength(0)
  })
})
