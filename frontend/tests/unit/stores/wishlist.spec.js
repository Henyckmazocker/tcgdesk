import { beforeEach, describe, expect, it, vi } from 'vitest'
import { createPinia, setActivePinia } from 'pinia'

import { useWishlistStore } from '@/stores/wishlist'
import { useCollectionStore } from '@/stores/collection'
import { apiCall } from '@/services/api'

import listaFixture from '../../fixtures/collection_list_wishlist.json'
import valorFixture from '../../fixtures/collection_value_wishlist.json'
import progresoFixture from '../../fixtures/collection_sets_wishlist.json'
import deseoFixture from '../../fixtures/collection_add_wishlist.json'
import cumplirFixture from '../../fixtures/collection_fulfill_wish.json'
import cumplirTotalFixture from '../../fixtures/collection_fulfill_wish_total.json'
import faltantesFixture from '../../fixtures/deck_get_faltantes.json'
import loteFixture from '../../fixtures/import_apply_deseos.json'
import deseadosFixture from '../../fixtures/collection_wished_uuids.json'
import cartaFixture from '../../fixtures/catalog_card.json'

/**
 * `stores/wishlist.js` — la lista de deseos.
 *
 * **Este fichero se parece a `collection.spec.js` y eso es una trampa, no una
 * comodidad.** Los dos stores comparten los nombres de casi todo (`hayMas`,
 * `vacio`, `filtrosActivos`, `cambiarCantidad`, `cambiarCondicion`, `quitar`),
 * así que un test copiado de allí pasaría aquí **sin probar nada de lo que este
 * store tiene de propio**. Por eso cada test de abajo se apoya en algo que el
 * store de colección NO hace:
 *
 *  - **`is_wishlist: true` viaja en cada `collection_list`**, y no se puede
 *    apagar desde los filtros: no recorta la lista, ELIGE el conjunto.
 *  - **`deseando` / `estaDeseando`**, que es estado propio y no `anadiendo`: los
 *    dos indexan por `printing_uuid`, así que compartirlos dejaría muerto el
 *    botón «Añadir» de la misma carta al pulsar el corazón.
 *  - **`cumplir()`**, que no existe en el otro store, y cuyo cuidado no tiene
 *    equivalente: al cumplir un deseo el `id` de la línea PUEDE DEJAR DE
 *    EXISTIR, así que manda el `origen` de la respuesta y no el `item.id` que se
 *    mandó.
 *
 * Las fixturas están capturadas contra el backend de dev con un usuario de usar
 * y tirar; ver `tests/fixtures/README.md`.
 */

vi.mock('@/services/api', () => ({
  apiCall: vi.fn(),
  catalogGet: vi.fn()
}))

/** Copia profunda: los stores mutan lo que reciben y las fixturas son de todos. */
function fixtura(json) {
  return structuredClone(json)
}

/** Un tramo de la lista capturada, con el sobre del backend puesto. */
function paginaDeseos(desde, hasta, nextCursor = null) {
  const items = fixtura(listaFixture).data.items.slice(desde, hasta)

  return {
    status: 'success',
    message: 'Colección.',
    data: { items, nextCursor, count: items.length },
    http_code: 200
  }
}

/** Las líneas de la fixtura, para sembrar la lista sin pasar por `buscar()`. */
function deseosDeFixtura(desde, hasta) {
  return fixtura(listaFixture).data.items.slice(desde, hasta)
}

/** El deseo de 4 ejemplares, que es el que ejercita el movimiento parcial. */
function deseoDeCuatro() {
  return fixtura(listaFixture).data.items.find((i) => i.quantity === 4)
}

let store

beforeEach(() => {
  // Pinia REAL: `createTestingPinia` no ejecuta las acciones con su
  // `stubActions` por defecto, y aquí lo que se prueba son ellas.
  setActivePinia(createPinia())
  store = useWishlistStore()

  apiCall.mockReset()
})

describe('la fixtura dice de qué lista hablamos', () => {
  it('todo lo capturado viene marcado como deseo', () => {
    const items = fixtura(listaFixture).data.items

    // Y el store bajo prueba es el de deseos: apuntar este fichero al de
    // colección tiene que ponerlo rojo, no dejarlo pasar de largo.
    expect(store.$id).toBe('wishlist')
    expect(items.length).toBeGreaterThan(0)
    // El flag del CONTRATO es `isWishlist` en camelCase
    // (`MySqlCollectionRepository.php:46`); el del payload de entrada es
    // `is_wishlist`. Confundirlos deja la vista leyendo `undefined`.
    expect(items.every((i) => i.isWishlist === true)).toBe(true)
  })
})

describe('el conjunto, que no es un filtro', () => {
  it('`buscar()` manda SIEMPRE `is_wishlist: true`', async () => {
    apiCall.mockResolvedValue(paginaDeseos(0, 4))

    await store.buscar()

    expect(apiCall).toHaveBeenCalledWith('collection_list', expect.objectContaining({
      is_wishlist: true,
      sort: 'price_desc',
      limit: 60
    }))
    expect(store.items).toHaveLength(4)
  })

  it('la bandera no vive en `filtros` y no hay forma de apagarla', async () => {
    apiCall.mockResolvedValue(paginaDeseos(0, 4))

    // Ni limpiando los filtros, que es el gesto más cercano a "quítalo todo".
    await store.limpiarFiltros()

    expect(store.filtros).not.toHaveProperty('is_wishlist')
    expect(apiCall).toHaveBeenLastCalledWith('collection_list', expect.objectContaining({
      is_wishlist: true
    }))
  })

  it('la página siguiente también la lleva: paginar no puede cambiar de lista', async () => {
    apiCall.mockResolvedValueOnce(paginaDeseos(0, 2, 'eyJvIjoyfQ'))
    await store.buscar()

    apiCall.mockResolvedValueOnce(paginaDeseos(2, 4))
    await store.cargarMas()

    expect(apiCall).toHaveBeenLastCalledWith('collection_list', expect.objectContaining({
      is_wishlist: true,
      cursor: 'eyJvIjoyfQ'
    }))
    expect(store.items).toHaveLength(4)
    expect(store.hayMas).toBe(false)
  })

  it('el store es OTRO singleton: llenar los deseos no toca la colección', async () => {
    const coleccion = useCollectionStore()

    apiCall.mockResolvedValue(paginaDeseos(0, 4))
    await store.buscar()

    // Esta es la razón de que sean dos ficheros y no una bandera: si fuera un
    // solo store, `/collection` enseñaría deseos en su primer frame.
    expect(store.items).toHaveLength(4)
    expect(coleccion.items).toEqual([])
    expect(coleccion.nextCursor).toBeNull()
  })
})

describe('el corazón', () => {
  it('`desear()` va por `collection_add` con la bandera, sin endpoint nuevo', async () => {
    apiCall.mockResolvedValue(fixtura(deseoFixture))

    const uuid = fixtura(deseoFixture).data.item.printingUuid
    const deseada = await store.desear(uuid)

    expect(deseada).toBe(true)
    // Ni `finish`, ni `language`, ni `condition`, ni `quantity`: la misma
    // promesa del botón grande, los defaults los pone el backend.
    expect(apiCall).toHaveBeenCalledWith('collection_add', {
      printing_uuid: uuid,
      is_wishlist: true
    })
    expect(store.aviso).toEqual({
      tipo: 'ok',
      texto: `${fixtura(deseoFixture).data.item.name} añadida a tu lista de deseos.`
    })
  })

  it('las opciones se respetan, pero la bandera NO se puede pisar', async () => {
    apiCall.mockResolvedValue(fixtura(deseoFixture))

    await store.desear('un-uuid', { condition: 'LP', quantity: 3, is_wishlist: false })

    // Un `is_wishlist: false` colado en las opciones metería la carta en la
    // colección desde la lista de deseos, en silencio.
    expect(apiCall).toHaveBeenCalledWith('collection_add', {
      printing_uuid: 'un-uuid',
      condition: 'LP',
      quantity: 3,
      is_wishlist: true
    })
  })

  it('`estaDeseando` es propio y NO es `estaAnadiendo` de la colección', async () => {
    const coleccion = useCollectionStore()

    let resolver
    apiCall.mockReturnValue(new Promise((r) => { resolver = r }))

    const enVuelo = store.desear('un-uuid')

    expect(store.estaDeseando('un-uuid')).toBe(true)
    // LA trampa del plan: si el corazón compartiera `anadiendo`, pulsarlo
    // dejaría apagado el botón «Añadir» de la misma carta.
    expect(coleccion.estaAnadiendo('un-uuid')).toBe(false)
    expect(store.anadiendo).toBeUndefined()

    resolver(fixtura(deseoFixture))
    await enVuelo

    expect(store.estaDeseando('un-uuid')).toBe(false)
  })

  it('dos clics seguidos sobre el mismo printing no doblan la petición', async () => {
    let resolver
    apiCall.mockReturnValue(new Promise((r) => { resolver = r }))

    const primera = store.desear('un-uuid')
    const segunda = await store.desear('un-uuid')

    expect(segunda).toBe(false)
    expect(apiCall).toHaveBeenCalledTimes(1)

    resolver(fixtura(deseoFixture))
    await primera
  })

  it('un 401 lo dice con las palabras de esta lista, no con las de la colección', async () => {
    apiCall.mockResolvedValue({ status: 'error', message: 'No autenticado.', http_code: 401 })

    const deseada = await store.desear('un-uuid')

    expect(deseada).toBe(false)
    expect(store.aviso.tipo).toBe('error')
    expect(store.aviso.texto).toBe('Inicia sesión para guardar cartas en tu lista de deseos.')
  })

  it('desear lo que ya está en la lista refresca su línea en el sitio', async () => {
    store.items = deseosDeFixtura(0, 4)

    const yaEstaba = fixtura(listaFixture).data.items[1]
    const sumada = { ...yaEstaba, quantity: yaEstaba.quantity + 1 }

    apiCall.mockResolvedValue({
      status: 'success',
      message: 'Carta añadida a la colección.',
      data: { id: sumada.id, item: sumada },
      http_code: 200
    })

    await store.desear(yaEstaba.printingUuid)

    expect(store.items[1].quantity).toBe(yaEstaba.quantity + 1)
    expect(store.items).toHaveLength(4)
    expect(store.aviso.texto).toBe(`${sumada.name} en tu lista de deseos — ya quieres ${sumada.quantity}.`)
  })
})

describe('«ya la tengo» — cumplir el deseo', () => {
  it('llama a `collection_fulfill_wish` con el id de la línea de DESEO', async () => {
    apiCall.mockResolvedValue(fixtura(cumplirFixture))

    store.items = deseosDeFixtura(0, 4)
    const deseo = deseoDeCuatro()

    const cumplido = await store.cumplir(deseo, { cantidad: 1 })

    expect(cumplido).toBe(true)
    expect(apiCall).toHaveBeenCalledWith('collection_fulfill_wish', {
      item_id: deseo.id,
      quantity: 1
    })
  })

  it('sin `cantidad` no manda `quantity`: el backend distingue ausente de null', async () => {
    apiCall.mockResolvedValue(fixtura(cumplirTotalFixture))

    store.items = deseosDeFixtura(0, 4)

    await store.cumplir(store.items[0])

    // `FulfillWish.php` lo lee con `array_key_exists()`: ausente es 1 y el null
    // explícito es la fila entera. Mandar `quantity: undefined` los confundiría.
    expect(apiCall).toHaveBeenCalledWith('collection_fulfill_wish', {
      item_id: store.items.length ? fixtura(listaFixture).data.items[0].id : null
    })
  })

  it('`condicion` solo viaja si se pide; si no, el deseo conserva la suya', async () => {
    apiCall.mockResolvedValue(fixtura(cumplirFixture))

    const deseo = deseoDeCuatro()
    store.items = [deseo]

    await store.cumplir(deseo, { cantidad: 1, condicion: null })
    expect(apiCall).toHaveBeenLastCalledWith('collection_fulfill_wish', {
      item_id: deseo.id,
      quantity: 1
    })

    await store.cumplir(deseo, { cantidad: 1, condicion: 'LP' })
    expect(apiCall).toHaveBeenLastCalledWith('collection_fulfill_wish', {
      item_id: deseo.id,
      quantity: 1,
      condition: 'LP'
    })
  })

  it('MOVIMIENTO PARCIAL: la línea se queda, con lo que dice `origen`', async () => {
    apiCall.mockResolvedValue(fixtura(cumplirFixture))

    store.items = deseosDeFixtura(0, 4)

    const posicion = store.items.findIndex((i) => i.quantity === 4)
    const deseo = store.items[posicion]

    await store.cumplir(deseo, { cantidad: 1 })

    const origen = fixtura(cumplirFixture).data.origen

    // Sigue habiendo cuatro líneas, y la tocada NO se ha movido de sitio:
    // reordenar movería cartas bajo el dedo del usuario.
    expect(store.items).toHaveLength(4)
    expect(store.items[posicion].id).toBe(origen.id)
    expect(store.items[posicion].quantity).toBe(3)
    expect(store.items[posicion].isWishlist).toBe(true)
    expect(store.aviso).toEqual({
      tipo: 'ok',
      texto: `${origen.name} en tu colección — todavía quieres 3.`
    })
  })

  it('MOVIMIENTO TOTAL: `origen: null` quita la línea, aunque el id que vuelva exista', async () => {
    apiCall.mockResolvedValue(fixtura(cumplirTotalFixture))

    store.items = deseosDeFixtura(0, 4)
    const deseo = store.items[0]

    await store.cumplir(deseo)

    const respuesta = fixtura(cumplirTotalFixture).data

    // El `item` que vuelve es la línea de COLECCIÓN, con OTRO id y
    // `isWishlist: false`: si la vista lo reinsertara aquí, la lista de deseos
    // enseñaría una carta que ya es tuya.
    expect(respuesta.origen).toBeNull()
    expect(respuesta.item.id).not.toBe(deseo.id)
    expect(respuesta.item.isWishlist).toBe(false)

    expect(store.items).toHaveLength(3)
    expect(store.items.map((i) => i.id)).not.toContain(deseo.id)
    expect(store.items.map((i) => i.id)).not.toContain(respuesta.item.id)
    expect(store.aviso.texto).toBe(`${respuesta.item.name} ya es tuya: fuera de la lista de deseos.`)
  })

  it('la línea se marca ocupada mientras se cumple, y se suelta al acabar', async () => {
    let resolver
    apiCall.mockReturnValue(new Promise((r) => { resolver = r }))

    const deseo = deseoDeCuatro()
    store.items = [deseo]

    const enVuelo = store.cumplir(deseo, { cantidad: 1 })

    expect(store.estaGuardando(deseo.id)).toBe(true)

    resolver(fixtura(cumplirFixture))
    await enVuelo

    expect(store.estaGuardando(deseo.id)).toBe(false)
  })

  it('un 422 no toca la lista: el backend lo rechaza y aquí no se inventa nada', async () => {
    // Lo devuelve el backend cuando la línea no es un deseo o se piden más
    // ejemplares de los que se querían (`FulfillWish.php`).
    apiCall.mockResolvedValue({
      status: 'error',
      message: 'No puedes cumplir 9 de un deseo de 4.',
      http_code: 422
    })

    store.items = deseosDeFixtura(0, 4)
    const deseo = deseoDeCuatro()

    const cumplido = await store.cumplir(deseo, { cantidad: 9 })

    expect(cumplido).toBe(false)
    expect(store.items).toHaveLength(4)
    expect(store.items.map((i) => i.id)).toContain(deseo.id)
    expect(store.aviso).toEqual({ tipo: 'error', texto: 'No puedes cumplir 9 de un deseo de 4.' })
  })

  it('cumplir un deseo que ya no está en la lista no rompe ni inventa filas', async () => {
    apiCall.mockResolvedValue(fixtura(cumplirTotalFixture))

    // Pasa de verdad: el scroll infinito recicla, y otra pestaña pudo tocarlo.
    store.items = deseosDeFixtura(1, 4)

    await store.cumplir({ id: 999999, name: 'Una que ya no está' })

    expect(store.items).toHaveLength(3)
  })
})

describe('los getters, que se llaman igual que en la colección', () => {
  it('arranca vacía, sin más páginas y sin nada en vuelo', () => {
    expect(store.$id).toBe('wishlist')
    expect(store.hayMas).toBe(false)
    expect(store.vacio).toBe(true)
    // `deseando`, no `anadiendo`: el estado en vuelo del corazón es propio.
    expect(store.deseando).toEqual([])
    expect(store.anadiendo).toBeUndefined()
  })

  it('`filtrosActivos` no cuenta el orden por defecto ni la bandera', async () => {
    apiCall.mockResolvedValue(paginaDeseos(0, 4))

    expect(store.filtrosActivos).toBe(0)

    await store.aplicarFiltros({ rarity: 'mythic' })

    // Uno, no dos: `is_wishlist` no está en `filtros` y por tanto no se cuenta
    // como filtro puesto — que es justo lo que significa que no lo sea. Pero sí
    // sale en la petición, que es la otra mitad de la misma afirmación.
    expect(store.filtrosActivos).toBe(1)
    expect(apiCall).toHaveBeenLastCalledWith('collection_list', expect.objectContaining({
      rarity: 'mythic',
      is_wishlist: true
    }))
  })

  it('una respuesta lenta no pisa a la reciente', async () => {
    let resolverLenta
    apiCall.mockReturnValueOnce(new Promise((r) => { resolverLenta = r }))

    const lenta = store.buscar()

    apiCall.mockResolvedValueOnce(paginaDeseos(0, 2))
    await store.buscar()

    resolverLenta(paginaDeseos(0, 4))
    await lenta

    expect(store.items).toHaveLength(2)
    // Las dos peticiones eran de deseos: ninguna se coló en la otra lista.
    expect(apiCall.mock.calls.every(([, carga]) => carga.is_wishlist === true)).toBe(true)
  })

  it('un error de red deja la lista vacía y el mensaje de ESTA lista', async () => {
    apiCall.mockResolvedValue({ status: 'error', http_code: 500 })

    store.items = deseosDeFixtura(0, 4)
    await store.buscar()

    expect(store.items).toEqual([])
    expect(store.error).toBe('No se pudo cargar tu lista de deseos.')
  })
})

describe('las ediciones en línea, que aquí hablan de deseos', () => {
  it('bajar la cantidad a 0 borra el deseo y lo dice como deseo', async () => {
    store.items = deseosDeFixtura(0, 4)
    const deseo = store.items[0]

    apiCall.mockResolvedValue({
      status: 'success',
      message: 'Carta eliminada.',
      data: { removed: true },
      http_code: 200
    })

    await store.cambiarCantidad(deseo, 0)

    expect(store.items.map((i) => i.id)).not.toContain(deseo.id)
    expect(store.aviso.texto).toBe(`${deseo.name} ya no está en tu lista de deseos.`)
  })

  it('quitar dice «lista de deseos», no «colección»', async () => {
    store.items = deseosDeFixtura(0, 4)
    const deseo = store.items[2]

    apiCall.mockResolvedValue({ status: 'success', message: 'Carta eliminada.', data: null, http_code: 200 })

    await store.quitar(deseo)

    expect(apiCall).toHaveBeenCalledWith('collection_remove', { item_id: deseo.id })
    expect(store.items).toHaveLength(3)
    expect(store.aviso.texto).toBe(`${deseo.name} ya no está en tu lista de deseos.`)
  })

  it('cambiar de estado puede FUNDIR dos deseos: manda el id que vuelve', async () => {
    store.items = deseosDeFixtura(0, 4)

    const movido = store.items[1]
    const destino = store.items[3]
    const fundido = { ...destino, quantity: destino.quantity + movido.quantity, condition: 'LP' }

    apiCall.mockResolvedValue({
      status: 'success',
      message: 'Estado actualizado.',
      data: { item: fundido, merged: true },
      http_code: 200
    })

    await store.cambiarCondicion(movido, 'LP')

    expect(store.items).toHaveLength(3)
    expect(store.items.map((i) => i.id)).not.toContain(movido.id)
    expect(store.items.find((i) => i.id === destino.id).quantity).toBe(fundido.quantity)
    expect(store.aviso.texto).toBe(`${movido.name}: unida al deseo que ya tenías en ese estado.`)
  })
})

describe('la query string', () => {
  it('rehidrata filtros y vista, y nunca un `is_wishlist` de fuera', async () => {
    apiCall.mockResolvedValue(paginaDeseos(0, 4))

    store.desdeQuery({ rarity: 'rare', view: 'table', is_wishlist: 'false', sort: 'name' })

    expect(store.filtros.rarity).toBe('rare')
    expect(store.filtros.sort).toBe('name')
    expect(store.vista).toBe('table')
    // Un `?is_wishlist=false` en la URL no puede convertir `/wishlist` en
    // `/collection`: la bandera no sale de los filtros, y la petición que viene
    // detrás sigue pidiendo deseos.
    expect(store.filtros).not.toHaveProperty('is_wishlist')

    await store.buscar()

    expect(apiCall).toHaveBeenLastCalledWith('collection_list', expect.objectContaining({
      is_wishlist: true
    }))
  })
})

/**
 * M4 — el valor de lo que quieres.
 *
 * Las dos llamadas que el backend aceptaba desde el primer día y a las que
 * nadie mandaba la bandera. Son las MISMAS acciones que usa `/`
 * (`collection_value`) y `/sets` (`collection_sets`), así que el único trozo de
 * código propio es el flag — y es exactamente lo que se mira aquí. Apuntar
 * estos tests a `useCollectionStore` los pone rojos: allí las dos acciones
 * llaman sin payload.
 */
describe('el valor de la lista de deseos', () => {
  it('`valorar()` manda `collection_value` con `is_wishlist: true`', async () => {
    apiCall.mockResolvedValue(fixtura(valorFixture))

    await store.valorar()

    expect(store.$id).toBe('wishlist')
    expect(apiCall).toHaveBeenCalledWith('collection_value', { is_wishlist: true })
    expect(store.cargandoResumen).toBe(false)
    expect(store.errorResumen).toBeNull()
  })

  it('guarda el resumen entero: total, los dos desgloses y el top 10', async () => {
    apiCall.mockResolvedValue(fixtura(valorFixture))

    await store.valorar()

    const datos = fixtura(valorFixture).data

    expect(store.$id).toBe('wishlist')
    expect(store.resumen.totals).toEqual(datos.totals)
    expect(store.resumen.bySet).toHaveLength(datos.bySet.length)
    expect(store.resumen.byRarity).toHaveLength(datos.byRarity.length)
    // El top son 10 como mucho, y la fixtura se capturó con 17 líneas con
    // precio a propósito: con menos de once el recorte no se ejercitaría.
    expect(store.resumen.topCards).toHaveLength(10)
    expect(datos.totals.uniqueItems).toBeGreaterThan(10)
  })

  it('lo que valora son DESEOS: todo lo que vuelve viene con `isWishlist`', async () => {
    apiCall.mockResolvedValue(fixtura(valorFixture))

    await store.valorar()

    // Si la bandera se perdiera por el camino, el backend devolvería la
    // colección y este `every` se caería: es la comprobación que separa esta
    // llamada de la del dashboard.
    expect(store.$id).toBe('wishlist')
    expect(store.resumen.topCards.every((c) => c.isWishlist === true)).toBe(true)
  })

  it('un fallo deja el resumen a null y el mensaje a la vista', async () => {
    apiCall.mockResolvedValue({ status: 'error', message: 'No autorizado.', http_code: 401 })

    await store.valorar()

    expect(store.$id).toBe('wishlist')
    expect(store.resumen).toBeNull()
    expect(store.errorResumen).toBe('No autorizado.')
    expect(store.cargandoResumen).toBe(false)
  })

  it('no toca nada del store de colección', async () => {
    const coleccion = useCollectionStore()

    apiCall.mockResolvedValue(fixtura(valorFixture))

    await store.valorar()

    // Dos singletons con los mismos nombres de estado: si el valor de los
    // deseos aterrizara en el de colección, `/` enseñaría como tuyo lo que solo
    // quieres.
    expect(coleccion.resumen).toBeNull()
    expect(store.resumen).not.toBeNull()
  })
})

describe('cuántas quieres de cada edición', () => {
  it('`cargarProgreso()` manda `collection_sets` con `is_wishlist: true`', async () => {
    apiCall.mockResolvedValue(fixtura(progresoFixture))

    await store.cargarProgreso()

    expect(apiCall).toHaveBeenCalledWith('collection_sets', { is_wishlist: true })
    expect(store.progreso.sets).toHaveLength(fixtura(progresoFixture).data.sets.length)
    expect(store.errorProgreso).toBeNull()
  })

  it('guarda el `percent` TAL CUAL, aunque sobre deseos no signifique nada', async () => {
    apiCall.mockResolvedValue(fixtura(progresoFixture))

    await store.cargarProgreso()

    // El backend divide igual, sin saber de qué conjunto habla. El store no lo
    // borra —quien lo depure tiene que poder verlo—: la decisión de NO pintarlo
    // es de la vista, y está probada en `SetsView.spec.js`.
    expect(store.$id).toBe('wishlist')
    expect(store.progreso.sets.every((s) => s.percent !== null)).toBe(true)
  })

  it('un fallo deja el progreso a null y el mensaje a la vista', async () => {
    apiCall.mockResolvedValue({ status: 'error', message: 'No autorizado.', http_code: 401 })

    await store.cargarProgreso()

    expect(store.$id).toBe('wishlist')
    expect(store.progreso).toBeNull()
    expect(store.errorProgreso).toBe('No autorizado.')
    expect(store.cargandoProgreso).toBe(false)
  })

  it('no toca nada del store de colección', async () => {
    const coleccion = useCollectionStore()

    apiCall.mockResolvedValue(fixtura(progresoFixture))

    await store.cargarProgreso()

    expect(coleccion.progreso).toBeNull()
    expect(store.progreso).not.toBeNull()
  })
})

/**
 * `desearLote()` — la puerta de entrada masiva del plan.
 *
 * Lo que se prueba aquí no es que la llamada salga, sino **con qué sale**: las
 * cuatro dimensiones exactas y una sola petición. Las dos cosas son el hito.
 */
describe('mandar un lote entero a la lista de deseos', () => {
  /** Las tres líneas que faltan, con sus cuatro dimensiones tal cual. */
  function loQueFalta() {
    return fixtura(faltantesFixture).data.availability.map((l) => ({
      printingUuid: l.printingUuid,
      finish: l.finish,
      language: l.language,
      condition: l.condition,
      quantity: l.missing
    }))
  }

  it('manda UNA sola petición con el lote entero, no una por carta', async () => {
    apiCall.mockResolvedValue(fixtura(loteFixture))

    const ok = await store.desearLote(loQueFalta())

    expect(ok).toBe(true)

    // Una por línea serían 3 peticiones aquí y 60 en un Commander a medio
    // montar: el límite son 60 por minuto y por IP, y `routes.php:228-231` ya
    // dejó escrito que trocear un lote choca contra él. Y además: `ApplyImport`
    // escribe el lote entero o no escribe nada.
    expect(apiCall).toHaveBeenCalledTimes(1)
    expect(apiCall.mock.calls[0][0]).toBe('import_apply')
    expect(apiCall.mock.calls[0][1].rows).toHaveLength(3)
  })

  it('EL HITO: cada fila viaja con sus CUATRO dimensiones, no con los defectos', async () => {
    const faltan = fixtura(faltantesFixture).data.availability

    // La fixtura está capturada para esto: ninguna de las tres líneas es
    // `normal` / `English` / `NM`, así que un deseo nacido con los valores por
    // defecto de `CollectionItem` no cerraría NINGUNO de los tres huecos.
    expect(faltan.map((l) => l.finish)).toEqual(['etched', 'normal', 'foil'])
    expect(faltan.map((l) => l.language)).toEqual(['Spanish', 'Japanese', 'English'])
    expect(faltan.map((l) => l.condition)).toEqual(['EX', 'LP', 'NM'])

    apiCall.mockResolvedValue(fixtura(loteFixture))

    await store.desearLote(loQueFalta())

    const cuerpo = apiCall.mock.calls[0][1]

    expect(cuerpo.is_wishlist).toBe(true)
    expect(cuerpo.rows).toEqual(
      faltan.map((l) => ({
        printingUuid: l.printingUuid,
        finish: l.finish,
        language: l.language,
        condition: l.condition,
        quantity: l.missing
      }))
    )
  })

  it('la cantidad es lo que FALTA, no lo que el mazo pide', async () => {
    apiCall.mockResolvedValue(fixtura(loteFixture))

    // Pide 4 y tiene 1: se desean 3. Desear 4 dejaría una de más en la lista
    // de la compra, y el usuario pagaría por ella.
    await store.desearLote([
      { printingUuid: 'uuid-1', finish: 'foil', language: 'English', condition: 'NM', quantity: 3 }
    ])

    expect(apiCall.mock.calls[0][1].rows[0].quantity).toBe(3)
  })

  it('sin nada que pedir no se llama al backend', async () => {
    expect(await store.desearLote([])).toBe(false)
    // Las líneas completas llegan con `missing: 0` y no son un deseo: pedir
    // cero ejemplares daría un 422 del dominio (`CollectionItem.php:107`).
    expect(await store.desearLote([{ printingUuid: 'uuid-1', quantity: 0 }])).toBe(false)
    expect(await store.desearLote(null)).toBe(false)

    expect(apiCall).not.toHaveBeenCalled()
  })

  it('el doble clic no duplica el lote: `anadiendoLote` cierra la puerta', async () => {
    let resolver
    apiCall.mockReturnValue(new Promise((r) => { resolver = r }))

    const primero = store.desearLote(loQueFalta())

    expect(store.anadiendoLote).toBe(true)

    // El segundo clic cae mientras el primero está en vuelo: si pasara, la
    // lista de deseos acabaría con el doble de ejemplares.
    expect(await store.desearLote(loQueFalta())).toBe(false)
    expect(apiCall).toHaveBeenCalledTimes(1)

    resolver(fixtura(loteFixture))
    await primero

    expect(store.anadiendoLote).toBe(false)
  })

  it('el aviso dice cuántos ejemplares, que es lo que el backend devuelve', async () => {
    apiCall.mockResolvedValue(fixtura(loteFixture))

    await store.desearLote(loQueFalta())

    expect(store.aviso.tipo).toBe('ok')
    expect(store.aviso.texto).toContain(String(fixtura(loteFixture).data.totalQuantity))
    expect(store.aviso.texto).toContain('lista de deseos')
  })

  it('un 401 se dice con el mensaje de sesión y no se traga el fallo', async () => {
    apiCall.mockResolvedValue({ status: 'error', message: 'Authentication required', http_code: 401 })

    expect(await store.desearLote(loQueFalta())).toBe(false)
    expect(store.aviso.tipo).toBe('error')
    expect(store.aviso.texto).toContain('Inicia sesión')
    expect(store.anadiendoLote).toBe(false)
  })

  it('no toca la lista: quien pulsa el botón no está mirando `/wishlist`', async () => {
    store.items = deseosDeFixtura(0, 2)

    apiCall.mockResolvedValue(fixtura(loteFixture))

    await store.desearLote(loQueFalta())

    // Recargar una lista que nadie tiene delante es una petición de más; al
    // entrar en `/wishlist` se lee entera.
    expect(store.$id).toBe('wishlist')
    expect(store.items).toHaveLength(2)
    expect(apiCall).toHaveBeenCalledTimes(1)
  })

  it('no escribe nada en el store de colección: querer no es tener', async () => {
    const coleccion = useCollectionStore()

    apiCall.mockResolvedValue(fixtura(loteFixture))

    await store.desearLote(loQueFalta())

    expect(coleccion.items).toEqual([])
    expect(coleccion.aviso).toBeNull()
  })
})

describe('el corazón relleno: el `Set` de impresiones deseadas', () => {
  /** Los uuids de la fixtura, que es lo que devolvió el backend de verdad. */
  const DESEADOS = fixtura(deseadosFixture).data.uuids

  it('la fixtura dice qué está probando: sin repetir, y con la carta del catálogo dentro', () => {
    // El `DISTINCT` del contrato: el usuario desechable tenía DOS líneas de
    // Sol Ring —normal/English/NM y foil/Japanese/LP— y aquí sale una sola
    // vez, porque el corazón habla de la impresión y no de la línea.
    expect(DESEADOS).toHaveLength(new Set(DESEADOS).size)
    expect(fixtura(deseadosFixture).data.count).toBe(DESEADOS.length)
    // Y la carta de `catalog_card.json` está entre ellas: es lo que permite
    // probar el relleno en la ficha sin inventarse el enlace entre fixturas.
    expect(DESEADOS).toContain(cartaFixture.uuid)
  })

  it('`cargarDeseados()` pide la acción NUEVA, sin un solo campo, y llena el `Set`', async () => {
    apiCall.mockResolvedValue(fixtura(deseadosFixture))

    expect(await store.cargarDeseados()).toBe(true)

    // Sin payload: la acción no tiene ni un campo, ni siquiera opcional. La
    // única entrada es el `user_id` que pone AuthMiddleware.
    expect(apiCall).toHaveBeenCalledWith('collection_wished_uuids')
    expect(store.deseados).toBeInstanceOf(Set)
    expect([...store.deseados].sort()).toEqual([...DESEADOS].sort())
    expect(store.esDeseada(cartaFixture.uuid)).toBe(true)
    expect(store.esDeseada('00000000-0000-0000-0000-000000000000')).toBe(false)
  })

  it('EL HITO (a): 60 corazones montándose a la vez son UNA petición, no 60', async () => {
    apiCall.mockResolvedValue(fixtura(deseadosFixture))

    // Es lo que hacen de verdad las tarjetas de una página de catálogo: se
    // montan todas en el mismo tick y todas llaman.
    await Promise.all(Array.from({ length: 60 }, () => store.cargarDeseados()))

    expect(apiCall).toHaveBeenCalledTimes(1)
    expect(store.esDeseada(cartaFixture.uuid)).toBe(true)
    expect(store.cargandoDeseados).toBe(false)
  })

  it('una vez cargado no se vuelve a pedir, y `forzar` es la única forma', async () => {
    apiCall.mockResolvedValue(fixtura(deseadosFixture))

    await store.cargarDeseados()
    await store.cargarDeseados()

    expect(apiCall).toHaveBeenCalledTimes(1)

    await store.cargarDeseados({ forzar: true })

    expect(apiCall).toHaveBeenCalledTimes(2)
  })

  it('un fallo deja el corazón en contorno y NO pone un aviso encima del catálogo', async () => {
    apiCall.mockResolvedValue({ status: 'error', message: 'Authentication required', http_code: 401 })

    expect(await store.cargarDeseados()).toBe(false)

    expect(store.deseados.size).toBe(0)
    // Ni aviso —el usuario no ha pulsado nada— ni marca de cargado, para que
    // la siguiente vista pueda volver a intentarlo.
    expect(store.aviso).toBeNull()
    expect(store.deseadosCargados).toBe(false)
  })

  it('EL HITO (b): `desear()` rellena el corazón YA, sin esperar a ninguna otra petición', async () => {
    apiCall.mockResolvedValue(fixtura(deseadosFixture))
    await store.cargarDeseados()

    // Una carta de la rejilla del catálogo que NO estaba deseada
    // (`catalog_cards.json`, Stomping Ground): su corazón sale en contorno.
    const nueva = '00cf70ec-98f3-5e3e-928a-a7866a1d0c54'

    expect(DESEADOS).not.toContain(nueva)
    expect(store.esDeseada(nueva)).toBe(false)

    apiCall.mockClear()
    apiCall.mockResolvedValue(fixtura(deseoFixture))

    await store.desear(nueva)

    expect(store.esDeseada(nueva)).toBe(true)
    // Y sin un `collection_wished_uuids` de refresco de por medio: si hubiera
    // que esperarlo, el botón mentiría durante un segundo y el usuario
    // pulsaría otra vez, que es el deseo doble que el relleno evita.
    expect(apiCall.mock.calls.map(([accion]) => accion)).toEqual(['collection_add'])
  })

  it('si el `desear()` falla, el corazón NO se rellena', async () => {
    apiCall.mockResolvedValue({ status: 'error', message: 'No se pudo.', http_code: 500 })

    await store.desear('a4649be8-4284-5371-831f-2e3ee32ec988')

    expect(store.deseados.size).toBe(0)
  })

  it('`desearLote()` rellena el corazón de todas las cartas del lote', async () => {
    const lineas = [
      { printingUuid: 'uuid-1', finish: 'foil', language: 'English', condition: 'NM', quantity: 2 },
      { printingUuid: 'uuid-2', finish: 'normal', language: 'Spanish', condition: 'EX', quantity: 1 }
    ]

    apiCall.mockResolvedValue(fixtura(loteFixture))

    await store.desearLote(lineas)

    expect(store.esDeseada('uuid-1')).toBe(true)
    expect(store.esDeseada('uuid-2')).toBe(true)
  })

  it('cumplir del TODO vacía el corazón; cumplir una PARTE no, porque sigues queriéndola', async () => {
    store.items = deseosDeFixtura(0, 4)
    store.deseados = new Set(store.items.map((i) => i.printingUuid))

    // Parcial: `origen` vuelve con 3 ejemplares, así que el deseo sigue vivo.
    const parcial = store.items.find((i) => i.quantity === 4)

    apiCall.mockResolvedValue(fixtura(cumplirFixture))
    await store.cumplir(parcial, { cantidad: 1 })

    expect(store.esDeseada(parcial.printingUuid)).toBe(true)

    // Total: `origen: null`, el backend borró la línea y ya la tienes.
    const total = store.items[0]

    apiCall.mockResolvedValue(fixtura(cumplirTotalFixture))
    await store.cumplir(total)

    expect(store.esDeseada(total.printingUuid)).toBe(false)
  })

  it('quitar un deseo vacía su corazón, salvo que quede otra línea de la misma carta', async () => {
    const [uno, dos] = deseosDeFixtura(0, 2)

    // Dos líneas distintas de la MISMA impresión: otro acabado, otro id.
    const gemela = { ...uno, id: uno.id + 10000, finish: 'foil' }

    store.items = [uno, gemela, dos]
    store.deseados = new Set([uno.printingUuid, dos.printingUuid])

    apiCall.mockResolvedValue({ status: 'success', message: 'Carta eliminada.', data: null, http_code: 200 })

    await store.quitar(uno)

    // Queda la gemela: el corazón habla de la carta, no de la línea.
    expect(store.esDeseada(uno.printingUuid)).toBe(true)

    await store.quitar(gemela)

    expect(store.esDeseada(uno.printingUuid)).toBe(false)
    expect(store.esDeseada(dos.printingUuid)).toBe(true)
  })

  it('el `Set` es de ESTE store: el de colección no sabe nada de deseos', async () => {
    const coleccion = useCollectionStore()

    apiCall.mockResolvedValue(fixtura(deseadosFixture))
    await store.cargarDeseados()

    expect(store.$id).toBe('wishlist')
    expect(coleccion.esDeseada).toBeUndefined()
    expect(coleccion.deseados).toBeUndefined()
    expect(coleccion.items).toEqual([])
  })
})
