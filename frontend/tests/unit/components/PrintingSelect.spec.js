import { beforeEach, describe, expect, it, vi } from 'vitest'
import { nextTick } from 'vue'
import { flushPromises } from '@vue/test-utils'

import PrintingSelect from '@/components/PrintingSelect.vue'
import { catalogGet } from '@/services/api'

import { montarVista } from '../../helpers'

import impresionesFixture from '../../fixtures/catalog_printings.json'
import paginadasFixture from '../../fixtures/catalog_printings_paginadas.json'
import paginadas2Fixture from '../../fixtures/catalog_printings_paginadas_2.json'
import unicaFixture from '../../fixtures/catalog_printings_unica.json'
import noEncontradaFixture from '../../fixtures/catalog_printing_not_found.json'

/**
 * `components/PrintingSelect.vue` — el desplegable que corrige una edición
 * asumida antes de importarla.
 *
 * Lo que se protege, por orden de lo que costaría que se rompiera:
 *
 *  1. **La carga bajo demanda.** Es la razón de que el componente exista tal
 *     como está: una previsualización de 300 conflictos monta 300 de estos, y
 *     pedir el endpoint en `onMounted` serían 300 peticiones para enseñar cero
 *     desplegables abiertos. Cuelga de `@before-show`, y **eso no se ve en
 *     ningún sitio salvo aquí**: si alguien lo mueve a `onMounted`, la vista
 *     sigue pintando exactamente igual.
 *  2. **Que el precio se lee de `priceEur` y no de los alias del SQL.** Es el
 *     pecado que tumbó `/decks` y `DeckCardSearch.vue`, y no lanza: pinta «sin
 *     precio» en todas las opciones y nadie se entera.
 *  3. **El pie «Ver más».** Un `Forest` son 949 impresiones y la primera página
 *     100: sin el pie, las otras 849 no existen para quien las busca — el mismo
 *     callejón sin salida que este plan abrió el endpoint para cerrar.
 *
 * **Dos trampas, las dos de PrimeVue:**
 *  - El `Select` **teletransporta su lista al `document.body`**, no al wrapper.
 *    Las opciones se leen de ahí, y abriendo el desplegable de verdad: mirar el
 *    `.p-select-label` diría cuál quedó seleccionada, no cuáles se ofrecían.
 *  - Una opción se elige con **`mousedown`**, no con `click`.
 *
 * Las fixturas son respuestas literales de `GET /api/catalog/cards/{uuid}/printings`
 * (ver `tests/fixtures/README.md`), no listas escritas a mano: el contrato de un
 * item es el de `aContrato()`, con `finishes` y `priceEur` anidados, y una
 * fixtura redactada aquí repetiría el error que se quiere cazar.
 */

vi.mock('@/services/api', async (importarOriginal) => ({
  ...(await importarOriginal()),
  catalogGet: vi.fn()
}))

/** Copia profunda: el componente mapea lo que recibe y las fixturas son de todos. */
function fixtura(json) {
  return structuredClone(json)
}

/** El uuid con el que se capturó `catalog_printings.json` (Lightning Bolt, 71). */
const UUID = '095866d8-baf8-5e2e-8a51-ba0849ce9503'

async function montarSelector(props = {}) {
  return montarVista(PrintingSelect, {
    ruta: '/import',
    attachTo: document.body,
    props: {
      printingUuid: UUID,
      printingCount: impresionesFixture.items.length,
      nombre: 'Lightning Bolt',
      ...props
    }
  })
}

/**
 * Abre (o cierra) el desplegable como lo haría el ratón sobre el campo.
 *
 * El `setTimeout(0)` no es un adorno: el `hide()` del `Select` se aplaza a un
 * macrotask a propósito —«For ScreenReaders», `primevue/select/index.mjs:331`—,
 * así que sin ceder el hilo el cierre no ha ocurrido todavía y el clic
 * siguiente vuelve a cerrar en vez de abrir. Con solo microtasks el alternar
 * se descuadra y el test mide otra cosa.
 */
async function alternar(wrapper) {
  await wrapper.find('.p-select').trigger('click')
  await new Promise((resolve) => setTimeout(resolve, 0))
  await flushPromises()
  await nextTick()
}

/** Lo que el desplegable OFRECE, leído del `document.body`. */
function opcionesOfrecidas() {
  return [...document.body.querySelectorAll('.p-select-option')].map((o) =>
    o.textContent.replace(/\s+/g, ' ').trim()
  )
}

beforeEach(() => {
  catalogGet.mockReset()
  catalogGet.mockResolvedValue(fixtura(impresionesFixture))
})

describe('la carga bajo demanda', () => {
  it('NO pide nada al montar', async () => {
    const { errores } = await montarSelector()

    await flushPromises()

    // 300 filas de edición asumida = 300 de estos componentes. Pedir aquí
    // serían 300 peticiones para enseñar cero desplegables abiertos.
    expect(catalogGet).not.toHaveBeenCalled()
    expect(errores).toEqual([])
  })

  it('pide las impresiones de SU uuid al desplegarse, con el limit del router', async () => {
    const { wrapper } = await montarSelector()

    await alternar(wrapper)

    expect(catalogGet).toHaveBeenCalledTimes(1)

    const [ruta, params] = catalogGet.mock.calls[0]

    expect(ruta).toBe(`/cards/${UUID}/printings`)
    // 100 es el `LIMITE_MAXIMO` del router, no su defecto de 60: con 100 se
    // cubre de una tirada cualquier carta reimpresa que no sea una tierra
    // básica. `cursor: null` es la primera página; `catalogGet` lo descarta.
    expect(params).toEqual({ limit: 100, cursor: null })
  })

  it('no repite la petición al cerrar y reabrir', async () => {
    const { wrapper } = await montarSelector()

    await alternar(wrapper)
    await alternar(wrapper)
    await alternar(wrapper)

    expect(catalogGet).toHaveBeenCalledTimes(1)
  })
})

describe('lo que ofrece el desplegable', () => {
  it('pinta cada impresión con su edición, su número y su precio de `priceEur`', async () => {
    const { wrapper } = await montarSelector()

    await alternar(wrapper)

    const ofrecidas = opcionesOfrecidas()

    expect(ofrecidas).toHaveLength(impresionesFixture.items.length)

    // La primera de la fixtura, que es la más reciente: el endpoint ordena de
    // nueva a vieja y el desplegable no reordena nada.
    const primera = impresionesFixture.items[0]

    expect(ofrecidas[0]).toContain(primera.setName)
    expect(ofrecidas[0]).toContain(`(${primera.setCode})`)
    expect(ofrecidas[0]).toContain(`#${primera.collectorNumber}`)
    expect(ofrecidas[0]).toContain(`${primera.priceEur.normal.toFixed(2)} €`)
  })

  it('cae al foil cuando no hay precio normal, y dice «sin precio» si no hay ninguno', async () => {
    const { wrapper } = await montarSelector()

    await alternar(wrapper)

    const ofrecidas = opcionesOfrecidas().join(' | ')

    // `Secret Lair Promo (SLP) #37`: sin precio normal y con foil de 1999,95 €.
    // Enseñar «sin precio» aquí sería mentir sobre una carta de dos mil euros.
    const soloFoil = impresionesFixture.items.find(
      (c) => c.priceEur.normal === null && c.priceEur.foil !== null
    )

    expect(ofrecidas).toContain(`${soloFoil.priceEur.foil.toFixed(2)} €`)

    // Y `Alchemy Horizons: Baldur's Gate (HBG) #926`, que no cotiza en ningún
    // acabado: *no se sabe* no es 0, así que se dice con palabras.
    const sinCotizar = impresionesFixture.items.find(
      (c) => c.priceEur.normal === null && c.priceEur.foil === null && c.priceEur.etched === null
    )

    expect(ofrecidas).toContain('sin precio')
    expect(ofrecidas).toContain(`(${sinCotizar.setCode})`)
  })

  it('el precio NO sale de los alias del SQL', async () => {
    // El fallo que tumbó `/decks` y `DeckCardSearch`: leer `priceNormal` en vez
    // de `priceEur.normal`. No lanza — pinta «sin precio» en TODO y ofrece
    // acabados que no existen—, así que se caza contando.
    const { wrapper } = await montarSelector()

    await alternar(wrapper)

    const conPrecio = opcionesOfrecidas().filter((t) => t.includes('€'))
    const cotizadas = impresionesFixture.items.filter(
      (c) => c.priceEur.normal !== null || c.priceEur.foil !== null || c.priceEur.etched !== null
    )

    expect(conPrecio).toHaveLength(cotizadas.length)
    expect(conPrecio.length).toBeGreaterThan(0)
  })

  it('el nombre de una doble cara no se parte: viaja entero al `aria-label`', async () => {
    const { wrapper } = await montarSelector({
      nombre: 'Ulvenwald Captive // Ulvenwald Abomination'
    })

    // El `aria-label` cuelga del `.p-select-label` —el `role="combobox"`—, no de
    // la raíz del componente.
    expect(wrapper.find('.p-select-label').attributes('aria-label')).toBe(
      'Cambiar la edición de Ulvenwald Captive // Ulvenwald Abomination'
    )
  })
})

describe('elegir una impresión', () => {
  it('emite `elegir` con el printingUuid concreto y nada más', async () => {
    const { wrapper } = await montarSelector()

    await alternar(wrapper)

    const objetivo = impresionesFixture.items[3]
    const opcion = [...document.body.querySelectorAll('.p-select-option')].find((o) =>
      o.textContent.includes(`(${objetivo.setCode})`)
    )

    expect(opcion).toBeDefined()

    // `mousedown`, que es lo que escucha la opción del `Select`.
    opcion.dispatchEvent(new MouseEvent('mousedown', { bubbles: true }))
    await flushPromises()
    await nextTick()

    // Lo que emite es el uuid pelado: quién lo guarda y dónde aterriza en el
    // payload de `import_apply` es cosa de `ImportView`.
    expect(wrapper.emitted('elegir')).toEqual([[objetivo.uuid]])
  })

  it('con `elegida` puesta, el campo enseña la corregida y no la asumida', async () => {
    const elegida = impresionesFixture.items[5]

    const { wrapper } = await montarSelector({ elegida: elegida.uuid })

    await alternar(wrapper)

    expect(wrapper.find('.p-select-label').text()).toContain(elegida.setName)
  })
})

describe('el pie «Ver más»', () => {
  it('no aparece cuando el backend no dejó `nextCursor`', async () => {
    const { wrapper } = await montarSelector()

    await alternar(wrapper)

    expect(impresionesFixture.nextCursor).toBeNull()
    expect(document.body.querySelector('.impresiones__pie')).toBeNull()
  })

  it('aparece con el `nextCursor`, pagina con él y AÑADE en vez de sustituir', async () => {
    // El peor caso del M0: `Forest`, 949 impresiones. Las fixturas se capturaron
    // con `limit=3` para tener las dos páginas sin 140 KB de fixtura.
    catalogGet.mockResolvedValueOnce(fixtura(paginadasFixture))
    catalogGet.mockResolvedValueOnce(fixtura(paginadas2Fixture))

    const { wrapper } = await montarSelector({ printingCount: 949 })

    await alternar(wrapper)

    expect(opcionesOfrecidas()).toHaveLength(3)

    const pie = document.body.querySelector('.impresiones__pie button')

    expect(pie).not.toBeNull()
    // «N de M»: lo que ya se ofrece contra lo que tiene la carta. Sin ese
    // recuento, el usuario no sabe si faltan tres impresiones o novecientas.
    expect(pie.textContent).toContain('Ver más (3 de 949)')

    pie.dispatchEvent(new MouseEvent('click', { bubbles: true }))
    await flushPromises()
    await nextTick()

    expect(catalogGet).toHaveBeenCalledTimes(2)
    expect(catalogGet.mock.calls[1][1]).toEqual({
      limit: 100,
      cursor: paginadasFixture.nextCursor
    })

    // Las tres nuevas se SUMAN: sustituirlas dejaría el desplegable paginando
    // hacia adelante y perdiendo lo que el usuario ya estaba mirando.
    const ofrecidas = opcionesOfrecidas()

    expect(ofrecidas).toHaveLength(6)
    expect(ofrecidas[0]).toContain(paginadasFixture.items[0].setName)
    expect(ofrecidas[5]).toContain(paginadas2Fixture.items[2].setName)
  })
})

describe('los casos en los que no hay nada que elegir', () => {
  it('una carta de impresión única ofrece su única fila', async () => {
    catalogGet.mockResolvedValue(fixtura(unicaFixture))

    const { wrapper } = await montarSelector({ printingCount: 1 })

    await alternar(wrapper)

    // 200 con un item, no 404: el endpoint distingue «esta carta no tiene
    // hermanas» de «este uuid no existe», y el componente también.
    expect(opcionesOfrecidas()).toHaveLength(1)
    expect(document.body.querySelector('.impresiones__pie')).toBeNull()
  })

  it('un 404 del catálogo se avisa FUERA del desplegable, y reabrir reintenta', async () => {
    // `catalogGet` devuelve el cuerpo del error tal cual (`api.js:143-146`):
    // `{error: 'printing_not_found'}`, sin `items`.
    catalogGet.mockResolvedValueOnce(fixtura(noEncontradaFixture))

    const { wrapper } = await montarSelector()

    await alternar(wrapper)

    // El aviso va fuera del overlay a propósito: si la petición falla, el
    // desplegable no tiene opciones que enseñar y un mensaje que solo se lee
    // abriéndolo no se lee.
    expect(wrapper.find('.impresiones__error').text()).toContain(
      'No se pudieron cargar las impresiones'
    )

    // Y la carga fallida NO queda marcada como hecha: cerrar y reabrir tiene
    // que volver a intentarlo, o un corte de red deja el desplegable muerto
    // para el resto de la previsualización.
    await alternar(wrapper)
    await alternar(wrapper)

    expect(catalogGet).toHaveBeenCalledTimes(2)
    expect(opcionesOfrecidas()).toHaveLength(impresionesFixture.items.length)
    expect(wrapper.find('.impresiones__error').exists()).toBe(false)
  })
})
