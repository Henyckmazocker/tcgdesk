import { fileURLToPath } from 'node:url'
import { defineConfig } from 'vitest/config'
import vue from '@vitejs/plugin-vue'

/**
 * Configuración de la suite de tests del frontend.
 *
 * Corre en el HOST con nvm (Node 22), no en el contenedor: el servicio
 * `frontend` de `docker-compose.yml` monta por volumen solo `src/`, `public/`,
 * `jsconfig.json`, `vite.config.js` e `index.html`, así que `tests/` ni siquiera
 * está dentro.
 *
 * Este fichero es independiente de `vite.config.js` —el build y los tests se
 * configuran por separado—, así que el alias '@' se replica aquí a mano y debe
 * coincidir con el de `jsconfig.json` / `vite.config.js`. Si los dos se llegaran
 * a fusionar, este comentario sobra.
 */
export default defineConfig({
  plugins: [vue()],
  resolve: {
    alias: {
      '@': fileURLToPath(new URL('./src', import.meta.url))
    }
  },
  test: {
    environment: 'jsdom',
    include: ['tests/**/*.spec.js'],
    setupFiles: ['tests/setup.js'],

    // `css: false`: ningún test asserta sobre estilos y así no hay que compilar
    // los bloques <style> de los SFC.
    css: false,

    // Limpia el registro de llamadas de los espías entre tests (no borra sus
    // implementaciones). Sin esto, un `expect(apiCall).toHaveBeenCalledTimes(1)`
    // cuenta también las llamadas del test anterior del mismo fichero.
    clearMocks: true,

    coverage: {
      // `v8` y no `istanbul`: no necesita instrumentar el código en el build.
      // Se eligió cuando el bundler de producción era Vue CLI; desde que es Vite
      // (2026-09-14) la razón ya no aplica, pero `v8` sigue siendo el más rápido.
      provider: 'v8',
      reporter: ['text', 'html'],
      reportsDirectory: 'coverage',

      // Con `include` explícito, los ficheros que ningún test toca cuentan como
      // 0 % en vez de desaparecer del informe — que es justo lo que el umbral
      // tiene que ver.
      include: ['src/**/*.{js,vue}'],
      exclude: [
        // Fuera del alcance del plan de tests, y con el porqué escrito aquí
        // para que no haya que buscarlo:
        //
        // `useGoogleAuth.js` habla con dos SDK de terceros —Google Identity
        // Services en web y @codetrix-studio/capacitor-google-auth en Android—.
        // Un test contra un doble de un SDK prueba el doble, no el código.
        'src/composables/useGoogleAuth.js',
        // `LoginView.vue` es la pantalla de ese composable y no tiene lógica
        // propia: el alta en dos pasos vive en `stores/auth.js`, que SÍ se cubre.
        'src/views/LoginView.vue'
      ],

      /**
       * Umbrales asimétricos a propósito (los verifica M6, no M1):
       * los stores son lógica pura y el 80 % es barato y honesto; las vistas son
       * ~6.000 líneas con mucho markup, y exigirles 80 % empuja a escribir tests
       * de adorno para subir el número.
       */
      thresholds: {
        'src/stores/**': { lines: 80, functions: 80 },
        'src/views/**': { lines: 60, functions: 60 },
        'src/services/**': { lines: 80, functions: 80 }
      }
    }
  }
})
