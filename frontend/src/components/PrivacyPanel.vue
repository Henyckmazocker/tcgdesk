<template>
  <section class="privacidad">
    <header class="privacidad__cabecera">
      <h2 class="privacidad__titulo">
        <i class="pi pi-eye"></i> Tu perfil público
      </h2>
      <a
        v-if="urlDelPerfil"
        class="privacidad__abrir"
        :href="urlDelPerfil"
        target="_blank"
        rel="noopener"
      >Abrirlo <i class="pi pi-external-link"></i></a>
    </header>

    <p class="privacidad__intro">
      Tu perfil vive en <code class="privacidad__url">{{ urlDelPerfil || '(sin nombre de usuario)' }}</code>
      y lo abre cualquiera <strong>sin necesitar cuenta</strong>. Aquí eliges qué enseña.
      <br>
      Para verlo como lo ve un desconocido hay que abrirlo en una <strong>ventana de
      incógnito</strong>: con tu sesión abierta te ves entero siempre, porque el perfil de uno
      mismo no se esconde.
    </p>

    <p v-if="error" class="privacidad__error">
      <i class="pi pi-exclamation-triangle"></i> {{ error }}
    </p>

    <div v-if="cargando" class="privacidad__lista">
      <!-- `+ 1` por el sexto selector, que no está en `SECCIONES`: ver abajo. -->
      <Skeleton v-for="n in SECCIONES.length + 1" :key="n" height="3.2rem" />
    </div>

    <!--
      Sin niveles no se pinta ni un selector, y no es por elegancia: un `Select`
      vacío se lee como «no hay nada puesto», y lo que hay puesto es
      exactamente lo que no se ha podido leer. Inventar un nivel aquí sería
      pintar «Cualquiera» sobre algo que el backend guarda como `nobody`.
    -->
    <ul v-else-if="hayNiveles" class="privacidad__lista">
      <li
        v-for="seccion in SECCIONES"
        :key="seccion.clave"
        class="seccion"
        :class="`seccion--${seccion.clave}`"
      >
        <div class="seccion__texto">
          <span class="seccion__etiqueta">{{ seccion.etiqueta }}</span>
          <small class="seccion__nota">{{ seccion.nota }}</small>
        </div>

        <!--
          El `:key` con el número de revisión NO es decorativo. El `Select` de
          PrimeVue 4 guarda su propio `d_value` y solo lo sincroniza cuando el
          `model-value` CAMBIA (`primevue/select/index.mjs:988`), así que al
          fallar el guardado —cuando el nivel de verdad no se ha movido— el
          desplegable se quedaría enseñando el que el usuario eligió y que no
          llegó a guardarse. Subir la revisión lo vuelve a montar y lo devuelve
          a lo que dice el backend, que es la única fuente de verdad de esta
          pantalla.
        -->
        <Select
          :key="`${seccion.clave}-${revision}`"
          :model-value="niveles[seccion.clave]"
          :options="NIVELES"
          option-label="label"
          option-value="value"
          size="small"
          class="seccion__select"
          :loading="guardando.includes(seccion.clave)"
          :disabled="guardando.includes(seccion.clave)"
          :aria-label="`Quién ve ${seccion.etiqueta}`"
          @update:model-value="cambiar(seccion.clave, $event)"
        />
      </li>

      <!--
        EL SEXTO SELECTOR, y va SEPARADO de los cinco de arriba a propósito.
        Aquellos contestan «qué se ve DE MÍ» y este contesta otra cosa: «¿se me
        puede encontrar?». No es una sección de contenido, no la resuelve el
        mismo motor de permisos del backend y **solo tiene dos opciones**, no
        tres: «solo amigos» no existe aquí, porque a quien ya es tu amigo lo
        tienes en `/friends` sin buscarlo. Si algún día este `li` se metiera
        dentro del `v-for` de arriba, sería que las seis se han vuelto lo mismo.
      -->
      <li v-if="busqueda" class="seccion seccion--busqueda">
        <div class="seccion__texto">
          <span class="seccion__etiqueta">{{ BUSQUEDA.etiqueta }}</span>
          <small class="seccion__nota">{{ BUSQUEDA.nota }}</small>
        </div>

        <!-- El `:key` con la revisión, por lo mismo que los cinco de arriba. -->
        <Select
          :key="`busqueda-${revision}`"
          :model-value="busqueda"
          :options="OPCIONES_DE_BUSQUEDA"
          option-label="label"
          option-value="value"
          size="small"
          class="seccion__select"
          :loading="guardandoBusqueda"
          :disabled="guardandoBusqueda"
          aria-label="Quién puede encontrarte en el buscador"
          @update:model-value="cambiarBusqueda($event)"
        />
      </li>
    </ul>

    <!--
      Aquí vivía el aviso de que «Solo amigos» no dejaba ver nada a nadie, porque
      la tabla `friendships` no existía. **Se retiró el 2026-09-14, al cerrar el
      M6 del Plan - Amigos y Seguimiento**: desde el M2 de ese plan el nivel
      funciona —`Visibilidad` resuelve `friends` preguntando por una amistad
      `accepted`— y el aviso había pasado de decir la verdad a decir lo
      contrario, que es peor que no decir nada: empujaba a no usar el nivel
      intermedio justo cuando es el que más sentido tiene.
    -->

    <!--
      Y el aviso que NO trata las cinco secciones como equivalentes: el valor en
      euros es información patrimonial y nace en `friends` a propósito, así que
      subirlo a `everyone` se dice en voz alta.
    -->
    <p v-if="hayNiveles && valorPublico" class="privacidad__patrimonio">
      <i class="pi pi-exclamation-triangle"></i>
      <span>
        Con «{{ ETIQUETA_TODOS }}», <strong>cualquiera que abra tu perfil verá cuánto dinero
        tienes en cartas</strong>: el total en euros de tu colección y el precio de cada línea.
        No es lo mismo que enseñar qué cartas tienes.
      </span>
    </p>

    <!--
      Y el aviso del sexto, que sale SOLO cuando está apagado: es la única de las
      seis opciones cuyo estado cerrado tiene una consecuencia que el usuario no
      ve desde ninguna pantalla —deja de aparecerle a gente que no conoce—, y
      recordarlo aquí es más barato que preguntarse por qué nadie te encuentra.
    -->
    <p v-if="busqueda === 'nobody'" class="privacidad__oculto">
      <i class="pi pi-info-circle"></i>
      <span>
        <strong>No sales en el buscador de nadie.</strong> Tu perfil sigue siendo público con las
        opciones de arriba y quien conozca tu nombre de usuario puede abrirlo y pedirte amistad;
        lo que no puede es dar contigo escribiendo las primeras letras.
      </span>
    </p>

    <p v-if="aviso" class="privacidad__aviso" :class="`privacidad__aviso--${aviso.tipo}`">
      {{ aviso.texto }}
    </p>
  </section>
</template>

<script setup>
import { computed, onMounted, ref } from 'vue'
import Select from 'primevue/select'
import Skeleton from 'primevue/skeleton'

import { apiCall } from '@/services/api'
import { useAuthStore } from '@/stores/auth'

/**
 * **El panel de privacidad**: qué se ve de ti y quién lo ve.
 *
 * Cinco secciones por tres niveles, y tres cosas que mandan sobre el resto:
 *
 *  1. **La edición es PARCIAL.** Cada selector manda `privacy_set` con **su**
 *     sección y nada más, igual que `deck_update`. Mandar las cinco sería
 *     escribir cuatro decisiones que el usuario no acaba de tomar, y pisaría lo
 *     que hubiera cambiado en otra pestaña entre la carga y el clic. El backend
 *     responde con las cinco tal como quedaron y eso es lo que se repinta: no
 *     se recompone nada en local.
 *  2. **`friends` ya significa algo, y desde el 2026-09-14.** Hasta el M2 del
 *     Plan - Amigos y Seguimiento la tabla `friendships` no existía, el backend
 *     respondía `false` a todo el mundo (`Nivel::esInerte()`, fail-closed a
 *     propósito) y este panel lo decía dos veces: en la etiqueta de la opción y
 *     en un aviso bajo la lista. **Las dos cosas se retiraron** al enchufar la
 *     amistad — `Visibilidad` resuelve ahora ese nivel preguntando por una
 *     amistad `accepted`, y `Nivel::esInerte()` se borró—. Un aviso que sobrevive
 *     a lo que avisaba es peor que ninguno: este empujaba a no usar el nivel
 *     intermedio justo cuando pasó a ser el que más sentido tiene.
 *  3. **Las cinco secciones NO son equivalentes.** `value` publica cuánto dinero
 *     tienes en cartas, que es información patrimonial y no una lista de cromos;
 *     nace en `friends` por eso mismo, y subirla a `everyone` se avisa.
 *  4. **Y el sexto selector NO es una sexta sección.** Desde el M6 del
 *     Plan - Amigos y Seguimiento hay un buscador de usuarios en `/friends`, y
 *     este panel es donde se apaga. Contesta otra pregunta que los cinco de
 *     arriba —«¿se me puede encontrar?» y no «¿qué se ve de mí?»—, **solo tiene
 *     dos opciones** («solo amigos» no significaría nada: a un amigo lo tienes
 *     listado sin buscarlo), y **nace abierto**, que lo hace el único de los
 *     seis que empieza en el valor más permisivo sin ser una sección de
 *     contenido. El motivo de nacer así: el nombre de usuario **ya es** la URL
 *     pública del perfil, así que salir no publica nada nuevo; y un buscador que
 *     nace vacío se queda vacío, porque nadie activa lo que no sabe que existe.
 *     Viaja por su propia clave (`search`) en la misma acción `privacy_set`, y
 *     moverlo no toca ninguna de las cinco de arriba.
 *
 * Las claves de sección son **las del backend** (`collection`, `value`,
 * `decks`, `sets`, `wishlist`) y son exactamente las mismas que trae el mapa
 * `visible` del perfil público (`stores/publicProfile.js:36`): ese vocabulario
 * compartido es lo que permite que este panel y aquella vista hablen de lo mismo
 * sin un diccionario en medio. **Nunca los nombres de columna** (`show_value`):
 * el prefijo `show_` es cosa del esquema.
 *
 * La red va por `apiCall` —son dos acciones `POST` con su `AuthMiddleware` y su
 * `CsrfMiddleware`— y nunca por `publicGet`, que es para lo que se lee sin
 * sesión. El token CSRF lo adjunta `services/api.js` solo (siempre que exista):
 * aquí no se replica ninguna lista de acciones protegidas.
 */

/** Cómo se llama cada nivel en pantalla, en un solo sitio. */
const ETIQUETA_NADIE = 'Nadie'
const ETIQUETA_AMIGOS = 'Solo amigos'
const ETIQUETA_TODOS = 'Cualquiera'

/**
 * Los tres niveles, del más cerrado al más abierto —el orden del ENUM de
 * `user_privacy_settings` y el del plan—.
 *
 * **La etiqueta de `friends` llevó hasta el 2026-09-14 la coletilla «(nadie,
 * todavía)»**, porque la amistad no existía y elegir ese nivel equivalía a
 * `nobody`. El M2 del Plan - Amigos y Seguimiento la enchufó —`Visibilidad`
 * resuelve `friends` preguntando por una amistad `accepted`— y la coletilla se
 * retiró con ella. Si alguien la echa en falta, lo que busca es el aviso que
 * había debajo de la lista, retirado en el mismo momento y por el mismo motivo.
 */
const NIVELES = [
  { value: 'nobody', label: ETIQUETA_NADIE },
  { value: 'friends', label: ETIQUETA_AMIGOS },
  { value: 'everyone', label: ETIQUETA_TODOS }
]

/**
 * Las DOS opciones del sexto selector. Dos y no tres, y no es una simplificación
 * de la interfaz: el ENUM de `show_in_search` tiene dos valores en la base de
 * datos, y `privacy_set` contesta 422 a cualquier otra cosa —`friends` incluido,
 * que es un nivel perfectamente válido en las otras cinco columnas—.
 *
 * «Cualquiera con cuenta» y no «Cualquiera» a secas: el buscador lleva
 * `AuthMiddleware`, así que quien no ha entrado no busca a nadie. Decir
 * «Cualquiera» aquí y en los cinco de arriba haría creer que es el mismo
 * «cualquiera», y no lo es — aquellos se ven **sin sesión**.
 */
const OPCIONES_DE_BUSQUEDA = [
  { value: 'nobody', label: 'Nadie' },
  { value: 'everyone', label: 'Cualquiera con cuenta' }
]

/**
 * El sexto, con su texto. Va en su propia constante y **no dentro de
 * `SECCIONES`**, por lo mismo que su `<li>` va fuera del `v-for`: si estuviera
 * en la lista, el bucle le pasaría los tres `NIVELES` y ofrecería un «Solo
 * amigos» que el backend rechaza con un 422.
 */
const BUSQUEDA = {
  etiqueta: 'Aparecer en el buscador',
  nota: 'Si la gente puede encontrarte en /friends escribiendo el principio de tu nombre de usuario.'
}

/**
 * Las cinco secciones, con lo que de verdad publica cada una.
 *
 * La nota no describe la sección: describe **qué se lleva quien la mire**. «Tu
 * colección» no dice nada; «las cartas que tienes, con su edición y su estado»
 * sí, y es lo que permite decidir.
 */
const SECCIONES = [
  {
    clave: 'collection',
    etiqueta: 'Colección',
    nota: 'Qué cartas tienes, con su edición, su acabado y su estado. Tus notas nunca viajan.'
  },
  {
    clave: 'value',
    etiqueta: 'Valor en euros',
    nota: 'Cuánto dinero tienes en cartas: el total de la colección y el precio de cada línea.'
  },
  {
    clave: 'decks',
    etiqueta: 'Mazos',
    nota: 'La lista de tus mazos. Lo que te falta para montarlos no se enseña nunca.'
  },
  {
    clave: 'sets',
    etiqueta: 'Ediciones',
    nota: 'Cuánto llevas de cada edición, en porcentaje.'
  },
  {
    clave: 'wishlist',
    etiqueta: 'Lista de deseos',
    nota: 'Lo que te falta y quieres comprar.'
  }
]

const auth = useAuthStore()

/** Sección → nivel. Vacío hasta que responde `privacy_get`. */
const niveles = ref({})
/**
 * El sexto: `'nobody'` o `'everyone'`. `null` hasta que responde `privacy_get`.
 *
 * **`null` y no `'everyone'`**, aunque ese sea el defecto del backend: lo que
 * significa «no se ha podido leer» y lo que significa «sales en el buscador» no
 * pueden ser el mismo valor en una pantalla de privacidad. Con el defecto
 * escrito aquí, un `privacy_get` que fallara pintaría «Cualquiera con cuenta»
 * sobre alguien que lo tiene apagado. Por eso el `<li>` entero va con un `v-if`.
 */
const busqueda = ref(null)
/** Hay un `privacy_set` del sexto selector en vuelo, para no doblar el clic. */
const guardandoBusqueda = ref(false)
const cargando = ref(true)
/** Claves de sección con un `privacy_set` en vuelo, para no doblar el clic. */
const guardando = ref([])
const error = ref(null)
const aviso = ref(null)
/**
 * Sube cada vez que un guardado falla, y va en el `:key` de los cinco `Select`.
 * Es lo que los devuelve a lo que dice el backend cuando el nivel no cambió:
 * ver el comentario de la plantilla.
 */
const revision = ref(0)

/**
 * La URL pública del perfil, absoluta y compuesta con el origen del navegador.
 *
 * Por lo mismo que el enlace del mazo compartido: el backend no sabe en qué
 * origen vive el frontend. Y lleva `/#/` porque el router va con
 * `createWebHashHistory`.
 */
const urlDelPerfil = computed(() =>
  auth.username ? `${window.location.origin}/#/user/${encodeURIComponent(auth.username)}` : null
)

const hayNiveles = computed(() => Object.keys(niveles.value).length > 0)
const valorPublico = computed(() => niveles.value.value === 'everyone')

/**
 * Los cinco niveles del usuario.
 *
 * **Llegan siempre los cinco**, tenga fila o no: quien no la tiene recibe los
 * defectos (`collection`/`decks`/`sets` a `everyone`, `value` y `wishlist` a
 * `friends`), que es el caso de todo el mundo hasta que toca el primer selector.
 * Por eso aquí no hay ningún valor por defecto escrito: inventarlo sería pintar
 * `everyone` sobre algo que el backend guarda como `nobody`.
 */
async function cargar() {
  cargando.value = true
  error.value = null

  const respuesta = await apiCall('privacy_get')

  if (respuesta.status !== 'success') {
    error.value = respuesta.message || 'No se pudo cargar tu privacidad.'
    cargando.value = false
    return
  }

  niveles.value = respuesta.data?.privacy ?? {}
  // `search` viaja al lado de `privacy` y no dentro, porque no es un nivel: ver
  // la cabecera. `?? null` y nunca `?? 'everyone'`, por lo dicho en su `ref`.
  busqueda.value = respuesta.data?.search ?? null
  cargando.value = false
}

/**
 * Cambiar una sección: **solo esa viaja**.
 *
 * Y el estado local no se toca hasta que el backend confirma. Un optimista aquí
 * enseñaría «Nadie» sobre una sección que sigue siendo pública si la petición
 * falla, que en una pantalla de privacidad es la peor mentira posible: el
 * usuario cierra creyendo que ha cerrado la puerta.
 */
async function cambiar(clave, nivel) {
  if (!nivel || nivel === niveles.value[clave] || guardando.value.includes(clave)) {
    return
  }

  guardando.value = [...guardando.value, clave]
  error.value = null
  aviso.value = null

  const respuesta = await apiCall('privacy_set', { [clave]: nivel })

  guardando.value = guardando.value.filter((c) => c !== clave)

  if (respuesta.status !== 'success') {
    error.value = respuesta.message || 'No se pudo guardar el cambio.'
    // El nivel no se ha movido, así que el desplegable tampoco puede quedarse
    // enseñando el que se eligió: se remonta y vuelve a leer lo que hay.
    revision.value += 1
    return
  }

  // Se repintan las cinco con lo que devuelve el backend y no solo la tocada:
  // es la respuesta la que demuestra que las otras cuatro siguen donde estaban.
  niveles.value = respuesta.data?.privacy ?? niveles.value

  const etiqueta = SECCIONES.find((s) => s.clave === clave)?.etiqueta ?? clave

  aviso.value = {
    tipo: 'ok',
    texto: `«${etiqueta}» guardado. El perfil público lo aplica en su siguiente carga.`
  }
}

/**
 * Cambiar el sexto selector. **Solo él viaja**, igual que con las secciones.
 *
 * Es una función aparte de `cambiar()` y no un caso suyo porque lo que se manda
 * es otra clave, lo que se repinta es otra variable y lo que se dice al terminar
 * es otra frase: seguir apareciendo o no en un buscador no es «qué se ve de ti».
 * Compartirían tres líneas de `apiCall` y se llevarían por delante la separación
 * que el backend mantiene a propósito entre las cinco columnas y esta.
 *
 * Y como allí, **el estado local no se toca hasta que el backend confirma**: un
 * optimista aquí diría «ya no sales» sobre alguien que sigue saliendo.
 */
async function cambiarBusqueda(valor) {
  if (!valor || valor === busqueda.value || guardandoBusqueda.value) {
    return
  }

  guardandoBusqueda.value = true
  error.value = null
  aviso.value = null

  const respuesta = await apiCall('privacy_set', { search: valor })

  guardandoBusqueda.value = false

  if (respuesta.status !== 'success') {
    error.value = respuesta.message || 'No se pudo guardar el cambio.'
    revision.value += 1
    return
  }

  // Se repinta con lo que devuelve el backend, y también las cinco secciones:
  // la respuesta trae las seis, y es ella la que demuestra que tocar esta no ha
  // movido las otras.
  niveles.value = respuesta.data?.privacy ?? niveles.value
  busqueda.value = respuesta.data?.search ?? busqueda.value

  aviso.value = {
    tipo: 'ok',
    texto: busqueda.value === 'nobody'
      ? 'Ya no apareces en el buscador. Quien sepa tu nombre de usuario sigue pudiendo abrir tu perfil.'
      : 'Vuelves a aparecer en el buscador de quien tenga cuenta.'
  }
}

onMounted(cargar)
</script>

<style scoped>
.privacidad {
  display: flex;
  flex-direction: column;
  gap: 0.6rem;
  padding: 0.75rem;
  border: 1px solid var(--p-content-border-color);
  border-radius: 8px;
  background: var(--p-content-background);
}

.privacidad__cabecera {
  display: flex;
  align-items: baseline;
  justify-content: space-between;
  gap: 0.75rem;
}

.privacidad__titulo {
  display: flex;
  align-items: center;
  gap: 0.4rem;
  margin: 0;
  font-size: 0.95rem;
}

.privacidad__abrir {
  font-size: 0.8rem;
  color: var(--p-primary-color);
  text-decoration: none;
}

.privacidad__intro {
  margin: 0;
  font-size: 0.8rem;
  color: var(--p-text-muted-color);
}

.privacidad__url {
  font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
  font-size: 0.76rem;
}

.privacidad__lista {
  display: flex;
  flex-direction: column;
  gap: 0.4rem;
  margin: 0;
  padding: 0;
  list-style: none;
}

.seccion {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  justify-content: space-between;
  gap: 0.5rem;
  padding: 0.4rem 0;
  border-bottom: 1px solid var(--p-content-border-color);
}

.seccion:last-child {
  border-bottom: none;
}

.seccion__texto {
  display: flex;
  flex-direction: column;
  flex: 1 1 16rem;
}

.seccion__etiqueta {
  font-size: 0.85rem;
  font-weight: 600;
}

.seccion__nota {
  font-size: 0.75rem;
  color: var(--p-text-muted-color);
}

.seccion__select {
  flex: 0 0 13rem;
}

/*
  El sexto va separado de los cinco con una línea más marcada: no es una sección
  de contenido y la pantalla tiene que decirlo antes de que nadie lea la nota.
*/
.seccion--busqueda {
  margin-top: 0.35rem;
  padding-top: 0.7rem;
  border-top: 2px solid var(--p-content-border-color);
}

.privacidad__oculto,
.privacidad__patrimonio {
  display: flex;
  align-items: flex-start;
  gap: 0.4rem;
  margin: 0;
  padding: 0.5rem 0.6rem;
  border-radius: 6px;
  font-size: 0.78rem;
}

.privacidad__oculto {
  border-left: 3px solid var(--p-primary-color);
  background: color-mix(in srgb, var(--p-primary-color) 6%, transparent);
}

.privacidad__patrimonio {
  border-left: 3px solid var(--p-orange-400, #fb923c);
  background: color-mix(in srgb, var(--p-orange-500, #f97316) 8%, transparent);
}

.privacidad__error {
  display: flex;
  align-items: center;
  gap: 0.4rem;
  margin: 0;
  color: var(--p-red-500);
  font-size: 0.8rem;
}

.privacidad__aviso {
  margin: 0;
  font-size: 0.78rem;
}

.privacidad__aviso--ok {
  color: var(--p-green-500, #22c55e);
}
</style>
