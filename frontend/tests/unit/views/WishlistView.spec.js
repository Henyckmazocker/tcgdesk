import { beforeEach, describe, expect, it, vi } from 'vitest'
import { nextTick } from 'vue'
import { flushPromises } from '@vue/test-utils'

import WishlistView from '@/views/WishlistView.vue'
import AddToCollectionButton from '@/components/AddToCollectionButton.vue'
import { apiCall, catalogGet } from '@/services/api'

import { SESION, montarVista } from '../../helpers'

import deseosFixture from '../../fixtures/collection_list_wishlist.json'
import cartaFixture from '../../fixtures/catalog_card.json'
import deseoFixture from '../../fixtures/collection_add_wishlist.json'
import cumplirFixture from '../../fixtures/collection_fulfill_wish.json'
import cumplirTotalFixture from '../../fixtures/collection_fulfill_wish_total.json'
import setsFixture from '../../fixtures/catalog_sets.json'
import valorFixture from '../../fixtures/collection_value_wishlist.json'

/**
 * `views/WishlistView.vue` — lo que quieres, y «ya la tengo».
 *
 * **Se parece a `CollectionView.spec.js` y ahí está la trampa**: las dos vistas
 * pintan la misma forma de dato sobre dos conjuntos excluyentes, y un test
 * copiado de allí pasaría aquí sin probar lo único que esta pantalla tiene de
 * propio. Por eso los de abajo se apoyan en lo que `/collection` no hace:
 *
 *  - **Pide `is_wishlist: true`**, siempre y desde la vista montada de verdad.
 *  - **«Ya la tengo»**, que no es un `UPDATE` sino un movimiento de fila. Su
 *    panel es un `Popover`, así que **se teletransporta al `document.body`** y
 *    hay que buscarlo allí o el test pasa en verde en falso.
 *  - **El `id` de la línea de deseo puede dejar de existir**: manda el `origen`
 *    de la respuesta. Es lo que separa el movimiento parcial (la línea se queda
 *    con menos) del total (la línea desaparece).
 *
 * Se monta con **router real y PrimeVue real**: la frontera de mock es
 * `@/services/api` y nada más.
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

/** Las instancias del doble del centinela, que deja `tests/setup.js`. */
const observadores = window.observadoresDeInterseccion

const DESEOS = fixtura(deseosFixture).data.items

/** Un tramo de la lista capturada, con la envoltura del endpoint único. */
function pagina(desde, hasta, nextCursor = null) {
  const items = fixtura(deseosFixture).data.items.slice(desde, hasta)

  return {
    status: 'success',
    message: 'Colección.',
    data: { items, nextCursor, count: items.length },
    http_code: 200
  }
}

/**
 * El doble responde por ACCIÓN y no por orden de llamada.
 *
 * Desde M4 esta vista hace **dos** peticiones al montar —la lista con
 * `is_wishlist` y su valor con el mismo flag—, y un doble que respondiera por
 * turnos se desincronizaría en cuanto cambiara el orden del `onMounted`.
 */
function respondeDeseos(respuesta = fixtura(deseosFixture), valor = fixtura(valorFixture)) {
  apiCall.mockImplementation((accion) =>
    Promise.resolve(accion === 'collection_value' ? valor : respuesta))
}

/** El formateador de euros de la vista: nada de cifras escritas a mano. */
const EUROS = new Intl.NumberFormat('es-ES', {
  style: 'currency',
  currency: 'EUR',
  minimumFractionDigits: 2,
  maximumFractionDigits: 2
})

async function montarDeseos(opciones = {}) {
  const montaje = await montarVista(WishlistView, {
    ruta: '/wishlist',
    estado: SESION,
    ...opciones
  })

  await flushPromises()
  await nextTick()

  return montaje
}

/** El panel de «ya la tengo», que NO cuelga del wrapper. */
function panelCumplir() {
  return document.body.querySelector('.cumplir__panel')
}

beforeEach(() => {
  apiCall.mockReset()
  catalogGet.mockReset()
  catalogGet.mockResolvedValue(fixtura(setsFixture))
  respondeDeseos()
})

describe('el primer render', () => {
  it('monta sin lanzar y pide DESEOS, no la colección', async () => {
    const { wrapper, errores } = await montarDeseos()

    expect(errores).toEqual([])
    // Lo que separa esta vista de `/collection` en una sola línea.
    expect(apiCall).toHaveBeenCalledWith('collection_list', expect.objectContaining({
      is_wishlist: true,
      limit: 60
    }))
    expect(catalogGet).toHaveBeenCalledWith('/sets')

    const tarjetas = wrapper.findAll('.carta')

    expect(tarjetas).toHaveLength(DESEOS.length)
    expect(tarjetas[0].find('.carta__nombre').text()).toBe(DESEOS[0].name)
    // Cada línea trae el editor de cantidad y estado, reutilizado tal cual.
    expect(wrapper.findAll('.controles')).toHaveLength(DESEOS.length)
    // Y el botón que solo existe aquí.
    expect(wrapper.findAll('.carta__cumplir')).toHaveLength(DESEOS.length)
  })

  it('los controles de la fila trabajan sobre los DESEOS, no sobre la colección', async () => {
    const { wrapper, pinia } = await montarDeseos()

    // El prop `deseos` de `CollectionControls` es lo que elige el store; si se
    // perdiera, editar un deseo escribiría en la lista de la colección.
    const fila = wrapper.findAll('.carta')[0]

    expect(fila.find('.controles').exists()).toBe(true)
    expect(pinia.state.value.wishlist.items).toHaveLength(DESEOS.length)
    expect(pinia.state.value.collection.items).toEqual([])
  })

  it('el precio ausente dice «sin precio» y el deseo NO desaparece', async () => {
    const lista = fixtura(deseosFixture)

    lista.data.items[0].priceEur = null
    lista.data.items[0].lineValue = 0

    respondeDeseos(lista)

    const { wrapper } = await montarDeseos()

    expect(wrapper.findAll('.carta')).toHaveLength(DESEOS.length)
    expect(wrapper.findAll('.carta')[0].find('.carta__precio').text()).toBe('sin precio')
  })

  it('la lista vacía invita al catálogo, que es donde está el corazón', async () => {
    respondeDeseos({
      status: 'success',
      message: 'Colección.',
      data: { items: [], nextCursor: null, count: 0 },
      http_code: 200
    })

    const { wrapper } = await montarDeseos()

    expect(wrapper.find('.deseos__vacio').text()).toContain('pulsa el corazón')
  })

  it('un error del backend se enseña y no tumba la pantalla', async () => {
    respondeDeseos({ status: 'error', message: 'No autorizado.', http_code: 401 })

    const { wrapper, errores } = await montarDeseos()

    expect(errores).toEqual([])
    expect(wrapper.find('.deseos__error').text()).toContain('No autorizado.')
  })
})

describe('LA CABECERA DEL VALOR: cuánto cuesta lo que quieres', () => {
  /**
   * **El número está cuadrado contra SQL, no contra la fixtura.**
   *
   * Comprobar que la vista pinta lo que trae el JSON solo prueba que la fixtura
   * es la fixtura. La fixtura se capturó el 2026-09-12 del backend de dev sobre
   * un usuario de usar y tirar (`9004`, borrado al terminar) y su total se
   * contrastó a mano contra la misma suma hecha en SQL:
   *
   *   SELECT ROUND(SUM(ci.quantity * pc.price_eur), 2)
   *     FROM mtg_collection_item ci
   *     LEFT JOIN mtg_price_current pc
   *           ON pc.printing_uuid = ci.printing_uuid AND pc.finish = ci.finish
   *    WHERE ci.user_id = 9004 AND ci.is_wishlist = 1;   -- 743.31
   *
   * Las 18 líneas —una de ellas sin precio— sumaban **743,31 €** en MySQL y
   * `collection_value` devolvió **743.31**. Ese es el número que este bloque
   * exige ver en pantalla, escrito aquí a mano para que cambiar la fixtura sin
   * rehacer la comprobación ponga el test en rojo.
   */
  const TOTAL_CUADRADO_EN_SQL = 743.31

  it('la fixtura sigue cuadrando con el `SUM` que se hizo en SQL', () => {
    expect(fixtura(valorFixture).data.totals.valueEur).toBe(TOTAL_CUADRADO_EN_SQL)
  })

  it('pide el valor de los DESEOS, con la bandera puesta', async () => {
    const { errores } = await montarDeseos()

    expect(errores).toEqual([])
    // Sin el flag esto valoraría la colección, y la cabecera de `/wishlist`
    // enseñaría lo que ya tienes como si fuera lo que te falta por comprar.
    expect(apiCall).toHaveBeenCalledWith('collection_value', { is_wishlist: true })
  })

  it('enseña ese total en euros, formateado en español', async () => {
    const { wrapper } = await montarDeseos()

    expect(wrapper.find('.valor__total').text()).toBe(EUROS.format(TOTAL_CUADRADO_EN_SQL))
    expect(wrapper.find('.valor__total').text()).toContain('743,31')
  })

  it('los ejemplares, las líneas y las que no cotizan salen del backend tal cual', async () => {
    const { wrapper } = await montarDeseos()

    const totales = fixtura(valorFixture).data.totals
    const cifras = wrapper.findAll('.valor__tarjeta').map((t) => t.text())

    expect(cifras[1]).toContain(String(totales.totalCopies))
    expect(cifras[1]).toContain(`en ${totales.uniqueItems} líneas`)
    // Una carta sin precio cuenta como carta y solo deja de sumar euros: sin
    // este dato, un total bajo sería ambiguo.
    expect(totales.itemsWithoutPrice).toBe(1)
    expect(cifras[2]).toContain('línea no cotiza')
  })

  it('el desglose arranca CERRADO: esta pantalla es una lista', async () => {
    const { wrapper } = await montarDeseos()

    expect(wrapper.find('.valor__desglose').exists()).toBe(false)
    expect(wrapper.findAll('.carta')).toHaveLength(DESEOS.length)
  })

  it('abrirlo enseña el reparto por edición, por rareza y el top 10', async () => {
    const { wrapper } = await montarDeseos()

    await wrapper.find('.valor__conmutador').trigger('click')
    await nextTick()

    const datos = fixtura(valorFixture).data

    expect(wrapper.find('.valor__desglose').exists()).toBe(true)

    const paneles = wrapper.findAll('.valor__panel')

    // Por edición: la barra más cara marca la escala y sale al 100 %.
    expect(paneles[0].text()).toContain(datos.bySet[0].setName)
    expect(paneles[0].findAll('.barra')).toHaveLength(datos.bySet.length)
    expect(paneles[0].findAll('.barra__relleno')[0].attributes('style'))
      .toContain('width: 100%')
    expect(paneles[0].findAll('.barra__valor')[0].text())
      .toBe(EUROS.format(datos.bySet[0].valueEur))

    // Por rareza, en el orden de la rareza y no del valor: es una escala.
    expect(paneles[1].findAll('.barra__nombre').map((n) => n.text()))
      .toEqual(['Mítica', 'Rara', 'Infrecuente', 'Común'])

    // Y el top 10, que son diez y no diecisiete.
    expect(paneles[2].findAll('.joya')).toHaveLength(10)
    expect(paneles[2].findAll('.joya__nombre')[0].text()).toBe(datos.topCards[0].name)
    expect(paneles[2].findAll('.joya__precio')[0].text())
      .toBe(EUROS.format(datos.topCards[0].priceEur))
  })

  it('cambiar de filtro NO vuelve a pedir el valor: no acepta filtros', async () => {
    const { router } = await montarDeseos()

    await router.replace({ name: 'wishlist', query: { rarity: 'mythic' } })
    await flushPromises()

    const valoraciones = apiCall.mock.calls.filter(([accion]) => accion === 'collection_value')

    // `collection_value` vale la lista ENTERA; repetirlo por cada filtro sería
    // la misma cifra otra vez y una petición de más.
    expect(valoraciones).toHaveLength(1)
    expect(apiCall.mock.calls.filter(([a]) => a === 'collection_list').length)
      .toBeGreaterThan(1)
  })

  it('sin nada que valorar no hay cabecera, y la lista vacía sigue invitando', async () => {
    const vacio = fixtura(valorFixture)

    vacio.data.totals.uniqueItems = 0

    respondeDeseos({
      status: 'success',
      message: 'Colección.',
      data: { items: [], nextCursor: null, count: 0 },
      http_code: 200
    }, vacio)

    const { wrapper } = await montarDeseos()

    expect(wrapper.find('.valor__total').exists()).toBe(false)
    expect(wrapper.find('.deseos__vacio').text()).toContain('pulsa el corazón')
  })

  it('si el valor falla se dice, y la lista se pinta igual', async () => {
    respondeDeseos(fixtura(deseosFixture), {
      status: 'error',
      message: 'No se pudo calcular.',
      http_code: 500
    })

    const { wrapper, errores } = await montarDeseos()

    expect(errores).toEqual([])
    expect(wrapper.find('.deseos__error').text()).toContain('No se pudo calcular.')
    // El fallo de la cabecera no se lleva por delante lo que sí llegó.
    expect(wrapper.findAll('.carta')).toHaveLength(DESEOS.length)
  })
})

describe('«ya la tengo», que se teletransporta fuera del wrapper', () => {
  /** Abre el panel sobre la tarjeta `indice` y devuelve el wrapper. */
  async function abrirPanel(indice, opciones = {}) {
    const montaje = await montarDeseos({ attachTo: document.body, ...opciones })

    expect(panelCumplir()).toBeNull()

    await montaje.wrapper.findAll('.carta__cumplir')[indice].trigger('click')
    await nextTick()

    // AQUÍ está la trampa: no en `wrapper.html()`, sino en el `document.body`.
    expect(montaje.wrapper.find('.cumplir__panel').exists()).toBe(false)
    expect(panelCumplir()).not.toBeNull()

    return montaje
  }

  it('el panel dice de qué carta habla y cuántas se querían', async () => {
    const { wrapper } = await abrirPanel(2)

    // El índice 2 es el deseo de 4: es el que ejercita el movimiento parcial.
    expect(DESEOS[2].quantity).toBe(4)
    expect(panelCumplir().querySelector('.cumplir__carta').textContent).toBe(DESEOS[2].name)
    expect(panelCumplir().querySelector('.cumplir__nota').textContent)
      .toBe('de las 4 que querías')
    expect(wrapper.findAll('.carta')).toHaveLength(DESEOS.length)
  })

  it('confirmar manda `collection_fulfill_wish` con el id del DESEO y una unidad', async () => {
    const { wrapper } = await abrirPanel(2)

    apiCall.mockResolvedValueOnce(fixtura(cumplirFixture))

    panelCumplir().querySelector('.cumplir__confirmar').click()
    await flushPromises()

    // Sin `condition`: el panel abre en el estado que se deseaba, así que no hay
    // nada que cambiar y el backend lo conserva.
    expect(apiCall).toHaveBeenLastCalledWith('collection_fulfill_wish', {
      item_id: DESEOS[2].id,
      quantity: 1
    })
    expect(wrapper.findAll('.carta')).toHaveLength(DESEOS.length)
  })

  it('MOVIMIENTO PARCIAL: la línea se queda en su sitio con lo que quedaba', async () => {
    const { wrapper } = await abrirPanel(2)

    apiCall.mockResolvedValueOnce(fixtura(cumplirFixture))

    panelCumplir().querySelector('.cumplir__confirmar').click()
    await flushPromises()
    await nextTick()

    const origen = fixtura(cumplirFixture).data.origen

    // Sigue habiendo cuatro deseos y el tocado NO se ha movido de posición:
    // reordenar movería cartas bajo el dedo del usuario.
    const tarjetas = wrapper.findAll('.carta')

    expect(tarjetas).toHaveLength(DESEOS.length)
    expect(tarjetas[2].find('.carta__nombre').text()).toBe(origen.name)
    expect(origen.quantity).toBe(3)
  })

  it('MOVIMIENTO TOTAL: la línea desaparece, y no la sustituye la de colección', async () => {
    const { wrapper, pinia } = await abrirPanel(0)

    // El índice 0 se quería x1: cumplir una es cumplirlo entero.
    expect(DESEOS[0].quantity).toBe(1)

    apiCall.mockResolvedValueOnce(fixtura(cumplirTotalFixture))

    panelCumplir().querySelector('.cumplir__confirmar').click()
    await flushPromises()
    await nextTick()

    const respuesta = fixtura(cumplirTotalFixture).data

    expect(respuesta.origen).toBeNull()
    expect(wrapper.findAll('.carta')).toHaveLength(DESEOS.length - 1)
    // El `item` que vuelve es la línea de COLECCIÓN, con otro id y
    // `isWishlist: false`: pintarla aquí enseñaría una carta que ya es tuya.
    expect(pinia.state.value.wishlist.items.map((i) => i.id)).not.toContain(respuesta.item.id)
    expect(pinia.state.value.wishlist.items.map((i) => i.id)).not.toContain(DESEOS[0].id)
  })

  it('cumplir NO recarga ninguna de las dos listas', async () => {
    await abrirPanel(2)

    apiCall.mockClear()
    apiCall.mockResolvedValueOnce(fixtura(cumplirFixture))

    panelCumplir().querySelector('.cumplir__confirmar').click()
    await flushPromises()
    await nextTick()

    // UNA petición y ninguna más: ni un `collection_list` de deseos ni uno de
    // colección. La lista se actualiza con lo que devolvió el propio cumplir.
    const acciones = apiCall.mock.calls.map(([accion]) => accion)

    expect(acciones).toEqual(['collection_fulfill_wish'])
  })

  it('si el backend lo rechaza, el panel NO se cierra y la línea sigue ahí', async () => {
    const { wrapper } = await abrirPanel(2)

    apiCall.mockResolvedValueOnce({
      status: 'error',
      message: 'No puedes cumplir más de lo que querías.',
      http_code: 422
    })

    panelCumplir().querySelector('.cumplir__confirmar').click()
    await flushPromises()
    await nextTick()

    expect(panelCumplir()).not.toBeNull()
    expect(wrapper.findAll('.carta')).toHaveLength(DESEOS.length)
  })
})

describe('EL CICLO COMPLETO: el corazón, la lista y «ya la tengo»', () => {
  it('del catálogo a los deseos y de los deseos a la colección, sin recargar', async () => {
    // --- 1. `/catalog`: un clic en el corazón ------------------------------
    const deseada = fixtura(deseoFixture).data.item

    apiCall.mockResolvedValue(fixtura(deseoFixture))

    const { wrapper: boton } = await montarVista(AddToCollectionButton, {
      ruta: '/catalog',
      estado: SESION,
      props: {
        printingUuid: deseada.printingUuid,
        nombre: deseada.name,
        finishes: fixtura(cartaFixture.finishes)
      }
    })

    await boton.find('.anadir__corazon').trigger('click')
    await flushPromises()

    expect(apiCall).toHaveBeenCalledWith('collection_add', {
      printing_uuid: deseada.printingUuid,
      is_wishlist: true
    })
    expect(fixtura(deseoFixture).data.item.isWishlist).toBe(true)

    // --- 2. `/wishlist`: la carta está en la lista -------------------------
    // La fixtura de la lista se capturó justo después de ese `collection_add`
    // contra el backend de dev, así que esto no es una coincidencia montada a
    // mano: es la carta que el corazón acababa de meter.
    expect(DESEOS.map((i) => i.printingUuid)).toContain(deseada.printingUuid)

    apiCall.mockReset()
    respondeDeseos()

    const { wrapper, pinia, errores } = await montarDeseos({ attachTo: document.body })

    expect(errores).toEqual([])

    const posicion = DESEOS.findIndex((i) => i.printingUuid === deseada.printingUuid)

    expect(wrapper.findAll('.carta')[posicion].find('.carta__nombre').text()).toBe(deseada.name)

    // --- 3. «Ya la tengo»: pasa a la colección -----------------------------
    await wrapper.findAll('.carta__cumplir')[2].trigger('click')
    await nextTick()

    apiCall.mockClear()
    apiCall.mockResolvedValueOnce(fixtura(cumplirFixture))

    panelCumplir().querySelector('.cumplir__confirmar').click()
    await flushPromises()
    await nextTick()

    const respuesta = fixtura(cumplirFixture).data

    // La carta cumplida es una línea de COLECCIÓN: `isWishlist: false`.
    expect(respuesta.item.isWishlist).toBe(false)
    // Y ninguna de las dos listas se ha recargado: una sola petición.
    expect(apiCall.mock.calls.map(([accion]) => accion)).toEqual(['collection_fulfill_wish'])
    // El deseo bajó de 4 a 3 en su sitio, sin un `collection_list` de por medio.
    expect(pinia.state.value.wishlist.items[2].quantity).toBe(3)
    expect(pinia.state.value.wishlist.items).toHaveLength(DESEOS.length)
  })
})

describe('los filtros y la query string', () => {
  it('aplicar un filtro lo escribe en la URL y vuelve a pedir DESEOS', async () => {
    const { wrapper, router } = await montarDeseos()

    await wrapper.find('button[aria-label="Filtros"]').trigger('click')
    await nextTick()

    expect(wrapper.find('.deseos__filtros').exists()).toBe(true)

    // Se escribe directamente en la URL, que es la fuente de verdad de la
    // vista: el `watch` de la query es quien dispara la búsqueda.
    await router.replace({ name: 'wishlist', query: { rarity: 'mythic' } })
    await flushPromises()

    expect(router.currentRoute.value.query.rarity).toBe('mythic')
    expect(apiCall).toHaveBeenLastCalledWith('collection_list', expect.objectContaining({
      rarity: 'mythic',
      is_wishlist: true
    }))
  })

  it('`?view=table` pinta la tabla, con su columna de «ya la tengo»', async () => {
    const { wrapper } = await montarDeseos({ ruta: '/wishlist?view=table' })

    expect(wrapper.find('.deseos__tabla').exists()).toBe(true)
    expect(wrapper.findAll('.carta')).toHaveLength(0)
    // El mismo dato por otro camino: las líneas y sus dos controles siguen ahí.
    expect(wrapper.findAll('.controles')).toHaveLength(DESEOS.length)
    expect(wrapper.text()).toContain(DESEOS[0].name)
    expect(wrapper.text()).toContain('Ya la tengo')
  })
})

describe('el scroll infinito', () => {
  it('el centinela pide la página siguiente por cursor y la ACUMULA', async () => {
    apiCall.mockReset()
    respondeDeseos(pagina(0, 2, 'eyJvIjoyfQ'))

    const { wrapper } = await montarDeseos()

    expect(wrapper.findAll('.carta')).toHaveLength(2)

    apiCall.mockResolvedValueOnce(pagina(2, 4))

    await observadores[0].entraEnPantalla()
    await flushPromises()
    await nextTick()

    expect(apiCall).toHaveBeenLastCalledWith('collection_list', expect.objectContaining({
      cursor: 'eyJvIjoyfQ',
      is_wishlist: true
    }))
    expect(wrapper.findAll('.carta')).toHaveLength(4)
    expect(wrapper.find('.deseos__fin').text()).toBe('No hay más deseos')
  })
})
