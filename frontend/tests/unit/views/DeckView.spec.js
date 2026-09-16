import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { nextTick } from 'vue'
import { flushPromises } from '@vue/test-utils'

import DeckView from '@/views/DeckView.vue'
import { apiCall } from '@/services/api'

import { SESION, montarVista, pulsacionLarga } from '../../helpers'

import deckGetFixture from '../../fixtures/deck_get.json'
import deckUpdateFixture from '../../fixtures/deck_update.json'
import coleccionFixture from '../../fixtures/collection_list.json'
import deckFaltaFixture from '../../fixtures/deck_get_faltantes.json'
import loteFixture from '../../fixtures/import_apply_deseos.json'
import shareFixture from '../../fixtures/deck_share.json'
import unshareFixture from '../../fixtures/deck_unshare.json'

/**
 * `views/DeckView.vue` — el editor de un mazo.
 *
 * El grueso de este fichero es **el aviso de legalidad de `DeckView.vue:371-415`**:
 * es lógica pura dentro de un `computed` y concentra toda la traducción de los
 * avisos. Sus tres reglas, y el orden importa:
 *
 *  1. **Sin formato no se dice nada**: `mtg_deck.format` es NULLable y un mazo
 *     sin formato es un mazo perfectamente válido.
 *  2. **Un formato que `mtg_legality` no conoce se dice y no se marca**: el
 *     formato lo teclea el usuario, y con «edh» en vez de «commander» no hay ni
 *     una fila y las cien cartas saldrían «no permitida» — una alarma falsa y
 *     entera.
 *  3. **Y si todo está en orden, tampoco se dice nada**: un panel que repite «el
 *     mazo es legal» en cada carga es ruido, no información.
 *
 * `deck_get.json` viene con `legality.statuses` **vacío** —el mazo capturado es
 * legal—, así que los casos a marcar se construyen **en memoria** sobre la
 * fixtura con la forma real del backend (`GetDeck.php:152`:
 * `statuses[oracle_id] = estado->value`), sin inventar ningún `.json`.
 *
 * Y **ojo con copiar de aquí a la colección**: `stores/deck.js` comparte los
 * nombres `hayMas`, `vacio`, `estaGuardando` y `estaAnadiendo` con
 * `stores/collection.js` pero indexa por `cardId` de línea de mazo, no por el
 * `item.id` de la colección.
 */

/**
 * Se dobla solo la I/O y se conserva el resto del módulo: `CardImage` compone
 * la URL de cada miniatura con `API_BASE` (`services/scryfall.js:23,42`).
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

/** El mazo capturado: `Tom bombadil`, id 17, 96 líneas y un conflicto. */
const ID = deckGetFixture.data.deck.id

/**
 * La ficha capturada **recortada a las primeras líneas**.
 *
 * Las 96 reales pintan 96 `Select`, 96 `InputNumber` y 96 miniaturas, y eso
 * convierte cada test en segundos. Se recorta EN MEMORIA —no se toca el
 * fichero—, porque lo que se prueba aquí es qué decide la vista con una línea,
 * no cuántas caben.
 */
function ficha(cuantas = 3) {
  const datos = fixtura(deckGetFixture)

  datos.data.cards = datos.data.cards.slice(0, cuantas)

  return datos
}

/** Un doble que responde según la ACCIÓN, que es como funciona el endpoint único. */
function respondeSegunAccion(mapa) {
  apiCall.mockImplementation((accion) =>
    Promise.resolve(
      mapa[accion] ?? { status: 'error', message: `Sin doble para ${accion}.`, http_code: 500 }
    )
  )
}

/** El escenario base: la ficha capturada y una colección para el índice. */
function backend(datos = ficha(), extra = {}) {
  respondeSegunAccion({
    deck_get: datos,
    collection_list: fixtura(coleccionFixture),
    ...extra
  })
}

/**
 * Teclear un formato a mano y salir del campo.
 *
 * El `blur` del `Select` **sale con 100 ms de retraso** (`Select.vue:360-368`),
 * a propósito: es lo que deja que el clic sobre una opción llegue antes que el
 * blur del input. Sin esperarlo aquí, el `deck_update` todavía no ha salido y el
 * test fallaría por el reloj, no por la vista.
 */
async function teclearFormato(wrapper, texto) {
  const campo = wrapper.find('.cabecera__campo--formato input')

  await campo.setValue(texto)
  await campo.trigger('blur')
  await new Promise((resolver) => setTimeout(resolver, 150))
  await flushPromises()
}

async function montarMazo(opciones = {}) {
  const montaje = await montarVista(DeckView, { ruta: `/deck/${ID}`, estado: SESION, ...opciones })

  await flushPromises()
  await nextTick()

  return montaje
}

beforeEach(() => {
  apiCall.mockReset()
  backend()
})

describe('el primer render', () => {
  it('monta sin lanzar, pide la ficha y el índice de la colección, y pinta la cabecera', async () => {
    const { wrapper, errores } = await montarMazo()

    const mazo = fixtura(deckGetFixture).data.deck

    expect(errores).toEqual([])
    expect(apiCall).toHaveBeenCalledWith('deck_get', { deck_id: ID })
    // El índice de lo que hay en las cajas, para que el clic del buscador pueda
    // resolver acabado, idioma y estado sin preguntar (`DeckView.vue:540-542`).
    expect(apiCall).toHaveBeenCalledWith('collection_list', expect.objectContaining({ sort: 'name' }))

    expect(wrapper.find('.mazo__titulo').text()).toBe(mazo.name)
    expect(wrapper.find('.cabecera__campo--nombre input').element.value).toBe(mazo.name)

    // Las cifras son del backend y aquí solo se formatean (`DeckView.vue:70-88`).
    const cifras = wrapper.find('.cabecera__cifras').text()

    expect(cifras).toContain(String(mazo.cards))
    expect(cifras).toContain(`${fixtura(deckGetFixture).data.valueEur.toFixed(2)} €`)
  })

  it('agrupa por zona y marca los tokens como lo que son: algo que no cuenta', async () => {
    // La capturada trae `main` y `planes`. Se añade una línea de tokens EN
    // MEMORIA, clonando una real y cambiándole el `board`, porque los tokens son
    // el caso que la regla del `CLAUDE.md` obliga a no sumar.
    const datos = ficha(2)
    const token = structuredClone(datos.data.cards[0])

    token.id = 99999
    token.board = 'tokens'
    token.count = 4
    datos.data.cards.push(token)

    backend(datos)

    const { wrapper } = await montarMazo()

    const zonas = wrapper.findAll('.zona')

    // En el orden del ENUM y solo las que tienen algo (`stores/deck.js:126-131`).
    expect(zonas).toHaveLength(2)
    expect(zonas[0].find('.zona__titulo').text()).toContain('Principal')
    expect(zonas[1].find('.zona__titulo').text()).toContain('Tokens')
    expect(zonas[1].find('.zona__nota').text())
      .toContain('los tokens no cuentan para el tamaño ni para el valor')

    // Y a una zona que no cuenta no se le piden cuentas de disponibilidad: un
    // guion, no un «faltan 4» de algo que nadie posee (`DeckView.vue:250-258`).
    expect(zonas[1].find('.zona__tenue').text()).toBe('—')
  })

  it('el título de cada zona cuenta COPIAS, no líneas', async () => {
    const datos = ficha(2)

    datos.data.cards[0].count = 4

    backend(datos)

    const { wrapper } = await montarMazo()

    // 4 + 1 = 5 copias en 2 líneas (`DeckView.vue:425-428`).
    expect(wrapper.find('.zona__titulo').text()).toContain('5 carta(s)')
    expect(wrapper.findAll('.zona .p-datatable-tbody > tr')).toHaveLength(2)
  })

  it('el mazo vacío lo dice, y no deja una tabla fantasma', async () => {
    backend(ficha(0))

    const { wrapper } = await montarMazo()

    expect(wrapper.find('.mazo__vacio').text()).toContain('El mazo está vacío')
    expect(wrapper.findAll('.zona')).toHaveLength(0)
  })

  it('un error del backend se dice y no tumba la pantalla', async () => {
    respondeSegunAccion({
      deck_get: { status: 'error', message: 'Ese mazo no existe.', http_code: 404 },
      collection_list: fixtura(coleccionFixture)
    })

    const { wrapper, errores } = await montarMazo()

    expect(errores).toEqual([])
    expect(wrapper.find('.mazo__error').text()).toContain('Ese mazo no existe.')
    expect(wrapper.find('.cabecera').exists()).toBe(false)
  })

  /**
   * **El clic normal sobre la miniatura sigue navegando** (M1 de «Vista Ampliada
   * de la Carta»), aquí contra el envoltorio más terco de los tres: un
   * `router-link` (`DeckView.vue:315-320`), que no escucha con un `@click` de
   * Vue sino con su propio manejador interno.
   *
   * `CardImage` lleva un listener de `click` en **fase de captura** sobre su
   * raíz, y M0 midió que desde ahí `stopPropagation()` mata incluso el manejador
   * del `router-link` —también vive en fase de burbuja sobre el `<a>`—. Por eso
   * ese listener solo traga cuando su bandera `debeTragarClick` está armada, y
   * nace en `false`: apuntar y hacer clic tiene que seguir llevando a la ficha.
   * Si alguien deja la bandera armada de serie (era el spike de M0), este test
   * se pone rojo.
   *
   * **Su par complementario llega con M2**, el hito que arma la bandera: una
   * pulsación larga en táctil amplía la carta y al soltar NO navega.
   */
  it('un clic sobre la MINIATURA navega a la ficha: el enlace sigue vivo', async () => {
    const { wrapper, router } = await montarMazo()

    /**
     * Se espía `router.push` —llamándolo de verdad— en vez de mirar solo la ruta
     * resultante, y no es un capricho: el componente de `/card/:uuid` es un
     * `import()` perezoso que en ESTE fichero nadie ha cargado todavía, así que
     * la navegación tarda MÁS que cualquier `flushPromises` razonable. Mirando
     * solo `currentRoute` el test daba falsos verdes —se midió en M0—: la ruta
     * aún no había cambiado, no es que no fuera a cambiar. `push` sí se llama de
     * forma síncrona dentro del manejador del `router-link`, así que es lo que
     * se afirma; la ruta resultante se espera aparte, con `vi.waitFor`.
     */
    const push = vi.spyOn(router, 'push')

    const miniatura = wrapper.find('.zona__mini img')

    expect(miniatura.exists()).toBe(true)

    await miniatura.trigger('click')
    await flushPromises()
    await nextTick()

    expect(push).toHaveBeenCalledTimes(1)

    // `vi.waitFor` y no un `flushPromises`: el destino es un `import()` perezoso.
    await vi.waitFor(() => expect(router.currentRoute.value.name).toBe('card'))

    push.mockRestore()
  })

  /**
   * **El par complementario del anterior** (M2): mantener el dedo sobre la
   * miniatura la amplía, y al soltar **no** navega.
   *
   * La secuencia es la real: `pointerdown(touch)` → 450 ms → `pointerup` →
   * `click`. `pointerup` arma `debeTragarClick` porque la ampliación llegó a
   * abrirse, y el listener de captura de `CardImage` mata el `click` antes de
   * que el `router-link` lo vea.
   *
   * Se afirma sobre el espía de `push` y NO sobre `router.currentRoute`, por lo
   * mismo que explica el test de arriba: el componente de `/card/:uuid` es un
   * `import()` perezoso y la ruta tarda más que cualquier espera razonable, así
   * que «no ha cambiado» no distingue «no iba a cambiar» de «todavía no ha
   * llegado». Es el falso verde que M0 midió.
   */
  it('una PULSACIÓN LARGA amplía y al soltar NO navega', async () => {
    const { wrapper, router } = await montarMazo()

    const push = vi.spyOn(router, 'push')

    const miniatura = wrapper.find('.zona__mini img')

    expect(miniatura.exists()).toBe(true)

    await pulsacionLarga(miniatura)
    await miniatura.trigger('click')
    await flushPromises()
    await nextTick()

    expect(push).not.toHaveBeenCalled()
    expect(router.currentRoute.value.name).toBe('deck')

    push.mockRestore()
  })
})

describe('el aviso de sobreasignación', () => {
  it('nombra a TODOS los implicados y enlaza a los otros con su id, nunca a sí mismo', async () => {
    const { wrapper, router, errores } = await montarMazo()

    const conflicto = fixtura(deckGetFixture).data.conflicts[0]
    const otros = conflicto.decks.filter((d) => d.id !== ID)

    expect(errores).toEqual([])
    expect(wrapper.find('.conflicto__titulo').text())
      .toContain('Este mazo se pelea con otros por las mismas cartas')

    const linea = wrapper.find('.conflicto__lista li').text()

    expect(linea).toContain(conflicto.name)
    expect(linea).toContain(`piden ${conflicto.claimed} y tienes ${conflicto.inCollection}`)
    // Este mazo se nombra, pero no se enlaza a sí mismo.
    expect(linea).toContain('este mazo')

    const enlaces = wrapper.findAll('.conflicto__mazos a')

    expect(enlaces).toHaveLength(otros.length)

    enlaces.forEach((enlace, i) => {
      // Resoluble contra las rutas REALES: un `/deck/undefined` también
      // existiría, y un `params` obligatorio a `undefined` **lanza**.
      const destino = router.resolve(enlace.attributes('href').replace(/^#/, ''))

      expect(destino.name).toBe('deck')
      expect(destino.params.id).toBe(String(otros[i].id))
      expect(enlace.text()).toBe(otros[i].name)
    })
  })

  it('ofrece desmontar solo si el mazo está construido, y es un deck_update normal', async () => {
    backend(ficha(), { deck_update: fixtura(deckUpdateFixture) })

    const { wrapper } = await montarMazo()

    const boton = wrapper.find('.conflicto button')

    expect(boton.text()).toContain('Desmontar este mazo')

    await boton.trigger('click')
    await flushPromises()

    // El análisis INFORMA, no actúa: nada desmonta un mazo solo, y cuando lo
    // desmonta el usuario es un `deck_update` corriente (`stores/deck.js:353-355`).
    expect(apiCall).toHaveBeenCalledWith('deck_update', { deck_id: ID, status: 'dismantled' })
  })

  it('un conflicto entre OTROS dos mazos no sale en esta ficha', async () => {
    // Los conflictos son globales, no de este mazo: el store se los traga
    // enteros y la vista filtra los que la implican (`DeckView.vue:348-352`).
    const datos = ficha()

    datos.data.conflicts[0].decks = [
      { id: 21, name: 'Food and Fellowship', claimed: 1 },
      { id: 99, name: 'Otro mazo cualquiera', claimed: 1 }
    ]

    backend(datos)

    const { wrapper } = await montarMazo()

    expect(wrapper.find('.conflicto').exists()).toBe(false)
  })
})

/**
 * La legalidad, que es **un aviso y nunca un bloqueo**: no hay botón que pulsar,
 * no impide guardar nada y no cambia el mazo. Un mazo ilegal es un mazo que
 * existe.
 */
describe('el aviso de legalidad', () => {
  /** La ficha con la legalidad sustituida por la forma real del backend. */
  async function conLegalidad(legalidad, cartas = 2) {
    const datos = ficha(cartas)

    datos.data.legality = { ...datos.data.legality, ...legalidad }
    backend(datos)

    return montarMazo()
  }

  it('sin formato no se dice nada: un mazo sin formato es un mazo válido', async () => {
    const { wrapper } = await conLegalidad({ format: null, known: false })

    expect(wrapper.find('.legalidad').exists()).toBe(false)
  })

  it('con todo en orden tampoco se dice nada: repetir «es legal» es ruido', async () => {
    // Es literalmente la fixtura capturada: el mazo de David es legal.
    const { wrapper } = await montarMazo()

    expect(fixtura(deckGetFixture).data.legality).toMatchObject({
      known: true, banned: 0, restricted: 0, notLegal: 0, belowMinimum: false
    })
    expect(wrapper.find('.legalidad').exists()).toBe(false)
  })

  it('un formato que mtg_legality no conoce se dice, y NO marca ninguna carta', async () => {
    const { wrapper } = await conLegalidad({ format: 'edh', known: false })

    const aviso = wrapper.find('.legalidad')

    expect(aviso.find('.legalidad__titulo').text()).toContain('«edh» no es un formato conocido')
    expect(aviso.find('.legalidad__lista li').text())
      .toContain('No hay legalidades para ese nombre')
    // Y lo que hay que teclear en su lugar, porque si no el usuario se queda
    // igual: en minúsculas y como los escribe MTGJSON.
    expect(aviso.text()).toContain('commander, modern, legacy')
    expect(wrapper.findAll('.zona__legalidad')).toHaveLength(0)
  })

  it('prohibidas, restringidas y no jugables se enumeran por separado', async () => {
    const { wrapper } = await conLegalidad({
      format: 'modern',
      known: true,
      banned: 2,
      restricted: 1,
      notLegal: 3
    })

    const lineas = wrapper.findAll('.legalidad__lista li').map((l) => l.text())

    expect(wrapper.find('.legalidad__titulo').text()).toContain('Avisos de legalidad en modern')
    expect(lineas).toEqual([
      '2 carta(s) prohibida(s) en modern.',
      '1 carta(s) restringida(s) en modern: el formato permite una sola copia de cada una.',
      '3 carta(s) que no se pueden jugar en modern.'
    ])

    // `restricted` se nombra —callarlo diría «legal» de una carta que no lo es—
    // pero la regla de una copia **no se implementa**: eso sería validar.
    expect(wrapper.find('.legalidad__nota').text()).toBe('Es un aviso: el mazo se guarda igual.')
  })

  it('un mazo por debajo del mínimo lo dice con las dos cifras, sin contar tokens', async () => {
    const { wrapper } = await conLegalidad({
      format: 'commander',
      known: true,
      size: 63,
      minSize: 100,
      belowMinimum: true
    })

    expect(wrapper.find('.legalidad__lista li').text())
      .toBe('El mazo tiene 63 carta(s) —sin contar tokens— y commander pide al menos 100.')
  })

  it('la marca por carta va pegada al nombre, es de la CARTA y no marca tokens', async () => {
    const datos = ficha(2)
    const token = structuredClone(datos.data.cards[0])

    token.id = 99999
    token.board = 'tokens'
    datos.data.cards.push(token)

    // La forma real del backend: `statuses[oracle_id] = estado->value`
    // (`GetDeck.php:152`). Por `oracleId`, así que la línea de tokens comparte
    // el mismo y aun así NO se marca (`stores/deck.js:145-147`).
    datos.data.legality = {
      ...datos.data.legality,
      format: 'modern',
      known: true,
      banned: 1,
      statuses: { [datos.data.cards[0].oracleId]: 'banned' }
    }

    backend(datos)

    const { wrapper } = await montarMazo()

    const marcas = wrapper.findAll('.zona__legalidad')

    // Una sola marca: la de la línea de `main`, no la del token con el mismo
    // oracle.
    expect(marcas).toHaveLength(1)
    expect(marcas[0].text()).toBe('Prohibida')
  })
})

describe('la edición en línea de la cabecera', () => {
  it('renombrar manda SOLO el nombre: la edición es parcial', async () => {
    backend(ficha(), { deck_update: fixtura(deckUpdateFixture) })

    const { wrapper } = await montarMazo()

    const campo = wrapper.find('.cabecera__campo--nombre input')

    await campo.setValue('  Tom Bombadil, el viejo  ')
    await campo.trigger('blur')
    await flushPromises()

    // Sin `status` ni `format` ni `notes`: cambiar el nombre no puede borrar lo
    // demás (`DeckView.vue:24-28`). Y el valor va recortado.
    expect(apiCall).toHaveBeenCalledWith('deck_update', {
      deck_id: ID,
      name: 'Tom Bombadil, el viejo'
    })
  })

  it('un nombre vacío no se guarda: se repone el que había', async () => {
    const { wrapper } = await montarMazo()

    const campo = wrapper.find('.cabecera__campo--nombre input')

    await campo.setValue('   ')
    await campo.trigger('blur')
    await flushPromises()

    expect(apiCall).not.toHaveBeenCalledWith('deck_update', expect.anything())
    expect(campo.element.value).toBe(fixtura(deckGetFixture).data.deck.name)
  })

  it('el formato se normaliza a minúsculas antes de mandarlo', async () => {
    backend(ficha(), { deck_update: fixtura(deckUpdateFixture) })

    const { wrapper } = await montarMazo()

    await teclearFormato(wrapper, 'Modern')

    // El `Select` con `editable` devuelve el texto TAL CUAL: sin el
    // `toLowerCase()` de la vista, «Commander» escrito a mano dejaría de casar
    // con `commander` y el aviso diría «no es un formato conocido».
    expect(apiCall).toHaveBeenCalledWith('deck_update', { deck_id: ID, format: 'modern' })
  })

  it('borrar el formato lo manda como null, no como cadena vacía', async () => {
    backend(ficha(), { deck_update: fixtura(deckUpdateFixture) })

    const { wrapper } = await montarMazo()

    await teclearFormato(wrapper, '')

    // `mtg_deck.format` es NULLable: una cadena vacía sería un formato llamado «».
    expect(apiCall).toHaveBeenCalledWith('deck_update', { deck_id: ID, format: null })
  })
})

/**
 * **El desplegable de formato** — el hito del #17.
 *
 * Las opciones salen de `deck_get`, que las trae de `mtg_format` (la tabla que
 * destila `catalog:import` con su `SELECT DISTINCT`), **nunca de una lista
 * escrita en la vista**: una copiada aquí se desincroniza sola en cuanto MTGJSON
 * publique un formato nuevo, y entonces la app ofrecería uno que el backend no
 * conoce, o callaría uno que sí.
 *
 * Y hay una trampa que hace pasar estos tests en verde **en falso**: el overlay
 * del `Select` se teletransporta al `document.body` y NO está en
 * `wrapper.html()`. Mirar el `.p-select-label` diría cuál quedó seleccionado,
 * que no es lo que se prueba aquí: lo que se prueba es **qué se ofrecía**.
 */
describe('el desplegable de formato', () => {
  /** Los 21 formatos capturados del backend real, que trae la fixtura. */
  const FORMATOS = deckGetFixture.data.legality.formatosDisponibles

  /** La ficha con otro formato de mazo y, si hace falta, otra legalidad. */
  async function conFichaDeFormato(formatoDelMazo, legalidad = {}) {
    const datos = ficha(2)

    datos.data.deck.format = formatoDelMazo
    datos.data.legality = { ...datos.data.legality, format: formatoDelMazo, ...legalidad }

    backend(datos, { deck_update: fixtura(deckUpdateFixture) })

    return montarMazo()
  }

  /** Abre el desplegable de verdad y devuelve lo que ofrece, leído del body. */
  async function formatosOfrecidos(wrapper) {
    await wrapper.find('.cabecera__campo--formato .p-select').trigger('click')
    await nextTick()
    await nextTick()

    return [...document.body.querySelectorAll('.p-select-option')].map((o) => o.textContent.trim())
  }

  it('ofrece los 21 formatos que manda el backend, y fuera del wrapper', async () => {
    const { wrapper } = await montarMazo()

    // La fixtura es la respuesta literal del backend: 21 formatos, los de
    // `mtg_format`. Si mañana MTGJSON publica el 22, esta cifra la cambia una
    // recaptura, no una lista en la vista.
    expect(FORMATOS).toHaveLength(21)
    expect(await formatosOfrecidos(wrapper)).toEqual(FORMATOS)

    // Y la trampa: no están en el wrapper, porque el overlay cuelga del body.
    expect(wrapper.html()).not.toContain('oathbreaker')
  })

  it('el mazo SIN formato también ofrece la lista entera: es el que más la necesita', async () => {
    // El mazo 9006 de la base de dev: `building` y con `format` a NULL. Si la
    // lista se rellenara solo cuando el formato ya existe, el desplegable
    // saldría vacío justo en el mazo al que le falta elegirlo.
    const { wrapper } = await conFichaDeFormato(null, { known: false })

    expect(wrapper.find('.cabecera__campo--formato input').element.value).toBe('')
    expect(await formatosOfrecidos(wrapper)).toEqual(FORMATOS)
  })

  it('elegir «commander» de la lista lo guarda sin teclear ni una letra', async () => {
    const { wrapper } = await conFichaDeFormato(null, { known: false })

    const antesDeAbrir = [...document.body.querySelectorAll('.p-select-option')]

    await formatosOfrecidos(wrapper)

    const commander = [...document.body.querySelectorAll('.p-select-option')].find(
      (o) => o.textContent.trim() === 'commander'
    )

    // Cerrado no ofrece nada, así que lo de abajo no es un resto de otro test.
    expect(antesDeAbrir).toHaveLength(0)
    expect(commander).toBeDefined()

    // `mousedown`, que es lo que escucha la opción del `Select`
    // (`Select.vue:145`) — y no por capricho: elegir con el ratón tiene que
    // ganarle al `blur` del input, que sale 100 ms después.
    commander.dispatchEvent(new MouseEvent('mousedown', { bubbles: true }))
    await flushPromises()

    // Un clic, y el `deck_update` sale ya: elegir de la lista no espera al blur.
    expect(apiCall).toHaveBeenCalledWith('deck_update', { deck_id: ID, format: 'commander' })
  })

  it('teclear NO manda un deck_update por letra, aunque el Select emita change en cada una', async () => {
    const { wrapper } = await conFichaDeFormato(null, { known: false })

    const campo = wrapper.find('.cabecera__campo--formato input')

    await campo.setValue('m')
    await campo.setValue('mo')
    await campo.setValue('mod')
    await flushPromises()

    // `onEditableInput` del `Select` llama al mismo `updateModel()` que el clic
    // en una opción, así que `change` sale también tecleando: guardar ahí sin
    // mirar el `originalEvent` serían tres altas de formato por «mod».
    expect(apiCall).not.toHaveBeenCalledWith('deck_update', expect.anything())
  })

  it('un formato inventado se sigue pudiendo escribir, y se sigue avisando de que no existe', async () => {
    // `mtg_deck.format` es texto libre acotado y un formato casero es legítimo:
    // el desplegable ofrece, no encierra.
    const { wrapper } = await conFichaDeFormato('commander')

    await teclearFormato(wrapper, 'formato de la cocina')

    expect(apiCall).toHaveBeenCalledWith('deck_update', {
      deck_id: ID,
      format: 'formato de la cocina'
    })
  })

  it('y con uno inventado se sigue avisando, con la lista ahí para corregirlo', async () => {
    // El aviso de `known: false` no lo quita el desplegable: es lo único que
    // distingue «ese formato no existe» de «existe y tu mazo es ilegal». Va en
    // su propio test porque dos vistas montadas a la vez comparten el
    // `document.body`, y los overlays de las dos se mezclarían ahí.
    const { wrapper } = await conFichaDeFormato('edh', { known: false })

    expect(wrapper.find('.legalidad__titulo').text()).toContain('«edh» no es un formato conocido')
    expect(await formatosOfrecidos(wrapper)).toEqual(FORMATOS)
  })
})

/**
 * **«Mandar lo que falta a deseos»** — el hito de las dos puertas masivas.
 *
 * Lo que hay que cazar aquí no es que el botón exista, sino **con qué
 * dimensiones nace el deseo**. El consumo del mazo cruza la colección por
 * `printing_uuid`, `finish`, `language` y `condition_grade` con `is_wishlist =
 * 0` (`MySqlDeckRepository.php:187-200`), así que un deseo nacido en
 * `normal`/`English`/`NM` no cerraría el hueco de un `foil`: el usuario compra
 * la carta y el mazo se la sigue pidiendo.
 *
 * `deck_get_faltantes.json` está capturada justamente para eso: sus tres líneas
 * que faltan son `etched`/`Spanish`/`EX`, `normal`/`Japanese`/`LP` y
 * `foil`/`English`/`NM`, y la cuarta es un `board: "tokens"`.
 */
describe('mandar lo que falta a deseos', () => {
  const ID_FALTAN = deckFaltaFixture.data.deck.id

  /** Las tres líneas de `availability` a las que les falta algo. */
  const FALTAN = deckFaltaFixture.data.availability.filter((l) => l.missing > 0)

  async function conFaltantes(extra = {}) {
    respondeSegunAccion({
      deck_get: fixtura(deckFaltaFixture),
      collection_list: fixtura(coleccionFixture),
      import_apply: fixtura(loteFixture),
      ...extra
    })

    const montaje = await montarVista(DeckView, {
      ruta: `/deck/${ID_FALTAN}`,
      estado: SESION
    })

    await flushPromises()
    await nextTick()

    return montaje
  }

  it('con el mazo completo no hay botón: no hay nada que comprar', async () => {
    // `deck_get.json` viene con `missing: 0` — el mazo 17 está montado entero.
    const { wrapper } = await montarMazo()

    expect(wrapper.find('.faltan').exists()).toBe(false)
  })

  it('con cartas que faltan se pinta el panel, con los ejemplares y las líneas', async () => {
    const { wrapper, errores } = await conFaltantes()

    expect(errores).toEqual([])

    const panel = wrapper.find('.faltan')

    expect(panel.exists()).toBe(true)
    // 3 ejemplares en 3 líneas. El `missing` del backend cuenta EJEMPLARES.
    expect(panel.text()).toContain(`Te faltan ${deckFaltaFixture.data.missing} ejemplar(es)`)
    expect(panel.text()).toContain(`${FALTAN.length} línea(s)`)
  })

  it('EL HITO: el deseo nace con las CUATRO dimensiones del mazo, no con los defectos', async () => {
    // Primero, que la fixtura pruebe algo: si las tres líneas fueran
    // `normal`/`English`/`NM`, mandar los defectos pasaría este test en verde.
    expect(FALTAN.map((l) => l.finish)).toEqual(['etched', 'normal', 'foil'])
    expect(FALTAN.map((l) => l.language)).toEqual(['Spanish', 'Japanese', 'English'])
    expect(FALTAN.map((l) => l.condition)).toEqual(['EX', 'LP', 'NM'])

    const { wrapper } = await conFaltantes()

    await wrapper.find('.faltan__boton').trigger('click')
    await flushPromises()

    const llamada = apiCall.mock.calls.find(([accion]) => accion === 'import_apply')

    expect(llamada).toBeDefined()
    expect(llamada[1].is_wishlist).toBe(true)
    expect(llamada[1].rows).toEqual(
      FALTAN.map((l) => ({
        printingUuid: l.printingUuid,
        finish: l.finish,
        language: l.language,
        condition: l.condition,
        quantity: l.missing
      }))
    )
  })

  it('de UN clic y en UNA petición: las tres cartas, no tres llamadas', async () => {
    const { wrapper } = await conFaltantes()

    await wrapper.find('.faltan__boton').trigger('click')
    await flushPromises()

    const escrituras = apiCall.mock.calls.filter(
      ([accion]) => accion === 'import_apply' || accion === 'collection_add'
    )

    expect(escrituras).toHaveLength(1)
    expect(escrituras[0][1].rows).toHaveLength(3)
  })

  it('los tokens no se mandan a deseos, y ni siquiera llegan al análisis', async () => {
    const { wrapper } = await conFaltantes()

    // La cuarta línea del mazo es un `board: "tokens"` que el usuario tampoco
    // tiene. `faltantes()` filtra `board <> 'tokens'`, así que no está en
    // `availability` y nada de lo que se manda lo menciona.
    const tokens = deckFaltaFixture.data.cards.find((c) => c.board === 'tokens')

    expect(tokens).toBeDefined()
    expect(FALTAN.some((l) => l.printingUuid === tokens.printingUuid)).toBe(false)

    await wrapper.find('.faltan__boton').trigger('click')
    await flushPromises()

    const filas = apiCall.mock.calls.find(([a]) => a === 'import_apply')[1].rows

    expect(filas).toHaveLength(3)
    expect(filas.some((f) => f.printingUuid === tokens.printingUuid)).toBe(false)
  })

  it('NO toca el mazo: ni lo actualiza, ni lo desmonta, ni lo vuelve a pedir', async () => {
    const { wrapper } = await conFaltantes()

    const antes = apiCall.mock.calls.filter(([a]) => a === 'deck_get').length

    await wrapper.find('.faltan__boton').trigger('click')
    await flushPromises()

    // El análisis INFORMA, no actúa. Y refrescar sería una petición para pintar
    // los mismos números: un deseo no cuenta como colección, así que `missing`
    // vale exactamente lo mismo antes y después.
    expect(apiCall).not.toHaveBeenCalledWith('deck_update', expect.anything())
    expect(apiCall.mock.calls.filter(([a]) => a === 'deck_get')).toHaveLength(antes)
    expect(wrapper.find('.faltan').text())
      .toContain(`Te faltan ${deckFaltaFixture.data.missing} ejemplar(es)`)
  })

  it('confirma con el aviso de los DESEOS, que es otro store y otro componente', async () => {
    const { wrapper } = await conFaltantes()

    await wrapper.find('.faltan__boton').trigger('click')
    await flushPromises()
    await nextTick()

    // Un solo aviso a la vista y es el de la lista de deseos: mandar cartas a
    // deseos no es un cambio del mazo.
    const avisos = wrapper.findAll('.aviso')

    expect(avisos).toHaveLength(1)
    expect(avisos[0].text()).toContain('lista de deseos')
  })

  it('un fallo del backend se dice y no deja el botón colgado', async () => {
    const { wrapper } = await conFaltantes({
      import_apply: { status: 'error', message: 'Demasiadas peticiones.', http_code: 429 }
    })

    await wrapper.find('.faltan__boton').trigger('click')
    await flushPromises()
    await nextTick()

    expect(wrapper.find('.aviso--error').text()).toContain('Demasiadas peticiones.')
    // Y el botón vuelve a estar disponible: el lote no se escribió.
    expect(wrapper.find('.faltan__boton').attributes('disabled')).toBeUndefined()
  })
})

/**
 * **El botón de compartir** — el hito del M6 en esta vista.
 *
 * Tres cosas que este bloque fija y que no se ven mirando la plantilla:
 *
 *  - **La URL que se enseña es ABSOLUTA y la compone el cliente.** El backend
 *    devuelve la relativa (`/#/shared/deck/<token>`) a propósito, porque lo
 *    único que tendría para adivinar el origen es la cabecera `Host`, que la
 *    manda el cliente. Aquí se comprueba contra el **router real** que esa URL
 *    abre de verdad `/shared/deck/:token`, que es la ruta pública que estrenó el
 *    M5 y que hasta este hito no tenía ni un token con el que abrirse.
 *  - **«Dejar de compartir» está SIEMPRE**, también sin ningún enlace en
 *    pantalla: `deck_get` no devuelve `share_token`, así que al recargar nadie
 *    sabe si el mazo seguía compartido, y la acción es idempotente en el
 *    backend. Es el único botón que mata un enlace que ya se te fue de las manos.
 *  - **`navigator.clipboard` no existe en jsdom** —y en el navegador solo vive en
 *    contextos seguros—, así que se dobla. El camino de «no se pudo copiar» se
 *    prueba **sin** doblarlo, que es el caso real de un `http://` que no sea
 *    localhost.
 */
describe('el enlace público del mazo', () => {
  const TOKEN = shareFixture.data.shareToken

  /** Monta la ficha con las dos acciones del enlace dobladas. */
  async function conCompartir(extra = {}) {
    backend(ficha(2), {
      deck_share: fixtura(shareFixture),
      deck_unshare: fixtura(unshareFixture),
      ...extra
    })

    return montarMazo()
  }

  /** Pulsa el botón que genera el enlace y espera a que la URL esté pintada. */
  async function compartir(wrapper) {
    await wrapper.find('.compartir__boton').trigger('click')
    await flushPromises()
    await nextTick()
  }

  /** El doble del portapapeles, que jsdom no trae. */
  function portapapeles() {
    const escribir = vi.fn().mockResolvedValue(undefined)

    Object.defineProperty(navigator, 'clipboard', {
      value: { writeText: escribir },
      configurable: true
    })

    return escribir
  }

  afterEach(() => {
    // Se quita siempre: dejarlo puesto haría pasar en verde el test del
    // navegador que NO deja copiar, que es justo el que importa.
    delete navigator.clipboard
  })

  it('antes de compartir no hay ninguna URL, y el aviso no promete que se recuerde', async () => {
    const { wrapper, errores } = await conCompartir()

    expect(errores).toEqual([])
    expect(wrapper.find('.compartir__boton').text()).toContain('Compartir por enlace')
    expect(wrapper.find('.compartir__url').exists()).toBe(false)
    expect(wrapper.find('.compartir__olvido').text())
      .toContain('no recuerda si este mazo estaba compartido')
    // Y la promesa que sí se puede cumplir: no se enseña nada de la colección.
    expect(wrapper.find('.compartir__nota').text()).toContain('sin necesitar cuenta')
  })

  it('ofrece «dejar de compartir» aunque no haya ningún enlace a la vista', async () => {
    const { wrapper } = await conCompartir()

    const revocar = wrapper.find('.compartir__acciones .compartir__revocar')

    expect(revocar.text()).toContain('Dejar de compartir')

    await revocar.trigger('click')
    await flushPromises()

    // Es idempotente en el backend, y es el único camino para matar un enlace
    // que ya no se tiene delante —el caso de después de recargar—.
    expect(apiCall).toHaveBeenCalledWith('deck_unshare', { deck_id: ID })
  })

  it('compartir enseña la URL absoluta, y el router REAL la resuelve al mazo compartido', async () => {
    const { wrapper, router, errores } = await conCompartir()

    await compartir(wrapper)

    expect(errores).toEqual([])
    expect(apiCall).toHaveBeenCalledWith('deck_share', { deck_id: ID })

    const url = wrapper.find('.compartir__url').element.value

    expect(url).toBe(`${window.location.origin}/#/shared/deck/${TOKEN}`)

    // La prueba que importa: esa URL abre una ruta que existe y es pública. Un
    // `#` perdido al componerla la dejaría resolviendo al catch-all en silencio.
    const destino = router.resolve(url.split('#')[1])

    expect(destino.name).toBe('sharedDeck')
    expect(destino.params.token).toBe(TOKEN)
    expect(destino.meta.public).toBe(true)
  })

  it('el campo de la URL es de solo lectura: se copia, no se edita', async () => {
    const { wrapper } = await conCompartir()

    await compartir(wrapper)

    expect(wrapper.find('.compartir__url').attributes('readonly')).toBeDefined()
  })

  it('«copiar» manda la URL al portapapeles y lo dice', async () => {
    const escribir = portapapeles()
    const { wrapper } = await conCompartir()

    await compartir(wrapper)

    expect(wrapper.find('.compartir__copiar').text()).toContain('Copiar')

    await wrapper.find('.compartir__copiar').trigger('click')
    await flushPromises()

    expect(escribir).toHaveBeenCalledWith(`${window.location.origin}/#/shared/deck/${TOKEN}`)
    expect(wrapper.find('.compartir__copiar').text()).toContain('Copiado')
    expect(wrapper.find('.compartir__error').exists()).toBe(false)
  })

  it('sin portapapeles no se finge que se copió: se dice y el enlace sigue en pantalla', async () => {
    const { wrapper } = await conCompartir()

    await compartir(wrapper)

    // Sin doblar `navigator.clipboard`: es el caso real de un `http://` que no
    // sea localhost, donde el navegador ni define la API.
    await wrapper.find('.compartir__copiar').trigger('click')
    await flushPromises()

    expect(wrapper.find('.compartir__error').text()).toContain('cópialo a mano')
    expect(wrapper.find('.compartir__copiar').text()).toContain('Copiar')
    expect(wrapper.find('.compartir__url').element.value).toContain(TOKEN)
  })

  it('un portapapeles que rechaza tampoco se pinta como éxito', async () => {
    Object.defineProperty(navigator, 'clipboard', {
      value: { writeText: vi.fn().mockRejectedValue(new Error('denegado')) },
      configurable: true
    })

    const { wrapper } = await conCompartir()

    await compartir(wrapper)
    await wrapper.find('.compartir__copiar').trigger('click')
    await flushPromises()

    expect(wrapper.find('.compartir__error').text()).toContain('No se pudo copiar')
    expect(wrapper.find('.compartir__copiar').text()).toContain('Copiar')
  })

  it('dejar de compartir mata el enlace y lo quita de la pantalla', async () => {
    const { wrapper } = await conCompartir()

    await compartir(wrapper)

    expect(wrapper.find('.compartir__url').exists()).toBe(true)

    await wrapper.find('.compartir__enlace .compartir__revocar').trigger('click')
    await flushPromises()
    await nextTick()

    expect(apiCall).toHaveBeenCalledWith('deck_unshare', { deck_id: ID })
    // No queda ni la URL ni el token a la vista: el enlace ya no resuelve.
    expect(wrapper.find('.compartir__url').exists()).toBe(false)
    expect(wrapper.html()).not.toContain(TOKEN)
    expect(wrapper.find('.aviso').text()).toContain('El enlace ya no funciona')
  })

  it('un fallo al compartir se dice y no deja media URL pintada', async () => {
    const { wrapper, errores } = await conCompartir({
      deck_share: { status: 'error', message: 'Ese mazo no existe o no es tuyo.', http_code: 404 }
    })

    await compartir(wrapper)

    expect(errores).toEqual([])
    expect(wrapper.find('.compartir__url').exists()).toBe(false)
    expect(wrapper.find('.aviso--error').text()).toContain('Ese mazo no existe o no es tuyo.')
  })

  it('volver a compartir avisa de que el enlace de antes muere', async () => {
    const { wrapper } = await conCompartir()

    await compartir(wrapper)

    // Regenerar invalida el anterior (`ShareDeck.php:26-31`), y quien mira la
    // pantalla tiene que saberlo ANTES de volver a pulsar.
    expect(wrapper.find('.compartir__olvido').text()).toContain('mata este')
  })
})
