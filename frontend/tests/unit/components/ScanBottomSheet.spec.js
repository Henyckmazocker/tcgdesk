import { beforeEach, describe, expect, it, vi } from 'vitest'
import { nextTick } from 'vue'
import { flushPromises } from '@vue/test-utils'

import ScanBottomSheet from '@/components/ScanBottomSheet.vue'
import { apiCall } from '@/services/api'
import { useScanStore } from '@/stores/scan'

import { SESION, montarVista } from '../../helpers'

import scanFixture from '../../fixtures/scan_resolve.json'
import altaFixture from '../../fixtures/collection_add.json'
import listaMazosFixture from '../../fixtures/deck_list.json'

/**
 * `components/ScanBottomSheet.vue` — el menú inferior de `/scan`.
 *
 * Lo que este fichero vigila, y por qué cada cosa:
 *
 *  - **Una fila escrita pierde el check y gana un `+1`.** Es la mitad visible de
 *    la deduplicación: la otra mitad —que el store no escriba dos veces— la
 *    cubre `stores/scan.spec.js`. Sin esta, el check seguiría ahí y el usuario
 *    volvería a pulsarlo.
 *  - **Las dudas se pintan con `ImportCandidate.vue`**, el mismo componente de
 *    `/import`, y elegir un candidato **no escribe**: solo habilita el check.
 *  - **Los ajustes van plegados y el destino primero**, que es lo que pide el
 *    hito. Plegados de verdad: con la cámara detrás, un formulario abierto tapa
 *    lo que hay que encuadrar.
 *
 * **La trampa de este fichero**: los `Select` de PrimeVue **teletransportan su
 * overlay al `document.body`**, así que las opciones que ofrecen NO están en
 * `wrapper.html()` — hay que montar con `attachTo` y buscarlas allí. Mirar el
 * `.p-select-label` diría cuál quedó seleccionado, nunca cuáles se ofrecían.
 */

vi.mock('@/services/api', async (importarOriginal) => ({
  ...(await importarOriginal()),
  apiCall: vi.fn(),
  catalogGet: vi.fn()
}))

function fixtura(json) {
  return structuredClone(json)
}

const VEREDICTOS = fixtura(scanFixture).data.results
const EXACTA = 0
const ASUMIDA = 1
const MISMATCH = 2

let cola = [EXACTA]

function lectura(campos = {}) {
  return {
    name: 'Rampant Growth',
    setCode: '2X2',
    collectorNumber: '155',
    rarity: 'common',
    language: 'English',
    ...campos
  }
}

beforeEach(() => {
  cola = [EXACTA]

  apiCall.mockImplementation(async (accion, datos = {}) => {
    if (accion === 'scan_resolve') {
      return {
        status: 'success',
        http_code: 200,
        data: {
          results: datos.lecturas.map((l) => {
            const indice = cola.length > 1 ? cola.shift() : cola[0]

            return { ...fixtura(VEREDICTOS)[indice], id: l.id }
          })
        }
      }
    }

    if (accion === 'collection_add') return fixtura(altaFixture)
    if (accion === 'deck_list') return fixtura(listaMazosFixture)
    if (accion === 'deck_get') return { status: 'success', data: { conflicts: [] }, http_code: 200 }

    return { status: 'error', message: `sin doble para ${accion}`, http_code: 500 }
  })
})

/**
 * Monta la hoja con las lecturas ya resueltas.
 *
 * Se siembra llamando al store DE VERDAD y no metiendo filas a mano: una fila
 * inventada aquí podría tener una forma que `resolver()` nunca produce, y
 * entonces el test pasaría sobre algo que no existe.
 */
async function montarHoja({ lecturas = [lectura()], attachTo } = {}) {
  const montaje = await montarVista(ScanBottomSheet, {
    ruta: '/',
    estado: SESION,
    ...(attachTo ? { attachTo } : {})
  })

  const escaner = useScanStore()

  for (const l of lecturas) {
    // DOS vueltas por lectura: desde el M3b una lectura no sale del móvil hasta
    // que se la ha visto dos veces seguidas, que es el filtro que mata el ruido
    // del OCR. Sembrar con una sola no dejaría ninguna fila que pintar.
    await escaner.detectar(l)
    await escaner.detectar(l)
  }

  await flushPromises()
  await nextTick()

  return { ...montaje, escaner }
}

/** Las llamadas a `apiCall` de una acción concreta, en orden. */
function llamadasDe(accion) {
  return apiCall.mock.calls.filter(([a]) => a === accion).map(([, datos]) => datos)
}

describe('los ajustes de sesión', () => {
  it('empiezan plegados y enseñan el destino en el resumen', async () => {
    const { wrapper } = await montarHoja({ lecturas: [] })

    expect(wrapper.find('.ajustes').exists()).toBe(false)
    expect(wrapper.find('.hoja__resumen').text()).toBe('A mi colección')
  })

  it('al desplegarlos, el destino va el PRIMERO', async () => {
    const { wrapper } = await montarHoja({ lecturas: [] })

    await wrapper.find('.hoja__plegable').trigger('click')

    const campos = wrapper.findAll('.ajustes__campo > span').map((s) => s.text())

    expect(campos[0]).toBe('Destino')
    // «Manos libres» va el ÚLTIMO desde el M4, y el orden importa: es el único
    // ajuste que hace que la app escriba sin que toques nada, así que no compite
    // por la vista con el destino, que es lo primero que hay que elegir.
    expect(campos).toEqual(['Destino', 'Acabado', 'Idioma', 'Estado', 'Manos libres'])
  })

  it('elegir «un mazo» pide la lista y saca el desplegable de mazos', async () => {
    const { wrapper, escaner } = await montarHoja({ lecturas: [] })

    await wrapper.find('.hoja__plegable').trigger('click')

    // El cambio se hace por el store, que es lo que hace el `@update:model-value`
    // del desplegable: abrirlo de verdad probaría PrimeVue, no esto.
    await escaner.fijarDestino('mazo')
    await escaner.cargarMazos()
    await nextTick()

    expect(llamadasDe('deck_list')).toHaveLength(1)

    const campos = wrapper.findAll('.ajustes__campo > span').map((s) => s.text())

    expect(campos).toEqual(['Destino', 'Mazo', 'Acabado', 'Idioma', 'Estado', 'Manos libres'])
    expect(wrapper.find('.hoja__resumen').text()).toBe('Elige un mazo')
  })

  it('dice que el acabado de sesión no siempre se aplica', async () => {
    const { wrapper } = await montarHoja({ lecturas: [] })

    await wrapper.find('.hoja__plegable').trigger('click')

    expect(wrapper.find('.ajustes__nota').text()).toContain('admite más de uno')
  })
})

describe('una fila resuelta', () => {
  it('pinta nombre, edición, número y el precio del acabado elegido', async () => {
    const { wrapper, errores } = await montarHoja()

    expect(errores).toEqual([])

    const fila = wrapper.find('.fila')

    expect(fila.find('.fila__nombre').text()).toContain('Rampant Growth')
    expect(fila.find('.fila__meta').text()).toContain('2X2')
    expect(fila.find('.fila__meta').text()).toContain('#155')
    // `priceEur.normal`, no `priceNormal` ni el foil: el contrato manda.
    expect(fila.find('.fila__precio').text()).toBe(`${VEREDICTOS[EXACTA].priceEur.normal.toFixed(2)} €`)
  })

  it('ofrece las tres dimensiones antes de confirmar', async () => {
    const { wrapper } = await montarHoja()

    expect(wrapper.findAll('.fila__dimensiones .p-select')).toHaveLength(3)
  })

  it('ofrece SOLO los acabados que esa impresión admite', async () => {
    cola = [ASUMIDA]

    const { wrapper } = await montarHoja({
      lecturas: [lectura({ name: 'Lightning Bolt', setCode: null, collectorNumber: null })],
      attachTo: document.body
    })

    // El desplegable de acabado es el primero de los tres de la fila, y su lista
    // se teletransporta al body: hay que abrirlo de verdad para verla.
    wrapper.findAll('.fila__dimensiones .p-select')[0].element.click()
    await nextTick()
    await nextTick()

    const ofrecidos = [...document.body.querySelectorAll('.p-select-option')].map((o) =>
      o.textContent.trim()
    )

    // `finishes` de esta impresión es `{foil:false, nonfoil:true, etched:false}`.
    expect(ofrecidos).toEqual(['Normal'])
  })

  it('marca la edición asumida y ofrece corregirla', async () => {
    cola = [ASUMIDA]

    const { wrapper } = await montarHoja({
      lecturas: [lectura({ name: 'Lightning Bolt', setCode: null, collectorNumber: null })]
    })

    expect(wrapper.find('.fila__asumida').text()).toContain('edición asumida de 71')
    expect(wrapper.findComponent({ name: 'PrintingSelect' }).exists()).toBe(true)
  })
})

describe('la escritura y su memoria', () => {
  it('marcar el check escribe la carta, sin diálogo ni segundo paso', async () => {
    const { wrapper } = await montarHoja()

    await wrapper.find('input[type="checkbox"]').trigger('change')
    await flushPromises()

    expect(llamadasDe('collection_add')).toHaveLength(1)
    expect(llamadasDe('collection_add')[0].printing_uuid).toBe(VEREDICTOS[EXACTA].printingUuid)
  })

  it('una vez escrita, la fila pierde el check y gana el `+1`', async () => {
    const { wrapper } = await montarHoja()

    expect(wrapper.find('input[type="checkbox"]').exists()).toBe(true)

    await wrapper.find('input[type="checkbox"]').trigger('change')
    await flushPromises()
    await nextTick()

    expect(wrapper.find('input[type="checkbox"]').exists()).toBe(false)
    expect(wrapper.find('.fila__escrita').exists()).toBe(true)
    expect(wrapper.find('.fila__mas').text()).toContain('+1')
    // Y ya no se retocan las dimensiones: corregir una línea escrita es otra cosa.
    expect(wrapper.find('.fila__dimensiones').exists()).toBe(false)
  })

  it('el `+1` escribe otra copia de la misma carta', async () => {
    const { wrapper } = await montarHoja()

    await wrapper.find('input[type="checkbox"]').trigger('change')
    await flushPromises()
    await nextTick()

    await wrapper.find('.fila__mas').trigger('click')
    await flushPromises()
    await nextTick()

    expect(llamadasDe('collection_add')).toHaveLength(2)
    expect(wrapper.find('.fila__copias').text()).toContain('× 2')
  })

  it('una re-detección de algo ya escrito nace SIN check', async () => {
    const { wrapper, escaner } = await montarHoja()

    await wrapper.find('input[type="checkbox"]').trigger('change')
    await flushPromises()

    escaner.limpiarFilas()
    await escaner.detectar(lectura())
    await escaner.detectar(lectura())
    await flushPromises()
    await nextTick()

    expect(wrapper.findAll('.fila')).toHaveLength(1)
    expect(wrapper.find('input[type="checkbox"]').exists()).toBe(false)
    expect(wrapper.find('.fila__escrita').exists()).toBe(true)
  })

  it('un fallo del backend se dice en la fila, y la carta no cuenta como escrita', async () => {
    const { wrapper, escaner } = await montarHoja()

    apiCall.mockResolvedValue({ status: 'error', message: 'Sesión caducada.', http_code: 401 })

    await wrapper.find('input[type="checkbox"]').trigger('change')
    await flushPromises()
    await nextTick()

    // El texto lo pone el store de colección, que traduce el 401 a algo que se
    // puede leer sin saber qué es un código HTTP.
    expect(wrapper.find('.fila__error').text()).toContain('Inicia sesión')
    expect(escaner.estaEscrita(escaner.filas[0])).toBe(false)
    expect(wrapper.find('input[type="checkbox"]').exists()).toBe(true)
  })
})

describe('las dudas', () => {
  it('pinta el motivo y los candidatos con `ImportCandidate`', async () => {
    cola = [MISMATCH]

    const { wrapper } = await montarHoja({
      lecturas: [lectura({ name: "Lim-Dûl's Vault", setCode: 'ICE', collectorNumber: '96' })]
    })

    expect(wrapper.find('.duda__motivo').text()).toContain('no dicen la misma carta')

    const candidatos = wrapper.findAllComponents({ name: 'ImportCandidate' })

    expect(candidatos).toHaveLength(2)
    expect(candidatos[0].text()).toContain("Lim-Dûl's Vault")
    expect(candidatos[1].text()).toContain('Shyft')
    // Sin impresión elegida no hay nada que marcar.
    expect(wrapper.find('input[type="checkbox"]').attributes('disabled')).toBeDefined()
  })

  it('elegir un candidato NO escribe: solo habilita el check', async () => {
    cola = [MISMATCH]

    const { wrapper } = await montarHoja({
      lecturas: [lectura({ name: "Lim-Dûl's Vault", setCode: 'ICE', collectorNumber: '96' })]
    })

    await wrapper.findAll('.candidato')[1].trigger('click')
    await nextTick()

    expect(llamadasDe('collection_add')).toHaveLength(0)
    expect(wrapper.find('.candidato--elegido').exists()).toBe(true)
    expect(wrapper.find('input[type="checkbox"]').attributes('disabled')).toBeUndefined()

    await wrapper.find('input[type="checkbox"]').trigger('change')
    await flushPromises()

    expect(llamadasDe('collection_add')[0].printing_uuid).toBe(
      VEREDICTOS[MISMATCH].candidates[1].printingUuid
    )
  })
})

describe('la lista vacía', () => {
  it('lo dice en vez de dejar un hueco', async () => {
    const { wrapper } = await montarHoja({ lecturas: [] })

    expect(wrapper.find('.hoja__vacio').text()).toContain('Apunta a una carta')
  })
})
