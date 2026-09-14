import { beforeEach, describe, expect, it, vi } from 'vitest'
import { createPinia, setActivePinia } from 'pinia'

import { useFriendsStore } from '@/stores/friends'
import { apiCall } from '@/services/api'

import friendListFixture from '../../fixtures/friend_list.json'
import friendListVacioFixture from '../../fixtures/friend_list_vacio.json'
import followListFixture from '../../fixtures/follow_list.json'
import followListVacioFixture from '../../fixtures/follow_list_vacio.json'
import aceptarFixture from '../../fixtures/friend_accept.json'
import rechazarFixture from '../../fixtures/friend_reject.json'
import deshacerFixture from '../../fixtures/friend_remove.json'
import dejarDeSeguirFixture from '../../fixtures/follow_remove.json'
import pedirFixture from '../../fixtures/friend_request.json'
import seguirFixture from '../../fixtures/follow_add.json'
import buscarFixture from '../../fixtures/user_search.json'
import buscarVacioFixture from '../../fixtures/user_search_vacio.json'

/**
 * `stores/friends.js` — tu lista de gente.
 *
 * Lo que este store decide, y es lo único que se mira aquí:
 *
 *  1. **Va por `apiCall`, nunca por `publicGet`.** `/friends` está tras el guard
 *     y las ocho acciones llevan `AuthMiddleware`. Mandarlas por la vía de las
 *     rutas públicas —que es lo que hace el store vecino, `publicProfile.js`— las
 *     dejaría contestadas con un 401. Hay un test que lo fija.
 *  2. **Amistad y seguimiento no se mezclan NUNCA.** Dos peticiones, dos listas,
 *     dos errores y dos contadores. Es el fallo que el plan nombra dos veces: el
 *     día que seguir cuente como amistad, el nivel `friends` pasa a significar
 *     «cualquiera que pulse seguir».
 *  3. **El contador son las RECIBIDAS y solo ellas.** Las enviadas esperan a la
 *     otra persona, así que sumarlas pondría un número sobre algo que no te pide
 *     nada.
 *  4. **Retirar una solicitud enviada es `friend_remove`**, la enmienda del
 *     2026-09-14: `friend_reject` es solo del destinatario, así que sin esto
 *     quien pedía amistad no tenía forma de retirarla.
 *
 * Las fixturas son capturas reales de las seis acciones, hechas el 2026-09-14
 * contra el backend de dev con usuarios de usar y tirar (`9101`-`9106`, borrados
 * al terminar). Ver `tests/fixtures/README.md`.
 */

/**
 * La frontera de mock del plan de tests. Este store solo usa `apiCall` —todo lo
 * suyo va por el endpoint único—, pero el doble declara las tres caras porque
 * `vi.mock` sustituye el módulo entero.
 */
vi.mock('@/services/api', () => ({
  apiCall: vi.fn(),
  catalogGet: vi.fn(),
  publicGet: vi.fn()
}))

/** Copia profunda: los stores mutan lo que reciben y las fixturas son de todos. */
function fixtura(json) {
  return structuredClone(json)
}

/**
 * Un doble que responde según la ACCIÓN, que es como funciona el endpoint único.
 * `cargar()` lanza las dos listas con un `Promise.all`, así que una cola de
 * `mockResolvedValueOnce` haría que el orden de resolución decidiera qué fixtura
 * se lleva cada lista y el test pasaría o fallaría por azar.
 */
function respondeSegunAccion(mapa) {
  apiCall.mockImplementation((accion) =>
    Promise.resolve(
      mapa[accion] ?? { status: 'error', message: `Sin doble para ${accion}.`, http_code: 500 }
    )
  )
}

/** El backend con las cuatro listas llenas, que es el caso que ejercita todo. */
function backendConGente() {
  respondeSegunAccion({
    friend_list: fixtura(friendListFixture),
    follow_list: fixtura(followListFixture),
    friend_accept: fixtura(aceptarFixture),
    friend_reject: fixtura(rechazarFixture),
    friend_remove: fixtura(deshacerFixture),
    follow_remove: fixtura(dejarDeSeguirFixture),
    friend_request: fixtura(pedirFixture),
    follow_add: fixtura(seguirFixture),
    user_search: fixtura(buscarFixture)
  })
}

let store

beforeEach(() => {
  // Pinia REAL y no `createTestingPinia`: lo que se prueba son las acciones, y
  // el `stubActions: true` por defecto de aquella no las ejecutaría.
  setActivePinia(createPinia())
  store = useFriendsStore()

  apiCall.mockReset()
})

describe('el estado inicial', () => {
  it('arranca con las cuatro listas vacías y los contadores a cero', () => {
    expect(store.amigos).toEqual([])
    expect(store.recibidas).toEqual([])
    expect(store.enviadas).toEqual([])
    expect(store.siguiendo).toEqual([])
    expect(store.contadores).toEqual({ friends: 0, pending: 0, sent: 0 })
    expect(store.seguidores).toBe(0)
    expect(store.pendientes).toBe(0)
    expect(store.vacio).toBe(true)
    expect(store.cargado).toBe(false)
  })
})

describe('cargar', () => {
  it('pide las DOS listas, y por `apiCall`: `/friends` va tras el guard', async () => {
    backendConGente()

    await store.cargar()

    // Dos acciones distintas, porque son dos tablas y dos controllers. Y las dos
    // por el endpoint único: si alguna se fuera por `publicGet`, el backend la
    // contestaría con un 401 —no hay cookie en esa vía—.
    expect(apiCall).toHaveBeenCalledWith('friend_list')
    expect(apiCall).toHaveBeenCalledWith('follow_list')
    expect(apiCall).toHaveBeenCalledTimes(2)
  })

  it('reparte las tres listas de amistad tal como las separa el backend', async () => {
    backendConGente()

    await store.cargar()

    const datos = fixtura(friendListFixture).data

    expect(store.amigos).toEqual(datos.friends)
    expect(store.recibidas).toEqual(datos.pending)
    expect(store.enviadas).toEqual(datos.sent)

    // Quién pidió es lo único que decide en qué lista cae una `pending`, y es lo
    // mismo que decide quién puede aceptarla: por eso la vista no puede ofrecer
    // un botón que el servidor vaya a rechazar con un 403.
    expect(store.recibidas[0].user.username).toBe('pidiomeamistad')
    expect(store.enviadas[0].user.username).toBe('lepediamistad')
  })

  it('los contadores se toman del backend, no se recuentan aquí', async () => {
    backendConGente()

    await store.cargar()

    expect(store.contadores).toEqual(fixtura(friendListFixture).data.counts)
    expect(store.pendientes).toBe(fixtura(friendListFixture).data.counts.pending)
  })

  it('guarda a quién sigues y cuánta gente te sigue, que son cosas distintas', async () => {
    backendConGente()

    await store.cargar()

    // `following` es una lista —son tus marcadores— y `followerCount` un número
    // y nunca una lista: quién te sigue no se publica.
    expect(store.siguiendo).toEqual(fixtura(followListFixture).data.following)
    expect(store.seguidores).toBe(fixtura(followListFixture).data.followerCount)
  })

  it('NINGUNA de las dos listas trae un email ni un id de usuario', async () => {
    backendConGente()

    await store.cargar()

    // El backend las compone por lista blanca con un `JOIN users` que ni siquiera
    // selecciona el `email`, y tampoco el `id` numérico —publicarlo daría un
    // diccionario de ids por el que empezar a recorrer—. Si algún día llegara
    // alguno de los dos, el fallo está en el backend y este test lo canta.
    const personas = [
      ...store.amigos.map((f) => f.user),
      ...store.recibidas.map((f) => f.user),
      ...store.enviadas.map((f) => f.user),
      ...store.siguiendo
    ]

    expect(personas.length).toBeGreaterThan(0)

    for (const persona of personas) {
      expect(persona).not.toHaveProperty('email')
      expect(persona).not.toHaveProperty('id')
      expect(Object.keys(persona).sort())
        .toEqual(expect.arrayContaining(['username', 'displayName', 'avatarUrl']))
    }
  })

  it('sin nadie en ninguna lista, el store queda vacío y sin error', async () => {
    respondeSegunAccion({
      friend_list: fixtura(friendListVacioFixture),
      follow_list: fixtura(followListVacioFixture)
    })

    await store.cargar()

    expect(store.vacio).toBe(true)
    expect(store.error).toBeNull()
    expect(store.errorSeguidos).toBeNull()
    expect(store.seguidores).toBe(0)
    // Vacío NO es un fallo: es la respuesta de quien todavía no conoce a nadie.
    expect(store.cargado).toBe(true)
  })

  it('un fallo en los seguimientos NO borra el aviso de las amistades, ni al revés', async () => {
    // Son dos peticiones independientes y una puede fallar sin la otra. Con un
    // solo `error`, media pantalla cargada perfectamente diría que no cargó.
    respondeSegunAccion({
      friend_list: fixtura(friendListFixture),
      follow_list: { status: 'error', message: 'Demasiadas peticiones.', http_code: 429 }
    })

    await store.cargar()

    expect(store.error).toBeNull()
    expect(store.amigos).toHaveLength(fixtura(friendListFixture).data.friends.length)
    expect(store.errorSeguidos).toBe('Demasiadas peticiones.')
    expect(store.siguiendo).toEqual([])
  })

  it('un 401 se traduce a algo que el usuario pueda resolver', async () => {
    respondeSegunAccion({
      friend_list: { status: 'error', message: 'No autorizado.', http_code: 401 },
      follow_list: { status: 'error', message: 'No autorizado.', http_code: 401 }
    })

    await store.cargar()

    // Es el único fallo que el usuario puede arreglar, y el mensaje del backend
    // para ese caso no se lo dice.
    expect(store.error).toMatch(/sesión ha caducado/i)
    expect(store.errorSeguidos).toMatch(/sesión ha caducado/i)
  })

  it('un fallo vacía las listas en vez de dejar lo de antes en pantalla', async () => {
    backendConGente()
    await store.cargar()
    expect(store.amigos.length).toBeGreaterThan(0)

    respondeSegunAccion({
      friend_list: { status: 'error', message: 'Se rompió.', http_code: 500 },
      follow_list: { status: 'error', message: 'Se rompió.', http_code: 500 }
    })
    await store.cargar()

    expect(store.amigos).toEqual([])
    expect(store.contadores).toEqual({ friends: 0, pending: 0, sent: 0 })
  })
})

describe('cargarContador — lo que pide la portada', () => {
  it('pide SOLO `friend_list`: a quién sigues no se pinta en la portada', async () => {
    backendConGente()

    await store.cargarContador()

    expect(apiCall).toHaveBeenCalledWith('friend_list')
    expect(apiCall).toHaveBeenCalledTimes(1)
    expect(store.pendientes).toBe(fixtura(friendListFixture).data.counts.pending)
  })

  it('no repite la petición en cada visita a la portada', async () => {
    backendConGente()

    await store.cargarContador()
    await store.cargarContador()

    expect(apiCall).toHaveBeenCalledTimes(1)
  })

  it('`forzar` la repite, que es lo que hace `/friends` al entrar', async () => {
    backendConGente()

    await store.cargarContador()
    await store.cargarContador({ forzar: true })

    expect(apiCall).toHaveBeenCalledTimes(2)
  })

  it('un fallo aquí NO deja error ni aviso: nadie ha pedido nada en la portada', async () => {
    respondeSegunAccion({ friend_list: { status: 'error', message: 'Se rompió.', http_code: 500 } })

    await store.cargarContador()

    expect(store.error).toBeNull()
    expect(store.aviso).toBeNull()
    expect(store.cargado).toBe(false)
  })
})

describe('el contador de la barra', () => {
  it('cuenta las solicitudes RECIBIDAS y no las enviadas', async () => {
    backendConGente()

    await store.cargar()

    // La fixtura tiene una de cada, así que si alguien sumara las dos el número
    // sería 2 en vez de 1 y este test lo vería.
    expect(store.contadores.pending).toBe(1)
    expect(store.contadores.sent).toBe(1)
    expect(store.pendientes).toBe(1)
  })

  it('aceptar baja el contador en el sitio, sin volver a preguntar al backend', async () => {
    backendConGente()
    await store.cargar()
    apiCall.mockClear()

    await store.aceptar(store.recibidas[0])

    expect(store.pendientes).toBe(0)
    // Una petición y solo una: la de aceptar. Recargar la lista entera para
    // bajar un número sería una petición de más en cada clic.
    expect(apiCall).toHaveBeenCalledTimes(1)
  })
})

describe('aceptar', () => {
  it('manda `friend_accept` con el id de la amistad y mueve la fila a amigos', async () => {
    backendConGente()
    await store.cargar()

    const solicitud = store.recibidas[0]

    await store.aceptar(solicitud)

    expect(apiCall).toHaveBeenCalledWith('friend_accept', {
      friendship_id: solicitud.friendshipId
    })
    expect(store.recibidas).toEqual([])
    expect(store.amigos[0].friendshipId).toBe(solicitud.friendshipId)
    expect(store.contadores.friends).toBe(2)
  })

  it('deja un aviso con el nombre de la persona, que es lo que el usuario reconoce', async () => {
    backendConGente()
    await store.cargar()

    await store.aceptar(store.recibidas[0])

    expect(store.aviso.tipo).toBe('ok')
    expect(store.aviso.texto).toContain('Quien Me Pidio Amistad')
  })

  it('el doble clic no manda dos veces: la segunda llegaría a una fila ya aceptada', async () => {
    backendConGente()
    await store.cargar()
    apiCall.mockClear()

    const solicitud = store.recibidas[0]
    const [uno, dos] = await Promise.all([store.aceptar(solicitud), store.aceptar(solicitud)])

    expect(apiCall).toHaveBeenCalledTimes(1)
    expect(uno).toBe(true)
    // Sin la guarda, la segunda llamada se comería un 409 del backend después de
    // haber hecho exactamente lo que el usuario pidió.
    expect(dos).toBe(false)
  })

  it('un 403 se enseña con el mensaje del backend y NO mueve la fila', async () => {
    backendConGente()
    await store.cargar()

    respondeSegunAccion({
      friend_accept: {
        status: 'error',
        message: 'Esa solicitud no es tuya: solo puede aceptarla quien la recibió.',
        http_code: 403
      }
    })

    const antes = store.recibidas.length
    const resultado = await store.aceptar(store.recibidas[0])

    expect(resultado).toBe(false)
    expect(store.recibidas).toHaveLength(antes)
    expect(store.amigos).toHaveLength(1)
    // Tal cual lo manda el backend: reescribirlo aquí sería un segundo juego de
    // mensajes que se desincroniza del primero.
    expect(store.aviso).toEqual({
      tipo: 'error',
      texto: 'Esa solicitud no es tuya: solo puede aceptarla quien la recibió.'
    })
  })

  it('un 404 recarga la lista: esa fila ya no existe y la pantalla miente', async () => {
    backendConGente()
    await store.cargar()

    const solicitud = store.recibidas[0]

    respondeSegunAccion({
      friend_accept: { status: 'error', message: 'Esa amistad no existe.', http_code: 404 },
      friend_list: fixtura(friendListVacioFixture),
      follow_list: fixtura(followListVacioFixture)
    })

    await store.aceptar(solicitud)

    // La otra persona la retiró mientras mirabas. Sin la recarga, el usuario se
    // quedaría pulsando un botón muerto.
    expect(apiCall).toHaveBeenCalledWith('friend_list')
    expect(store.recibidas).toEqual([])
  })

  it('una fila sin `friendshipId` no llama a la red', async () => {
    backendConGente()
    await store.cargar()
    apiCall.mockClear()

    expect(await store.aceptar({})).toBe(false)
    expect(await store.aceptar(null)).toBe(false)
    expect(apiCall).not.toHaveBeenCalled()
  })
})

describe('rechazar', () => {
  it('manda `friend_reject`, quita la fila y baja el contador', async () => {
    backendConGente()
    await store.cargar()

    const solicitud = store.recibidas[0]

    await store.rechazar(solicitud)

    expect(apiCall).toHaveBeenCalledWith('friend_reject', {
      friendship_id: solicitud.friendshipId
    })
    expect(store.recibidas).toEqual([])
    expect(store.pendientes).toBe(0)
    // Y NO se convierte en amistad ni en nada: la fila desaparece.
    expect(store.amigos).toHaveLength(1)
  })

  it('el aviso dice que se puede volver a pedir, porque no hay estado `rejected`', async () => {
    backendConGente()
    await store.cargar()

    await store.rechazar(store.recibidas[0])

    // Rechazar hace DELETE: nadie acumula un historial de desaires y quien pidió
    // puede volver a pedir. Con un `rejected` y el UNIQUE simétrico, la fila
    // rechazada habría bloqueado para siempre sin que nadie lo decidiera.
    expect(store.aviso.texto).toMatch(/volver a pedírtelo/i)
  })
})

describe('retirar — la enmienda del 2026-09-14', () => {
  it('una solicitud ENVIADA se retira con `friend_remove`, no con `friend_reject`', async () => {
    backendConGente()
    await store.cargar()

    const enviada = store.enviadas[0]

    await store.retirar(enviada)

    // `friend_reject` es SOLO del destinatario, así que sin esto quien envía una
    // solicitud no tendría ninguna forma de retirarla. El criterio ya no es el
    // estado de la fila sino de qué lado estás: si participas, la deshaces.
    expect(apiCall).toHaveBeenCalledWith('friend_remove', {
      friendship_id: enviada.friendshipId
    })
    expect(apiCall).not.toHaveBeenCalledWith('friend_reject', expect.anything())
    expect(store.enviadas).toEqual([])
    expect(store.contadores.sent).toBe(0)
  })

  it('retirar no toca el contador de recibidas', async () => {
    backendConGente()
    await store.cargar()

    await store.retirar(store.enviadas[0])

    expect(store.pendientes).toBe(1)
  })
})

describe('deshacer una amistad', () => {
  it('manda `friend_remove` y saca a la persona de la lista de amigos', async () => {
    backendConGente()
    await store.cargar()

    const amigo = store.amigos[0]

    await store.deshacer(amigo)

    expect(apiCall).toHaveBeenCalledWith('friend_remove', { friendship_id: amigo.friendshipId })
    expect(store.amigos).toEqual([])
    expect(store.contadores.friends).toBe(0)
  })

  it('el aviso dice lo que se corta de verdad: la próxima lectura, no lo ya visto', async () => {
    backendConGente()
    await store.cargar()

    await store.deshacer(store.amigos[0])

    expect(store.aviso.texto).toContain('Amiga Aceptada')
    expect(store.aviso.texto).toMatch(/deja de ver/i)
  })

  it('deshacer y retirar usan la MISMA acción y siguen siendo dos cosas distintas', async () => {
    backendConGente()
    await store.cargar()

    await store.deshacer(store.amigos[0])
    const avisoDeshacer = store.aviso.texto

    await store.retirar(store.enviadas[0])
    const avisoRetirar = store.aviso.texto

    // «has retirado tu solicitud» y «ya no sois amigos» no son la misma frase ni
    // el mismo acto, aunque el endpoint sea el mismo.
    expect(avisoDeshacer).not.toBe(avisoRetirar)
    expect(avisoRetirar).toMatch(/retirada/i)
  })
})

describe('dejar de seguir', () => {
  it('manda `follow_remove` por USERNAME: `user_follow` no tiene id', async () => {
    backendConGente()
    await store.cargar()

    const seguido = store.siguiendo[0]

    await store.dejarDeSeguir(seguido)

    // Su clave es `(follower_id, followed_id)`, asimétrica a propósito: no hay un
    // id de fila que mandar.
    expect(apiCall).toHaveBeenCalledWith('follow_remove', { username: seguido.username })
    expect(store.siguiendo.some((f) => f.username === seguido.username)).toBe(false)
  })

  it('NO toca ninguna de las tres listas de amistad', async () => {
    backendConGente()
    await store.cargar()

    const amigosAntes = [...store.amigos]

    await store.dejarDeSeguir(store.siguiendo[0])

    // Son dos relaciones distintas. Si soltar un marcador tocara la amistad,
    // seguir habría empezado a significar algo que no significa.
    expect(store.amigos).toEqual(amigosAntes)
    expect(store.contadores).toEqual(fixtura(friendListFixture).data.counts)
  })

  it('el doble clic no manda dos veces', async () => {
    backendConGente()
    await store.cargar()
    apiCall.mockClear()

    const seguido = store.siguiendo[0]
    const [uno, dos] = await Promise.all([
      store.dejarDeSeguir(seguido),
      store.dejarDeSeguir(seguido)
    ])

    expect(apiCall).toHaveBeenCalledTimes(1)
    expect(uno).toBe(true)
    expect(dos).toBe(false)
  })

  it('un fallo deja la fila donde estaba y lo dice', async () => {
    backendConGente()
    await store.cargar()

    const antes = store.siguiendo.length

    respondeSegunAccion({
      follow_remove: { status: 'error', message: 'No autorizado.', http_code: 401 }
    })

    expect(await store.dejarDeSeguir(store.siguiendo[0])).toBe(false)
    expect(store.siguiendo).toHaveLength(antes)
    expect(store.aviso.texto).toMatch(/sesión ha caducado/i)
  })

  it('una fila sin `username` no llama a la red', async () => {
    backendConGente()
    await store.cargar()
    apiCall.mockClear()

    expect(await store.dejarDeSeguir({})).toBe(false)
    expect(apiCall).not.toHaveBeenCalled()
  })
})

describe('limpiar', () => {
  it('deja el store como recién creado', async () => {
    backendConGente()
    await store.cargar()

    store.limpiar()

    expect(store.amigos).toEqual([])
    expect(store.recibidas).toEqual([])
    expect(store.enviadas).toEqual([])
    expect(store.siguiendo).toEqual([])
    expect(store.contadores).toEqual({ friends: 0, pending: 0, sent: 0 })
    expect(store.seguidores).toBe(0)
    expect(store.aviso).toBeNull()
    expect(store.error).toBeNull()
    expect(store.errorSeguidos).toBeNull()
    expect(store.moviendo).toEqual([])
    expect(store.soltando).toEqual([])
    expect(store.cargado).toBe(false)

    // Las cinco del M5, que también tienen que volver al estado de fábrica: si
    // `cargadoSeguidos` sobreviviera a un `limpiar()`, la siguiente pantalla
    // daría por buena una lista de seguidos vacía y pintaría «Seguir» sobre
    // alguien a quien ya se sigue.
    expect(store.pidiendo).toEqual([])
    expect(store.marcando).toEqual([])
    expect(store.cargadoSeguidos).toBe(false)
    expect(store.cargandoRelacion).toBe(false)
    expect(store.errorRelacion).toBeNull()
  })
})

/**
 * ---------------------------------------------------------------------------
 * M5 — lo que el PERFIL PÚBLICO necesita de este store.
 *
 * Tres cosas, y la primera es la que sostiene el hito entero:
 *
 *  1. **`relacionCon(username)` deduce la relación cruzando las dos listas.**
 *     Ninguna acción del backend contesta «¿qué soy yo de esta persona?»:
 *     `friend_list` y `follow_list` traen las listas ENTERAS y la ruta pública
 *     `GET /api/public/user/{u}` no trae la relación a propósito. El cruce lo
 *     hace el cliente.
 *  2. **Amistad y seguimiento son ortogonales**, y el getter los devuelve en dos
 *     campos independientes. Es la garantía estructural de que no salgan dos
 *     botones contradictorios: `amistad` es un valor de cuatro y `siguiendo` un
 *     booleano, así que no hay estado que encienda dos controles de la misma
 *     cadena.
 *  3. **`pedir()` y `seguir()` van por `username`**, que es lo que reciben
 *     `friend_request` y `follow_add`, y por `apiCall`.
 * ---------------------------------------------------------------------------
 */

describe('relacionCon — la relación con UNA persona, deducida de tus listas', () => {
  beforeEach(async () => {
    backendConGente()
    await store.cargar()
  })

  it('«nada»: quien no está en ninguna de las cuatro listas', () => {
    expect(store.relacionCon('unadesconocida')).toEqual({
      amistad: 'nada',
      fila: null,
      siguiendo: false
    })
  })

  it('«amigos»: sale de `friends`, y trae la fila con su `friendshipId`', () => {
    const relacion = store.relacionCon('amigaaceptada')

    expect(relacion.amistad).toBe('amigos')
    // El `friendshipId` es lo que necesita `deshacer()`: sin él, el botón de
    // «Quitar amistad» no tendría qué mandar.
    expect(relacion.fila.friendshipId).toBe(6)
    expect(relacion.siguiendo).toBe(false)
  })

  it('«recibida»: sale de `pending`, que es la lista de quien te pidió a TI', () => {
    const relacion = store.relacionCon('pidiomeamistad')

    expect(relacion.amistad).toBe('recibida')
    expect(relacion.fila.friendshipId).toBe(7)
  })

  it('«enviada»: sale de `sent`, y NO se confunde con la anterior', () => {
    const relacion = store.relacionCon('lepediamistad')

    expect(relacion.amistad).toBe('enviada')
    expect(relacion.fila.friendshipId).toBe(8)

    // Las dos son `pending` en la tabla y lo único que las separa es quién
    // pidió. Si se confundieran, el perfil ofrecería «Aceptar» sobre una
    // solicitud propia y el backend contestaría 403.
    expect(store.relacionCon('pidiomeamistad').amistad).not.toBe('enviada')
  })

  it('«siguiendo» es un campo APARTE y no un quinto estado de la amistad', () => {
    const seguido = store.relacionCon('perfilseguido')

    // Se le sigue, y con eso la amistad sigue siendo «nada»: seguir no es un
    // grado de amistad ni concede nada. Si esto fuera un selector de cinco
    // estados, este caso tendría que elegir uno y mentiría en cualquiera.
    expect(seguido.siguiendo).toBe(true)
    expect(seguido.amistad).toBe('nada')
  })

  it('se puede ser amigo Y seguir a la vez: son dos relaciones, no una', async () => {
    // La fixtura no trae esa combinación porque en el backend son dos tablas
    // que no se consultan juntas; se monta aquí sobre el estado ya cargado.
    store.siguiendo = [...store.siguiendo, { username: 'amigaaceptada', displayName: 'Amiga Aceptada' }]

    const relacion = store.relacionCon('amigaaceptada')

    expect(relacion.amistad).toBe('amigos')
    expect(relacion.siguiendo).toBe(true)
  })

  it('sin `username` no revienta y devuelve el estado neutro', () => {
    expect(store.relacionCon('').amistad).toBe('nada')
    expect(store.relacionCon(undefined).siguiendo).toBe(false)
  })

  it('LOS CINCO ESTADOS del *Hecho cuando:*, cada uno con su cadena excluyente', () => {
    store.siguiendo = [...store.siguiendo, { username: 'amigaaceptada' }]

    const casos = [
      ['unadesconocida', 'nada', false],
      ['lepediamistad', 'enviada', false],
      ['pidiomeamistad', 'recibida', false],
      ['amigaaceptada', 'amigos', true],
      ['perfilseguido', 'nada', true]
    ]

    for (const [username, amistad, siguiendo] of casos) {
      const relacion = store.relacionCon(username)

      expect(relacion.amistad).toBe(amistad)
      expect(relacion.siguiendo).toBe(siguiendo)

      // Y la condición literal: `amistad` es UN valor de una lista cerrada, así
      // que la cadena `v-if`/`v-else-if` de la vista enciende exactamente un
      // control. No hay combinación que encienda dos.
      expect(['nada', 'enviada', 'recibida', 'amigos']).toContain(relacion.amistad)
    }
  })
})

describe('cargarRelaciones', () => {
  it('pide las dos listas la primera vez, y ninguna la segunda', async () => {
    backendConGente()

    await store.cargarRelaciones()

    expect(apiCall).toHaveBeenCalledWith('friend_list')
    expect(apiCall).toHaveBeenCalledWith('follow_list')
    expect(apiCall).toHaveBeenCalledTimes(2)

    apiCall.mockClear()

    // Abrir un segundo perfil no vuelve a pedir nada: el store ya lo tiene.
    await store.cargarRelaciones()
    expect(apiCall).not.toHaveBeenCalled()
  })

  it('`amistad: false` pide SOLO `follow_list` — es el perfil de uno mismo', async () => {
    backendConGente()

    await store.cargarRelaciones({ amistad: false })

    expect(apiCall).toHaveBeenCalledWith('follow_list')
    expect(apiCall).toHaveBeenCalledTimes(1)
    // Y el número de seguidores, que es lo único que se va a pintar ahí.
    expect(store.seguidores).toBe(2)
  })

  it('tras cargar solo los seguidos, abrir un perfil ajeno sí pide `friend_list`', async () => {
    backendConGente()
    await store.cargarRelaciones({ amistad: false })
    apiCall.mockClear()

    await store.cargarRelaciones()

    // La bandera de seguidos no vale por la de amistades: con una sola, este
    // perfil habría pintado «Pedir amistad» sobre gente que ya es amiga.
    expect(apiCall).toHaveBeenCalledWith('friend_list')
    expect(apiCall).toHaveBeenCalledTimes(1)
  })

  it('un fallo deja `errorRelacion` y NO ensucia el error de `/friends`', async () => {
    respondeSegunAccion({
      friend_list: { status: 'error', message: 'Demasiadas peticiones.', http_code: 429 },
      follow_list: fixtura(followListFixture)
    })

    await store.cargarRelaciones()

    expect(store.errorRelacion).toMatch(/demasiadas/i)
    // `error` es el aviso rojo de `/friends`, una pantalla que el usuario ni
    // siquiera ha abierto: dejarle ahí un error de otra pantalla sería ruido.
    expect(store.error).toBeNull()
    // Y lo que sí llegó se aplica igual: media respuesta no se tira.
    expect(store.siguiendo).toHaveLength(2)
    expect(store.cargado).toBe(false)
    expect(store.cargadoSeguidos).toBe(true)
  })

  it('un 401 se traduce a «tu sesión ha caducado»', async () => {
    respondeSegunAccion({
      friend_list: { status: 'error', message: 'Authentication required.', http_code: 401 },
      follow_list: { status: 'error', message: 'Authentication required.', http_code: 401 }
    })

    await store.cargarRelaciones()

    expect(store.errorRelacion).toMatch(/sesión ha caducado/i)
  })

  it('`forzar` vuelve a pedirlo todo aunque ya estuviera cargado', async () => {
    backendConGente()
    await store.cargarRelaciones()
    apiCall.mockClear()

    await store.cargarRelaciones({ forzar: true })

    expect(apiCall).toHaveBeenCalledTimes(2)
  })
})

describe('pedir — `friend_request`, por `username`', () => {
  it('manda el `username` y mete la fila creada en `enviadas`', async () => {
    backendConGente()
    await store.cargar()
    apiCall.mockClear()

    expect(await store.pedir('apedir')).toBe(true)

    // Por `username` y no por id: es la clave pública del proyecto y es lo que
    // recibe la acción. Y por `apiCall`, que es la vía con cookie y CSRF.
    expect(apiCall).toHaveBeenCalledWith('friend_request', { username: 'apedir' })

    // La fila sale de la respuesta y NO de una segunda petición: el backend
    // devuelve `friendshipId` y la persona enteros.
    expect(apiCall).toHaveBeenCalledTimes(1)
    expect(store.enviadas[0].friendshipId).toBe(11)
    expect(store.enviadas[0].user.username).toBe('apedir')
    expect(store.contadores.sent).toBe(2)
    // El aviso nombra a la persona por su `displayName`, que es como la vio el
    // usuario en la página, y dice que ahora toca esperar: una solicitud enviada
    // no es una amistad, y el texto no puede dar a entender que sí.
    expect(store.aviso.tipo).toBe('ok')
    expect(store.aviso.texto).toContain('A Quien Pido Amistad')
    expect(store.aviso.texto).toMatch(/acepte/i)
  })

  it('la fila nueva NO lleva amistad ni seguimiento: solo entra en `enviadas`', async () => {
    backendConGente()
    await store.cargar()

    await store.pedir('apedir')

    expect(store.relacionCon('apedir')).toEqual({
      amistad: 'enviada',
      fila: expect.objectContaining({ friendshipId: 11 }),
      siguiendo: false
    })
    // Pedir amistad no es seguir. Si esta lista creciera, el nivel `friends`
    // habría empezado a significar otra cosa.
    expect(store.siguiendo.some((f) => f.username === 'apedir')).toBe(false)
  })

  it('un 409 recarga las listas: significa que las tuyas están viejas', async () => {
    backendConGente()
    await store.cargar()

    respondeSegunAccion({
      friend_request: {
        status: 'error',
        message: 'Ya existe una solicitud con esa persona.',
        http_code: 409
      },
      friend_list: fixtura(friendListFixture),
      follow_list: fixtura(followListFixture)
    })
    apiCall.mockClear()

    expect(await store.pedir('apedir')).toBe(false)

    // El `UNIQUE` es simétrico: un 409 puede ser una solicitud que esa persona
    // te mandó a TI. Dejar el botón donde estaba invitaría a repetirlo; debajo
    // hay un «Aceptar».
    expect(apiCall).toHaveBeenCalledWith('friend_list')
    expect(apiCall).toHaveBeenCalledWith('follow_list')
    expect(store.aviso).toEqual({ tipo: 'error', texto: 'Ya existe una solicitud con esa persona.' })
  })

  it('un 404 NO recarga nada: no es que la relación cambiara, es que no existe', async () => {
    respondeSegunAccion({
      friend_request: {
        status: 'error',
        message: 'No hay nadie con ese nombre de usuario.',
        http_code: 404
      }
    })

    expect(await store.pedir('nohayquiensellameasi')).toBe(false)

    expect(apiCall).toHaveBeenCalledTimes(1)
    expect(store.aviso.texto).toMatch(/no hay nadie/i)
  })

  it('el doble clic manda UNA sola petición', async () => {
    backendConGente()

    const [a, b] = await Promise.all([store.pedir('apedir'), store.pedir('apedir')])

    // Sin la guarda, el segundo `friend_request` choca contra el `UNIQUE` y
    // devuelve un 409 justo después de haber hecho lo que el usuario pedía.
    expect([a, b].filter(Boolean)).toHaveLength(1)
    expect(apiCall).toHaveBeenCalledTimes(1)
    expect(store.pidiendo).toEqual([])
  })

  it('sin `username` no llama a la red', async () => {
    expect(await store.pedir('')).toBe(false)
    expect(apiCall).not.toHaveBeenCalled()
  })
})

describe('seguir — `follow_add`, por `username`', () => {
  it('manda el `username` y mete a la persona en `siguiendo`', async () => {
    backendConGente()
    await store.cargar()
    apiCall.mockClear()

    expect(await store.seguir('aseguir')).toBe(true)

    expect(apiCall).toHaveBeenCalledWith('follow_add', { username: 'aseguir' })
    expect(store.siguiendo[0].username).toBe('aseguir')
    expect(store.siguiendo).toHaveLength(3)
  })

  it('seguir NO toca la amistad ni los contadores de amistad', async () => {
    backendConGente()
    await store.cargar()

    const antes = { ...store.contadores }
    await store.seguir('aseguir')

    // Es la línea que el plan nombra dos veces: seguir no concede nada, así que
    // no puede aparecer en ninguna lista de amistad.
    expect(store.relacionCon('aseguir').amistad).toBe('nada')
    expect(store.amigos).toHaveLength(1)
    expect(store.contadores).toEqual(antes)
  })

  it('NO mueve `seguidores`: ese número es cuánta gente te sigue a TI', async () => {
    backendConGente()
    await store.cargar()

    expect(store.seguidores).toBe(2)
    await store.seguir('aseguir')
    expect(store.seguidores).toBe(2)
  })

  it('seguir dos veces es idempotente y no duplica la fila', async () => {
    backendConGente()
    await store.cargar()

    await store.seguir('aseguir')
    await store.seguir('aseguir')

    // El backend contesta 200 las dos veces (`ON DUPLICATE KEY UPDATE`): si el
    // store no comprobara, `/friends` enseñaría a la misma persona dos veces.
    expect(store.siguiendo.filter((f) => f.username === 'aseguir')).toHaveLength(1)
  })

  it('el 422 del perfil cerrado se enseña tal cual y no pone marcador', async () => {
    respondeSegunAccion({
      follow_add: {
        status: 'error',
        message: 'Ese perfil no enseña nada públicamente: seguirlo sería un marcador a una página vacía.',
        http_code: 422
      }
    })

    expect(await store.seguir('apedir')).toBe(false)

    // El cliente NO puede predecir este 422: el backend mira los niveles
    // configurados y aquí solo se tiene el mapa `visible`, que dice qué ve uno
    // mismo. Por eso el botón se pinta siempre y el mensaje se enseña entero.
    expect(store.siguiendo).toEqual([])
    expect(store.aviso.texto).toMatch(/no enseña nada públicamente/i)
  })

  it('el doble clic manda UNA sola petición', async () => {
    backendConGente()

    const [a, b] = await Promise.all([store.seguir('aseguir'), store.seguir('aseguir')])

    expect([a, b].filter(Boolean)).toHaveLength(1)
    expect(apiCall).toHaveBeenCalledTimes(1)
    expect(store.marcando).toEqual([])
  })

  it('sin `username` no llama a la red', async () => {
    expect(await store.seguir(undefined)).toBe(false)
    expect(apiCall).not.toHaveBeenCalled()
  })
})

describe('las dos guardas de «hay algo en vuelo»', () => {
  it('`amistadOcupada` junta `pidiendo` (sin fila) y `moviendo` (con fila)', async () => {
    backendConGente()
    await store.cargar()

    expect(store.amistadOcupada('amigaaceptada')).toBe(false)

    store.moviendo = [6]
    expect(store.amistadOcupada('amigaaceptada')).toBe(true)

    store.moviendo = []
    store.pidiendo = ['unadesconocida']
    expect(store.amistadOcupada('unadesconocida')).toBe(true)
    expect(store.amistadOcupada('')).toBe(false)
  })

  it('`seguimientoOcupado` es INDEPENDIENTE: un `friend_request` lento no mata el botón de seguir', async () => {
    backendConGente()
    await store.cargar()

    store.pidiendo = ['perfilseguido']

    expect(store.amistadOcupada('perfilseguido')).toBe(true)
    expect(store.seguimientoOcupado('perfilseguido')).toBe(false)

    store.marcando = ['perfilseguido']
    expect(store.seguimientoOcupado('perfilseguido')).toBe(true)

    store.marcando = []
    store.soltando = ['perfilseguido']
    expect(store.seguimientoOcupado('perfilseguido')).toBe(true)
  })
})


/**
 * **El buscador (M6).** Cinco campos de estado y una acción, y lo que se prueba
 * es lo mismo de siempre en este fichero: que no se mezcle con las listas.
 *
 * Las dos fixturas son capturas reales de `user_search` contra el backend de dev
 * con usuarios de usar y tirar (`9301`-`9305`, borrados al terminar), y la
 * segunda es **la que más importa**: una búsqueda que no encuentra a nadie es un
 * **200 con lista vacía**, exactamente igual que una en la que sí hay alguien
 * pero tiene `show_in_search` en `nobody`. Que las dos respuestas sean idénticas
 * es lo que hace que el interruptor de privacidad sirva de algo.
 */
describe('buscar', () => {
  it('llama a `user_search` con lo escrito y guarda los resultados', async () => {
    backendConGente()

    expect(await store.buscar('busca')).toBe(true)

    // Por `apiCall` y no por `publicGet`: `user_search` lleva `AuthMiddleware`,
    // porque un buscador de personas abierto a internet sería el directorio que
    // la sexta columna de privacidad existe para no publicar.
    expect(apiCall).toHaveBeenCalledWith('user_search', { q: 'busca' })
    expect(store.resultados.map((u) => u.username)).toEqual(['buscable', 'buscavisible'])
    expect(store.consulta).toBe('busca')
    expect(store.busquedaHecha).toBe(true)
    expect(store.errorBusqueda).toBeNull()
  })

  it('recorta los espacios antes de mandar', async () => {
    backendConGente()

    await store.buscar('   busca   ')

    expect(apiCall).toHaveBeenCalledWith('user_search', { q: 'busca' })
    expect(store.consulta).toBe('busca')
  })

  /**
   * **El primer *Hecho cuando:* del hito, visto desde el cliente.** El mínimo de
   * tres caracteres NO se replica aquí: se manda lo que haya, el backend
   * contesta 422 y su mensaje se enseña tal cual. Si el store lo bloqueara, el
   * 422 no llegaría nunca y la regla viviría en dos sitios que se
   * desincronizan.
   */
  it('dos letras se MANDAN y el 422 del backend se enseña tal cual', async () => {
    respondeSegunAccion({
      user_search: {
        status: 'error',
        message: 'Escribe al menos 3 caracteres para buscar a alguien.',
        data: null,
        http_code: 422
      }
    })

    expect(await store.buscar('bu')).toBe(false)

    expect(apiCall).toHaveBeenCalledWith('user_search', { q: 'bu' })
    expect(store.errorBusqueda).toBe('Escribe al menos 3 caracteres para buscar a alguien.')
    expect(store.resultados).toEqual([])
  })

  /**
   * Los resultados viejos se tiran al fallar: dejarlos debajo de un error sobre
   * una consulta NUEVA haría creer que son la respuesta a lo que se acaba de
   * escribir.
   */
  it('un fallo vacía los resultados anteriores', async () => {
    backendConGente()
    await store.buscar('busca')
    expect(store.resultados).toHaveLength(2)

    respondeSegunAccion({
      user_search: { status: 'error', message: 'Too many requests.', http_code: 429 }
    })

    await store.buscar('busc')

    expect(store.resultados).toEqual([])
    expect(store.errorBusqueda).toBe('Too many requests.')
  })

  /** El 401 se traduce aparte, como en el resto del store: es el único accionable. */
  it('un 401 dice que la sesión ha caducado', async () => {
    respondeSegunAccion({
      user_search: { status: 'error', message: 'Authentication required.', http_code: 401 }
    })

    await store.buscar('busca')

    expect(store.errorBusqueda).toBe('Tu sesión ha caducado. Vuelve a entrar.')
  })

  it('sin coincidencias deja la lista vacía y marca que ya se buscó', async () => {
    respondeSegunAccion({ user_search: fixtura(buscarVacioFixture) })

    expect(await store.buscar('buscaoculto')).toBe(true)

    expect(store.resultados).toEqual([])
    expect(store.busquedaHecha).toBe(true)
    expect(store.errorBusqueda).toBeNull()
  })

  it('no lanza dos búsquedas a la vez', async () => {
    backendConGente()

    store.buscando = true
    expect(await store.buscar('busca')).toBe(false)
    expect(apiCall).not.toHaveBeenCalled()
  })

  /**
   * **El buscador no toca ninguna de las cuatro listas, y es la misma regla que
   * separa la amistad del seguimiento.** Un resultado de búsqueda no es una
   * relación: si alguna vez apareciera en `amigos`, ver a alguien en el buscador
   * le estaría concediendo el nivel `friends` de la privacidad de quien busca.
   */
  it('buscar no mueve ni una de las cuatro listas ni ningún contador', async () => {
    backendConGente()
    await store.cargar()

    const antes = {
      amigos: [...store.amigos],
      recibidas: [...store.recibidas],
      enviadas: [...store.enviadas],
      siguiendo: [...store.siguiendo],
      contadores: { ...store.contadores },
      seguidores: store.seguidores
    }

    await store.buscar('busca')

    expect(store.amigos).toEqual(antes.amigos)
    expect(store.recibidas).toEqual(antes.recibidas)
    expect(store.enviadas).toEqual(antes.enviadas)
    expect(store.siguiendo).toEqual(antes.siguiendo)
    expect(store.contadores).toEqual(antes.contadores)
    expect(store.seguidores).toBe(antes.seguidores)
  })

  /** Y al revés: un fallo del buscador no toca los errores de las listas. */
  it('el error del buscador es suyo y no pisa los de las dos listas', async () => {
    respondeSegunAccion({
      user_search: { status: 'error', message: 'Escribe al menos 3 caracteres.', http_code: 422 }
    })

    store.error = 'No se pudieron cargar tus amistades.'
    store.errorSeguidos = 'No se pudo cargar a quién sigues.'

    await store.buscar('bu')

    expect(store.error).toBe('No se pudieron cargar tus amistades.')
    expect(store.errorSeguidos).toBe('No se pudo cargar a quién sigues.')
    expect(store.errorBusqueda).toBe('Escribe al menos 3 caracteres.')
  })

  it('`limpiarBusqueda` vacía los cinco campos y NO toca las listas', async () => {
    backendConGente()
    await store.cargar()
    await store.buscar('busca')

    store.limpiarBusqueda()

    expect(store.consulta).toBe('')
    expect(store.resultados).toEqual([])
    expect(store.busquedaHecha).toBe(false)
    expect(store.errorBusqueda).toBeNull()
    // El contador de la portada vive en este store: vaciarlo aquí lo dejaría a
    // cero al cerrar una búsqueda.
    expect(store.contadores.pending).toBe(1)
    expect(store.amigos).toHaveLength(1)
  })

  it('`limpiar` también devuelve el buscador a cero', async () => {
    backendConGente()
    await store.buscar('busca')

    store.limpiar()

    expect(store.resultados).toEqual([])
    expect(store.consulta).toBe('')
    expect(store.busquedaHecha).toBe(false)
    expect(store.buscando).toBe(false)
  })

  /**
   * **El cruce que decide qué botón pinta cada resultado.** Se hace leyendo, no
   * escribiendo: los resultados se guardan crudos y `relacionCon()` —el del M5—
   * dice en qué relación estás con cada uno. Así, en cuanto `pedir()` mete la
   * fila en `enviadas`, ese resultado cambia solo de botón.
   */
  it('un resultado que ya es amigo se lee como amigo, sin tocar los resultados', async () => {
    respondeSegunAccion({
      friend_list: fixtura(friendListFixture),
      follow_list: fixtura(followListFixture),
      user_search: {
        status: 'success',
        message: 'Resultados de la búsqueda.',
        data: { users: [{ username: 'amigaaceptada', displayName: 'Amiga', avatarUrl: null }] },
        http_code: 200
      }
    })

    await store.cargar()
    await store.buscar('amiga')

    expect(store.relacionCon('amigaaceptada').amistad).toBe('amigos')
    // Y el resultado sigue siendo lo que dijo el backend: el cruce no lo anota.
    expect(store.resultados[0]).toEqual({
      username: 'amigaaceptada', displayName: 'Amiga', avatarUrl: null
    })
  })
})
