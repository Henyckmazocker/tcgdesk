import { defineStore } from 'pinia'
import { apiCall, setCsrfToken, setJwtToken, getJwtToken } from '@/services/api'

/**
 * Estado de sesión.
 *
 * El alta es de dos pasos, porque el backend no autogenera el `username`:
 *   1. `login(idToken)` → si es la primera vez, devuelve `needsUsername = true`
 *      y el perfil de Google, pero NO crea nada.
 *   2. `completeRegistration(username)` → reenvía el mismo idToken con el
 *      username elegido y ahí se da de alta.
 */
export const useAuthStore = defineStore('auth', {
  state: () => ({
    user: null,
    isAuthenticated: false,
    isLoading: false,
    error: null,

    // Estado del alta a medias
    needsUsername: false,
    googleProfile: null,
    pendingIdToken: null
  }),

  getters: {
    username: (state) => state.user?.username ?? null,
    displayName: (state) => state.user?.display_name ?? state.user?.username ?? null
  },

  actions: {
    /**
     * Paso 1: entrega a Google el ID token al backend.
     */
    async login(idToken) {
      this.isLoading = true
      this.error = null

      try {
        const response = await apiCall('login', { id_token: idToken })

        if (response.status !== 'success') {
          this.error = response.message || 'No se pudo iniciar sesión.'
          return false
        }

        // Primer acceso: falta elegir nombre de usuario.
        if (response.data?.needs_username) {
          this.needsUsername = true
          this.googleProfile = response.data.google_profile
          this.pendingIdToken = idToken
          return false
        }

        this.applySession(response.data)
        return true
      } finally {
        this.isLoading = false
      }
    },

    /**
     * Paso 2: reenvía el mismo ID token con el username elegido.
     */
    async completeRegistration(username) {
      if (!this.pendingIdToken) {
        this.error = 'La sesión de registro ha caducado. Vuelve a entrar con Google.'
        return false
      }

      this.isLoading = true
      this.error = null

      try {
        const response = await apiCall('login', {
          id_token: this.pendingIdToken,
          username
        })

        if (response.status !== 'success') {
          this.error = response.message || 'No se pudo completar el registro.'
          return false
        }

        this.applySession(response.data)
        return true
      } finally {
        this.isLoading = false
      }
    },

    /**
     * Rehidrata la sesión al arrancar la app: la cookie o el JWT guardado
     * pueden seguir siendo válidos.
     */
    async checkAuth() {
      this.isLoading = true

      try {
        const response = await apiCall('check_auth')

        if (response.status === 'success' && response.data?.user) {
          this.user = response.data.user
          this.isAuthenticated = true
          if (response.data.csrf_token) {
            setCsrfToken(response.data.csrf_token)
          }
          return true
        }

        this.clearSession()
        return false
      } finally {
        this.isLoading = false
      }
    },

    async logout() {
      try {
        await apiCall('logout')
      } finally {
        // Se limpia el estado local pase lo que pase: si el backend no responde,
        // dejar al usuario "dentro" en el cliente es peor que cerrarle la sesión.
        this.clearSession()
      }
    },

    applySession(data) {
      this.user = data.user
      this.isAuthenticated = true
      this.needsUsername = false
      this.googleProfile = null
      this.pendingIdToken = null

      if (data.token) {
        setJwtToken(data.token)
      }
      if (data.csrf_token) {
        setCsrfToken(data.csrf_token)
      }
    },

    clearSession() {
      this.user = null
      this.isAuthenticated = false
      this.needsUsername = false
      this.googleProfile = null
      this.pendingIdToken = null
      setJwtToken(null)
      setCsrfToken(null)
    },

    hasStoredToken() {
      return Boolean(getJwtToken())
    }
  }
})
