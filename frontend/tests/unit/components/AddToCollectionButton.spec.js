import { beforeEach, describe, expect, it, vi } from 'vitest'
import { h, nextTick } from 'vue'
import { flushPromises } from '@vue/test-utils'

import AddToCollectionButton from '@/components/AddToCollectionButton.vue'
import { apiCall } from '@/services/api'
import { ACABADOS } from '@/constants/collection'

import { SESION, montarVista } from '../../helpers'

import cartaFixture from '../../fixtures/catalog_card.json'
import altaFixture from '../../fixtures/collection_add.json'
import deseoFixture from '../../fixtures/collection_add_wishlist.json'
import deseadosFixture from '../../fixtures/collection_wished_uuids.json'

/**
 * `components/AddToCollectionButton.vue` — el botón «Añadir» del catálogo y de
 * la ficha.
 *
 * **La mitigación del riesgo del plan vive en este componente**: si añadir una
 * carta costara cinco clics nadie registraría nada. De ahí sus tres caminos:
 *
 *  - **El grande**, que llama al store con SOLO el `printing_uuid` y deja que el
 *    backend ponga los valores por defecto (`normal` / `English` / `NM`,
 *    cantidad 1). Ni diálogo, ni confirmación, ni un segundo botón.
 *  - **El corazón**, que es el mismo gesto sobre la lista de deseos: un clic =
 *    un deseo, por `collection_add` con `is_wishlist`.
 *  - **El de al lado**, «Opciones», con las cinco dimensiones. Abre **siempre en
 *    los valores por defecto**, para que sea un retoque y no un formulario en
 *    blanco (`AddToCollectionButton.vue:203-212`).
 *
 * Por eso los botones se buscan por CLASE y no por posición: el corazón entró
 * entre los otros dos, y un `findAll('button')[1]` habría empezado a apuntar a
 * un botón distinto sin que ningún test lo dijera.
 *
 * **La trampa de este fichero**: el `Popover` de PrimeVue **teletransporta su
 * contenido al `document.body`** (`AddToCollectionButton.vue:34`), así que lo que
 * abre «Opciones» NO está dentro de `wrapper.html()`. Hay que montar con
 * `attachTo: document.body` y buscarlo allí, o los tests del panel buscarían en
 * un sitio donde nunca va a estar.
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

const CARTA = cartaFixture

async function montarBoton({ props = {}, estado = SESION, attachTo } = {}) {
  const montaje = await montarVista(AddToCollectionButton, {
    ruta: '/catalog',
    estado,
    ...(attachTo ? { attachTo } : {}),
    props: {
      printingUuid: CARTA.uuid,
      nombre: CARTA.name,
      finishes: fixtura(CARTA.finishes),
      ...props
    }
  })

  await nextTick()

  return montaje
}

/** El panel del `Popover`, que vive fuera del wrapper. */
function panel() {
  return document.body.querySelector('.anadir__panel')
}

beforeEach(() => {
  apiCall.mockReset()
  apiCall.mockResolvedValue(fixtura(altaFixture))
})

describe('el camino de un clic', () => {
  it('manda SOLO el printing_uuid: los valores por defecto los pone el backend', async () => {
    const { wrapper, errores } = await montarBoton()

    await wrapper.find('.anadir__principal').trigger('click')
    await flushPromises()

    expect(errores).toEqual([])
    // Ni `finish`, ni `language`, ni `condition`, ni `quantity`: el cliente no
    // replica los defaults del backend (`constants/collection.js:47-59`).
    expect(apiCall).toHaveBeenCalledWith('collection_add', { printing_uuid: CARTA.uuid })
  })

  it('el aviso lo deja el store, y dice cuántas tienes ya si la carta se sumó', async () => {
    const { wrapper, pinia } = await montarBoton()

    await wrapper.find('.anadir__principal').trigger('click')
    await flushPromises()

    // La fixtura capturada vuelve con `quantity: 2`: añadir dos veces la misma
    // carta **suma**, y eso se dice en voz alta en vez de impedirse.
    expect(fixtura(altaFixture).data.item.quantity).toBe(2)
    expect(pinia.state.value.collection.aviso).toEqual({
      tipo: 'ok',
      texto: `${fixtura(altaFixture).data.item.name} añadida — ya tienes 2.`
    })
  })

  it('con un alta en vuelo el botón se queda ocupado y el de opciones, apagado', async () => {
    // `estaAnadiendo` indexa por `printing_uuid` —no por id de línea—, que es
    // lo único que hay antes de que la carta exista (`stores/collection.js:84`).
    const { wrapper } = await montarBoton({
      estado: { ...SESION, collection: { anadiendo: [CARTA.uuid] } }
    })

    expect(wrapper.find('.anadir__principal').classes()).toContain('p-button-loading')
    expect(wrapper.find('.anadir__opciones').attributes('disabled')).toBeDefined()
  })

  it('en modo compacto el botón va sin texto, pero nunca sin aria-label', async () => {
    const { wrapper } = await montarBoton({ props: { compacto: true } })

    // En la rejilla no hay sitio y la carta ya se ve; en la ficha sí lo hay.
    expect(wrapper.find('.anadir__principal').text()).toBe('')
    expect(wrapper.find('.anadir__principal').attributes('aria-label'))
      .toBe(`Añadir ${CARTA.name} a mi colección`)
    expect(wrapper.classes()).toContain('anadir--compacto')

    const { wrapper: ancho } = await montarBoton()

    expect(ancho.find('.anadir__principal').text()).toBe('Añadir')
  })
})

describe('el corazón', () => {
  it('un clic es un deseo: el MISMO endpoint con `is_wishlist`, y nada más', async () => {
    apiCall.mockResolvedValue(fixtura(deseoFixture))

    const { wrapper, errores } = await montarBoton()

    await wrapper.find('.anadir__corazon').trigger('click')
    await flushPromises()

    expect(errores).toEqual([])
    // No hay acción nueva: `collection_add` ya acepta la bandera. Y tampoco
    // viajan las dimensiones: las pone el backend, igual que en el botón grande.
    expect(apiCall).toHaveBeenCalledWith('collection_add', {
      printing_uuid: CARTA.uuid,
      is_wishlist: true
    })
    // La fixtura está capturada con ESA llamada: vuelve marcada como deseo.
    expect(fixtura(deseoFixture).data.item.isWishlist).toBe(true)
  })

  it('el deseo va al store de deseos y NO ensucia el de la colección', async () => {
    apiCall.mockResolvedValue(fixtura(deseoFixture))

    const { wrapper, pinia } = await montarBoton()

    await wrapper.find('.anadir__corazon').trigger('click')
    await flushPromises()

    expect(pinia.state.value.wishlist.aviso).toEqual({
      tipo: 'ok',
      texto: `${fixtura(deseoFixture).data.item.name} añadida a tu lista de deseos.`
    })
    // Si esto fuera un solo store con una bandera, el aviso habría caído aquí.
    expect(pinia.state.value.collection.aviso).toBeNull()
    expect(pinia.state.value.collection.items).toEqual([])
  })

  it('un alta en vuelo NO apaga el corazón, ni un deseo en vuelo el «Añadir»', async () => {
    // La trampa que el plan anota: los dos «en vuelo» indexan por
    // `printing_uuid`, así que compartirlos dejaría muerto el otro botón.
    const { wrapper } = await montarBoton({
      estado: { ...SESION, collection: { anadiendo: [CARTA.uuid] } }
    })

    expect(wrapper.find('.anadir__principal').classes()).toContain('p-button-loading')
    expect(wrapper.find('.anadir__corazon').classes()).not.toContain('p-button-loading')

    const { wrapper: alReves } = await montarBoton({
      estado: { ...SESION, wishlist: { deseando: [CARTA.uuid] } }
    })

    expect(alReves.find('.anadir__corazon').classes()).toContain('p-button-loading')
    expect(alReves.find('.anadir__principal').classes()).not.toContain('p-button-loading')
  })

  it('sin sesión el corazón tampoco sale a la red: manda a entrar', async () => {
    const { wrapper, router } = await montarBoton({
      estado: { auth: { isAuthenticated: false, user: null } }
    })

    await wrapper.find('.anadir__corazon').trigger('click')

    await vi.waitFor(() => expect(router.currentRoute.value.name).toBe('login'))
    expect(router.currentRoute.value.query.redirect).toBe('/catalog')
    expect(apiCall).not.toHaveBeenCalled()
  })
})

describe('sin sesión', () => {
  it('el clic NO sale a la red: manda a entrar y guarda a dónde volver', async () => {
    const { wrapper, router } = await montarBoton({
      estado: { auth: { isAuthenticated: false, user: null } }
    })

    await wrapper.find('.anadir__principal').trigger('click')

    // El botón se ve siempre —esconderlo dejaría el catálogo sin decir para qué
    // sirve— y el clic no se pierde (`AddToCollectionButton.vue:126-132`).
    await vi.waitFor(() => expect(router.currentRoute.value.name).toBe('login'))
    expect(router.currentRoute.value.query.redirect).toBe('/catalog')
    expect(apiCall).not.toHaveBeenCalled()
  })
})

describe('el panel de «Opciones», que se teletransporta fuera del wrapper', () => {
  it('abre en los valores por defecto y manda las cuatro dimensiones', async () => {
    const { wrapper } = await montarBoton({ attachTo: document.body })

    expect(panel()).toBeNull()

    await wrapper.find('.anadir__opciones').trigger('click')
    await nextTick()

    // AQUÍ está la trampa: no en `wrapper.html()`, sino en el `document.body`.
    expect(wrapper.find('.anadir__panel').exists()).toBe(false)
    expect(panel()).not.toBeNull()
    expect(panel().querySelector('.anadir__titulo').textContent).toBe('Añadir con opciones')

    panel().querySelector('.anadir__confirmar').click()
    await flushPromises()

    // Lo mismo que habría hecho el clic simple, pero dicho a mano: «Opciones»
    // es un retoque, no un formulario en blanco.
    expect(apiCall).toHaveBeenCalledWith('collection_add', {
      printing_uuid: CARTA.uuid,
      finish: 'normal',
      language: 'English',
      condition: 'NM',
      quantity: 1
    })

    // Y al guardar bien, se cierra solo.
    await vi.waitFor(() => expect(panel()).toBeNull())
  })

  it('si el alta falla, el panel NO se cierra: lo tecleado no se pierde', async () => {
    apiCall.mockResolvedValue({ status: 'error', message: 'No se pudo añadir.', http_code: 500 })

    const { wrapper } = await montarBoton({ attachTo: document.body })

    await wrapper.find('.anadir__opciones').trigger('click')
    await nextTick()

    panel().querySelector('.anadir__confirmar').click()
    await flushPromises()
    await nextTick()

    expect(panel()).not.toBeNull()
  })

  it('sin sesión, «Opciones» tampoco abre: manda a entrar', async () => {
    const { wrapper, router } = await montarBoton({
      attachTo: document.body,
      estado: { auth: { isAuthenticated: false, user: null } }
    })

    await wrapper.find('.anadir__opciones').trigger('click')
    await nextTick()

    expect(panel()).toBeNull()
    await vi.waitFor(() => expect(router.currentRoute.value.name).toBe('login'))
  })
})

describe('los acabados que se ofrecen', () => {
  /** Las etiquetas del desplegable de acabado, que vive dentro del `Popover`. */
  async function acabadosOfrecidos(finishes) {
    const { wrapper } = await montarBoton({ attachTo: document.body, props: { finishes } })

    await wrapper.find('.anadir__opciones').trigger('click')
    await nextTick()

    // El desplegable arranca cerrado: lo que se comprueba es qué opción quedó
    // seleccionada, que es lo que se mandaría al pulsar «Añadir».
    return panel().querySelector('.p-select-label').textContent
  }

  it('un foil de una carta que nunca se imprimió en foil no se ofrece', async () => {
    // `Sol Ring` de LTC: `{ foil: false, nonfoil: true, etched: false }` tal
    // cual lo da el catálogo. Un acabado inexistente se guardaría igual y
    // valoraría con un precio que no hay (`AddToCollectionButton.vue:138-144`).
    expect(fixtura(CARTA.finishes)).toEqual({ foil: false, nonfoil: true, etched: false })
    expect(await acabadosOfrecidos(fixtura(CARTA.finishes))).toBe('Normal')
  })

  it('si el defecto no existe para esa carta, se cae al primero que sí', async () => {
    const { wrapper } = await montarBoton({
      attachTo: document.body,
      props: { finishes: { foil: true, nonfoil: false, etched: false } }
    })

    await wrapper.find('.anadir__opciones').trigger('click')
    await nextTick()

    expect(panel().querySelector('.p-select-label').textContent).toBe('Foil')

    panel().querySelector('.anadir__confirmar').click()
    await flushPromises()

    expect(apiCall).toHaveBeenCalledWith(
      'collection_add',
      expect.objectContaining({ finish: 'foil' })
    )
  })

  it('sin datos de acabado, o con ninguno marcado, se ofrecen los tres', async () => {
    // Un desplegable vacío impediría añadir la carta, que es peor que ofrecer
    // de más (`AddToCollectionButton.vue:173-175`).
    expect(ACABADOS.map((a) => a.value)).toEqual(['normal', 'foil', 'etched'])
    expect(await acabadosOfrecidos(null)).toBe('Normal')
    expect(await acabadosOfrecidos({ foil: false, nonfoil: false, etched: false })).toBe('Normal')
  })
})

describe('el corazón RELLENO: lo que ya está en la lista de deseos', () => {
  /** Una carta del catálogo que NO está deseada (`catalog_cards.json`). */
  const SIN_DESEAR = '00cf70ec-98f3-5e3e-928a-a7866a1d0c54'

  /** Responde a cada acción con lo suyo: al montar se piden los deseados. */
  function respondiendoPorAccion() {
    apiCall.mockImplementation((accion) =>
      Promise.resolve(
        accion === 'collection_wished_uuids' ? fixtura(deseadosFixture) : fixtura(deseoFixture)
      )
    )
  }

  /** El icono del corazón, que es lo único que separa relleno de contorno. */
  function iconoDelCorazon(wrapper) {
    return wrapper.find('.anadir__corazon span.p-button-icon').classes()
  }

  beforeEach(respondiendoPorAccion)

  it('la fixtura trae la carta de la ficha: sin eso, esto no probaría nada', () => {
    // `catalog_card.json` es el Sol Ring que el usuario desechable tenía
    // deseado, así que el relleno de `/card/:uuid` se prueba de verdad.
    expect(fixtura(deseadosFixture).data.uuids).toContain(CARTA.uuid)
    expect(fixtura(deseadosFixture).data.uuids).not.toContain(SIN_DESEAR)
  })

  it('EL HITO (a): tras montar —o sea, tras RECARGAR—, la carta deseada sale rellena', async () => {
    const { wrapper, errores } = await montarBoton()
    await flushPromises()
    await nextTick()

    expect(errores).toEqual([])
    // El catálogo no tiene sesión y no puede anotar esto: el cruce lo hace el
    // cliente con la lista que pidió al montarse.
    expect(apiCall).toHaveBeenCalledWith('collection_wished_uuids')
    expect(iconoDelCorazon(wrapper)).toContain('pi-heart-fill')
    expect(wrapper.find('.anadir__corazon').classes()).toContain('anadir__corazon--relleno')
    expect(wrapper.find('.anadir__corazon').attributes('title')).toBe('Ya la quieres')
    expect(wrapper.find('.anadir__corazon').attributes('aria-label'))
      .toBe(`${CARTA.name} ya está en tu lista de deseos — querer otra`)
  })

  it('la que no está deseada sigue siendo un contorno, y lo dice', async () => {
    const { wrapper } = await montarBoton({ props: { printingUuid: SIN_DESEAR } })
    await flushPromises()
    await nextTick()

    expect(iconoDelCorazon(wrapper)).toContain('pi-heart')
    expect(iconoDelCorazon(wrapper)).not.toContain('pi-heart-fill')
    expect(wrapper.find('.anadir__corazon').classes()).not.toContain('anadir__corazon--relleno')
    expect(wrapper.find('.anadir__corazon').attributes('title')).toBe('La quiero')
  })

  it('EL HITO (b): pulsarlo lo rellena SIN esperar a ninguna otra petición', async () => {
    const { wrapper } = await montarBoton({ props: { printingUuid: SIN_DESEAR } })
    await flushPromises()
    await nextTick()

    expect(iconoDelCorazon(wrapper)).not.toContain('pi-heart-fill')

    apiCall.mockClear()

    await wrapper.find('.anadir__corazon').trigger('click')
    await flushPromises()
    await nextTick()

    expect(iconoDelCorazon(wrapper)).toContain('pi-heart-fill')
    // Una sola llamada, la del propio deseo: ni un `collection_wished_uuids` de
    // refresco. Si hubiera que esperarlo, el botón mentiría durante un segundo
    // y el usuario pulsaría otra vez.
    expect(apiCall.mock.calls.map(([accion]) => accion)).toEqual(['collection_add'])
  })

  it('una rejilla de 60 tarjetas pide la lista UNA vez, no sesenta', async () => {
    // La trampa que el plan anota: el corazón se monta POR TARJETA, así que si
    // la guarda no estuviera en el store, una página de catálogo serían 60
    // peticiones idénticas. Se monta una rejilla de verdad —60 botones en la
    // MISMA Pinia— porque con 60 montajes sueltos cada uno tendría la suya y el
    // recuento no probaría nada.
    const uuids = Array.from({ length: 60 }, (_, i) => `uuid-de-la-rejilla-${i}`)

    const Rejilla = {
      render: () =>
        h(
          'div',
          uuids.map((uuid) =>
            h(AddToCollectionButton, { key: uuid, printingUuid: uuid, nombre: 'Carta', compacto: true })
          )
        )
    }

    const { wrapper, pinia } = await montarVista(Rejilla, { ruta: '/catalog', estado: SESION })

    await flushPromises()

    expect(wrapper.findAll('.anadir__corazon')).toHaveLength(60)
    expect(apiCall.mock.calls.filter(([a]) => a === 'collection_wished_uuids')).toHaveLength(1)
    expect(pinia.state.value.wishlist.deseadosCargados).toBe(true)
  })

  it('sin sesión NO se pide la lista: el catálogo se ve igual', async () => {
    await montarBoton({ estado: { auth: { isAuthenticated: false, user: null } } })
    await flushPromises()

    expect(apiCall).not.toHaveBeenCalled()
  })

  it('si la lista falla, el corazón se queda en contorno y no rompe nada', async () => {
    apiCall.mockImplementation((accion) =>
      Promise.resolve(
        accion === 'collection_wished_uuids'
          ? { status: 'error', message: 'Authentication required', http_code: 401 }
          : fixtura(deseoFixture)
      )
    )

    const { wrapper, errores, pinia } = await montarBoton()
    await flushPromises()
    await nextTick()

    expect(errores).toEqual([])
    expect(iconoDelCorazon(wrapper)).toContain('pi-heart')
    expect(pinia.state.value.wishlist.aviso).toBeNull()
  })
})
