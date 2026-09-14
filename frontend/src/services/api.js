import axios from 'axios'

/**
 * Único punto de salida de red de la app.
 *
 * Convención heredada del resto de repos: NO se instancia axios suelto ni se
 * escribe la URL a mano en ninguna vista. Todo pasa por aquí.
 *
 * El backend es un endpoint único por POST con la acción en el body; las
 * excepciones son las rutas GET, que tienen su propio helper más abajo: el
 * catálogo con `catalogGet` y el perfil público / el mazo compartido con
 * `publicGet`. Son dos y no uno porque solo el segundo se identifica (Bearer),
 * y la diferencia está explicada donde vive.
 */

const API_URL = import.meta.env.VITE_API_URL || '/index.php'

/**
 * La base del backend, sin `/index.php`.
 *
 * Se deriva en vez de configurarse aparte para que siga habiendo UNA sola
 * variable de entorno que apunte al backend: en web es `http://127.0.0.1:8899`
 * y en Capacitor `http://10.0.2.2:8899`, y las dos salen de la misma.
 */
export const API_BASE = API_URL.replace(/\/index\.php$/, '')

const http = axios.create({
  baseURL: '',
  // Imprescindible para que viaje la cookie de sesión: el frontend (:8094) y el
  // backend (:8899) son orígenes distintos.
  withCredentials: true,
  timeout: 30000,
  headers: { 'Content-Type': 'application/json' }
})

/** Token JWT en memoria + localStorage (lo usa el cliente móvil, sin cookies). */
let jwtToken = localStorage.getItem('tcgdesk_jwt') || null
/** Token CSRF de la sesión, que emite el backend al iniciar sesión. */
let csrfToken = null

export function setJwtToken(token) {
  jwtToken = token
  if (token) {
    localStorage.setItem('tcgdesk_jwt', token)
  } else {
    localStorage.removeItem('tcgdesk_jwt')
  }
}

export function getJwtToken() {
  return jwtToken
}

export function setCsrfToken(token) {
  csrfToken = token
}

/**
 * Llama a una acción del backend.
 *
 * A diferencia de LibraryVue, que mantiene una lista de 60+ acciones
 * «protegidas» para decidir si adjunta el CSRF, aquí se manda SIEMPRE que lo
 * tengamos: quién lo exige lo decide `CsrfMiddleware` en config/routes.php del
 * backend. Una lista duplicada en el cliente se desincroniza sola.
 *
 * `opciones.timeout` sobreescribe los 30 s de la instancia, y existe por la
 * importación: un ManaBox de 20.000 líneas es UNA petición con megas de cuerpo,
 * y abortarla a los 30 s dejaría al usuario sin previsualización sin que el
 * servidor se hubiera enterado. `opciones.onUploadProgress` es lo que alimenta
 * la barra de progreso mientras el fichero sube.
 *
 * @param {string} action
 * @param {object} data
 * @param {{timeout?: number, onUploadProgress?: Function}} opciones
 * @returns {Promise<object>} el cuerpo de la respuesta del backend
 */
export async function apiCall(action, data = {}, opciones = {}) {
  const payload = { action, ...data }

  if (csrfToken) {
    payload.csrf_token = csrfToken
  }

  const config = { headers: {} }
  if (jwtToken) {
    config.headers.Authorization = `Bearer ${jwtToken}`
  }

  if (opciones.timeout) {
    config.timeout = opciones.timeout
  }

  if (opciones.onUploadProgress) {
    config.onUploadProgress = opciones.onUploadProgress
  }

  try {
    const response = await http.post(API_URL, payload, config)
    return response.data
  } catch (error) {
    // El backend responde con JSON también en los errores (4xx/5xx); se
    // devuelve tal cual para que quien llama lea `status` y `message` sin tener
    // que distinguir entre "falló la red" y "el backend dijo que no".
    if (error.response?.data) {
      return error.response.data
    }

    return {
      status: 'error',
      message: 'No se pudo contactar con el servidor.',
      http_code: 0
    }
  }
}

/**
 * Llama a una ruta GET del catálogo.
 *
 * El catálogo NO pasa por el endpoint único, y es deliberado: es lectura
 * pública, paginable y cacheable, y con un POST único ni el navegador ni un
 * proxy pueden cachear nada. Tampoco lleva credenciales —no hay nada que
 * autorizar— y por eso va sin `withCredentials`: así el navegador no exige que
 * el backend refleje el origen para una petición que no lo necesita.
 *
 * @param {string} path   p. ej. '/cards' o '/cards/<uuid>'
 * @param {object} params query string; las claves con valor vacío se omiten
 * @returns {Promise<object>} el cuerpo de la respuesta
 */
export async function catalogGet(path, params = {}) {
  const limpios = Object.fromEntries(
    Object.entries(params).filter(([, v]) => v !== null && v !== undefined && v !== '')
  )

  try {
    const response = await axios.get(`${API_BASE}/api/catalog${path}`, {
      params: limpios,
      timeout: 30000
    })

    return response.data
  } catch (error) {
    // El catálogo responde JSON también en los errores (404 de ficha, por
    // ejemplo); se devuelve tal cual para que la vista decida qué enseñar.
    if (error.response?.data) {
      return error.response.data
    }

    return { error: 'network_error' }
  }
}

/**
 * Llama a una ruta GET pública (perfil de usuario, mazo compartido).
 *
 * Hermana de `catalogGet` y NO de `apiCall`, con una diferencia que es la razón
 * de que exista en vez de reutilizar aquella: **adjunta el `Authorization:
 * Bearer` cuando hay JWT**.
 *
 * El porqué, que se descubrió midiendo y no razonando: el plan daba por hecho
 * que quien tuviera sesión se identificaría igualmente «por la cookie». No es
 * cierto. `catalogGet` usa `axios.get` directo, sin `withCredentials` (a
 * diferencia de la instancia de `apiCall`, arriba), y en dev el frontend
 * (:8094) y el backend (:8899) son orígenes distintos, así que **el navegador
 * no manda la cookie**. Eso lo decide el cliente, no el servidor. Con
 * `catalogGet` tal cual, el espectador habría sido SIEMPRE `null` desde la web
 * y dos cosas del backend no se habrían activado nunca en el navegador: la
 * regla «tú siempre te ves a ti mismo» de `Visibilidad`, y el nivel `friends`
 * que el plan de Amigos enchufará.
 *
 * Bearer y no cookie porque `login` emite JWT también en web, el backend ya
 * sabe leerlo para resolver al espectador, y **es lo único que funciona en
 * Capacitor, que no tiene cookie**.
 *
 * Sigue SIN mandarse la cookie, y eso es deliberado: la respuesta no depende
 * del estado del navegador, así que un anónimo recibe exactamente lo mismo esté
 * donde esté.
 *
 * @param {string} path   p. ej. '/user/david' o '/deck/<token>'
 * @param {object} params query string; las claves con valor vacío se omiten
 * @returns {Promise<object>} el cuerpo de la respuesta
 */
export async function publicGet(path, params = {}) {
  const limpios = Object.fromEntries(
    Object.entries(params).filter(([, v]) => v !== null && v !== undefined && v !== '')
  )

  const cabeceras = {}
  if (jwtToken) {
    cabeceras.Authorization = `Bearer ${jwtToken}`
  }

  try {
    const response = await axios.get(`${API_BASE}/api/public${path}`, {
      params: limpios,
      headers: cabeceras,
      timeout: 30000
    })

    return response.data
  } catch (error) {
    // Las rutas públicas responden JSON también en los errores, y todos los que
    // importan son esperados, no excepcionales: 403 `not_visible` cuando la
    // sección no se enseña, 404 `user_not_found` / `deck_not_found`, y 429
    // `rate_limited` al pasarse del límite por IP. Se devuelven tal cual para
    // que el store distinga «privado» de «no existe» de «se rompió».
    if (error.response?.data) {
      return error.response.data
    }

    return { error: 'network_error' }
  }
}

export default { apiCall, catalogGet, publicGet, setJwtToken, getJwtToken, setCsrfToken, API_BASE }
