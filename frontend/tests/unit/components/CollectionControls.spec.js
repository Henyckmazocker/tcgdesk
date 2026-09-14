import { beforeEach, describe, expect, it, vi } from 'vitest'
import { nextTick } from 'vue'
import { flushPromises } from '@vue/test-utils'
import InputNumber from 'primevue/inputnumber'
import Select from 'primevue/select'

import CollectionControls from '@/components/CollectionControls.vue'
import { apiCall } from '@/services/api'

import { SESION, montarVista } from '../../helpers'

import coleccionFixture from '../../fixtures/collection_list.json'
import cantidadFixture from '../../fixtures/collection_update_quantity.json'
import gradoFixture from '../../fixtures/collection_change_grade.json'
import quitarFixture from '../../fixtures/collection_remove.json'

/**
 * `components/CollectionControls.vue` — la edición en línea de una carta de la
 * colección: cantidad, estado físico y quitar.
 *
 * Tres reglas, y ninguna es de adorno:
 *
 *  - **El commit va en `blur` y en `enter`, no en cada pulsación**: escribir «12»
 *    pasaría por el 1 y guardaría un 1 (`CollectionControls.vue:5-8`).
 *  - **Cero borra la fila** y la carta desaparece de la lista: el backend no deja
 *    filas a 0 y la vista no debe enseñar una que ya no existe.
 *  - **Cambiar el estado NO es un update normal.** `condition_grade` está dentro
 *    del `UNIQUE KEY uq_item`, así que el backend puede haber **fundido** esta
 *    línea con otra que ya tenías en ese estado, y entonces esta desaparece
 *    sumada a la otra. Por eso el store mira el `id` que vuelve y no asume que
 *    sea el que mandó (`stores/collection.js:296-337`).
 *
 * **Ojo al copiar hacia los mazos**: aquí `estaGuardando` indexa por `item.id`
 * de línea de COLECCIÓN; el getter homónimo de `stores/deck.js` indexa por el id
 * de línea de mazo.
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

/** Una línea de colección de verdad, capturada del backend. */
const ITEM = coleccionFixture.data.items[0]

function respondeSegunAccion(mapa) {
  apiCall.mockImplementation((accion) =>
    Promise.resolve(
      mapa[accion] ?? { status: 'error', message: `Sin doble para ${accion}.`, http_code: 500 }
    )
  )
}

async function montarControles({ item, compacto = false, guardando = [] } = {}) {
  const linea = item ?? { ...fixtura(ITEM), quantity: 2 }

  const montaje = await montarVista(CollectionControls, {
    ruta: '/collection',
    estado: { ...SESION, collection: { items: [linea], guardando } },
    props: { item: linea, compacto }
  })

  await nextTick()

  return { ...montaje, item: linea }
}

function partes(wrapper) {
  const botones = wrapper.findAll('button')

  return { menos: botones[0], mas: botones[1], campo: wrapper.find('input') }
}

beforeEach(() => {
  apiCall.mockReset()
  respondeSegunAccion({
    collection_update_quantity: fixtura(cantidadFixture),
    collection_change_grade: fixtura(gradoFixture),
    collection_remove: fixtura(quitarFixture)
  })
})

describe('lo que se ve', () => {
  it('arranca en la cantidad de la línea, con el estado puesto y todo nombrado', async () => {
    const { wrapper, item, errores } = await montarControles()

    const { menos, mas, campo } = partes(wrapper)

    expect(errores).toEqual([])
    expect(campo.element.value).toBe('2')
    expect(menos.attributes('aria-label')).toBe(`Quitar un ejemplar de ${item.name}`)
    expect(mas.attributes('aria-label')).toBe(`Añadir un ejemplar de ${item.name}`)
    // El `Select` de PrimeVue cuelga el `aria-label` del elemento interno que
    // recibe el foco, no de su raíz: se busca por el atributo, no por la clase.
    expect(wrapper.find(`[aria-label="Estado de ${item.name}"]`).exists()).toBe(true)
    expect(wrapper.find('.controles__estado').text()).toBe('Near Mint')
  })

  it('en modo compacto no cabe la papelera: se va a la rejilla sin ella', async () => {
    const { wrapper } = await montarControles({ compacto: true })

    expect(wrapper.classes()).toContain('controles--compacto')
    expect(wrapper.findAll('button')).toHaveLength(2)

    const { wrapper: completo } = await montarControles()

    expect(completo.findAll('button')).toHaveLength(3)
  })

  it('con una escritura en vuelo, todo se deshabilita', async () => {
    const item = { ...fixtura(ITEM), quantity: 2 }
    const { wrapper } = await montarControles({ item, guardando: [item.id] })

    wrapper.findAll('button').forEach((boton) => {
      expect(boton.attributes('disabled')).toBeDefined()
    })
    expect(wrapper.find('input').attributes('disabled')).toBeDefined()
  })

  it('el scroll infinito recicla nodos: con otro item, el campo lo sigue', async () => {
    const { wrapper } = await montarControles()

    // Sin el `watch` de `CollectionControls.vue:94-99`, el campo seguiría
    // enseñando la cantidad de la carta anterior.
    const otro = { ...fixtura(coleccionFixture.data.items[1]), quantity: 9 }

    await wrapper.setProps({ item: otro })
    await nextTick()

    expect(wrapper.find('input').element.value).toBe('9')
  })
})

describe('la cantidad', () => {
  it('«+» manda collection_update_quantity con un ejemplar más', async () => {
    const { wrapper, item } = await montarControles()

    await partes(wrapper).mas.trigger('click')
    await flushPromises()

    expect(apiCall).toHaveBeenCalledWith('collection_update_quantity', {
      item_id: item.id,
      quantity: 3
    })
  })

  it('bajar a cero BORRA la fila y la saca de la lista, sin dejar un fantasma', async () => {
    const item = { ...fixtura(ITEM), quantity: 1 }

    // Lo que responde el backend al llegar a 0: la fixtura capturada trae
    // `removed: false`, así que se altera EN MEMORIA.
    const borrado = fixtura(cantidadFixture)

    borrado.data = { removed: true }

    respondeSegunAccion({ collection_update_quantity: borrado })

    const { wrapper, pinia } = await montarControles({ item })

    await partes(wrapper).menos.trigger('click')
    await flushPromises()

    expect(apiCall).toHaveBeenCalledWith('collection_update_quantity', {
      item_id: item.id,
      quantity: 0
    })
    expect(pinia.state.value.collection.items).toEqual([])
    expect(pinia.state.value.collection.aviso.texto)
      .toBe(`${item.name} ya no está en tu colección.`)
  })

  it('escribir a mano y salir del campo guarda UNA vez, con el valor final', async () => {
    const { wrapper, item } = await montarControles()

    wrapper.findComponent(InputNumber).vm.$emit('update:modelValue', 12)
    await nextTick()

    // Nada todavía: el commit es el `blur`, no la pulsación.
    expect(apiCall).not.toHaveBeenCalled()

    await partes(wrapper).campo.trigger('blur')
    await flushPromises()

    expect(apiCall).toHaveBeenCalledTimes(1)
    expect(apiCall).toHaveBeenCalledWith('collection_update_quantity', {
      item_id: item.id,
      quantity: 12
    })
  })

  it('si el backend falla, el campo vuelve a lo que había', async () => {
    respondeSegunAccion({
      collection_update_quantity: { status: 'error', message: 'No se pudo guardar.', http_code: 500 }
    })

    const { wrapper, pinia } = await montarControles()

    await partes(wrapper).mas.trigger('click')
    await flushPromises()
    await nextTick()

    expect(wrapper.find('input').element.value).toBe('2')
    expect(pinia.state.value.collection.aviso.tipo).toBe('error')
  })
})

describe('el estado físico', () => {
  it('elegir otro estado manda collection_change_grade', async () => {
    const { wrapper, item } = await montarControles()

    wrapper.findComponent(Select).vm.$emit('update:modelValue', 'LP')
    await flushPromises()

    expect(apiCall).toHaveBeenCalledWith('collection_change_grade', {
      item_id: item.id,
      condition: 'LP'
    })
  })

  it('si el backend FUNDE la línea con otra, se dice y la fila cambia de id', async () => {
    // El caso que hace de esto algo distinto de un update: `condition_grade` va
    // dentro del `UNIQUE KEY`, así que cambiar de estado puede mover la fila a
    // un hueco ya ocupado y fundirlas. La fixtura capturada trae
    // `merged: false`, así que el caso se construye EN MEMORIA.
    const fundida = fixtura(gradoFixture)

    fundida.data.merged = true

    respondeSegunAccion({ collection_change_grade: fundida })

    const { wrapper, item, pinia } = await montarControles()

    wrapper.findComponent(Select).vm.$emit('update:modelValue', 'LP')
    await flushPromises()

    expect(pinia.state.value.collection.aviso.texto)
      .toBe(`${item.name}: unida a la línea que ya tenías en ese estado.`)
    // La de partida ya no existe con esa clave: el id de la lista es el que
    // volvió, no el que se mandó.
    expect(pinia.state.value.collection.items.map((i) => i.id))
      .toEqual([fundida.data.item.id])
  })

  it('un estado vacío no se manda: el desplegable no puede borrar el estado', async () => {
    const { wrapper } = await montarControles()

    wrapper.findComponent(Select).vm.$emit('update:modelValue', null)
    await flushPromises()

    expect(apiCall).not.toHaveBeenCalled()
  })
})

describe('quitar la línea entera', () => {
  it('la papelera manda collection_remove y la saca de la lista', async () => {
    const { wrapper, item, pinia } = await montarControles()

    await wrapper.findAll('button')[2].trigger('click')
    await flushPromises()

    expect(apiCall).toHaveBeenCalledWith('collection_remove', { item_id: item.id })
    expect(pinia.state.value.collection.items).toEqual([])
  })
})
