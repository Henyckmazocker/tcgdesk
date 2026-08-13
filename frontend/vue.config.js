const { defineConfig } = require('@vue/cli-service')

// En build móvil (VUE_APP_MODE=mobile) los assets se cargan desde el protocolo
// capacitor:// — se necesita ruta relativa. En web se mantiene '/'.
const isMobile = process.env.VUE_APP_MODE === 'mobile'

module.exports = defineConfig({
  outputDir: 'dist',
  publicPath: isMobile ? './' : '/',
  devServer: {
    host: '0.0.0.0',
    // Dentro del contenedor sirve en 8080; docker-compose lo publica en 8094.
    // (El 8099 lo tenía bingoSorpresa desde antes: su `npm run serve` no sale en
    //  `ss -ltn` si no está levantado, y por eso no se detectó al reservar puertos.)
    port: 8080,
    client: {
      webSocketURL: 'auto://0.0.0.0:0/ws'
    }
    // Sin proxy, a propósito: el backend ya responde con CORS acotado a
    // localhost/127.0.0.1/capacitor:// (backend/public/.htaccess). Un proxy aquí
    // no serviría de nada al cliente Android, que no pasa por este devServer, y
    // duplicaría cabeceras — que es la razón por la que LibraryVue lo quitó.
  }
})
