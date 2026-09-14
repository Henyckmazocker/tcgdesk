import { defineStore } from 'pinia'
import { apiCall } from '@/services/api'

/**
 * Tu lista: amigos, solicitudes recibidas, solicitudes enviadas y a quién sigues.
 *
 * Tres cosas lo separan de `stores/publicProfile.js`, que es el store vecino y de
 * quien más se parece:
 *
 *  - **Va por `apiCall` y NUNCA por `publicGet`.** Aquel lee rutas públicas
 *    porque sirve a gente sin cuenta; esto es lo contrario: `/friends` está
 *    **tras el guard** del router y todo lo que hay aquí es sobre uno mismo. Las
 *    ocho acciones llevan `AuthMiddleware` y el `user_id` sale de la sesión, no
 *    del cuerpo. Copiar aquel `publicGet` mandaría estas acciones por una vía
 *    sin cookie que el backend contestaría con un 401.
 *  - **Dos listas que son DOS RELACIONES DISTINTAS, y por eso son dos llamadas.**
 *    `friend_list` y `follow_list` están en dos tablas y en dos controllers a
 *    propósito: la amistad es recíproca, hay que aceptarla y **concede acceso al
 *    nivel `friends`**; seguir es unilateral, no se pide y **no concede nada**.
 *    Aquí conviven en un store porque las pinta la misma pantalla, pero no se
 *    funden en una sola lista ni en un solo contador: el día que algo de este
 *    fichero mezcle `amigos` con `siguiendo`, el nivel `friends` habrá empezado
 *    a significar «cualquiera que pulse seguir».
 *  - **`pendientes` es un contador que vive fuera de `/friends`.** Lo pinta la
 *    portada (`views/HomeView.vue`), que es la navegación real de esta app, así
 *    que el store tiene que poder cargarse **sin** que la vista de amigos esté
 *    montada. De ahí `cargarContador()`, que pide solo `friend_list`: pedir
 *    también los seguimientos desde la portada sería una petición para un número
 *    que nadie enseña ahí.
 *
 * **`pedir()` y `seguir()` viven aquí desde el M5, y las llama una vista que NO
 * es `/friends`.** Las dos acciones reciben un `username` y el único sitio donde
 * se conoce el `username` de otra persona es su perfil público, así que quien
 * las dispara es `views/PublicProfileView.vue`. Están en este store y no en
 * `stores/publicProfile.js` por lo mismo que todo lo demás de aquí: aquel lee
 * rutas públicas por `publicGet` y **no toca la sesión en ninguna línea**,
 * mientras que `friend_request` y `follow_add` son acciones `POST` con
 * `AuthMiddleware` y `CsrfMiddleware`. Meterlas allí obligaría a aquel store a
 * saber si hay sesión, que es exactamente lo que su cabecera dice que no hace.
 *
 * **Y el perfil público necesita saber en qué relación estás con esa persona,
 * cosa que ninguna acción del backend responde.** `GET /api/public/user/{u}` no
 * trae la relación —es una ruta sin sesión, pública y cacheable a propósito— y
 * `friend_list` / `follow_list` devuelven TUS listas enteras, no tu estado
 * respecto a alguien concreto. La salida es `relacionCon(username)`: **el cruce
 * lo hace el cliente sobre las dos listas que ya se piden**, que es el mismo
 * precedente que el `CLAUDE.md` del repo dejó escrito al rechazar anotar el
 * catálogo con la lista de deseos («si vuelves a necesitar el catálogo pero
 * sabiendo algo de mí, la respuesta es una acción `POST` normal, no una ruta
 * `GET` nueva»). Añadir la relación a la ruta pública habría sido meter dato de
 * sesión en la única ruta de la app cuyo valor entero es no tenerlo.
 *
 * **Y `buscar()` vive aquí desde el M6, que es el buscador de `/friends`.** Está
 * en este store y no en uno propio por una razón concreta: lo único que se hace
 * con un resultado de la búsqueda es **pedirle amistad**, y eso son `pedir()` y
 * `relacionCon()`, que ya están aquí. Un store aparte tendría que importar este
 * para pintar el botón de cada fila, y entonces serían dos stores acoplados en
 * vez de uno con cinco campos más. Además el resultado se lee **cruzado con las
 * listas**: quien ya es tu amigo no sale con «Pedir amistad» sino con «Ya sois
 * amigos», y ese cruce es el mismo `relacionCon()` del M5.
 *
 * **`user_search` es la novena acción y la única que no habla de una relación.**
 * No escribe una fila, no crea nada que nadie tenga que resolver y su respuesta
 * es la misma para todo el mundo salvo por quién la pide (que se excluye a sí
 * mismo); va por `apiCall` como las otras ocho, y **no por `publicGet`**: lleva
 * `AuthMiddleware`, porque un buscador de personas abierto a internet sería el
 * directorio que la sexta columna de privacidad existe para no publicar.
 *
 * **Ninguna respuesta de este store trae un `email`.** El backend compone los dos
 * listados por lista blanca con un `JOIN users` que ni siquiera lo selecciona, y
 * tampoco viaja el `id` numérico de ninguna persona: se navega a su perfil por
 * `username`, que es la clave pública del proyecto. Si algún día llegara un
 * `email`, el fallo estaría en el backend y hay un test que lo canta.
 */

/**
 * Lo que se dice cuando no se pudo averiguar la relación con alguien.
 *
 * Se dice y no se calla porque la pantalla deja de ofrecer los botones: sin este
 * texto, el hueco donde estaban se leería como «con esta persona no se puede
 * hacer nada», que es una afirmación distinta y falsa.
 */
const MENSAJE_SIN_RELACION = 'No se pudo comprobar tu relación con esta persona.'

/** El estado inicial de las cuatro listas y sus contadores. */
function listasVacias() {
  return {
    amigos: [],
    recibidas: [],
    enviadas: [],
    siguiendo: [],
    /** Los tres que manda el backend en `counts`. */
    contadores: { friends: 0, pending: 0, sent: 0 },
    /** Cuánta gente te sigue. Un número, nunca una lista: ver `ListarSeguimientos`. */
    seguidores: 0
  }
}

export const useFriendsStore = defineStore('friends', {
  state: () => ({
    ...listasVacias(),

    /** Carga de `friend_list` + `follow_list` al entrar en `/friends`. */
    cargando: false,
    /** Fallo del listado de amistades, ya traducido. `null` si no lo hubo. */
    error: null,
    /**
     * Fallo del listado de seguimientos, **aparte del anterior**.
     *
     * Son dos peticiones independientes y una puede fallar sin la otra. Con un
     * solo `error`, un 429 sobre `follow_list` borraría el aviso de un fallo de
     * amistades —o al revés— y la pantalla diría que no se pudo cargar nada
     * cuando media pantalla está cargada perfectamente.
     */
    errorSeguidos: null,

    /**
     * `friendshipId` con una acción en vuelo, para deshabilitar sus botones.
     *
     * Es lo que impide el doble clic sobre «Aceptar»: la segunda llamada llegaría
     * con la fila ya en `accepted` y el backend contestaría un 409 que el usuario
     * no entendería, después de haber hecho lo que pedía.
     */
    moviendo: [],
    /** `username` con un `follow_remove` en vuelo. Indexa por nombre: no hay id. */
    soltando: [],

    /**
     * `username` con un `friend_request` en vuelo.
     *
     * Aparte de `moviendo` porque **todavía no hay `friendshipId` que poner ahí**:
     * la fila la crea justo esta llamada. Sin esta guarda, el doble clic sobre
     * «Pedir amistad» manda dos `friend_request` y el segundo choca contra el
     * `UNIQUE` simétrico de la tabla: un 409 «ya existe una solicitud con esa
     * persona» inmediatamente después de haber hecho lo que el usuario pedía.
     */
    pidiendo: [],
    /**
     * `username` con un `follow_add` en vuelo.
     *
     * Aparte de `soltando` porque son las dos mitades de un mismo botón y el
     * nombre de aquel miente sobre esta: el perfil público las junta en un solo
     * getter (`seguimientoOcupado`). Aquí el doble clic no rompería nada —seguir
     * es idempotente y devuelve 200— pero dejaría el botón vivo mientras la
     * petición viaja, que es cómo se pide dos veces sin querer.
     */
    marcando: [],

    /** Último aviso para el toast de la vista: `{tipo: 'ok'|'error', texto}`. */
    aviso: null,

    /**
     * Ya se cargó la lista alguna vez en esta navegación.
     *
     * Lo mira el contador de la portada para no volver a pedir `friend_list` cada
     * vez que se entra en `/`. No caduca a propósito: quien mueve una solicitud
     * lo hace desde aquí y actualiza los contadores en el sitio, así que el único
     * dato que puede quedarse viejo es una solicitud que alguien te mandó
     * mientras mirabas otra pantalla — y eso se ve al entrar en `/friends`, que
     * es exactamente lo que el plan dice que pasa: no hay notificaciones.
     */
    cargado: false,

    /**
     * Lo mismo para `follow_list`, y **es una bandera aparte de `cargado`**.
     *
     * Son dos peticiones distintas y hay un camino que pide una sin la otra:
     * `cargarContador()` trae solo `friend_list` desde la portada. Con una sola
     * bandera, entrar en la portada y luego en un perfil público dejaría
     * `siguiendo` vacío dando `cargado === true` por bueno, y el perfil pintaría
     * «Seguir» sobre alguien a quien ya sigues.
     */
    cargadoSeguidos: false,

    /**
     * Hay una carga de relación en vuelo (la que dispara el perfil público).
     *
     * No reutiliza `cargando`, que es el de `/friends`: aquella pinta esqueletos
     * en toda la pantalla y esta solo en el par de botones de la cabecera.
     */
    cargandoRelacion: false,

    /**
     * No se pudo averiguar en qué relación estás con la persona del perfil.
     *
     * Existe porque la alternativa es peor: sin saberlo, los botones caerían en
     * el estado «nada» —que es el que se pinta por defecto— y ofrecerían «Pedir
     * amistad» a quien ya es tu amigo. **Mientras esto no sea `null`, el perfil
     * no pinta ningún botón de relación**: decir que no se sabe es honesto y
     * pintar el botón equivocado no lo es. Y es un error aparte de `error` y de
     * `errorSeguidos` porque aquí las dos listas son **una sola pregunta** —«¿qué
     * soy de esta persona?»— y media respuesta no sirve para contestarla.
     */
    errorRelacion: null,

    // ====================================================================
    // EL BUSCADOR (M6). Cinco campos, y ninguno se mezcla con las listas.
    // ====================================================================

    /**
     * Lo último que se buscó, ya recortado. Se guarda para poder decir «nadie
     * empieza por “juan”» en vez de «no hay resultados», que no dice de qué.
     */
    consulta: '',

    /**
     * Las personas encontradas: `{username, displayName, avatarUrl}`.
     *
     * **No se mezclan con `amigos` ni con `siguiendo` en ninguna línea**, por lo
     * mismo que aquellas dos no se mezclan entre sí: un resultado de búsqueda no
     * es una relación. Lo que sí se hace es LEERLO cruzado con ellas
     * (`relacionCon`), para no ofrecer «Pedir amistad» a quien ya es tu amigo.
     */
    resultados: [],

    /** Hay un `user_search` en vuelo. Deshabilita el botón y pinta el esqueleto. */
    buscando: false,

    /**
     * Fallo de la búsqueda, **aparte de `error` y de `errorSeguidos`**.
     *
     * Es donde aterriza el **422 de «escribe al menos 3 caracteres»**, que es el
     * caso más frecuente de todos: se escribe mientras se teclea. Con un `error`
     * compartido, ese aviso taparía el de «no se pudieron cargar tus amistades»
     * y diría que falló media pantalla que está perfectamente cargada.
     */
    errorBusqueda: null,

    /**
     * Ya se buscó algo alguna vez en esta navegación.
     *
     * Distingue «no hay nadie que empiece por eso» de «todavía no has buscado
     * nada», que se ven igual —una lista vacía— y significan cosas distintas.
     */
    busquedaHecha: false
  }),

  getters: {
    /**
     * **El contador de la barra**: cuántas solicitudes esperan respuesta TUYA.
     *
     * Son las `pending` y solo ellas. Las `sent` no cuentan: están esperando a la
     * otra persona y no hay nada que hacer con ellas, así que sumarlas pondría un
     * número rojo sobre algo que no te pide nada.
     */
    pendientes: (state) => state.contadores.pending ?? 0,

    /** ¿Hay algo que enseñar? Decide entre las listas y el estado vacío. */
    vacio: (state) =>
      state.amigos.length === 0 &&
      state.recibidas.length === 0 &&
      state.enviadas.length === 0 &&
      state.siguiendo.length === 0,

    /** ¿Esta fila tiene una acción en vuelo? Deshabilita sus dos botones. */
    estaMoviendo: (state) => (friendshipId) => state.moviendo.includes(friendshipId),
    /** ¿Este seguimiento tiene un `follow_remove` en vuelo? */
    estaSoltando: (state) => (username) => state.soltando.includes(username),

    /**
     * **En qué relación estás con una persona concreta, deducido de tus listas.**
     *
     * Es la pregunta que el perfil público tiene que contestar y que **ninguna
     * acción del backend responde**: `friend_list` y `follow_list` traen tus
     * listas enteras, y el cruce por `username` lo hace el cliente. El porqué de
     * resolverlo así —y no añadiendo la relación a la ruta pública ni abriendo
     * una acción nueva— está en la cabecera de este fichero.
     *
     * Devuelve **dos campos independientes, y esa es toda la garantía de que no
     * salgan dos botones contradictorios**:
     *
     *  - `amistad` es UN valor de cuatro (`'amigos'`, `'recibida'`, `'enviada'`,
     *    `'nada'`), así que la vista lo pinta con una cadena `v-if` / `v-else-if`
     *    y no hay forma de que salgan «Pedir amistad» y «Quitar amistad» a la
     *    vez. El orden del `??` no es cosmético: el backend no puede devolver a
     *    la misma persona en dos listas —hay una sola fila por pareja y un solo
     *    `status`—, pero si algún día lo hiciera, el estado que manda es el que
     *    más acceso concede, porque decir de menos sobre un permiso miente.
     *  - `siguiendo` es un booleano **y no un quinto valor de `amistad`**. Seguir
     *    y ser amigo son dos relaciones ortogonales: se puede seguir a un amigo,
     *    a un desconocido, o a nadie. Fundirlas en un selector de cinco estados
     *    sería decir que seguir es un grado de amistad, y **seguir no da ningún
     *    acceso**.
     *
     * `fila` es la entrada del listado con su `friendshipId`, que es lo que
     * necesitan `aceptar`, `rechazar`, `retirar` y `deshacer`. `null` cuando no
     * hay ninguna fila entre los dos.
     */
    relacionCon: (state) => (username) => {
      if (!username) {
        return { amistad: 'nada', fila: null, siguiendo: false }
      }

      const esEl = (f) => f?.user?.username === username

      const amigo = state.amigos.find(esEl) ?? null
      const recibida = state.recibidas.find(esEl) ?? null
      const enviada = state.enviadas.find(esEl) ?? null

      let amistad = 'nada'
      if (amigo) {
        amistad = 'amigos'
      } else if (recibida) {
        amistad = 'recibida'
      } else if (enviada) {
        amistad = 'enviada'
      }

      return {
        amistad,
        fila: amigo ?? recibida ?? enviada,
        siguiendo: state.siguiendo.some((f) => f?.username === username)
      }
    },

    /**
     * ¿Hay una acción de AMISTAD en vuelo contra esta persona?
     *
     * Junta las dos guardas que existen porque el botón es uno solo: `pidiendo`
     * indexa por nombre (la fila aún no existe) y `moviendo` por `friendshipId`
     * (ya existe). Va como getter con `function` y no con flecha porque necesita
     * `this` para leer `relacionCon`.
     */
    amistadOcupada() {
      return (username) => {
        if (!username) {
          return false
        }

        const { fila } = this.relacionCon(username)

        return this.pidiendo.includes(username) ||
          (fila !== null && this.moviendo.includes(fila.friendshipId))
      }
    },

    /**
     * ¿Hay una acción de SEGUIMIENTO en vuelo contra esta persona?
     *
     * **Aparte de la anterior a propósito**: son dos controles independientes y
     * un `friend_request` lento no tiene por qué dejar muerto el botón de seguir.
     */
    seguimientoOcupado: (state) => (username) =>
      Boolean(username) && (state.marcando.includes(username) || state.soltando.includes(username))
  },

  actions: {
    /**
     * Todo lo que pinta `/friends`: las tres listas de amistad y los seguidos.
     *
     * Las dos peticiones van **a la vez** y no encadenadas: son dos tablas
     * independientes y esperar a la primera para lanzar la segunda duplicaría la
     * espera de la pantalla sin ganar nada. Y cada una guarda su propio error,
     * por lo dicho arriba.
     */
    async cargar() {
      this.cargando = true
      this.error = null
      this.errorSeguidos = null

      const [amistades, seguimientos] = await Promise.all([
        apiCall('friend_list'),
        apiCall('follow_list')
      ])

      this.aplicarAmistades(amistades)
      this.aplicarSeguimientos(seguimientos)

      this.cargando = false
      this.cargado = true
      this.cargadoSeguidos = true
    },

    /**
     * Las listas que hacen falta para saber en qué relación estás con alguien.
     *
     * **La llama el perfil público, y solo cuando hay sesión.** Un visitante sin
     * cuenta no pide ninguna de las dos: las ocho acciones de amistad y
     * seguimiento llevan `AuthMiddleware` y le contestarían 401, y el caso
     * principal de `/user/:username` es precisamente quien abre un enlace
     * compartido sin tener cuenta.
     *
     * **Lo que ya esté cargado no se vuelve a pedir**, por eso las dos banderas:
     * abrir tres perfiles seguidos son dos peticiones en total, no seis. No
     * caducan por lo mismo que `cargado` —lo único que puede quedarse viejo es
     * una solicitud que alguien te mandó mientras mirabas otra pantalla, y el
     * plan deja las notificaciones fuera de alcance a todas letras—, y si esa
     * fila llegara a existir sin que la pantalla lo supiera, el `friend_request`
     * choca contra el `UNIQUE` simétrico, vuelve un 409 y `pedir()` recarga.
     *
     * `amistad: false` pide **solo** `follow_list`, y es lo que usa el perfil de
     * uno mismo: allí no hay amistad que pintar —pedirte amistad a ti mismo es un
     * 422 y el botón ni se dibuja— pero sí el número de seguidores, que sale de
     * `followerCount`.
     *
     * @param {{amistad?: boolean, forzar?: boolean}} opciones
     */
    async cargarRelaciones({ amistad = true, forzar = false } = {}) {
      if (this.cargandoRelacion) {
        return
      }

      const faltaAmistad = amistad && (forzar || !this.cargado)
      const faltanSeguidos = forzar || !this.cargadoSeguidos

      if (!faltaAmistad && !faltanSeguidos) {
        return
      }

      this.cargandoRelacion = true
      this.errorRelacion = null

      // Las dos a la vez y no encadenadas, por lo mismo que en `cargar()`: son
      // dos tablas independientes y esperar a la primera duplicaría la espera.
      const [amistades, seguimientos] = await Promise.all([
        faltaAmistad ? apiCall('friend_list') : null,
        faltanSeguidos ? apiCall('follow_list') : null
      ])

      // Se comprueba el `status` ANTES de volcar, y no se delega el fallo en
      // `aplicarAmistades()`: aquel escribe en `error`, que es el aviso rojo de
      // `/friends`. Un 429 al abrir el perfil de un desconocido no debe dejar un
      // error colgado en una pantalla que el usuario ni siquiera ha abierto.
      if (amistades) {
        if (amistades.status === 'success') {
          this.aplicarAmistades(amistades)
          this.cargado = true
        } else {
          this.errorRelacion = this.mensajeDeError(amistades, MENSAJE_SIN_RELACION)
        }
      }

      if (seguimientos) {
        if (seguimientos.status === 'success') {
          this.aplicarSeguimientos(seguimientos)
          this.cargadoSeguidos = true
        } else {
          this.errorRelacion = this.mensajeDeError(seguimientos, MENSAJE_SIN_RELACION)
        }
      }

      this.cargandoRelacion = false
    },

    /**
     * **Pedir amistad, por `username`.**
     *
     * Va por el nombre y no por un id porque es lo que recibe `friend_request`:
     * `users.username` es la clave pública del proyecto —es la URL del perfil— y
     * mandar enteros por el cuerpo invitaría a recorrerlos.
     *
     * Dos respuestas del backend que esta función traduce a algo distinto:
     *
     *  - **409 = ya hay fila entre los dos**, la haya pedido quien la haya
     *    pedido, porque el `UNIQUE (user_low, user_high)` es simétrico. Si el
     *    cliente creía que no había ninguna, **sus listas están viejas**: esa
     *    persona te pidió amistad mientras mirabas otra cosa. Por eso se recarga:
     *    dejar el botón donde estaba invitaría a pulsarlo otra vez para recibir
     *    el mismo 409, cuando lo que hay debajo es un «Aceptar».
     *  - **404 = no hay nadie con ese nombre.** No se recarga nada: no es que la
     *    relación haya cambiado, es que la persona no existe.
     *
     * La fila creada se mete en `enviadas` en el sitio y no se recarga la lista:
     * `friend_request` devuelve la fila entera (`friendshipId` y la persona), así
     * que una segunda petición traería lo que ya está en la mano.
     *
     * @param {string} username
     * @returns {Promise<boolean>} si la solicitud se creó
     */
    async pedir(username) {
      if (!username || this.pidiendo.includes(username)) {
        return false
      }

      this.pidiendo = [...this.pidiendo, username]

      const respuesta = await apiCall('friend_request', { username })

      this.pidiendo = this.pidiendo.filter((u) => u !== username)

      if (respuesta.status !== 'success') {
        this.aviso = { tipo: 'error', texto: this.mensajeDeError(respuesta) }

        if (respuesta.http_code === 409) {
          await this.cargar()
        }

        return false
      }

      const creada = respuesta.data ?? {}
      const persona = creada.user ?? { username, displayName: null, avatarUrl: null }

      // `since` no viene en la respuesta de `friend_request` —la fila acaba de
      // nacer y su `created_at` lo pone MySQL— y no se inventa: `PersonaFila` no
      // pinta el «desde …» cuando falta, que es mejor que una fecha de mentira.
      this.enviadas = [
        { friendshipId: creada.friendshipId, since: creada.since ?? null, user: persona },
        ...this.enviadas
      ]
      this.contadores = { ...this.contadores, sent: this.contadores.sent + 1 }

      this.aviso = {
        tipo: 'ok',
        texto: `Solicitud enviada a ${persona.displayName || persona.username}. Queda esperar a que la acepte.`
      }

      return true
    },

    /**
     * **Seguir a alguien, por `username`.**
     *
     * Es un marcador unilateral: no se pide permiso, no se acepta y **no da
     * ningún acceso**. El aviso lo dice con esas palabras a propósito, porque
     * «seguir» en otras apps significa cosas que aquí no significa.
     *
     * Dos cosas que no se ven en el código:
     *
     *  - **Seguir a quien ya sigues es 200, no 409**, y el backend lo absorbe con
     *    un `ON DUPLICATE KEY UPDATE`: el estado que el usuario pidió ya es el
     *    actual y ahí no hay ningún conflicto que resolver. Por eso aquí se
     *    comprueba antes de meter la fila en `siguiendo`, o dos llamadas seguidas
     *    dejarían a la misma persona dos veces en la lista de `/friends`.
     *  - **El 422 «ese perfil no enseña nada públicamente» no se puede predecir
     *    desde aquí**, y por eso el botón se pinta siempre. El backend mira los
     *    NIVELES configurados —«¿hay alguna sección en `everyone`?»— y lo único
     *    que el cliente tiene es el mapa `visible`, que dice qué ves TÚ: siendo
     *    amigo de alguien con el perfil entero en `friends`, `visible` viene todo
     *    a `true` y `follow_add` contesta 422 igualmente. Adivinarlo escondería
     *    el botón en casos en los que sí se puede seguir.
     *
     * **`seguidores` no se toca**: ese número es cuánta gente te sigue A TI, y
     * seguir a otra persona no lo mueve ni un dígito.
     *
     * @param {string} username
     * @returns {Promise<boolean>} si el marcador quedó puesto
     */
    async seguir(username) {
      if (!username || this.marcando.includes(username)) {
        return false
      }

      this.marcando = [...this.marcando, username]

      const respuesta = await apiCall('follow_add', { username })

      this.marcando = this.marcando.filter((u) => u !== username)

      if (respuesta.status !== 'success') {
        this.aviso = { tipo: 'error', texto: this.mensajeDeError(respuesta) }
        return false
      }

      const persona = respuesta.data?.user ?? { username, displayName: null, avatarUrl: null }

      if (!this.siguiendo.some((f) => f?.username === persona.username)) {
        this.siguiendo = [{ ...persona, since: null }, ...this.siguiendo]
      }

      this.aviso = {
        tipo: 'ok',
        texto: `Ahora sigues a ${persona.displayName || persona.username}. Seguir no te deja ver nada nuevo.`
      }

      return true
    },

    /**
     * **Buscar personas por el principio de su nombre de usuario.**
     *
     * Es la puerta de entrada que a las cinco acciones de amistad les faltaba:
     * `friend_request` va por nombre EXACTO, así que hasta el M6 solo se llegaba
     * a alguien sabiéndoselo de memoria.
     *
     * Tres cosas que no se ven en el código:
     *
     *  - **El mínimo de tres caracteres NO se replica aquí.** El backend lo
     *    contesta con un 422 y su mensaje se enseña tal cual, por el mismo
     *    criterio que el resto de este store y que el 422 de `follow_add` en el
     *    M5: un segundo juego de reglas en el cliente se desincroniza del
     *    primero, y este en concreto no es una regla de forma sino de política
     *    —sin el mínimo, `q=a` devuelve el censo— que vive donde se aplica. De
     *    propina, el *Hecho cuando:* del hito («buscar dos letras devuelve 422 y
     *    no resultados») es verdad **también por la interfaz**, y no solo por
     *    curl.
     *  - **Quien esté en `nobody` no sale, y eso no se distingue de que no
     *    exista.** Las dos respuestas del backend son idénticas —200 con lista
     *    vacía— a propósito: un 404 diría «aquí hay alguien que no quiere
     *    salir», que es la mitad de lo que se pidió no publicar.
     *  - **Los resultados no se cruzan con las listas aquí.** Se guardan crudos
     *    y quien decide qué botón pintar es `relacionCon()` desde la vista, que
     *    es el mismo mecanismo del M5: así, en cuanto `pedir()` mete la fila en
     *    `enviadas`, el botón de ese resultado cambia solo sin recargar nada.
     *
     * @param {string} q lo que el usuario escribió
     * @returns {Promise<boolean>} si la búsqueda trajo respuesta buena
     */
    async buscar(q) {
      if (this.buscando) {
        return false
      }

      const consulta = String(q ?? '').trim()

      this.consulta = consulta
      this.buscando = true
      this.errorBusqueda = null

      const respuesta = await apiCall('user_search', { q: consulta })

      this.buscando = false
      this.busquedaHecha = true

      if (respuesta.status !== 'success') {
        this.errorBusqueda = this.mensajeDeError(respuesta, 'No se pudo buscar.')
        // Los resultados viejos se tiran: dejarlos debajo de un error sobre una
        // consulta NUEVA haría creer que son la respuesta a lo que se acaba de
        // escribir.
        this.resultados = []
        return false
      }

      this.resultados = respuesta.data?.users ?? []

      return true
    },

    /**
     * Vaciar el buscador sin tocar ninguna de las cuatro listas.
     *
     * Lo llama la «x» del campo de búsqueda. Es su propio método y no un
     * `limpiar()` recortado porque lo que hay que devolver a cero son cinco
     * campos y ninguno de ellos es una relación: si algún día esto vaciara
     * `amigos`, el contador de la portada se quedaría a cero al cerrar una
     * búsqueda.
     */
    limpiarBusqueda() {
      this.consulta = ''
      this.resultados = []
      this.errorBusqueda = null
      this.busquedaHecha = false
    },

    /**
     * Solo los contadores, para la portada.
     *
     * Pide `friend_list` y **no** `follow_list`: el número que la portada enseña
     * es el de solicitudes pendientes, y cuánta gente sigues no se pinta ahí. Una
     * segunda petición por una cifra que nadie mira es una petición de más en una
     * pantalla que ya hace dos.
     *
     * De propina, las tres listas quedan cargadas: entrar después en `/friends`
     * ya tiene qué pintar mientras se refresca.
     *
     * @param {{forzar?: boolean}} opciones
     */
    async cargarContador({ forzar = false } = {}) {
      if (this.cargando || (this.cargado && !forzar)) {
        return
      }

      const respuesta = await apiCall('friend_list')

      // Un fallo aquí NO deja aviso ni error en pantalla: el contador vive en la
      // portada, donde el usuario no ha pedido nada. Un error sobre las amistades
      // encima del valor de la colección sería ruido por algo que nadie miraba.
      if (respuesta.status === 'success') {
        this.aplicarAmistades(respuesta)
        this.cargado = true
      }
    },

    /**
     * `pending` → `accepted`. **Solo sobre una solicitud RECIBIDA.**
     *
     * El backend devuelve 403 al `requester` que intenta aceptar la suya, y por
     * eso la vista pinta este botón únicamente sobre `recibidas`: las dos listas
     * están separadas en el backend con ese mismo criterio para que la interfaz
     * no pueda ofrecer algo que el servidor va a rechazar.
     *
     * **Es lo que abre el nivel `friends` de tu privacidad a esa persona**, y es
     * inmediato: la próxima lectura suya ya lo ve.
     */
    async aceptar(fila) {
      return this.mover('friend_accept', fila, (entrada) => {
        this.recibidas = this.recibidas.filter((f) => f.friendshipId !== entrada.friendshipId)
        this.amigos = [entrada, ...this.amigos]
        this.contadores = {
          ...this.contadores,
          pending: Math.max(0, this.contadores.pending - 1),
          friends: this.contadores.friends + 1
        }

        return `Ahora eres amigo de ${this.nombre(entrada)}.`
      })
    },

    /** Rechazar una solicitud recibida: borra la fila, no la marca. */
    async rechazar(fila) {
      return this.mover('friend_reject', fila, (entrada) => {
        this.recibidas = this.recibidas.filter((f) => f.friendshipId !== entrada.friendshipId)
        this.contadores = {
          ...this.contadores,
          pending: Math.max(0, this.contadores.pending - 1)
        }

        // Se dice que se puede volver a pedir porque es verdad y no es evidente:
        // no hay estado `rejected`, la fila desaparece, y quien pidió puede
        // volver a hacerlo más adelante.
        return `Solicitud rechazada. Esa persona puede volver a pedírtelo.`
      })
    },

    /**
     * **Retirar una solicitud que enviaste tú.**
     *
     * La acción es `friend_remove` y no un `friend_cancel` que no existe: la
     * enmienda del 2026-09-14 al plan le quitó a `DeshacerAmistad` la guarda de
     * «solo sobre una amistad aceptada», porque `friend_reject` es **solo del
     * destinatario** y sin eso quien enviaba una solicitud no tenía ninguna forma
     * de retirarla. El criterio ya no es el estado de la fila sino de qué lado
     * estás: si participas, la deshaces.
     *
     * Es el mismo endpoint que `deshacer()` y son dos actions distintas a
     * propósito: lo que cambia es de qué lista sale la fila y qué se le dice al
     * usuario, y «has retirado tu solicitud» y «ya no sois amigos» no son la
     * misma frase ni el mismo acto.
     */
    async retirar(fila) {
      return this.mover('friend_remove', fila, (entrada) => {
        this.enviadas = this.enviadas.filter((f) => f.friendshipId !== entrada.friendshipId)
        this.contadores = {
          ...this.contadores,
          sent: Math.max(0, this.contadores.sent - 1)
        }

        return `Solicitud a ${this.nombre(entrada)} retirada.`
      })
    },

    /**
     * Deshacer una amistad aceptada. **Cualquiera de los dos puede.**
     *
     * Y el efecto es inmediato y retroactivo a la vez: lo que ya vio esa persona
     * lo vio, y lo que se corta es su próxima lectura. No hay nada que deshacer
     * más allá de esto, y el aviso lo dice para que nadie espere otra cosa.
     */
    async deshacer(fila) {
      return this.mover('friend_remove', fila, (entrada) => {
        this.amigos = this.amigos.filter((f) => f.friendshipId !== entrada.friendshipId)
        this.contadores = {
          ...this.contadores,
          friends: Math.max(0, this.contadores.friends - 1)
        }

        return `Ya no eres amigo de ${this.nombre(entrada)}: deja de ver lo que tengas en «amigos».`
      })
    },

    /**
     * Quitar un marcador de seguimiento.
     *
     * Indexa por `username` y no por un id porque `user_follow` **no tiene id**:
     * su clave es `(follower_id, followed_id)`, asimétrica a propósito. Y el
     * backend no comprueba la privacidad del seguido al soltarlo: si esa persona
     * cerró su perfil después de que la siguieras, exigir la condición de entrada
     * para salir te dejaría con un marcador imposible de quitar.
     */
    async dejarDeSeguir(fila) {
      const username = fila?.username

      if (!username || this.soltando.includes(username)) {
        return false
      }

      this.soltando = [...this.soltando, username]

      const respuesta = await apiCall('follow_remove', { username })

      this.soltando = this.soltando.filter((u) => u !== username)

      if (respuesta.status !== 'success') {
        this.aviso = { tipo: 'error', texto: this.mensajeDeError(respuesta) }
        return false
      }

      this.siguiendo = this.siguiendo.filter((f) => f.username !== username)
      this.aviso = {
        tipo: 'ok',
        texto: `Has dejado de seguir a ${fila.displayName || username}.`
      }

      return true
    },

    /**
     * El cuerpo común de las cuatro acciones que mueven una fila de amistad.
     *
     * Va factorizado y no copiado cuatro veces por lo mismo que el `mover()` del
     * `FriendController` del backend: lo que hay dentro es la guarda del doble
     * clic y la traducción del fallo, y cuatro copias de eso son cuatro sitios
     * donde una de ellas se olvida de quitar el `friendshipId` de `moviendo` y
     * deja los botones muertos para siempre.
     *
     * `alTerminar` recibe la fila y devuelve el texto del aviso; es lo único que
     * cambia entre las cuatro, y por eso viaja como función.
     *
     * @param {string} accion      `friend_accept` | `friend_reject` | `friend_remove`
     * @param {object} fila        la entrada del listado, con su `friendshipId`
     * @param {(fila: object) => string} alTerminar
     * @returns {Promise<boolean>} si la fila se movió
     */
    async mover(accion, fila, alTerminar) {
      const id = fila?.friendshipId

      if (!id || this.moviendo.includes(id)) {
        return false
      }

      this.moviendo = [...this.moviendo, id]

      const respuesta = await apiCall(accion, { friendship_id: id })

      this.moviendo = this.moviendo.filter((x) => x !== id)

      if (respuesta.status !== 'success') {
        this.aviso = { tipo: 'error', texto: this.mensajeDeError(respuesta) }

        // Un 404 significa que esa fila ya no existe: la otra persona la movió
        // mientras mirabas. Se recarga para que la pantalla deje de enseñar algo
        // que no está, en vez de dejar al usuario pulsando un botón muerto.
        if (respuesta.http_code === 404) {
          await this.cargar()
        }

        return false
      }

      this.aviso = { tipo: 'ok', texto: alTerminar(fila) }

      return true
    },

    /**
     * Vuelca la respuesta de `friend_list` en las tres listas y los contadores.
     *
     * **Los contadores se toman del backend y no se cuentan aquí**, aunque las
     * listas estén delante: son la misma cifra calculada en el mismo sitio
     * (`ListarAmistades`), y recontarlas en el cliente sería una segunda
     * implementación que puede discrepar. Las acciones de este store sí los
     * mueven a mano, y ahí es donde tienen que cuadrar.
     */
    aplicarAmistades(respuesta) {
      if (respuesta.status !== 'success') {
        this.error = this.mensajeDeError(respuesta, 'No se pudieron cargar tus amistades.')
        this.amigos = []
        this.recibidas = []
        this.enviadas = []
        this.contadores = { friends: 0, pending: 0, sent: 0 }
        return
      }

      const datos = respuesta.data ?? {}

      this.amigos = datos.friends ?? []
      this.recibidas = datos.pending ?? []
      this.enviadas = datos.sent ?? []
      this.contadores = {
        friends: datos.counts?.friends ?? 0,
        pending: datos.counts?.pending ?? 0,
        sent: datos.counts?.sent ?? 0
      }
    },

    /** Lo mismo con `follow_list`, que es la otra relación y va por su lado. */
    aplicarSeguimientos(respuesta) {
      if (respuesta.status !== 'success') {
        this.errorSeguidos = this.mensajeDeError(respuesta, 'No se pudo cargar a quién sigues.')
        this.siguiendo = []
        this.seguidores = 0
        return
      }

      this.siguiendo = respuesta.data?.following ?? []
      this.seguidores = respuesta.data?.followerCount ?? 0
    },

    /**
     * El texto de un fallo.
     *
     * El 401 se traduce aparte porque es el único que el usuario puede resolver
     * —le ha caducado la sesión— y el mensaje del backend para ese caso no se lo
     * dice. El resto se enseña **tal cual lo manda el backend**: «esa solicitud
     * no es tuya», «esa amistad ya estaba aceptada» y «esa amistad no existe» son
     * frases escritas para leerse, y reescribirlas aquí sería un segundo juego de
     * mensajes que se desincroniza del primero.
     */
    mensajeDeError(respuesta, porDefecto = 'No se pudo completar la operación.') {
      if (respuesta.http_code === 401) {
        return 'Tu sesión ha caducado. Vuelve a entrar.'
      }

      return respuesta.message || porDefecto
    },

    /** Cómo se llama la persona de una fila del listado de amistades. */
    nombre(fila) {
      return fila?.user?.displayName || fila?.user?.username || 'esa persona'
    },

    /** Deja el store como recién creado. Lo llama la vista al salir. */
    limpiar() {
      Object.assign(this, listasVacias())
      this.cargando = false
      this.error = null
      this.errorSeguidos = null
      this.moviendo = []
      this.soltando = []
      this.pidiendo = []
      this.marcando = []
      this.aviso = null
      this.cargado = false
      this.cargadoSeguidos = false
      this.cargandoRelacion = false
      this.errorRelacion = null
      this.buscando = false
      this.limpiarBusqueda()
    }
  }
})
