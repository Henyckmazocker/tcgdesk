import { beforeEach, describe, expect, it, vi } from 'vitest'
import { nextTick } from 'vue'
import { flushPromises } from '@vue/test-utils'

import PreconView from '@/views/PreconView.vue'
import { apiCall, catalogGet } from '@/services/api'
import { ZONAS } from '@/constants/collection'
import { useWishlistStore } from '@/stores/wishlist'

import { SESION, montarVista } from '../../helpers'

import preconFixture from '../../fixtures/catalog_deck.json'
import importFixture from '../../fixtures/precon_add_to_collection.json'
import deseosFixture from '../../fixtures/precon_add_to_collection_deseos.json'

/**
 * `views/PreconView.vue` — 558 líneas, la otra vista **que nadie había visto
 * renderizar**. Mismo caso que `PreconsView` y mismo motivo para estar en este
 * hito: se entregó el 2026-09-12 validada por contrato, `curl`, SQL y build, y
 * por render, nada.
 *
 * Lo que se mira es lo que esta vista decide, que no es ninguno de los números:
 *
 *  - **No calcula precios ni valor.** `priceEur`, `lineValue` y `valueEur` los
 *    da el backend uniendo por `(printing_uuid, finish)`; aquí solo se formatean
 *    (`PreconView.vue:288-290`). Por eso las cifras de este fichero se leen de la
 *    fixtura capturada en vez de escribirse a mano.
 *  - **Agrupa por zona en el orden del ENUM** y esconde las zonas vacías
 *    (`PreconView.vue:270-284`), con los tokens marcados como lo que son: no
 *    cuentan ni para el tamaño ni para el valor.
 *  - **El botón de un clic**, que es una sola acción `precon_add_to_collection`
 *    y, al lado, el aviso que dice en voz alta lo que se asume —`English` y
 *    `NM`—, porque MTGJSON no publica ni idioma ni condición y el plan exige
 *    decirlo antes de pulsar, no después.
 *
 * **La red no se toca**: la frontera de mock es `@/services/api`, así que el
 * botón se cubre entero sin llamar al backend de verdad.
 */

/**
 * Se doblan las DOS funciones de I/O y nada más. El resto del módulo se
 * conserva porque `CardImage` —que pinta cada miniatura de la tabla— compone la
 * URL con `API_BASE` (`services/scryfall.js:23,42`): un doble sin esa constante
 * deja `undefined` en cada `<img>` y el render se llena de errores que no son
 * de esta vista.
 */
vi.mock('@/services/api', async (importarOriginal) => ({
  ...(await importarOriginal()),
  catalogGet: vi.fn(),
  apiCall: vi.fn()
}))

/** El precon capturado del backend real: `RidersOfRohan_LTC`, 100 cartas. */
const FICHERO = preconFixture.precon.fileName

/** Copia profunda: el store muta lo que recibe y las fixturas son de todos. */
function fixtura(json) {
  return structuredClone(json)
}

/** Monta la ficha y espera a que `catalogGet` haya llegado y pintado. */
async function montarPrecon(ficha = fixtura(preconFixture)) {
  catalogGet.mockResolvedValue(ficha)

  const montaje = await montarVista(PreconView, {
    ruta: `/precon/${FICHERO}`,
    estado: SESION
  })

  await flushPromises()
  await nextTick()

  return montaje
}

/** Los ejemplares de una zona, sumando `count` como hace `ejemplaresDe()`. */
function ejemplares(ficha, zona) {
  return (ficha.boards[zona] ?? []).reduce((suma, carta) => suma + carta.count, 0)
}

beforeEach(() => {
  catalogGet.mockReset()
  apiCall.mockReset()
})

describe('el primer render', () => {
  /**
   * ESTE ES EL TEST NOMBRADO POR EL PLAN, literal. Cubre el botón de
   * `precon_add_to_collection` **sin llamarlo de verdad**: se comprueba que está
   * en pantalla, utilizable y con su aviso, y que nada ha salido por la red.
   */
  it('pinta las zonas del precon, su valor total y el botón de meterlo en la colección', async () => {
    const ficha = fixtura(preconFixture)
    const { wrapper, errores } = await montarPrecon(ficha)

    expect(errores).toEqual([])

    // La ficha se pide por `fileName` —clave natural de `mtg_precon`—, por la
    // ruta GET del catálogo y no por el endpoint único (`precons.js:200`).
    expect(catalogGet).toHaveBeenCalledWith(`/decks/${FICHERO}`)
    expect(wrapper.find('.precon__titulo').text()).toBe(ficha.precon.name)

    // LAS ZONAS: en el orden del ENUM y solo las que traen cartas. La capturada
    // tiene comandante y principal, así que salen dos y en ese orden.
    const conCartas = ZONAS.filter((zona) => (ficha.boards[zona.value] ?? []).length > 0)
    const zonasPintadas = wrapper.findAll('.zona')

    expect(conCartas.map((z) => z.value)).toEqual(['main', 'commander'])
    expect(zonasPintadas).toHaveLength(conCartas.length)

    zonasPintadas.forEach((seccion, i) => {
      const titulo = seccion.find('.zona__titulo').text()

      expect(titulo).toContain(conCartas[i].label)
      expect(titulo).toContain(`${ejemplares(ficha, conCartas[i].value)} carta(s)`)
      // Una fila por línea de la zona: la tabla pinta lo que vino, sin agrupar.
      expect(seccion.findAll('tbody tr')).toHaveLength(ficha.boards[conCartas[i].value].length)
    })

    // EL VALOR TOTAL y el recuento, los dos del backend y ya sin tokens.
    const cifras = wrapper.find('.cabecera__cifras').text()

    expect(cifras).toContain(String(ficha.cardsTotal))
    expect(cifras).toContain(`${ficha.valueEur.toFixed(2)} €`)

    // EL BOTÓN DE UN CLIC: existe, está utilizable y NO se ha llamado a nadie.
    const boton = wrapper.find('.caja__accion button')

    expect(boton.exists()).toBe(true)
    expect(boton.text()).toContain('Meter la caja en mi colección')
    expect(boton.attributes('disabled')).toBeUndefined()
    expect(apiCall).not.toHaveBeenCalled()

    // Y su aviso, que no es decorativo: MTGJSON no publica ni idioma ni estado,
    // así que el backend los da por English/NM y la UI tiene que decirlo ANTES
    // de pulsar (`PreconView.vue:55-64`).
    const asuncion = wrapper.find('.caja__asuncion').text()

    expect(asuncion).toContain(`${ficha.cardsTotal} ejemplares`)
    expect(asuncion).toContain('como English y en estado NM')
    expect(asuncion).toContain('construido')
  })

  it('una carta que el catálogo no conoce se lista igual, y se avisa de que el valor sale corto', async () => {
    // 254 filas de `mtg_precon_card` apuntan a un printing que todavía no está
    // en `mtg_printing`: MTGJSON publica las cajas antes que las cartas. No es
    // un error y no se esconde. La capturada no tiene ninguna (`unknownPrintings
    // = 0`), así que el caso se construye EN MEMORIA sobre ella, con la forma
    // real del backend: `known: false` y sin nombre ni precio.
    const ficha = fixtura(preconFixture)
    const huerfana = ficha.boards.main[0]

    huerfana.known = false
    huerfana.name = null
    huerfana.priceEur = null
    huerfana.lineValue = 0
    ficha.unknownPrintings = 1

    const { wrapper } = await montarPrecon(ficha)

    expect(wrapper.find('.aviso__titulo').text()).toContain('1 línea(s) de este mazo no están')
    expect(wrapper.find('.zona__desconocida').text()).toBe('Carta no importada todavía')
    // Sin enlace a `/card/<uuid>`: el catálogo no tiene esa ficha y llevaría a
    // una pantalla en blanco (`PreconView.vue:156-160`).
    expect(wrapper.find('.zona__desconocida').element.closest('a')).toBeNull()
    expect(wrapper.text()).toContain('sin precio')
  })

  it('si NINGUNA carta está en el catálogo, el botón no se puede pulsar', async () => {
    // Tanto la colección como el mazo tienen FK contra `mtg_printing`: sin una
    // sola línea conocida el backend no podría escribir nada, así que no se
    // ofrece un botón que no puede hacer su trabajo (`PreconView.vue:252-254`).
    const ficha = fixtura(preconFixture)

    ficha.cards.forEach((carta) => { carta.known = false })

    const { wrapper } = await montarPrecon(ficha)

    expect(wrapper.find('.caja__accion button').attributes('disabled')).toBeDefined()
    expect(wrapper.find('.caja__imposible').exists()).toBe(true)
  })

  it('un precon que no existe se dice con su mensaje, sin tabla ni botón', async () => {
    const { wrapper } = await montarPrecon({ error: 'precon_not_found' })

    expect(wrapper.find('.precon__error').text()).toContain('Ese precon no existe en el catálogo.')
    expect(wrapper.find('.caja').exists()).toBe(false)
    expect(wrapper.findAll('.zona')).toHaveLength(0)
  })
})

describe('el botón de meter la caja en la colección', () => {
  it('manda precon_add_to_collection con el fileName de la ruta y enseña el parte', async () => {
    const { wrapper } = await montarPrecon()

    apiCall.mockResolvedValue(fixtura(importFixture))

    await wrapper.find('.caja__accion button').trigger('click')
    await flushPromises()
    await nextTick()

    // Escribe dato de usuario, así que va por `apiCall` —sesión y token CSRF— y
    // no por `catalogGet`: aquí termina la divergencia GET del `CLAUDE.md`.
    expect(apiCall).toHaveBeenCalledWith('precon_add_to_collection', { file_name: FICHERO })

    // El parte se QUEDA en pantalla, al contrario que el aviso de «Añadir»: se
    // han escrito 100 cartas y dos tablas, y hay que poder leer los números y
    // saltar al mazo recién montado.
    const parte = wrapper.find('.caja__parte')

    expect(parte.text()).toContain(importFixture.message)
    expect(parte.find('.caja__parte-enlace').attributes('href'))
      .toBe(`#/deck/${importFixture.data.deck.id}`)
  })

  it('un 401 se traduce a «inicia sesión» y no deja parte', async () => {
    const { wrapper } = await montarPrecon()

    apiCall.mockResolvedValue({ status: 'error', message: 'No autenticado.', http_code: 401 })

    await wrapper.find('.caja__accion button').trigger('click')
    await flushPromises()
    await nextTick()

    expect(wrapper.find('.caja__error').text()).toContain('Inicia sesión para meter esta caja')
    expect(wrapper.find('.caja__parte').exists()).toBe(false)
  })
})

/**
 * M7 — el segundo botón: la caja entera a la lista de deseos.
 *
 * Es la quinta superficie de la lista de deseos, y la que se usa **antes** de
 * comprar: la lista de la compra de una caja que todavía no tienes.
 */
describe('el botón de mandar la caja a la lista de deseos', () => {
  /** El segundo botón de la caja, que es el de deseos. */
  function botonDeseos(wrapper) {
    return wrapper.findAll('.caja__accion button')[1]
  }

  it('lo ofrece al lado del otro, diciendo que el mazo nace en construcción', async () => {
    const ficha = fixtura(preconFixture)
    const { wrapper, errores } = await montarPrecon(ficha)

    expect(errores).toEqual([])

    const boton = botonDeseos(wrapper)

    expect(boton.text()).toContain('La quiero')
    expect(boton.attributes('disabled')).toBeUndefined()
    expect(apiCall).not.toHaveBeenCalled()

    // Que el mazo nazca «en construcción» y no «construido» NO es obvio y hay
    // que decirlo antes de pulsar: un mazo construido consume colección, y uno
    // hecho de cartas que solo deseas diría estar montado con cartas que no
    // tienes.
    const aviso = wrapper.findAll('.caja__asuncion')[1].text()

    expect(aviso).toContain('lista de deseos')
    expect(aviso).toContain('ninguna')
    expect(aviso).toContain('en construcción')
  })

  /**
   * **EL HITO.** Una sola petición, la misma acción con la bandera puesta, y el
   * parte llevando a `/wishlist` en vez de a `/collection`.
   */
  it('manda precon_add_to_collection con is_wishlist y lleva a /wishlist', async () => {
    const { wrapper } = await montarPrecon()

    apiCall.mockResolvedValue(fixtura(deseosFixture))

    await botonDeseos(wrapper).trigger('click')
    await flushPromises()
    await nextTick()

    expect(apiCall).toHaveBeenCalledTimes(1)
    expect(apiCall).toHaveBeenCalledWith('precon_add_to_collection', {
      file_name: FICHERO,
      is_wishlist: true
    })

    const parte = wrapper.find('.caja__parte')

    // El mensaje es el capturado del backend: dice «lista de deseos» y NO «en
    // tu colección», que es la única diferencia entre los dos botones.
    expect(parte.text()).toContain(deseosFixture.message)
    expect(parte.text()).toContain('lista de deseos')

    const enlaces = parte.findAll('.caja__parte-enlace')

    expect(enlaces[0].attributes('href')).toBe('#/wishlist')
    expect(enlaces[1].attributes('href')).toBe(`#/deck/${deseosFixture.data.deck.id}`)
  })

  /**
   * La mitad de M6 que esta pantalla podría romper: la escritura no pasa por el
   * store de deseos —la hace el de precons, porque la caja escribe además el
   * mazo—, así que el `Set` del corazón hay que moverlo aquí o el catálogo
   * pintaría en contorno cartas que ya están deseadas.
   */
  it('rellena el corazón de sus cartas SIN pedir nada más', async () => {
    const ficha = fixtura(preconFixture)
    const { wrapper } = await montarPrecon(ficha)
    const deseos = useWishlistStore()

    expect(deseos.esDeseada(ficha.cards[0].printingUuid)).toBe(false)

    apiCall.mockResolvedValue(fixtura(deseosFixture))

    await botonDeseos(wrapper).trigger('click')
    await flushPromises()

    // Ni un `collection_wished_uuids` de refresco: si hubiera que esperar a otra
    // petición, el corazón mentiría durante ese segundo.
    expect(apiCall).toHaveBeenCalledTimes(1)

    for (const carta of ficha.cards) {
      expect(deseos.esDeseada(carta.printingUuid)).toBe(true)
    }
  })

  it('si el backend dice que no, el corazón NO se rellena', async () => {
    const ficha = fixtura(preconFixture)
    const { wrapper } = await montarPrecon(ficha)
    const deseos = useWishlistStore()

    apiCall.mockResolvedValue({ status: 'error', message: 'No autenticado.', http_code: 401 })

    await botonDeseos(wrapper).trigger('click')
    await flushPromises()
    await nextTick()

    expect(wrapper.find('.caja__error').text()).toContain('lista de deseos')
    expect(deseos.deseados.size).toBe(0)
  })
})
