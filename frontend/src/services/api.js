import axios from 'axios'

/**
 * Único punto de salida de red de la app.
 *
 * Convención heredada del resto de repos: NO se instancia axios suelto ni se
 * escribe la URL a mano en ninguna vista. Todo pasa por aquí.
 *
 * El backend es un endpoint único por POST con la acción en el body; la única
 * excepción son las rutas GET del catálogo, que tienen su propio helper
 * (`catalogGet`) más abajo.
 */

const API_URL = process.env.VUE_APP_API_URL || '/index.php'

/**
 * La base del backend, sin `/index.php`.
 *
 * Se deriva en vez de configurarse aparte para que siga habiendo UNA sola
 * variable de entorno que apunte al backend: en web es `http://127.0.0.1:8899`
 * y en Capacitor `http://10.0.2.2:8899`, y las dos salen de la misma.
 */
const API_BASE = API_URL.replace(/\/index\.php$/, '')

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
 * @param {string} action
 * @param {object} data
 * @returns {Promise<object>} el cuerpo de la respuesta del backend
 */
export async function apiCall(action, data = {}) {
  const payload = { action, ...data }

  if (csrfToken) {
    payload.csrf_token = csrfToken
  }

  const config = { headers: {} }
  if (jwtToken) {
    config.headers.Authorization = `Bearer ${jwtToken}`
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

export default { apiCall, catalogGet, setJwtToken, getJwtToken, setCsrfToken }
