import { defineStore } from 'pinia'
import { apiCall, catalogGet } from '@/services/api'
import { CONDICIONES_DE_PEOR_A_MEJOR, ESTADOS_MAZO, ZONAS } from '@/constants/collection'

/**
 * El bloque de legalidad cuando no hay mazo o el backend no lo manda.
 *
 * Existe para que la vista pueda leer `legality.statuses` sin comprobar nada:
 * un `null` aquí obligaría a un `?.` en cada línea de la tabla.
 */
const LEGALIDAD_VACIA = {
  format: null,
  known: false,
  statuses: {},
  banned: 0,
  restricted: 0,
  notLegal: 0,
  size: 0,
  minSize: null,
  belowMinimum: false,
  formatosDisponibles: []
}

/**
 * Estado de los mazos del usuario.
 *
 * Va por `apiCall` (POST al endpoint único) para todo lo que es del mazo o de la
 * colección —son privados y necesitan credenciales— y por `catalogGet` solo para
 * el buscador embebido, que lee el catálogo público. Ni una URL escrita a mano:
 * las dos salen de `services/api.js`.
 *
 * Tres cosas que no son obvias y explican la forma del store:
 *
 *  1. **Las cartas se guardan en una lista PLANA (`cartas`) y las zonas son un
 *     getter.** `deck_get` devuelve las dos cosas (`boards` y `cards`), pero
 *     mantener el árbol sincronizado a mano tras cada edición es justo lo que
 *     hace que una fila fundida (`merged: true`) se quede en pantalla. Con una
 *     lista plana, fundir dos líneas es un `splice` y la vista se recalcula
 *     sola: **sin recargar**, que es lo que pide el hito.
 *  2. **El valor y la disponibilidad NO se calculan aquí.** `valueEur`,
 *     `priceEur`, `lineValue` y las líneas de `availability` vienen del backend,
 *     que une el precio por `(printing_uuid, finish)`. Tras cada escritura se
 *     pide el mazo otra vez en segundo plano (`refrescar()`), sin tocar los
 *     flags de carga, para que los números se pongan al día sin parpadeo.
 *  3. **El índice de la colección** (`indiceColeccion`) es lo que hace posible
 *     el modo «coge lo que tenga»: qué versiones tienes de cada carta, para
 *     resolver `finish`/`language`/`condition_grade` en el propio clic en vez de
 *     preguntarle al usuario. Se carga una vez por sesión de edición.
 */

/** Lo que pide el buscador embebido de golpe. Suficiente para una pantalla. */
const RESULTADOS_POR_PAGINA = 30

/** Páginas de colección que se traen como mucho al construir el índice. */
const PAGINAS_MAXIMAS_COLECCION = 40

/** Tamaño de página del índice de colección; el backend acota a 200. */
const COLECCION_POR_PAGINA = 200

export const useDeckStore = defineStore('deck', {
  state: () => ({
    // ---- Lista (`/decks`) --------------------------------------------------
    mazos: [],
    cargandoLista: false,
    errorLista: null,

    // ---- Ficha (`/deck/:id`) ----------------------------------------------
    mazo: null,
    cartas: [],
    valueEur: 0,
    availability: [],
    missing: 0,
    missingValueEur: 0,
    /** Conflictos de sobreasignación: son GLOBALES, no de este mazo. */
    conflicts: [],
    /**
     * Los avisos de legalidad del mazo, tal y como vienen en `deck_get`.
     *
     * `statuses` va por `oracle_id` y **solo trae lo que hay que marcar**: la
     * legalidad es de la carta, no de la edición, así que cuatro líneas de la
     * misma carta comparten marca. `known` dice si el formato existe de verdad
     * en `mtg_legality`; con `false` no se marca nada, porque un formato mal
     * tecleado dejaría las cien cartas en «no permitida».
     *
     * **No bloquea nada.** Un mazo ilegal es un mazo que existe: esto pinta un
     * aviso y ni un solo camino de este store depende de ello para guardar.
     *
     * Y `formatosDisponibles` es lo que llena el desplegable de formato de la
     * ficha: los de `mtg_format`, que destila la ingesta. Viaja aquí dentro y no
     * en una acción propia porque son 21 valores que ni paginan ni se comparten
     * por URL, y pedirlos aparte haría que el campo se llenara después de
     * pintarse. **Llega siempre**, incluso en el mazo sin formato: es justo al
     * que hay que dejarle elegir uno.
     */
    legality: { ...LEGALIDAD_VACIA },
    cargando: false,
    error: null,

    /**
     * El enlace público de ESTE mazo, `{ token, url }`, o `null`.
     *
     * **Solo dura lo que dura la visita**, y no es un olvido: `deck_get` no
     * devuelve `share_token` —la respuesta se compone en
     * `MySqlDeckRepository::COLUMNAS_MAZO` y esa columna no está—, así que al
     * recargar la página nadie sabe si el mazo seguía compartido. La interfaz
     * lo dice en vez de fingir que lo recuerda, y por eso «dejar de compartir»
     * se ofrece SIEMPRE: es idempotente en el backend y es lo único que mata un
     * enlace que ya no se tiene a la vista.
     */
    enlaceCompartido: null,
    /** Un `deck_share` o un `deck_unshare` en vuelo, para no doblar el clic. */
    compartiendo: false,

    /** Ids de línea con una edición en vuelo, para deshabilitar sus controles. */
    guardando: [],
    /** `printing_uuid` con un alta en vuelo, para no doblar el clic. */
    anadiendo: [],
    /** Último aviso, para el toast discreto de la vista. */
    aviso: null,

    // ---- Buscador embebido -------------------------------------------------
    consulta: '',
    resultados: [],
    cursorResultados: null,
    buscando: false,
    buscandoMas: false,
    /** Token de la búsqueda en curso, para descartar respuestas viejas. */
    peticionActual: 0,

    // ---- Lo que tienes en las cajas ---------------------------------------
    /** `printing_uuid` → líneas de colección de esa carta. */
    indiceColeccion: {},
    indiceListo: false,
    /** El índice se cortó por el tope de páginas: el clic cae al camino simple. */
    indiceParcial: false,
    cargandoIndice: false,

    /** `card_id` → versiones que tienes de esa carta (`deck_card_variants`). */
    variantes: {},
    cargandoVariantes: []
  }),

  getters: {
    /**
     * Las cartas agrupadas por zona, en el orden del ENUM y **solo las zonas que
     * tienen algo**: siete claves vacías son ruido que la vista tendría que
     * filtrar igualmente. Es el mismo criterio que `GetDeck` en el backend.
     */
    zonas: (state) =>
      ZONAS.map((zona) => ({
        ...zona,
        cartas: state.cartas.filter((c) => c.board === zona.value)
      })).filter((z) => z.cartas.length > 0),

    /** El tamaño del mazo, **sin tokens**: un token no es una carta del mazo. */
    totalCartas: (state) =>
      state.cartas.reduce((suma, c) => (c.board === 'tokens' ? suma : suma + c.count), 0),

    hayConflicto: (state) => state.conflicts.length > 0,

    /**
     * La marca de legalidad de una línea, o null si no hay nada que decir.
     *
     * Se resuelve por `oracleId` y no por línea porque la legalidad es de la
     * carta. Los tokens no se marcan: no se juegan ni se poseen, y el backend ya
     * los deja fuera del cruce.
     */
    legalidadDe: (state) => (carta) =>
      carta.board === 'tokens' ? null : state.legality.statuses?.[carta.oracleId] ?? null,

    /** Cuántas cartas distintas llevan aviso, para el resumen de la cabecera. */
    cartasConAviso: (state) =>
      state.legality.banned + state.legality.restricted + state.legality.notLegal,

    estaGuardando: (state) => (cardId) => state.guardando.includes(cardId),
    estaAnadiendo: (state) => (printingUuid) => state.anadiendo.includes(printingUuid),

    /**
     * Cuánto reclama **este mazo** de una versión concreta, sin contar tokens.
     */
    reclamadoPorEsteMazo: (state) => (printingUuid, finish, language, condition) =>
      state.cartas
        .filter(
          (c) =>
            c.board !== 'tokens' &&
            c.printingUuid === printingUuid &&
            c.finish === finish &&
            c.language === language &&
            c.condition === condition
        )
        .reduce((suma, c) => suma + c.count, 0),

    /**
     * Las versiones de una carta que **te quedan libres para este mazo**.
     *
     * Es lo que el buscador enseña en cada resultado. Ojo con lo que significa:
     * se descuenta lo que **este** mazo ya reclama, porque es la pregunta que se
     * está haciendo el usuario mientras monta («¿me queda alguna para meter?»).
     * El número global —lo que descuentan TODOS tus mazos construidos— lo da el
     * backend en `deck_card_variants`, y es el que sale en el desplegable de
     * cada línea, que es donde importa la verdad completa.
     */
    libresDe() {
      return (printingUuid) =>
        (this.indiceColeccion[printingUuid] || [])
          .map((linea) => ({
            ...linea,
            libres: Math.max(
              0,
              linea.quantity -
                this.reclamadoPorEsteMazo(
                  printingUuid,
                  linea.finish,
                  linea.language,
                  linea.condition
                )
            )
          }))
          .filter((linea) => linea.libres > 0)
    },

    /** Cuántas tienes en total de esa carta, en cualquier versión. */
    enColeccion: (state) => (printingUuid) =>
      (state.indiceColeccion[printingUuid] || []).reduce((suma, l) => suma + l.quantity, 0),

    totalLibres() {
      return (printingUuid) => this.libresDe(printingUuid).reduce((s, l) => s + l.libres, 0)
    },

    /**
     * Cuántos mazos hay de cada estado, para la fila del dashboard.
     *
     * Sale de la lista ya cargada (`deck_list`) y no de una acción nueva: los
     * tres números son un recuento de lo que ya está en memoria. Se devuelven
     * **los tres estados siempre**, también con 0, porque un hueco que aparece y
     * desaparece se lee peor que un cero.
     */
    porEstado: (state) =>
      ESTADOS_MAZO.map((estado) => ({
        ...estado,
        cuantos: state.mazos.filter((m) => m.status === estado.value).length
      })),

    /**
     * El valor de lo **construido**, en euros.
     *
     * Solo `built`: los tres estados no son simétricos y sumar los `building`
     * diría que tienes montado lo que todavía estás juntando. El `valueEur` de
     * cada mazo lo calcula el backend con el `LEFT JOIN` de siempre; aquí solo
     * se suman.
     */
    valorConstruido: (state) =>
      state.mazos
        .filter((m) => m.status === 'built')
        .reduce((suma, m) => suma + (m.valueEur ?? 0), 0),

    /** La línea de `availability` de una carta del mazo, si la hay. */
    disponibilidadDe: (state) => (carta) =>
      state.availability.find(
        (a) =>
          a.printingUuid === carta.printingUuid &&
          a.finish === carta.finish &&
          a.language === carta.language &&
          a.condition === carta.condition
      ) ?? null
  },

  actions: {
    // ======================================================================
    // Lista de mazos
    // ======================================================================

    async listar() {
      this.cargandoLista = true
      this.errorLista = null

      const respuesta = await apiCall('deck_list')

      if (respuesta.status !== 'success') {
        this.errorLista = respuesta.message || 'No se pudieron cargar tus mazos.'
        this.mazos = []
      } else {
        this.mazos = respuesta.data?.decks || []
        await this.cargarConflictos()
      }

      this.cargandoLista = false
    },

    /**
     * El aviso de sobreasignación de `/decks`.
     *
     * No hay acción propia para el análisis: viaja como `conflicts` dentro de
     * `deck_get`, y los conflictos son **globales** —vienen enteros aunque se
     * pregunte por un mazo, justamente para poder nombrar al otro implicado—.
     * Así que basta con preguntar por cualquier mazo construido: si no hay
     * ninguno no puede haber conflicto, porque solo `built` consume colección.
     */
    async cargarConflictos() {
      const construido = this.mazos.find((m) => m.status === 'built')

      if (!construido) {
        this.conflicts = []
        return
      }

      const respuesta = await apiCall('deck_get', { deck_id: construido.id })

      this.conflicts = respuesta.status === 'success' ? respuesta.data?.conflicts || [] : []
    },

    async crear(datos) {
      const respuesta = await apiCall('deck_create', datos)

      if (respuesta.status !== 'success') {
        this.aviso = { tipo: 'error', texto: respuesta.message || 'No se pudo crear el mazo.' }
        return null
      }

      const mazo = respuesta.data?.deck ?? null

      if (mazo) {
        this.mazos = [mazo, ...this.mazos]
      }

      this.aviso = { tipo: 'ok', texto: `Mazo «${mazo?.name}» creado.` }

      return mazo
    },

    /**
     * Edición **parcial**: se manda solo lo que se toca. El botón «desmontar»
     * manda únicamente `status`, y por eso no puede borrar el nombre.
     */
    async actualizar(deckId, campos) {
      const respuesta = await apiCall('deck_update', { deck_id: deckId, ...campos })

      if (respuesta.status !== 'success') {
        this.aviso = { tipo: 'error', texto: respuesta.message || 'No se pudo guardar el mazo.' }
        return false
      }

      const mazo = respuesta.data?.deck ?? null

      if (mazo) {
        const i = this.mazos.findIndex((m) => m.id === mazo.id)

        if (i !== -1) {
          this.mazos[i] = mazo
        }

        if (this.mazo?.id === mazo.id) {
          this.mazo = mazo
        }
      }

      this.aviso = { tipo: 'ok', texto: 'Mazo actualizado.' }

      // Cambiar de estado cambia quién consume la colección: un mazo que pasa a
      // `built` puede crear un conflicto y uno que se desmonta puede resolverlo.
      if (campos.status !== undefined) {
        await this.cargarConflictos()
      }

      // Y cambiar de formato cambia TODAS las marcas de legalidad de golpe: las
      // calcula el backend con un LEFT JOIN sobre `mtg_legality`, así que aquí
      // no se reinventan, se vuelven a pedir.
      if ((campos.status !== undefined || campos.format !== undefined) && this.mazo?.id === deckId) {
        await this.refrescar()
      }

      return true
    },

    /** El botón «desmontar» del aviso: un `deck_update` normal, nada más. */
    desmontar(deckId) {
      return this.actualizar(deckId, { status: 'dismantled' })
    },

    /**
     * Borrar un mazo. `conCartas` es lo único que separa «he deshecho la lista»
     * de «he vendido el mazo entero», así que nunca se manda por defecto.
     */
    async borrar(deckId, conCartas = false) {
      const respuesta = await apiCall('deck_delete', {
        deck_id: deckId,
        with_cards: conCartas
      })

      if (respuesta.status !== 'success') {
        this.aviso = { tipo: 'error', texto: respuesta.message || 'No se pudo borrar el mazo.' }
        return false
      }

      this.mazos = this.mazos.filter((m) => m.id !== deckId)

      const quitadas = respuesta.data?.removedFromCollection ?? 0
      const faltaban = respuesta.data?.shortfall?.length ?? 0

      this.aviso = {
        tipo: 'ok',
        texto: quitadas > 0
          ? `Mazo borrado y ${quitadas} ejemplar(es) descontados de tu colección` +
            (faltaban > 0 ? `; de ${faltaban} carta(s) tenías menos de las que pedía.` : '.')
          : 'Mazo borrado.'
      }

      return true
    },

    // ======================================================================
    // Ficha de un mazo
    // ======================================================================

    async cargar(deckId) {
      this.cargando = true
      this.error = null
      // El enlace es de UN mazo: pasar de `/deck/17` a `/deck/21` sin borrarlo
      // dejaría en pantalla, bajo el nombre del segundo, la URL que publica el
      // primero. Es el mismo motivo por el que `stores/publicProfile.js` se
      // limpia al entrar y no solo al salir.
      this.enlaceCompartido = null

      const ok = await this.refrescar(deckId)

      if (!ok) {
        this.mazo = null
        this.cartas = []
      }

      this.cargando = false

      return ok
    },

    /**
     * Vuelve a pedir el mazo **sin tocar los flags de carga**.
     *
     * Se llama después de cada escritura: la tabla ya se ha actualizado en local
     * (por eso la fila fundida desaparece al instante), y esto solo pone al día
     * el valor en euros y la disponibilidad, que los calcula el backend y aquí
     * no se reinventan.
     */
    async refrescar(deckId = null) {
      const id = deckId ?? this.mazo?.id

      if (!id) {
        return false
      }

      const respuesta = await apiCall('deck_get', { deck_id: id })

      if (respuesta.status !== 'success') {
        this.error = respuesta.message || 'No se pudo cargar el mazo.'
        return false
      }

      const datos = respuesta.data || {}

      this.mazo = datos.deck ?? null
      this.cartas = datos.cards || []
      this.valueEur = datos.valueEur ?? 0
      this.availability = datos.availability || []
      this.missing = datos.missing ?? 0
      this.missingValueEur = datos.missingValueEur ?? 0
      this.conflicts = datos.conflicts || []
      // Se FUNDE sobre los valores vacíos en vez de sustituirlos: el bloque trae
      // hoy diez claves y la vista las lee todas sin comprobar ninguna, así que
      // una respuesta a la que le falte una —un backend más viejo, una fixtura
      // capturada antes— dejaría un `undefined` donde se espera una lista.
      this.legality = { ...LEGALIDAD_VACIA, ...(datos.legality || {}) }

      return true
    },

    // ======================================================================
    // El enlace público del mazo
    // ======================================================================

    /**
     * **Compartir el mazo por enlace**, o regenerarlo.
     *
     * Dos cosas que no se ven en estas quince líneas y mandan sobre todo lo
     * demás:
     *
     *  - **Llamarlo otra vez REGENERA**, y el enlace anterior muere en el acto
     *    (`ShareDeck.php:26-31`): `mtg_deck.share_token` es una columna, no una
     *    tabla de enlaces. Por eso la vista no ofrece «compartir» como si fuera
     *    inofensivo cuando ya hay un enlace a la vista.
     *  - **La URL absoluta se compone AQUÍ.** El backend devuelve la relativa
     *    (`/#/shared/deck/<token>`, `ShareDeck::RUTA_PUBLICA`) a propósito: lo
     *    único que tendría para adivinar el origen es la cabecera `Host`, que la
     *    manda el cliente, y componerla allí dejaría que un atacante eligiera el
     *    dominio del enlace que el usuario va a copiar y pegar. Quien conoce su
     *    propio origen es el navegador.
     *
     * El `/#/` de la ruta relativa no es decorativo: el router va con
     * `createWebHashHistory` y sin la almohadilla el enlace no resuelve.
     *
     * @returns {Promise<{token: string, url: string}|null>}
     */
    async compartir(deckId) {
      this.compartiendo = true

      const respuesta = await apiCall('deck_share', { deck_id: deckId })

      this.compartiendo = false

      if (respuesta.status !== 'success') {
        this.aviso = { tipo: 'error', texto: respuesta.message || 'No se pudo compartir el mazo.' }
        return null
      }

      const datos = respuesta.data || {}

      // Sin token no hay enlace que enseñar, y `${origin}undefined` sería una
      // URL rota pintada como si fuera buena: peor que decir que falló.
      if (!datos.shareToken || !datos.url) {
        this.aviso = { tipo: 'error', texto: 'El backend no devolvió ningún enlace.' }
        return null
      }

      this.enlaceCompartido = {
        token: datos.shareToken,
        url: `${window.location.origin}${datos.url}`
      }

      this.aviso = {
        tipo: 'ok',
        texto: 'Enlace creado: quien lo tenga verá el mazo sin necesitar cuenta.'
      }

      return this.enlaceCompartido
    },

    /**
     * **Dejar de compartir**: el token vuelve a `NULL` y el enlace deja de
     * resolver. Medido contra el backend de dev: la ruta pública pasa de 200 a
     * 404 con el mismo token.
     *
     * Es **idempotente** (`UnshareDeck.php`, `DeckController.php:411-417`):
     * revocar lo que no estaba compartido responde que sí. Eso es lo que permite
     * ofrecer el botón aunque no haya ningún enlace en pantalla, que es el caso
     * de después de recargar — el único momento en el que de verdad hace falta.
     */
    async dejarDeCompartir(deckId) {
      this.compartiendo = true

      const respuesta = await apiCall('deck_unshare', { deck_id: deckId })

      this.compartiendo = false

      if (respuesta.status !== 'success') {
        this.aviso = {
          tipo: 'error',
          texto: respuesta.message || 'No se pudo dejar de compartir el mazo.'
        }
        return false
      }

      this.enlaceCompartido = null

      this.aviso = {
        tipo: 'ok',
        texto: 'El enlace ya no funciona. Quien lo tuviera guardado verá una página que no existe.'
      }

      return true
    },

    /** Deja la ficha limpia al salir, para que la siguiente no herede nada. */
    limpiarFicha() {
      this.mazo = null
      this.cartas = []
      this.valueEur = 0
      this.availability = []
      this.missing = 0
      this.missingValueEur = 0
      this.conflicts = []
      this.legality = { ...LEGALIDAD_VACIA }
      this.enlaceCompartido = null
      this.resultados = []
      this.consulta = ''
      this.cursorResultados = null
      this.variantes = {}
      this.error = null
    },

    // ======================================================================
    // El buscador embebido — catálogo público, sin credenciales
    // ======================================================================

    async buscarCartas(texto) {
      this.consulta = texto

      if (texto.trim().length < 2) {
        this.resultados = []
        this.cursorResultados = null
        this.buscando = false
        return
      }

      const miPeticion = ++this.peticionActual
      this.buscando = true

      const respuesta = await catalogGet('/cards', {
        q: texto,
        limit: RESULTADOS_POR_PAGINA,
        sort: 'relevance'
      })

      // Teclear deja varias peticiones en vuelo; sin esto la más lenta pisaría
      // a la más reciente. Mismo criterio que el store del catálogo.
      if (miPeticion !== this.peticionActual) {
        return
      }

      this.resultados = respuesta.error ? [] : respuesta.items || []
      this.cursorResultados = respuesta.error ? null : respuesta.nextCursor ?? null
      this.buscando = false
    },

    async masResultados() {
      if (!this.cursorResultados || this.buscando || this.buscandoMas) {
        return
      }

      const miPeticion = this.peticionActual
      this.buscandoMas = true

      const respuesta = await catalogGet('/cards', {
        q: this.consulta,
        limit: RESULTADOS_POR_PAGINA,
        sort: 'relevance',
        cursor: this.cursorResultados
      })

      if (miPeticion !== this.peticionActual) {
        this.buscandoMas = false
        return
      }

      if (!respuesta.error) {
        this.resultados.push(...(respuesta.items || []))
        this.cursorResultados = respuesta.nextCursor ?? null
      }

      this.buscandoMas = false
    },

    // ======================================================================
    // Lo que tienes en las cajas
    // ======================================================================

    /**
     * Construye el índice de la colección: `printing_uuid` → versiones.
     *
     * Se pide entera por `collection_list` paginando por cursor, igual que la
     * vista de colección, porque el modo «coge lo que tenga» necesita saber qué
     * versiones tienes **antes** de que el usuario pulse: si hubiera que
     * preguntarlo carta a carta, el clic dejaría de ser un clic.
     *
     * El tope de páginas existe para que una colección enorme no convierta la
     * entrada al editor en cincuenta peticiones. Si se corta, `indiceParcial`
     * queda a `true` y el clic cae al camino simple —añadir con los valores por
     * defecto del backend—, que sigue siendo un clic y una carta.
     */
    async cargarIndiceColeccion() {
      if (this.indiceListo || this.cargandoIndice) {
        return
      }

      this.cargandoIndice = true

      const indice = {}
      let cursor = null
      let pagina = 0

      do {
        const respuesta = await apiCall('collection_list', {
          limit: COLECCION_POR_PAGINA,
          sort: 'name',
          ...(cursor ? { cursor } : {})
        })

        if (respuesta.status !== 'success') {
          this.cargandoIndice = false
          this.indiceParcial = true
          return
        }

        for (const item of respuesta.data?.items || []) {
          indice[item.printingUuid] = indice[item.printingUuid] || []
          indice[item.printingUuid].push({
            finish: item.finish,
            language: item.language,
            condition: item.condition,
            quantity: item.quantity,
            priceEur: item.priceEur
          })
        }

        cursor = respuesta.data?.nextCursor ?? null
        pagina += 1
      } while (cursor && pagina < PAGINAS_MAXIMAS_COLECCION)

      this.indiceColeccion = indice
      this.indiceParcial = cursor !== null
      this.indiceListo = true
      this.cargandoIndice = false
    },

    // ======================================================================
    // Añadir: un clic
    // ======================================================================

    /**
     * **El modo «coge lo que tenga»**, que es el diseño adoptado en M0.
     *
     * Un clic sobre un resultado del buscador y la carta está en el mazo: el
     * acabado, el idioma y el estado se resuelven solos desde la colección,
     * **primero lo que esté libre y en peor estado** —las cartas buenas se
     * guardan y las jugadas se juegan—, y se generan **varias líneas** si hace
     * falta para llegar a la cantidad pedida.
     *
     * Si no tienes ninguna (o el índice no está), se añade igual con los valores
     * por defecto del backend: el mazo puede pedir cartas que aún no tienes, y
     * el cruce con la colección ya dirá «te falta 1».
     */
    async anadirResuelto(printingUuid, cantidad = 1, board = 'main') {
      if (!printingUuid || this.anadiendo.includes(printingUuid)) {
        return false
      }

      const reparto = this.repartir(printingUuid, cantidad)

      this.anadiendo = [...this.anadiendo, printingUuid]

      let ok = true

      for (const parte of reparto) {
        ok = (await this.enviarAlta({ printing_uuid: printingUuid, board, ...parte })) && ok
      }

      this.anadiendo = this.anadiendo.filter((x) => x !== printingUuid)

      if (ok) {
        const nombre = this.cartas.find((c) => c.printingUuid === printingUuid)?.name || 'La carta'

        this.aviso = {
          tipo: 'ok',
          texto: reparto.length > 1
            ? `${nombre}: ${cantidad} copias repartidas en ${reparto.length} versiones tuyas.`
            : `${nombre} añadida al mazo.`
        }

        await this.refrescar()
      }

      return ok
    },

    /**
     * Reparte `cantidad` copias entre las versiones que te quedan libres,
     * **de peor a mejor estado** y, a igualdad de estado, la más barata primero.
     * Lo que no llegue a cubrirse se pide con los valores por defecto: el mazo
     * declara lo que quiere llevar aunque no lo tengas todavía.
     *
     * @returns {Array<object>} partes con `count` y, si se resolvieron, las tres
     *          dimensiones
     */
    repartir(printingUuid, cantidad) {
      const candidatas = this.libresDe(printingUuid).sort((a, b) => {
        const porEstado =
          CONDICIONES_DE_PEOR_A_MEJOR.indexOf(a.condition) -
          CONDICIONES_DE_PEOR_A_MEJOR.indexOf(b.condition)

        if (porEstado !== 0) {
          return porEstado
        }

        return (a.priceEur ?? 0) - (b.priceEur ?? 0)
      })

      const partes = []
      let pendientes = cantidad

      for (const candidata of candidatas) {
        if (pendientes <= 0) {
          break
        }

        const cuantas = Math.min(candidata.libres, pendientes)

        partes.push({
          finish: candidata.finish,
          language: candidata.language,
          condition_grade: candidata.condition,
          count: cuantas
        })

        pendientes -= cuantas
      }

      if (pendientes > 0) {
        partes.push({ count: pendientes })
      }

      return partes
    },

    /** El camino de «Opciones»: las cinco dimensiones, dichas a mano. */
    async anadirConOpciones(printingUuid, opciones) {
      if (!printingUuid || this.anadiendo.includes(printingUuid)) {
        return false
      }

      this.anadiendo = [...this.anadiendo, printingUuid]

      const ok = await this.enviarAlta({ printing_uuid: printingUuid, ...opciones })

      this.anadiendo = this.anadiendo.filter((x) => x !== printingUuid)

      if (ok) {
        this.aviso = { tipo: 'ok', texto: 'Carta añadida al mazo.' }
        await this.refrescar()
      }

      return ok
    },

    /**
     * El alta en sí. Va por el MISMO sitio desde los dos caminos —el clic y
     * «Opciones»— a propósito: con dos llamadas distintas podrían divergir sin
     * que nadie se enterase.
     *
     * La línea se inserta en local antes del refresco para que la tabla responda
     * al instante; repetir la misma carta **suma** en el servidor, así que aquí
     * se reemplaza la fila por la que vuelve, nunca se duplica.
     */
    async enviarAlta(datos) {
      const respuesta = await apiCall('deck_card_add', { deck_id: this.mazo?.id, ...datos })

      if (respuesta.status !== 'success') {
        this.aviso = { tipo: 'error', texto: respuesta.message || 'No se pudo añadir la carta.' }
        return false
      }

      const carta = respuesta.data?.card ?? null

      if (carta) {
        const i = this.cartas.findIndex((c) => c.id === carta.id)

        if (i === -1) {
          this.cartas.push(carta)
        } else {
          this.cartas[i] = carta
        }
      }

      return true
    },

    // ======================================================================
    // Editar líneas del mazo
    // ======================================================================

    /** **Cero borra la línea**, igual que la cantidad de la colección. */
    async fijarCantidad(carta, cantidad) {
      if (cantidad === carta.count) {
        return true
      }

      this.marcarGuardando(carta.id, true)

      const respuesta = await apiCall('deck_card_set', {
        deck_id: this.mazo?.id,
        card_id: carta.id,
        count: cantidad
      })

      this.marcarGuardando(carta.id, false)

      if (respuesta.status !== 'success') {
        this.aviso = { tipo: 'error', texto: respuesta.message || 'No se pudo guardar la cantidad.' }
        return false
      }

      if (respuesta.data?.removed) {
        this.cartas = this.cartas.filter((c) => c.id !== carta.id)
        this.aviso = { tipo: 'ok', texto: `${carta.name} ya no está en el mazo.` }
      } else {
        this.reemplazar(carta.id, respuesta.data?.card)
      }

      await this.refrescar()

      return true
    },

    /** Quitar la línea entera. **No toca la colección**: la carta sigue siendo tuya. */
    async quitar(carta) {
      this.marcarGuardando(carta.id, true)

      const respuesta = await apiCall('deck_card_remove', {
        deck_id: this.mazo?.id,
        card_id: carta.id
      })

      this.marcarGuardando(carta.id, false)

      if (respuesta.status !== 'success') {
        this.aviso = { tipo: 'error', texto: respuesta.message || 'No se pudo quitar la carta.' }
        return false
      }

      this.cartas = this.cartas.filter((c) => c.id !== carta.id)
      this.aviso = { tipo: 'ok', texto: `${carta.name} fuera del mazo.` }

      await this.refrescar()

      return true
    },

    /**
     * Las versiones que tienes **de verdad** de esa carta, para el desplegable.
     *
     * Es lo que convierte cambiar de versión en un clic: se pide al abrir el
     * desplegable y no antes, porque una tabla de 40 líneas serían 40 peticiones
     * de algo que casi nadie mira. `free` viene del backend y cuenta lo que
     * reclaman **todos** tus mazos construidos, que es la verdad completa.
     */
    async cargarVariantes(carta) {
      if (this.variantes[carta.id] || this.cargandoVariantes.includes(carta.id)) {
        return
      }

      this.cargandoVariantes = [...this.cargandoVariantes, carta.id]

      const respuesta = await apiCall('deck_card_variants', {
        deck_id: this.mazo?.id,
        card_id: carta.id
      })

      this.cargandoVariantes = this.cargandoVariantes.filter((x) => x !== carta.id)

      if (respuesta.status === 'success') {
        this.variantes = { ...this.variantes, [carta.id]: respuesta.data?.variants || [] }
      }
    },

    /**
     * Cambiar qué versión pide el mazo. **Puede fundir dos líneas.**
     *
     * Las cuatro columnas están dentro de `uq_deck_card`, así que el cambio no
     * modifica la fila: la mueve, y el destino puede estar ya ocupado por otra
     * línea del mismo mazo. Cuando lo está (`merged: true`) el `id` que vuelve
     * **no es el que se mandó**, y la tabla tiene que quedarse con una fila
     * menos sin recargar: por eso se quita siempre la de origen y se coloca la
     * que devuelve el backend.
     *
     * **No mueve ni una carta de la colección**: cambia lo que el mazo reclama.
     */
    async cambiarVersion(carta, version) {
      const igual =
        version.finish === carta.finish &&
        version.language === carta.language &&
        version.condition_grade === carta.condition &&
        (version.board ?? carta.board) === carta.board

      if (igual) {
        return true
      }

      this.marcarGuardando(carta.id, true)

      const respuesta = await apiCall('deck_card_change', {
        deck_id: this.mazo?.id,
        card_id: carta.id,
        ...version
      })

      this.marcarGuardando(carta.id, false)

      if (respuesta.status !== 'success') {
        this.aviso = { tipo: 'error', texto: respuesta.message || 'No se pudo cambiar la versión.' }
        return false
      }

      const nueva = respuesta.data?.card ?? null
      const posicion = this.cartas.findIndex((c) => c.id === carta.id)

      if (posicion !== -1) {
        this.cartas.splice(posicion, 1)
      }

      if (nueva) {
        const yaEstaba = this.cartas.findIndex((c) => c.id === nueva.id)

        if (yaEstaba !== -1) {
          this.cartas[yaEstaba] = nueva
        } else if (posicion !== -1) {
          // Se reinserta donde estaba: reordenar la tabla entera movería filas
          // bajo el dedo del usuario en mitad de una edición.
          this.cartas.splice(posicion, 0, nueva)
        } else {
          this.cartas.push(nueva)
        }
      }

      // Las versiones cacheadas son de la línea vieja; con el `id` movido ya no
      // valen, y las de la nueva se piden cuando se vuelva a abrir.
      const cache = { ...this.variantes }
      delete cache[carta.id]
      this.variantes = cache

      this.aviso = respuesta.data?.merged
        ? { tipo: 'ok', texto: `${carta.name}: unida a la línea que ya tenías de esa versión.` }
        : { tipo: 'ok', texto: 'Versión cambiada.' }

      await this.refrescar()

      return true
    },

    reemplazar(id, carta) {
      if (!carta) {
        return
      }

      const i = this.cartas.findIndex((c) => c.id === id)

      if (i !== -1) {
        this.cartas[i] = carta
      }
    },

    marcarGuardando(id, activo) {
      this.guardando = activo
        ? [...this.guardando, id]
        : this.guardando.filter((x) => x !== id)
    }
  }
})
