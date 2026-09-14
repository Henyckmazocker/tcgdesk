import { ref } from 'vue'
import { Capacitor } from '@capacitor/core'
import { GoogleAuth } from '@codetrix-studio/capacitor-google-auth'

/**
 * Obtiene un ID token de Google, por dos caminos según la plataforma:
 *
 *  - **Web**: Google Identity Services (`accounts.google.com/gsi/client`). El
 *    token llega al callback de JS, sin redirección. Por eso el cliente OAuth
 *    solo necesita «orígenes autorizados» y ningún «URI de redirección».
 *  - **Android (Capacitor)**: el SDK nativo. Recibe el mismo Client ID **web**
 *    como `serverClientId`, que es lo que hace que el `aud` del token coincida
 *    con el que valida GoogleAuthClient.php en el backend.
 *
 * En ambos casos lo que sale de aquí es un ID token que el backend verifica
 * contra el JWKS de Google. El frontend nunca decide si alguien está autenticado.
 */

const GOOGLE_CLIENT_ID = import.meta.env.VITE_GOOGLE_CLIENT_ID

const isSdkLoaded = ref(false)
const isInitialized = ref(false)

function isNative() {
  return Capacitor.isNativePlatform()
}

function loadGoogleSdk() {
  return new Promise((resolve, reject) => {
    if (window.google?.accounts?.id) {
      isSdkLoaded.value = true
      return resolve()
    }

    const existing = document.querySelector('script[src*="accounts.google.com/gsi/client"]')
    if (existing) {
      existing.addEventListener('load', () => {
        isSdkLoaded.value = true
        resolve()
      })
      return
    }

    const script = document.createElement('script')
    script.src = 'https://accounts.google.com/gsi/client'
    script.async = true
    script.defer = true
    script.onload = () => {
      isSdkLoaded.value = true
      resolve()
    }
    script.onerror = () => reject(new Error('No se pudo cargar el SDK de Google.'))
    document.head.appendChild(script)
  })
}

export function useGoogleAuth() {
  const error = ref(null)

  /**
   * @param {(idToken: string) => void} onCredential
   */
  async function initialize(onCredential) {
    if (!GOOGLE_CLIENT_ID) {
      error.value = 'Falta VITE_GOOGLE_CLIENT_ID.'
      return false
    }

    try {
      if (isNative()) {
        await GoogleAuth.initialize({
          clientId: GOOGLE_CLIENT_ID,
          scopes: ['profile', 'email'],
          grantOfflineAccess: false
        })
        isInitialized.value = true
        return true
      }

      await loadGoogleSdk()

      window.google.accounts.id.initialize({
        client_id: GOOGLE_CLIENT_ID,
        callback: (response) => onCredential(response.credential),
        auto_select: false,
        cancel_on_tap_outside: true
      })

      isInitialized.value = true
      return true
    } catch (err) {
      error.value = err.message
      return false
    }
  }

  /**
   * Pinta el botón oficial de Google dentro de un elemento.
   * Solo web: en nativo se usa `signInNative()`.
   */
  function renderButton(element) {
    if (isNative() || !window.google?.accounts?.id || !element) {
      return
    }

    window.google.accounts.id.renderButton(element, {
      theme: 'filled_blue',
      size: 'large',
      text: 'signin_with',
      shape: 'pill',
      locale: 'es'
    })
  }

  /** Android: abre el selector de cuenta nativo y devuelve el ID token. */
  async function signInNative() {
    const user = await GoogleAuth.signIn()
    return user?.authentication?.idToken ?? null
  }

  return {
    isSdkLoaded,
    isInitialized,
    error,
    isNative,
    initialize,
    renderButton,
    signInNative
  }
}
