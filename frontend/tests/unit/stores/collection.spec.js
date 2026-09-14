import { beforeEach, describe, expect, it, vi } from 'vitest'
import { createPinia, setActivePinia } from 'pinia'

import { useCollectionStore } from '@/stores/collection'
import { apiCall } from '@/services/api'

import listFixture from '../../fixtures/collection_list.json'
import valueFixture from '../../fixtures/collection_value.json'
import setsFixture from '../../fixtures/collection_sets.json'
import addFixture from '../../fixtures/collection_add.json'
import quantityFixture from '../../fixtures/collection_update_quantity.json'
import gradeFixture from '../../fixtures/collection_change_grade.json'
import removeFixture from '../../fixtures/collection_remove.json'

/**
 * `stores/collection.js` — la colección del usuario.
 *
 * **Ojo al copiar de aquí a `deck.spec.js` o al revés**: los dos stores tienen
 * getters con el MISMO nombre (`hayMas`, `vacio`, `estaGuardando`,
 * `estaAnadiendo`) y semántica distinta. Aquí `estaGuardando` indexa por el `id`
 * de la línea de COLECCIÓN (`collection.js:83`) y allí por el `id` de la línea
 * del MAZO; `estaAnadiendo` va por `printing_uuid` en los dos, pero lo que se
 * está añadiendo no es lo mismo. Un test copiado de un store a otro es la forma
 * más fácil de escribir uno que no prueba nada.
 *
 * Lo que de verdad hay que cubrir, y por qué:
 *
 *  - **El contador `peticionActual`** (`collection.js:96,106-108`): dos
 *    `buscar()` solapados y la respuesta lenta NO puede pisar a la reciente.
 *  - **`filtrosActivos`**, que no cuenta el `sort` por defecto (`:79-82`).
 *  - Las tres ediciones en línea, que son las que pueden hacer **desaparecer una
 *    fila** de la lista: cantidad a 0 borra, y cambiar de estado puede FUNDIR la
 *    línea con otra —`condition_grade` está dentro del `UNIQUE KEY`—, así que el
 *    `id` que vuelve no tiene por qué ser el que se mandó.
 */

vi.mock('@/services/api', () => ({
  apiCall: vi.fn(),
  catalogGet: vi.fn()
}))

/** Copia profunda: los stores mutan lo que reciben y las fixturas son de todos. */
function fixtura(json) {
  return structuredClone(json)
}

/** Un tramo de la colección capturada, con el sobre del backend puesto. */
function paginaColeccion(desde, hasta, nextCursor = null) {
  const items = fixtura(listFixture).data.items.slice(desde, hasta)

  return {
    status: 'success',
    message: 'Colección.',
    data: { items, nextCursor, count: items.length },
    http_code: 200
  }
}

/** Las líneas de la fixtura, para sembrar la lista sin pasar por `buscar()`. */
function itemsDeFixtura(desde, hasta) {
  return fixtura(listFixture).data.items.slice(desde, hasta)
}

let store

beforeEach(() => {
  // Pinia REAL: `createTestingPinia` no ejecuta las acciones con su
  // `stubActions` por defecto, y aquí lo que se prueba son ellas.
  setActivePinia(createPinia())
  store = useCollectionStore()

  apiCall.mockReset()
})

describe('getters', () => {
  it('arranca vacía y sin más páginas', () => {
    expect(store.hayMas).toBe(false)
    expect(store.vacio).toBe(true)
  })

  it('vacio es false mientras carga (la vista pinta esqueletos, no el «no tienes nada»)', () => {
    store.cargando = true

    expect(store.vacio).toBe(false)
  })

  it('filtrosActivos NO cuenta el sort por defecto', () => {
    // El defecto de la colección es `price_desc`, no `relevance`: son dos
    // stores parecidos con defectos distintos, y contar el sort haría que el
    // botón dijera «1 filtro» nada más entrar.
    expect(store.filtros.sort).toBe('price_desc')
    expect(store.filtrosActivos).toBe(0)
  })

  it('filtrosActivos SÍ cuenta el sort cuando se cambia a otro', () => {
    store.filtros.sort = 'name'

    expect(store.filtrosActivos).toBe(1)
  })

  it('estaGuardando indexa por el id de la LÍNEA de colección', () => {
    // No por `printing_uuid` ni por `cardId`: la misma carta puede estar en
    // cuatro líneas (acabado, idioma, estado) y solo se bloquea la que se edita.
    store.guardando = [6399]

    expect(store.estaGuardando(6399)).toBe(true)
    expect(store.estaGuardando(6486)).toBe(false)
  })

  it('estaAnadiendo indexa por printing_uuid, que es lo que se añade desde el catálogo', () => {
    store.anadiendo = ['22cadebf-e1f3-5dd2-81e9-1d03df5ba19c']

    expect(store.estaAnadiendo('22cadebf-e1f3-5dd2-81e9-1d03df5ba19c')).toBe(true)
    expect(store.estaAnadiendo('otro-uuid')).toBe(false)
  })
})

describe('buscar()', () => {
  it('pide collection_list con los filtros y el límite de página', async () => {
    apiCall.mockResolvedValue(fixtura(listFixture))

    store.filtros.set = 'LTC'

    await store.buscar()

    expect(apiCall).toHaveBeenCalledWith('collection_list', {
      set: 'LTC',
      rarity: '',
      colors: '',
      finish: '',
      language: '',
      condition: '',
      price_min: '',
      price_max: '',
      sort: 'price_desc',
      limit: 60
    })

    expect(store.items).toHaveLength(60)
    expect(store.nextCursor).toBe(fixtura(listFixture).data.nextCursor)
    expect(store.cargando).toBe(false)
    expect(store.error).toBeNull()
  })

  it('con un error del backend vacía la lista y guarda su mensaje', async () => {
    apiCall.mockResolvedValue({
      status: 'error',
      message: 'No autenticado.',
      data: null,
      http_code: 401
    })

    store.items = itemsDeFixtura(0, 3)

    await store.buscar()

    expect(store.error).toBe('No autenticado.')
    expect(store.items).toEqual([])
    expect(store.nextCursor).toBeNull()
  })

  it('sin mensaje del backend pone uno propio', async () => {
    apiCall.mockResolvedValue({ status: 'error', http_code: 500 })

    await store.buscar()

    expect(store.error).toMatch(/colección/i)
  })

  /**
   * **El test del `peticionActual`**, que es condición de cierre del hito: se
   * pone en rojo si se borra la guarda de `collection.js:106`.
   *
   * Cambiar de filtro varias veces seguidas deja varias peticiones en vuelo, y
   * el backend no garantiza el orden de llegada: sin el contador, la respuesta
   * de la consulta ABANDONADA repinta la lista encima de la buena y el usuario
   * ve resultados que no ha pedido.
   */
  it('dos buscar() solapados: la respuesta LENTA de la vieja no pisa a la reciente', async () => {
    let resolverLenta

    apiCall.mockImplementationOnce(
      () => new Promise((resolver) => { resolverLenta = resolver })
    )
    apiCall.mockResolvedValueOnce(paginaColeccion(30, 40, 'cursor-reciente'))

    // La lenta sale primero (filtro viejo) y la reciente después (filtro nuevo).
    const lenta = store.buscar()
    const reciente = store.buscar()

    await reciente

    const idsRecientes = itemsDeFixtura(30, 40).map((i) => i.id)

    expect(store.items.map((i) => i.id)).toEqual(idsRecientes)

    // Y ahora contesta la vieja, tarde.
    resolverLenta(paginaColeccion(0, 3, 'cursor-viejo'))
    await lenta

    expect(store.items.map((i) => i.id)).toEqual(idsRecientes)
    expect(store.nextCursor).toBe('cursor-reciente')
  })
})

describe('cargarMas()', () => {
  it('ACUMULA la página siguiente, no la reemplaza', async () => {
    apiCall.mockResolvedValueOnce(paginaColeccion(0, 10, 'cursor-pagina-2'))
    await store.buscar()

    apiCall.mockResolvedValueOnce(paginaColeccion(10, 20, 'cursor-pagina-3'))
    await store.cargarMas()

    expect(store.items).toHaveLength(20)
    expect(store.items[0].id).toBe(itemsDeFixtura(0, 1)[0].id)
    expect(store.items[10].id).toBe(itemsDeFixtura(10, 11)[0].id)
    expect(store.nextCursor).toBe('cursor-pagina-3')
    expect(store.cargandoMas).toBe(false)
  })

  it('manda el cursor y repite los filtros', async () => {
    store.filtros.condition = 'NM'
    store.nextCursor = 'eyJvIjo2MH0'

    apiCall.mockResolvedValue(paginaColeccion(0, 5))

    await store.cargarMas()

    expect(apiCall.mock.calls[0][1]).toMatchObject({
      condition: 'NM',
      cursor: 'eyJvIjo2MH0',
      limit: 60
    })
  })

  it('sin cursor, con otra página en vuelo o cargando la primera, no pide nada', async () => {
    await store.cargarMas()

    store.nextCursor = 'un-cursor'
    store.cargandoMas = true
    await store.cargarMas()

    store.cargandoMas = false
    store.cargando = true
    await store.cargarMas()

    expect(apiCall).not.toHaveBeenCalled()
  })

  it('descarta la página siguiente si los filtros cambiaron mientras se pedía', async () => {
    // `collection.js:178-181`: esa página es de la consulta anterior y añadirla
    // mezclaría dos listas con filtros distintos.
    store.nextCursor = 'un-cursor'

    let resolverPagina

    apiCall.mockImplementationOnce(
      () => new Promise((resolver) => { resolverPagina = resolver })
    )

    const masPaginas = store.cargarMas()

    apiCall.mockResolvedValueOnce(paginaColeccion(40, 45, 'cursor-de-la-nueva'))
    await store.buscar()

    resolverPagina(paginaColeccion(0, 10, 'cursor-viejo'))
    await masPaginas

    expect(store.items).toHaveLength(5)
    expect(store.nextCursor).toBe('cursor-de-la-nueva')
    expect(store.cargandoMas).toBe(false)
  })

  it('con un error del backend conserva lo que ya había', async () => {
    apiCall.mockResolvedValueOnce(paginaColeccion(0, 10, 'cursor-pagina-2'))
    await store.buscar()

    apiCall.mockResolvedValueOnce({ status: 'error', message: 'Vaya.', http_code: 500 })
    await store.cargarMas()

    expect(store.items).toHaveLength(10)
    expect(store.nextCursor).toBe('cursor-pagina-2')
  })
})

describe('el dashboard y el progreso por edición', () => {
  it('valorar() pide la valoración entera en cada visita', async () => {
    apiCall.mockResolvedValue(fixtura(valueFixture))

    await store.valorar()

    // Sin caché a propósito: el backend la recalcula sobre los precios de hoy y
    // un total guardado estaría mal el 100 % de los días.
    expect(apiCall).toHaveBeenCalledWith('collection_value')
    expect(store.resumen.totals).toBeDefined()
    expect(store.cargandoResumen).toBe(false)
    expect(store.errorResumen).toBeNull()
  })

  it('valorar() con error deja el resumen a null y avisa', async () => {
    apiCall.mockResolvedValue({ status: 'error', message: 'No autenticado.', http_code: 401 })

    store.resumen = fixtura(valueFixture).data

    await store.valorar()

    expect(store.resumen).toBeNull()
    expect(store.errorResumen).toBe('No autenticado.')
  })

  it('cargarProgreso() trae el porcentaje por edición', async () => {
    apiCall.mockResolvedValue(fixtura(setsFixture))

    await store.cargarProgreso()

    expect(apiCall).toHaveBeenCalledWith('collection_sets')
    expect(store.progreso.sets).toBeDefined()
    expect(store.cargandoProgreso).toBe(false)
  })

  it('cargarProgreso() con error pone un mensaje propio si el backend no lo manda', async () => {
    apiCall.mockResolvedValue({ status: 'error', http_code: 500 })

    await store.cargarProgreso()

    expect(store.progreso).toBeNull()
    expect(store.errorProgreso).toMatch(/progreso/i)
  })
})

describe('anadir() — el camino de un clic', () => {
  it('manda solo el printing_uuid: los valores por defecto los pone el backend', async () => {
    apiCall.mockResolvedValue(fixtura(addFixture))

    const ok = await store.anadir('22cadebf-e1f3-5dd2-81e9-1d03df5ba19c')

    expect(ok).toBe(true)
    // Ni diálogo ni segundo paso: un `@click` es una carta añadida. Las cinco
    // dimensiones existen en el modelo porque la importación las necesita.
    expect(apiCall).toHaveBeenCalledWith('collection_add', {
      printing_uuid: '22cadebf-e1f3-5dd2-81e9-1d03df5ba19c'
    })
    expect(store.anadiendo).toEqual([])
  })

  it('el camino de «Opciones» va por el MISMO sitio, con las dimensiones dichas', async () => {
    apiCall.mockResolvedValue(fixtura(addFixture))

    await store.anadir('22cadebf-e1f3-5dd2-81e9-1d03df5ba19c', {
      finish: 'foil',
      language: 'Spanish',
      condition: 'LP',
      quantity: 3
    })

    // Con dos llamadas distintas, el clic simple y el selector avanzado podrían
    // divergir sin que nadie se enterase.
    expect(apiCall).toHaveBeenCalledWith('collection_add', {
      printing_uuid: '22cadebf-e1f3-5dd2-81e9-1d03df5ba19c',
      finish: 'foil',
      language: 'Spanish',
      condition: 'LP',
      quantity: 3
    })
  })

  it('el aviso dice cuántas tienes ya cuando el upsert ha sumado', async () => {
    // La fixtura capturada vuelve con `quantity: 2`: el upsert del repositorio
    // (`quantity = quantity + VALUES(quantity)`) suma en el servidor, así que
    // aquí no hay nada que deduplicar.
    apiCall.mockResolvedValue(fixtura(addFixture))

    await store.anadir('22cadebf-e1f3-5dd2-81e9-1d03df5ba19c')

    expect(store.aviso.tipo).toBe('ok')
    expect(store.aviso.texto).toMatch(/ya tienes 2/)
  })

  it('refresca en su sitio la línea que ya estaba en la lista', async () => {
    const respuesta = fixtura(addFixture)
    const enLista = itemsDeFixtura(0, 3)

    enLista[1] = { ...enLista[1], id: respuesta.data.item.id, quantity: 1 }
    store.items = enLista

    apiCall.mockResolvedValue(respuesta)

    await store.anadir(respuesta.data.item.printingUuid)

    expect(store.items).toHaveLength(3)
    expect(store.items[1].quantity).toBe(2)
  })

  it('el segundo clic sobre la misma carta no dobla la petición', async () => {
    let resolverAlta

    apiCall.mockImplementationOnce(
      () => new Promise((resolver) => { resolverAlta = resolver })
    )

    const primero = store.anadir('22cadebf-e1f3-5dd2-81e9-1d03df5ba19c')

    expect(store.estaAnadiendo('22cadebf-e1f3-5dd2-81e9-1d03df5ba19c')).toBe(true)
    await expect(store.anadir('22cadebf-e1f3-5dd2-81e9-1d03df5ba19c')).resolves.toBe(false)

    resolverAlta(fixtura(addFixture))
    await primero

    expect(apiCall).toHaveBeenCalledTimes(1)
    expect(store.estaAnadiendo('22cadebf-e1f3-5dd2-81e9-1d03df5ba19c')).toBe(false)
  })

  it('sin printing_uuid no llama al backend', async () => {
    await expect(store.anadir('')).resolves.toBe(false)

    expect(apiCall).not.toHaveBeenCalled()
  })

  it('con un 401 pide iniciar sesión en vez de repetir el mensaje del backend', async () => {
    apiCall.mockResolvedValue({
      status: 'error',
      message: 'No autenticado.',
      data: null,
      http_code: 401
    })

    await expect(store.anadir('22cadebf-e1f3-5dd2-81e9-1d03df5ba19c')).resolves.toBe(false)

    expect(store.aviso).toEqual({
      tipo: 'error',
      texto: 'Inicia sesión para añadir cartas a tu colección.'
    })
    expect(store.anadiendo).toEqual([])
  })

  it('con otro error enseña lo que dijo el backend', async () => {
    apiCall.mockResolvedValue({ status: 'error', message: 'Esa carta no existe.', http_code: 404 })

    await store.anadir('no-existe')

    expect(store.aviso.texto).toBe('Esa carta no existe.')
  })
})

describe('cambiarCantidad() — cero borra la línea', () => {
  it('no llama al backend si la cantidad es la que ya había', async () => {
    const item = itemsDeFixtura(0, 1)[0]

    await expect(store.cambiarCantidad(item, item.quantity)).resolves.toBe(true)

    expect(apiCall).not.toHaveBeenCalled()
  })

  it('guarda la cantidad nueva y reemplaza la línea en su sitio', async () => {
    const respuesta = fixtura(quantityFixture)
    const item = { ...itemsDeFixtura(0, 1)[0], id: respuesta.data.item.id, quantity: 2 }

    store.items = [item, ...itemsDeFixtura(1, 3)]

    apiCall.mockResolvedValue(respuesta)

    const ok = await store.cambiarCantidad(item, 1)

    expect(ok).toBe(true)
    expect(apiCall).toHaveBeenCalledWith('collection_update_quantity', {
      item_id: item.id,
      quantity: 1
    })
    expect(store.items[0].quantity).toBe(1)
    expect(store.items).toHaveLength(3)
    expect(store.estaGuardando(item.id)).toBe(false)
  })

  it('con cantidad 0 el backend devuelve removed y la fila DESAPARECE de la lista', async () => {
    const item = itemsDeFixtura(0, 1)[0]

    store.items = itemsDeFixtura(0, 3)

    // El backend no deja filas a 0, así que la vista no debe enseñar una línea
    // que ya no existe.
    apiCall.mockResolvedValue({
      status: 'success',
      message: 'Línea eliminada.',
      data: { removed: true },
      http_code: 200
    })

    await store.cambiarCantidad(item, 0)

    expect(store.items).toHaveLength(2)
    expect(store.items.find((i) => i.id === item.id)).toBeUndefined()
    expect(store.aviso.texto).toMatch(new RegExp(`${item.name} ya no está`))
  })

  it('con error deja la lista como estaba y avisa', async () => {
    const item = itemsDeFixtura(0, 1)[0]

    store.items = itemsDeFixtura(0, 3)

    apiCall.mockResolvedValue({ status: 'error', message: 'Cantidad inválida.', http_code: 400 })

    await expect(store.cambiarCantidad(item, 99)).resolves.toBe(false)

    expect(store.items).toHaveLength(3)
    expect(store.aviso).toEqual({ tipo: 'error', texto: 'Cantidad inválida.' })
    expect(store.estaGuardando(item.id)).toBe(false)
  })
})

describe('cambiarCondicion() — la edición que puede FUNDIR dos líneas', () => {
  it('no llama al backend si el estado es el que ya había', async () => {
    const item = itemsDeFixtura(0, 1)[0]

    await expect(store.cambiarCondicion(item, item.condition)).resolves.toBe(true)

    expect(apiCall).not.toHaveBeenCalled()
  })

  it('reinserta la línea movida DONDE ESTABA, no al final', async () => {
    const respuesta = fixtura(gradeFixture)
    const item = { ...itemsDeFixtura(1, 2)[0], id: respuesta.data.item.id, condition: 'NM' }

    store.items = [itemsDeFixtura(0, 1)[0], item, itemsDeFixtura(2, 3)[0]]

    apiCall.mockResolvedValue(respuesta)

    await store.cambiarCondicion(item, 'LP')

    expect(apiCall).toHaveBeenCalledWith('collection_change_grade', {
      item_id: item.id,
      condition: 'LP'
    })
    // Reordenar la lista entera movería cartas bajo el dedo del usuario en
    // mitad de una edición (`collection.js:333-337`).
    expect(store.items).toHaveLength(3)
    expect(store.items[1].condition).toBe('LP')
    expect(store.aviso.texto).toBe('Estado actualizado.')
  })

  it('cuando el backend FUNDE dos líneas, la lista se queda con una menos', async () => {
    // `condition_grade` está dentro del `UNIQUE KEY`: el cambio no edita la
    // fila, la MUEVE, y el destino puede estar ocupado. El `id` que vuelve no
    // es el que se mandó, así que no se puede dar por supuesto.
    const respuesta = fixtura(gradeFixture)
    const destino = { ...respuesta.data.item, id: 9001, quantity: 5 }

    respuesta.data.item = destino
    respuesta.data.merged = true

    const origen = { ...itemsDeFixtura(1, 2)[0], id: 6827, condition: 'NM' }

    store.items = [itemsDeFixtura(0, 1)[0], origen, { ...destino, quantity: 3 }]

    apiCall.mockResolvedValue(respuesta)

    await store.cambiarCondicion(origen, 'LP')

    expect(store.items).toHaveLength(2)
    expect(store.items.find((i) => i.id === 6827)).toBeUndefined()
    expect(store.items.find((i) => i.id === 9001).quantity).toBe(5)
    expect(store.aviso.texto).toMatch(/unida a la línea/)
  })

  it('con un filtro de estado puesto, la línea que deja de encajar DESAPARECE', async () => {
    // Si estás filtrando por NM y marcas una carta como LP, dejarla en pantalla
    // sería enseñar una línea que contradice el filtro (`collection.js:373-375`).
    const respuesta = fixtura(gradeFixture)
    const item = { ...itemsDeFixtura(1, 2)[0], id: respuesta.data.item.id, condition: 'NM' }

    store.filtros.condition = 'NM'
    store.items = [itemsDeFixtura(0, 1)[0], item]

    apiCall.mockResolvedValue(respuesta)

    await store.cambiarCondicion(item, 'LP')

    expect(store.items).toHaveLength(1)
    expect(store.items[0].id).not.toBe(item.id)
  })

  it('con error deja la lista como estaba', async () => {
    const item = { ...itemsDeFixtura(1, 2)[0], condition: 'NM' }

    store.items = itemsDeFixtura(0, 3)

    apiCall.mockResolvedValue({ status: 'error', message: 'Estado inválido.', http_code: 400 })

    await expect(store.cambiarCondicion(item, 'LP')).resolves.toBe(false)

    expect(store.items).toHaveLength(3)
    expect(store.aviso).toEqual({ tipo: 'error', texto: 'Estado inválido.' })
  })
})

describe('quitar()', () => {
  it('saca la línea de la lista sin pasar por la cantidad', async () => {
    const item = itemsDeFixtura(0, 1)[0]

    store.items = itemsDeFixtura(0, 3)

    apiCall.mockResolvedValue(fixtura(removeFixture))

    const ok = await store.quitar(item)

    expect(ok).toBe(true)
    expect(apiCall).toHaveBeenCalledWith('collection_remove', { item_id: item.id })
    expect(store.items).toHaveLength(2)
    expect(store.aviso.tipo).toBe('ok')
    expect(store.estaGuardando(item.id)).toBe(false)
  })

  it('con error no quita nada', async () => {
    const item = itemsDeFixtura(0, 1)[0]

    store.items = itemsDeFixtura(0, 3)

    apiCall.mockResolvedValue({ status: 'error', http_code: 500 })

    await expect(store.quitar(item)).resolves.toBe(false)

    expect(store.items).toHaveLength(3)
    expect(store.aviso.texto).toMatch(/no se pudo quitar/i)
  })
})

describe('los filtros y la query string', () => {
  it('aplicarFiltros conserva los que no se tocan y vuelve a buscar', async () => {
    apiCall.mockResolvedValue(paginaColeccion(0, 5))

    store.filtros.set = 'LTC'

    await store.aplicarFiltros({ condition: 'NM' })

    expect(store.filtros.set).toBe('LTC')
    expect(store.filtros.condition).toBe('NM')
    expect(apiCall).toHaveBeenCalledTimes(1)
  })

  it('limpiarFiltros los devuelve al defecto y vuelve a buscar', async () => {
    apiCall.mockResolvedValue(paginaColeccion(0, 5))

    store.filtros.set = 'LTC'
    store.filtros.sort = 'name'

    await store.limpiarFiltros()

    expect(store.filtros.set).toBe('')
    expect(store.filtros.sort).toBe('price_desc')
    expect(store.filtrosActivos).toBe(0)
  })

  it('desdeQuery rehidrata filtros y modo de vista', () => {
    store.desdeQuery({ set: 'LTC', price_min: 5, view: 'table' })

    expect(store.filtros.set).toBe('LTC')
    expect(store.filtros.price_min).toBe('5')
    expect(store.vista).toBe('table')
  })

  it('desdeQuery cae a la rejilla con cualquier otro valor de view', () => {
    store.vista = 'table'

    store.desdeQuery({ view: 'lo-que-sea' })

    expect(store.vista).toBe('grid')
    expect(store.filtros.sort).toBe('price_desc')
  })
})
