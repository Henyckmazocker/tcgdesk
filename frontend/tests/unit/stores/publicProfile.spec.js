import { beforeEach, describe, expect, it, vi } from 'vitest'
import { createPinia, setActivePinia } from 'pinia'

import { SECCIONES, usePublicProfileStore } from '@/stores/publicProfile'
import { publicGet } from '@/services/api'

import perfilFixture from '../../fixtures/public_profile.json'
import perfilTodoFixture from '../../fixtures/public_profile_todo.json'
import coleccionFixture from '../../fixtures/public_collection.json'
import coleccionValorFixture from '../../fixtures/public_collection_valor.json'
import mazosFixture from '../../fixtures/public_decks.json'
import setsFixture from '../../fixtures/public_sets.json'
import mazoFixture from '../../fixtures/public_deck.json'
import noVisibleFixture from '../../fixtures/public_not_visible.json'
import sinUsuarioFixture from '../../fixtures/public_user_not_found.json'
import sinMazoFixture from '../../fixtures/public_deck_not_found.json'

/**
 * `stores/publicProfile.js` — lo que se ve de otra persona.
 *
 * Lo que este store decide, y es lo que se mira aquí:
 *
 *  1. **Quién manda es el mapa `visible` del backend.** Una sección que no está
 *     a `true` ni siquiera se pide: además de ser fail-closed, ahorra
 *     peticiones en unas rutas limitadas a 60/min por IP.
 *  2. **`not_visible` NO es un error.** Es una respuesta, y acaba en
 *     `privada: true` con `error: null`. Confundirlos es exactamente lo que
 *     rompe el estado vacío honesto: la vista diría «no se pudo cargar» de algo
 *     que cargó perfectamente y dijo que no.
 *  3. **404 no es «se rompió».** `user_not_found` y `deck_not_found` tienen su
 *     propio estado (`'no_existe'`) porque la vista dice cosas distintas.
 *  4. **Una página siguiente que falla no borra lo ya cargado.** Castigar al
 *     visitante con la pantalla en blanco por un 429 sería el peor final para
 *     un enlace que alguien acaba de abrir.
 *
 * Las fixturas son capturas reales de las seis rutas públicas; `public_profile`
 * es el perfil por defecto (`value` y `wishlist` en `friends`, o sea invisibles
 * para un anónimo) y `public_profile_todo` el mismo con las cinco a `everyone`.
 */

/**
 * La frontera de mock del plan de tests. Este store solo usa `publicGet` —las
 * rutas públicas van sin cookie—, pero el doble declara las tres caras porque
 * `vi.mock` sustituye el módulo entero.
 */
vi.mock('@/services/api', () => ({
  publicGet: vi.fn(),
  catalogGet: vi.fn(),
  apiCall: vi.fn()
}))

/** Copia profunda: los stores mutan lo que reciben y las fixturas son de todos. */
function fixtura(json) {
  return structuredClone(json)
}

/**
 * Responde a cada ruta pública con su fixtura, mirando la ruta que se pide.
 *
 * Se enruta por sufijo y no por orden de llamada porque `cargarPerfil` lanza las
 * cuatro secciones **a la vez** con un `Promise.all`: con `mockResolvedValueOnce`
 * encadenados, el orden de resolución decidiría qué fixtura se lleva cada
 * sección y el test pasaría o fallaría por azar.
 */
function responderPorRuta(mapa) {
  publicGet.mockImplementation((path) => {
    for (const [sufijo, respuesta] of Object.entries(mapa)) {
      if (path.endsWith(sufijo)) {
        return Promise.resolve(fixtura(respuesta))
      }
    }

    return Promise.resolve(fixtura(noVisibleFixture))
  })
}

/** El backend por defecto: perfil real, y cada sección con lo suyo. */
function backendPorDefecto(perfil = perfilFixture) {
  responderPorRuta({
    '/collection': coleccionFixture,
    '/decks': mazosFixture,
    '/sets': setsFixture,
    '/wishlist': noVisibleFixture,
    [`/user/${perfil.user.username}`]: perfil
  })
}

let store

beforeEach(() => {
  // Pinia REAL y no `createTestingPinia`: lo que se prueba son las acciones, y
  // el `stubActions: true` por defecto de aquella no las ejecutaría.
  setActivePinia(createPinia())
  store = usePublicProfileStore()

  publicGet.mockReset()
})

describe('el estado inicial', () => {
  it('arranca sin perfil, sin mazo y con las cuatro secciones vacías', () => {
    expect(store.hayPerfil).toBe(false)
    expect(store.valorVisible).toBe(false)
    expect(store.mazo).toBeNull()

    for (const { clave } of SECCIONES) {
      expect(store.secciones[clave].items).toEqual([])
      expect(store.secciones[clave].privada).toBe(false)
    }
  })

  it('sin perfil cargado, las cuatro secciones son invisibles (fail-closed también aquí)', () => {
    expect(store.seccionesDelPerfil.map((s) => s.visible)).toEqual([false, false, false, false])
  })
})

describe('cargarPerfil', () => {
  it('guarda el usuario y el mapa de visibilidad tal cual los manda el backend', async () => {
    backendPorDefecto()

    await store.cargarPerfil('fixturas')

    expect(store.usuario).toEqual(fixtura(perfilFixture).user)
    expect(store.visible).toEqual(fixtura(perfilFixture).visible)
    expect(store.hayPerfil).toBe(true)
  })

  it('el perfil NO trae email, y si algún día lo trajera este test lo canta', async () => {
    backendPorDefecto()

    await store.cargarPerfil('fixturas')

    expect(JSON.stringify(store.usuario)).not.toMatch(/email/i)
    expect(store.usuario).toEqual({
      username: expect.any(String),
      displayName: expect.any(String),
      avatarUrl: expect.any(String)
    })
  })

  it('pide SOLO las secciones que el backend marca visibles', async () => {
    backendPorDefecto()

    await store.cargarPerfil('fixturas')

    const rutas = publicGet.mock.calls.map(([path]) => path)

    // `value` y `wishlist` nacen en `friends`, así que para un anónimo la lista
    // de deseos ni se intenta: se pide el perfil y las tres secciones abiertas.
    expect(rutas).toHaveLength(4)
    expect(rutas.some((r) => r.endsWith('/wishlist'))).toBe(false)
    expect(rutas.some((r) => r.endsWith('/collection'))).toBe(true)
    expect(rutas.some((r) => r.endsWith('/decks'))).toBe(true)
    expect(rutas.some((r) => r.endsWith('/sets'))).toBe(true)
  })

  it('deja las secciones cargadas con la lista que cada ruta devuelve', async () => {
    backendPorDefecto()

    await store.cargarPerfil('fixturas')

    expect(store.secciones.collection.items).toHaveLength(fixtura(coleccionFixture).items.length)
    expect(store.secciones.decks.items).toHaveLength(fixtura(mazosFixture).decks.length)
    expect(store.secciones.sets.items).toHaveLength(fixtura(setsFixture).sets.length)
    expect(store.secciones.collection.nextCursor).toBe(fixtura(coleccionFixture).nextCursor)
  })

  it('la sección invisible se queda vacía pero NO marcada como privada: no se ha preguntado', async () => {
    backendPorDefecto()

    await store.cargarPerfil('fixturas')

    // Quien dice que es privada es el getter, cruzando con `visible`. La marca
    // `privada` del estado es solo para el 403 que llega de la propia sección.
    expect(store.secciones.wishlist.items).toEqual([])
    expect(store.seccionesDelPerfil.find((s) => s.clave === 'wishlist').visible).toBe(false)
  })

  it('escapa el username en la URL: un nombre con barra no inventa una ruta', async () => {
    backendPorDefecto()
    publicGet.mockResolvedValue(fixtura(sinUsuarioFixture))

    await store.cargarPerfil('a/b')

    expect(publicGet).toHaveBeenCalledWith('/user/a%2Fb')
  })

  it('un username que no existe es «no_existe», no un fallo de carga', async () => {
    publicGet.mockResolvedValue(fixtura(sinUsuarioFixture))

    await store.cargarPerfil('nadie')

    expect(store.errorPerfil).toBe('no_existe')
    expect(store.hayPerfil).toBe(false)
    // Y no se pide ninguna sección de un usuario que no existe.
    expect(publicGet).toHaveBeenCalledTimes(1)
  })

  it('el 429 del límite por IP se traduce a algo que no invita a recargar', async () => {
    publicGet.mockResolvedValue({ error: 'rate_limited' })

    await store.cargarPerfil('fixturas')

    expect(store.errorPerfil).toMatch(/espera un minuto/i)
    expect(store.errorPerfil).not.toBe('no_existe')
  })

  it('sin servidor detrás, lo dice y no se queda cargando para siempre', async () => {
    publicGet.mockResolvedValue({ error: 'network_error' })

    await store.cargarPerfil('fixturas')

    expect(store.errorPerfil).toBe('No se pudo contactar con el servidor.')
    expect(store.cargandoPerfil).toBe(false)
  })

  it('cambiar de perfil no deja ni una carta del anterior en pantalla', async () => {
    backendPorDefecto()
    await store.cargarPerfil('fixturas')
    expect(store.secciones.collection.items.length).toBeGreaterThan(0)

    publicGet.mockReset()
    publicGet.mockResolvedValue(fixtura(sinUsuarioFixture))
    await store.cargarPerfil('otro')

    expect(store.usuario).toBeNull()
    expect(store.secciones.collection.items).toEqual([])
  })
})

describe('el dinero, que es la excepción del modelo', () => {
  it('con `show_value` invisible no hay bloque de valor ni precios en las cartas', async () => {
    backendPorDefecto()

    await store.cargarPerfil('fixturas')

    expect(store.valorVisible).toBe(false)
    expect(store.valor).toBeNull()
    // Es lista blanca del backend, no un filtro: la clave no está siquiera.
    expect(store.secciones.collection.items[0]).not.toHaveProperty('priceEur')
  })

  it('con `show_value` a everyone llegan el resumen en euros y el precio por línea', async () => {
    responderPorRuta({
      '/collection': coleccionValorFixture,
      '/decks': mazosFixture,
      '/sets': setsFixture,
      '/wishlist': coleccionValorFixture,
      '/user/fixturas': perfilTodoFixture
    })

    await store.cargarPerfil('fixturas')

    expect(store.valorVisible).toBe(true)
    expect(store.valor.valueEur).toBe(fixtura(perfilTodoFixture).value.valueEur)
    expect(store.secciones.collection.items[0]).toHaveProperty('priceEur')
  })
})

describe('cargarSeccion', () => {
  beforeEach(() => {
    store.username = 'fixturas'
  })

  it('un 403 `not_visible` marca la sección privada y NO la marca con error', async () => {
    publicGet.mockResolvedValue(fixtura(noVisibleFixture))

    await store.cargarSeccion('wishlist')

    expect(store.secciones.wishlist.privada).toBe(true)
    expect(store.secciones.wishlist.error).toBeNull()
    expect(store.secciones.wishlist.items).toEqual([])
  })

  it('cualquier otro fallo SÍ es error, y entonces no es privada', async () => {
    publicGet.mockResolvedValue({ error: 'rate_limited' })

    await store.cargarSeccion('collection')

    expect(store.secciones.collection.privada).toBe(false)
    expect(store.secciones.collection.error).toMatch(/espera un minuto/i)
  })

  it('una clave que no es una sección no llama a la red', async () => {
    await store.cargarSeccion('email')

    expect(publicGet).not.toHaveBeenCalled()
  })

  it('no se solapa consigo misma', async () => {
    publicGet.mockResolvedValue(fixtura(mazosFixture))
    store.secciones.decks.cargando = true

    await store.cargarSeccion('decks')

    expect(publicGet).not.toHaveBeenCalled()
  })
})

describe('cargarMas', () => {
  beforeEach(async () => {
    backendPorDefecto()
    await store.cargarPerfil('fixturas')
    publicGet.mockReset()
  })

  it('ACUMULA la página siguiente en vez de reemplazarla', async () => {
    const primeras = store.secciones.collection.items.length
    const siguientes = fixtura(coleccionFixture).items.slice(0, 5)

    publicGet.mockResolvedValue({ items: siguientes, nextCursor: null })

    await store.cargarMas('collection')

    expect(store.secciones.collection.items).toHaveLength(primeras + 5)
    expect(store.secciones.collection.nextCursor).toBeNull()
  })

  it('manda el cursor que devolvió la página anterior', async () => {
    publicGet.mockResolvedValue({ items: [], nextCursor: null })

    await store.cargarMas('collection')

    expect(publicGet).toHaveBeenCalledWith('/user/fixturas/collection', {
      cursor: fixtura(coleccionFixture).nextCursor
    })
  })

  it('sin cursor no hay página siguiente que pedir', async () => {
    store.secciones.collection.nextCursor = null

    await store.cargarMas('collection')

    expect(publicGet).not.toHaveBeenCalled()
  })

  it('las secciones que no se paginan lo ignoran', async () => {
    store.secciones.decks.nextCursor = 'loquesea'

    await store.cargarMas('decks')

    expect(publicGet).not.toHaveBeenCalled()
  })

  it('un fallo en la página siguiente NO borra lo que ya se veía', async () => {
    const primeras = store.secciones.collection.items.length

    publicGet.mockResolvedValue({ error: 'rate_limited' })

    await store.cargarMas('collection')

    expect(store.secciones.collection.items).toHaveLength(primeras)
    expect(store.secciones.collection.error).toMatch(/espera un minuto/i)
    expect(store.secciones.collection.cargandoMas).toBe(false)
  })

  it('si el dueño cierra la sección entre dos páginas, pasa a privada', async () => {
    publicGet.mockResolvedValue(fixtura(noVisibleFixture))

    await store.cargarMas('collection')

    expect(store.secciones.collection.privada).toBe(true)
    expect(store.secciones.collection.error).toBeNull()
  })
})

describe('cargarMazoCompartido', () => {
  it('guarda el sobre entero del mazo', async () => {
    publicGet.mockResolvedValue(fixtura(mazoFixture))

    await store.cargarMazoCompartido('a7d2')

    expect(publicGet).toHaveBeenCalledWith('/deck/a7d2')
    expect(store.mazo.deck.name).toBe(fixtura(mazoFixture).deck.name)
    expect(store.mazo.cards).toHaveLength(fixtura(mazoFixture).cards.length)
    expect(store.errorMazo).toBeNull()
  })

  it('el mazo compartido NO trae ni un campo del cruce con la colección', async () => {
    publicGet.mockResolvedValue(fixtura(mazoFixture))

    await store.cargarMazoCompartido('a7d2')

    // El backend lo compone por lista blanca; si alguna vez se le colara, es
    // la colección de alguien saliendo por la puerta de al lado.
    const crudo = JSON.stringify(store.mazo)

    for (const prohibido of [
      'missingCount', 'missing', 'conflicts', 'availability',
      'overallocated', 'inCollection', 'claimed', 'notes', 'email'
    ]) {
      expect(crudo).not.toContain(prohibido)
    }
  })

  it('un token inválido, revocado o inventado son el MISMO «no_existe»', async () => {
    publicGet.mockResolvedValue(fixtura(sinMazoFixture))

    await store.cargarMazoCompartido('deadbeef')

    expect(store.errorMazo).toBe('no_existe')
    expect(store.mazo).toBeNull()
  })

  it('un fallo de red no se confunde con un enlace muerto', async () => {
    publicGet.mockResolvedValue({ error: 'network_error' })

    await store.cargarMazoCompartido('a7d2')

    expect(store.errorMazo).toBe('No se pudo contactar con el servidor.')
    expect(store.errorMazo).not.toBe('no_existe')
  })

  it('cargar otro mazo borra el anterior antes de pedirlo', async () => {
    publicGet.mockResolvedValue(fixtura(mazoFixture))
    await store.cargarMazoCompartido('a7d2')

    publicGet.mockResolvedValue(fixtura(sinMazoFixture))
    await store.cargarMazoCompartido('otro')

    expect(store.mazo).toBeNull()
  })
})

describe('limpiar', () => {
  it('deja el store como recién creado', async () => {
    backendPorDefecto()
    await store.cargarPerfil('fixturas')

    store.limpiar()

    expect(store.username).toBeNull()
    expect(store.usuario).toBeNull()
    expect(store.visible).toEqual({})
    expect(store.valor).toBeNull()
    expect(store.errorPerfil).toBeNull()
    expect(store.mazo).toBeNull()
    expect(store.errorMazo).toBeNull()

    for (const { clave } of SECCIONES) {
      expect(store.secciones[clave]).toEqual({
        items: [],
        nextCursor: null,
        cargando: false,
        cargandoMas: false,
        privada: false,
        error: null
      })
    }
  })
})
