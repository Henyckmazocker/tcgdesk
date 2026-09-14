import { beforeEach, describe, expect, it, vi } from 'vitest'
import { nextTick } from 'vue'
import { flushPromises } from '@vue/test-utils'

import DecksView from '@/views/DecksView.vue'
import { apiCall } from '@/services/api'

import { SESION, montarVista } from '../../helpers'

import deckListFixture from '../../fixtures/deck_list.json'
import deckGetFixture from '../../fixtures/deck_get.json'
import deckCreateFixture from '../../fixtures/deck_create.json'
import deckDeleteFixture from '../../fixtures/deck_delete.json'

/**
 * `views/DecksView.vue` — **la vista que motiva todo este plan**.
 *
 * El 2026-09-10 `/decks` se caía entera en cuanto había un conflicto de
 * sobreasignación: el aviso leía los alias del SQL
 * (`deckId`/`deckName`/`reclamado`, `MySqlDeckRepository.php:976-978`) en vez del
 * contrato que el backend devuelve de verdad (`id`/`name`/`claimed`, mapeados en
 * `:1001-1003`). El detonante es `DecksView.vue:43`:
 * `<router-link :to="{ name: 'deck', params: { id: implicado.id } }">`, y un
 * `params` obligatorio a `undefined` **lanza** —`Missing required param "id"`,
 * desde el `useLink` de vue-router— en vez de fallar en silencio: tumba el
 * render entero y la rejilla de mazos se queda a cero.
 *
 * Por eso aquí NO se stubea el router. `RouterLinkStub` de `@vue/test-utils` no
 * resuelve la ruta, así que con un `params.id` a `undefined` no lanzaría y este
 * fichero habría pasado en verde el día del fallo. El error lo produce el router
 * al resolver, no el componente al pintar (`helpers.js:18-25`).
 *
 * Y por eso hacen falta **las dos fixturas**: la vista encadena `deck_list` →
 * `deck_get` (`DecksView.vue:284` → `stores/deck.js:250-262`), y
 * `cargarConflictos()` se rinde devolviendo `conflicts: []` si en la lista no
 * hay ningún mazo `built` (`stores/deck.js:276-281`), **sin llegar a preguntar
 * por los conflictos**. Con una lista sin construidos, el test de regresión
 * pasaría en verde sin haber probado nada: ese es justo el falso positivo que
 * este plan existe para impedir.
 */

vi.mock('@/services/api', () => ({
  apiCall: vi.fn(),
  catalogGet: vi.fn()
}))

/** Copia profunda: el store muta lo que recibe y las fixturas son de todos. */
function fixtura(json) {
  return structuredClone(json)
}

/**
 * Un doble que responde según la ACCIÓN, que es como funciona el endpoint
 * único. La vista encadena dos acciones en un solo `onMounted`, así que una cola
 * de `mockResolvedValueOnce` se rompería al cambiar el orden.
 */
function respondeSegunAccion(mapa) {
  apiCall.mockImplementation((accion) =>
    Promise.resolve(
      mapa[accion] ?? { status: 'error', message: `Sin doble para ${accion}.`, http_code: 500 }
    )
  )
}

/** Lo capturado del backend real: 2 mazos `built` y 1 conflicto entre ellos. */
function backendConConflicto() {
  respondeSegunAccion({
    deck_list: fixtura(deckListFixture),
    deck_get: fixtura(deckGetFixture)
  })
}

/**
 * Monta la vista y espera a que la cadena `deck_list` → `deck_get` haya
 * terminado **y** el DOM se haya repintado con lo que trajo.
 */
async function montarDecks(opciones = {}) {
  const montaje = await montarVista(DecksView, { ruta: '/decks', estado: SESION, ...opciones })

  await flushPromises()
  await nextTick()

  return montaje
}

beforeEach(() => {
  apiCall.mockReset()
})

describe('el aviso de sobreasignación', () => {
  /**
   * ESTE ES EL TEST DE REGRESIÓN DEL PLAN. Su nombre es literal y es condición
   * de cierre: con `id` cambiado a `deckId` dentro de `conflicts[].decks[]` de
   * `tests/fixtures/deck_get.json`, **tiene que ponerse en rojo**.
   */
  it('el enlace de cada mazo implicado en un conflicto sale con su id', async () => {
    backendConConflicto()

    const { wrapper, router, errores } = await montarDecks()

    const conflicto = fixtura(deckGetFixture).data.conflicts[0]

    // Lo primero, el fallo histórico tal cual: un `params.id` a `undefined`
    // LANZA al resolver, y el helper recoge esos errores asíncronos en
    // `errores` (`helpers.js:109-119`). Con la fixtura alterada, aquí salen dos
    // `Missing required param "id"` —uno por mazo implicado—.
    expect(errores.map((error) => error.message)).toEqual([])

    // Y la consecuencia: el error tumba el render del bloque entero, así que si
    // lanza no queda ni un enlace que mirar.
    const enlaces = wrapper.findAll('.conflicto__mazo a')

    expect(enlaces).toHaveLength(conflicto.decks.length)

    // El `href` de cada implicado tiene que ser RESOLUBLE y llevar a SU mazo:
    // no basta con que exista, porque un `/deck/undefined` también existiría.
    const idsPintados = enlaces.map((enlace) => {
      const href = enlace.attributes('href')

      expect(href).toBeTruthy()

      // `createWebHashHistory` (`router/index.js:79`), así que el href sale como
      // `#/deck/21`; se resuelve contra las rutas REALES de la app.
      const destino = router.resolve(href.replace(/^#/, ''))

      expect(destino.name).toBe('deck')
      expect(destino.matched).not.toHaveLength(0)

      return destino.params.id
    })

    // Y son los ids del contrato del backend (`id`), no los alias del SQL.
    expect(idsPintados).toEqual(conflicto.decks.map((mazo) => String(mazo.id)))

    // El nombre del implicado también es del contrato (`name`, no `deckName`).
    expect(enlaces.map((enlace) => enlace.text())).toEqual(
      conflicto.decks.map((mazo) => mazo.name)
    )
  })

  it('los conflictos se piden encadenando deck_list → deck_get, no en una acción propia', async () => {
    backendConConflicto()

    await montarDecks()

    // No hay acción de análisis: `conflicts` viaja dentro de la ficha de
    // cualquier mazo construido (`DeckController.php:125` ← `stores/deck.js:283`).
    expect(apiCall).toHaveBeenCalledWith('deck_list')
    expect(apiCall).toHaveBeenCalledWith('deck_get', {
      deck_id: fixtura(deckListFixture).data.decks.find((m) => m.status === 'built').id
    })
  })

  it('sin ningún mazo construido no se pregunta por los conflictos y no hay aviso', async () => {
    // La otra mitad de lo mismo: solo `built` consume colección, así que sin
    // construidos `cargarConflictos()` se rinde sin llamar a `deck_get`
    // (`stores/deck.js:276-281`). Se altera la fixtura EN MEMORIA, sin tocar el
    // fichero: la lista capturada trae los dos mazos en `built`.
    const lista = fixtura(deckListFixture)
    lista.data.decks.forEach((mazo) => { mazo.status = 'building' })

    respondeSegunAccion({ deck_list: lista, deck_get: fixtura(deckGetFixture) })

    const { wrapper } = await montarDecks()

    expect(apiCall).not.toHaveBeenCalledWith('deck_get', expect.anything())
    expect(wrapper.find('.conflicto').exists()).toBe(false)
  })
})

describe('la rejilla de mazos', () => {
  it('pinta una tarjeta por mazo, con su enlace, su estado y su valor en euros', async () => {
    backendConConflicto()

    const { wrapper, errores } = await montarDecks()

    const mazos = fixtura(deckListFixture).data.decks
    const tarjetas = wrapper.findAll('.mazo')

    expect(errores).toEqual([])
    expect(tarjetas).toHaveLength(mazos.length)

    tarjetas.forEach((tarjeta, i) => {
      expect(tarjeta.find('.mazo__nombre').text()).toBe(mazos[i].name)
      expect(tarjeta.find('.mazo__enlace').attributes('href')).toBe(`#/deck/${mazos[i].id}`)
      // El valor NO se calcula en la vista: `valueEur` lo da el backend y aquí
      // solo se formatea (`DecksView.vue:228-230`).
      expect(tarjeta.text()).toContain(`${mazos[i].valueEur.toFixed(2)} €`)
      // «Construido», no `built`: la etiqueta sale de `constants/collection.js:113`.
      expect(tarjeta.text()).toContain('Construido')
    })
  })

  it('sin mazos enseña el vacío, y no la rejilla', async () => {
    respondeSegunAccion({
      deck_list: { status: 'success', message: 'Mazos.', data: { decks: [], count: 0 }, http_code: 200 }
    })

    const { wrapper } = await montarDecks()

    expect(wrapper.find('.mazos__vacio').exists()).toBe(true)
    expect(wrapper.findAll('.mazo')).toHaveLength(0)
  })

  it('un error del backend se enseña y deja la lista vacía', async () => {
    respondeSegunAccion({
      deck_list: { status: 'error', message: 'No autorizado.', http_code: 401 }
    })

    const { wrapper } = await montarDecks()

    expect(wrapper.find('.mazos__error').text()).toContain('No autorizado.')
  })
})

/**
 * Los dos diálogos van al `document.body` y NO están dentro del wrapper: el
 * `Dialog` de PrimeVue teletransporta su contenido (`appendTo: 'body'`), igual
 * que el `Popover`. Buscarlos con `wrapper.find` daría siempre vacío.
 */
function enElDialogo(selector) {
  return document.body.querySelector(selector)
}

/** El botón del diálogo cuyo texto es `etiqueta`. */
function botonDelDialogo(etiqueta) {
  return [...document.body.querySelectorAll('.p-dialog button')]
    .find((boton) => boton.textContent.trim() === etiqueta)
}

describe('los dos diálogos', () => {
  it('crear un mazo lo manda con el estado elegido y lleva a su ficha', async () => {
    respondeSegunAccion({
      deck_list: fixtura(deckListFixture),
      deck_get: fixtura(deckGetFixture),
      deck_create: fixtura(deckCreateFixture)
    })

    const { wrapper, router } = await montarDecks({ attachTo: document.body })

    await wrapper.find('.mazos__bar button:last-child').trigger('click')
    await nextTick()

    const nombre = enElDialogo('.p-dialog input.p-inputtext')

    nombre.value = 'Mazo de pruebas'
    nombre.dispatchEvent(new Event('input'))
    await nextTick()

    botonDelDialogo('Crear').click()
    await flushPromises()

    // `status: 'building'` es el defecto del formulario, y el formato vacío
    // viaja como null y no como cadena vacía (`DecksView.vue:249-254`).
    expect(apiCall).toHaveBeenCalledWith('deck_create', {
      name: 'Mazo de pruebas',
      status: 'building',
      format: null,
      notes: null
    })

    await vi.waitFor(() => expect(router.currentRoute.value.name).toBe('deck'))
    expect(router.currentRoute.value.params.id)
      .toBe(String(fixtura(deckCreateFixture).data.deck.id))
  })

  it('borrar pregunta SIEMPRE por las cartas, y por defecto no toca la colección', async () => {
    respondeSegunAccion({
      deck_list: fixtura(deckListFixture),
      deck_get: fixtura(deckGetFixture),
      deck_delete: fixtura(deckDeleteFixture)
    })

    const { wrapper } = await montarDecks({ attachTo: document.body })

    const primero = fixtura(deckListFixture).data.decks[0]

    await wrapper.findAll('.mazo__pie button')[0].trigger('click')
    await nextTick()

    // «He deshecho la lista» y «he vendido el mazo entero» son cosas distintas
    // y el backend no adivina cuál es, así que se pregunta. La casilla arranca
    // sin marcar: descontar de la colección es lo excepcional.
    expect(enElDialogo('.p-dialog input[type="checkbox"]').checked).toBe(false)
    expect(document.body.querySelector('.borrado__texto').textContent).toContain(primero.name)

    botonDelDialogo('Borrar').click()
    await flushPromises()

    expect(apiCall).toHaveBeenCalledWith('deck_delete', {
      deck_id: primero.id,
      with_cards: false
    })

    // Y al borrar se vuelven a pedir los conflictos: si el que se fue era uno de
    // los implicados, el aviso tiene que dejar de salir (`DecksView.vue:269-277`).
    expect(apiCall).toHaveBeenLastCalledWith('deck_get', expect.anything())
  })
})
