import { defineStore } from 'pinia'
import { apiCall } from '@/services/api'

/**
 * Estado de la colección del usuario.
 *
 * Va por `apiCall` (POST al endpoint único) y NO por `catalogGet`: la colección
 * es privada y necesita credenciales, mientras que el helper del catálogo sale
 * sin ellas a propósito porque lo que lee es público.
 *
 * La paginación es por CURSOR, igual que en el catálogo: el backend devuelve
 * `nextCursor` y aquí solo se guarda; nunca se pide "la página 7".
 *
 * Los filtros viven aquí y la vista los refleja en la query string, no al revés.
 * Eso es lo que hace que recargar con filtros puestos los mantenga —lo que pide
 * el hito— y que el estado de la vista sea enlazable.
 */

/** El límite por tirón. El backend acota a 200; 60 llena una pantalla larga. */
const POR_PAGINA = 60

/** Filtros vacíos. Sirve de forma canónica y para el botón de limpiar. */
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

export const useCollectionStore = defineStore('collection', {
  state: () => ({
    /**
     * La línea que devolvió el último `collection_add`, con su `id` y su
     * `quantity` YA sumada.
     *
     * La necesita el escáner: para deshacer una carta escrita sola hay que
     * restarle una copia, y eso exige el `item_id` que solo conoce la respuesta
     * del alta. Se expone aquí en vez de cambiar lo que devuelve `anadir()`
     * porque esa firma la consumen también el catálogo y la ficha, y pasar de
     * `boolean` a objeto obligaría a revisarlas a las dos para ganar nada.
     */
    ultimoAnadido: null,

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
    /** `printing_uuid` con un "Añadir" en vuelo, para no doblar la petición. */
    anadiendo: [],
    /** Token de la búsqueda en curso, para descartar respuestas viejas. */
    peticionActual: 0,

    // ---- Dashboard (`/`) y progreso por edición (`/sets`) -----------------
    // Viven aquí y no en un store aparte porque son la MISMA colección vista
    // de otra manera: si mañana añadir una carta tuviera que invalidar el
    // total, el sitio donde hacerlo es este.
    //
    // Nada de esto se cachea entre visitas: el total se recalcula en cada
    // entrada porque los precios cambian a diario, y un valor guardado estaría
    // mal el 100 % de los días.
    /** Respuesta de `collection_value`: totals, bySet, byRarity, topCards. */
    resumen: null,
    cargandoResumen: false,
    errorResumen: null,
    /** Respuesta de `collection_sets`: sets con su porcentaje, y totals. */
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
    estaAnadiendo: (state) => (printingUuid) => state.anadiendo.includes(printingUuid)
  },

  actions: {
    /**
     * Primera página con los filtros actuales.
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
        limit: POR_PAGINA
      })

      if (miPeticion !== this.peticionActual) {
        return
      }

      if (respuesta.status !== 'success') {
        this.error = respuesta.message || 'No se pudo cargar tu colección.'
        this.items = []
        this.nextCursor = null
      } else {
        this.items = respuesta.data?.items || []
        this.nextCursor = respuesta.data?.nextCursor ?? null
      }

      this.cargando = false
    },

    /**
     * La valoración de la colección, para el dashboard de `/`.
     *
     * Se pide entera en cada visita a propósito: el backend la recalcula sobre
     * los precios de hoy y **nunca** la lee de una columna guardada.
     */
    async valorar() {
      this.cargandoResumen = true
      this.errorResumen = null

      const respuesta = await apiCall('collection_value')

      if (respuesta.status !== 'success') {
        this.errorResumen = respuesta.message || 'No se pudo calcular el valor de tu colección.'
        this.resumen = null
      } else {
        this.resumen = respuesta.data
      }

      this.cargandoResumen = false
    },

    /** El porcentaje de completado por edición, para `/sets`. */
    async cargarProgreso() {
      this.cargandoProgreso = true
      this.errorProgreso = null

      const respuesta = await apiCall('collection_sets')

      if (respuesta.status !== 'success') {
        this.errorProgreso = respuesta.message || 'No se pudo cargar tu progreso por edición.'
        this.progreso = null
      } else {
        this.progreso = respuesta.data
      }

      this.cargandoProgreso = false
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
     * Añadir una carta a la colección desde el catálogo.
     *
     * **Este es el camino de un solo clic** y la mitigación del riesgo del plan:
     * quien llama solo tiene que pasar el `printing_uuid` y la carta entra con
     * los valores por defecto del backend (`normal` / `English` / `NM`, cantidad
     * 1). No hay diálogo, ni confirmación, ni un segundo paso: un `@click` es
     * una carta añadida. Las cinco dimensiones existen en el modelo porque la
     * importación las necesita; la UI no obliga a rellenarlas.
     *
     * `opciones` es el camino de "Opciones", que es el que sí manda acabado,
     * idioma, estado o cantidad. Va por el MISMO sitio a propósito: si el
     * selector avanzado tuviera su propia llamada, los dos caminos podrían
     * divergir sin que nadie se enterase.
     *
     * Añadir dos veces la misma carta **suma a 2**: el upsert del repositorio
     * (`quantity = quantity + VALUES(quantity)`) lo resuelve en el servidor, así
     * que aquí no hay nada que impedir ni que deduplicar.
     *
     * @param {string} printingUuid
     * @param {object} opciones `finish`, `language`, `condition`, `quantity`
     * @returns {Promise<boolean>} si la carta quedó añadida
     */
    async anadir(printingUuid, opciones = {}) {
      if (!printingUuid || this.anadiendo.includes(printingUuid)) {
        return false
      }

      this.anadiendo = [...this.anadiendo, printingUuid]

      const respuesta = await apiCall('collection_add', {
        printing_uuid: printingUuid,
        ...opciones
      })

      this.anadiendo = this.anadiendo.filter((x) => x !== printingUuid)

      if (respuesta.status !== 'success') {
        this.aviso = {
          tipo: 'error',
          texto: respuesta.http_code === 401
            ? 'Inicia sesión para añadir cartas a tu colección.'
            : respuesta.message || 'No se pudo añadir la carta.'
        }
        return false
      }

      const item = respuesta.data?.item ?? null

      this.ultimoAnadido = item

      // Si la vista de colección está montada con esta línea a la vista, se
      // refresca en su sitio: el backend devuelve la cantidad YA sumada.
      if (item) {
        this.reemplazar(item.id, item)
      }

      const nombre = item?.name || 'La carta'
      const cantidad = item?.quantity ?? 1

      this.aviso = {
        tipo: 'ok',
        texto: cantidad > 1
          ? `${nombre} añadida — ya tienes ${cantidad}.`
          : `${nombre} añadida a tu colección.`
      }

      return true
    },

    /**
     * Edición en línea de la cantidad. **Cero borra la línea**, y la carta
     * desaparece de la lista: el backend no deja filas a 0 y la vista no debe
     * enseñar una que ya no existe.
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
        this.aviso = { tipo: 'ok', texto: `${item.name} ya no está en tu colección.` }
      } else {
        this.reemplazar(item.id, respuesta.data.item)
      }

      return true
    },

    /**
     * Edición en línea del estado físico.
     *
     * Es la única edición que puede hacer desaparecer una fila SIN borrar
     * cartas: `condition_grade` está dentro del `UNIQUE KEY`, así que el backend
     * puede haber fundido esta línea con otra que ya tenías en ese estado. Por
     * eso se mira el `id` que vuelve y no se asume que sea el que se mandó.
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
        ? { tipo: 'ok', texto: `${item.name}: unida a la línea que ya tenías en ese estado.` }
        : { tipo: 'ok', texto: 'Estado actualizado.' }

      return true
    },

    /** Quitar la línea entera, sin pasar por la cantidad. */
    async quitar(item) {
      this.marcarGuardando(item.id, true)

      const respuesta = await apiCall('collection_remove', { item_id: item.id })

      this.marcarGuardando(item.id, false)

      if (respuesta.status !== 'success') {
        this.aviso = { tipo: 'error', texto: respuesta.message || 'No se pudo quitar la carta.' }
        return false
      }

      this.items = this.items.filter((i) => i.id !== item.id)
      this.aviso = { tipo: 'ok', texto: `${item.name} ya no está en tu colección.` }

      return true
    },

    /**
     * ¿La línea sigue cumpliendo el filtro que hay puesto?
     *
     * Solo se comprueba el estado, que es lo único que cambia una edición en
     * línea: si estás filtrando por NM y marcas una carta como LP, tiene que
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
