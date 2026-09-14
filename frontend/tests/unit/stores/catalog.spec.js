import { beforeEach, describe, expect, it, vi } from 'vitest'
import { createPinia, setActivePinia } from 'pinia'

import { useCatalogStore } from '@/stores/catalog'
import { catalogGet } from '@/services/api'

import cardsFixture from '../../fixtures/catalog_cards.json'
import setsFixture from '../../fixtures/catalog_sets.json'

/**
 * `stores/catalog.js` — el explorador de catálogo.
 *
 * Dos reglas propias del proyecto, y son las que se cubren aquí:
 *
 *  1. **La paginación es por CURSOR y ACUMULA.** `buscar()` reemplaza la lista y
 *     `cargarMas()` la continúa (`catalog.js:109`); si `cargarMas()` reemplazara,
 *     el scroll infinito enseñaría siempre 60 cartas y nadie vería el fallo,
 *     porque la pantalla seguiría llena.
 *  2. **El contador `peticionActual` descarta las respuestas viejas**
 *     (`catalog.js:63,70-72,92,103-106`). Al teclear en el buscador salen varias
 *     peticiones en vuelo y sin la guarda la más lenta pisa a la más reciente.
 *
 * Y `filtrosActivos`, que **no cuenta el `sort` por defecto** (`catalog.js:48-51`):
 * el contador del botón de filtros diría «1 filtro puesto» nada más entrar.
 */

/**
 * La frontera de mock del plan: todo lo que no sea `api.spec.js` dobla
 * `@/services/api`. Este store solo usa `catalogGet` —el catálogo es público y
 * sale SIN credenciales—, pero el doble declara las dos caras porque
 * `vi.mock` sustituye el módulo entero.
 */
vi.mock('@/services/api', () => ({
  catalogGet: vi.fn(),
  apiCall: vi.fn()
}))

/** Copia profunda: los stores mutan lo que reciben y las fixturas son de todos. */
function fixtura(json) {
  return structuredClone(json)
}

/**
 * Un tramo de la fixtura capturada, para poder distinguir «la página 1» de «la
 * página 2» sin inventarse una respuesta: las cartas son las reales, solo se
 * eligen otras.
 */
function pagina(desde, hasta, nextCursor = null) {
  return { items: fixtura(cardsFixture).items.slice(desde, hasta), nextCursor }
}

let store

beforeEach(() => {
  // Pinia REAL y no `createTestingPinia`: su `stubActions` por defecto (`true`)
  // no ejecuta las acciones, y aquí lo que se prueba son justamente ellas.
  setActivePinia(createPinia())
  store = useCatalogStore()

  catalogGet.mockReset()
})

describe('getters', () => {
  it('arranca vacío y sin más páginas', () => {
    expect(store.hayMas).toBe(false)
    expect(store.vacio).toBe(true)
  })

  it('vacio es false mientras se está cargando (la vista pinta esqueletos, no el «no hay nada»)', () => {
    store.cargando = true

    expect(store.vacio).toBe(false)
  })

  it('hayMas mira el cursor, no el número de items', () => {
    store.nextCursor = 'eyJvIjo2MH0'

    expect(store.hayMas).toBe(true)
  })

  it('filtrosActivos NO cuenta el sort por defecto', () => {
    // `relevance` es el valor que trae `filtrosVacios()`: contarlo haría que el
    // botón de filtros dijera «1» nada más entrar en el catálogo.
    expect(store.filtros.sort).toBe('relevance')
    expect(store.filtrosActivos).toBe(0)
  })

  it('filtrosActivos SÍ cuenta el sort cuando se cambia a otro', () => {
    store.filtros.sort = 'price_desc'

    expect(store.filtrosActivos).toBe(1)
  })

  it('filtrosActivos cuenta un filtro por cada valor puesto', () => {
    store.filtros.q = 'bosque'
    store.filtros.rarity = 'rare'

    expect(store.filtrosActivos).toBe(2)
  })
})

describe('buscar()', () => {
  it('pide /cards con los filtros puestos y un límite de 60', async () => {
    catalogGet.mockResolvedValue(fixtura(cardsFixture))

    store.filtros.q = 'bosque'

    await store.buscar()

    expect(catalogGet).toHaveBeenCalledWith('/cards', {
      q: 'bosque',
      set: '',
      rarity: '',
      colors: '',
      price_min: '',
      price_max: '',
      sort: 'relevance',
      limit: 60
    })

    expect(store.items).toHaveLength(60)
    expect(store.nextCursor).toBe(fixtura(cardsFixture).nextCursor)
    expect(store.cargando).toBe(false)
    expect(store.error).toBeNull()
  })

  it('con un error de red vacía la lista y el cursor', async () => {
    // La forma la da `api.js`: sin respuesta, `catalogGet` devuelve
    // `{ error: 'network_error' }` en vez de lanzar.
    catalogGet.mockResolvedValue({ error: 'network_error' })

    store.items = pagina(0, 3).items
    store.nextCursor = 'eyJvIjo2MH0'

    await store.buscar()

    expect(store.error).toMatch(/catálogo/i)
    expect(store.items).toEqual([])
    expect(store.nextCursor).toBeNull()
    expect(store.cargando).toBe(false)
  })

  it('una respuesta sin items ni cursor deja la lista vacía sin reventar', async () => {
    catalogGet.mockResolvedValue({})

    await store.buscar()

    expect(store.items).toEqual([])
    expect(store.nextCursor).toBeNull()
  })

  it('la respuesta LENTA de una búsqueda vieja no pisa a la reciente', async () => {
    // El contador de `catalog.js:63` y su guarda de `:70-72`. Sin ella, teclear
    // «bos» y luego «bosque» puede dejar en pantalla los resultados de «bos»
    // porque su petición tardó más.
    let resolverLenta

    catalogGet.mockImplementationOnce(
      () => new Promise((resolver) => { resolverLenta = resolver })
    )
    catalogGet.mockResolvedValueOnce(pagina(30, 40, 'cursor-reciente'))

    const lenta = store.buscar()
    const reciente = store.buscar()

    await reciente

    expect(store.items).toHaveLength(10)

    resolverLenta(pagina(0, 3, 'cursor-viejo'))
    await lenta

    expect(store.items).toHaveLength(10)
    expect(store.nextCursor).toBe('cursor-reciente')
  })
})

describe('cargarMas()', () => {
  it('ACUMULA la página siguiente, no la reemplaza', async () => {
    catalogGet.mockResolvedValueOnce(pagina(0, 10, 'cursor-pagina-2'))
    await store.buscar()

    catalogGet.mockResolvedValueOnce(pagina(10, 20, 'cursor-pagina-3'))
    await store.cargarMas()

    expect(store.items).toHaveLength(20)
    // La primera carta sigue siendo la de la página 1: si `cargarMas` asignara
    // en vez de empujar, el scroll enseñaría siempre la misma cantidad y el
    // fallo no se vería, porque la pantalla seguiría llena.
    expect(store.items[0].uuid).toBe(fixtura(cardsFixture).items[0].uuid)
    expect(store.items[10].uuid).toBe(fixtura(cardsFixture).items[10].uuid)

    // El cursor, en cambio, SÍ se reemplaza: es el sitio por donde seguir.
    expect(store.nextCursor).toBe('cursor-pagina-3')
    expect(store.cargandoMas).toBe(false)
  })

  it('manda el cursor y repite los filtros en cada página', async () => {
    store.filtros.rarity = 'rare'
    store.nextCursor = 'eyJvIjo2MH0'

    catalogGet.mockResolvedValue(pagina(0, 5, null))

    await store.cargarMas()

    expect(catalogGet.mock.calls[0][1]).toMatchObject({
      rarity: 'rare',
      cursor: 'eyJvIjo2MH0',
      limit: 60
    })
  })

  it('sin cursor no pide nada (no hay página siguiente)', async () => {
    await store.cargarMas()

    expect(catalogGet).not.toHaveBeenCalled()
  })

  it('no dobla la petición si ya hay una página en vuelo', async () => {
    store.nextCursor = 'eyJvIjo2MH0'
    store.cargandoMas = true

    await store.cargarMas()

    expect(catalogGet).not.toHaveBeenCalled()
  })

  it('no pide mientras se está cargando la primera página', async () => {
    store.nextCursor = 'eyJvIjo2MH0'
    store.cargando = true

    await store.cargarMas()

    expect(catalogGet).not.toHaveBeenCalled()
  })

  it('descarta la página siguiente si los filtros cambiaron mientras se pedía', async () => {
    // La guarda de `catalog.js:103-106`: esa página pertenece a la búsqueda
    // anterior y mezclarla daría una lista con dos filtros distintos dentro.
    store.nextCursor = 'eyJvIjo2MH0'

    let resolverPagina

    catalogGet.mockImplementationOnce(
      () => new Promise((resolver) => { resolverPagina = resolver })
    )

    const masPaginas = store.cargarMas()

    catalogGet.mockResolvedValueOnce(pagina(40, 45, 'cursor-de-la-nueva'))
    await store.buscar()

    resolverPagina(pagina(0, 10, 'cursor-viejo'))
    await masPaginas

    expect(store.items).toHaveLength(5)
    expect(store.nextCursor).toBe('cursor-de-la-nueva')
    expect(store.cargandoMas).toBe(false)
  })

  it('con un error de red conserva lo que ya había', async () => {
    catalogGet.mockResolvedValueOnce(pagina(0, 10, 'cursor-pagina-2'))
    await store.buscar()

    catalogGet.mockResolvedValueOnce({ error: 'network_error' })
    await store.cargarMas()

    // Vaciar la lista por un fallo de la página 3 borraría de la pantalla lo
    // que el usuario ya estaba mirando.
    expect(store.items).toHaveLength(10)
    expect(store.nextCursor).toBe('cursor-pagina-2')
  })
})

describe('cargarSets()', () => {
  it('trae las ediciones para el desplegable', async () => {
    catalogGet.mockResolvedValue(fixtura(setsFixture))

    await store.cargarSets()

    expect(catalogGet).toHaveBeenCalledWith('/sets')
    expect(store.sets).toHaveLength(868)
    expect(store.sets[0]).toHaveProperty('code')
  })

  it('no las vuelve a pedir si ya están (son 868 y no cambian en una sesión)', async () => {
    store.sets = fixtura(setsFixture)

    await store.cargarSets()

    expect(catalogGet).not.toHaveBeenCalled()
  })

  it('con un error de red deja el desplegable como estaba', async () => {
    catalogGet.mockResolvedValue({ error: 'network_error' })

    await store.cargarSets()

    expect(store.sets).toEqual([])
  })
})

describe('los filtros', () => {
  it('aplicarFiltros conserva los que no se tocan y vuelve a buscar', async () => {
    catalogGet.mockResolvedValue(pagina(0, 5, null))

    store.filtros.q = 'bosque'

    await store.aplicarFiltros({ rarity: 'rare' })

    expect(store.filtros.q).toBe('bosque')
    expect(store.filtros.rarity).toBe('rare')
    expect(catalogGet).toHaveBeenCalledTimes(1)
  })

  it('limpiarFiltros los devuelve al defecto y vuelve a buscar', async () => {
    catalogGet.mockResolvedValue(pagina(0, 5, null))

    store.filtros.q = 'bosque'
    store.filtros.sort = 'price_desc'

    await store.limpiarFiltros()

    expect(store.filtros.q).toBe('')
    expect(store.filtros.sort).toBe('relevance')
    expect(store.filtrosActivos).toBe(0)
  })

  it('desdeQuery rehidrata lo que venga en la URL y normaliza a texto', () => {
    // Los filtros viven en el store y la vista los refleja en la query string:
    // así recargar con filtros puestos los mantiene y el enlace se comparte.
    store.desdeQuery({ q: 'bosque', price_min: 5, sort: 'price_desc' })

    expect(store.filtros.q).toBe('bosque')
    expect(store.filtros.price_min).toBe('5')
    expect(store.filtros.sort).toBe('price_desc')
  })

  it('desdeQuery ignora lo vacío y lo que no es un filtro', () => {
    store.filtros.q = 'lo de antes'

    store.desdeQuery({ set: '', pagina: '7' })

    expect(store.filtros).toEqual({
      q: '',
      set: '',
      rarity: '',
      colors: '',
      price_min: '',
      price_max: '',
      sort: 'relevance'
    })
  })
})
