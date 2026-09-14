import { defineStore } from 'pinia'
import { apiCall } from '@/services/api'

/**
 * Estado de la lista de deseos del usuario.
 *
 * **Es un store aparte de `collection.js` a propósito, y se asume que el código
 * se parece.** Una Pinia es un singleton y `/collection` y `/wishlist` conviven
 * en el historial: con un solo store y una bandera, navegar de una a otra
 * obligaría a reiniciar `items`, `filtros`, `nextCursor` y el bloque del
 * dashboard, y el primer frame de `/wishlist` enseñaría tus cartas. El precio
 * —dos ficheros parecidos— está aceptado por escrito en el plan; si algún día
 * apareciera un tercer modo, entonces sí tocará la factoría compartida.
 *
 * `is_wishlist` NO es un filtro más: el `WHERE ci.is_wishlist = :is_wishlist`
 * está SIEMPRE en la consulta (`MySqlCollectionRepository.php:464`), así que
 * elige el conjunto en vez de recortarlo. Por eso viaja fijo en cada llamada y
 * no vive en `filtros`: nadie debe poder quitarlo desde la interfaz.
 */

/** El mismo límite por tirón que la colección; 60 llena una pantalla larga. */
const POR_PAGINA = 60

/** Filtros vacíos. Sin `is_wishlist`: eso no se filtra, se fija. */
export function filtrosVacios() {
  return {
    set: '',
    rarity: '',
    colors: '',
    finish: '',
    language: '',
    condition: '',
    price_min: '',
    price_max: '',
    sort: 'price_desc'
  }
}

export const useWishlistStore = defineStore('wishlist', {
  state: () => ({
    filtros: filtrosVacios(),
    /** 'grid' o 'table'. Se conmuta y también viaja en la query string. */
    vista: 'grid',
    items: [],
    nextCursor: null,
    /** Carga de la PRIMERA página: la vista enseña esqueletos. */
    cargando: false,
    /** Carga de una página siguiente: la vista enseña el spinner del pie. */
    cargandoMas: false,
    error: null,
    /** Último aviso de una edición en línea, para el toast de la vista. */
    aviso: null,
    /** Ids con una edición en vuelo, para deshabilitar sus controles. */
    guardando: [],
    /**
     * `printing_uuid` con un deseo en vuelo.
     *
     * Es propio y NO se comparte con `collection.anadiendo`: los dos indexan por
     * `printing_uuid`, así que con uno solo pulsar el corazón deshabilitaría el
     * botón «Añadir» de la misma carta, y al revés.
     */
    deseando: [],
    /**
     * Un alta EN LOTE en vuelo (`desearLote()`).
     *
     * No se indexa por nada: el lote no es de una carta, es de una pantalla
     * entera. Lo que evita es el doble clic sobre «mandar lo que falta a
     * deseos», que dejaría el doble de ejemplares deseados.
     */
    anadiendoLote: false,
    /** Token de la búsqueda en curso, para descartar respuestas viejas. */
    peticionActual: 0,

    // ---- El corazón relleno del catálogo -----------------------------------
    // `GET /api/catalog/cards` se desvía en `public/index.php:30-43` ANTES de
    // construir `Application`, así que no tiene sesión y sus resultados no
    // pueden venir anotados con la lista de deseos de nadie. El cruce lo hace
    // el cliente: se pide una vez la lista de uuids deseados y con ella se
    // pintan los 60 corazones de la rejilla.
    //
    // Vive en ESTE store y no en el componente porque el corazón sale en dos
    // sitios —la rejilla de `/catalog` y la ficha de `/card/:uuid`— y los dos
    // tienen que ver lo mismo. Una Pinia es un singleton: ese es justo el
    // motivo por el que el Set va aquí.
    /** Los `printing_uuid` que YA están en la lista de deseos. */
    deseados: new Set(),
    /** Ya se pidió la lista en esta navegación, aunque volviera vacía. */
    deseadosCargados: false,
    /**
     * La lista en vuelo.
     *
     * Es lo que convierte 60 tarjetas montándose a la vez en UNA petición: la
     * primera levanta la bandera y las otras 59 se vuelven sin llamar a nada.
     */
    cargandoDeseados: false,

    // ---- El valor de lo que quieres (cabecera de `/wishlist` y `/sets`) ----
    // Viven aquí por el mismo motivo que sus gemelos de `collection.js`: son la
    // MISMA lista vista de otra manera, y el día que cumplir un deseo tenga que
    // invalidar el total, el sitio donde hacerlo es este.
    //
    // Y nada de esto se cachea entre visitas, igual que allí: el backend
    // recalcula sobre los precios de hoy y un total guardado estaría mal el
    // 100 % de los días. En una lista de deseos eso pesa todavía más, porque el
    // número es justo el que dice cuánto costaría comprarla hoy.
    /** Respuesta de `collection_value`: totals, bySet, byRarity, topCards. */
    resumen: null,
    cargandoResumen: false,
    errorResumen: null,
    /** Respuesta de `collection_sets`, para el modo deseos de `/sets`. */
    progreso: null,
    cargandoProgreso: false,
    errorProgreso: null
  }),

  getters: {
    hayMas: (state) => state.nextCursor !== null,
    vacio: (state) => !state.cargando && state.items.length === 0,
    filtrosActivos: (state) =>
      Object.entries(state.filtros).filter(
        ([clave, valor]) => valor !== '' && !(clave === 'sort' && valor === 'price_desc')
      ).length,
    estaGuardando: (state) => (id) => state.guardando.includes(id),
    estaDeseando: (state) => (printingUuid) => state.deseando.includes(printingUuid),
    /** ¿Esta impresión ya está en la lista? Lo que decide el corazón relleno. */
    esDeseada: (state) => (printingUuid) => state.deseados.has(printingUuid)
  },

  actions: {
    /**
     * Primera página de deseos con los filtros actuales.
     *
     * El contador de peticiones descarta las respuestas viejas: cambiar de
     * filtro varias veces seguidas deja varias en vuelo, y sin esto la más lenta
     * pisaría a la más reciente.
     */
    async buscar() {
      const miPeticion = ++this.peticionActual

      this.cargando = true
      this.error = null

      const respuesta = await apiCall('collection_list', {
        ...this.filtros,
        is_wishlist: true,
        limit: POR_PAGINA
      })

      if (miPeticion !== this.peticionActual) {
        return
      }

      if (respuesta.status !== 'success') {
        this.error = respuesta.message || 'No se pudo cargar tu lista de deseos.'
        this.items = []
        this.nextCursor = null
      } else {
        this.items = respuesta.data?.items || []
        this.nextCursor = respuesta.data?.nextCursor ?? null
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

      const respuesta = await apiCall('collection_list', {
        ...this.filtros,
        is_wishlist: true,
        cursor: this.nextCursor,
        limit: POR_PAGINA
      })

      // Si mientras se pedía la página siguiente cambiaron los filtros, esta
      // respuesta pertenece a otra consulta y añadirla mezclaría listas.
      if (miPeticion !== this.peticionActual) {
        this.cargandoMas = false
        return
      }

      if (respuesta.status === 'success') {
        this.items.push(...(respuesta.data?.items || []))
        this.nextCursor = respuesta.data?.nextCursor ?? null
      }

      this.cargandoMas = false
    },

    /**
     * Cuánto cuesta lo que quieres, para la cabecera de `/wishlist`.
     *
     * Es el mismo `collection_value` del dashboard con `is_wishlist: true`, que
     * el backend acepta desde el primer día (`ValueCollection.php:52`) y que
     * hasta ahora nadie le mandaba: no hay endpoint nuevo que inventar.
     *
     * El flag viaja aquí y **no en `filtros`**, por lo mismo que en `buscar()`:
     * elige el conjunto, no lo recorta, y nadie debe poder apagarlo desde la
     * interfaz. Ojo con los dos nombres: el del **payload** es `is_wishlist` y
     * el del **contrato** de vuelta es `isWishlist`.
     */
    async valorar() {
      this.cargandoResumen = true
      this.errorResumen = null

      const respuesta = await apiCall('collection_value', { is_wishlist: true })

      if (respuesta.status !== 'success') {
        this.errorResumen = respuesta.message || 'No se pudo calcular el valor de tu lista de deseos.'
        this.resumen = null
      } else {
        this.resumen = respuesta.data
      }

      this.cargandoResumen = false
    },

    /**
     * Cuántas quieres de cada edición, para el modo deseos de `/sets`.
     *
     * `collection_sets` devuelve **también** `percent` sobre una lista de deseos
     * —lo calcula igual, sin saber de qué conjunto habla—, y ese número parece
     * progreso y no lo es: `ownedPrintings / totalSetSize` sobre lo que quieres
     * no mide nada. Aquí se guarda tal cual y es la vista la que decide no
     * pintarlo; filtrarlo en el store escondería el dato a quien lo depure.
     */
    async cargarProgreso() {
      this.cargandoProgreso = true
      this.errorProgreso = null

      const respuesta = await apiCall('collection_sets', { is_wishlist: true })

      if (respuesta.status !== 'success') {
        this.errorProgreso = respuesta.message || 'No se pudo cargar lo que quieres de cada edición.'
        this.progreso = null
      } else {
        this.progreso = respuesta.data
      }

      this.cargandoProgreso = false
    },

    /**
     * Qué impresiones quieres ya, para que el corazón salga relleno.
     *
     * **Una sola petición por navegación.** La llaman los propios corazones al
     * montarse —el de la rejilla y el de la ficha, y cualquiera que se añada
     * mañana—, así que la guarda de aquí no es una optimización: sin ella, una
     * página de catálogo serían 60 peticiones iguales.
     *
     * Y a diferencia de `resumen` y `progreso`, esto **sí** puede vivir mientras
     * dure la navegación: no es un total que los precios de hoy dejen obsoleto,
     * es un conjunto que solo cambia cuando el usuario toca su lista, y quien la
     * toca lo actualiza aquí mismo (`desear()`, `desearLote()`, `quitar()` y
     * `cumplir()`). Por eso no hace falta refrescarlo nunca.
     *
     * Un fallo NO deja aviso: el corazón se queda en contorno y ya está. Un
     * toast de error por algo que el usuario no ha pedido sería ruido encima
     * del catálogo.
     *
     * @param {{forzar?: boolean}} opciones
     * @returns {Promise<boolean>} si el conjunto quedó cargado
     */
    async cargarDeseados({ forzar = false } = {}) {
      if (this.cargandoDeseados) {
        return false
      }

      if (this.deseadosCargados && !forzar) {
        return true
      }

      this.cargandoDeseados = true

      const respuesta = await apiCall('collection_wished_uuids')

      this.cargandoDeseados = false

      if (respuesta.status !== 'success') {
        return false
      }

      this.deseados = new Set(respuesta.data?.uuids || [])
      this.deseadosCargados = true

      return true
    },

    /**
     * Estas impresiones ya están deseadas: el corazón se rellena **sin esperar
     * a ninguna otra petición**.
     *
     * Existe porque no todo lo que llena la lista de deseos pasa por este store:
     * mandar un precon entero a deseos (M7) lo escribe
     * `precon_add_to_collection`, que es del store de precons porque la caja
     * escribe además el mazo. El `Set` sigue siendo de aquí —es estado de la
     * lista de deseos— y quien lo mueve, un action de aquí; así el catálogo no
     * miente hasta el siguiente refresco, que es justo lo que M6 arregló.
     *
     * @param {string[]} printingUuids
     */
    marcarDeseados(printingUuids) {
      for (const uuid of printingUuids || []) {
        if (uuid) {
          this.deseados.add(uuid)
        }
      }
    },

    /**
     * Esta impresión ya NO tiene ningún deseo: el corazón vuelve al contorno.
     *
     * Se llama después de quitar la línea de `items`, y por eso puede mirarla:
     * si queda otra línea de deseo de la misma impresión —otro acabado, otro
     * idioma, otro estado— el corazón sigue relleno, porque habla de la carta y
     * no de la línea.
     *
     * `items` es la página cargada y no la lista entera, pero dos líneas de la
     * misma impresión valen casi lo mismo y el orden por defecto es el precio:
     * caer en páginas distintas es prácticamente imposible, y si pasara el
     * corazón se quedaría en contorno hasta la siguiente carga, que es el error
     * que se corrige solo.
     */
    olvidarDeseo(printingUuid) {
      if (!printingUuid || this.items.some((i) => i.printingUuid === printingUuid)) {
        return
      }

      this.deseados.delete(printingUuid)
    },

    /**
     * Querer una carta desde el catálogo: **el corazón**.
     *
     * Misma promesa que el botón «Añadir» de la colección —un clic es un deseo,
     * sin diálogo ni segundo paso—, y por el mismo camino: `collection_add` con
     * `is_wishlist`, que el backend acepta desde el primer día
     * (`CollectionItem.php:84`). No hay acción nueva que inventar.
     *
     * Desear dos veces la misma carta **suma a 2**, igual que añadirla: lo
     * resuelve el upsert del repositorio y aquí no hay nada que deduplicar.
     *
     * @param {string} printingUuid
     * @param {object} opciones `finish`, `language`, `condition`, `quantity`
     * @returns {Promise<boolean>} si la carta quedó deseada
     */
    async desear(printingUuid, opciones = {}) {
      if (!printingUuid || this.deseando.includes(printingUuid)) {
        return false
      }

      this.deseando = [...this.deseando, printingUuid]

      const respuesta = await apiCall('collection_add', {
        printing_uuid: printingUuid,
        ...opciones,
        is_wishlist: true
      })

      this.deseando = this.deseando.filter((x) => x !== printingUuid)

      if (respuesta.status !== 'success') {
        this.aviso = {
          tipo: 'error',
          texto: respuesta.http_code === 401
            ? 'Inicia sesión para guardar cartas en tu lista de deseos.'
            : respuesta.message || 'No se pudo guardar la carta en tu lista de deseos.'
        }
        return false
      }

      const item = respuesta.data?.item ?? null

      // EL CORAZÓN SE RELLENA AQUÍ, no en el siguiente refresco. Si hubiera que
      // esperar a volver a pedir la lista, el botón mentiría durante un segundo
      // y el usuario pulsaría otra vez, que es justo el deseo doble que este
      // relleno existe para evitar.
      this.deseados.add(printingUuid)

      // Si `/wishlist` está montada con esta línea a la vista, se refresca en su
      // sitio: el backend devuelve la cantidad YA sumada.
      if (item) {
        this.reemplazar(item.id, item)
      }

      const nombre = item?.name || 'La carta'
      const cantidad = item?.quantity ?? 1

      this.aviso = {
        tipo: 'ok',
        texto: cantidad > 1
          ? `${nombre} en tu lista de deseos — ya quieres ${cantidad}.`
          : `${nombre} añadida a tu lista de deseos.`
      }

      return true
    },

    /**
     * Querer VARIAS cartas de un solo gesto: la puerta de entrada masiva.
     *
     * Va por `import_apply` con `is_wishlist: true` y no por N `collection_add`,
     * y las dos razones pesan lo mismo:
     *
     *  - **Una petición, una transacción.** `ApplyImport` escribe el lote entero
     *    con `upsertLote()` o no escribe nada. Con una llamada por línea, la
     *    séptima puede no llegar y la lista queda a medias sin que nadie lo diga
     *    —es el mismo motivo por el que el `CLAUDE.md` prohíbe resolver un
     *    movimiento de fila con un `remove` + un `add`—.
     *  - **El límite de peticiones.** Son 60 por minuto y por IP
     *    (`RATE_LIMIT_MAX_REQUESTS`), y a un Commander a medio montar le pueden
     *    faltar 60 líneas. `routes.php:228-231` ya dejó escrito que trocear un
     *    lote en peticiones choca contra el límite.
     *
     * **Las cuatro dimensiones viajan siempre y sin valores por defecto.** Un
     * deseo en `normal` no cierra un hueco de `foil`: el consumo del mazo cruza
     * por las cinco dimensiones con `is_wishlist = 0`
     * (`MySqlDeckRepository.php:187-200`), así que un deseo nacido con los
     * defectos de `CollectionItem` (`normal`/`English`/`NM`) dejaría al usuario
     * comprando una carta que el mazo le seguiría pidiendo.
     *
     * No se toca `items`: quien llama a esto no es `/wishlist` —está en el
     * editor del mazo— y recargar una lista que nadie está mirando sería una
     * petición de más. La lista se leerá entera al entrar en `/wishlist`.
     *
     * @param {Array<{printingUuid: string, finish: string, language: string,
     *                condition: string, quantity: number}>} lineas
     * @returns {Promise<boolean>} si el lote entero quedó deseado
     */
    async desearLote(lineas) {
      const filas = (lineas || [])
        .filter((l) => l?.printingUuid && l.quantity > 0)
        .map((l) => ({
          printingUuid: l.printingUuid,
          finish: l.finish,
          language: l.language,
          condition: l.condition,
          quantity: l.quantity
        }))

      if (filas.length === 0) {
        return false
      }

      if (this.anadiendoLote) {
        return false
      }

      this.anadiendoLote = true

      const respuesta = await apiCall('import_apply', { rows: filas, is_wishlist: true })

      this.anadiendoLote = false

      if (respuesta.status !== 'success') {
        this.aviso = {
          tipo: 'error',
          texto: respuesta.http_code === 401
            ? 'Inicia sesión para guardar cartas en tu lista de deseos.'
            : respuesta.message || 'No se han podido mandar las cartas a tu lista de deseos.'
        }
        return false
      }

      // Las cartas del lote también rellenan su corazón: quien vuelva al
      // catálogo tras mandar lo que le falta a deseos tiene que verlas ya
      // marcadas, sin esperar a ninguna otra petición.
      for (const fila of filas) {
        this.deseados.add(fila.printingUuid)
      }

      // El backend cuenta líneas nuevas y líneas que ya estaban y han sumado;
      // lo que le importa al usuario es cuántos ejemplares quiere ahora.
      const ejemplares = respuesta.data?.totalQuantity ?? 0

      this.aviso = {
        tipo: 'ok',
        texto: `${ejemplares} ejemplar(es) en tu lista de deseos, con la versión exacta que pide el mazo.`
      }

      return true
    },

    /**
     * Edición en línea de la cantidad deseada. **Cero borra la línea**: el
     * backend no deja filas a 0 y la vista no debe enseñar una que ya no existe.
     */
    async cambiarCantidad(item, cantidad) {
      if (cantidad === item.quantity) {
        return true
      }

      this.marcarGuardando(item.id, true)

      const respuesta = await apiCall('collection_update_quantity', {
        item_id: item.id,
        quantity: cantidad
      })

      this.marcarGuardando(item.id, false)

      if (respuesta.status !== 'success') {
        this.aviso = { tipo: 'error', texto: respuesta.message || 'No se pudo guardar la cantidad.' }
        return false
      }

      if (respuesta.data?.removed) {
        this.items = this.items.filter((i) => i.id !== item.id)
        this.olvidarDeseo(item.printingUuid)
        this.aviso = { tipo: 'ok', texto: `${item.name} ya no está en tu lista de deseos.` }
      } else {
        this.reemplazar(item.id, respuesta.data.item)
      }

      return true
    },

    /**
     * Edición en línea del estado que quieres.
     *
     * `condition_grade` está dentro del `UNIQUE KEY`, así que el backend puede
     * haber fundido este deseo con otro que ya quisieras en ese estado y la
     * línea desaparece sumada a la otra. Por eso se mira el `id` que vuelve y no
     * se asume que sea el que se mandó.
     */
    async cambiarCondicion(item, condicion) {
      if (condicion === item.condition) {
        return true
      }

      this.marcarGuardando(item.id, true)

      const respuesta = await apiCall('collection_change_grade', {
        item_id: item.id,
        condition: condicion
      })

      this.marcarGuardando(item.id, false)

      if (respuesta.status !== 'success') {
        this.aviso = { tipo: 'error', texto: respuesta.message || 'No se pudo guardar el estado.' }
        return false
      }

      const nuevo = respuesta.data?.item ?? null
      const posicion = this.items.findIndex((i) => i.id === item.id)

      // La línea original ya no existe con esa clave, se haya movido o fundido.
      if (posicion !== -1) {
        this.items.splice(posicion, 1)
      }

      if (nuevo) {
        const yaEnLista = this.items.findIndex((i) => i.id === nuevo.id)

        if (yaEnLista !== -1) {
          this.items[yaEnLista] = nuevo
        } else if (this.encajaEnLosFiltros(nuevo) && posicion !== -1) {
          // Se reinserta donde estaba: reordenar la lista entera movería cartas
          // bajo el dedo del usuario en mitad de una edición.
          this.items.splice(posicion, 0, nuevo)
        }
      }

      this.aviso = respuesta.data?.merged
        ? { tipo: 'ok', texto: `${item.name}: unida al deseo que ya tenías en ese estado.` }
        : { tipo: 'ok', texto: 'Estado actualizado.' }

      return true
    },

    /**
     * **«Ya la tengo»**: cumplir el deseo, del todo o en parte.
     *
     * No es un `UPDATE`, es un movimiento de fila dentro de `uq_item`, y de ahí
     * los dos cuidados que no tiene ninguna otra edición de esta lista:
     *
     * 1. **El `id` del deseo puede haber dejado de existir.** Si cumples lo que
     *    querías entero, el backend borra la línea de origen; si cumples una
     *    parte, la deja con menos. Quien manda es el `origen` de la respuesta, y
     *    no el `item.id` que se envió: `origen === null` significa quitar la
     *    línea, y un `origen` con cantidad significa bajarle el número **en su
     *    sitio**, porque reordenar movería cartas bajo el dedo del usuario.
     * 2. **`item` es la línea de COLECCIÓN**, no de deseos, y puede venir fundida
     *    con una que ya tuvieras (`merged`). Aquí no se pinta: esta lista solo
     *    enseña deseos. Se devuelve para que la vista lo diga en el aviso.
     *
     * `cantidad` ausente es **uno**, que es el gesto normal —compras una carta—;
     * `null` explícito es la fila entera. El backend distingue los dos casos con
     * `array_key_exists()` (`FulfillWish.php:90-125`), así que aquí la clave solo
     * viaja cuando hay algo que decir.
     *
     * @param {object} item      la línea de DESEO
     * @param {object} opciones  `cantidad` (número o null) y `condicion`
     * @returns {Promise<boolean>} si el deseo quedó cumplido
     */
    async cumplir(item, opciones = {}) {
      const carga = { item_id: item.id }

      if ('cantidad' in opciones) {
        carga.quantity = opciones.cantidad
      }

      if (opciones.condicion) {
        carga.condition = opciones.condicion
      }

      this.marcarGuardando(item.id, true)

      const respuesta = await apiCall('collection_fulfill_wish', carga)

      this.marcarGuardando(item.id, false)

      if (respuesta.status !== 'success') {
        this.aviso = { tipo: 'error', texto: respuesta.message || 'No se pudo cumplir el deseo.' }
        return false
      }

      const origen = respuesta.data?.origen ?? null
      const posicion = this.items.findIndex((i) => i.id === item.id)

      if (posicion !== -1) {
        if (origen) {
          // Movimiento parcial: el deseo sigue ahí con menos ejemplares.
          this.items[posicion] = origen
        } else {
          this.items.splice(posicion, 1)
        }
      }

      // Sin `origen` el deseo se agotó y el backend borró la línea: ya la
      // tienes, así que el corazón del catálogo deja de decir que la quieres.
      if (!origen) {
        this.olvidarDeseo(item.printingUuid)
      }

      const nombre = respuesta.data?.item?.name || item.name

      this.aviso = {
        tipo: 'ok',
        texto: origen
          ? `${nombre} en tu colección — todavía quieres ${origen.quantity}.`
          : `${nombre} ya es tuya: fuera de la lista de deseos.`
      }

      return true
    },

    /** Quitar el deseo entero, sin pasar por la cantidad. */
    async quitar(item) {
      this.marcarGuardando(item.id, true)

      const respuesta = await apiCall('collection_remove', { item_id: item.id })

      this.marcarGuardando(item.id, false)

      if (respuesta.status !== 'success') {
        this.aviso = { tipo: 'error', texto: respuesta.message || 'No se pudo quitar la carta.' }
        return false
      }

      this.items = this.items.filter((i) => i.id !== item.id)
      this.olvidarDeseo(item.printingUuid)
      this.aviso = { tipo: 'ok', texto: `${item.name} ya no está en tu lista de deseos.` }

      return true
    },

    /**
     * ¿La línea sigue cumpliendo el filtro que hay puesto?
     *
     * Solo se comprueba el estado, que es lo único que cambia una edición en
     * línea: si estás filtrando por NM y marcas un deseo como LP, tiene que
     * desaparecer de la lista, no quedarse contradiciendo el filtro.
     */
    encajaEnLosFiltros(item) {
      return this.filtros.condition === '' || this.filtros.condition === item.condition
    },

    reemplazar(id, item) {
      if (!item) {
        return
      }

      const i = this.items.findIndex((x) => x.id === id)

      if (i !== -1) {
        this.items[i] = item
      }
    },

    marcarGuardando(id, activo) {
      this.guardando = activo
        ? [...this.guardando, id]
        : this.guardando.filter((x) => x !== id)
    },

    /** @param {object} nuevos Filtros parciales; el resto se conserva */
    aplicarFiltros(nuevos) {
      this.filtros = { ...this.filtros, ...nuevos }
      return this.buscar()
    },

    limpiarFiltros() {
      this.filtros = filtrosVacios()
      return this.buscar()
    },

    /** Rehidrata filtros y modo de vista desde la query string al recargar. */
    desdeQuery(query) {
      const base = filtrosVacios()

      for (const clave of Object.keys(base)) {
        if (query[clave] !== undefined && query[clave] !== '') {
          base[clave] = String(query[clave])
        }
      }

      this.filtros = base
      this.vista = query.view === 'table' ? 'table' : 'grid'
    }
  }
})
