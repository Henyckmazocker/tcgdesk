<template>
  <div class="perfil">
    <header class="perfil__bar">
      <span class="perfil__marca">TCGDesk</span>
    </header>

    <div v-if="perfil.cargandoPerfil" class="perfil__main">
      <Skeleton height="5rem" />
      <Skeleton height="12rem" />
    </div>

    <!--
      El 404 se dice con todas las letras y NO se convierte en un perfil vacío:
      «no hay nadie con ese nombre» y «esta persona no enseña nada» son dos
      cosas distintas, y la segunda es la que este plan tiene que poder decir
      sin mentir.
    -->
    <main v-else-if="perfil.errorPerfil === 'no_existe'" class="perfil__main">
      <p class="perfil__vacio">
        <i class="pi pi-user-minus"></i>
        No existe ningún usuario llamado «{{ username }}».
      </p>
    </main>

    <main v-else-if="perfil.errorPerfil" class="perfil__main">
      <p class="perfil__error">
        <i class="pi pi-exclamation-triangle"></i> {{ perfil.errorPerfil }}
      </p>
    </main>

    <main v-else-if="perfil.hayPerfil" class="perfil__main">
      <section class="tarjeta">
        <!--
          Avatar y nombre vienen de Google. El correo NO viaja en esta respuesta
          y no debe hacerlo nunca: si algún día apareciera en el JSON, el fallo
          está en el backend, no aquí.
        -->
        <img
          v-if="perfil.usuario.avatarUrl"
          :src="perfil.usuario.avatarUrl"
          :alt="perfil.usuario.displayName || perfil.usuario.username"
          class="tarjeta__avatar"
          referrerpolicy="no-referrer"
        >
        <div class="tarjeta__datos">
          <h1 class="tarjeta__nombre">
            {{ perfil.usuario.displayName || perfil.usuario.username }}
          </h1>
          <p class="tarjeta__usuario">@{{ perfil.usuario.username }}</p>
        </div>
      </section>

      <!--
        LOS DOS CONTROLES DE RELACIÓN, y son DOS y no un selector de estados.

        Amistad y seguimiento son relaciones ortogonales: la primera es recíproca,
        se acepta y **da acceso al nivel `friends`**; la segunda es unilateral, no
        se pide y **no da acceso a nada**. Se puede ser amigo de alguien y no
        seguirlo, seguirlo sin ser su amigo, las dos cosas, o ninguna. Por eso
        cuelgan de dos expresiones independientes —`relacion.amistad`, que es UN
        valor de cuatro, y `relacion.siguiendo`, que es un booleano— y no de una
        sola cadena de cinco casos: dentro de cada control la cadena
        `v-if`/`v-else-if` es exhaustiva y excluyente, así que no hay combinación
        que pinte dos botones contradictorios.

        Todo esto existe SOLO con sesión iniciada. Las ocho acciones llevan
        `AuthMiddleware`, y quien abre esta página desde un enlace pegado fuera
        sigue siendo el caso principal de la ruta: sin sesión no se pide nada al
        backend y aquí no se pinta nada.
      -->
      <section v-if="haySesion" class="relacion" data-test="relacion">
        <!--
          TU PROPIO PERFIL: ni amistad ni seguir —las dos acciones contra uno
          mismo son un 422 y el botón no debería llegar a existir— y sí el número
          de seguidores, que es la decisión cerrada del plan: **su dueño lo ve y
          nadie puede impedirlo**. La herramienta para no ser seguido no es
          esconder el número, es bajar las secciones a «amigos» o «nadie», y el
          pie lo dice para que no se busque un botón que no hay.
        -->
        <template v-if="esMiPerfil">
          <p class="relacion__seguidores" data-test="seguidores">
            <i class="pi pi-eye"></i>
            <span>{{ textoSeguidores }}</span>
          </p>
          <p class="relacion__nota">
            Este es tu perfil, tal y como lo ve quien abra el enlace. Seguirte no le deja ver nada
            que no enseñes ya en público: para dejar de aparecer, baja tus secciones a «amigos» o
            «nadie».
          </p>
        </template>

        <Skeleton v-else-if="amigos.cargandoRelacion" height="2.5rem" />

        <!--
          Que no se sepa la relación NO se resuelve pintando el estado «nada»:
          ofrecerle «Pedir amistad» a quien ya es tu amigo sería mentir sobre un
          permiso. Se dice que no se pudo comprobar y no se ofrece nada.
        -->
        <p v-else-if="amigos.errorRelacion" class="perfil__error" data-test="error-relacion">
          <i class="pi pi-exclamation-triangle"></i> {{ amigos.errorRelacion }}
        </p>

        <template v-else>
          <div class="relacion__controles">
            <div class="relacion__control" data-test="control-amistad">
              <Button
                v-if="relacion.amistad === 'nada'"
                label="Pedir amistad"
                icon="pi pi-user-plus"
                size="small"
                :disabled="amigos.amistadOcupada(nombreDelPerfil)"
                @click="amigos.pedir(nombreDelPerfil)"
              />

              <!--
                «Retirar solicitud» manda `friend_remove` y no un `friend_cancel`
                que no existe: `friend_reject` es SOLO del destinatario, así que
                la enmienda del 2026-09-14 dejó que `friend_remove` valga también
                sobre una `pending`. Es literalmente lo que hace posible este
                botón.
              -->
              <template v-else-if="relacion.amistad === 'enviada'">
                <span class="relacion__estado">
                  <i class="pi pi-clock"></i> Solicitud enviada
                </span>
                <Button
                  label="Retirar solicitud"
                  icon="pi pi-times"
                  size="small"
                  severity="secondary"
                  text
                  :disabled="amigos.amistadOcupada(nombreDelPerfil)"
                  @click="amigos.retirar(relacion.fila)"
                />
              </template>

              <!--
                Solicitud RECIBIDA. Son dos botones y no se contradicen: son las
                dos respuestas a la misma pregunta, igual que en `/friends`. Solo
                el destinatario puede aceptar —el backend devuelve 403 al
                solicitante— y por eso este bloque cuelga de `recibidas`, que es
                la lista que el backend separa con ese mismo criterio.
              -->
              <template v-else-if="relacion.amistad === 'recibida'">
                <span class="relacion__estado">
                  <i class="pi pi-user-plus"></i> Te ha pedido amistad
                </span>
                <Button
                  label="Aceptar"
                  icon="pi pi-check"
                  size="small"
                  :disabled="amigos.amistadOcupada(nombreDelPerfil)"
                  @click="amigos.aceptar(relacion.fila)"
                />
                <Button
                  label="Rechazar"
                  icon="pi pi-times"
                  size="small"
                  severity="secondary"
                  text
                  :disabled="amigos.amistadOcupada(nombreDelPerfil)"
                  @click="amigos.rechazar(relacion.fila)"
                />
              </template>

              <template v-else>
                <span class="relacion__estado">
                  <i class="pi pi-check-circle"></i> Sois amigos
                </span>
                <Button
                  label="Quitar amistad"
                  icon="pi pi-user-minus"
                  size="small"
                  severity="danger"
                  text
                  :disabled="amigos.amistadOcupada(nombreDelPerfil)"
                  @click="amigos.deshacer(relacion.fila)"
                />
              </template>
            </div>

            <!--
              EL OTRO CONTROL, y va sin confirmación ninguna en los dos sentidos:
              poner y quitar un marcador que no concede nada no es una decisión
              que haya que confirmar, y quitarlo se deshace pulsando otra vez.
              Quitar una amistad sí corta un acceso, pero tampoco la pide: es
              exactamente lo mismo que hace `/friends`, y dos criterios distintos
              para el mismo acto en dos pantallas sería lo raro.
            -->
            <div class="relacion__control" data-test="control-seguir">
              <Button
                v-if="!relacion.siguiendo"
                label="Seguir"
                icon="pi pi-eye"
                size="small"
                severity="secondary"
                outlined
                :disabled="amigos.seguimientoOcupado(nombreDelPerfil)"
                @click="amigos.seguir(nombreDelPerfil)"
              />
              <Button
                v-else
                label="Dejar de seguir"
                icon="pi pi-eye-slash"
                size="small"
                severity="secondary"
                text
                :disabled="amigos.seguimientoOcupado(nombreDelPerfil)"
                @click="amigos.dejarDeSeguir(personaDelPerfil)"
              />
            </div>
          </div>

          <!--
            El pie que impide que la interfaz mienta sobre el modelo de permisos.
            Si la pantalla insinuara que seguir sirve para ver más, estaría
            diciendo algo que `Visibilidad` no hace en ninguna línea.
          -->
          <p class="relacion__nota">
            La amistad se pide y se acepta, y es lo único que deja ver lo que esta persona tenga en
            el nivel «amigos». Seguir es un marcador para volver a su perfil:
            <strong>no le pide permiso a nadie y no te deja ver nada</strong> que no enseñe ya en
            público.
          </p>
        </template>
      </section>

      <!--
        El resumen en euros solo existe si `show_value` lo permite, y nace en
        `friends` precisamente por esto: cuánto dinero tienes en cartas no es lo
        mismo que qué cartas tienes.
      -->
      <section v-if="perfil.valorVisible && perfil.valor" class="valor">
        <div>
          <dt>Valor</dt>
          <dd>{{ euros(perfil.valor.valueEur) }}</dd>
        </div>
        <div>
          <dt>Cartas distintas</dt>
          <dd>{{ perfil.valor.uniqueCards }}</dd>
        </div>
        <div>
          <dt>Ejemplares</dt>
          <dd>{{ perfil.valor.totalCopies }}</dd>
        </div>
      </section>

      <section
        v-for="seccion in perfil.seccionesDelPerfil"
        :key="seccion.clave"
        class="seccion"
        :data-seccion="seccion.clave"
      >
        <h2 class="seccion__titulo">{{ seccion.etiqueta }}</h2>

        <!--
          EL ESTADO VACÍO HONESTO, que es la razón de ser de esta vista.

          Una sección que no se puede ver **se pinta igual** y dice que es
          privada. No se esconde: una sección que desaparece de la página se lee
          como un fallo de carga, y entonces el visitante recarga —contra una
          ruta con límite de 60/min— buscando algo que nunca iba a estar.

          Las dos vías llegan aquí: que el mapa `visible` del perfil ya dijera
          que no, o que la propia sección volviera con 403 `not_visible` porque
          el dueño cambió su privacidad entre las dos peticiones.
        -->
        <p v-if="!seccion.visible || seccion.estado.privada" class="seccion__privada">
          <i class="pi pi-lock"></i> Esta sección es privada.
        </p>

        <template v-else>
          <div v-if="seccion.estado.cargando" class="seccion__cargando">
            <Skeleton height="6rem" />
          </div>

          <p v-else-if="seccion.estado.error" class="perfil__error">
            <i class="pi pi-exclamation-triangle"></i> {{ seccion.estado.error }}
          </p>

          <!--
            Y el otro vacío honesto, el que NO es privacidad: la sección se ve,
            pero no hay nada dentro. Decirlo con otras palabras que «es privada»
            importa, porque son dos situaciones que el visitante interpreta
            distinto.
          -->
          <p v-else-if="seccion.estado.items.length === 0" class="seccion__nada">
            Aquí no hay nada todavía.
          </p>

          <template v-else>
            <!-- Colección y lista de deseos comparten contrato: misma rejilla. -->
            <template v-if="seccion.clave === 'collection' || seccion.clave === 'wishlist'">
              <ul class="cartas">
                <li v-for="carta in seccion.estado.items" :key="claveDeCarta(carta)" class="carta">
                  <!--
                    SIN enlace a `/card/:uuid`: esa ruta está tras el guard de
                    sesión, así que un `router-link` ahí mandaría al login a
                    quien acaba de abrir un enlace público. Un perfil público que
                    pide iniciar sesión al primer clic no es público.
                  -->
                  <CardImage
                    :scryfall-id="carta.scryfallId"
                    :nombre="carta.name"
                    tamano="small"
                  />
                  <span class="carta__nombre">{{ carta.name }}</span>
                  <small class="carta__sub">
                    {{ carta.setCode }} · {{ etiquetaAcabado(carta.finish) }} ×{{ carta.quantity }}
                  </small>
                  <!--
                    `priceEur` solo viene si `show_value` lo permite: el backend
                    lo añade por lista blanca. Aquí no se calcula ni se deduce
                    nada, solo se pinta lo que llegó.
                  -->
                  <small v-if="carta.priceEur !== undefined" class="carta__precio">
                    {{ carta.priceEur === null ? 'sin precio' : euros(carta.priceEur) }}
                  </small>
                </li>
              </ul>

              <div v-if="seccion.estado.nextCursor" class="seccion__mas">
                <Button
                  label="Ver más cartas"
                  icon="pi pi-angle-down"
                  text
                  :loading="seccion.estado.cargandoMas"
                  @click="perfil.cargarMas(seccion.clave)"
                />
              </div>
            </template>

            <ul v-else-if="seccion.clave === 'decks'" class="mazos">
              <!--
                Tampoco hay enlace al mazo: `/deck/:id` es privado y además el
                mazo es de otra persona. Un mazo se abre desde fuera solo por su
                enlace de compartir, que es otra ruta y otro token.
              -->
              <li v-for="mazo in seccion.estado.items" :key="mazo.id" class="mazo">
                <span class="mazo__nombre">{{ mazo.name }}</span>
                <Tag
                  :value="etiquetaEstadoMazo(mazo.status)"
                  :severity="severidadEstadoMazo(mazo.status)"
                />
                <small class="mazo__sub">
                  {{ mazo.format || 'sin formato' }} · {{ mazo.cards }} cartas
                </small>
              </li>
            </ul>

            <ul v-else class="sets">
              <li v-for="set in seccion.estado.items" :key="set.setCode" class="set">
                <div class="set__cabecera">
                  <span class="set__nombre">
                    {{ set.setName }} <small>{{ set.setCode }}</small>
                  </span>
                  <span class="set__porcentaje">{{ porcentaje(set) }}</span>
                </div>
                <!--
                  Sin `total_set_size` no hay porcentaje que dibujar: la columna
                  es nullable y pintar una barra vacía se leería como un 0 %.
                -->
                <div v-if="set.percent !== null" class="set__pista">
                  <div class="set__relleno" :style="{ width: ancho(set.percent) }"></div>
                </div>
                <small class="set__pie">
                  {{ set.ownedPrintings }} cartas distintas · {{ set.copies }} ejemplares
                </small>
              </li>
            </ul>
          </template>
        </template>
      </section>
    </main>

    <!--
      El aviso de lo que hicieron los botones de arriba, con las mismas clases y
      el mismo comportamiento que el de `/friends`: no interrumpe, no roba el
      foco y se va solo. Se copia en vez de reutilizarse por lo mismo que allí:
      `CollectionAviso.vue` lee el aviso de los dos stores de colección, y darle
      un tercer modo sería tocar un componente compartido para una pantalla que
      no tiene nada que ver con él.
    -->
    <Transition name="aviso">
      <div
        v-if="amigos.aviso"
        class="aviso"
        :class="`aviso--${amigos.aviso.tipo}`"
        role="status"
        aria-live="polite"
      >
        <i :class="amigos.aviso.tipo === 'error' ? 'pi pi-exclamation-triangle' : 'pi pi-check-circle'"></i>
        <span>{{ amigos.aviso.texto }}</span>
      </div>
    </Transition>
  </div>
</template>

<script setup>
import { computed, onBeforeUnmount, onMounted, watch } from 'vue'
import { useRoute } from 'vue-router'
import Button from 'primevue/button'
import Skeleton from 'primevue/skeleton'
import Tag from 'primevue/tag'

import CardImage from '@/components/CardImage.vue'
import {
  etiquetaAcabado,
  etiquetaEstadoMazo,
  severidadEstadoMazo
} from '@/constants/collection'
import { useAuthStore } from '@/stores/auth'
import { useFriendsStore } from '@/stores/friends'
import { usePublicProfileStore } from '@/stores/publicProfile'

/**
 * El perfil público de alguien: `/user/:username`, `meta: { public: true }`.
 *
 * **Esta vista no depende del store de sesión**, y no es un detalle: quien la
 * abre viene de un enlace pegado en un Discord y puede no tener cuenta ni nada
 * en `localStorage`. Todo lo que necesita llega por `publicGet`, que manda el
 * `Bearer` si resulta haberlo —así el dueño se ve a sí mismo entero— y nada si
 * no —y entonces se ve lo que sea `everyone`—.
 *
 * Lo que decide qué se pinta es **el mapa `visible` del backend**, no una
 * comprobación de aquí. La vista no sabe qué es `nobody`, `friends` ni
 * `everyone`, y es lo que garantiza que el plan de Amigos no tenga que tocarla:
 * cuando `friends` deje de ser inerte, la misma respuesta traerá más `true` y
 * esto pintará más cosas sin cambiar una línea.
 *
 * Y la regla que da nombre al hito: **una sección que no se ve se pinta
 * igualmente y dice que es privada**. Ver el comentario del template, donde se
 * decide.
 *
 * ---
 *
 * **ESTA VISTA ES MIXTA desde el M5, y conviene saber por dónde va cada cosa.**
 *
 * El perfil —el párrafo de arriba, sin cambios— sigue llegando por `publicGet`,
 * que va sin cookie y añade el `Bearer` si resulta haberlo. Los **botones de
 * relación** son otra cosa: `friend_request`, `friend_remove`, `follow_add` y
 * compañía son acciones `POST` con `AuthMiddleware` y `CsrfMiddleware`, y van
 * por `apiCall` a través de `stores/friends.js`. Dos vías de red en una pantalla
 * porque son dos preguntas distintas: «¿qué enseña esta persona?» no necesita
 * saber quién mira, y «¿qué soy yo de esta persona?» no se puede contestar sin
 * saberlo.
 *
 * De ahí la única dependencia nueva: **la vista lee `stores/auth`, y
 * `stores/publicProfile` sigue sin tocarlo en ninguna línea.** Aquel store no
 * puede depender de la sesión —su cabecera explica por qué— y no lo hace; es
 * esta vista la que decide si hay botones que pintar, y la que garantiza las dos
 * condiciones que el backend contestaría con un error si se le preguntara:
 *
 *  - **Sin sesión no hay botones y no se pide nada.** Un anónimo comería 401 en
 *    las ocho acciones, y quien abre el enlace compartido es el caso principal
 *    de esta ruta: la página no puede romperse ni cambiar por él.
 *  - **En tu propio perfil tampoco.** Pedirte amistad a ti mismo es un 422 y
 *    seguirte también, así que el botón no llega a existir. Lo que sí sale ahí
 *    es el número de seguidores.
 *
 * Y el número de seguidores es la otra decisión cerrada del plan: **lo ve su
 * dueño y nadie puede impedir que suba**. No hay forma de bloquear a alguien
 * —está fuera del alcance— y la herramienta que sí existe para no ser seguido es
 * bajar las secciones a `friends` o `nobody`, que es lo que el pie de ese bloque
 * dice con todas las letras.
 */

/** Lo que tarda el aviso en irse solo, igual que en `FriendsView.vue`. */
const DURACION_AVISO_MS = 3500

const route = useRoute()
const perfil = usePublicProfileStore()
const auth = useAuthStore()
const amigos = useFriendsStore()

const username = computed(() => String(route.params.username ?? ''))

const haySesion = computed(() => auth.isAuthenticated)

/**
 * El `username` con el que se cruzan las listas.
 *
 * Sale del **perfil ya cargado** y no del parámetro de la ruta, que es lo que el
 * visitante tecleó: el backend resuelve el nombre y devuelve el canónico, y
 * cruzar por el de la URL dejaría la relación en «nada» ante cualquier
 * diferencia de caja. Vacío mientras el perfil no haya llegado, que es lo que
 * impide pedir las listas antes de saber de quién es el perfil.
 */
const nombreDelPerfil = computed(() => perfil.usuario?.username ?? '')

/** Lo que necesita `dejarDeSeguir()`, que indexa por nombre porque no hay id. */
const personaDelPerfil = computed(() => ({
  username: nombreDelPerfil.value,
  displayName: perfil.usuario?.displayName ?? null
}))

const esMiPerfil = computed(
  () => haySesion.value && Boolean(nombreDelPerfil.value) && auth.username === nombreDelPerfil.value
)

/** Los dos campos ortogonales de la relación. Ver `relacionCon` en el store. */
const relacion = computed(() => amigos.relacionCon(nombreDelPerfil.value))

/** El número de seguidores en una frase, con el singular escrito aparte. */
const textoSeguidores = computed(() =>
  amigos.seguidores === 1 ? '1 persona te sigue' : `${amigos.seguidores} personas te siguen`
)

const FORMATO_EUR = new Intl.NumberFormat('es-ES', {
  style: 'currency',
  currency: 'EUR',
  minimumFractionDigits: 2,
  maximumFractionDigits: 2
})

function euros(valor) {
  return valor === null || valor === undefined ? 'sin precio' : FORMATO_EUR.format(valor)
}

/**
 * La clave de la rejilla.
 *
 * No basta el `printingUuid`: la misma impresión puede estar dos veces con
 * acabado, idioma o estado distintos, que es justo lo que forma la PK real de
 * la línea de colección.
 */
function claveDeCarta(carta) {
  return `${carta.printingUuid}|${carta.finish}|${carta.language}|${carta.condition}`
}

function porcentaje(set) {
  return set.percent === null ? 'sin tamaño' : `${String(set.percent).replace('.', ',')} %`
}

/** El número se enseña tal cual, pero la barra no se sale de su carril. */
function ancho(percent) {
  return `${Math.min(percent, 100)}%`
}

// Cambiar de `/user/a` a `/user/b` reutiliza el componente: sin esto se
// quedaría el perfil anterior en pantalla, que en una vista sobre privacidad es
// lo último que puede pasar.
watch(username, (nuevo) => {
  if (nuevo) {
    perfil.cargarPerfil(nuevo)
  }
})

/**
 * Las listas de relación se piden CUANDO EL PERFIL YA ESTÁ, y no al montar.
 *
 * Tres cosas dependen de esa espera y ninguna se sabe antes: de quién es el
 * perfil (el `username` canónico con el que se cruzan las listas), si es el tuyo
 * —y entonces sobra `friend_list`— y si existe siquiera, porque pedir tus
 * amistades por un `/user/nadie` que devuelve 404 son dos peticiones para una
 * pantalla que solo va a decir «no existe nadie con ese nombre».
 *
 * **El coste de abrir un perfil ajeno con sesión son dos peticiones más**, y
 * solo la primera vez en la navegación: el store no vuelve a pedir lo que ya
 * tiene. Uno propio, una. Sin sesión, ninguna — que es el caso principal.
 */
watch(nombreDelPerfil, (nombre) => {
  if (!nombre || !haySesion.value) {
    return
  }

  amigos.cargarRelaciones({ amistad: !esMiPerfil.value })
})

let temporizador = null

watch(
  () => amigos.aviso,
  (aviso) => {
    clearTimeout(temporizador)

    if (aviso) {
      temporizador = setTimeout(() => { amigos.aviso = null }, DURACION_AVISO_MS)
    }
  }
)

onMounted(() => perfil.cargarPerfil(username.value))

onBeforeUnmount(() => {
  clearTimeout(temporizador)
  perfil.limpiar()

  // El aviso se apaga; las listas NO se limpian. Viven en el store de amistades,
  // que las comparte con `/friends` y con el contador de la portada: vaciarlas al
  // salir de un perfil dejaría ese contador a cero hasta la siguiente petición.
  amigos.aviso = null
})
</script>

<style scoped>
.perfil {
  padding-bottom: 3rem;
}

.perfil__bar {
  display: flex;
  align-items: center;
  padding: 0.6rem 0.9rem;
  background: var(--p-content-background);
  border-bottom: 1px solid var(--p-content-border-color);
}

.perfil__marca {
  font-weight: 700;
  letter-spacing: 0.02em;
}

.perfil__main {
  display: flex;
  flex-direction: column;
  gap: 1.25rem;
  max-width: 60rem;
  margin: 0 auto;
  padding: 1rem 0.75rem;
}

.perfil__error {
  display: flex;
  align-items: center;
  gap: 0.4rem;
  margin: 0;
  color: var(--p-red-500);
  font-size: 0.85rem;
}

.perfil__vacio {
  display: flex;
  align-items: center;
  gap: 0.5rem;
  margin: 2rem 0;
  color: var(--p-text-muted-color);
}

.tarjeta {
  display: flex;
  align-items: center;
  gap: 0.9rem;
  padding: 0.9rem;
  border: 1px solid var(--p-content-border-color);
  border-radius: 8px;
  background: var(--p-content-background);
}

.tarjeta__avatar {
  width: 3.5rem;
  height: 3.5rem;
  border-radius: 50%;
  object-fit: cover;
}

.tarjeta__nombre {
  margin: 0;
  font-size: 1.2rem;
}

.tarjeta__usuario {
  margin: 0.1rem 0 0;
  color: var(--p-text-muted-color);
  font-size: 0.85rem;
}

.valor {
  display: flex;
  flex-wrap: wrap;
  gap: 1.5rem;
  padding: 0.75rem 0.9rem;
  border: 1px solid var(--p-content-border-color);
  border-radius: 8px;
  background: var(--p-content-background);
}

.valor dt {
  font-size: 0.68rem;
  text-transform: uppercase;
  letter-spacing: 0.04em;
  color: var(--p-text-muted-color);
}

.valor dd {
  margin: 0;
  font-size: 1.05rem;
  font-weight: 600;
}

.seccion__titulo {
  margin: 0 0 0.5rem;
  font-size: 1rem;
}

.seccion__privada {
  display: flex;
  align-items: center;
  gap: 0.45rem;
  margin: 0;
  padding: 0.85rem;
  border: 1px dashed var(--p-content-border-color);
  border-radius: 8px;
  color: var(--p-text-muted-color);
  font-size: 0.85rem;
}

.seccion__nada {
  margin: 0;
  color: var(--p-text-muted-color);
  font-size: 0.85rem;
}

.seccion__mas {
  display: flex;
  justify-content: center;
  padding-top: 0.5rem;
}

.cartas {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(7rem, 1fr));
  gap: 0.75rem;
  margin: 0;
  padding: 0;
  list-style: none;
}

.carta__nombre {
  display: block;
  margin-top: 0.3rem;
  font-size: 0.78rem;
  line-height: 1.25;
}

.carta__sub,
.carta__precio {
  display: block;
  font-size: 0.7rem;
  color: var(--p-text-muted-color);
}

.mazos,
.sets {
  display: flex;
  flex-direction: column;
  gap: 0.5rem;
  margin: 0;
  padding: 0;
  list-style: none;
}

.mazo {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 0.5rem;
  padding: 0.6rem 0.75rem;
  border: 1px solid var(--p-content-border-color);
  border-radius: 8px;
  background: var(--p-content-background);
}

.mazo__nombre {
  font-weight: 600;
  font-size: 0.9rem;
}

.mazo__sub,
.set__pie {
  color: var(--p-text-muted-color);
  font-size: 0.75rem;
}

.set {
  padding: 0.6rem 0.75rem;
  border: 1px solid var(--p-content-border-color);
  border-radius: 8px;
  background: var(--p-content-background);
}

.set__cabecera {
  display: flex;
  align-items: baseline;
  justify-content: space-between;
  gap: 0.75rem;
  font-size: 0.88rem;
}

.set__nombre small {
  color: var(--p-text-muted-color);
  font-size: 0.72rem;
}

.set__pista {
  height: 5px;
  margin: 0.35rem 0;
  border-radius: 3px;
  background: var(--p-content-border-color);
  overflow: hidden;
}

.set__relleno {
  height: 100%;
  background: var(--p-primary-color);
}

/* Los dos controles de relación. Ver el comentario del template. */
.relacion {
  display: flex;
  flex-direction: column;
  gap: 0.6rem;
  padding: 0.75rem 0.9rem;
  border: 1px solid var(--p-content-border-color);
  border-radius: 8px;
  background: var(--p-content-background);
}

.relacion__controles {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 0.75rem 1.25rem;
}

.relacion__control {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 0.4rem;
}

.relacion__estado {
  display: inline-flex;
  align-items: center;
  gap: 0.35rem;
  font-size: 0.82rem;
  color: var(--p-text-muted-color);
}

.relacion__seguidores {
  display: flex;
  align-items: center;
  gap: 0.45rem;
  margin: 0;
  font-size: 0.9rem;
  font-weight: 600;
}

.relacion__nota {
  margin: 0;
  font-size: 0.75rem;
  line-height: 1.4;
  color: var(--p-text-muted-color);
}

/* Copiado literal de `FriendsView.vue`: el mismo aviso y el mismo sitio. */
.aviso {
  position: fixed;
  left: 50%;
  bottom: 1.25rem;
  z-index: 50;
  display: flex;
  align-items: center;
  gap: 0.5rem;
  transform: translateX(-50%);
  max-width: 90vw;
  padding: 0.6rem 1rem;
  border-radius: 6px;
  font-size: 0.85rem;
  color: var(--p-primary-contrast-color);
  background: var(--p-primary-color);
  box-shadow: 0 4px 16px rgb(0 0 0 / 25%);
  pointer-events: none;
}

.aviso--error {
  color: #fff;
  background: var(--p-red-500);
}

.aviso-enter-active,
.aviso-leave-active {
  transition: opacity 0.2s ease, transform 0.2s ease;
}

.aviso-enter-from,
.aviso-leave-to {
  opacity: 0;
  transform: translate(-50%, 0.5rem);
}
</style>
