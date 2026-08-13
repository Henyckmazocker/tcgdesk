import 'primeicons/primeicons.css'

import { createApp } from 'vue'
import { createPinia } from 'pinia'
import PrimeVue from 'primevue/config'
import Aura from '@primevue/themes/aura'

import App from './App.vue'
import router from './router'
import { useAuthStore } from '@/stores/auth'

const app = createApp(App)

// Pinia antes que el router: el guard de navegación usa el store de auth.
const pinia = createPinia()
app.use(pinia)

app.use(PrimeVue, {
  ripple: true,
  theme: {
    preset: Aura,
    options: {
      prefix: 'p',
      cssLayer: false,
      darkModeSelector: '.app-dark'
    }
  }
})

/**
 * La sesión se rehidrata ANTES de instalar el router, y esto no es un detalle de
 * estilo: el guard de `router/index.js` lee `isAuthenticated` en la navegación
 * inicial, así que si `checkAuth` todavía no ha terminado manda a /login a
 * alguien que tiene cookie o JWT válidos. Hacerlo en `onMounted` de App.vue
 * —donde estaba— llegaba tarde: recargar cualquier ruta protegida rebotaba al
 * acceso y se perdían los filtros del catálogo que llevaba la URL.
 */
async function arrancar() {
  await useAuthStore(pinia).checkAuth()

  app.use(router)
  app.mount('#app')
}

arrancar()
