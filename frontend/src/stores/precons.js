import { defineStore } from 'pinia'
import { apiCall, catalogGet } from '@/services/api'

/**
 * El catálogo de mazos preconstruidos: los 3.029 que publica MTGJSON.
 *
 * Hermano de `stores/catalog.js` y con sus mismas dos reglas, por los mismos
 * motivos:
 *
 *  - **La paginación es por CURSOR.** El backend devuelve `nextCursor` y aquí
 *    solo se transporta; nunca se calcula un offset ni se pide «la página 7».
 *  - **Los filtros viven aquí y la vista los refleja en la query string**, no al
 *    revés: así recargar con filtros puestos los mantiene y el enlace se comparte.
 *
 * Lo propio de los precons es el tercero: **el filtro de tipo es honesto**. De
 * los 48 tipos, cinco no son mazos (739 Secret Lair Drop, 197 MTGO Redemption,
 * 89 Bundle Land Pack, 16 Welcome Booster y 7 SDCC Promos: 1.048 de 3.029), así
 * que por defecto se pide `playable=1` y solo el conmutador «ver todo» lo quita.
 * **Quién es jugable lo dice el backend**, que marca cada tipo con `playable` en
 * las facetas: aquí no hay ni una cadena de tipo escrita a mano, porque MTGJSON
 * añadirá tipos y una lista copiada se desincronizaría sola.
 *
 * Y una cuarta que solo tiene la acción de M5: **la escritura NO va por
 * `catalogGet`**. Leer precons es catálogo —público, cacheable, sin sesión— pero
 * meter la caja en tu colección es dato de usuario, así que va por `apiCall` al
 * endpoint único, con su cookie y su token CSRF. La divergencia `GET` es solo de
 * lectura y aquí se ve dónde termina.
 */

/** Filtros vacíos. `todo` a false = solo mazos, que es el defecto honesto. */
function filtrosVacios() {
  return {
    q: '',
    type: '',
    set: '',
    todo: false
  }
}

export const usePreconStore = defineStore('precons', {
  state: () => ({
    filtros: filtrosVacios(),
    items: [],
    nextCursor: null,
    cargando: false,
    cargandoMas: false,
    error: null,
    /**
     * Las facetas del backend: los 48 tipos con su recuento y su marca de
     * `playable`, y las 295 ediciones que tienen precon (no las 868 del
     * catálogo: un desplegable donde 573 opciones dan cero resultados miente).
     * Vienen SOLO en la primera página, así que solo se pisan cuando llegan.
     */
    tipos: [],
    ediciones: [],
    /** La ficha abierta, con sus cartas ya agrupadas por zona. */
    ficha: null,
    cargandoFicha: false,
    errorFicha: null,
    /** Token de la búsqueda en curso, para descartar respuestas viejas. */
    peticionActual: 0,
    /**
     * El clic sobre la caja, en vuelo.
     *
     * Es el `fileName` que se está escribiendo o null. Bloquea **los dos**
     * botones mientras dura —el de la colección y el de deseos—: son 100 cartas
     * y dos tablas en una transacción, así que un segundo clic impaciente
     * crearía un segundo mazo sin que nadie lo pidiera. Y que sea uno solo para
     * los dos es deliberado: pulsar «lo quiero» mientras la compra está en vuelo
     * dejaría la caja en los dos conjuntos a la vez sin haberlo pedido.
     */
    importando: null,
    /**
     * A dónde fue el último clic: `'coleccion'` o `'deseos'`.
     *
     * Lo necesita la vista para saber cuál de los dos botones pintar cargando,
     * y el parte para no decir «en tu colección» a quien pulsó «lo quiero».
     */
    destinoEnVuelo: null,
    /**
     * Lo que devolvió el último clic, para poder enseñarlo **sin que se vaya
     * solo**: cuántos ejemplares entraron, qué mazo se creó y —lo importante—
     * qué se dio por supuesto. Un aviso de 3,5 segundos no basta para decir que
     * las cartas se han guardado como inglesas en NM.
     */
    resultadoImport: null,
    errorImport: null
  }),

  getters: {
    hayMas: (state) => state.nextCursor !== null,
    vacio: (state) => !state.cargando && state.items.length === 0,

    /** Los filtros puestos, para el contador del botón de filtros. */
    filtrosActivos: (state) =>
      [state.filtros.q, state.filtros.type, state.filtros.set].filter((v) => v !== '').length,

    /** Los tipos que sí son mazos, tal como los marca el backend. */
    tiposJugables: (state) => state.tipos.filter((t) => t.playable),

    /** Y los que no: solo se ofrecen con el «ver todo» puesto. */
    tiposNoJugables: (state) => state.tipos.filter((t) => !t.playable),

    /**
     * Los `printing_uuid` de la ficha abierta que de verdad caen en la
     * colección: los que el catálogo conoce y **no** son fichas.
     *
     * Es la misma criba que hace el backend —`known` por la FK a `mtg_printing`
     * y `Board::esPoseible()` por los tokens—, y existe aquí para que el
     * corazón del catálogo pueda rellenarse tras mandar la caja a deseos sin
     * volver a preguntar quién quedó deseado.
     */
    uuidsImportables: (state) =>
      (state.ficha?.cards ?? [])
        .filter((carta) => carta.known && carta.board !== 'tokens')
        .map((carta) => carta.printingUuid),

    /** Cuántas cajas esconde el defecto honesto, para poder decirlo en voz alta. */
    ocultosPorDefecto: (state) =>
      state.tipos.filter((t) => !t.playable).reduce((suma, t) => suma + t.count, 0)
  },

  actions: {
    /** Los filtros, traducidos a lo que entiende `/api/catalog/decks`. */
    parametros() {
      return {
        q: this.filtros.q,
        type: this.filtros.type,
        set: this.filtros.set,
        // `playable=1` solo cuando NO está el «ver todo». Va en cada página: si
        // se perdiera al pasar el cursor, el segundo tirón del scroll metería
        // los 739 Secret Lair Drop en medio de la lista.
        playable: this.filtros.todo ? '' : '1',
        limit: 60
      }
    },

    /**
     * Primera página con los filtros actuales.
     *
     * El contador de peticiones descarta las respuestas viejas: al teclear en el
     * buscador salen varias en vuelo y sin esto la más lenta pisa a la más
     * reciente.
     */
    async buscar() {
      const miPeticion = ++this.peticionActual

      this.cargando = true
      this.error = null

      const respuesta = await catalogGet('/decks', this.parametros())

      if (miPeticion !== this.peticionActual) {
        return
      }

      if (respuesta.error) {
        this.error = 'No se pudo cargar el catálogo de precons.'
        this.items = []
        this.nextCursor = null
      } else {
        this.items = respuesta.items || []
        this.nextCursor = respuesta.nextCursor ?? null

        // Solo la primera página las trae; si no vinieran, se conservan las que
        // ya hay en vez de vaciar los desplegables.
        if (Array.isArray(respuesta.deckTypes)) {
          this.tipos = respuesta.deckTypes
        }

        if (Array.isArray(respuesta.sets)) {
          this.ediciones = respuesta.sets
        }
      }

      this.cargando = false
    },

    /** Página siguiente, para el scroll infinito. */
    async cargarMas() {
      if (!this.nextCursor || this.cargandoMas || this.cargando) {
        return
      }

      const miPeticion = this.peticionActual
      this.cargandoMas = true

      const respuesta = await catalogGet('/decks', {
        ...this.parametros(),
        cursor: this.nextCursor
      })

      // Si mientras se pedía cambiaron los filtros, esta respuesta pertenece a
      // otra búsqueda y añadirla mezclaría resultados.
      if (miPeticion !== this.peticionActual) {
        this.cargandoMas = false
        return
      }

      if (!respuesta.error) {
        this.items.push(...(respuesta.items || []))
        this.nextCursor = respuesta.nextCursor ?? null
      }

      this.cargandoMas = false
    },

    /**
     * La ficha de un precon, por su `fileName`.
     *
     * `fileName` y no `name`: hay precons homónimos en ediciones distintas y
     * `SneakAttack_ZNC` es único. Es la clave natural de la tabla.
     */
    async cargarFicha(fileName) {
      if (!fileName) {
        this.errorFicha = 'Falta el identificador del precon.'
        return
      }

      this.cargandoFicha = true
      this.errorFicha = null
      this.ficha = null

      const respuesta = await catalogGet('/decks/' + encodeURIComponent(fileName))

      if (respuesta.error) {
        this.errorFicha = respuesta.error === 'precon_not_found'
          ? 'Ese precon no existe en el catálogo.'
          : 'No se pudo cargar el precon.'
      } else {
        this.ficha = respuesta
      }

      this.cargandoFicha = false
    },

    limpiarFicha() {
      this.ficha = null
      this.errorFicha = null
      this.resultadoImport = null
      this.errorImport = null
      this.destinoEnVuelo = null
    },

    /**
     * El botón de un clic: la caja entera a la colección y el mazo montado.
     *
     * @param {string} fileName La clave natural del precon
     * @param {string} [deckStatus] `built` por defecto en el backend
     * @returns {Promise<boolean>} si la caja quedó importada
     */
    async importar(fileName, deckStatus = undefined) {
      const datos = {}

      if (deckStatus) {
        datos.deck_status = deckStatus
      }

      return this.enviarLaCaja(fileName, 'coleccion', datos)
    },

    /**
     * El segundo botón (M7): la caja entera a la **lista de deseos**. Es la
     * lista de la compra de una caja que todavía no has comprado.
     *
     * **Es la misma acción con una bandera**, no un endpoint nuevo: no hay
     * operación nueva, son las mismas cartas cayendo en el otro conjunto del
     * `UNIQUE KEY` (`is_wishlist` está dentro), igual que `import_apply` lleva
     * la bandera del lote en vez de tener un gemelo.
     *
     * **El estado del mazo no se manda desde aquí**: `built` consume colección,
     * así que una caja deseada tiene que nacer `building` o el mazo diría estar
     * construido con cartas que no existen. Ese invariante es del dato y vive en
     * el backend (`ImportPreconToCollection`), no en esta pantalla; mandarlo
     * desde el cliente sería poder equivocarse desde el cliente.
     *
     * @param {string} fileName La clave natural del precon
     * @returns {Promise<boolean>} si la caja quedó deseada
     */
    async desearCaja(fileName) {
      return this.enviarLaCaja(fileName, 'deseos', { is_wishlist: true })
    },

    /**
     * Lo que comparten los dos botones: una sola llamada a
     * `precon_add_to_collection`, el guardia del doble clic y el parte.
     *
     * Va por `apiCall` y no por `catalogGet` porque **escribe**: necesita la
     * sesión y el token CSRF, que `apiCall` adjunta siempre que exista. Aquí no
     * se replica ninguna lista de «acciones protegidas»: quién exige el token lo
     * decide `CsrfMiddleware` en `routes.php` del backend.
     *
     * @param {string} fileName
     * @param {'coleccion'|'deseos'} destino
     * @param {object} extra Lo que distingue a un botón del otro en el payload
     * @returns {Promise<boolean>}
     */
    async enviarLaCaja(fileName, destino, extra = {}) {
      if (!fileName || this.importando) {
        return false
      }

      this.importando = fileName
      this.destinoEnVuelo = destino
      this.errorImport = null
      this.resultadoImport = null

      const respuesta = await apiCall('precon_add_to_collection', {
        file_name: fileName,
        ...extra
      })

      this.importando = null

      if (respuesta.status !== 'success') {
        this.destinoEnVuelo = null
        this.errorImport = respuesta.http_code === 401
          ? destino === 'deseos'
            ? 'Inicia sesión para guardar esta caja en tu lista de deseos.'
            : 'Inicia sesión para meter esta caja en tu colección.'
          : respuesta.message || (destino === 'deseos'
            ? 'No se pudo guardar esta caja en tu lista de deseos.'
            : 'No se pudo importar esta caja.')

        return false
      }

      this.resultadoImport = { ...respuesta.data, mensaje: respuesta.message }

      return true
    },

    /** Rehidrata los filtros desde la query string al entrar o recargar. */
    desdeQuery(query) {
      const base = filtrosVacios()

      for (const clave of ['q', 'type', 'set']) {
        if (query[clave] !== undefined && query[clave] !== '') {
          base[clave] = String(query[clave])
        }
      }

      // `all=1` en la URL = «ver todo». Su ausencia es el defecto honesto, así
      // que la URL limpia enseña mazos y no productos.
      base.todo = String(query.all ?? '') === '1'

      this.filtros = base
    }
  }
})
