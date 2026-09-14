import { fileURLToPath, URL } from 'node:url'
import { defineConfig } from 'vite'
import vue from '@vitejs/plugin-vue'

// `mode` lo pasa Vite (`--mode mobile` en el script build:mobile): sustituye a
// la antigua variable VUE_APP_MODE, que ya no existe.
export default defineConfig(({ mode }) => ({
  plugins: [vue()],
  resolve: {
    alias: {
      '@': fileURLToPath(new URL('./src', import.meta.url))
    }
  },
  // './' en móvil: Capacitor sirve desde capacitor:// y una ruta absoluta
  // deja la app en blanco sin ningún error en la consola del WebView.
  base: mode === 'mobile' ? './' : '/',
  // 'dist' no es opcional: es lo que capacitor.config.json declara como webDir.
  build: { outDir: 'dist' },
  server: {
    host: true,          // = 0.0.0.0, para que salga del contenedor
    port: 8080,          // el que expone el Dockerfile
    strictPort: true,    // que reviente en vez de saltar al 8081 en silencio
    hmr: { clientPort: 8094 }  // el puerto que ve el NAVEGADOR, no el servidor
  }
}))
