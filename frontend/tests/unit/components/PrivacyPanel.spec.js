import { beforeEach, describe, expect, it, vi } from 'vitest'
import { nextTick } from 'vue'
import { flushPromises } from '@vue/test-utils'

import PrivacyPanel from '@/components/PrivacyPanel.vue'
import { apiCall } from '@/services/api'
import { SECCIONES as SECCIONES_DEL_PERFIL } from '@/stores/publicProfile'

import { SESION, montarVista } from '../../helpers'

import privacyGetFixture from '../../fixtures/privacy_get.json'
import privacySetFixture from '../../fixtures/privacy_set.json'

/**
 * `components/PrivacyPanel.vue` — el panel de privacidad del M6.
 *
 * Es la pantalla que decide qué datos personales se publican, así que lo que se
 * prueba aquí no es que pinte cinco desplegables sino las tres cosas que, de
 * salir mal, publican algo que el usuario creía cerrado:
 *
 *  1. **La edición es PARCIAL.** Cada selector manda `privacy_set` con **su**
 *     sección y nada más. Mandar las cinco escribiría cuatro decisiones que
 *     nadie acaba de tomar y pisaría lo que otra pestaña hubiera cambiado en
 *     medio.
 *  2. **Nada es optimista.** El selector no se mueve hasta que el backend
 *     confirma: enseñar «Nadie» sobre una sección que sigue siendo pública
 *     porque la petición falló es la peor mentira que puede contar esta
 *     pantalla.
 *  3. **`friends` no miente.** La tabla `friendships` no existe todavía y el
 *     backend responde `false` a todo el mundo (fail-closed a propósito). El
 *     nivel se puede elegir —guardarlo es válido— pero ni la opción ni el panel
 *     pueden dejar creer que hay alguien viendo algo.
 *
 * **La trampa de PrimeVue, y aquí por partida quíntuple**: el overlay del
 * `Select` se teletransporta al `document.body` y NO está en `wrapper.html()`.
 * Mirar el `.p-select-label` dice **cuál quedó seleccionado**, que no es lo
 * mismo que **qué se ofrecía**: para lo segundo hay que abrir el desplegable de
 * verdad y leer del body.
 *
 * Las dos fixturas son la respuesta literal del backend de dev (usuario 1, sin
 * fila en `user_privacy_settings`, restaurado después): `privacy_get.json` son
 * los cinco defectos y `privacy_set.json` es el resultado de cambiar **una
 * sola** sección.
 */

vi.mock('@/services/api', async (importarOriginal) => ({
  ...(await importarOriginal()),
  apiCall: vi.fn()
}))

/** Copia profunda: el componente muta lo que recibe y las fixturas son de todos. */
function fixtura(json) {
  return structuredClone(json)
}

/** Los cinco defectos capturados del backend: la privacidad de quien no ha tocado nada. */
const DEFECTOS = privacyGetFixture.data.privacy

/** Las etiquetas de los tres niveles, tal y como las ve una persona. */
const NADIE = 'Nadie'
const AMIGOS = 'Solo amigos'
const TODOS = 'Cualquiera'

/** Un doble que responde según la ACCIÓN, que es como funciona el endpoint único. */
function respondeSegunAccion(mapa) {
  apiCall.mockImplementation((accion) =>
    Promise.resolve(
      mapa[accion] ?? { status: 'error', message: `Sin doble para ${accion}.`, http_code: 500 }
    )
  )
}

async function montarPanel(extra = {}) {
  respondeSegunAccion({
    privacy_get: fixtura(privacyGetFixture),
    privacy_set: fixtura(privacySetFixture),
    ...extra
  })

  const montaje = await montarVista(PrivacyPanel, { estado: SESION })

  await flushPromises()
  await nextTick()

  return montaje
}

/** La fila de una sección por su clave de backend. */
function fila(wrapper, clave) {
  return wrapper.find(`.seccion--${clave}`)
}

/** Cuál quedó seleccionado en esa sección (el `.p-select-label`, no la lista). */
function elegido(wrapper, clave) {
  return fila(wrapper, clave).find('.p-select-label').text()
}

/** Abre el desplegable de una sección de VERDAD y devuelve lo que ofrece. */
async function nivelesOfrecidos(wrapper, clave) {
  await fila(wrapper, clave).find('.p-select').trigger('click')
  await nextTick()
  await nextTick()

  return [...document.body.querySelectorAll('.p-select-option')].map((o) => o.textContent.trim())
}

/** Elige un nivel del desplegable abierto, como lo haría el ratón. */
async function elegir(wrapper, clave, etiqueta) {
  await nivelesOfrecidos(wrapper, clave)

  const opcion = [...document.body.querySelectorAll('.p-select-option')].find(
    (o) => o.textContent.trim() === etiqueta
  )

  expect(opcion).toBeDefined()

  // `mousedown`, que es lo que escucha la opción del `Select` (`Select.vue:145`).
  opcion.dispatchEvent(new MouseEvent('mousedown', { bubbles: true }))
  await flushPromises()
  await nextTick()
}

/**
 * Las CINCO filas de sección de contenido, sin el sexto selector del M6.
 *
 * Va aparte porque el sexto comparte la clase `.seccion` —es la misma fila y el
 * mismo `Select`— pero **no es una sección**: contesta «¿se me puede
 * encontrar?» y no «¿qué se ve de mí?», tiene dos opciones y no tres, y el
 * backend lo guarda en otra columna con otro método. Todo test que hable de
 * «las cinco» tiene que usar esto, o pasaría a hablar de seis cosas distintas.
 */
function secciones(wrapper) {
  return wrapper.findAll('.seccion').filter((li) => !li.classes().includes('seccion--busqueda'))
}

beforeEach(() => {
  apiCall.mockReset()
})

describe('el primer render', () => {
  it('monta sin lanzar, pide la privacidad y pinta los cinco selectores más el sexto', async () => {
    const { wrapper, errores } = await montarPanel()

    expect(errores).toEqual([])
    // Sin un solo campo en el payload: la única entrada es el `user_id` que
    // pone `AuthMiddleware`, y es lectura, así que su ruta no lleva Csrf.
    expect(apiCall).toHaveBeenCalledWith('privacy_get')

    // Seis filas: las CINCO secciones de contenido y el sexto selector del M6,
    // que no es una de ellas —ver la cabecera— y por eso se cuenta aparte.
    expect(wrapper.findAll('.seccion')).toHaveLength(6)
    expect(wrapper.findAll('.seccion--busqueda')).toHaveLength(1)
    expect(secciones(wrapper)).toHaveLength(5)
  })

  it('las cinco claves son las del backend, y las mismas que lee el perfil público', async () => {
    const { wrapper } = await montarPanel()

    const claves = secciones(wrapper).map(
      (li) => [...li.classes()].find((c) => c.startsWith('seccion--')).replace('seccion--', '')
    )

    // Las del contrato, nunca los nombres de columna (`show_value`): el prefijo
    // `show_` es del esquema, y dejarlo salir ataría el cliente a la tabla.
    expect(claves).toEqual(['collection', 'value', 'decks', 'sets', 'wishlist'])
    expect(claves).toEqual(Object.keys(DEFECTOS))

    // Y el vocabulario compartido: las cuatro secciones que pinta el perfil
    // público salen de estas mismas claves. `value` no es sección con ruta
    // propia sino un modificador, y por eso está aquí y no allí.
    expect(claves.filter((c) => c !== 'value'))
      .toEqual(SECCIONES_DEL_PERFIL.map((s) => s.clave))
  })

  it('enseña los cinco defectos que manda el backend, no un «todo público» inventado', async () => {
    const { wrapper } = await montarPanel()

    // Quien no ha tocado nada no tiene fila, y el backend responde con los
    // defectos: `value` y `wishlist` nacen en `friends` a propósito.
    expect(DEFECTOS).toEqual({
      collection: 'everyone',
      value: 'friends',
      decks: 'everyone',
      sets: 'everyone',
      wishlist: 'friends'
    })

    expect(elegido(wrapper, 'collection')).toBe(TODOS)
    expect(elegido(wrapper, 'value')).toBe(AMIGOS)
    expect(elegido(wrapper, 'decks')).toBe(TODOS)
    expect(elegido(wrapper, 'sets')).toBe(TODOS)
    expect(elegido(wrapper, 'wishlist')).toBe(AMIGOS)
  })

  it('enseña la URL pública del usuario, compuesta con el origen del navegador', async () => {
    const { wrapper } = await montarPanel()

    const url = `${window.location.origin}/#/user/${SESION.auth.user.username}`

    expect(wrapper.find('.privacidad__url').text()).toBe(url)
    expect(wrapper.find('.privacidad__abrir').attributes('href')).toBe(url)
  })

  it('no promete que el propio perfil se vea como lo ve un desconocido', async () => {
    const { wrapper } = await montarPanel()

    // Con sesión abierta el backend manda el `Bearer` y la regla 1 de
    // `Visibilidad` te enseña tu perfil entero: decir «así te ven» sería falso,
    // y es exactamente la comprobación que hay que hacer en incógnito.
    expect(wrapper.find('.privacidad__intro').text()).toContain('incógnito')
  })

  it('un fallo al cargar se dice y no deja cinco selectores mintiendo', async () => {
    const { wrapper, errores } = await montarPanel({
      privacy_get: { status: 'error', message: 'No autenticado.', http_code: 401 }
    })

    expect(errores).toEqual([])
    expect(wrapper.find('.privacidad__error').text()).toContain('No autenticado.')
    // Ni un selector: pintar «Cualquiera» sobre algo que el backend guarda como
    // `nobody` sería inventarse la privacidad de alguien.
    expect(wrapper.findAll('.seccion')).toHaveLength(0)
  })
})

describe('los tres niveles', () => {
  it('ofrece los tres, del más cerrado al más abierto, y fuera del wrapper', async () => {
    const { wrapper } = await montarPanel()

    // Cerrado no ofrece nada: lo de abajo no es un resto de otro desplegable.
    expect(document.body.querySelectorAll('.p-select-option')).toHaveLength(0)

    expect(await nivelesOfrecidos(wrapper, 'collection')).toEqual([NADIE, AMIGOS, TODOS])

    // La trampa: el overlay cuelga del body, no del wrapper. La colección está
    // en «Cualquiera», así que si «solo amigos» apareciera dentro de su fila
    // sería porque el overlay NO se teletransportó — y entonces buscar las
    // opciones en el body habría pasado en verde por casualidad.
    expect(fila(wrapper, 'collection').html()).not.toContain(AMIGOS)
  })

  /**
   * **La etiqueta ya NO lleva coletilla, y este test es lo que impide que
   * vuelva.** Hasta el 2026-09-14 decía «Solo amigos (nadie, todavía)» porque
   * la tabla `friendships` no existía; el M2 del Plan - Amigos y Seguimiento la
   * enchufó y la advertencia pasó a ser falsa. Una etiqueta que avisa de una
   * limitación levantada no es prudente, es un error: desaconseja el nivel
   * intermedio justo cuando es el que más sentido tiene.
   */
  it('«solo amigos» se ofrece limpio, sin la advertencia que sobró al llegar la amistad', async () => {
    const { wrapper } = await montarPanel()

    const ofrecidos = await nivelesOfrecidos(wrapper, 'value')

    expect(ofrecidos).toContain(AMIGOS)
    expect(ofrecidos.join(' ')).not.toContain('todavía')
  })
})

/**
 * El aviso de nivel inerte **se retiró el 2026-09-14** junto con la coletilla de
 * la etiqueta, y por el mismo motivo. Este test se queda en su sitio para que no
 * vuelva por inercia: mientras `Visibilidad` resuelva `friends` preguntando por
 * una amistad `accepted`, no hay nada de lo que avisar.
 */
describe('el aviso de que `friends` estaba inerte, ya retirado', () => {
  it('no se pinta aunque haya secciones en «solo amigos» — los defectos traen dos', async () => {
    const { wrapper } = await montarPanel()

    expect(wrapper.find('.privacidad__inerte').exists()).toBe(false)
    expect(wrapper.text()).not.toContain('no deja ver nada a nadie')
  })
})

describe('el aviso del dinero — las cinco secciones no son equivalentes', () => {
  it('con el valor en «cualquiera» se dice en voz alta lo que se publica', async () => {
    const respuesta = fixtura(privacyGetFixture)

    respuesta.data.privacy.value = 'everyone'

    const { wrapper } = await montarPanel({ privacy_get: respuesta })

    const aviso = wrapper.find('.privacidad__patrimonio').text()

    expect(aviso).toContain('cuánto dinero tienes en cartas')
    expect(aviso).toContain('No es lo mismo que enseñar qué cartas tienes')
  })

  it('con el valor en su defecto (`friends`) no hay nada que avisar', async () => {
    const { wrapper } = await montarPanel()

    expect(DEFECTOS.value).toBe('friends')
    expect(wrapper.find('.privacidad__patrimonio').exists()).toBe(false)
  })
})

/**
 * **La edición parcial**, que es la condición del hito: `privacy_set` manda solo
 * la sección que cambia, igual que `deck_update`.
 */
describe('guardar un cambio', () => {
  it('manda SOLO la sección tocada, con su clave de contrato', async () => {
    const { wrapper } = await montarPanel()

    await elegir(wrapper, 'value', NADIE)

    // Una sola clave en el payload. Las cinco serían cuatro decisiones que el
    // usuario no ha tomado — y pisarían lo que otra pestaña hubiera cambiado.
    expect(apiCall).toHaveBeenCalledWith('privacy_set', { value: 'nobody' })

    const [, payload] = apiCall.mock.calls.find(([accion]) => accion === 'privacy_set')

    expect(Object.keys(payload)).toEqual(['value'])
  })

  it('repinta las cinco con lo que devuelve el backend: las otras cuatro no se movieron', async () => {
    const { wrapper } = await montarPanel()

    await elegir(wrapper, 'value', NADIE)

    // La respuesta capturada trae las cinco tal como quedaron en la BD real.
    expect(privacySetFixture.data.privacy).toEqual({
      collection: 'everyone',
      value: 'nobody',
      decks: 'everyone',
      sets: 'everyone',
      wishlist: 'friends'
    })

    expect(elegido(wrapper, 'value')).toBe(NADIE)
    expect(elegido(wrapper, 'collection')).toBe(TODOS)
    expect(elegido(wrapper, 'decks')).toBe(TODOS)
    expect(elegido(wrapper, 'sets')).toBe(TODOS)
    expect(elegido(wrapper, 'wishlist')).toBe(AMIGOS)
  })

  it('confirma diciendo cuándo vale el cambio, que no es «ya»', async () => {
    const { wrapper } = await montarPanel()

    await elegir(wrapper, 'value', NADIE)

    // El perfil público se compone en su propia carga: quien lo tuviera abierto
    // sigue viendo lo de antes hasta que recargue.
    expect(wrapper.find('.privacidad__aviso').text()).toContain('siguiente carga')
  })

  it('un fallo al guardar NO mueve el selector: la sección sigue como estaba', async () => {
    const { wrapper, errores } = await montarPanel({
      privacy_set: { status: 'error', message: 'Nivel de privacidad no soportado.', http_code: 422 }
    })

    await elegir(wrapper, 'collection', NADIE)

    expect(errores).toEqual([])
    expect(wrapper.find('.privacidad__error').text()).toContain('Nivel de privacidad no soportado.')
    // Nada de optimismo: la colección sigue siendo pública y el panel lo dice.
    expect(elegido(wrapper, 'collection')).toBe(TODOS)
  })

  it('elegir el nivel que ya estaba no manda nada', async () => {
    const { wrapper } = await montarPanel()

    await elegir(wrapper, 'collection', TODOS)

    expect(apiCall).not.toHaveBeenCalledWith('privacy_set', expect.anything())
  })

  it('guardar «solo amigos» se acepta y ya no arrastra ningún aviso', async () => {
    const respuesta = fixtura(privacySetFixture)

    respuesta.data.privacy = { ...DEFECTOS, decks: 'friends' }

    const { wrapper } = await montarPanel({ privacy_set: respuesta })

    await elegir(wrapper, 'decks', AMIGOS)

    // Antes del 2026-09-14, elegir este nivel hacía aparecer el aviso de que no
    // le abría la puerta a nadie. Desde que el M2 enchufó la amistad, elegirlo
    // es una decisión efectiva y no hay nada que advertir.
    expect(apiCall).toHaveBeenCalledWith('privacy_set', { decks: 'friends' })
    expect(elegido(wrapper, 'decks')).toBe(AMIGOS)
    expect(wrapper.find('.privacidad__inerte').exists()).toBe(false)
  })
})


/**
 * **El sexto selector (M6): «Aparecer en el buscador».**
 *
 * No es una sexta sección y este bloque existe para que no se convierta en una:
 * contesta «¿se me puede encontrar?» y no «¿qué se ve de mí?», **tiene dos
 * opciones y no tres**, viaja por su propia clave (`search`) y el backend lo
 * guarda en otra columna con otro método. Si algún día ofreciera «Solo amigos»,
 * `privacy_set` contestaría 422 —ese valor no existe en su ENUM— y el panel
 * estaría ofreciendo algo que el servidor rechaza.
 *
 * La trampa de PrimeVue vale aquí igual que en los cinco de arriba: el overlay
 * se teletransporta al `document.body`, así que **qué opciones se ofrecen** hay
 * que leerlo de ahí y no del `wrapper`.
 */
describe('el sexto selector: aparecer en el buscador', () => {
  /**
   * La respuesta de `privacy_set` cuando lo ÚNICO que se ha movido es el sexto
   * selector: las cinco secciones tal como estaban y `search` con el valor
   * nuevo.
   *
   * Se parte de `privacy_get.json` para las cinco y no de `privacy_set.json`,
   * que es la captura de cambiar **`value`** y por tanto lo trae en `nobody`.
   * Reutilizarla aquí haría que el backend simulado moviera una sección que
   * nadie tocó — justo lo que estos tests afirman que no pasa.
   */
  function conBusqueda(valor) {
    const respuesta = fixtura(privacySetFixture)
    respuesta.data.privacy = fixtura(privacyGetFixture).data.privacy
    respuesta.data.search = valor
    return respuesta
  }

  it('se pinta, y con el valor que manda el backend', async () => {
    const { wrapper } = await montarPanel()

    // La fixtura es de un usuario SIN fila en `user_privacy_settings`, que es el
    // caso de todo el mundo: el defecto de esta columna es `everyone`, la única
    // de las seis que nace abierta sin ser una sección de contenido.
    expect(privacyGetFixture.data.search).toBe('everyone')
    expect(fila(wrapper, 'busqueda').exists()).toBe(true)
    expect(elegido(wrapper, 'busqueda')).toBe('Cualquiera con cuenta')
  })

  /** DOS opciones. `friends` es un nivel válido en las otras cinco y aquí no existe. */
  it('ofrece DOS opciones y ninguna es «Solo amigos»', async () => {
    const { wrapper } = await montarPanel()

    const ofrecidas = await nivelesOfrecidos(wrapper, 'busqueda')

    expect(ofrecidas).toEqual(['Nadie', 'Cualquiera con cuenta'])
    expect(ofrecidas).not.toContain(AMIGOS)
    expect(ofrecidas).not.toContain('Solo amigos')
  })

  /**
   * «Cualquiera con cuenta» y no «Cualquiera» a secas, que es lo que dicen los
   * cinco de arriba: el buscador lleva `AuthMiddleware` y aquellos se ven **sin
   * sesión**. No es el mismo «cualquiera».
   */
  it('su etiqueta abierta no es la misma que la de las cinco secciones', async () => {
    const { wrapper } = await montarPanel()

    const deLaBusqueda = await nivelesOfrecidos(wrapper, 'busqueda')

    expect(deLaBusqueda).toContain('Cualquiera con cuenta')
    expect(deLaBusqueda).not.toContain(TODOS)
  })

  /** La edición es parcial, igual que con las secciones: solo `search` viaja. */
  it('manda SOLO `search` y ninguna sección', async () => {
    const { wrapper } = await montarPanel({ privacy_set: conBusqueda('nobody') })

    await elegir(wrapper, 'busqueda', NADIE)

    expect(apiCall).toHaveBeenCalledWith('privacy_set', { search: 'nobody' })
    expect(elegido(wrapper, 'busqueda')).toBe('Nadie')
  })

  /** Y al revés: tocar una sección no manda `search`. */
  it('tocar una sección no manda `search`', async () => {
    const { wrapper } = await montarPanel()

    await elegir(wrapper, 'value', NADIE)

    expect(apiCall).toHaveBeenCalledWith('privacy_set', { value: 'nobody' })
    expect(apiCall).not.toHaveBeenCalledWith('privacy_set', expect.objectContaining({ search: expect.anything() }))
  })

  /** Mover el sexto no mueve ninguna de las cinco: lo demuestra la respuesta. */
  it('cambiar el buscador deja las cinco secciones donde estaban', async () => {
    const { wrapper } = await montarPanel({ privacy_set: conBusqueda('nobody') })

    const antes = secciones(wrapper).map((li) => li.find('.p-select-label').text())

    await elegir(wrapper, 'busqueda', NADIE)

    expect(secciones(wrapper).map((li) => li.find('.p-select-label').text())).toEqual(antes)
  })

  /**
   * **Nada es optimista, tampoco aquí.** Si el guardado falla, el desplegable
   * vuelve a lo que dice el backend: decir «ya no sales» a quien sigue saliendo
   * es la misma mentira que decir «Nadie» sobre una sección pública.
   */
  it('si el guardado falla, el selector vuelve a lo que hay guardado', async () => {
    const { wrapper } = await montarPanel({
      privacy_set: { status: 'error', message: 'No se pudo guardar.', http_code: 500 }
    })

    await elegir(wrapper, 'busqueda', NADIE)

    expect(elegido(wrapper, 'busqueda')).toBe('Cualquiera con cuenta')
    expect(wrapper.find('.privacidad__error').text()).toContain('No se pudo guardar.')
  })

  /**
   * El aviso de «no sales» solo aparece cuando está apagado, y dice la mitad que
   * no es evidente: el perfil sigue siendo público y quien sepa tu nombre puede
   * abrirlo. Apagar el buscador no es cerrar el perfil.
   */
  it('apagado avisa de que el perfil sigue siendo público', async () => {
    const { wrapper } = await montarPanel({ privacy_set: conBusqueda('nobody') })

    expect(wrapper.find('.privacidad__oculto').exists()).toBe(false)

    await elegir(wrapper, 'busqueda', NADIE)

    expect(wrapper.find('.privacidad__oculto').text()).toContain('No sales en el buscador')
    expect(wrapper.find('.privacidad__oculto').text()).toContain('pedirte amistad')
  })

  /**
   * Sin poder leer la privacidad no se pinta ningún selector, y el sexto
   * tampoco: pintar «Cualquiera con cuenta» por defecto sería decirle a quien lo
   * tiene apagado que sale en el buscador.
   */
  it('si `privacy_get` falla, el sexto selector tampoco se inventa un valor', async () => {
    const { wrapper } = await montarPanel({
      privacy_get: { status: 'error', message: 'No se pudo cargar tu privacidad.', http_code: 500 }
    })

    expect(fila(wrapper, 'busqueda').exists()).toBe(false)
    expect(wrapper.findAll('.seccion')).toHaveLength(0)
  })
})
