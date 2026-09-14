import { beforeEach, describe, expect, it, vi } from 'vitest'

/**
 * `services/api.js` — la única capa que habla con la red.
 *
 * **Este es el ÚNICO fichero de la suite que mockea `axios`.** Todos los demás
 * —stores, vistas, componentes— doblan `@/services/api`, que es la frontera que
 * el `CLAUDE.md` del repo ya declara («toda la I/O de red pasa por
 * `src/services/api.js`»). Aquí se baja un nivel porque lo que se prueba es
 * justo esa capa: si también se doblara `apiCall`, no quedaría nada que probar.
 *
 * Lo que `api.js` decide de verdad, y que es lo que se cubre abajo:
 *   · el `csrf_token` viaja en el body siempre que exista  (api.js:78-80)
 *   · el JWT va en la cabecera `Authorization`              (api.js:82-85)
 *   · un 4xx del backend se DEVUELVE como cuerpo, no lanza  (api.js:102-104)
 *   · sin respuesta → `{ status:'error', http_code: 0 }`    (api.js:106-110)
 *   · `catalogGet` omite los params vacíos                  (api.js:128-130)
 *   · `publicGet` va sin cookie PERO con `Bearer` si lo hay
 */

/**
 * `vi.hoisted` porque `vi.mock` se iza por encima de los `const` del fichero:
 * sin esto, la factoría se ejecutaría antes de que existieran los espías.
 *
 * `api.js` usa dos caras distintas de axios —`axios.create(...)` para el
 * endpoint único (con `withCredentials`) y `axios.get` suelto para el catálogo
 * (a propósito SIN credenciales, `api.js:117-121`)—, así que el doble tiene que
 * ofrecer las dos.
 */
const { httpPost, axiosGet, axiosCreate } = vi.hoisted(() => ({
  httpPost: vi.fn(),
  axiosGet: vi.fn(),
  axiosCreate: vi.fn()
}))

vi.mock('axios', () => {
  axiosCreate.mockReturnValue({ post: httpPost })

  return { default: { create: axiosCreate, get: axiosGet } }
})

import {
  API_BASE,
  apiCall,
  catalogGet,
  getJwtToken,
  publicGet,
  setCsrfToken,
  setJwtToken
} from '@/services/api'

/** Una respuesta de axios con el sobre del backend dentro. */
function respuestaAxios(cuerpo) {
  return { data: cuerpo, status: cuerpo.http_code ?? 200 }
}

/** El error que lanza axios cuando el backend respondió, pero con 4xx/5xx. */
function errorConRespuesta(cuerpo) {
  const error = new Error('Request failed')
  error.response = { data: cuerpo, status: cuerpo.http_code ?? 400 }

  return error
}

beforeEach(() => {
  /**
   * `jwtToken` y `csrfToken` son variables de MÓDULO (`api.js:35,37`) y el
   * módulo se carga una sola vez por fichero: sin esto, el token que pone un
   * test se lo encuentra el siguiente. `tests/setup.js` limpia el
   * `localStorage`, pero eso no toca la copia en memoria.
   */
  setJwtToken(null)
  setCsrfToken(null)
  httpPost.mockReset()
  axiosGet.mockReset()
})

describe('apiCall', () => {
  it('manda la acción y el payload al endpoint único', async () => {
    httpPost.mockResolvedValue(respuestaAxios({ status: 'success', data: { ok: true } }))

    const cuerpo = await apiCall('collection_list', { limit: 60 })

    expect(httpPost).toHaveBeenCalledTimes(1)

    const [url, payload] = httpPost.mock.calls[0]

    expect(url).toBe('/index.php')
    expect(payload).toEqual({ action: 'collection_list', limit: 60 })
    expect(cuerpo).toEqual({ status: 'success', data: { ok: true } })
  })

  it('NO mete csrf_token mientras no haya token', async () => {
    httpPost.mockResolvedValue(respuestaAxios({ status: 'success' }))

    await apiCall('check_auth')

    expect(httpPost.mock.calls[0][1]).not.toHaveProperty('csrf_token')
  })

  it('mete el csrf_token en el BODY siempre que exista, sin mirar la acción', async () => {
    httpPost.mockResolvedValue(respuestaAxios({ status: 'success' }))

    setCsrfToken('un-token-csrf')

    // Dos acciones: una que el backend protege (`logout`) y otra que no
    // (`check_auth`). Las dos lo llevan: quién lo exige lo decide
    // `CsrfMiddleware` en el backend, y replicar la lista aquí la
    // desincronizaría sola (`api.js:58-62`).
    await apiCall('logout')
    await apiCall('check_auth')

    expect(httpPost.mock.calls[0][1].csrf_token).toBe('un-token-csrf')
    expect(httpPost.mock.calls[1][1].csrf_token).toBe('un-token-csrf')
  })

  it('manda el JWT en la cabecera Authorization, no en el body', async () => {
    httpPost.mockResolvedValue(respuestaAxios({ status: 'success' }))

    setJwtToken('jwt-de-pega')

    await apiCall('check_auth')

    const [, payload, config] = httpPost.mock.calls[0]

    expect(config.headers.Authorization).toBe('Bearer jwt-de-pega')
    expect(payload).not.toHaveProperty('token')
  })

  it('sin JWT no manda cabecera Authorization', async () => {
    httpPost.mockResolvedValue(respuestaAxios({ status: 'success' }))

    await apiCall('check_auth')

    expect(httpPost.mock.calls[0][2].headers).toEqual({})
  })

  it('pasa timeout y onUploadProgress a la petición (los pide la importación)', async () => {
    httpPost.mockResolvedValue(respuestaAxios({ status: 'success' }))

    const onUploadProgress = vi.fn()

    await apiCall('import_preview', { contenido: 'x' }, { timeout: 300000, onUploadProgress })

    const config = httpPost.mock.calls[0][2]

    expect(config.timeout).toBe(300000)
    expect(config.onUploadProgress).toBe(onUploadProgress)
  })

  it('no pone timeout ni progreso cuando no se piden', async () => {
    httpPost.mockResolvedValue(respuestaAxios({ status: 'success' }))

    await apiCall('ping')

    expect(httpPost.mock.calls[0][2]).not.toHaveProperty('timeout')
    expect(httpPost.mock.calls[0][2]).not.toHaveProperty('onUploadProgress')
  })

  it('DEVUELVE el cuerpo de un 4xx en vez de lanzar', async () => {
    const cuerpo401 = {
      status: 'error',
      message: 'No autenticado.',
      data: null,
      http_code: 401
    }

    httpPost.mockRejectedValue(errorConRespuesta(cuerpo401))

    // Sin `rejects`: la gracia es justamente que NO lanza, para que quien llama
    // lea `status` y `message` sin distinguir «falló la red» de «el backend
    // dijo que no».
    await expect(apiCall('collection_add')).resolves.toEqual(cuerpo401)
  })

  it('sin respuesta del servidor devuelve http_code 0 y no lanza', async () => {
    httpPost.mockRejectedValue(new Error('Network Error'))

    const cuerpo = await apiCall('check_auth')

    expect(cuerpo.status).toBe('error')
    expect(cuerpo.http_code).toBe(0)
    expect(cuerpo.message).toMatch(/servidor/i)
  })
})

describe('el token JWT', () => {
  it('se guarda en localStorage y se puede leer de vuelta', () => {
    setJwtToken('jwt-de-pega')

    expect(getJwtToken()).toBe('jwt-de-pega')
    expect(localStorage.getItem('tcgdesk_jwt')).toBe('jwt-de-pega')
  })

  it('con null se borra del localStorage', () => {
    setJwtToken('jwt-de-pega')
    setJwtToken(null)

    expect(getJwtToken()).toBeNull()
    expect(localStorage.getItem('tcgdesk_jwt')).toBeNull()
  })

  it('se rehidrata del localStorage al cargar el módulo (el cliente móvil no tiene cookie)', async () => {
    localStorage.setItem('tcgdesk_jwt', 'jwt-que-estaba-guardado')

    // `api.js:35` lee el localStorage UNA vez, al importarse: para probarlo hay
    // que volver a cargar el módulo, no basta con escribir en el storage.
    vi.resetModules()

    const recargado = await import('@/services/api')

    expect(recargado.getJwtToken()).toBe('jwt-que-estaba-guardado')
  })
})

describe('catalogGet', () => {
  it('omite los params vacíos y conserva los que valen 0 o false', async () => {
    axiosGet.mockResolvedValue({ data: { items: [], nextCursor: null } })

    await catalogGet('/cards', {
      q: 'bosque',
      set: '',
      rarity: null,
      colors: undefined,
      price_min: 0,
      playable: false,
      limit: 60
    })

    const [url, config] = axiosGet.mock.calls[0]

    expect(url).toBe(`${API_BASE}/api/catalog/cards`)
    expect(config.params).toEqual({ q: 'bosque', price_min: 0, playable: false, limit: 60 })
  })

  it('devuelve el cuerpo tal cual, sin desenvolver nada', async () => {
    // El catálogo NO lleva el sobre `{status, message, data}`: responde el
    // recurso directamente (divergencia 3 del `CLAUDE.md`).
    axiosGet.mockResolvedValue({ data: { items: [{ uuid: 'x' }], nextCursor: 'eyJvIjo2MH0' } })

    const cuerpo = await catalogGet('/cards')

    expect(cuerpo).toEqual({ items: [{ uuid: 'x' }], nextCursor: 'eyJvIjo2MH0' })
  })

  it('DEVUELVE el cuerpo de un 404 (la ficha que no existe) en vez de lanzar', async () => {
    axiosGet.mockRejectedValue(errorConRespuesta({ error: 'printing_not_found', http_code: 404 }))

    await expect(catalogGet('/cards/no-existe')).resolves.toMatchObject({
      error: 'printing_not_found'
    })
  })

  it('sin respuesta devuelve { error: network_error }', async () => {
    axiosGet.mockRejectedValue(new Error('Network Error'))

    await expect(catalogGet('/sets')).resolves.toEqual({ error: 'network_error' })
  })

  it('va SIN credenciales, a diferencia del endpoint único', async () => {
    axiosGet.mockResolvedValue({ data: [] })

    await catalogGet('/sets')

    // No es un detalle cosmético: con `withCredentials` el navegador exigiría
    // que el backend reflejase el origen para una lectura pública que no lo
    // necesita (`api.js:117-121`).
    expect(axiosGet.mock.calls[0][1]).not.toHaveProperty('withCredentials')
  })
})

/**
 * `publicGet` — las seis rutas del perfil público y del mazo compartido.
 *
 * Es hermana de `catalogGet` y **no** de `apiCall`, con una sola diferencia, que
 * es toda la razón de que exista en vez de reutilizar aquella: manda el
 * `Authorization: Bearer` cuando hay JWT.
 *
 * El porqué se comprobó midiendo, no razonando. El plan daba por hecho que quien
 * tuviera sesión se identificaría «por la cookie»: no viaja. `catalogGet` usa
 * `axios.get` directo, sin `withCredentials`, y en dev el frontend (:8094) y el
 * backend (:8899) son orígenes distintos, así que el navegador no la adjunta. Con
 * `catalogGet` tal cual, el espectador habría sido SIEMPRE `null` desde la web y
 * la regla «tú siempre te ves a ti mismo» de `Visibilidad` no se habría activado
 * nunca en el navegador.
 */
describe('publicGet', () => {
  it('compone la ruta bajo /api/public y omite los params vacíos', async () => {
    axiosGet.mockResolvedValue({ data: { items: [], nextCursor: null } })

    await publicGet('/user/fixturas/collection', { cursor: 'eyJvIjo2MH0', q: '', set: null })

    const [url, config] = axiosGet.mock.calls[0]

    expect(url).toBe(`${API_BASE}/api/public/user/fixturas/collection`)
    expect(config.params).toEqual({ cursor: 'eyJvIjo2MH0' })
  })

  it('SIN JWT no manda cabecera de autorización: el espectador es un anónimo', async () => {
    axiosGet.mockResolvedValue({ data: { user: {}, visible: {} } })

    await publicGet('/user/fixturas')

    expect(axiosGet.mock.calls[0][1].headers).toEqual({})
  })

  it('CON JWT manda el Bearer, que es lo que identifica al espectador', async () => {
    setJwtToken('jwt-de-quien-mira')
    axiosGet.mockResolvedValue({ data: { user: {}, visible: {} } })

    await publicGet('/user/fixturas')

    expect(axiosGet.mock.calls[0][1].headers).toEqual({
      Authorization: 'Bearer jwt-de-quien-mira'
    })
  })

  it('NUNCA manda la cookie, ni con sesión ni sin ella', async () => {
    setJwtToken('jwt-de-quien-mira')
    axiosGet.mockResolvedValue({ data: {} })

    await publicGet('/user/fixturas')

    // Es deliberado y no un olvido: así la respuesta no depende del estado del
    // navegador y un anónimo recibe exactamente lo mismo esté donde esté. Lo
    // que identifica es el Bearer de arriba, que además funciona en Capacitor,
    // que no tiene cookie.
    expect(axiosGet.mock.calls[0][1]).not.toHaveProperty('withCredentials')
  })

  it('DEVUELVE el cuerpo de un 403 `not_visible` en vez de lanzar', async () => {
    // No es una excepción: es la respuesta normal de una sección que su dueño no
    // enseña, y el store la convierte en «esta sección es privada».
    axiosGet.mockRejectedValue(errorConRespuesta({ error: 'not_visible', http_code: 403 }))

    await expect(publicGet('/user/fixturas/wishlist')).resolves.toEqual({
      error: 'not_visible',
      http_code: 403
    })
  })

  it('DEVUELVE el cuerpo de un 404 de token, que es el único fallo del mazo compartido', async () => {
    axiosGet.mockRejectedValue(errorConRespuesta({ error: 'deck_not_found', http_code: 404 }))

    await expect(publicGet('/deck/deadbeef')).resolves.toMatchObject({ error: 'deck_not_found' })
  })

  it('DEVUELVE el cuerpo de un 429: las rutas públicas van limitadas a 60/min por IP', async () => {
    axiosGet.mockRejectedValue(errorConRespuesta({ error: 'rate_limited', http_code: 429 }))

    await expect(publicGet('/user/fixturas')).resolves.toMatchObject({ error: 'rate_limited' })
  })

  it('sin respuesta devuelve { error: network_error }', async () => {
    axiosGet.mockRejectedValue(new Error('Network Error'))

    await expect(publicGet('/user/fixturas')).resolves.toEqual({ error: 'network_error' })
  })
})

describe('API_BASE', () => {
  it('se deriva de la URL del endpoint quitándole /index.php', () => {
    // Una sola variable de entorno apunta al backend, y de ella salen las dos
    // caras (POST único y GET de catálogo): la del endpoint lleva `/index.php`
    // y la del catálogo no, así que `API_BASE` nunca puede acabar en él.
    expect(API_BASE).not.toMatch(/\/index\.php$/)
    expect(typeof API_BASE).toBe('string')
  })
})
