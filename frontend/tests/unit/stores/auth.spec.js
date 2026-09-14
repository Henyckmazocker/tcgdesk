import { beforeEach, describe, expect, it, vi } from 'vitest'
import { createPinia, setActivePinia } from 'pinia'

import { useAuthStore } from '@/stores/auth'
import { apiCall, getJwtToken, setCsrfToken, setJwtToken } from '@/services/api'

import checkAuthFixture from '../../fixtures/check_auth.json'
import logoutFixture from '../../fixtures/logout.json'

/**
 * `stores/auth.js` — el estado de sesión.
 *
 * **`login()` está FUERA de alcance** desde el 2026-09-12 y con él la rama
 * `needs_username` (`auth.js:35-60`): `login` es el único endpoint que no se
 * pudo capturar —exige un ID token firmado por Google, `GoogleAuthClient.php:62-66`—
 * y sin fixtura real solo quedaba inventarse la forma a mano, que es justo lo
 * que esta suite existe para impedir. Aquí no hay ni un test de `login` ni
 * ninguna respuesta de `login` escrita a mano: **el hueco de cobertura es
 * deliberado y se reporta, no se rellena**.
 *
 * Lo que sí se cubre —y es lógica propia del proyecto, no adorno— es
 * `checkAuth()`, `completeRegistration()` (el paso 2 del alta en dos pasos) y
 * `logout()`.
 */

/**
 * La frontera de mock del plan: todo lo que no sea `api.spec.js` dobla
 * `@/services/api`. Se doblan también los tres setters porque el store los
 * llama y son efectos que este test quiere poder mirar.
 */
vi.mock('@/services/api', () => ({
  apiCall: vi.fn(),
  setCsrfToken: vi.fn(),
  setJwtToken: vi.fn(),
  getJwtToken: vi.fn()
}))

/** Copia profunda: los stores mutan lo que reciben y las fixturas son de todos. */
function fixtura(json) {
  return structuredClone(json)
}

let store

beforeEach(() => {
  /**
   * Pinia REAL y no `createTestingPinia`: su `stubActions` por defecto (`true`)
   * no ejecuta las acciones, y este fichero no prueba otra cosa. Con una Pinia
   * de verdad no hay margen de duda.
   */
  setActivePinia(createPinia())
  store = useAuthStore()

  apiCall.mockReset()
  setCsrfToken.mockReset()
  setJwtToken.mockReset()
  getJwtToken.mockReset()
})

describe('getters', () => {
  it('username y displayName salen a null sin usuario', () => {
    expect(store.username).toBeNull()
    expect(store.displayName).toBeNull()
  })

  it('displayName cae al username cuando no hay display_name', () => {
    store.user = { username: 'fixturas', display_name: null }

    expect(store.username).toBe('fixturas')
    expect(store.displayName).toBe('fixturas')
  })

  it('displayName prefiere el display_name cuando lo hay', () => {
    store.user = fixtura(checkAuthFixture).data.user

    expect(store.displayName).toBe('Usuaria de Fixturas')
  })
})

describe('checkAuth()', () => {
  it('rehidrata la sesión y entrega el csrf_token a la capa de red', async () => {
    apiCall.mockResolvedValue(fixtura(checkAuthFixture))

    const ok = await store.checkAuth()

    expect(ok).toBe(true)
    expect(apiCall).toHaveBeenCalledWith('check_auth')
    expect(store.isAuthenticated).toBe(true)
    expect(store.user.username).toBe('fixturas')
    expect(store.isLoading).toBe(false)

    // El token CSRF no se guarda en el store: se le entrega a `api.js`, que es
    // quien lo mete en el body de cada petición.
    expect(setCsrfToken).toHaveBeenCalledWith(fixtura(checkAuthFixture).data.csrf_token)
  })

  it('sin csrf_token en la respuesta no llama al setter', async () => {
    const respuesta = fixtura(checkAuthFixture)
    delete respuesta.data.csrf_token

    apiCall.mockResolvedValue(respuesta)

    await store.checkAuth()

    expect(store.isAuthenticated).toBe(true)
    expect(setCsrfToken).not.toHaveBeenCalled()
  })

  it('con un 401 limpia la sesión entera', async () => {
    store.user = { id: 1 }
    store.isAuthenticated = true

    apiCall.mockResolvedValue({
      status: 'error',
      message: 'No autenticado.',
      data: null,
      http_code: 401
    })

    const ok = await store.checkAuth()

    expect(ok).toBe(false)
    expect(store.isAuthenticated).toBe(false)
    expect(store.user).toBeNull()
    expect(setJwtToken).toHaveBeenCalledWith(null)
    expect(setCsrfToken).toHaveBeenCalledWith(null)
  })

  it('un success sin usuario también limpia (no se da por bueno el sobre a secas)', async () => {
    apiCall.mockResolvedValue({ status: 'success', data: {}, http_code: 200 })

    await expect(store.checkAuth()).resolves.toBe(false)
    expect(store.isAuthenticated).toBe(false)
  })

  it('baja isLoading aunque la llamada reviente', async () => {
    apiCall.mockRejectedValue(new Error('boom'))

    await expect(store.checkAuth()).rejects.toThrow('boom')

    // El `finally` de `auth.js:113-115`: dejar el spinner girando para siempre
    // es peor que el error.
    expect(store.isLoading).toBe(false)
  })
})

describe('completeRegistration() — el paso 2 del alta en dos pasos', () => {
  it('sin idToken pendiente no llama al backend y avisa de que caducó', async () => {
    const ok = await store.completeRegistration('fixturas')

    expect(ok).toBe(false)
    expect(apiCall).not.toHaveBeenCalled()
    expect(store.error).toMatch(/caducado/i)
  })

  it('reenvía el MISMO idToken pendiente junto al username elegido', async () => {
    store.pendingIdToken = 'id-token-pendiente'

    apiCall.mockResolvedValue({
      status: 'success',
      message: 'Sesión iniciada.',
      data: {
        user: fixtura(checkAuthFixture).data.user,
        token: 'jwt-nuevo',
        csrf_token: 'csrf-nuevo'
      },
      http_code: 200
    })

    const ok = await store.completeRegistration('fixturas')

    expect(ok).toBe(true)
    expect(apiCall).toHaveBeenCalledWith('login', {
      id_token: 'id-token-pendiente',
      username: 'fixturas'
    })

    // `applySession`: el alta a medias se deshace y los dos tokens bajan a la
    // capa de red.
    expect(store.isAuthenticated).toBe(true)
    expect(store.needsUsername).toBe(false)
    expect(store.pendingIdToken).toBeNull()
    expect(store.googleProfile).toBeNull()
    expect(setJwtToken).toHaveBeenCalledWith('jwt-nuevo')
    expect(setCsrfToken).toHaveBeenCalledWith('csrf-nuevo')
    expect(store.isLoading).toBe(false)
  })

  it('sin token ni csrf en la respuesta no toca la capa de red, pero da la sesión por buena', async () => {
    store.pendingIdToken = 'id-token-pendiente'

    apiCall.mockResolvedValue({
      status: 'success',
      data: { user: { id: 1, username: 'fixturas' } },
      http_code: 200
    })

    await expect(store.completeRegistration('fixturas')).resolves.toBe(true)

    expect(store.isAuthenticated).toBe(true)
    expect(setJwtToken).not.toHaveBeenCalled()
    expect(setCsrfToken).not.toHaveBeenCalled()
  })

  it('con un username cogido devuelve false y guarda el mensaje del backend', async () => {
    store.pendingIdToken = 'id-token-pendiente'

    apiCall.mockResolvedValue({
      status: 'error',
      message: 'Ese nombre de usuario ya está en uso.',
      data: null,
      http_code: 409
    })

    const ok = await store.completeRegistration('fixturas')

    expect(ok).toBe(false)
    expect(store.error).toBe('Ese nombre de usuario ya está en uso.')
    expect(store.isAuthenticated).toBe(false)
    // El idToken NO se tira: el usuario tiene que poder reintentar con otro
    // nombre sin volver a pasar por Google.
    expect(store.pendingIdToken).toBe('id-token-pendiente')
    expect(store.isLoading).toBe(false)
  })

  it('sin mensaje del backend pone uno propio', async () => {
    store.pendingIdToken = 'id-token-pendiente'
    apiCall.mockResolvedValue({ status: 'error', http_code: 500 })

    await store.completeRegistration('fixturas')

    expect(store.error).toMatch(/registro/i)
  })
})

describe('logout()', () => {
  it('avisa al backend y deja la sesión limpia', async () => {
    store.user = { id: 1 }
    store.isAuthenticated = true

    apiCall.mockResolvedValue(fixtura(logoutFixture))

    await store.logout()

    expect(apiCall).toHaveBeenCalledWith('logout')
    expect(store.user).toBeNull()
    expect(store.isAuthenticated).toBe(false)
    expect(setJwtToken).toHaveBeenCalledWith(null)
    expect(setCsrfToken).toHaveBeenCalledWith(null)
  })

  it('limpia la sesión AUNQUE el backend no conteste', async () => {
    store.isAuthenticated = true
    apiCall.mockRejectedValue(new Error('Network Error'))

    // Dejar al usuario «dentro» en el cliente porque no hubo red es peor que
    // cerrarle la sesión (`auth.js:121-125`).
    await expect(store.logout()).rejects.toThrow('Network Error')

    expect(store.isAuthenticated).toBe(false)
    expect(store.user).toBeNull()
  })
})

describe('hasStoredToken()', () => {
  it('dice que sí cuando `api.js` tiene un JWT guardado', () => {
    getJwtToken.mockReturnValue('jwt-de-pega')

    expect(store.hasStoredToken()).toBe(true)
  })

  it('dice que no cuando no lo hay', () => {
    getJwtToken.mockReturnValue(null)

    expect(store.hasStoredToken()).toBe(false)
  })
})
