import { beforeEach, describe, expect, it, vi } from 'vitest'
import { createPinia, setActivePinia } from 'pinia'

import { usePreconStore } from '@/stores/precons'
import { apiCall, catalogGet } from '@/services/api'

import decksFixture from '../../fixtures/catalog_decks.json'
import deckFixture from '../../fixtures/catalog_deck.json'
import importFixture from '../../fixtures/precon_add_to_collection.json'
import deseosFixture from '../../fixtures/precon_add_to_collection_deseos.json'

/**
 * `stores/precons.js` — el catálogo de los 3.029 mazos preconstruidos.
 *
 * Hermano de `stores/catalog.js` y con sus mismas dos reglas —cursor que
 * **acumula** y contador que descarta respuestas viejas—, más la suya propia:
 *
 * **El filtro de tipo es honesto.** De los 48 tipos, cinco no son mazos
 * (1.048 cajas de 3.029), así que por defecto se pide `playable=1` y solo el
 * conmutador «ver todo» lo quita (`precons.js:89-98,109`). **Quién es jugable lo
 * dice el backend**: los tests leen `playable` de la fixtura capturada y no hay
 * ni una cadena de tipo escrita a mano, por lo mismo que el store no la tiene —
 * MTGJSON añadirá tipos y una lista copiada se desincronizaría sola.
 *
 * Y la cuarta, que es dónde termina la divergencia `GET` del `CLAUDE.md`:
 * **leer precons va por `catalogGet` y meterlos en la colección por `apiCall`**,
 * porque lo segundo escribe dato de usuario y necesita sesión y token CSRF.
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
 * Un tramo de la fixtura capturada. `facetas` a false imita una página
 * siguiente, que es como responde el backend: `deckTypes` y `sets` solo viajan
 * en la primera.
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

let store

beforeEach(() => {
  // Pinia REAL: `createTestingPinia` no ejecuta las acciones con su
  // `stubActions` por defecto, y aquí lo que se prueba son ellas.
  setActivePinia(createPinia())
  store = usePreconStore()

  catalogGet.mockReset()
  apiCall.mockReset()
})

describe('getters', () => {
  it('arranca vacío y sin más páginas', () => {
    expect(store.hayMas).toBe(false)
    expect(store.vacio).toBe(true)
  })

  it('vacio es false mientras carga (la vista pinta esqueletos)', () => {
    store.cargando = true

    expect(store.vacio).toBe(false)
  })

  it('filtrosActivos cuenta q, type y set — y NO el conmutador de ver todo', () => {
    store.filtros.q = 'rohan'
    store.filtros.todo = true

    // «Ver todo» no es un filtro que recorte: es el que deja de recortar. Si
    // contara, el botón diría «1 filtro» justo cuando no hay ninguno puesto.
    expect(store.filtrosActivos).toBe(1)
  })

  it('tiposJugables y tiposNoJugables salen de la marca del BACKEND', () => {
    store.tipos = fixtura(decksFixture).deckTypes

    const jugables = store.tiposJugables
    const noJugables = store.tiposNoJugables

    expect(jugables).toHaveLength(43)
    expect(noJugables).toHaveLength(5)
    expect(jugables.length + noJugables.length).toBe(store.tipos.length)

    // La marca es la del backend, tipo a tipo: aquí no se reconoce ninguna
    // cadena («Secret Lair Drop» y compañía) porque MTGJSON añadirá tipos.
    expect(jugables.every((t) => t.playable)).toBe(true)
    expect(noJugables.every((t) => !t.playable)).toBe(true)
  })

  it('ocultosPorDefecto suma las cajas que esconde el defecto honesto', () => {
    store.tipos = fixtura(decksFixture).deckTypes

    // 1.048 de 3.029: es el número que la vista dice en voz alta para que el
    // usuario sepa que le están escondiendo algo y pueda pedirlo.
    expect(store.ocultosPorDefecto).toBe(1048)
  })

  it('ocultosPorDefecto es 0 antes de que lleguen las facetas', () => {
    expect(store.ocultosPorDefecto).toBe(0)
    expect(store.tiposJugables).toEqual([])
  })
})

describe('parametros()', () => {
  it('pide playable=1 por defecto: lo que se lista son mazos, no productos', () => {
    expect(store.parametros()).toEqual({ q: '', type: '', set: '', playable: '1', limit: 60 })
  })

  it('con «ver todo» puesto deja de recortar', () => {
    store.filtros.todo = true

    expect(store.parametros().playable).toBe('')
  })
})

describe('buscar()', () => {
  it('trae la primera página con sus facetas', async () => {
    catalogGet.mockResolvedValue(fixtura(decksFixture))

    store.filtros.q = 'rohan'

    await store.buscar()

    expect(catalogGet).toHaveBeenCalledWith('/decks', {
      q: 'rohan',
      type: '',
      set: '',
      playable: '1',
      limit: 60
    })

    expect(store.items).toHaveLength(60)
    expect(store.tipos).toHaveLength(48)
    // 295 ediciones con precon, no las 868 del catálogo: un desplegable donde
    // 573 opciones dan cero resultados miente.
    expect(store.ediciones).toHaveLength(295)
    expect(store.cargando).toBe(false)
  })

  it('con un error de red vacía la lista y avisa', async () => {
    catalogGet.mockResolvedValue({ error: 'network_error' })

    store.items = pagina(0, 3).items

    await store.buscar()

    expect(store.error).toMatch(/precons/i)
    expect(store.items).toEqual([])
    expect(store.nextCursor).toBeNull()
  })

  it('si una respuesta no trae facetas, CONSERVA las que ya había', async () => {
    catalogGet.mockResolvedValueOnce(fixtura(decksFixture))
    await store.buscar()

    catalogGet.mockResolvedValueOnce(pagina(0, 5, null, false))
    await store.buscar()

    // Vaciar los desplegables porque una respuesta no las repitió dejaría la
    // vista sin filtros justo después de filtrar (`precons.js:141-149`).
    expect(store.tipos).toHaveLength(48)
    expect(store.ediciones).toHaveLength(295)
  })

  it('la respuesta LENTA de una búsqueda vieja no pisa a la reciente', async () => {
    let resolverLenta

    catalogGet.mockImplementationOnce(
      () => new Promise((resolver) => { resolverLenta = resolver })
    )
    catalogGet.mockResolvedValueOnce(pagina(30, 40, 'cursor-reciente', false))

    const lenta = store.buscar()
    const reciente = store.buscar()

    await reciente

    expect(store.items).toHaveLength(10)

    resolverLenta(pagina(0, 3, 'cursor-viejo', false))
    await lenta

    expect(store.items).toHaveLength(10)
    expect(store.nextCursor).toBe('cursor-reciente')
  })
})

describe('cargarMas()', () => {
  it('ACUMULA la página siguiente y NO pierde el playable por el camino', async () => {
    catalogGet.mockResolvedValueOnce(pagina(0, 10, 'cursor-pagina-2'))
    await store.buscar()

    catalogGet.mockResolvedValueOnce(pagina(10, 20, 'cursor-pagina-3', false))
    await store.cargarMas()

    expect(store.items).toHaveLength(20)
    expect(store.items[0].fileName).toBe(fixtura(decksFixture).items[0].fileName)
    expect(store.nextCursor).toBe('cursor-pagina-3')

    // Si `playable` se perdiera al pasar el cursor, el segundo tirón del scroll
    // metería los 739 Secret Lair Drop en medio de la lista (`precons.js:106-109`).
    expect(catalogGet.mock.calls[1][1]).toMatchObject({
      playable: '1',
      cursor: 'cursor-pagina-2'
    })
  })

  it('sin cursor no pide nada', async () => {
    await store.cargarMas()

    expect(catalogGet).not.toHaveBeenCalled()
  })

  it('no dobla la petición si ya hay una página en vuelo', async () => {
    store.nextCursor = 'un-cursor'
    store.cargandoMas = true

    await store.cargarMas()

    expect(catalogGet).not.toHaveBeenCalled()
  })

  it('descarta la página siguiente si los filtros cambiaron mientras se pedía', async () => {
    store.nextCursor = 'un-cursor'

    let resolverPagina

    catalogGet.mockImplementationOnce(
      () => new Promise((resolver) => { resolverPagina = resolver })
    )

    const masPaginas = store.cargarMas()

    catalogGet.mockResolvedValueOnce(pagina(40, 45, 'cursor-de-la-nueva', false))
    await store.buscar()

    resolverPagina(pagina(0, 10, 'cursor-viejo', false))
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

    expect(store.items).toHaveLength(10)
    expect(store.nextCursor).toBe('cursor-pagina-2')
  })
})

describe('cargarFicha()', () => {
  it('pide el precon por su fileName, que es la clave natural', async () => {
    catalogGet.mockResolvedValue(fixtura(deckFixture))

    await store.cargarFicha('RidersOfRohan_LTC')

    // `fileName` y no `name`: hay precons homónimos en ediciones distintas.
    expect(catalogGet).toHaveBeenCalledWith('/decks/RidersOfRohan_LTC')
    expect(store.ficha.precon.name).toBe(fixtura(deckFixture).precon.name)
    expect(store.ficha.cards).toHaveLength(fixtura(deckFixture).cards.length)
    expect(store.cargandoFicha).toBe(false)
    expect(store.errorFicha).toBeNull()
  })

  it('escapa el fileName antes de meterlo en la ruta', async () => {
    catalogGet.mockResolvedValue(fixtura(deckFixture))

    await store.cargarFicha("Aragorn at Helm's Deep_LTC")

    expect(catalogGet).toHaveBeenCalledWith('/decks/Aragorn%20at%20Helm\'s%20Deep_LTC')
  })

  it('sin fileName no llama al backend', async () => {
    await store.cargarFicha('')

    expect(catalogGet).not.toHaveBeenCalled()
    expect(store.errorFicha).toMatch(/identificador/i)
  })

  it('distingue «ese precon no existe» de «no se pudo cargar»', async () => {
    // El 404 del catálogo trae `{"error":"precon_not_found"}` y `api.js` lo
    // DEVUELVE como cuerpo en vez de lanzar: por eso aquí se mira el campo.
    catalogGet.mockResolvedValueOnce({ error: 'precon_not_found' })
    await store.cargarFicha('NoExiste_XXX')

    expect(store.errorFicha).toMatch(/no existe/i)
    expect(store.ficha).toBeNull()

    catalogGet.mockResolvedValueOnce({ error: 'network_error' })
    await store.cargarFicha('RidersOfRohan_LTC')

    expect(store.errorFicha).toMatch(/no se pudo/i)
  })

  it('limpiarFicha deja la vista sin nada heredado de la anterior', async () => {
    catalogGet.mockResolvedValue(fixtura(deckFixture))
    await store.cargarFicha('RidersOfRohan_LTC')

    store.resultadoImport = { inserted: 1 }
    store.errorImport = 'algo'

    store.limpiarFicha()

    expect(store.ficha).toBeNull()
    expect(store.errorFicha).toBeNull()
    expect(store.resultadoImport).toBeNull()
    expect(store.errorImport).toBeNull()
  })
})

describe('importar() — la escritura, que NO va por catalogGet', () => {
  it('mete la caja por el endpoint único y guarda lo que se dio por supuesto', async () => {
    apiCall.mockResolvedValue(fixtura(importFixture))

    const ok = await store.importar('RidersOfRohan_LTC')

    expect(ok).toBe(true)
    // Por `apiCall` y no por `catalogGet`: esto escribe dato de usuario y
    // necesita la sesión y el token CSRF, que `catalogGet` no lleva a propósito.
    expect(apiCall).toHaveBeenCalledWith('precon_add_to_collection', {
      file_name: 'RidersOfRohan_LTC'
    })
    expect(catalogGet).not.toHaveBeenCalled()

    expect(store.resultadoImport.totalQuantity).toBe(100)
    expect(store.resultadoImport.deck.name).toBe('Riders of Rohan')
    // El mensaje se guarda con el resto porque dice lo que se ha supuesto
    // (English y NM): un toast de 3,5 segundos no basta para contarlo.
    expect(store.resultadoImport.mensaje).toMatch(/English y NM/)
    expect(store.resultadoImport.assumed).toEqual({ language: 'English', condition: 'NM' })
    expect(store.importando).toBeNull()
  })

  it('manda deck_status solo cuando se pide', async () => {
    apiCall.mockResolvedValue(fixtura(importFixture))

    await store.importar('RidersOfRohan_LTC', 'building')

    expect(apiCall).toHaveBeenCalledWith('precon_add_to_collection', {
      file_name: 'RidersOfRohan_LTC',
      deck_status: 'building'
    })
  })

  it('el segundo clic impaciente no crea un segundo mazo', async () => {
    let resolverImport

    apiCall.mockImplementationOnce(
      () => new Promise((resolver) => { resolverImport = resolver })
    )

    const primero = store.importar('RidersOfRohan_LTC')

    expect(store.importando).toBe('RidersOfRohan_LTC')

    // Son 100 cartas y dos tablas en una transacción: sin el bloqueo, el
    // segundo clic crearía otro mazo sin que nadie lo pidiera.
    await expect(store.importar('RidersOfRohan_LTC')).resolves.toBe(false)
    expect(apiCall).toHaveBeenCalledTimes(1)

    resolverImport(fixtura(importFixture))
    await primero

    expect(store.importando).toBeNull()
  })

  it('sin fileName no llama al backend', async () => {
    await expect(store.importar('')).resolves.toBe(false)

    expect(apiCall).not.toHaveBeenCalled()
  })

  it('con un 401 pide iniciar sesión en vez de repetir el mensaje del backend', async () => {
    apiCall.mockResolvedValue({
      status: 'error',
      message: 'No autenticado.',
      data: null,
      http_code: 401
    })

    await expect(store.importar('RidersOfRohan_LTC')).resolves.toBe(false)

    expect(store.errorImport).toMatch(/inicia sesión/i)
    expect(store.resultadoImport).toBeNull()
    expect(store.importando).toBeNull()
  })

  it('con otro error enseña lo que dijo el backend', async () => {
    apiCall.mockResolvedValue({
      status: 'error',
      message: 'Ese precon no existe.',
      data: null,
      http_code: 404
    })

    await store.importar('NoExiste_XXX')

    expect(store.errorImport).toBe('Ese precon no existe.')
  })

  it('sin mensaje del backend pone uno propio', async () => {
    apiCall.mockResolvedValue({ status: 'error', http_code: 500 })

    await store.importar('RidersOfRohan_LTC')

    expect(store.errorImport).toMatch(/no se pudo importar/i)
  })
})

/**
 * M7 — la quinta superficie de la lista de deseos.
 *
 * Lo que hay que probarle al store es que **no inventa una operación nueva**: es
 * la misma acción del botón de al lado con la bandera del lote puesta, igual que
 * `import_apply` lleva `is_wishlist` en vez de tener un gemelo.
 */
describe('desearCaja() — la caja entera a la lista de deseos', () => {
  it('es la MISMA acción con is_wishlist, y el mazo lo decide el backend', async () => {
    apiCall.mockResolvedValue(fixtura(deseosFixture))

    const ok = await store.desearCaja('RidersOfRohan_LTC')

    expect(ok).toBe(true)
    expect(apiCall).toHaveBeenCalledTimes(1)
    expect(apiCall).toHaveBeenCalledWith('precon_add_to_collection', {
      file_name: 'RidersOfRohan_LTC',
      is_wishlist: true
    })

    // **`deck_status` NO viaja.** Que una caja deseada nazca `building` y no
    // `built` es un invariante del dato —`built` consume colección— y vive en
    // el backend: mandarlo desde aquí sería poder equivocarse desde aquí.
    expect(apiCall.mock.calls[0][1]).not.toHaveProperty('deck_status')

    // Y se comprueba contra la fixtura capturada, que es quien lo demuestra.
    expect(store.resultadoImport.isWishlist).toBe(true)
    expect(store.resultadoImport.deck.status).toBe('building')
    expect(store.resultadoImport.deck.cards).toBe(100)
  })

  it('el guardia del doble clic es UNO para los dos botones', async () => {
    let resolver

    apiCall.mockImplementationOnce(() => new Promise((r) => { resolver = r }))

    const compra = store.importar('RidersOfRohan_LTC')

    expect(store.importando).toBe('RidersOfRohan_LTC')
    expect(store.destinoEnVuelo).toBe('coleccion')

    // Querer la caja mientras la compra está en vuelo la dejaría en los dos
    // conjuntos sin que nadie lo hubiera pedido.
    await expect(store.desearCaja('RidersOfRohan_LTC')).resolves.toBe(false)
    expect(apiCall).toHaveBeenCalledTimes(1)

    resolver(fixtura(importFixture))
    await compra

    expect(store.importando).toBeNull()
  })

  it('con un 401 dice que es para la lista de deseos, no para la colección', async () => {
    apiCall.mockResolvedValue({ status: 'error', message: 'No autenticado.', http_code: 401 })

    await expect(store.desearCaja('RidersOfRohan_LTC')).resolves.toBe(false)

    expect(store.errorImport).toMatch(/lista de deseos/i)
    expect(store.errorImport).not.toMatch(/colección/i)
    expect(store.resultadoImport).toBeNull()
  })

  it('uuidsImportables deja fuera las fichas y las que el catálogo no conoce', async () => {
    // Los tokens no llegan a la colección (`Board::esPoseible()`) y las
    // huérfanas no tienen fila en `mtg_printing`: ni unas ni otras se escriben,
    // así que rellenarles el corazón sería decir que están en una lista donde
    // no están.
    const ficha = fixtura(deckFixture)
    const [primera, segunda] = ficha.cards

    primera.board = 'tokens'
    segunda.known = false

    catalogGet.mockResolvedValue(ficha)
    await store.cargarFicha('RidersOfRohan_LTC')

    const uuids = store.uuidsImportables

    expect(uuids).toHaveLength(ficha.cards.length - 2)
    expect(uuids).not.toContain(primera.printingUuid)
    expect(uuids).not.toContain(segunda.printingUuid)
  })
})

describe('desdeQuery()', () => {
  it('rehidrata los filtros de la URL y normaliza a texto', () => {
    store.desdeQuery({ q: 'rohan', type: 'Commander Deck', set: 'LTC' })

    expect(store.filtros).toEqual({
      q: 'rohan',
      type: 'Commander Deck',
      set: 'LTC',
      todo: false
    })
  })

  it('all=1 enciende el «ver todo»; su ausencia deja el defecto honesto', () => {
    store.desdeQuery({ all: '1' })
    expect(store.filtros.todo).toBe(true)

    store.desdeQuery({})
    expect(store.filtros.todo).toBe(false)

    store.desdeQuery({ all: '0' })
    expect(store.filtros.todo).toBe(false)
  })
})
