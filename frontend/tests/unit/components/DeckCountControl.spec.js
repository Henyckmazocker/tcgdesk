import { beforeEach, describe, expect, it, vi } from 'vitest'
import { nextTick } from 'vue'
import { flushPromises } from '@vue/test-utils'
import InputNumber from 'primevue/inputnumber'

import DeckCountControl from '@/components/DeckCountControl.vue'
import { apiCall } from '@/services/api'

import { SESION, montarVista } from '../../helpers'

import deckGetFixture from '../../fixtures/deck_get.json'
import deckCardSetFixture from '../../fixtures/deck_card_set.json'

/**
 * `components/DeckCountControl.vue` — cuántas copias lleva una línea del mazo.
 *
 * Dos reglas, y las dos vienen de un gesto real:
 *
 *  - **El commit va en `blur` y en `enter`, nunca en cada pulsación.** Escribir
 *    «12» pasa por el 1, y guardar en cada tecla guardaría un 1 por el camino.
 *  - **Cero borra la línea**, igual que en la colección: una línea a 0 contaría
 *    como carta del mazo y falsearía el tamaño.
 *
 * **Ojo**: este componente usa `estaGuardando(carta.id)` de `stores/deck.js`,
 * que indexa por el **id de línea de mazo**. `stores/collection.js` tiene un
 * getter con el mismo nombre que indexa por `item.id` de línea de colección:
 * copiar un test de uno a otro es la forma más fácil de no probar nada.
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

/** Una línea de mazo de verdad, capturada del backend. */
const LINEA = deckGetFixture.data.cards[0]
const MAZO = deckGetFixture.data.deck

function respondeSegunAccion(mapa) {
  apiCall.mockImplementation((accion) =>
    Promise.resolve(
      mapa[accion] ?? { status: 'error', message: `Sin doble para ${accion}.`, http_code: 500 }
    )
  )
}

/**
 * Monta el control con el store sembrado como si la ficha ya estuviera cargada.
 * `deck_get` responde porque **cada escritura llama a `refrescar()`**: el valor
 * en euros y la disponibilidad los calcula el backend y no se reinventan aquí.
 */
async function montarControl({ carta = { ...fixtura(LINEA), count: 2 }, guardando = [] } = {}) {
  const montaje = await montarVista(DeckCountControl, {
    ruta: `/deck/${MAZO.id}`,
    estado: {
      ...SESION,
      deck: { mazo: fixtura(MAZO), cartas: [carta], guardando }
    },
    props: { carta }
  })

  await nextTick()

  return { ...montaje, carta }
}

/** Los tres controles, en el orden en que se pintan. */
function partes(wrapper) {
  return {
    menos: wrapper.findAll('button')[0],
    campo: wrapper.find('input'),
    mas: wrapper.findAll('button')[1]
  }
}

beforeEach(() => {
  apiCall.mockReset()
  respondeSegunAccion({
    deck_card_set: fixtura(deckCardSetFixture),
    deck_get: fixtura(deckGetFixture)
  })
})

describe('lo que se ve', () => {
  it('arranca en la cantidad de la línea y nombra la carta en cada control', async () => {
    const { wrapper, carta, errores } = await montarControl()

    const { menos, campo, mas } = partes(wrapper)

    expect(errores).toEqual([])
    expect(campo.element.value).toBe('2')
    // Los `aria-label` llevan el nombre porque en una tabla de 96 filas hay 96
    // botones «+» idénticos (`DeckCountControl.vue:9,19,28`).
    expect(menos.attributes('aria-label')).toBe(`Quitar una copia de ${carta.name}`)
    expect(campo.attributes('aria-label')).toBe(`Copias de ${carta.name} en el mazo`)
    expect(mas.attributes('aria-label')).toBe(`Añadir una copia de ${carta.name}`)
  })

  it('con una escritura en vuelo los tres controles se deshabilitan', async () => {
    // `guardando` va por id de línea de MAZO (`stores/deck.js:152`).
    const carta = { ...fixtura(LINEA), count: 2 }
    const { wrapper } = await montarControl({ carta, guardando: [carta.id] })

    const { menos, campo, mas } = partes(wrapper)

    expect(menos.attributes('disabled')).toBeDefined()
    expect(mas.attributes('disabled')).toBeDefined()
    expect(campo.attributes('disabled')).toBeDefined()
  })

  it('la fila puede venir cambiada del servidor y el borrador la sigue', async () => {
    const { wrapper } = await montarControl()

    // Una suma o una fusión cambian el `count` bajo el mismo componente: el
    // borrador tiene que seguir a lo que hay, no a lo que se tecleó
    // (`DeckCountControl.vue:62-69`).
    await wrapper.setProps({ carta: { ...fixtura(LINEA), count: 7 } })
    await nextTick()

    expect(wrapper.find('input').element.value).toBe('7')
  })
})

describe('guardar', () => {
  it('«+» manda deck_card_set con una copia más y el mazo de la ficha', async () => {
    const { wrapper, carta } = await montarControl()

    await partes(wrapper).mas.trigger('click')
    await flushPromises()

    expect(apiCall).toHaveBeenCalledWith('deck_card_set', {
      deck_id: MAZO.id,
      card_id: carta.id,
      count: 3
    })
  })

  it('«−» hasta cero BORRA la línea, en vez de dejar un fantasma a 0', async () => {
    const carta = { ...fixtura(LINEA), count: 1 }

    // Lo que responde el backend cuando la cantidad baja a 0: la fixtura
    // capturada trae `removed: false`, así que se altera EN MEMORIA.
    const borrado = fixtura(deckCardSetFixture)

    borrado.data = { removed: true }

    respondeSegunAccion({ deck_card_set: borrado, deck_get: fixtura(deckGetFixture) })

    const { wrapper, pinia } = await montarControl({ carta })

    await partes(wrapper).menos.trigger('click')
    await flushPromises()

    expect(apiCall).toHaveBeenCalledWith('deck_card_set', {
      deck_id: MAZO.id,
      card_id: carta.id,
      count: 0
    })
    expect(pinia.state.value.deck.aviso.texto).toBe(`${carta.name} ya no está en el mazo.`)
  })

  it('escribir a mano y salir del campo guarda UNA vez, con el valor final', async () => {
    const { wrapper, carta } = await montarControl()

    // El gesto real: se teclea «12» y se sale. El valor se mete por el
    // `update:modelValue` del `InputNumber` —que es lo que hace el componente de
    // PrimeVue al teclear— y el commit llega SOLO con el `blur`: si fuera por
    // pulsación, por el camino se habría guardado un 1.
    wrapper.findComponent(InputNumber).vm.$emit('update:modelValue', 12)
    await nextTick()

    expect(apiCall).not.toHaveBeenCalledWith('deck_card_set', expect.anything())

    await partes(wrapper).campo.trigger('blur')
    await flushPromises()

    const llamadas = apiCall.mock.calls.filter(([accion]) => accion === 'deck_card_set')

    expect(llamadas).toHaveLength(1)
    expect(llamadas[0][1]).toEqual({ deck_id: MAZO.id, card_id: carta.id, count: 12 })
  })

  it('salir del campo sin haberlo tocado no manda nada', async () => {
    const { wrapper } = await montarControl()

    await partes(wrapper).campo.trigger('blur')
    await flushPromises()

    expect(apiCall).not.toHaveBeenCalledWith('deck_card_set', expect.anything())
  })

  it('si el backend falla, el campo vuelve a lo que había', async () => {
    respondeSegunAccion({
      deck_card_set: { status: 'error', message: 'No se pudo guardar.', http_code: 500 },
      deck_get: fixtura(deckGetFixture)
    })

    const { wrapper, pinia } = await montarControl()

    await partes(wrapper).mas.trigger('click')
    await flushPromises()
    await nextTick()

    // Dejar el 3 en pantalla diría que hay tres copias cuando siguen siendo dos.
    expect(wrapper.find('input').element.value).toBe('2')
    expect(pinia.state.value.deck.aviso).toEqual({ tipo: 'error', texto: 'No se pudo guardar.' })
  })
})
