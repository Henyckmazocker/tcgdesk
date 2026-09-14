import { beforeEach, describe, expect, it, vi } from 'vitest'
import { nextTick } from 'vue'
import { flushPromises } from '@vue/test-utils'

import DeckCardSearch from '@/components/DeckCardSearch.vue'
import { apiCall, catalogGet } from '@/services/api'
import { ACABADOS } from '@/constants/collection'

import { SESION, montarVista } from '../../helpers'

import catalogoFixture from '../../fixtures/catalog_cards.json'
import altaFixture from '../../fixtures/deck_card_add.json'
import mazoFixture from '../../fixtures/deck_get.json'

/**
 * `components/DeckCardSearch.vue` — el buscador embebido del editor de mazos
 * (`DeckView.vue:152`, su único consumidor).
 *
 * **Este fichero destapó un fallo real y se escribió CONTRA el arreglo.** El
 * componente leía los alias del SQL en vez del contrato —`carta.hasNonfoil` /
 * `hasFoil` / `hasEtched` en `:263-265` y `carta.priceNormal` / `priceFoil` /
 * `priceEtched` en `:275`—, y ninguna de las seis claves existe: `search()` pasa
 * cada fila por `aContrato()` (`MySqlCardRepository.php:111` → `:442-466`), que
 * las publica como `finishes.{nonfoil,foil,etched}` y
 * `priceEur.{normal,foil,etched}`. Eso es lo que trae `catalog_cards.json` y lo
 * que `stores/deck.js:490` guarda **sin mapear**. No lanzaba: pintaba «sin
 * precio» en todo resultado y ofrecía *etched* en cartas que nunca se
 * imprimieron así. Es el mismo pecado que tumbó `/decks`, en un segundo sitio.
 *
 * El test nombrado por el hito es «con `finishes.etched: false` el desplegable
 * NO ofrece etched»: con el código anterior cae al fallback y ofrece los tres,
 * así que es el que se pondría en rojo si alguien revirtiera el arreglo.
 *
 * **Dos trampas de este fichero**, las dos por PrimeVue:
 *  - El `Popover` de «Opciones» (`DeckCardSearch.vue:114`) **teletransporta su
 *    contenido al `document.body`**: no está en `wrapper.html()`. Igual que en
 *    `AddToCollectionButton.spec.js`, se monta con `attachTo: document.body`.
 *  - El `Select` hace lo mismo con su lista de opciones, así que los acabados
 *    ofrecidos se leen del `document.body` **abriendo el desplegable de verdad**,
 *    y no del `.p-select-label`, que solo diría cuál quedó seleccionado.
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

/** Los 60 resultados capturados de `GET /cards`, tal cual los devuelve el backend. */
const ITEMS = catalogoFixture.items
const CARTA = ITEMS[0]

/** El mazo abierto mientras se busca; el alta necesita su `id`. */
const MAZO = mazoFixture.data.deck

async function montarBuscador({ deck = {}, props = {}, attachTo } = {}) {
  const montaje = await montarVista(DeckCardSearch, {
    ruta: `/deck/${MAZO.id}`,
    estado: {
      ...SESION,
      deck: {
        mazo: fixtura(MAZO),
        cartas: [],
        indiceListo: true,
        resultados: ITEMS.slice(0, 3).map(fixtura),
        ...deck
      }
    },
    ...(attachTo ? { attachTo } : {}),
    props: { zonaPorDefecto: 'main', ...props }
  })

  await nextTick()

  return montaje
}

/** Las dos partes de una fila: el botón grande y el de «Opciones». */
function fila(wrapper, indice = 0) {
  const li = wrapper.findAll('.resultado')[indice]

  return { li, principal: li.find('.resultado__principal'), opciones: li.findAll('button')[1] }
}

/** El panel del `Popover`, que vive fuera del wrapper. */
function panel() {
  return document.body.querySelector('.opciones__panel')
}

/** Abre «Opciones» de una fila y devuelve el panel ya teletransportado. */
async function abrirOpciones(wrapper, indice = 0) {
  await fila(wrapper, indice).opciones.trigger('click')
  await nextTick()

  return panel()
}

/**
 * Las etiquetas que ofrece el desplegable de **acabado**, abierto de verdad.
 *
 * Es el primero de los cinco campos del panel (`DeckCardSearch.vue:118-131`), y
 * su lista también se teletransporta: se lee del `document.body`.
 */
async function acabadosOfrecidos(wrapper) {
  await abrirOpciones(wrapper, 0)

  panel().querySelectorAll('.p-select')[0].click()
  await nextTick()
  await nextTick()

  return [...document.body.querySelectorAll('.p-select-option')].map((o) => o.textContent.trim())
}

beforeEach(() => {
  apiCall.mockReset()
  apiCall.mockResolvedValue(fixtura(altaFixture))
  catalogGet.mockReset()
  catalogGet.mockResolvedValue(fixtura(catalogoFixture))
})

describe('la lista de resultados', () => {
  it('pinta una fila por carta con su set, su número, su rareza y su precio', async () => {
    const { wrapper, errores } = await montarBuscador()

    expect(errores).toEqual([])
    expect(wrapper.findAll('.resultado')).toHaveLength(3)

    const primera = fila(wrapper).principal

    expect(primera.find('.resultado__nombre').text()).toBe(CARTA.name)
    expect(primera.find('.resultado__meta').text()).toContain(CARTA.setCode)
    expect(primera.find('.resultado__meta').text()).toContain(CARTA.collectorNumber)
    expect(primera.attributes('aria-label')).toBe(`Añadir ${CARTA.name} al mazo`)
  })

  it('mientras busca enseña esqueletos, y sin coincidencias lo dice con el texto tecleado', async () => {
    const { wrapper } = await montarBuscador({ deck: { buscando: true } })

    expect(wrapper.findAll('.buscador__esqueleto')).toHaveLength(4)
    expect(wrapper.find('.resultado').exists()).toBe(false)

    const { wrapper: vacio } = await montarBuscador({ deck: { resultados: [] } })

    await vacio.find('input').setValue('zzzz')
    await nextTick()

    expect(vacio.find('.buscador__vacio').text()).toContain('«zzzz»')
  })

  it('la etiqueta de tenencia distingue lo libre, lo que está en uso y lo que no tienes', async () => {
    // `totalLibres` descuenta lo que ESTE mazo ya reclama, no lo que reclaman
    // todos (`stores/deck.js:170-199`): con las dos copias metidas en el mazo,
    // la carta pasa de «2 libres» a «2 en uso» sin salir de la colección.
    const linea = {
      id: 1,
      quantity: 2,
      finish: 'normal',
      language: 'English',
      condition: 'NM',
      priceEur: 1
    }

    const { wrapper: libres } = await montarBuscador({
      deck: { indiceColeccion: { [CARTA.uuid]: [linea] } }
    })

    expect(fila(libres).principal.find('.resultado__tenencia').text()).toBe('2 libres')

    const { wrapper: enUso } = await montarBuscador({
      deck: {
        indiceColeccion: { [CARTA.uuid]: [linea] },
        cartas: [
          {
            id: 9,
            printingUuid: CARTA.uuid,
            board: 'main',
            finish: 'normal',
            language: 'English',
            condition: 'NM',
            count: 2
          }
        ]
      }
    })

    expect(fila(enUso).principal.find('.resultado__tenencia').text()).toBe('2 en uso')

    const { wrapper: ninguna } = await montarBuscador()

    expect(fila(ninguna).principal.find('.resultado__tenencia').text()).toBe('no la tienes')
  })

  it('si la colección no cupo entera, el buscador lo avisa en vez de mentir', async () => {
    // `indiceParcial` lo pone el store al cortar por el tope de páginas
    // (`stores/deck.js:560`): el clic sigue funcionando, pero con los valores
    // por defecto, y eso se dice en voz alta.
    const { wrapper } = await montarBuscador({ deck: { indiceParcial: true } })

    expect(wrapper.find('.buscador__nota').exists()).toBe(true)
  })
})

describe('un clic sobre la fila es una carta en el mazo', () => {
  it('manda el alta con el uuid y la zona elegida, y deja la versión al store', async () => {
    const { wrapper } = await montarBuscador()

    await fila(wrapper).principal.trigger('click')
    await flushPromises()

    // Sin nada en la colección no hay nada que repartir: se pide `count: 1` y
    // los valores por defecto los pone el backend (`stores/deck.js:602-633`).
    expect(apiCall).toHaveBeenCalledWith('deck_card_add', {
      deck_id: MAZO.id,
      printing_uuid: CARTA.uuid,
      board: 'main',
      count: 1
    })
  })

  it('la zona del selector de arriba es la que viaja en el alta', async () => {
    const { wrapper } = await montarBuscador({ props: { zonaPorDefecto: 'sideboard' } })

    await fila(wrapper).principal.trigger('click')
    await flushPromises()

    expect(apiCall).toHaveBeenCalledWith(
      'deck_card_add',
      expect.objectContaining({ board: 'sideboard' })
    )
  })

  it('con un alta en vuelo la fila y su botón de opciones quedan apagados', async () => {
    // `estaAnadiendo` indexa por `printing_uuid`, que es lo único que hay antes
    // de que la línea exista (`stores/deck.js:153`).
    const { wrapper } = await montarBuscador({ deck: { anadiendo: [CARTA.uuid] } })

    expect(fila(wrapper).principal.attributes('disabled')).toBeDefined()
    expect(fila(wrapper).opciones.attributes('disabled')).toBeDefined()
  })
})

describe('teclear en el campo', () => {
  it('no busca por cada letra: espera a que pares (300 ms)', async () => {
    vi.useFakeTimers()

    try {
      const { wrapper } = await montarBuscador({ deck: { resultados: [] } })

      await wrapper.find('input').setValue('sol ring')

      // Aún nada: el retardo es lo que evita una petición por pulsación
      // (`DeckCardSearch.vue:233`).
      expect(catalogGet).not.toHaveBeenCalled()

      vi.advanceTimersByTime(300)
      await flushPromises()

      expect(catalogGet).toHaveBeenCalledWith('/cards', {
        q: 'sol ring',
        limit: expect.any(Number),
        sort: 'relevance'
      })
    } finally {
      vi.useRealTimers()
    }
  })
})

describe('los acabados que se ofrecen — el contrato, no los alias del SQL', () => {
  it('con finishes.etched: false en la fixtura, el desplegable NO ofrece etched', async () => {
    // EL TEST DEL HITO. La fixtura es la respuesta capturada de `/cards` y sus
    // 60 items traen `etched: false`; con `carta.hasEtched` (el alias del SQL,
    // que no existe en el contrato) las tres claves salían `undefined`, el
    // filtro no dejaba ninguna y el fallback ofrecía los tres acabados — entre
    // ellos uno que esa carta nunca tuvo.
    expect(CARTA.finishes).toEqual({ foil: true, nonfoil: true, etched: false })
    expect(ITEMS.filter((c) => c.finishes.etched)).toHaveLength(0)
    expect(CARTA.hasEtched).toBeUndefined()

    const { wrapper } = await montarBuscador({ attachTo: document.body })

    expect(await acabadosOfrecidos(wrapper)).toEqual(['Normal', 'Foil'])
  })

  it('sin datos de acabado, o con ninguno marcado, se ofrecen los tres', async () => {
    // Un desplegable vacío impediría añadir la carta, que es peor que ofrecer
    // de más (`DeckCardSearch.vue:268-270`), y es el mismo criterio que
    // `AddToCollectionButton.vue:173-175`.
    expect(ACABADOS.map((a) => a.value)).toEqual(['normal', 'foil', 'etched'])

    const ninguno = fixtura(CARTA)

    ninguno.finishes = { foil: false, nonfoil: false, etched: false }

    const { wrapper } = await montarBuscador({
      attachTo: document.body,
      deck: { resultados: [ninguno] }
    })

    expect(await acabadosOfrecidos(wrapper)).toEqual(['Normal', 'Foil', 'Etched'])
  })

  it('si el acabado por defecto no existe para esa carta, abre en el primero que sí', async () => {
    const soloFoil = fixtura(CARTA)

    soloFoil.finishes = { foil: true, nonfoil: false, etched: false }

    const { wrapper } = await montarBuscador({
      attachTo: document.body,
      deck: { resultados: [soloFoil] }
    })

    await abrirOpciones(wrapper)

    expect(panel().querySelector('.p-select-label').textContent.trim()).toBe('Foil')

    panel().querySelector('.opciones__confirmar').click()
    await flushPromises()

    expect(apiCall).toHaveBeenCalledWith(
      'deck_card_add',
      expect.objectContaining({ finish: 'foil', printing_uuid: soloFoil.uuid })
    )
  })
})

describe('el precio de la fila — también del contrato', () => {
  it('lee priceEur: con precio lo pinta en euros, y sin precio lo dice', async () => {
    // Las 60 cartas capturadas vienen con `priceEur.normal: null` (el catálogo
    // de dev no tiene precios sincronizados), así que el caso CON precio se
    // construye en memoria sobre la fixtura, con la forma real del contrato
    // (`MySqlCardRepository.php:464`). Sin esto el fallo de precios no sería
    // demostrable: «sin precio» saldría igual con el código bueno y con el malo.
    expect(CARTA.priceEur).toEqual({ normal: null, foil: null, etched: null })
    expect(CARTA.priceNormal).toBeUndefined()

    const { wrapper: sinPrecio } = await montarBuscador()

    expect(fila(sinPrecio).principal.find('.resultado__meta').text()).toContain('sin precio')

    const conPrecio = fixtura(CARTA)

    conPrecio.priceEur = { normal: 4.5, foil: null, etched: null }

    const { wrapper } = await montarBuscador({ deck: { resultados: [conPrecio] } })

    expect(fila(wrapper).principal.find('.resultado__meta').text()).toContain('4.50 €')
  })

  it('sin precio normal se cae al del foil antes que rendirse', async () => {
    const soloFoil = fixtura(CARTA)

    soloFoil.priceEur = { normal: null, foil: 12, etched: null }

    const { wrapper } = await montarBuscador({ deck: { resultados: [soloFoil] } })

    expect(fila(wrapper).principal.find('.resultado__meta').text()).toContain('12.00 €')
  })
})

describe('ver más resultados', () => {
  it('el botón solo sale si hay cursor, y pide la página siguiente con él', async () => {
    const { wrapper: sinCursor } = await montarBuscador({ deck: { cursorResultados: null } })

    expect(sinCursor.find('.buscador__mas').exists()).toBe(false)

    const { wrapper } = await montarBuscador({
      deck: { consulta: 'sol', cursorResultados: catalogoFixture.nextCursor }
    })

    expect(wrapper.find('.buscador__mas').exists()).toBe(true)

    await wrapper.find('.buscador__mas button').trigger('click')
    await flushPromises()

    expect(catalogGet).toHaveBeenCalledWith(
      '/cards',
      expect.objectContaining({ cursor: catalogoFixture.nextCursor })
    )
  })
})
