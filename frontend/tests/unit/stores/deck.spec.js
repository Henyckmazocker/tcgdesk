import { beforeEach, describe, expect, it, vi } from 'vitest'
import { createPinia, setActivePinia } from 'pinia'

import { useDeckStore } from '@/stores/deck'
import { apiCall, catalogGet } from '@/services/api'

import deckListFixture from '../../fixtures/deck_list.json'
import deckGetFixture from '../../fixtures/deck_get.json'
import deckCreateFixture from '../../fixtures/deck_create.json'
import deckUpdateFixture from '../../fixtures/deck_update.json'
import deckDeleteFixture from '../../fixtures/deck_delete.json'
import cardAddFixture from '../../fixtures/deck_card_add.json'
import cardSetFixture from '../../fixtures/deck_card_set.json'
import cardRemoveFixture from '../../fixtures/deck_card_remove.json'
import cardChangeFixture from '../../fixtures/deck_card_change.json'
import variantsFixture from '../../fixtures/deck_card_variants.json'
import collectionListFixture from '../../fixtures/collection_list.json'
import cardsFixture from '../../fixtures/catalog_cards.json'
import shareFixture from '../../fixtures/deck_share.json'
import unshareFixture from '../../fixtures/deck_unshare.json'

/**
 * `stores/deck.js` — 916 líneas y el store con más lógica propia del frontend.
 *
 * **Ojo al copiar de `collection.spec.js` o al revés**: los dos stores tienen
 * getters con el MISMO nombre y semántica distinta. Aquí `estaGuardando` indexa
 * por el `id` de la línea del MAZO (`deck.js:152`) y allí por el de la línea de
 * COLECCIÓN; `hayMas` ni siquiera existe en este. Un test copiado de un store a
 * otro es la forma más fácil de escribir uno que no prueba nada.
 *
 * Lo que se cubre y por qué:
 *
 *  - **Los getters caros**, que son los que la vista llama por cada fila de una
 *    tabla de 96 líneas: `zonas`, `porEstado`, `valorConstruido`, `hayConflicto`,
 *    `disponibilidadDe`, `reclamadoPorEsteMazo` y `legalidadDe`.
 *  - **`board: 'tokens'` NO cuenta para nada** —la regla del `CLAUDE.md`—: ni
 *    suma al total del mazo, ni consume colección, ni se marca de legalidad.
 *  - **El modo «coge lo que tenga»** (`repartir`), que resuelve las tres
 *    dimensiones en el propio clic: peor estado primero y, a igualdad, la más
 *    barata.
 *  - **Las dos ediciones que mueven filas**: cantidad a 0 borra y cambiar de
 *    versión puede FUNDIR dos líneas, porque las cuatro columnas están dentro de
 *    `uq_deck_card` y el `id` que vuelve no tiene por qué ser el que se mandó.
 */

vi.mock('@/services/api', () => ({
  apiCall: vi.fn(),
  catalogGet: vi.fn()
}))

/** Copia profunda: los stores mutan lo que reciben y las fixturas son de todos. */
function fixtura(json) {
  return structuredClone(json)
}

/** Las líneas reales del mazo capturado, para sembrar sin pasar por la red. */
function cartasDeFixtura(desde, hasta) {
  return fixtura(deckGetFixture).data.cards.slice(desde, hasta)
}

/**
 * Un doble que responde según la ACCIÓN, que es como funciona el endpoint
 * único: varios caminos del store encadenan dos o tres acciones (una escritura
 * y el `refrescar()` de después) y con una cola de `mockResolvedValueOnce` el
 * test se rompería al cambiar el orden.
 */
function respondeSegunAccion(mapa) {
  apiCall.mockImplementation((accion) =>
    Promise.resolve(mapa[accion] ?? { status: 'error', message: `Sin doble para ${accion}.`, http_code: 500 })
  )
}

/**
 * Como `respondeSegunAccion`, pero dejando **en vuelo** el `deck_get` del
 * refresco de después de cada escritura.
 *
 * Es la única forma de mirar lo que la tabla hizo EN LOCAL —que es lo que hace
 * que la fila fundida desaparezca al instante, «sin recargar», que es lo que
 * pide el hito— antes de que `refrescar()` sustituya la lista entera por la del
 * backend (`deck.js:416-442`). Con el refresco resuelto al vuelo, cualquier
 * aserción sobre la lista estaría comprobando la fixtura y no el store.
 */
function refrescoEnVuelo(mapa) {
  const control = { resolver: null }

  apiCall.mockImplementation((accion) =>
    accion === 'deck_get'
      ? new Promise((resolver) => { control.resolver = resolver })
      : Promise.resolve(mapa[accion] ?? { status: 'error', message: `Sin doble para ${accion}.`, http_code: 500 })
  )

  /** Espera a que la escritura haya terminado y el refresco esté pedido. */
  control.esperar = async () => {
    await vi.waitFor(() => expect(control.resolver).not.toBeNull())
  }

  /** Deja llegar el refresco, con la ficha capturada. */
  control.completar = (respuesta = fixtura(deckGetFixture)) => control.resolver(respuesta)

  return control
}

/** Deja el store como si ya se hubiera cargado el mazo capturado. */
function sembrarFicha(store) {
  const datos = fixtura(deckGetFixture).data

  store.mazo = datos.deck
  store.cartas = datos.cards
  store.valueEur = datos.valueEur
  store.availability = datos.availability
  store.conflicts = datos.conflicts
  store.legality = datos.legality
}

let store

beforeEach(() => {
  // Pinia REAL: `createTestingPinia` no ejecuta las acciones con su
  // `stubActions` por defecto, y aquí lo que se prueba son ellas.
  setActivePinia(createPinia())
  store = useDeckStore()

  apiCall.mockReset()
  catalogGet.mockReset()
})

describe('zonas y el total — los tokens no cuentan', () => {
  it('agrupa por zona en el orden del ENUM y deja fuera las zonas vacías', () => {
    sembrarFicha(store)

    // El mazo capturado tiene `main` y `planes`: siete claves vacías serían
    // ruido que la vista tendría que filtrar igualmente (`deck.js:126-131`).
    expect(store.zonas.map((z) => z.value)).toEqual(['main', 'planes'])
    expect(store.zonas[0].cartas).toHaveLength(95)
    expect(store.zonas[1].cartas).toHaveLength(1)
  })

  it('el total del mazo coincide con el que dice el backend', () => {
    sembrarFicha(store)

    // Contraste cruzado: `deck.cards` lo cuenta el backend en SQL y
    // `totalCartas` lo recalcula aquí. Si divergieran, uno de los dos miente.
    expect(store.totalCartas).toBe(store.mazo.cards)
    expect(store.totalCartas).toBe(100)
  })

  it('una línea de tokens NO suma al total ni al tamaño del mazo', () => {
    sembrarFicha(store)

    const token = { ...cartasDeFixtura(0, 1)[0], id: 99001, board: 'tokens', count: 5 }

    store.cartas = [...store.cartas, token]

    // La regla del `CLAUDE.md`: los `board = 'tokens'` no cuentan para nada —ni
    // consumen colección, ni suman al valor, ni cuentan para el tamaño mínimo—.
    expect(store.totalCartas).toBe(100)

    // La zona sí aparece (hay que poder verlos), pero marcada con `cuenta: false`,
    // que es lo que le dice a la vista que no los sume.
    const zonaTokens = store.zonas.find((z) => z.value === 'tokens')

    expect(zonaTokens.cartas).toHaveLength(1)
    expect(zonaTokens.cuenta).toBe(false)
  })

  it('una línea de tokens NO consume colección', () => {
    const linea = cartasDeFixtura(0, 1)[0]

    store.cartas = [
      { ...linea, count: 2 },
      { ...linea, id: 99001, board: 'tokens', count: 4 }
    ]

    // `reclamadoPorEsteMazo` es lo que descuenta de lo que tienes libre: si el
    // token contara, el mazo diría que te faltan cartas que nadie usa.
    expect(
      store.reclamadoPorEsteMazo(linea.printingUuid, linea.finish, linea.language, linea.condition)
    ).toBe(2)
  })
})

describe('los getters de la ficha', () => {
  it('hayConflicto mira los conflictos, que son GLOBALES y no de este mazo', () => {
    expect(store.hayConflicto).toBe(false)

    sembrarFicha(store)

    // La fixtura capturada trae un conflicto real: dos mazos `built` peleándose
    // por el mismo `Arcane Signet`.
    expect(store.hayConflicto).toBe(true)
    expect(store.conflicts[0].decks).toHaveLength(2)
    // Las claves son las del CONTRATO (`id`/`name`/`claimed`), no los alias del
    // SQL (`deckId`/`deckName`/`reclamado`): ese fue el bug que motivó la suite.
    expect(store.conflicts[0].decks[0]).toHaveProperty('id')
    expect(store.conflicts[0].decks[0]).not.toHaveProperty('deckId')
  })

  it('disponibilidadDe casa por las CUATRO dimensiones, no solo por la carta', () => {
    sembrarFicha(store)

    const carta = store.cartas[1]
    const linea = store.disponibilidadDe(carta)

    expect(linea.printingUuid).toBe(carta.printingUuid)
    expect(linea.claimed).toBe(1)

    // La misma carta en otro acabado es otra línea de disponibilidad: buscar
    // solo por `printingUuid` daría el dato de una versión que no es esa.
    expect(store.disponibilidadDe({ ...carta, finish: 'foil' })).toBeNull()
  })

  it('reclamadoPorEsteMazo suma las líneas que coinciden en las cuatro dimensiones', () => {
    const linea = cartasDeFixtura(0, 1)[0]

    store.cartas = [
      { ...linea, id: 1, count: 2 },
      { ...linea, id: 2, count: 3 },
      { ...linea, id: 3, finish: 'foil', count: 7 }
    ]

    expect(
      store.reclamadoPorEsteMazo(linea.printingUuid, linea.finish, linea.language, linea.condition)
    ).toBe(5)
  })

  it('legalidadDe resuelve por oracle_id, porque la legalidad es de la CARTA', () => {
    sembrarFicha(store)

    const carta = store.cartas[0]
    const otraEdicion = { ...carta, id: 99002, printingUuid: 'otro-uuid-de-la-misma-carta' }

    // `statuses` va por `oracle_id` y trae el valor del enum del backend
    // (`GetDeck.php:152`). La fixtura capturada viene sin avisos —el mazo es
    // legal—, así que el caso a marcar se construye EN MEMORIA sobre ella.
    store.legality = { ...store.legality, statuses: { [carta.oracleId]: 'banned' } }

    expect(store.legalidadDe(carta)).toBe('banned')
    // Cuatro líneas de la misma carta comparten marca aunque sean ediciones
    // distintas: por eso se indexa por `oracleId` y no por `printingUuid`.
    expect(store.legalidadDe(otraEdicion)).toBe('banned')
  })

  it('legalidadDe no marca los tokens ni las cartas sin aviso', () => {
    sembrarFicha(store)

    const carta = store.cartas[0]

    store.legality = { ...store.legality, statuses: { [carta.oracleId]: 'banned' } }

    // Un token no se juega ni se posee, y el backend ya lo deja fuera del cruce.
    expect(store.legalidadDe({ ...carta, board: 'tokens' })).toBeNull()
    // Y el backend manda SOLO lo que hay que marcar: lo que no está, no se pinta.
    expect(store.legalidadDe(store.cartas[2])).toBeNull()
  })

  it('cartasConAviso suma los tres recuentos del backend', () => {
    sembrarFicha(store)

    expect(store.cartasConAviso).toBe(0)

    store.legality = { ...store.legality, banned: 1, restricted: 2, notLegal: 3 }

    expect(store.cartasConAviso).toBe(6)
  })

  it('estaGuardando indexa por el id de la LÍNEA DEL MAZO, no por la de colección', () => {
    store.guardando = [1665]

    expect(store.estaGuardando(1665)).toBe(true)
    expect(store.estaGuardando(6399)).toBe(false)
  })

  it('estaAnadiendo indexa por printing_uuid', () => {
    store.anadiendo = ['a4649be8-4284-5371-831f-2e3ee32ec988']

    expect(store.estaAnadiendo('a4649be8-4284-5371-831f-2e3ee32ec988')).toBe(true)
    expect(store.estaAnadiendo('otro')).toBe(false)
  })
})

describe('los getters de la lista', () => {
  it('porEstado devuelve SIEMPRE los tres estados, también con 0', () => {
    store.mazos = fixtura(deckListFixture).data.decks

    const porEstado = store.porEstado

    // Un hueco que aparece y desaparece se lee peor que un cero (`deck.js:216-220`).
    expect(porEstado.map((e) => e.value)).toEqual(['built', 'building', 'dismantled'])
    expect(porEstado[0].cuantos).toBe(2)
    expect(porEstado[1].cuantos).toBe(0)
    expect(porEstado[2].cuantos).toBe(0)
  })

  it('valorConstruido suma SOLO los construidos', () => {
    const mazos = fixtura(deckListFixture).data.decks

    store.mazos = mazos

    expect(store.valorConstruido).toBeCloseTo(mazos[0].valueEur + mazos[1].valueEur, 2)

    // Los tres estados no son simétricos: sumar los `building` diría que tienes
    // montado lo que todavía estás juntando, y el `dismantled` es puro archivo.
    store.mazos = [
      ...mazos,
      { ...mazos[0], id: 90, status: 'building', valueEur: 1000 },
      { ...mazos[0], id: 91, status: 'dismantled', valueEur: 2000 }
    ]

    expect(store.valorConstruido).toBeCloseTo(mazos[0].valueEur + mazos[1].valueEur, 2)
  })

  it('valorConstruido trata un valueEur ausente como 0 y no como NaN', () => {
    store.mazos = [{ id: 1, status: 'built' }]

    expect(store.valorConstruido).toBe(0)
  })
})

describe('lo que tienes en las cajas', () => {
  /** Tres versiones de la misma carta, construidas sobre una línea capturada. */
  function indiceDeTresVersiones(uuid) {
    const base = fixtura(collectionListFixture).data.items[0]

    return {
      [uuid]: [
        { finish: base.finish, language: base.language, condition: 'NM', quantity: 1, priceEur: 8.49 },
        { finish: base.finish, language: base.language, condition: 'LP', quantity: 2, priceEur: 1.0 },
        { finish: 'foil', language: base.language, condition: 'LP', quantity: 1, priceEur: 0.5 }
      ]
    }
  }

  it('enColeccion suma todas las versiones que tienes', () => {
    store.indiceColeccion = indiceDeTresVersiones('un-uuid')

    expect(store.enColeccion('un-uuid')).toBe(4)
    expect(store.enColeccion('uuid-que-no-tienes')).toBe(0)
  })

  it('libresDe descuenta lo que ESTE mazo ya reclama y esconde lo que queda a cero', () => {
    const uuid = 'un-uuid'

    store.indiceColeccion = indiceDeTresVersiones(uuid)
    store.cartas = [
      {
        id: 1,
        printingUuid: uuid,
        finish: 'normal',
        language: 'English',
        condition: 'NM',
        board: 'main',
        count: 1
      }
    ]

    const libres = store.libresDe(uuid)

    // La NM se gasta entera en este mazo, así que desaparece de lo ofrecible:
    // es la pregunta que se hace el usuario mientras monta («¿me queda alguna?»).
    expect(libres).toHaveLength(2)
    expect(libres.every((l) => l.libres > 0)).toBe(true)
    expect(store.totalLibres(uuid)).toBe(3)
  })
})

describe('repartir() — el modo «coge lo que tenga»', () => {
  const uuid = 'a4649be8-4284-5371-831f-2e3ee32ec988'

  beforeEach(() => {
    store.indiceColeccion = {
      [uuid]: [
        { finish: 'normal', language: 'English', condition: 'NM', quantity: 1, priceEur: 8.49 },
        { finish: 'normal', language: 'English', condition: 'LP', quantity: 2, priceEur: 1.0 },
        { finish: 'foil', language: 'English', condition: 'LP', quantity: 1, priceEur: 0.5 }
      ]
    }
  })

  it('gasta primero lo PEOR y, a igualdad de estado, lo más barato', () => {
    const partes = store.repartir(uuid, 4)

    // Las cartas buenas se guardan y las jugadas se juegan: por eso el orden va
    // de peor a mejor (`CONDICIONES_DE_PEOR_A_MEJOR`) y a igualdad, la barata.
    expect(partes).toEqual([
      { finish: 'foil', language: 'English', condition_grade: 'LP', count: 1 },
      { finish: 'normal', language: 'English', condition_grade: 'LP', count: 2 },
      { finish: 'normal', language: 'English', condition_grade: 'NM', count: 1 }
    ])
  })

  it('lo que no tienes se pide SIN dimensiones, para que las ponga el backend', () => {
    const partes = store.repartir(uuid, 6)

    // El mazo declara lo que quiere llevar aunque no lo tengas todavía, y el
    // cruce con la colección ya dirá «te falta 2».
    expect(partes.at(-1)).toEqual({ count: 2 })
    expect(partes.reduce((suma, p) => suma + p.count, 0)).toBe(6)
  })

  it('sin índice cae al camino simple: una parte y sin resolver nada', () => {
    store.indiceColeccion = {}

    expect(store.repartir(uuid, 2)).toEqual([{ count: 2 }])
  })
})

describe('listar() y los conflictos', () => {
  it('carga la lista y pregunta los conflictos por un mazo CONSTRUIDO', async () => {
    respondeSegunAccion({
      deck_list: fixtura(deckListFixture),
      deck_get: fixtura(deckGetFixture)
    })

    await store.listar()

    expect(store.mazos).toHaveLength(2)
    // No hay acción propia para el análisis: los conflictos viajan dentro de
    // `deck_get` y son globales, así que basta con preguntar por cualquier
    // construido (`DeckController.php:125` ← `deck.js:283-286`).
    expect(apiCall).toHaveBeenCalledWith('deck_get', { deck_id: 17 })
    expect(store.conflicts).toHaveLength(1)
    expect(store.cargandoLista).toBe(false)
  })

  it('sin ningún mazo construido NO pregunta: solo `built` consume colección', async () => {
    const lista = fixtura(deckListFixture)

    lista.data.decks = lista.data.decks.map((m) => ({ ...m, status: 'building' }))

    respondeSegunAccion({ deck_list: lista })

    await store.listar()

    expect(store.conflicts).toEqual([])
    expect(apiCall).toHaveBeenCalledTimes(1)
  })

  it('si el deck_get de los conflictos falla, la lista sigue en pie sin conflictos', async () => {
    respondeSegunAccion({
      deck_list: fixtura(deckListFixture),
      deck_get: { status: 'error', message: 'Vaya.', http_code: 500 }
    })

    await store.listar()

    expect(store.mazos).toHaveLength(2)
    expect(store.conflicts).toEqual([])
  })

  it('con error de lista deja los mazos vacíos y avisa', async () => {
    respondeSegunAccion({ deck_list: { status: 'error', http_code: 401 } })

    store.mazos = fixtura(deckListFixture).data.decks

    await store.listar()

    expect(store.mazos).toEqual([])
    expect(store.errorLista).toMatch(/mazos/i)
    expect(store.cargandoLista).toBe(false)
  })
})

describe('crear, actualizar y borrar', () => {
  it('crear() pone el mazo nuevo el PRIMERO de la lista', async () => {
    store.mazos = fixtura(deckListFixture).data.decks

    respondeSegunAccion({ deck_create: fixtura(deckCreateFixture) })

    const mazo = await store.crear({ name: 'Mazo de pruebas', format: 'commander' })

    expect(mazo.id).toBe(23)
    expect(store.mazos[0].id).toBe(23)
    expect(store.mazos).toHaveLength(3)
    expect(store.aviso.texto).toMatch(/Mazo de pruebas/)
  })

  it('crear() con error no toca la lista', async () => {
    store.mazos = fixtura(deckListFixture).data.decks

    respondeSegunAccion({ deck_create: { status: 'error', message: 'Falta el nombre.', http_code: 400 } })

    await expect(store.crear({})).resolves.toBeNull()

    expect(store.mazos).toHaveLength(2)
    expect(store.aviso).toEqual({ tipo: 'error', texto: 'Falta el nombre.' })
  })

  it('actualizar() manda solo lo que se toca y refresca la fila de la lista', async () => {
    store.mazos = [...fixtura(deckListFixture).data.decks, fixtura(deckCreateFixture).data.deck]

    respondeSegunAccion({ deck_update: fixtura(deckUpdateFixture) })

    const ok = await store.actualizar(23, { name: 'Mazo de pruebas (renombrado)' })

    expect(ok).toBe(true)
    // Edición parcial: el botón «desmontar» manda solo `status`, y por eso no
    // puede borrar el nombre.
    expect(apiCall).toHaveBeenCalledWith('deck_update', {
      deck_id: 23,
      name: 'Mazo de pruebas (renombrado)'
    })
    expect(store.mazos[2].name).toBe('Mazo de pruebas (renombrado)')
    // Sin tocar `status` ni `format` no hay ni conflictos ni refresco.
    expect(apiCall).toHaveBeenCalledTimes(1)
  })

  it('cambiar de estado vuelve a mirar los conflictos', async () => {
    store.mazos = fixtura(deckListFixture).data.decks

    const actualizado = fixtura(deckUpdateFixture)

    actualizado.data.deck = { ...fixtura(deckListFixture).data.decks[0], status: 'dismantled' }

    respondeSegunAccion({
      deck_update: actualizado,
      deck_list: fixtura(deckListFixture),
      deck_get: fixtura(deckGetFixture)
    })

    await store.desmontar(17)

    expect(apiCall).toHaveBeenCalledWith('deck_update', { deck_id: 17, status: 'dismantled' })
    // Un mazo que pasa a `built` puede crear un conflicto y uno que se desmonta
    // puede resolverlo: por eso se vuelve a mirar (`deck.js:376-380`).
    //
    // Y se pregunta por el mazo **21**, no por el 17: el 17 acaba de dejar de
    // estar construido, y `cargarConflictos()` busca uno que SÍ lo esté porque
    // solo `built` consume colección. Preguntar por el desmontado devolvería
    // los conflictos de un mazo que ya no compite por nada.
    expect(apiCall).toHaveBeenCalledWith('deck_get', { deck_id: 21 })
  })

  it('cambiar de formato vuelve a PEDIR la ficha: las marcas de legalidad son del backend', async () => {
    sembrarFicha(store)
    store.mazos = fixtura(deckListFixture).data.decks

    const actualizado = fixtura(deckUpdateFixture)

    actualizado.data.deck = { ...fixtura(deckGetFixture).data.deck, format: 'modern' }

    respondeSegunAccion({ deck_update: actualizado, deck_get: fixtura(deckGetFixture) })

    await store.actualizar(17, { format: 'modern' })

    // Cambiar de formato cambia TODAS las marcas de golpe, y las calcula el
    // backend con un LEFT JOIN sobre `mtg_legality`: aquí no se reinventan.
    expect(apiCall).toHaveBeenCalledWith('deck_get', { deck_id: 17 })
  })

  it('actualizar() con error no toca nada', async () => {
    store.mazos = fixtura(deckListFixture).data.decks

    respondeSegunAccion({ deck_update: { status: 'error', http_code: 500 } })

    await expect(store.actualizar(17, { name: 'x' })).resolves.toBe(false)

    expect(store.mazos[0].name).toBe('Tom bombadil')
    expect(store.aviso.texto).toMatch(/no se pudo guardar/i)
  })

  it('borrar() saca el mazo de la lista y NO descuenta cartas por defecto', async () => {
    store.mazos = fixtura(deckListFixture).data.decks

    respondeSegunAccion({ deck_delete: fixtura(deckDeleteFixture) })

    const ok = await store.borrar(17)

    expect(ok).toBe(true)
    // `conCartas` es lo único que separa «he deshecho la lista» de «he vendido
    // el mazo entero»: nunca se manda por defecto.
    expect(apiCall).toHaveBeenCalledWith('deck_delete', { deck_id: 17, with_cards: false })
    expect(store.mazos).toHaveLength(1)
    expect(store.aviso.texto).toBe('Mazo borrado.')
  })

  it('borrar() con cartas cuenta lo descontado y lo que faltaba', async () => {
    store.mazos = fixtura(deckListFixture).data.decks

    const respuesta = fixtura(deckDeleteFixture)

    respuesta.data.removedFromCollection = 97
    respuesta.data.shortfall = [{ printingUuid: 'x', missing: 3 }]

    respondeSegunAccion({ deck_delete: respuesta })

    await store.borrar(17, true)

    expect(apiCall).toHaveBeenCalledWith('deck_delete', { deck_id: 17, with_cards: true })
    expect(store.aviso.texto).toMatch(/97 ejemplar\(es\) descontados/)
    expect(store.aviso.texto).toMatch(/de 1 carta\(s\) tenías menos/)
  })

  it('borrar() con error no quita el mazo de la lista', async () => {
    store.mazos = fixtura(deckListFixture).data.decks

    respondeSegunAccion({ deck_delete: { status: 'error', http_code: 500 } })

    await expect(store.borrar(17)).resolves.toBe(false)

    expect(store.mazos).toHaveLength(2)
  })
})

describe('cargar() y refrescar()', () => {
  it('cargar() deja la ficha entera puesta', async () => {
    respondeSegunAccion({ deck_get: fixtura(deckGetFixture) })

    const ok = await store.cargar(17)

    expect(ok).toBe(true)
    expect(store.mazo.id).toBe(17)
    expect(store.cartas).toHaveLength(96)
    expect(store.valueEur).toBe(160.18)
    expect(store.availability).toHaveLength(96)
    expect(store.conflicts).toHaveLength(1)
    expect(store.legality.format).toBe('commander')
    expect(store.cargando).toBe(false)
  })

  it('cargar() con error deja la ficha vacía y el mensaje puesto', async () => {
    respondeSegunAccion({ deck_get: { status: 'error', message: 'Ese mazo no es tuyo.', http_code: 403 } })

    await expect(store.cargar(999)).resolves.toBe(false)

    expect(store.mazo).toBeNull()
    expect(store.cartas).toEqual([])
    expect(store.error).toBe('Ese mazo no es tuyo.')
    expect(store.cargando).toBe(false)
  })

  it('refrescar() sin id y sin mazo cargado no llama a nadie', async () => {
    await expect(store.refrescar()).resolves.toBe(false)

    expect(apiCall).not.toHaveBeenCalled()
  })

  it('refrescar() usa el mazo que ya está cargado y NO toca los flags de carga', async () => {
    sembrarFicha(store)

    const respuesta = fixtura(deckGetFixture)

    respuesta.data.valueEur = 999.99
    respondeSegunAccion({ deck_get: respuesta })

    await store.refrescar()

    expect(apiCall).toHaveBeenCalledWith('deck_get', { deck_id: 17 })
    // Sin parpadeo: la tabla ya está pintada y esto solo pone al día el valor y
    // la disponibilidad, que los calcula el backend.
    expect(store.cargando).toBe(false)
    expect(store.valueEur).toBe(999.99)
  })

  it('una respuesta sin datos deja los valores por defecto, no undefined', async () => {
    respondeSegunAccion({ deck_get: { status: 'success', data: {}, http_code: 200 } })

    await store.cargar(17)

    expect(store.mazo).toBeNull()
    expect(store.cartas).toEqual([])
    expect(store.valueEur).toBe(0)
    // `LEGALIDAD_VACIA` existe para que la vista pueda leer `legality.statuses`
    // sin comprobar nada (`deck.js:11-21`).
    expect(store.legality.statuses).toEqual({})
    expect(store.legality.known).toBe(false)
  })

  it('limpiarFicha() no deja nada que pueda heredar la siguiente', () => {
    sembrarFicha(store)
    store.resultados = fixtura(cardsFixture).items.slice(0, 5)
    store.consulta = 'sol ring'
    store.variantes = { 1665: [] }

    store.limpiarFicha()

    expect(store.mazo).toBeNull()
    expect(store.cartas).toEqual([])
    expect(store.conflicts).toEqual([])
    expect(store.resultados).toEqual([])
    expect(store.consulta).toBe('')
    expect(store.variantes).toEqual({})
    expect(store.legality.statuses).toEqual({})
  })
})

describe('el buscador embebido — catálogo público, sin credenciales', () => {
  it('busca por catalogGet y no por el endpoint único', async () => {
    catalogGet.mockResolvedValue({ items: fixtura(cardsFixture).items.slice(0, 30), nextCursor: 'c2' })

    await store.buscarCartas('sol ring')

    expect(catalogGet).toHaveBeenCalledWith('/cards', {
      q: 'sol ring',
      limit: 30,
      sort: 'relevance'
    })
    expect(apiCall).not.toHaveBeenCalled()
    expect(store.resultados).toHaveLength(30)
    expect(store.cursorResultados).toBe('c2')
    expect(store.buscando).toBe(false)
  })

  it('con menos de dos caracteres no sale a la red', async () => {
    store.resultados = fixtura(cardsFixture).items.slice(0, 5)

    await store.buscarCartas(' s ')

    expect(catalogGet).not.toHaveBeenCalled()
    expect(store.resultados).toEqual([])
    expect(store.consulta).toBe(' s ')
  })

  it('la respuesta LENTA de una búsqueda vieja no pisa a la reciente', async () => {
    let resolverLenta

    catalogGet.mockImplementationOnce(
      () => new Promise((resolver) => { resolverLenta = resolver })
    )
    catalogGet.mockResolvedValueOnce({
      items: fixtura(cardsFixture).items.slice(0, 3),
      nextCursor: 'cursor-reciente'
    })

    const lenta = store.buscarCartas('sol')
    const reciente = store.buscarCartas('sol ring')

    await reciente

    expect(store.resultados).toHaveLength(3)

    resolverLenta({ items: fixtura(cardsFixture).items.slice(0, 20), nextCursor: 'cursor-viejo' })
    await lenta

    expect(store.resultados).toHaveLength(3)
    expect(store.cursorResultados).toBe('cursor-reciente')
  })

  it('con error de red deja la lista vacía en vez de reventar', async () => {
    catalogGet.mockResolvedValue({ error: 'network_error' })

    await store.buscarCartas('sol ring')

    expect(store.resultados).toEqual([])
    expect(store.cursorResultados).toBeNull()
  })

  it('masResultados() ACUMULA la página siguiente', async () => {
    catalogGet.mockResolvedValueOnce({
      items: fixtura(cardsFixture).items.slice(0, 10),
      nextCursor: 'c2'
    })
    await store.buscarCartas('sol ring')

    catalogGet.mockResolvedValueOnce({
      items: fixtura(cardsFixture).items.slice(10, 20),
      nextCursor: null
    })
    await store.masResultados()

    expect(store.resultados).toHaveLength(20)
    expect(store.cursorResultados).toBeNull()
    expect(catalogGet.mock.calls[1][1]).toMatchObject({ cursor: 'c2', q: 'sol ring' })
    expect(store.buscandoMas).toBe(false)
  })

  it('masResultados() sin cursor o con otra página en vuelo no pide nada', async () => {
    await store.masResultados()

    store.cursorResultados = 'c2'
    store.buscandoMas = true
    await store.masResultados()

    expect(catalogGet).not.toHaveBeenCalled()
  })

  it('masResultados() descarta la página si la consulta cambió mientras se pedía', async () => {
    store.cursorResultados = 'c2'
    store.consulta = 'sol'

    let resolverPagina

    catalogGet.mockImplementationOnce(
      () => new Promise((resolver) => { resolverPagina = resolver })
    )

    const masPaginas = store.masResultados()

    catalogGet.mockResolvedValueOnce({
      items: fixtura(cardsFixture).items.slice(40, 45),
      nextCursor: 'cursor-de-la-nueva'
    })
    await store.buscarCartas('sol ring')

    resolverPagina({ items: fixtura(cardsFixture).items.slice(0, 10), nextCursor: 'cursor-viejo' })
    await masPaginas

    expect(store.resultados).toHaveLength(5)
    expect(store.cursorResultados).toBe('cursor-de-la-nueva')
    expect(store.buscandoMas).toBe(false)
  })
})

describe('cargarIndiceColeccion()', () => {
  /** Una página de colección con el sobre del backend puesto. */
  function paginaColeccion(desde, hasta, nextCursor = null) {
    const items = fixtura(collectionListFixture).data.items.slice(desde, hasta)

    return { status: 'success', data: { items, nextCursor, count: items.length }, http_code: 200 }
  }

  it('pagina por cursor hasta el final y agrupa por printing_uuid', async () => {
    apiCall
      .mockResolvedValueOnce(paginaColeccion(0, 30, 'cursor-2'))
      .mockResolvedValueOnce(paginaColeccion(30, 60, null))

    await store.cargarIndiceColeccion()

    expect(apiCall).toHaveBeenCalledTimes(2)
    expect(apiCall.mock.calls[0][1]).toEqual({ limit: 200, sort: 'name' })
    expect(apiCall.mock.calls[1][1]).toEqual({ limit: 200, sort: 'name', cursor: 'cursor-2' })

    const items = fixtura(collectionListFixture).data.items.slice(0, 60)
    const uuids = new Set(items.map((i) => i.printingUuid))

    expect(Object.keys(store.indiceColeccion)).toHaveLength(uuids.size)
    expect(store.indiceListo).toBe(true)
    expect(store.indiceParcial).toBe(false)
    expect(store.cargandoIndice).toBe(false)

    // Del item solo se guardan las cinco cosas que el reparto necesita.
    expect(store.indiceColeccion[items[0].printingUuid][0]).toEqual({
      finish: items[0].finish,
      language: items[0].language,
      condition: items[0].condition,
      quantity: items[0].quantity,
      priceEur: items[0].priceEur
    })
  })

  it('no lo reconstruye si ya está listo o si hay otro en vuelo', async () => {
    store.indiceListo = true
    await store.cargarIndiceColeccion()

    store.indiceListo = false
    store.cargandoIndice = true
    await store.cargarIndiceColeccion()

    expect(apiCall).not.toHaveBeenCalled()
  })

  it('con un error a mitad se marca PARCIAL y el clic cae al camino simple', async () => {
    apiCall
      .mockResolvedValueOnce(paginaColeccion(0, 10, 'cursor-2'))
      .mockResolvedValueOnce({ status: 'error', http_code: 500 })

    await store.cargarIndiceColeccion()

    // `indiceParcial` no es un error que se enseñe: es el aviso de que el clic
    // no puede resolver versiones y añade con los valores por defecto.
    expect(store.indiceParcial).toBe(true)
    expect(store.indiceListo).toBe(false)
    expect(store.cargandoIndice).toBe(false)
  })
})

describe('añadir al mazo', () => {
  const uuid = 'a4649be8-4284-5371-831f-2e3ee32ec988'

  beforeEach(() => {
    store.mazo = fixtura(deckCreateFixture).data.deck
  })

  it('un clic con índice puesto reparte en VARIAS altas y refresca al final', async () => {
    store.indiceColeccion = {
      [uuid]: [
        { finish: 'normal', language: 'English', condition: 'NM', quantity: 1, priceEur: 8.49 },
        { finish: 'normal', language: 'English', condition: 'LP', quantity: 1, priceEur: 1.0 }
      ]
    }

    respondeSegunAccion({
      deck_card_add: fixtura(cardAddFixture),
      deck_get: fixtura(deckGetFixture)
    })

    const ok = await store.anadirResuelto(uuid, 2)

    expect(ok).toBe(true)

    const altas = apiCall.mock.calls.filter(([accion]) => accion === 'deck_card_add')

    expect(altas).toHaveLength(2)
    expect(altas[0][1]).toEqual({
      deck_id: 23,
      printing_uuid: uuid,
      board: 'main',
      finish: 'normal',
      language: 'English',
      condition_grade: 'LP',
      count: 1
    })
    expect(store.aviso.texto).toMatch(/repartidas en 2 versiones/)
    // El valor y la disponibilidad los calcula el backend: tras escribir se
    // vuelven a pedir en vez de reinventarlos aquí.
    expect(apiCall).toHaveBeenCalledWith('deck_get', { deck_id: 23 })
    expect(store.anadiendo).toEqual([])
  })

  it('sin índice añade igual, con los valores por defecto del backend', async () => {
    respondeSegunAccion({
      deck_card_add: fixtura(cardAddFixture),
      deck_get: fixtura(deckGetFixture)
    })

    await store.anadirResuelto(uuid, 1, 'side')

    expect(apiCall).toHaveBeenCalledWith('deck_card_add', {
      deck_id: 23,
      printing_uuid: uuid,
      board: 'side',
      count: 1
    })
    expect(store.aviso.texto).toMatch(/Sol Ring añadida al mazo/)
  })

  it('el segundo clic sobre la misma carta no dobla el alta', async () => {
    let resolverAlta

    apiCall.mockImplementationOnce(
      () => new Promise((resolver) => { resolverAlta = resolver })
    )

    const primero = store.anadirResuelto(uuid, 1)

    expect(store.estaAnadiendo(uuid)).toBe(true)
    await expect(store.anadirResuelto(uuid, 1)).resolves.toBe(false)

    apiCall.mockResolvedValue(fixtura(deckGetFixture))
    resolverAlta(fixtura(cardAddFixture))
    await primero

    expect(store.estaAnadiendo(uuid)).toBe(false)
  })

  it('sin printing_uuid no llama a nadie', async () => {
    await expect(store.anadirResuelto('')).resolves.toBe(false)

    expect(apiCall).not.toHaveBeenCalled()
  })

  it('el camino de «Opciones» manda las dimensiones dichas a mano', async () => {
    respondeSegunAccion({
      deck_card_add: fixtura(cardAddFixture),
      deck_get: fixtura(deckGetFixture)
    })

    const ok = await store.anadirConOpciones(uuid, {
      board: 'side',
      finish: 'foil',
      language: 'Spanish',
      condition_grade: 'LP',
      count: 2
    })

    expect(ok).toBe(true)
    expect(apiCall).toHaveBeenCalledWith('deck_card_add', {
      deck_id: 23,
      printing_uuid: uuid,
      board: 'side',
      finish: 'foil',
      language: 'Spanish',
      condition_grade: 'LP',
      count: 2
    })
    expect(store.aviso.texto).toBe('Carta añadida al mazo.')
  })

  it('«Opciones» no se dobla tampoco', async () => {
    store.anadiendo = [uuid]

    await expect(store.anadirConOpciones(uuid, {})).resolves.toBe(false)

    expect(apiCall).not.toHaveBeenCalled()
  })

  it('el alta REEMPLAZA la línea si ya existía, no la duplica', async () => {
    const carta = fixtura(cardAddFixture).data.card

    store.cartas = [{ ...carta, count: 1 }]

    const refresco = refrescoEnVuelo({ deck_card_add: fixtura(cardAddFixture) })
    const alta = store.anadirConOpciones(uuid, { count: 1 })

    await refresco.esperar()

    // Repetir la misma carta SUMA en el servidor, así que aquí se reemplaza la
    // fila por la que vuelve: duplicarla enseñaría dos líneas de lo mismo.
    const lineas = store.cartas.filter((c) => c.id === carta.id)

    expect(lineas).toHaveLength(1)
    expect(lineas[0].count).toBe(2)

    refresco.completar()
    await alta
  })

  it('con error no refresca ni avisa en verde', async () => {
    respondeSegunAccion({
      deck_card_add: { status: 'error', message: 'Ese mazo no es tuyo.', http_code: 403 }
    })

    await expect(store.anadirConOpciones(uuid, {})).resolves.toBe(false)

    expect(store.aviso).toEqual({ tipo: 'error', texto: 'Ese mazo no es tuyo.' })
    expect(apiCall).toHaveBeenCalledTimes(1)
  })
})

describe('editar las líneas del mazo', () => {
  beforeEach(() => {
    sembrarFicha(store)
  })

  it('fijarCantidad() no llama al backend si la cantidad es la misma', async () => {
    const carta = store.cartas[0]

    await expect(store.fijarCantidad(carta, carta.count)).resolves.toBe(true)

    expect(apiCall).not.toHaveBeenCalled()
  })

  it('fijarCantidad() guarda y reemplaza la línea en su sitio', async () => {
    const carta = fixtura(cardSetFixture).data.card

    store.cartas = [{ ...carta, count: 2 }, ...cartasDeFixtura(0, 2)]
    store.mazo = fixtura(deckCreateFixture).data.deck

    respondeSegunAccion({ deck_card_set: fixtura(cardSetFixture), deck_get: fixtura(deckGetFixture) })

    const ok = await store.fijarCantidad({ ...carta, count: 2 }, 3)

    expect(ok).toBe(true)
    expect(apiCall).toHaveBeenCalledWith('deck_card_set', {
      deck_id: 23,
      card_id: carta.id,
      count: 3
    })
    expect(store.estaGuardando(carta.id)).toBe(false)
  })

  it('fijarCantidad(0) hace DESAPARECER la línea de la tabla', async () => {
    const carta = store.cartas[0]

    const refresco = refrescoEnVuelo({
      deck_card_set: { status: 'success', data: { removed: true }, http_code: 200 }
    })

    const edicion = store.fijarCantidad(carta, 0)

    await refresco.esperar()

    expect(store.cartas.find((c) => c.id === carta.id)).toBeUndefined()
    expect(store.aviso.texto).toMatch(new RegExp(`${carta.name} ya no está en el mazo`))

    refresco.completar()
    await edicion
  })

  it('fijarCantidad() con error no toca la tabla', async () => {
    const carta = store.cartas[0]

    respondeSegunAccion({ deck_card_set: { status: 'error', http_code: 500 } })

    await expect(store.fijarCantidad(carta, 4)).resolves.toBe(false)

    expect(store.cartas).toHaveLength(96)
    expect(store.aviso.texto).toMatch(/no se pudo guardar la cantidad/i)
    expect(store.estaGuardando(carta.id)).toBe(false)
  })

  it('quitar() saca la línea del mazo y NO toca la colección', async () => {
    const carta = store.cartas[0]

    respondeSegunAccion({
      deck_card_remove: fixtura(cardRemoveFixture),
      deck_get: fixtura(deckGetFixture)
    })

    const ok = await store.quitar(carta)

    expect(ok).toBe(true)
    expect(apiCall).toHaveBeenCalledWith('deck_card_remove', { deck_id: 17, card_id: carta.id })
    expect(store.aviso.texto).toMatch(/fuera del mazo/)
  })

  it('quitar() con error deja la línea donde estaba', async () => {
    const carta = store.cartas[0]

    respondeSegunAccion({ deck_card_remove: { status: 'error', http_code: 500 } })

    await expect(store.quitar(carta)).resolves.toBe(false)

    expect(store.cartas).toHaveLength(96)
    expect(store.aviso.texto).toMatch(/no se pudo quitar/i)
  })
})

describe('cargarVariantes()', () => {
  beforeEach(() => {
    sembrarFicha(store)
  })

  it('pide las versiones de esa línea y las cachea', async () => {
    const carta = store.cartas[0]

    respondeSegunAccion({ deck_card_variants: fixtura(variantsFixture) })

    await store.cargarVariantes(carta)

    expect(apiCall).toHaveBeenCalledWith('deck_card_variants', {
      deck_id: 17,
      card_id: carta.id
    })
    // `free` viene del backend y cuenta lo que reclaman TODOS tus mazos
    // construidos, que es la verdad completa; `libresDe` solo mira este mazo.
    expect(store.variantes[carta.id]).toHaveLength(2)
    expect(store.variantes[carta.id][1].free).toBe(0)
  })

  it('no las vuelve a pedir si ya están (una tabla de 40 líneas serían 40 peticiones)', async () => {
    const carta = store.cartas[0]

    store.variantes = { [carta.id]: [] }

    await store.cargarVariantes(carta)

    expect(apiCall).not.toHaveBeenCalled()
  })

  it('no dobla la petición mientras una está en vuelo', async () => {
    const carta = store.cartas[0]

    store.cargandoVariantes = [carta.id]

    await store.cargarVariantes(carta)

    expect(apiCall).not.toHaveBeenCalled()
  })

  it('con error no deja una entrada vacía en la caché', async () => {
    const carta = store.cartas[0]

    respondeSegunAccion({ deck_card_variants: { status: 'error', http_code: 500 } })

    await store.cargarVariantes(carta)

    expect(store.variantes[carta.id]).toBeUndefined()
    expect(store.cargandoVariantes).toEqual([])
  })
})

describe('cambiarVersion() — la edición que MUEVE la fila', () => {
  beforeEach(() => {
    store.mazo = fixtura(deckCreateFixture).data.deck
  })

  it('no llama al backend si la versión es la que ya tenía', async () => {
    const carta = fixtura(cardChangeFixture).data.card

    await expect(
      store.cambiarVersion(carta, {
        finish: carta.finish,
        language: carta.language,
        condition_grade: carta.condition,
        board: carta.board
      })
    ).resolves.toBe(true)

    expect(apiCall).not.toHaveBeenCalled()
  })

  it('reinserta la línea DONDE ESTABA e invalida sus versiones cacheadas', async () => {
    const nueva = fixtura(cardChangeFixture).data.card
    const original = { ...nueva, finish: 'normal', condition: 'NM', board: 'main' }

    store.cartas = [cartasDeFixtura(0, 1)[0], original, cartasDeFixtura(1, 2)[0]]
    store.variantes = { [original.id]: [{ finish: 'normal' }] }

    const refresco = refrescoEnVuelo({ deck_card_change: fixtura(cardChangeFixture) })
    const cambio = store.cambiarVersion(original, {
      finish: 'foil',
      language: 'English',
      condition_grade: 'LP',
      board: 'side'
    })

    await refresco.esperar()
    expect(apiCall).toHaveBeenCalledWith('deck_card_change', {
      deck_id: 23,
      card_id: original.id,
      finish: 'foil',
      language: 'English',
      condition_grade: 'LP',
      board: 'side'
    })

    // Reordenar la tabla entera movería filas bajo el dedo del usuario en mitad
    // de una edición (`deck.js:874-877`).
    expect(store.cartas).toHaveLength(3)
    expect(store.cartas[1].finish).toBe('foil')
    // Las versiones cacheadas eran de la línea vieja: con el `id` movido ya no
    // valen.
    expect(store.variantes[original.id]).toBeUndefined()
    expect(store.aviso.texto).toBe('Versión cambiada.')

    refresco.completar()
    await expect(cambio).resolves.toBe(true)
  })

  it('cuando el backend FUNDE dos líneas, la tabla se queda con una menos y sin recargar', async () => {
    // Las cuatro columnas están dentro de `uq_deck_card`: el cambio no modifica
    // la fila, la MUEVE, y el destino puede estar ya ocupado por otra línea del
    // mismo mazo. El `id` que vuelve no es el que se mandó.
    const respuesta = fixtura(cardChangeFixture)
    const destino = { ...respuesta.data.card, id: 1700, count: 4 }

    respuesta.data.card = destino
    respuesta.data.merged = true

    const original = { ...fixtura(cardChangeFixture).data.card, id: 1665, finish: 'normal' }

    store.cartas = [cartasDeFixtura(0, 1)[0], original, { ...destino, count: 1 }]

    const refresco = refrescoEnVuelo({ deck_card_change: respuesta })
    const cambio = store.cambiarVersion(original, {
      finish: 'foil',
      language: 'English',
      condition_grade: 'LP',
      board: 'side'
    })

    await refresco.esperar()

    expect(store.cartas).toHaveLength(2)
    expect(store.cartas.find((c) => c.id === 1665)).toBeUndefined()
    expect(store.cartas.find((c) => c.id === 1700).count).toBe(4)
    expect(store.aviso.texto).toMatch(/unida a la línea/)

    refresco.completar()
    await cambio
  })

  it('con error la tabla se queda como estaba', async () => {
    const original = { ...fixtura(cardChangeFixture).data.card, finish: 'normal' }

    store.cartas = [original]

    respondeSegunAccion({
      deck_card_change: { status: 'error', message: 'No tienes esa versión.', http_code: 400 }
    })

    await expect(
      store.cambiarVersion(original, {
        finish: 'foil',
        language: 'English',
        condition_grade: 'LP',
        board: 'side'
      })
    ).resolves.toBe(false)

    expect(store.cartas).toHaveLength(1)
    expect(store.aviso).toEqual({ tipo: 'error', texto: 'No tienes esa versión.' })
    expect(store.estaGuardando(original.id)).toBe(false)
  })
})

/**
 * **El enlace público del mazo** — `compartir()` y `dejarDeCompartir()`.
 *
 * Las dos fixturas son la respuesta literal del backend de dev sobre el mazo 17,
 * y se capturaron comprobando además lo que ningún test del frontend puede
 * comprobar: con el token en pie, `GET /api/public/deck/<token>` devuelve **200**
 * y, tras el `deck_unshare`, el **mismo** token devuelve **404**. El enlace muere
 * de verdad; aquí se prueba que el store lo pide bien y guarda lo que vuelve.
 *
 * Lo que de verdad se fija en este bloque:
 *
 *  - **La URL absoluta la compone el CLIENTE.** El backend manda la relativa
 *    (`/#/shared/deck/<token>`) a propósito: componerla allí con la cabecera
 *    `Host` dejaría que un atacante eligiera el dominio del enlace que el
 *    usuario va a copiar y pegar.
 *  - **El `/#/` tiene que sobrevivir**: el router va con `createWebHashHistory`
 *    y una URL sin almohadilla no resuelve.
 *  - **`dejarDeCompartir()` no necesita ningún token**: manda solo el `deck_id`,
 *    que es lo que permite matar un enlace que ya no se tiene a la vista.
 */
describe('el enlace público del mazo', () => {
  /** El origen que jsdom le da a la ventana; es lo que el store antepone. */
  const ORIGEN = window.location.origin
  const TOKEN = shareFixture.data.shareToken

  it('compartir() manda solo el deck_id y guarda la URL ABSOLUTA', async () => {
    respondeSegunAccion({ deck_share: fixtura(shareFixture) })

    const enlace = await store.compartir(17)

    // Ni el token ni la URL viajan del cliente al servidor: el token lo genera
    // `random_bytes()` en el backend y lo único que hay que mandar es el mazo.
    expect(apiCall).toHaveBeenCalledWith('deck_share', { deck_id: 17 })

    expect(TOKEN).toHaveLength(64)
    expect(shareFixture.data.url).toBe(`/#/shared/deck/${TOKEN}`)

    // La relativa de la fixtura, con el origen del navegador delante.
    expect(enlace).toEqual({ token: TOKEN, url: `${ORIGEN}/#/shared/deck/${TOKEN}` })
    expect(store.enlaceCompartido).toEqual(enlace)
    expect(store.compartiendo).toBe(false)
    expect(store.aviso.tipo).toBe('ok')
  })

  it('la URL conserva el `/#/`, sin el que createWebHashHistory no resuelve nada', async () => {
    respondeSegunAccion({ deck_share: fixtura(shareFixture) })

    await store.compartir(17)

    // Que esa URL abre de verdad `/shared/deck/:token` lo comprueba contra el
    // router REAL `DeckView.spec.js`, donde ya hay uno montado; aquí se fija lo
    // que el store no puede perder al componerla.
    expect(store.enlaceCompartido.url.startsWith(`${ORIGEN}/#/`)).toBe(true)
    expect(store.enlaceCompartido.url.endsWith(`/shared/deck/${TOKEN}`)).toBe(true)
  })

  it('un fallo al compartir se dice y NO deja ningún enlace a medias', async () => {
    respondeSegunAccion({
      deck_share: { status: 'error', message: 'Ese mazo no existe o no es tuyo.', http_code: 404 }
    })

    await expect(store.compartir(999)).resolves.toBeNull()

    expect(store.enlaceCompartido).toBeNull()
    expect(store.aviso).toEqual({ tipo: 'error', texto: 'Ese mazo no existe o no es tuyo.' })
    expect(store.compartiendo).toBe(false)
  })

  it('un éxito sin token no se pinta como enlace: `${origin}undefined` sería una URL rota', async () => {
    respondeSegunAccion({ deck_share: { status: 'success', data: {}, http_code: 200 } })

    await expect(store.compartir(17)).resolves.toBeNull()

    expect(store.enlaceCompartido).toBeNull()
    expect(store.aviso.tipo).toBe('error')
  })

  it('dejarDeCompartir() manda solo el deck_id y borra el enlace de la pantalla', async () => {
    respondeSegunAccion({
      deck_share: fixtura(shareFixture),
      deck_unshare: fixtura(unshareFixture)
    })

    await store.compartir(17)

    await expect(store.dejarDeCompartir(17)).resolves.toBe(true)

    // Sin el token: pedirlo obligaría a la interfaz a conocerlo para poder
    // matarlo, que es lo contrario de lo que hace falta cuando el enlace se te
    // fue de las manos.
    expect(apiCall).toHaveBeenCalledWith('deck_unshare', { deck_id: 17 })
    expect(unshareFixture.data.shareToken).toBeNull()
    expect(store.enlaceCompartido).toBeNull()
    expect(store.aviso.tipo).toBe('ok')
  })

  it('dejarDeCompartir() funciona SIN ningún enlace en pantalla: es idempotente', async () => {
    respondeSegunAccion({ deck_unshare: fixtura(unshareFixture) })

    expect(store.enlaceCompartido).toBeNull()

    // Es el caso de después de recargar, que es cuando de verdad hace falta:
    // `deck_get` no devuelve `share_token`, así que nadie sabe si seguía
    // compartido. El backend responde que sí igualmente.
    await expect(store.dejarDeCompartir(17)).resolves.toBe(true)

    expect(apiCall).toHaveBeenCalledWith('deck_unshare', { deck_id: 17 })
  })

  it('un fallo al revocar NO borra el enlace: seguiría vivo y decir lo contrario es mentir', async () => {
    respondeSegunAccion({
      deck_share: fixtura(shareFixture),
      deck_unshare: { status: 'error', message: 'No se pudo.', http_code: 500 }
    })

    await store.compartir(17)

    await expect(store.dejarDeCompartir(17)).resolves.toBe(false)

    expect(store.enlaceCompartido).not.toBeNull()
    expect(store.aviso).toEqual({ tipo: 'error', texto: 'No se pudo.' })
  })

  it('cargar() otro mazo tira el enlace del anterior antes de pedir nada', async () => {
    respondeSegunAccion({ deck_share: fixtura(shareFixture), deck_get: fixtura(deckGetFixture) })

    await store.compartir(17)

    expect(store.enlaceCompartido).not.toBeNull()

    await store.cargar(21)

    // La URL del 17 bajo el nombre del 21 sería publicar el mazo equivocado a
    // ojos de quien mira la pantalla.
    expect(store.enlaceCompartido).toBeNull()
  })

  it('limpiarFicha() tampoco lo hereda la siguiente ficha', async () => {
    respondeSegunAccion({ deck_share: fixtura(shareFixture) })

    await store.compartir(17)
    store.limpiarFicha()

    expect(store.enlaceCompartido).toBeNull()
  })
})
