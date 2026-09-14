import { beforeEach, describe, expect, it, vi } from 'vitest'
import { nextTick } from 'vue'
import { flushPromises } from '@vue/test-utils'

import PublicProfileView from '@/views/PublicProfileView.vue'
import { apiCall, publicGet } from '@/services/api'

import { montarVista, SESION } from '../../helpers'

import perfilFixture from '../../fixtures/public_profile.json'
import perfilTodoFixture from '../../fixtures/public_profile_todo.json'
import coleccionFixture from '../../fixtures/public_collection.json'
import coleccionValorFixture from '../../fixtures/public_collection_valor.json'
import mazosFixture from '../../fixtures/public_decks.json'
import setsFixture from '../../fixtures/public_sets.json'
import noVisibleFixture from '../../fixtures/public_not_visible.json'
import sinUsuarioFixture from '../../fixtures/public_user_not_found.json'
import friendListFixture from '../../fixtures/friend_list.json'
import followListFixture from '../../fixtures/follow_list.json'
import pedirFixture from '../../fixtures/friend_request.json'
import seguirFixture from '../../fixtures/follow_add.json'

/**
 * `views/PublicProfileView.vue` — el perfil de otra persona, `/user/:username`.
 *
 * **Se monta SIN sembrar sesión**, y eso es la mitad del test: es la primera
 * vista de la app que un desconocido puede abrir. Si `meta: { public: true }`
 * faltara o el guard no la respetara, el router la mandaría a `/login` y lo que
 * comprueban estos tests es precisamente que no lo hace — con el router REAL,
 * porque un `RouterLinkStub` ni resuelve la ruta ni ejecuta el guard, y el test
 * pasaría en verde sin haber probado nada.
 *
 * Y la regla que da nombre al hito: **una sección que no se ve se pinta
 * igualmente y dice que es privada**. Esconderla sin más se lee como un fallo de
 * carga, así que aquí no basta con comprobar que no hay cartas: hay que
 * comprobar que la sección SIGUE EN LA PÁGINA y que dice por qué está vacía.
 *
 * Las fixturas son capturas reales de las cinco rutas públicas de perfil.
 */

/**
 * El módulo original se conserva y solo se doblan las funciones de red: la
 * vista pinta `CardImage`, que compone la URL con `API_BASE` de este mismo
 * fichero (`services/scryfall.js:22`). Con una factoría que lo sustituya entero,
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

/**
 * Enruta cada respuesta por el sufijo de la ruta pedida.
 *
 * Por sufijo y no por orden de llamada porque la vista pide las secciones
 * visibles **a la vez**: con `mockResolvedValueOnce` encadenados, el orden de
 * resolución decidiría qué fixtura cae en qué sección.
 */
function backend(mapa) {
  publicGet.mockImplementation((path) => {
    for (const [sufijo, respuesta] of Object.entries(mapa)) {
      if (path.endsWith(sufijo)) {
        return Promise.resolve(fixtura(respuesta))
      }
    }

    return Promise.resolve(fixtura(noVisibleFixture))
  })
}

const BACKEND_POR_DEFECTO = {
  '/collection': coleccionFixture,
  '/decks': mazosFixture,
  '/sets': setsFixture,
  '/wishlist': noVisibleFixture,
  '/user/fixturas': perfilFixture
}

/**
 * Monta el perfil **sin sesión**: `estado` se deja a propósito sin `SESION`,
 * que es lo que hacen todos los demás specs de vista de esta suite.
 */
async function montarPerfil(mapa = BACKEND_POR_DEFECTO, username = 'fixturas') {
  backend(mapa)

  const montaje = await montarVista(PublicProfileView, { ruta: `/user/${username}` })

  await flushPromises()
  await nextTick()

  return montaje
}

/** La sección por su clave del backend, que es el `data-seccion` del template. */
function seccion(wrapper, clave) {
  return wrapper.find(`[data-seccion="${clave}"]`)
}

beforeEach(() => {
  publicGet.mockReset()
  // `apiCall` es la OTRA vía de red de esta pantalla desde el M5: las acciones
  // de relación no van por `publicGet`. Ver el bloque del final.
  apiCall.mockReset()
})

describe('la ruta pública', () => {
  it('un anónimo llega al perfil y NO acaba en el login', async () => {
    const { router, errores } = await montarPerfil()

    expect(router.currentRoute.value.name).toBe('publicProfile')
    expect(router.currentRoute.value.meta.public).toBe(true)
    expect(errores).toHaveLength(0)
  })

  it('monta sin lanzar y pinta el nombre y el @usuario', async () => {
    const { wrapper, errores } = await montarPerfil()

    const usuario = fixtura(perfilFixture).user

    expect(wrapper.text()).toContain(usuario.displayName)
    expect(wrapper.text()).toContain(`@${usuario.username}`)
    expect(wrapper.find('.tarjeta__avatar').attributes('src')).toBe(usuario.avatarUrl)
    expect(errores).toHaveLength(0)
  })

  it('no enseña ningún correo, porque el backend no lo manda', async () => {
    const { wrapper } = await montarPerfil()

    expect(wrapper.text()).not.toMatch(/@[\w.-]+\.(com|es|invalid)/)
  })
})

describe('el estado vacío honesto', () => {
  it('la sección que no se ve SIGUE en la página y dice que es privada', async () => {
    const { wrapper } = await montarPerfil()

    const deseos = seccion(wrapper, 'wishlist')

    // Lo que NO puede pasar: que la sección desaparezca. Una sección que se
    // esconde parece un fallo de carga, y el visitante recarga una ruta con
    // límite de 60/min buscando algo que nunca iba a estar.
    expect(deseos.exists()).toBe(true)
    expect(deseos.text()).toContain('Lista de deseos')
    expect(deseos.find('.seccion__privada').text()).toContain('Esta sección es privada')
  })

  it('las cuatro secciones están SIEMPRE, se vean o no', async () => {
    const { wrapper } = await montarPerfil()

    for (const clave of ['collection', 'decks', 'sets', 'wishlist']) {
      expect(seccion(wrapper, clave).exists()).toBe(true)
    }
  })

  it('«es privada» y «no hay nada» son mensajes DISTINTOS', async () => {
    const { wrapper } = await montarPerfil({
      ...BACKEND_POR_DEFECTO,
      '/decks': { decks: [] }
    })

    const mazos = seccion(wrapper, 'decks')

    expect(mazos.find('.seccion__privada').exists()).toBe(false)
    expect(mazos.text()).toContain('Aquí no hay nada todavía')
  })

  it('un 403 de la propia sección se pinta igual que un `visible: false`', async () => {
    // Pasa si el dueño cambia su privacidad entre la carga del perfil y la de la
    // sección: el backend no se fía del `visible` que mandó antes, y la vista
    // tampoco tiene que distinguirlo.
    const { wrapper } = await montarPerfil({
      ...BACKEND_POR_DEFECTO,
      '/sets': noVisibleFixture
    })

    expect(seccion(wrapper, 'sets').find('.seccion__privada').exists()).toBe(true)
  })

  it('un fallo de red SÍ se distingue de una sección privada', async () => {
    const { wrapper } = await montarPerfil({
      ...BACKEND_POR_DEFECTO,
      '/decks': { error: 'rate_limited' }
    })

    const mazos = seccion(wrapper, 'decks')

    expect(mazos.find('.seccion__privada').exists()).toBe(false)
    expect(mazos.text()).toMatch(/espera un minuto/i)
  })
})

describe('lo que sí se ve', () => {
  it('pinta una tarjeta por carta de la colección', async () => {
    const { wrapper } = await montarPerfil()

    expect(seccion(wrapper, 'collection').findAll('.carta')).toHaveLength(
      fixtura(coleccionFixture).items.length
    )
  })

  it('pinta los mazos con su estado, y sin enlace: `/deck/:id` es privado', async () => {
    const { wrapper } = await montarPerfil()

    const mazos = seccion(wrapper, 'decks')
    const primero = fixtura(mazosFixture).decks[0]

    expect(mazos.findAll('.mazo')).toHaveLength(fixtura(mazosFixture).decks.length)
    expect(mazos.text()).toContain(primero.name)
    // Un `router-link` a una ruta tras el guard mandaría al login a quien acaba
    // de abrir un enlace público: un perfil que pide sesión al primer clic no
    // es público.
    expect(mazos.findAll('a')).toHaveLength(0)
  })

  it('pinta el progreso por edición con el porcentaje que da el backend', async () => {
    const { wrapper } = await montarPerfil()

    const sets = seccion(wrapper, 'sets')
    const primera = fixtura(setsFixture).sets[0]

    expect(sets.findAll('.set')).toHaveLength(fixtura(setsFixture).sets.length)
    expect(sets.text()).toContain(primera.setName)
    expect(sets.text()).toContain(`${String(primera.percent).replace('.', ',')} %`)
  })

  it('sin `total_set_size` no dibuja barra: se diría un 0 % que no es', async () => {
    const sets = fixtura(setsFixture)
    sets.sets = [{ ...sets.sets[0], totalSetSize: null, percent: null }]

    const { wrapper } = await montarPerfil({ ...BACKEND_POR_DEFECTO, '/sets': sets })

    const set = seccion(wrapper, 'sets').find('.set')

    expect(set.text()).toContain('sin tamaño')
    expect(set.find('.set__pista').exists()).toBe(false)
  })
})

describe('el dinero', () => {
  it('con `show_value` invisible no aparece ni el resumen ni un precio', async () => {
    const { wrapper } = await montarPerfil()

    expect(wrapper.find('.valor').exists()).toBe(false)
    expect(wrapper.findAll('.carta__precio')).toHaveLength(0)
  })

  it('con `show_value` a everyone aparecen el resumen y el precio por carta', async () => {
    const { wrapper } = await montarPerfil({
      '/collection': coleccionValorFixture,
      '/decks': mazosFixture,
      '/sets': setsFixture,
      '/wishlist': coleccionValorFixture,
      '/user/fixturas': perfilTodoFixture
    })

    expect(wrapper.find('.valor').exists()).toBe(true)
    expect(wrapper.find('.valor').text()).toContain('281,72')
    expect(wrapper.findAll('.carta__precio').length).toBeGreaterThan(0)
    // Y la lista de deseos deja de ser privada cuando su nivel lo permite.
    expect(seccion(wrapper, 'wishlist').find('.seccion__privada').exists()).toBe(false)
  })
})

describe('la página siguiente', () => {
  it('el botón pide el cursor y AÑADE, no reemplaza', async () => {
    const { wrapper } = await montarPerfil()

    const antes = seccion(wrapper, 'collection').findAll('.carta').length

    publicGet.mockResolvedValue({
      items: fixtura(coleccionFixture).items.slice(0, 5),
      nextCursor: null
    })

    await seccion(wrapper, 'collection').find('.seccion__mas button').trigger('click')
    await flushPromises()
    await nextTick()

    expect(publicGet).toHaveBeenLastCalledWith('/user/fixturas/collection', {
      cursor: fixtura(coleccionFixture).nextCursor
    })
    expect(seccion(wrapper, 'collection').findAll('.carta')).toHaveLength(antes + 5)
  })

  it('sin cursor no hay botón que pulsar', async () => {
    const { wrapper } = await montarPerfil({
      ...BACKEND_POR_DEFECTO,
      '/collection': { ...fixtura(coleccionFixture), nextCursor: null }
    })

    expect(seccion(wrapper, 'collection').find('.seccion__mas').exists()).toBe(false)
  })

  it('NO hay centinela de scroll infinito, a diferencia de las vistas privadas', async () => {
    // Deliberado: estas rutas van limitadas a 60/min por IP, y un observador que
    // dispara solo al bajar se come el límite del visitante sin que él lo haya
    // pedido. Aquí lo pulsa quien quiera seguir viendo.
    await montarPerfil()

    expect(window.observadoresDeInterseccion).toHaveLength(0)
  })
})

describe('el usuario que no existe', () => {
  it('lo dice con su nombre, y no lo disfraza de perfil vacío', async () => {
    const { wrapper, errores } = await montarPerfil(
      { '/user/nadie': sinUsuarioFixture },
      'nadie'
    )

    expect(wrapper.text()).toContain('No existe ningún usuario llamado «nadie»')
    // «No hay nadie así» y «esta persona no enseña nada» son cosas distintas y
    // la vista no puede confundirlas: sin perfil no se pinta ninguna sección.
    expect(wrapper.find('[data-seccion]').exists()).toBe(false)
    expect(errores).toHaveLength(0)
  })
})

/**
 * ---------------------------------------------------------------------------
 * M5 — LOS BOTONES DE RELACIÓN.
 *
 * Esta pantalla es **mixta** desde aquí, y es la trampa del hito: el perfil
 * sigue llegando por `publicGet` (sin cookie, `Bearer` si lo hay) y las ocho
 * acciones de relación son `POST` con `AuthMiddleware`, o sea `apiCall`. Los
 * dos dobles conviven en este fichero por eso.
 *
 * Lo que se fija aquí:
 *
 *  - **Los cinco estados** del *Hecho cuando:* pintan el botón correcto, y
 *    **ninguna combinación deja dos botones contradictorios**: son dos controles
 *    independientes (amistad y seguimiento), cada uno con su cadena excluyente.
 *  - **Un anónimo no ve ningún botón y no dispara ni una petición.** Es el caso
 *    principal de la ruta —quien abre un enlace pegado en un Discord— y la
 *    página no puede romperse ni cambiar por él.
 *  - **En tu propio perfil no hay botones y sí el número de seguidores**, que es
 *    la decisión cerrada del plan: lo ve su dueño y nadie puede impedirlo.
 *
 * La relación se deduce cruzando `friend_list` y `follow_list` por `username`,
 * que es lo que hace `relacionCon()` en el store. **Ninguna acción del backend
 * contesta esa pregunta directamente**, y la ruta pública del perfil no la trae
 * a propósito: ver la cabecera de `stores/friends.js`.
 * ---------------------------------------------------------------------------
 */

/**
 * El perfil capturado, pero de otra persona.
 *
 * La fixtura es del usuario `fixturas` y las listas de amistad son de gente con
 * otros nombres: son dos capturas del backend hechas con usuarios distintos, y
 * cruzarlas exige mover el `username` de una de las dos. Se mueve el del perfil
 * —un solo campo— y se deja intacta la respuesta de los listados, que es la que
 * contiene el contrato que importa aquí (`friendshipId`, las tres listas y los
 * `counts`).
 */
function perfilDe(username) {
  const perfil = fixtura(perfilFixture)

  perfil.user = { ...perfil.user, username }

  return perfil
}

/**
 * Monta el perfil de `username` **con sesión iniciada** como `tester`.
 *
 * `acciones` sustituye o añade respuestas de `apiCall` por nombre de acción, que
 * es como funciona el endpoint único: el perfil pide `friend_list` y
 * `follow_list` a la vez, así que una cola de `mockResolvedValueOnce` dejaría el
 * reparto al orden de resolución.
 */
async function montarConSesion(username = 'unadesconocida', acciones = {}) {
  backend({ ...BACKEND_POR_DEFECTO, [`/user/${username}`]: perfilDe(username) })

  const respuestas = {
    friend_list: friendListFixture,
    follow_list: followListFixture,
    ...acciones
  }

  apiCall.mockImplementation((accion) =>
    Promise.resolve(
      respuestas[accion] ?? { status: 'error', message: `Sin doble para ${accion}.`, http_code: 500 }
    )
  )

  const montaje = await montarVista(PublicProfileView, {
    ruta: `/user/${username}`,
    estado: SESION
  })

  await flushPromises()
  await nextTick()
  await flushPromises()
  await nextTick()

  return montaje
}

/** Los botones de un control, por su etiqueta visible. */
function etiquetas(wrapper, control) {
  return wrapper
    .find(`[data-test="control-${control}"]`)
    .findAll('button')
    .map((b) => b.text().trim())
}

describe('sin sesión: el caso principal de esta ruta', () => {
  it('no pinta ningún control de relación', async () => {
    const { wrapper } = await montarPerfil()

    expect(wrapper.find('[data-test="relacion"]').exists()).toBe(false)
    expect(wrapper.find('[data-test="control-amistad"]').exists()).toBe(false)
    expect(wrapper.find('[data-test="control-seguir"]').exists()).toBe(false)
  })

  it('no pide NI UNA acción al backend: las ocho llevan `AuthMiddleware`', async () => {
    await montarPerfil()

    // Un anónimo comería 401 en `friend_list` y en `follow_list`. Que la vista
    // no reviente no basta: es que no tiene que preguntarlo siquiera.
    expect(apiCall).not.toHaveBeenCalled()
  })

  it('sigue pintando el perfil entero y sin errores de render', async () => {
    const { wrapper, errores } = await montarPerfil()

    expect(wrapper.find('.tarjeta__nombre').exists()).toBe(true)
    expect(wrapper.findAll('[data-seccion]')).toHaveLength(4)
    expect(errores).toHaveLength(0)
  })
})

describe('los cinco estados de la relación', () => {
  it('NADA: solo «Pedir amistad» y «Seguir»', async () => {
    const { wrapper, errores } = await montarConSesion('unadesconocida')

    expect(etiquetas(wrapper, 'amistad')).toEqual(['Pedir amistad'])
    expect(etiquetas(wrapper, 'seguir')).toEqual(['Seguir'])
    expect(errores).toHaveLength(0)
  })

  it('SOLICITUD ENVIADA: «Retirar solicitud», y NUNCA «Pedir amistad»', async () => {
    const { wrapper } = await montarConSesion('lepediamistad')

    expect(wrapper.find('[data-test="control-amistad"]').text()).toContain('Solicitud enviada')
    expect(etiquetas(wrapper, 'amistad')).toEqual(['Retirar solicitud'])
  })

  it('SOLICITUD RECIBIDA: «Aceptar» y «Rechazar», que no se contradicen', async () => {
    const { wrapper } = await montarConSesion('pidiomeamistad')

    // Son las dos respuestas a la misma pregunta, igual que en `/friends`. Lo
    // que no sale es «Pedir amistad» ni «Retirar solicitud»: aceptar una
    // solicitud propia es un 403 del backend.
    expect(etiquetas(wrapper, 'amistad')).toEqual(['Aceptar', 'Rechazar'])
    expect(wrapper.find('[data-test="control-amistad"]').text()).toContain('Te ha pedido amistad')
  })

  it('AMIGOS: «Quitar amistad», y nada de pedir', async () => {
    const { wrapper } = await montarConSesion('amigaaceptada')

    expect(etiquetas(wrapper, 'amistad')).toEqual(['Quitar amistad'])
    expect(wrapper.find('[data-test="control-amistad"]').text()).toContain('Sois amigos')
  })

  it('SIGUIENDO: «Dejar de seguir», y la amistad sigue en «Pedir amistad»', async () => {
    const { wrapper } = await montarConSesion('perfilseguido')

    // El quinto estado es del OTRO control. Seguir a alguien no dice nada sobre
    // si sois amigos: son dos relaciones ortogonales y aquí se ve.
    expect(etiquetas(wrapper, 'seguir')).toEqual(['Dejar de seguir'])
    expect(etiquetas(wrapper, 'amistad')).toEqual(['Pedir amistad'])
  })

  it('NINGUNA combinación deja dos botones contradictorios', async () => {
    const contradictorios = [
      ['Pedir amistad', 'Quitar amistad'],
      ['Pedir amistad', 'Retirar solicitud'],
      ['Pedir amistad', 'Aceptar'],
      ['Aceptar', 'Retirar solicitud'],
      ['Quitar amistad', 'Aceptar'],
      ['Seguir', 'Dejar de seguir']
    ]

    for (const quien of [
      'unadesconocida',
      'lepediamistad',
      'pidiomeamistad',
      'amigaaceptada',
      'perfilseguido'
    ]) {
      const { wrapper } = await montarConSesion(quien)
      const pintados = [...etiquetas(wrapper, 'amistad'), ...etiquetas(wrapper, 'seguir')]

      for (const [a, b] of contradictorios) {
        expect(
          pintados.includes(a) && pintados.includes(b),
          `«${a}» y «${b}» a la vez en el perfil de ${quien}: ${pintados.join(' · ')}`
        ).toBe(false)
      }

      // Y cada control pinta exactamente uno de sus estados, nunca cero.
      expect(etiquetas(wrapper, 'seguir')).toHaveLength(1)
      expect(pintados.length).toBeGreaterThan(0)
    }
  })

  it('la interfaz NO insinúa que seguir dé acceso a nada', async () => {
    const { wrapper } = await montarConSesion('unadesconocida')

    const nota = wrapper.find('[data-test="relacion"]').text()

    // Si esta pantalla diera a entender que seguir sirve para ver más, estaría
    // mintiendo sobre el modelo de permisos: `user_follow` no aparece en
    // `Visibilidad` en ninguna línea.
    expect(nota).toMatch(/no te deja ver nada/i)
    expect(nota).toMatch(/amistad se pide y se acepta/i)
  })
})

describe('tu propio perfil', () => {
  it('no hay botones de relación: pedírtelo a ti mismo es un 422', async () => {
    const { wrapper } = await montarConSesion('tester')

    expect(wrapper.find('[data-test="relacion"]').exists()).toBe(true)
    expect(wrapper.find('[data-test="control-amistad"]').exists()).toBe(false)
    expect(wrapper.find('[data-test="control-seguir"]').exists()).toBe(false)
  })

  it('SÍ enseña el número de seguidores, y nadie puede impedirlo', async () => {
    const { wrapper } = await montarConSesion('tester')

    // La decisión cerrada del plan: su dueño lo ve. La herramienta para no ser
    // seguido no es esconder el número, es bajar las secciones — y el pie de
    // este bloque lo dice, para que nadie busque un botón que no existe.
    expect(wrapper.find('[data-test="seguidores"]').text()).toContain('2 personas te siguen')
    expect(wrapper.find('[data-test="relacion"]').text()).toMatch(/«amigos» o «nadie»/)
  })

  it('el singular se escribe aparte', async () => {
    const unSeguidor = fixtura(followListFixture)
    unSeguidor.data.followerCount = 1

    const { wrapper } = await montarConSesion('tester', { follow_list: unSeguidor })

    expect(wrapper.find('[data-test="seguidores"]').text()).toContain('1 persona te sigue')
  })

  it('pide SOLO `follow_list`: no hay amistad que pintar con uno mismo', async () => {
    await montarConSesion('tester')

    expect(apiCall).toHaveBeenCalledWith('follow_list')
    expect(apiCall).toHaveBeenCalledTimes(1)
  })
})

describe('lo que cuesta abrir un perfil', () => {
  it('un perfil ajeno con sesión son DOS acciones, y solo esas', async () => {
    await montarConSesion('unadesconocida')

    expect(apiCall).toHaveBeenCalledWith('friend_list')
    expect(apiCall).toHaveBeenCalledWith('follow_list')
    expect(apiCall).toHaveBeenCalledTimes(2)
  })

  it('las listas se piden por `apiCall` y NUNCA por `publicGet`', async () => {
    await montarConSesion('unadesconocida')

    // `publicGet` va sin cookie: mandar por ahí una acción con `AuthMiddleware`
    // la dejaría contestada con un 401 y los botones nunca aparecerían.
    for (const [ruta] of publicGet.mock.calls) {
      expect(ruta.startsWith('/user/')).toBe(true)
    }
  })

  it('si no se puede saber la relación, NO se ofrece ningún botón', async () => {
    const { wrapper } = await montarConSesion('unadesconocida', {
      friend_list: { status: 'error', message: 'Demasiadas peticiones.', http_code: 429 }
    })

    // Caer en el estado «nada» ofrecería «Pedir amistad» a quien ya es amigo,
    // que es mentir sobre un permiso. Se dice que no se sabe.
    expect(wrapper.find('[data-test="error-relacion"]').exists()).toBe(true)
    expect(wrapper.find('[data-test="control-amistad"]').exists()).toBe(false)
    expect(wrapper.find('[data-test="control-seguir"]').exists()).toBe(false)
  })
})

describe('pulsar los botones', () => {
  it('«Pedir amistad» manda `friend_request` con el `username` y repinta', async () => {
    const { wrapper } = await montarConSesion('unadesconocida', {
      friend_request: pedirFixture
    })

    await wrapper.find('[data-test="control-amistad"] button').trigger('click')
    await flushPromises()
    await nextTick()

    expect(apiCall).toHaveBeenCalledWith('friend_request', { username: 'unadesconocida' })
  })

  it('«Seguir» manda `follow_add` y el botón pasa a «Dejar de seguir»', async () => {
    const respuesta = fixtura(seguirFixture)
    respuesta.data.user.username = 'unadesconocida'

    const { wrapper } = await montarConSesion('unadesconocida', { follow_add: respuesta })

    await wrapper.find('[data-test="control-seguir"] button').trigger('click')
    await flushPromises()
    await nextTick()

    expect(apiCall).toHaveBeenCalledWith('follow_add', { username: 'unadesconocida' })
    expect(etiquetas(wrapper, 'seguir')).toEqual(['Dejar de seguir'])
    // Y la amistad no se ha movido: seguir no concede nada.
    expect(etiquetas(wrapper, 'amistad')).toEqual(['Pedir amistad'])
  })

  it('«Quitar amistad» manda `friend_remove` con el `friendshipId` de la fila', async () => {
    const { wrapper } = await montarConSesion('amigaaceptada', {
      friend_remove: { status: 'success', message: 'Amistad deshecha.', data: null, http_code: 200 }
    })

    await wrapper.find('[data-test="control-amistad"] button').trigger('click')
    await flushPromises()
    await nextTick()

    // Por id y no por nombre: es lo que recibe la acción, y el id sale del
    // cruce que hizo `relacionCon()`.
    expect(apiCall).toHaveBeenCalledWith('friend_remove', { friendship_id: 6 })
    expect(etiquetas(wrapper, 'amistad')).toEqual(['Pedir amistad'])
  })

  it('«Retirar solicitud» manda `friend_remove` sobre una `pending` propia', async () => {
    const { wrapper } = await montarConSesion('lepediamistad', {
      friend_remove: { status: 'success', message: 'Amistad deshecha.', data: null, http_code: 200 }
    })

    await wrapper.find('[data-test="control-amistad"] button').trigger('click')
    await flushPromises()
    await nextTick()

    // Es la enmienda del 2026-09-14: `friend_reject` es solo del destinatario,
    // así que sin esto quien envía una solicitud no puede retirarla.
    expect(apiCall).toHaveBeenCalledWith('friend_remove', { friendship_id: 8 })
  })

  it('«Aceptar» manda `friend_accept` y el perfil pasa a «Sois amigos»', async () => {
    const { wrapper } = await montarConSesion('pidiomeamistad', {
      friend_accept: { status: 'success', message: 'Amistad aceptada.', data: null, http_code: 200 }
    })

    await wrapper.findAll('[data-test="control-amistad"] button')[0].trigger('click')
    await flushPromises()
    await nextTick()

    expect(apiCall).toHaveBeenCalledWith('friend_accept', { friendship_id: 7 })
    expect(etiquetas(wrapper, 'amistad')).toEqual(['Quitar amistad'])
  })

  it('lo que hizo el botón se dice en un aviso que no interrumpe', async () => {
    const respuesta = fixtura(seguirFixture)
    respuesta.data.user.username = 'unadesconocida'

    const { wrapper } = await montarConSesion('unadesconocida', { follow_add: respuesta })

    await wrapper.find('[data-test="control-seguir"] button').trigger('click')
    await flushPromises()
    await nextTick()

    const aviso = wrapper.find('.aviso')

    expect(aviso.exists()).toBe(true)
    expect(aviso.attributes('role')).toBe('status')
    // Y el texto vuelve a decir lo que seguir NO hace.
    expect(aviso.text()).toMatch(/no te deja ver nada nuevo/i)
  })

  it('un error del backend se enseña tal cual, sin reescribirlo', async () => {
    const { wrapper } = await montarConSesion('unadesconocida', {
      follow_add: {
        status: 'error',
        message: 'Ese perfil no enseña nada públicamente: seguirlo sería un marcador a una página vacía.',
        http_code: 422
      }
    })

    await wrapper.find('[data-test="control-seguir"] button').trigger('click')
    await flushPromises()
    await nextTick()

    // Este 422 NO se puede predecir desde el cliente: el backend mira los
    // niveles configurados y aquí solo se tiene el mapa `visible`, que dice qué
    // ve uno mismo. Por eso el botón se pinta y el mensaje se enseña entero.
    expect(wrapper.find('.aviso--error').text()).toContain('no enseña nada públicamente')
    expect(etiquetas(wrapper, 'seguir')).toEqual(['Seguir'])
  })
})
