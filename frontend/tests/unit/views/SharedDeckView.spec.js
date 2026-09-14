import { beforeEach, describe, expect, it, vi } from 'vitest'
import { nextTick } from 'vue'
import { flushPromises } from '@vue/test-utils'

import SharedDeckView from '@/views/SharedDeckView.vue'
import { publicGet } from '@/services/api'

import { montarVista } from '../../helpers'

import mazoFixture from '../../fixtures/public_deck.json'
import sinMazoFixture from '../../fixtures/public_deck_not_found.json'

/**
 * `views/SharedDeckView.vue` — el mazo de un enlace, `/shared/deck/:token`.
 *
 * **Se monta SIN sembrar sesión**, igual que el perfil público: el caso de uso
 * entero es pegar el enlace en un Discord y que lo abra alguien que no tiene
 * cuenta. Con el router REAL, que es quien ejecuta el guard: si faltara el
 * `meta: { public: true }` esto acabaría en `/login`, y con un router estubeado
 * el test no se enteraría.
 *
 * Las dos reglas propias de esta vista, y son las que se miran:
 *
 *  1. **El mazo se ve entero, sin preguntar por la privacidad del perfil.**
 *     Compartir es un acto explícito sobre ese mazo.
 *  2. **Pero NO viaja el cruce con la colección** —lo que le falta al dueño, los
 *     conflictos, qué copias tiene libres—. Eso es su inventario colándose por
 *     la puerta de al lado, y el test lo comprueba sobre el HTML pintado y no
 *     solo sobre el JSON.
 *
 * Y el 404: token mal formado, inexistente y revocado son **el mismo** mensaje,
 * porque distinguirlos confirmaría cuáles existen.
 *
 * La fixtura es una captura real de `GET /api/public/deck/{token}`, tomada
 * poniéndole un `share_token` al mazo 17 y devolviéndolo a NULL después.
 */

/**
 * El módulo original se conserva y solo se doblan las funciones de red: la vista
 * pinta `CardImage`, que compone la URL con `API_BASE` de este mismo fichero
 * (`services/scryfall.js:22`). Con una factoría que lo sustituya entero,
 * `API_BASE` viene a `undefined` y el render de cada carta lanza.
 */
vi.mock('@/services/api', async (importarOriginal) => ({
  ...(await importarOriginal()),
  publicGet: vi.fn(),
  catalogGet: vi.fn(),
  apiCall: vi.fn()
}))

/** Copia profunda: el store muta lo que recibe y las fixturas son de todos. */
function fixtura(json) {
  return structuredClone(json)
}

/** Un token con la forma real: 32 bytes de random_bytes() en hex. */
const TOKEN = 'a7d27382ff6d40d3bc8ed68fcafffc07cb0a9597a34eadee404c53609c8733e1'

/** Monta el mazo compartido **sin sesión**, que es como se abre de verdad. */
async function montarMazo(respuesta = fixtura(mazoFixture), token = TOKEN) {
  publicGet.mockResolvedValue(respuesta)

  const montaje = await montarVista(SharedDeckView, { ruta: `/shared/deck/${token}` })

  await flushPromises()
  await nextTick()

  return montaje
}

beforeEach(() => {
  publicGet.mockReset()
})

describe('la ruta pública', () => {
  it('un anónimo abre el enlace y NO acaba en el login', async () => {
    const { router, errores } = await montarMazo()

    expect(router.currentRoute.value.name).toBe('sharedDeck')
    expect(router.currentRoute.value.meta.public).toBe(true)
    expect(errores).toHaveLength(0)
  })

  it('pide el mazo por el token de la URL, sin más', async () => {
    await montarMazo()

    expect(publicGet).toHaveBeenCalledTimes(1)
    expect(publicGet).toHaveBeenCalledWith(`/deck/${TOKEN}`)
  })
})

describe('el mazo pintado', () => {
  it('monta sin lanzar y enseña el nombre, el estado y el formato', async () => {
    const { wrapper, errores } = await montarMazo()

    const deck = fixtura(mazoFixture).deck

    expect(wrapper.find('.compartido__titulo').text()).toBe(deck.name)
    expect(wrapper.find('.cabecera').text()).toContain('Construido')
    expect(wrapper.find('.cabecera').text()).toContain(deck.format)
    expect(errores).toHaveLength(0)
  })

  it('las cifras de la cabecera son las del backend, no cuentas de la vista', async () => {
    const { wrapper } = await montarMazo()

    const datos = fixtura(mazoFixture)
    const cifras = wrapper.find('.cabecera__cifras').text()

    expect(cifras).toContain(String(datos.deck.cards))
    expect(cifras).toContain(String(datos.deck.cardLines))
    expect(cifras).toContain('160,18')
  })

  it('pinta una sección por zona con cartas, y solo esas', async () => {
    const { wrapper } = await montarMazo()

    const boards = fixtura(mazoFixture).boards
    const conCartas = Object.values(boards).filter((z) => z.length > 0).length

    expect(wrapper.findAll('.zona')).toHaveLength(conCartas)
    // La fixtura es un Commander con `main` y `planes`: si alguna vez se
    // pintaran siete zonas, cinco estarían vacías.
    expect(wrapper.text()).toContain('Principal')
    expect(wrapper.text()).toContain('Planos')
  })

  it('pinta una fila por línea de mazo', async () => {
    const { wrapper } = await montarMazo()

    const lineas = fixtura(mazoFixture).cards.length

    expect(wrapper.findAll('tbody tr')).toHaveLength(lineas)
  })

  it('el recuento de cada zona suma las copias, no las líneas', async () => {
    const { wrapper } = await montarMazo()

    const principal = fixtura(mazoFixture).boards.main
    const copias = principal.reduce((suma, carta) => suma + carta.count, 0)

    // 95 líneas y 99 copias en esta fixtura: si el título dijera las líneas, un
    // mazo con cuatro copias de un bosque parecería tener menos cartas.
    expect(copias).not.toBe(principal.length)
    expect(wrapper.findAll('.zona__titulo')[0].text()).toContain(`${copias} carta(s)`)
  })

  it('NO enlaza a la ficha de carta: `/card/:uuid` está tras el guard', async () => {
    const { wrapper } = await montarMazo()

    // Un mazo compartido cuyo primer clic pide iniciar sesión no está
    // compartido. Y de paso, ni un `router-link` con params a `undefined` que
    // tumbe el render.
    expect(wrapper.findAll('a')).toHaveLength(0)
  })
})

describe('lo que NO puede aparecer', () => {
  it('ni un campo del cruce con la colección de su dueño', async () => {
    const { wrapper } = await montarMazo()

    const html = wrapper.html()

    for (const prohibido of [
      'missingCount', 'missing', 'conflicts', 'availability',
      'overallocated', 'inCollection', 'claimed', 'notes'
    ]) {
      expect(html).not.toContain(prohibido)
    }
  })

  it('ni «te faltan», ni nada que hable del inventario de nadie', async () => {
    const { wrapper } = await montarMazo()

    expect(wrapper.text()).not.toMatch(/falta|conflicto|tu colección/i)
  })
})

describe('los avisos de legalidad', () => {
  it('con un mazo en orden no dice nada: repetir «es legal» es ruido', async () => {
    const { wrapper } = await montarMazo()

    // La fixtura es un Commander de 100 cartas sin prohibidas ni restringidas.
    expect(wrapper.find('.legalidad').exists()).toBe(false)
  })

  it('avisa de las prohibidas y de quedarse corto, sin bloquear nada', async () => {
    const datos = fixtura(mazoFixture)
    datos.legality = {
      ...datos.legality,
      banned: 2,
      notLegal: 1,
      size: 98,
      minSize: 100,
      belowMinimum: true
    }

    const { wrapper } = await montarMazo(datos)

    const aviso = wrapper.find('.legalidad')

    expect(aviso.text()).toContain('2 carta(s) prohibida(s) en commander')
    expect(aviso.text()).toContain('1 carta(s) que no se pueden jugar')
    expect(aviso.text()).toContain('pide al menos 100')
    // Avisa y ya: el mazo se sigue pintando entero.
    expect(wrapper.findAll('tbody tr').length).toBeGreaterThan(0)
  })

  it('un formato que `mtg_legality` no conoce se nombra y no marca ninguna carta', async () => {
    const datos = fixtura(mazoFixture)
    datos.legality = { ...datos.legality, format: 'edh', known: false }

    const { wrapper } = await montarMazo(datos)

    expect(wrapper.find('.legalidad').text()).toContain('«edh» no es un formato conocido')
  })

  it('un mazo sin formato no tiene nada de lo que avisar', async () => {
    const datos = fixtura(mazoFixture)
    datos.deck.format = null
    datos.legality = { ...datos.legality, format: null }

    const { wrapper } = await montarMazo(datos)

    expect(wrapper.find('.legalidad').exists()).toBe(false)
    expect(wrapper.find('.cabecera').text()).toContain('sin formato')
  })
})

describe('el enlace que no lleva a ningún sitio', () => {
  it('un token inventado, revocado o mal formado dan el MISMO mensaje', async () => {
    const { wrapper, errores } = await montarMazo(fixtura(sinMazoFixture), 'deadbeef')

    expect(wrapper.text()).toContain('Este enlace no lleva a ningún mazo')
    // Ni una palabra sobre si el token existía: distinguirlo confirmaría cuáles
    // existen, que es justo lo que el 404 único del backend evita.
    expect(wrapper.text()).not.toMatch(/revocad|caducad|existe el token/i)
    expect(wrapper.findAll('.zona')).toHaveLength(0)
    expect(errores).toHaveLength(0)
  })

  it('un fallo de red no se disfraza de enlace muerto', async () => {
    const { wrapper } = await montarMazo({ error: 'network_error' })

    expect(wrapper.text()).toContain('No se pudo contactar con el servidor.')
    expect(wrapper.text()).not.toContain('Este enlace no lleva a ningún mazo')
  })

  it('el 429 del límite por IP dice que se espere, no que recargue', async () => {
    const { wrapper } = await montarMazo({ error: 'rate_limited' })

    expect(wrapper.text()).toMatch(/espera un minuto/i)
  })
})
