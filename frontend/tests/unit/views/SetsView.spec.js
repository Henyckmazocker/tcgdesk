import { beforeEach, describe, expect, it, vi } from 'vitest'
import { nextTick } from 'vue'
import { flushPromises } from '@vue/test-utils'

import SetsView from '@/views/SetsView.vue'
import { apiCall } from '@/services/api'

import { SESION, montarVista } from '../../helpers'

import progresoFixture from '../../fixtures/collection_sets.json'
import deseosFixture from '../../fixtures/collection_sets_wishlist.json'

/**
 * `views/SetsView.vue` — cuánto llevas de cada edición.
 *
 * Tres cosas que decide esta vista, y son las tres que se miran:
 *
 *  - **El porcentaje no se calcula aquí.** Lo da el backend
 *    (`COUNT(DISTINCT printing) / total_set_size`) y la vista enseña el
 *    numerador y el denominador al lado para que se pueda comprobar de un
 *    vistazo. Por eso las cifras de este fichero salen de la fixtura capturada.
 *  - **`total_set_size` es NULLable y sin él NO hay porcentaje.** No se dibuja
 *    una barra vacía —que se leería como un 0 %— sino que se dice que no se sabe
 *    de cuántas cartas consta la edición (`SetsView.vue:43-56,116-119`).
 *  - **La barra no se sale de su carril.** Una edición puede declarar menos
 *    cartas de las que el catálogo le cuenta: el número se enseña tal cual, el
 *    ancho se queda en el 100 % (`SetsView.vue:121-128`).
 */

vi.mock('@/services/api', () => ({
  apiCall: vi.fn(),
  catalogGet: vi.fn()
}))

/** Copia profunda: el store muta lo que recibe y las fixturas son de todos. */
function fixtura(json) {
  return structuredClone(json)
}

/** El mismo formateador que usa la vista: nada de euros escritos a mano. */
const EUROS = new Intl.NumberFormat('es-ES', {
  style: 'currency',
  currency: 'EUR',
  minimumFractionDigits: 2,
  maximumFractionDigits: 2
})

async function montarSets(respuesta = fixtura(progresoFixture)) {
  apiCall.mockResolvedValue(respuesta)

  const montaje = await montarVista(SetsView, { ruta: '/sets', estado: SESION })

  await flushPromises()
  await nextTick()

  return montaje
}

/**
 * Monta y conmuta a **quiero**, que es el modo que M4 añadió.
 *
 * El conmutador es un `SelectButton` y sus opciones son `<button>`: se pulsa
 * por su texto y no por posición, que es lo único que sobrevive a que mañana se
 * añada otro botón a esa barra.
 */
async function montarDeseos(respuesta = fixtura(deseosFixture)) {
  const montaje = await montarSets()

  apiCall.mockResolvedValue(respuesta)

  const quiero = montaje.wrapper.findAll('.sets__modo button')
    .find((b) => b.text() === 'Quiero')

  await quiero.trigger('click')
  await flushPromises()
  await nextTick()

  return montaje
}

beforeEach(() => {
  apiCall.mockReset()
})

describe('el primer render', () => {
  it('monta sin lanzar y pinta una fila por edición empezada', async () => {
    const { wrapper, errores } = await montarSets()

    const datos = fixtura(progresoFixture).data

    expect(errores).toEqual([])
    expect(apiCall).toHaveBeenCalledWith('collection_sets')

    // Solo las ediciones en las que hay algo: las 868 del catálogo con un 0 %
    // serían ruido, y el recuento de la cabecera ya da esa escala.
    expect(wrapper.findAll('.set')).toHaveLength(datos.sets.length)
    expect(datos.sets.length).toBeLessThan(datos.totals.catalogSets)
  })

  it('el resumen da la escala: «empezadas de 868», y no un recuento suelto', async () => {
    const { wrapper } = await montarSets()

    const totales = fixtura(progresoFixture).data.totals
    const resumen = wrapper.find('.sets__resumen').text()

    // «42 de 868» es lo que hace legible el progreso: 42 ediciones a secas no
    // dicen si vas empezando o si te falta poco (`SetsView.vue:18-19`).
    expect(resumen).toContain(`${totales.startedSets}`)
    expect(resumen).toContain(`de ${totales.catalogSets} ediciones empezadas`)
    expect(resumen).toContain(`${totales.ownedPrintings}`)
    expect(resumen).toContain(`${totales.completedSets}`)
  })

  it('cada edición enlaza a la colección YA filtrada por su código', async () => {
    const { wrapper, router } = await montarSets()

    const primera = fixtura(progresoFixture).data.sets[0]
    const fila = wrapper.findAll('.set')[0]

    expect(fila.find('.set__nombre').text()).toContain(primera.setName)
    expect(fila.find('.set__codigo').text()).toBe(primera.setCode)

    // El enlace tiene que ser RESOLUBLE contra las rutas reales y llevar el
    // filtro puesto: es el camino de «¿qué me falta de esta edición?».
    const href = fila.find('.set__nombre').attributes('href')
    const destino = router.resolve(href.replace(/^#/, ''))

    expect(destino.name).toBe('collection')
    expect(destino.query.set).toBe(primera.setCode)
  })

  it('el numerador, el denominador y el valor salen del backend tal cual', async () => {
    const { wrapper } = await montarSets()

    const primera = fixtura(progresoFixture).data.sets[0]
    const fila = wrapper.findAll('.set')[0]

    expect(fila.find('.set__porcentaje').text())
      .toBe(`${String(primera.percent).replace('.', ',')} %`)
    expect(fila.find('.set__pie').text())
      .toContain(`${primera.ownedPrintings} de ${primera.totalSetSize} cartas`)
    expect(fila.find('.set__pie').text()).toContain(`${primera.copies} ejemplares`)
    expect(fila.find('.set__valor').text()).toBe(EUROS.format(primera.valueEur))
    expect(fila.find('.set__relleno').attributes('style')).toContain(`width: ${primera.percent}%`)
  })
})

describe('los casos raros del tamaño de edición', () => {
  it('sin tamaño declarado se dice, y no se dibuja una barra que sería un 0 %', async () => {
    // `mtg_set.total_set_size` es NULLable: sin tamaño no se puede dividir. Se
    // altera la fixtura EN MEMORIA con la forma real del backend —`percent` y
    // `totalSetSize` a null—, que es lo que manda `collection_sets`.
    const progreso = fixtura(progresoFixture)

    progreso.data.sets[0].percent = null
    progreso.data.sets[0].totalSetSize = null

    const { wrapper } = await montarSets(progreso)

    const fila = wrapper.findAll('.set')[0]

    expect(fila.find('.set__porcentaje').text()).toBe('sin tamaño')
    expect(fila.find('.set__relleno').exists()).toBe(false)
    expect(fila.find('.set__pista--desconocida').exists()).toBe(true)
    expect(fila.find('.set__sin-tamano').text())
      .toContain('esta edición no declara cuántas tiene')
  })

  it('un porcentaje por encima de 100 se enseña entero, pero la barra se para ahí', async () => {
    // Pasa de verdad: una edición puede declarar menos cartas de las que el
    // catálogo le cuenta. El número es el dato; la barra es el dibujo.
    const progreso = fixtura(progresoFixture)

    progreso.data.sets[0].percent = 140

    const { wrapper } = await montarSets(progreso)

    const fila = wrapper.findAll('.set')[0]

    expect(fila.find('.set__porcentaje').text()).toBe('140 %')
    expect(fila.find('.set__relleno').attributes('style')).toContain('width: 100%')
  })

  it('una edición completa se marca, sin dejar de enseñar sus cifras', async () => {
    const progreso = fixtura(progresoFixture)

    progreso.data.sets[0].complete = true
    progreso.data.sets[0].percent = 100

    const { wrapper } = await montarSets(progreso)

    expect(wrapper.findAll('.set')[0].find('.set__relleno--completo').exists()).toBe(true)
  })
})

describe('cuando no hay nada que enseñar', () => {
  it('sin ninguna edición sale el vacío con el camino al catálogo', async () => {
    const progreso = fixtura(progresoFixture)

    progreso.data.sets = []

    const { wrapper } = await montarSets(progreso)

    expect(wrapper.find('.sets__vacio').text()).toContain('Todavía no tienes cartas')
    expect(wrapper.findAll('.set')).toHaveLength(0)
  })

  it('un error del backend se dice y no tumba la pantalla', async () => {
    const { wrapper, errores } = await montarSets({
      status: 'error',
      message: 'No autorizado.',
      http_code: 401
    })

    expect(errores).toEqual([])
    expect(wrapper.find('.sets__error').text()).toContain('No autorizado.')
    expect(wrapper.find('.sets__vacio').exists()).toBe(true)
  })
})

/**
 * M4 — el conmutador **tengo / quiero**.
 *
 * Lo que decide esta mitad de la pantalla cabe en una frase del plan, y es el
 * `*Hecho cuando:*` del hito: **en modo deseos no se pinta ningún porcentaje**.
 * El backend lo manda igual —`collection_sets` divide sin saber de qué conjunto
 * habla, y la fixtura capturada lo demuestra—, así que la decisión es de la
 * vista y aquí es donde se fija.
 */
describe('el conmutador tengo / quiero', () => {
  it('arranca en «tengo» y pide la colección, sin ninguna bandera', async () => {
    const { wrapper } = await montarSets()

    expect(apiCall).toHaveBeenCalledWith('collection_sets')
    expect(wrapper.find('.sets__modo').exists()).toBe(true)
    expect(wrapper.findAll('.set__porcentaje').length).toBeGreaterThan(0)
  })

  it('conmutar a «quiero» pide `collection_sets` con `is_wishlist: true`', async () => {
    const { wrapper } = await montarDeseos()

    expect(apiCall).toHaveBeenLastCalledWith('collection_sets', { is_wishlist: true })
    expect(wrapper.findAll('.set')).toHaveLength(fixtura(deseosFixture).data.sets.length)
  })

  it('el modo deseos lee el STORE DE DESEOS, no una bandera en el de colección', async () => {
    const { pinia } = await montarDeseos()

    // Dos singletons con los mismos nombres de estado. Si esto viviera en el
    // store de colección, volver a `/` enseñaría como tuyo lo que solo quieres.
    expect(pinia.state.value.wishlist.progreso).not.toBeNull()
    expect(pinia.state.value.collection.progreso.sets)
      .toHaveLength(fixtura(progresoFixture).data.sets.length)
  })

  it('EL HITO: en modo deseos no se pinta NINGÚN porcentaje', async () => {
    const { wrapper } = await montarDeseos()

    // Primero, que la fixtura traiga porcentajes de verdad: si el backend no
    // los mandara, este test no estaría probando nada.
    const sets = fixtura(deseosFixture).data.sets

    expect(sets.every((s) => s.percent !== null)).toBe(true)
    expect(sets[0].percent).toBeGreaterThan(0)

    // Ni la cifra…
    expect(wrapper.findAll('.set__porcentaje')).toHaveLength(0)
    // …ni la barra, que es el mismo porcentaje dibujado de lado…
    expect(wrapper.findAll('.set__pista')).toHaveLength(0)
    expect(wrapper.findAll('.set__relleno')).toHaveLength(0)
    // …ni un «de 1.067 cartas», que también se lee como avance…
    expect(wrapper.text()).not.toContain(`de ${sets[0].totalSetSize} cartas`)
    // …ni un solo símbolo de porcentaje en toda la pantalla.
    expect(wrapper.text()).not.toContain('%')
  })

  it('lo que ocupa el sitio del porcentaje es cuántas quieres', async () => {
    const { wrapper } = await montarDeseos()

    const primera = fixtura(deseosFixture).data.sets[0]
    const fila = wrapper.findAll('.set')[0]

    expect(fila.find('.set__cuantas').text()).toBe(`${primera.copies} ejemplares`)
    expect(fila.find('.set__pie').text())
      .toContain(`${primera.ownedPrintings} cartas distintas`)
    expect(fila.find('.set__valor').text()).toBe(EUROS.format(primera.valueEur))
  })

  it('el resumen deja de hablar de ediciones «empezadas» y «completas»', async () => {
    const { wrapper } = await montarDeseos()

    const totales = fixtura(deseosFixture).data.totals
    const resumen = wrapper.find('.sets__resumen').text()

    // «5 de 868 empezadas» sobre una lista de la compra leería un antojo como
    // un avance, y «completas» daría por terminada una edición de la que solo
    // quieres una carta.
    expect(resumen).not.toContain('empezadas')
    expect(resumen).not.toContain('completas')
    expect(resumen).not.toContain(String(totales.catalogSets))
    expect(resumen).toContain(`${totales.startedSets} ediciones en tu lista`)
    expect(resumen).toContain(`${totales.totalCopies} ejemplares`)
  })

  it('en modo deseos cada edición enlaza a `/wishlist` YA filtrada', async () => {
    const { wrapper, router } = await montarDeseos()

    const primera = fixtura(deseosFixture).data.sets[0]
    const href = wrapper.findAll('.set')[0].find('.set__nombre').attributes('href')
    const destino = router.resolve(href.replace(/^#/, ''))

    // Con el destino en `collection`, pulsar una edición de tu lista de deseos
    // te llevaría a lo que YA tienes de ella: el camino contrario al que pide
    // la pantalla.
    expect(destino.name).toBe('wishlist')
    expect(destino.query.set).toBe(primera.setCode)
  })

  it('volver a «tengo» devuelve el porcentaje y vuelve a pedir la colección', async () => {
    const { wrapper } = await montarDeseos()

    apiCall.mockResolvedValue(fixtura(progresoFixture))

    const tengo = wrapper.findAll('.sets__modo button').find((b) => b.text() === 'Tengo')

    await tengo.trigger('click')
    await flushPromises()
    await nextTick()

    expect(apiCall).toHaveBeenLastCalledWith('collection_sets')
    expect(wrapper.findAll('.set__porcentaje').length).toBeGreaterThan(0)
    expect(wrapper.findAll('.set__cuantas')).toHaveLength(0)
  })

  it('la lista de deseos vacía lo dice con sus palabras', async () => {
    const vacio = fixtura(deseosFixture)

    vacio.data.sets = []

    const { wrapper } = await montarDeseos(vacio)

    expect(wrapper.find('.sets__vacio').text()).toContain('no quieres ninguna carta')
  })

  it('un error en modo deseos se dice, y no es el de la colección', async () => {
    const { wrapper, errores } = await montarDeseos({
      status: 'error',
      message: 'No se pudo cargar lo que quieres.',
      http_code: 500
    })

    expect(errores).toEqual([])
    expect(wrapper.find('.sets__error').text()).toContain('No se pudo cargar lo que quieres.')
  })
})
