import { beforeEach, describe, expect, it, vi } from 'vitest'
import { nextTick } from 'vue'
import { flushPromises } from '@vue/test-utils'

import FriendsView from '@/views/FriendsView.vue'
import { apiCall } from '@/services/api'

import { SESION, montarVista } from '../../helpers'

import friendListFixture from '../../fixtures/friend_list.json'
import friendListVacioFixture from '../../fixtures/friend_list_vacio.json'
import followListFixture from '../../fixtures/follow_list.json'
import followListVacioFixture from '../../fixtures/follow_list_vacio.json'
import aceptarFixture from '../../fixtures/friend_accept.json'
import rechazarFixture from '../../fixtures/friend_reject.json'
import deshacerFixture from '../../fixtures/friend_remove.json'
import dejarDeSeguirFixture from '../../fixtures/follow_remove.json'
import pedirFixture from '../../fixtures/friend_request.json'
import buscarFixture from '../../fixtures/user_search.json'
import buscarVacioFixture from '../../fixtures/user_search_vacio.json'

/**
 * `views/FriendsView.vue` — `/friends`.
 *
 * Lo que esta vista decide, y es lo único que se mira aquí:
 *
 *  - **Las solicitudes recibidas van primero.** El plan deja las notificaciones
 *    fuera de alcance con una condición: «las solicitudes pendientes se ven al
 *    entrar en `/friends`, con un contador». Si hubiera que bajar para verlas,
 *    entrar aquí no sería verlas.
 *  - **Cada lista pinta SUS botones y ninguno más.** Aceptar solo sobre una
 *    recibida, retirar solo sobre una enviada, quitar amistad solo sobre un
 *    amigo. El backend separa `pending` de `sent` con ese mismo criterio para
 *    que la interfaz no ofrezca algo que el servidor va a rechazar con un 403.
 *  - **Amigos y seguidos son dos bloques y nunca una lista mezclada.** Seguir no
 *    se pide, no se acepta y no da ningún acceso.
 *  - **Se navega por `username`**, que es la clave pública del proyecto. El
 *    montaje va con el **router real**: un `router-link` con un `params`
 *    obligatorio a `undefined` lanza al resolver y tumbaría el render entero,
 *    que es el fallo que se llevó `/decks` por delante y que `RouterLinkStub` no
 *    habría visto.
 */

/**
 * Se dobla `apiCall` y nada más: el resto del módulo se conserva porque otras
 * piezas del árbol componen URLs con `API_BASE`, y un doble sin esa constante
 * llena el render de errores que no son de esta vista.
 */
vi.mock('@/services/api', async (importarOriginal) => ({
  ...(await importarOriginal()),
  apiCall: vi.fn()
}))

/** Copia profunda: el store muta lo que recibe y las fixturas son de todos. */
function fixtura(json) {
  return structuredClone(json)
}

/** Responde según la ACCIÓN: la vista dispara las dos listas en el mismo `onMounted`. */
function respondeSegunAccion(mapa) {
  apiCall.mockImplementation((accion) =>
    Promise.resolve(
      mapa[accion] ?? { status: 'error', message: `Sin doble para ${accion}.`, http_code: 500 }
    )
  )
}

function backendConGente(extra = {}) {
  respondeSegunAccion({
    friend_list: fixtura(friendListFixture),
    follow_list: fixtura(followListFixture),
    friend_accept: fixtura(aceptarFixture),
    friend_reject: fixtura(rechazarFixture),
    friend_remove: fixtura(deshacerFixture),
    follow_remove: fixtura(dejarDeSeguirFixture),
    friend_request: fixtura(pedirFixture),
    user_search: fixtura(buscarFixture),
    ...extra
  })
}

async function montarAmigos(opciones = {}) {
  const montaje = await montarVista(FriendsView, {
    ruta: '/friends',
    estado: SESION,
    ...opciones
  })

  await flushPromises()
  await nextTick()

  return montaje
}

/** Los bloques de la pantalla, en el orden en el que se pintan. */
function titulos(wrapper) {
  return wrapper.findAll('.bloque__titulo').map((t) => t.text())
}

/** El bloque cuyo título empieza por `texto`, para no depender del índice. */
function bloque(wrapper, texto) {
  return wrapper
    .findAll('.bloque')
    .find((b) => b.find('.bloque__titulo').text().startsWith(texto))
}

beforeEach(() => {
  apiCall.mockReset()
  backendConGente()
})

describe('el primer render', () => {
  it('monta sin lanzar y pide las dos listas por el endpoint único', async () => {
    const { wrapper, errores } = await montarAmigos()

    expect(errores).toEqual([])
    expect(apiCall).toHaveBeenCalledWith('friend_list')
    expect(apiCall).toHaveBeenCalledWith('follow_list')
    expect(wrapper.find('.amigos__titulo').text()).toBe('Amigos')
  })

  it('la ruta es privada: está tras el guard y la sesión sembrada la deja pasar', async () => {
    const { router } = await montarAmigos()

    // `/friends` va SIN `meta.public`, al contrario que `/user/:username`: no es
    // el perfil de nadie, es tu lista. Si el guard la hubiera rebotado, este
    // nombre sería `login`.
    expect(router.currentRoute.value.name).toBe('friends')
    expect(router.currentRoute.value.meta.public).toBeUndefined()
  })

  it('LAS SOLICITUDES RECIBIDAS SE VEN AL ENTRAR, y van las primeras', async () => {
    const { wrapper } = await montarAmigos()

    // Es la condición literal del alcance del plan: no hay notificaciones, así
    // que las pendientes se ven al entrar aquí. Si este bloque fuera el tercero,
    // entrar no sería verlas.
    expect(titulos(wrapper)[0]).toContain('Solicitudes recibidas')
    expect(wrapper.text()).toContain('Quien Me Pidio Amistad')
  })

  it('el contador de recibidas sale del backend y es el de las RECIBIDAS', async () => {
    const { wrapper } = await montarAmigos()

    const cuentas = fixtura(friendListFixture).data.counts
    const recibidas = bloque(wrapper, 'Solicitudes recibidas')

    expect(recibidas.find('.bloque__cuenta').text()).toBe(String(cuentas.pending))
    // La fixtura trae una recibida y una enviada: si alguien las sumara, aquí
    // saldría un 2.
    expect(cuentas.pending).toBe(1)
    expect(cuentas.sent).toBe(1)
  })

  it('pinta las cuatro listas con la gente que trae cada una', async () => {
    const { wrapper } = await montarAmigos()

    const datos = fixtura(friendListFixture).data
    const seguidos = fixtura(followListFixture).data

    expect(bloque(wrapper, 'Solicitudes recibidas').findAll('.fila')).toHaveLength(datos.pending.length)
    expect(bloque(wrapper, 'Amigos').findAll('.fila')).toHaveLength(datos.friends.length)
    expect(bloque(wrapper, 'Solicitudes enviadas').findAll('.fila')).toHaveLength(datos.sent.length)
    expect(bloque(wrapper, 'Sigues a').findAll('.fila')).toHaveLength(seguidos.following.length)
  })

  it('enseña cuánta gente te sigue, que es un número y no una lista', async () => {
    const { wrapper } = await montarAmigos()

    // Quién te sigue no se publica: seguir es unilateral y el seguido no lo
    // autoriza. El backend manda `followerCount` y nunca nombres.
    expect(wrapper.find('.amigos__seguidores').text())
      .toContain(String(fixtura(followListFixture).data.followerCount))
    expect(wrapper.find('.amigos__seguidores').text()).toMatch(/te siguen/i)
  })

  it('dice por escrito que seguir NO da ningún acceso', async () => {
    const { wrapper } = await montarAmigos()

    // «Seguir» en otras apps significa cosas que aquí no significa, y esta es la
    // pantalla donde las dos relaciones conviven: si el texto no lo distinguiera,
    // el bloque de al lado invitaría a leerlo como media amistad.
    const seguidos = bloque(wrapper, 'Sigues a')

    expect(seguidos.text()).toMatch(/no te deja ver nada/i)
  })

  it('NO pinta ni un email de nadie', async () => {
    const { wrapper } = await montarAmigos()

    // El backend no lo manda en ninguno de los dos listados. Si algún día lo
    // hiciera, el fallo estaría allí; esta vista no lo pintaría igualmente.
    expect(wrapper.text()).not.toMatch(/@example\.invalid/)
    expect(wrapper.text()).not.toMatch(/\bemail\b/i)
  })
})

describe('cada lista con sus botones y ninguno más', () => {
  it('la recibida ofrece aceptar y rechazar, y NO retirar', async () => {
    const { wrapper } = await montarAmigos()

    const acciones = bloque(wrapper, 'Solicitudes recibidas')
      .findAll('.fila__acciones button')
      .map((b) => b.text())

    expect(acciones).toEqual(['Aceptar', 'Rechazar'])
  })

  it('la enviada ofrece SOLO retirar: aceptar la tuya sería un 403 seguro', async () => {
    const { wrapper } = await montarAmigos()

    const acciones = bloque(wrapper, 'Solicitudes enviadas')
      .findAll('.fila__acciones button')
      .map((b) => b.text())

    // El backend devuelve 403 al `requester` que intenta aceptar la suya, y las
    // dos listas están separadas para que la interfaz no pueda ofrecerlo.
    expect(acciones).toEqual(['Retirar'])
  })

  it('el amigo ofrece SOLO quitar la amistad', async () => {
    const { wrapper } = await montarAmigos()

    expect(
      bloque(wrapper, 'Amigos').findAll('.fila__acciones button').map((b) => b.text())
    ).toEqual(['Quitar amistad'])
  })

  it('el seguido ofrece SOLO dejar de seguir', async () => {
    const { wrapper } = await montarAmigos()

    // Por FILA y no por bloque: los seguidos son dos en la fixtura, así que
    // mirar el bloque entero contaría dos botones idénticos y no diría nada.
    for (const fila of bloque(wrapper, 'Sigues a').findAll('.fila')) {
      expect(fila.findAll('.fila__acciones button').map((b) => b.text()))
        .toEqual(['Dejar de seguir'])
    }
  })
})

describe('aceptar una solicitud, que es el gesto del hito', () => {
  it('manda `friend_accept`, mueve la fila a amigos y avisa', async () => {
    const { wrapper } = await montarAmigos()

    const solicitud = fixtura(friendListFixture).data.pending[0]

    await bloque(wrapper, 'Solicitudes recibidas')
      .findAll('.fila__acciones button')[0]
      .trigger('click')
    await flushPromises()
    await nextTick()

    expect(apiCall).toHaveBeenCalledWith('friend_accept', {
      friendship_id: solicitud.friendshipId
    })

    // El bloque de recibidas desaparece entero al quedarse vacío, y la persona
    // aparece entre los amigos.
    expect(bloque(wrapper, 'Solicitudes recibidas')).toBeUndefined()
    expect(bloque(wrapper, 'Amigos').text()).toContain(solicitud.user.displayName)
    expect(wrapper.find('.aviso').text()).toContain(solicitud.user.displayName)
  })

  it('rechazar quita la fila y dice que esa persona puede volver a pedirlo', async () => {
    const { wrapper } = await montarAmigos()

    await bloque(wrapper, 'Solicitudes recibidas')
      .findAll('.fila__acciones button')[1]
      .trigger('click')
    await flushPromises()
    await nextTick()

    expect(apiCall).toHaveBeenCalledWith('friend_reject', expect.anything())
    expect(bloque(wrapper, 'Solicitudes recibidas')).toBeUndefined()
    // No hay estado `rejected`: la fila se borra y se puede volver a pedir.
    expect(wrapper.find('.aviso').text()).toMatch(/volver a pedírtelo/i)
  })

  it('un 403 del backend se enseña tal cual y la fila se queda donde estaba', async () => {
    backendConGente({
      friend_accept: {
        status: 'error',
        message: 'Esa solicitud no es tuya: solo puede aceptarla quien la recibió.',
        http_code: 403
      }
    })

    const { wrapper } = await montarAmigos()

    await bloque(wrapper, 'Solicitudes recibidas')
      .findAll('.fila__acciones button')[0]
      .trigger('click')
    await flushPromises()
    await nextTick()

    expect(bloque(wrapper, 'Solicitudes recibidas').findAll('.fila')).toHaveLength(1)
    expect(wrapper.find('.aviso--error').text()).toContain('solo puede aceptarla quien la recibió')
  })
})

describe('retirar una solicitud enviada — la enmienda del 2026-09-14', () => {
  it('el botón de la lista de enviadas manda `friend_remove`', async () => {
    const { wrapper } = await montarAmigos()

    const enviada = fixtura(friendListFixture).data.sent[0]

    await bloque(wrapper, 'Solicitudes enviadas')
      .findAll('.fila__acciones button')[0]
      .trigger('click')
    await flushPromises()
    await nextTick()

    // `friend_reject` es solo del destinatario: sin `friend_remove` sobre una
    // `pending`, quien envía una solicitud no podría retirarla nunca.
    expect(apiCall).toHaveBeenCalledWith('friend_remove', {
      friendship_id: enviada.friendshipId
    })
    expect(bloque(wrapper, 'Solicitudes enviadas')).toBeUndefined()
    // Y las recibidas siguen donde estaban: es la otra lista.
    expect(bloque(wrapper, 'Solicitudes recibidas').findAll('.fila')).toHaveLength(1)
  })
})

describe('las dos relaciones no se mezclan', () => {
  it('dejar de seguir a alguien no toca la lista de amigos', async () => {
    const { wrapper } = await montarAmigos()

    const amigosAntes = bloque(wrapper, 'Amigos').text()

    await bloque(wrapper, 'Sigues a')
      .findAll('.fila__acciones button')[0]
      .trigger('click')
    await flushPromises()
    await nextTick()

    expect(apiCall).toHaveBeenCalledWith('follow_remove', {
      username: fixtura(followListFixture).data.following[0].username
    })
    expect(bloque(wrapper, 'Amigos').text()).toBe(amigosAntes)
    expect(bloque(wrapper, 'Sigues a').findAll('.fila')).toHaveLength(
      fixtura(followListFixture).data.following.length - 1
    )
  })

  it('quitar una amistad no toca la lista de seguidos', async () => {
    const { wrapper } = await montarAmigos()

    const seguidosAntes = bloque(wrapper, 'Sigues a').text()

    await bloque(wrapper, 'Amigos')
      .findAll('.fila__acciones button')[0]
      .trigger('click')
    await flushPromises()
    await nextTick()

    expect(apiCall).toHaveBeenCalledWith('friend_remove', expect.anything())
    expect(bloque(wrapper, 'Sigues a').text()).toBe(seguidosAntes)
    expect(wrapper.find('.aviso').text()).toMatch(/deja de ver/i)
  })
})

describe('la navegación al perfil de cada persona', () => {
  it('cada fila enlaza a `/user/<username>`, que es la clave pública', async () => {
    const { wrapper, errores } = await montarAmigos()

    const enlaces = wrapper.findAll('.persona__enlace')

    // Cinco personas en las cuatro listas de las fixturas: un amigo, una
    // solicitud recibida, una enviada y dos seguidos. Y el router es el REAL: un
    // `params.username` a `undefined` habría lanzado al resolver y tumbado el
    // render entero, como pasó con `/decks`.
    const cuantas =
      fixtura(friendListFixture).data.counts.friends +
      fixtura(friendListFixture).data.counts.pending +
      fixtura(friendListFixture).data.counts.sent +
      fixtura(followListFixture).data.following.length

    expect(errores).toEqual([])
    expect(enlaces.length).toBe(cuantas)
    expect(cuantas).toBe(5)
    expect(enlaces.map((a) => a.attributes('href'))).toContain('#/user/amigaaceptada')
  })

  it('el enlace lleva de verdad al perfil público', async () => {
    const { wrapper, router } = await montarAmigos()

    await bloque(wrapper, 'Amigos').find('.persona__enlace').trigger('click')

    // `vi.waitFor` y no `flushPromises`: el componente de destino es un
    // `import()` perezoso.
    await vi.waitFor(() => expect(router.currentRoute.value.name).toBe('publicProfile'))
    expect(router.currentRoute.value.params.username).toBe('amigaaceptada')
  })

  it('quien no tiene avatar ni nombre visible se pinta igual, sin huecos rotos', async () => {
    const { wrapper } = await montarAmigos()

    // `otroseguido` viene con `displayName` y `avatarUrl` a null en la fixtura
    // real: es el caso que rompe una vista que dé por hecho que hay foto.
    const seguidos = bloque(wrapper, 'Sigues a')

    expect(seguidos.text()).toContain('otroseguido')
    expect(seguidos.findAll('.persona__avatar--vacio').length).toBeGreaterThan(0)
  })
})

describe('los casos que no son el feliz', () => {
  it('sin nadie en ninguna lista, cada bloque dice dónde se empieza', async () => {
    respondeSegunAccion({
      friend_list: fixtura(friendListVacioFixture),
      follow_list: fixtura(followListVacioFixture)
    })

    const { wrapper, errores } = await montarAmigos()

    expect(errores).toEqual([])
    // Los dos bloques con lista vacía no desaparecen: dicen qué hacer. Y los de
    // solicitudes sí, porque un «no tienes solicitudes» permanente es ruido.
    expect(bloque(wrapper, 'Solicitudes recibidas')).toBeUndefined()
    expect(bloque(wrapper, 'Solicitudes enviadas')).toBeUndefined()
    expect(bloque(wrapper, 'Amigos').find('.bloque__vacio').text()).toMatch(/perfil de la otra persona/i)
    expect(bloque(wrapper, 'Sigues a').find('.bloque__vacio').text()).toMatch(/perfil público/i)
    expect(wrapper.find('.amigos__seguidores').exists()).toBe(false)
  })

  it('un fallo en los seguimientos deja las amistades en pie', async () => {
    respondeSegunAccion({
      friend_list: fixtura(friendListFixture),
      follow_list: { status: 'error', message: 'Demasiadas peticiones.', http_code: 429 }
    })

    const { wrapper, errores } = await montarAmigos()

    expect(errores).toEqual([])
    // Media pantalla cargó perfectamente y sigue ahí: el aviso es del bloque que
    // falló, no de la pantalla.
    expect(bloque(wrapper, 'Amigos').findAll('.fila')).toHaveLength(1)
    expect(bloque(wrapper, 'Sigues a').text()).toContain('Demasiadas peticiones.')
  })

  it('un 401 lo dice de forma que se pueda resolver, y no tumba la pantalla', async () => {
    respondeSegunAccion({
      friend_list: { status: 'error', message: 'No autorizado.', http_code: 401 },
      follow_list: { status: 'error', message: 'No autorizado.', http_code: 401 }
    })

    const { wrapper, errores } = await montarAmigos()

    expect(errores).toEqual([])
    expect(wrapper.find('.amigos__error').text()).toMatch(/sesión ha caducado/i)
  })
})

describe('volver', () => {
  it('la flecha de la barra lleva a la portada', async () => {
    const { wrapper, router } = await montarAmigos()

    await wrapper.find('.amigos__bar button').trigger('click')

    await vi.waitFor(() => expect(router.currentRoute.value.name).toBe('home'))
  })
})


/**
 * **El buscador (M6)**, que es lo que le faltaba a esta pantalla para que las
 * cinco acciones de amistad tuvieran puerta de entrada: `friend_request` va por
 * nombre EXACTO, así que hasta aquí solo se llegaba a alguien sabiéndoselo de
 * memoria.
 *
 * Lo que se mira, y son los cuatro *Hecho cuando:* del hito que se pueden ver
 * desde la interfaz:
 *
 *  1. **Dos letras dan el 422 del backend y no resultados**, y ese mensaje se
 *     enseña tal cual: el mínimo no está replicado en el cliente.
 *  2. **Quien está en `nobody` no sale**, y la pantalla no puede distinguirlo de
 *     que no exista — lo dice con esas palabras.
 *  3. **Desde el resultado se pide amistad sin salir de `/friends`**, y sin
 *     estrenar ninguna acción: es el `pedir()` que el M5 puso para el perfil
 *     público.
 *  4. **Y el bloque va DESPUÉS de las solicitudes recibidas**, porque ver lo que
 *     te han pedido sigue siendo lo primero de esta pantalla.
 */
describe('el buscador de usuarios', () => {
  /** El campo de texto del buscador. */
  function campo(wrapper) {
    return wrapper.find('.buscador__campo')
  }

  /** Escribe y envía el formulario, que es lo que hace una persona con el Enter. */
  async function buscar(wrapper, texto) {
    await campo(wrapper).setValue(texto)
    await wrapper.find('.buscador').trigger('submit')
    await flushPromises()
    await nextTick()
  }

  /** Las filas del bloque del buscador, que es el único con esa clase. */
  function resultados(wrapper) {
    return wrapper.findAll('.bloque--buscar .fila')
  }

  it('el bloque va después de las recibidas y antes de los amigos', async () => {
    const { wrapper } = await montarAmigos()

    const orden = titulos(wrapper)

    // Las recibidas siguen siendo las primeras —es la condición del alcance del
    // plan— y el buscador va justo detrás: es para lo que se entra aquí desde el
    // M6, y por delante de tres listas que no piden nada.
    expect(orden[0]).toContain('Solicitudes recibidas')
    expect(orden[1]).toContain('Buscar personas')
    expect(orden[2]).toContain('Amigos')
  })

  it('al montar no busca nada: `user_search` no se llama solo', async () => {
    const { wrapper } = await montarAmigos()

    expect(apiCall).not.toHaveBeenCalledWith('user_search', expect.anything())
    expect(resultados(wrapper)).toHaveLength(0)
  })

  it('busca lo escrito y pinta a quien encuentra, con su enlace al perfil', async () => {
    const { wrapper } = await montarAmigos()

    await buscar(wrapper, 'busca')

    expect(apiCall).toHaveBeenCalledWith('user_search', { q: 'busca' })
    expect(resultados(wrapper)).toHaveLength(2)

    const bloqueBuscar = bloque(wrapper, 'Buscar personas')
    expect(bloqueBuscar.text()).toContain('Sin Fila Ninguna')
    // El enlace se resuelve con el ROUTER REAL: un `params` a `undefined`
    // lanzaría al resolver y tumbaría el render entero de la pantalla.
    expect(bloqueBuscar.findAll('a').map((a) => a.attributes('href')))
      .toContain('#/user/buscable')
  })

  /**
   * **El primer *Hecho cuando:* del hito, por la interfaz.** Dos letras se
   * mandan igualmente y lo que sale es el 422 del backend, con su texto: si el
   * cliente replicara el mínimo, la regla viviría en dos sitios.
   */
  it('dos letras enseñan el 422 del backend y NINGÚN resultado', async () => {
    const { wrapper } = await montarAmigos({})

    backendConGente({
      user_search: {
        status: 'error',
        message: 'Escribe al menos 3 caracteres para buscar a alguien.',
        data: null,
        http_code: 422
      }
    })

    await buscar(wrapper, 'bu')

    expect(apiCall).toHaveBeenCalledWith('user_search', { q: 'bu' })
    expect(wrapper.find('.amigos__error--busqueda').text())
      .toContain('Escribe al menos 3 caracteres')
    expect(resultados(wrapper)).toHaveLength(0)
  })

  /**
   * **El segundo y el tercero.** `buscaoculto` existe y tiene `show_in_search`
   * en `nobody`; la respuesta capturada es un 200 con lista vacía, byte a byte
   * la misma que si no existiera. La pantalla no puede decir cuál de las dos
   * cosas pasa, y lo dice.
   */
  it('quien no quiere salir se ve igual que quien no existe, y el texto lo dice', async () => {
    const { wrapper } = await montarAmigos()

    backendConGente({ user_search: fixtura(buscarVacioFixture) })

    await buscar(wrapper, 'buscaoculto')

    const vacio = bloque(wrapper, 'Buscar personas').find('.bloque__vacio')

    expect(vacio.text()).toContain('buscaoculto')
    expect(vacio.text()).toContain('haya preferido no aparecer')
    expect(resultados(wrapper)).toHaveLength(0)
  })

  /**
   * **El cuarto *Hecho cuando:*: pedir amistad desde el resultado, sin salir.**
   * Y sin estrenar ninguna acción: `friend_request` es la del M2 y `pedir()` la
   * del M5. Después del clic, el botón de esa fila se convierte solo en
   * «Solicitud enviada», porque `pedir()` mete la fila en `enviadas` y el botón
   * sale de `relacionCon()`.
   */
  it('desde un resultado se pide amistad sin salir de /friends', async () => {
    const { wrapper } = await montarAmigos()

    backendConGente({
      user_search: {
        status: 'success',
        message: 'Resultados de la búsqueda.',
        data: { users: [{ username: 'apedir', displayName: 'A Quien Pido Amistad', avatarUrl: null }] },
        http_code: 200
      }
    })

    await buscar(wrapper, 'aped')

    const fila = resultados(wrapper)[0]
    expect(fila.find('button').text()).toContain('Pedir amistad')

    await fila.find('button').trigger('click')
    await flushPromises()
    await nextTick()

    expect(apiCall).toHaveBeenCalledWith('friend_request', { username: 'apedir' })

    // Y la pantalla se entera sola: el mismo resultado ya no ofrece pedir.
    const despues = resultados(wrapper)[0]
    expect(despues.find('button').exists()).toBe(false)
    expect(despues.find('.fila__estado').text()).toBe('Solicitud enviada')

    // La lista de enviadas de esta misma pantalla también lo recoge.
    expect(bloque(wrapper, 'Solicitudes enviadas').text()).toContain('A Quien Pido Amistad')
  })

  /**
   * **Un botón y nunca dos**, igual que en el perfil público del M5: la cadena
   * `v-if`/`v-else-if` es excluyente porque `relacionCon().amistad` es UN valor
   * de cuatro.
   */
  it('a quien ya es amigo no se le ofrece pedir amistad, se dice que ya lo es', async () => {
    const { wrapper } = await montarAmigos()

    backendConGente({
      user_search: {
        status: 'success',
        message: 'Resultados de la búsqueda.',
        data: { users: [{ username: 'amigaaceptada', displayName: 'Amiga Aceptada', avatarUrl: null }] },
        http_code: 200
      }
    })

    await buscar(wrapper, 'amig')

    const fila = resultados(wrapper)[0]

    expect(fila.find('.fila__estado').text()).toBe('Ya sois amigos')
    expect(fila.find('button').exists()).toBe(false)
  })

  it('a quien te ha pedido amistad se le dice eso, y tampoco sale el botón', async () => {
    const { wrapper } = await montarAmigos()

    backendConGente({
      user_search: {
        status: 'success',
        message: 'Resultados de la búsqueda.',
        data: { users: [{ username: 'pidiomeamistad', displayName: 'Quien Me Pidio', avatarUrl: null }] },
        http_code: 200
      }
    })

    await buscar(wrapper, 'pidio')

    const fila = resultados(wrapper)[0]

    expect(fila.find('.fila__estado').text()).toBe('Te ha pedido amistad')
    expect(fila.find('button').exists()).toBe(false)
  })

  it('«Limpiar» vacía el resultado sin tocar ninguna de las cuatro listas', async () => {
    const { wrapper } = await montarAmigos()

    await buscar(wrapper, 'busca')
    expect(resultados(wrapper)).toHaveLength(2)

    const limpiar = bloque(wrapper, 'Buscar personas')
      .findAll('button').find((b) => b.text().includes('Limpiar'))

    await limpiar.trigger('click')
    await nextTick()

    expect(resultados(wrapper)).toHaveLength(0)
    expect(campo(wrapper).element.value).toBe('')
    // Y las listas siguen ahí: el contador de la portada vive en este store.
    expect(bloque(wrapper, 'Amigos').text()).toContain('Amiga Aceptada')
    expect(bloque(wrapper, 'Solicitudes recibidas').text()).toContain('Quien Me Pidio Amistad')
  })
})
