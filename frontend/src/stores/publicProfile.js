import { defineStore } from 'pinia'
import { publicGet } from '@/services/api'

/**
 * Lo que se ve de otra persona: su perfil público y el mazo de un enlace
 * compartido.
 *
 * Tres cosas lo separan del resto de stores de la app:
 *
 *  - **Lee por `publicGet`, nunca por `apiCall`.** Sin cookie, con `Bearer` si
 *    hay JWT. Quien abre `/user/<username>` puede no tener cuenta, así que aquí
 *    no se toca `stores/auth` ni se da por hecho que haya sesión.
 *  - **No decide qué se enseña: lo decide el backend.** El mapa `visible` que
 *    trae el perfil es la única fuente de verdad de qué secciones se pintan, y
 *    aun así cada sección puede volver con 403 `not_visible` por su cuenta —lo
 *    comprueba `Visibilidad` otra vez—. Las dos vías acaban en lo mismo:
 *    `privada: true`.
 *  - **Una sección privada NO se esconde.** Se marca, y la vista dice que es
 *    privada. Una sección que desaparece se lee como un fallo de carga, y esa
 *    ambigüedad es justo lo que este plan no quiere: hay que distinguir «no me
 *    dejan verlo» de «no cargó».
 */

/**
 * Las cuatro secciones del perfil, con la ruta que las sirve y de qué clave sale
 * su lista.
 *
 * Las claves son **las del backend** (`visible.collection`, `visible.decks`…) y
 * no una traducción propia: es lo que permite cruzar el mapa `visible` con esta
 * tabla sin un segundo diccionario que se desincronice.
 *
 * `value` NO está aquí, y es a propósito: no es una sección con ruta propia sino
 * un modificador —cuando es visible, el perfil trae un bloque `value` y las
 * cartas traen `priceEur`—.
 */
export const SECCIONES = [
  { clave: 'collection', etiqueta: 'Colección', ruta: '/collection', lista: 'items', paginada: true },
  { clave: 'decks', etiqueta: 'Mazos', ruta: '/decks', lista: 'decks', paginada: false },
  { clave: 'sets', etiqueta: 'Ediciones', ruta: '/sets', lista: 'sets', paginada: false },
  { clave: 'wishlist', etiqueta: 'Lista de deseos', ruta: '/wishlist', lista: 'items', paginada: true }
]

const POR_CLAVE = Object.fromEntries(SECCIONES.map((s) => [s.clave, s]))

/** El estado inicial de una sección, antes de saber siquiera si se puede ver. */
function seccionVacia() {
  return {
    items: [],
    nextCursor: null,
    cargando: false,
    cargandoMas: false,
    /** El backend dijo que no se enseña. NO es un error: es una respuesta. */
    privada: false,
    /** Un fallo de verdad, ya traducido. `null` si no lo hubo. */
    error: null
  }
}

function seccionesVacias() {
  return Object.fromEntries(SECCIONES.map((s) => [s.clave, seccionVacia()]))
}

/**
 * Traduce el código de error del backend a lo que se le dice a una persona.
 *
 * `not_visible` no pasa por aquí: no es un fallo, y confundirlo con uno es
 * exactamente el error que el estado vacío honesto evita.
 */
export function mensajeDeError(codigo) {
  switch (codigo) {
    case 'rate_limited':
      // Las rutas públicas van limitadas a 60/min por IP. Decir «error» a secas
      // haría que el visitante recargara, que es lo peor que puede hacer.
      return 'Demasiadas peticiones seguidas. Espera un minuto y vuelve a intentarlo.'
    case 'network_error':
      return 'No se pudo contactar con el servidor.'
    default:
      return 'No se pudo cargar esta información.'
  }
}

export const usePublicProfileStore = defineStore('publicProfile', {
  state: () => ({
    /** El `username` de la URL, para saber a quién pertenece lo cargado. */
    username: null,
    /** `{username, displayName, avatarUrl}`. Nunca trae `email`. */
    usuario: null,
    /** El mapa del backend: qué secciones se enseñan a QUIEN está mirando. */
    visible: {},
    /** El resumen en euros, solo si `visible.value`. `null` si no. */
    valor: null,
    cargandoPerfil: false,
    /**
     * `'no_existe'` para un username que no existe (404), o el mensaje ya
     * traducido para cualquier otro fallo. Se distinguen porque la vista dice
     * cosas distintas: «no hay nadie así» no es «no se pudo cargar».
     */
    errorPerfil: null,

    secciones: seccionesVacias(),

    /** El mazo de `/shared/deck/:token`, que no tiene nada que ver con el perfil. */
    mazo: null,
    cargandoMazo: false,
    /** `'no_existe'` o un mensaje traducido. Un token malo SIEMPRE es 404. */
    errorMazo: null
  }),

  getters: {
    /** El perfil está cargado y se puede pintar algo. */
    hayPerfil: (state) => state.usuario !== null,

    /**
     * Las cuatro secciones en orden, ya cruzadas con `visible` y con su estado.
     * Es lo único que la vista necesita para pintar el perfil entero, incluido
     * el «esta sección es privada».
     */
    seccionesDelPerfil: (state) =>
      SECCIONES.map((seccion) => ({
        ...seccion,
        // Sin perfil cargado no se sabe nada todavía; con él, lo que mande el
        // backend. `?? false` y no `?? true`: fail-closed también en el cliente.
        visible: state.visible[seccion.clave] ?? false,
        estado: state.secciones[seccion.clave]
      })),

    /** ¿Se enseña el dinero? Decide el resumen del perfil y la columna de precio. */
    valorVisible: (state) => state.visible.value === true
  },

  actions: {
    /**
     * Carga el perfil y, con él, las secciones que el backend deja ver.
     *
     * Las secciones no se piden en cascada sino a la vez: son cuatro `GET`
     * independientes y encadenarlas multiplicaría por cuatro la espera de una
     * página que un desconocido abre desde un enlace.
     */
    async cargarPerfil(username) {
      this.limpiar()
      this.username = username
      this.cargandoPerfil = true

      const respuesta = await publicGet(`/user/${encodeURIComponent(username)}`)

      if (respuesta.error) {
        this.errorPerfil =
          respuesta.error === 'user_not_found' ? 'no_existe' : mensajeDeError(respuesta.error)
        this.cargandoPerfil = false
        return
      }

      this.usuario = respuesta.user ?? null
      this.visible = respuesta.visible ?? {}
      this.valor = respuesta.value ?? null
      this.cargandoPerfil = false

      await Promise.all(
        SECCIONES.filter((s) => this.visible[s.clave]).map((s) => this.cargarSeccion(s.clave))
      )
    },

    /**
     * Una sección del perfil, ya sabiendo que el mapa `visible` la permite.
     *
     * Se vuelve a comprobar el 403 de todos modos: entre el perfil y la sección
     * hay dos peticiones, y el dueño puede haber cambiado su privacidad en
     * medio. El backend no se fía del `visible` que mandó antes, y aquí tampoco.
     */
    async cargarSeccion(clave) {
      const definicion = POR_CLAVE[clave]
      const seccion = this.secciones[clave]

      if (!definicion || seccion.cargando) {
        return
      }

      seccion.cargando = true
      seccion.error = null

      const respuesta = await publicGet(`/user/${encodeURIComponent(this.username)}${definicion.ruta}`)

      if (respuesta.error) {
        seccion.privada = respuesta.error === 'not_visible'
        seccion.error = seccion.privada ? null : mensajeDeError(respuesta.error)
        seccion.items = []
      } else {
        seccion.privada = false
        seccion.items = respuesta[definicion.lista] ?? []
        seccion.nextCursor = respuesta.nextCursor ?? null
      }

      seccion.cargando = false
    },

    /**
     * La página siguiente de una sección paginada por cursor.
     *
     * No hay centinela de scroll infinito detrás de esto y es deliberado: estas
     * rutas van limitadas a 60/min por IP, y un observador que dispara solo al
     * bajar por una colección de miles de cartas se come el límite del visitante
     * sin que él haya pedido nada. Lo pulsa quien quiere seguir viendo.
     */
    async cargarMas(clave) {
      const definicion = POR_CLAVE[clave]
      const seccion = this.secciones[clave]

      if (!definicion?.paginada || !seccion.nextCursor || seccion.cargandoMas || seccion.cargando) {
        return
      }

      seccion.cargandoMas = true

      const respuesta = await publicGet(
        `/user/${encodeURIComponent(this.username)}${definicion.ruta}`,
        { cursor: seccion.nextCursor }
      )

      if (respuesta.error) {
        // La página siguiente que falla no borra lo ya pintado: lo que hay
        // seguido siendo válido, y vaciarlo castigaría al visitante por un 429.
        seccion.error = respuesta.error === 'not_visible' ? null : mensajeDeError(respuesta.error)
        seccion.privada = respuesta.error === 'not_visible'
      } else {
        seccion.items.push(...(respuesta[definicion.lista] ?? []))
        seccion.nextCursor = respuesta.nextCursor ?? null
      }

      seccion.cargandoMas = false
    },

    /**
     * El mazo de un enlace compartido.
     *
     * No pregunta por ninguna privacidad ni necesita perfil: compartir un mazo
     * es un acto explícito sobre ese mazo. Y su único fallo es 404 —para un
     * token inválido, revocado o mal formado, los tres el mismo—, porque un 403
     * confirmaría que el token existe.
     */
    async cargarMazoCompartido(token) {
      this.mazo = null
      this.errorMazo = null
      this.cargandoMazo = true

      const respuesta = await publicGet(`/deck/${encodeURIComponent(token)}`)

      if (respuesta.error) {
        this.errorMazo =
          respuesta.error === 'deck_not_found' ? 'no_existe' : mensajeDeError(respuesta.error)
      } else {
        this.mazo = respuesta
      }

      this.cargandoMazo = false
    },

    /**
     * Deja el store como recién creado.
     *
     * Lo llaman las dos vistas al salir, y `cargarPerfil` al entrar: sin esto,
     * abrir el perfil de otra persona enseñaría durante un instante las cartas
     * de la anterior, que en una pantalla sobre privacidad es lo último que
     * puede pasar.
     */
    limpiar() {
      this.username = null
      this.usuario = null
      this.visible = {}
      this.valor = null
      this.cargandoPerfil = false
      this.errorPerfil = null
      this.secciones = seccionesVacias()
      this.mazo = null
      this.cargandoMazo = false
      this.errorMazo = null
    }
  }
})
