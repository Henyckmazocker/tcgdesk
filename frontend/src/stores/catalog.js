import { defineStore } from 'pinia'
import { catalogGet } from '@/services/api'

/**
 * Estado del explorador de catálogo.
 *
 * La paginación es por CURSOR, no por página: el backend devuelve `nextCursor` y
 * aquí solo se guarda. Nunca se calcula un offset ni se pide "la página 7",
 * porque el backend recorrería y descartaría todas las filas anteriores en cada
 * tirón del scroll.
 *
 * Los filtros viven aquí y la vista los refleja en la query string, no al revés:
 * así recargar con filtros puestos los mantiene y el enlace se puede compartir.
 */

/** Filtros vacíos. Sirve de forma canónica y para el botón de limpiar. */
function filtrosVacios() {
  return {
    q: '',
    set: '',
    rarity: '',
    colors: '',
    price_min: '',
    price_max: '',
    sort: 'relevance'
  }
}

export const useCatalogStore = defineStore('catalog', {
  state: () => ({
    filtros: filtrosVacios(),
    items: [],
    nextCursor: null,
    /** Carga de la PRIMERA página: la vista enseña esqueletos. */
    cargando: false,
    /** Carga de una página siguiente: la vista enseña el spinner del pie. */
    cargandoMas: false,
    error: null,
    /** Ediciones para el desplegable; se piden una vez. */
    sets: [],
    /** Token de la búsqueda en curso, para descartar respuestas viejas. */
    peticionActual: 0
  }),

  getters: {
    hayMas: (state) => state.nextCursor !== null,
    vacio: (state) => !state.cargando && state.items.length === 0,
    filtrosActivos: (state) =>
      Object.entries(state.filtros).filter(
        ([clave, valor]) => valor !== '' && !(clave === 'sort' && valor === 'relevance')
      ).length
  },

  actions: {
    /**
     * Primera página con los filtros actuales.
     *
     * Cada llamada incrementa un contador y las respuestas de peticiones
     * anteriores se descartan: al teclear en el buscador salen varias en vuelo y
     * sin esto la más lenta pisa a la más reciente.
     */
    async buscar() {
      const miPeticion = ++this.peticionActual

      this.cargando = true
      this.error = null

      const respuesta = await catalogGet('/cards', { ...this.filtros, limit: 60 })

      if (miPeticion !== this.peticionActual) {
        return
      }

      if (respuesta.error) {
        this.error = 'No se pudo cargar el catálogo.'
        this.items = []
        this.nextCursor = null
      } else {
        this.items = respuesta.items || []
        this.nextCursor = respuesta.nextCursor ?? null
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

      const respuesta = await catalogGet('/cards', {
        ...this.filtros,
        cursor: this.nextCursor,
        limit: 60
      })

      // Si mientras se pedía la página siguiente cambiaron los filtros, esta
      // respuesta pertenece a otra búsqueda y añadirla mezclaría resultados.
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

    async cargarSets() {
      if (this.sets.length > 0) {
        return
      }

      const respuesta = await catalogGet('/sets')

      if (Array.isArray(respuesta)) {
        this.sets = respuesta
      }
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

    /** Rehidrata los filtros desde la query string al entrar o recargar. */
    desdeQuery(query) {
      const base = filtrosVacios()

      for (const clave of Object.keys(base)) {
        if (query[clave] !== undefined && query[clave] !== '') {
          base[clave] = String(query[clave])
        }
      }

      this.filtros = base
    }
  }
})
