<template>
  <div class="amigos">
    <header class="amigos__bar">
      <Button icon="pi pi-arrow-left" text rounded aria-label="Volver" @click="router.push('/')" />
      <h1 class="amigos__titulo">Amigos</h1>

      <!--
        El número de seguidores va en la barra y no en su sección: es lo único de
        esta pantalla que habla de OTRA gente —filas que pusieron ellos— y no de
        una lista que el usuario administre. Su dueño lo ve y no puede impedir que
        suba: la herramienta para no ser seguido es bajar las secciones a
        «amigos» o «nadie», y eso se hace en la portada.
      -->
      <span v-if="amigos.seguidores > 0" class="amigos__seguidores">
        <i class="pi pi-eye"></i>
        {{ amigos.seguidores }} {{ amigos.seguidores === 1 ? 'persona te sigue' : 'personas te siguen' }}
      </span>
    </header>

    <main class="amigos__main">
      <p v-if="amigos.error" class="amigos__error">
        <i class="pi pi-exclamation-triangle"></i> {{ amigos.error }}
      </p>

      <div v-if="amigos.cargando" class="amigos__esqueletos">
        <Skeleton v-for="n in 4" :key="n" height="4rem" />
      </div>

      <template v-else>
        <!--
          LAS RECIBIDAS VAN PRIMERO, y es la decisión de composición de esta
          pantalla. El plan descarta las notificaciones a todas letras: «las
          solicitudes pendientes se ven al entrar en /friends, con un contador».
          Si esta lista fuera la tercera, entrar aquí no sería verlas.
        -->
        <section v-if="amigos.recibidas.length" class="bloque">
          <h2 class="bloque__titulo">
            Solicitudes recibidas
            <span class="bloque__cuenta bloque__cuenta--atencion">{{ amigos.contadores.pending }}</span>
          </h2>
          <p class="bloque__nota">
            Aceptar deja ver a esa persona lo que tengas en el nivel «amigos» —el valor de tu
            colección y tu lista de deseos, si no los has cambiado—.
          </p>

          <ul class="filas">
            <li v-for="fila in amigos.recibidas" :key="fila.friendshipId" class="fila">
              <PersonaFila :persona="fila.user" :desde="fila.since" />

              <div class="fila__acciones">
                <Button
                  label="Aceptar"
                  icon="pi pi-check"
                  size="small"
                  :disabled="amigos.estaMoviendo(fila.friendshipId)"
                  @click="amigos.aceptar(fila)"
                />
                <Button
                  label="Rechazar"
                  icon="pi pi-times"
                  size="small"
                  severity="secondary"
                  text
                  :disabled="amigos.estaMoviendo(fila.friendshipId)"
                  @click="amigos.rechazar(fila)"
                />
              </div>
            </li>
          </ul>
        </section>

        <!--
          EL BUSCADOR VA DESPUÉS DE LAS SOLICITUDES RECIBIDAS Y ANTES DE TODO LO
          DEMÁS, y las dos mitades de esa frase son decisiones.

          Después de las recibidas, porque la condición con la que el plan deja las
          notificaciones fuera de alcance —«las solicitudes pendientes se ven al
          entrar en /friends»— manda sobre todo lo demás de esta pantalla: lo que
          alguien te ha pedido a ti se ve antes que la herramienta para pedirle
          algo a alguien. Y antes de los amigos, las enviadas y los seguidos,
          porque **esas tres listas no tienen nada que hacer y esto sí**: es para
          lo que se entra aquí desde el M6.

          Dentro del `v-else`, o sea que mientras cargan las listas no hay campo de
          búsqueda. Es a propósito: lo que se hace con un resultado es pedir
          amistad, y el botón correcto de cada fila sale de cruzarlo con las listas
          (`relacionCon`). Con las listas a medio cargar, a un amigo se le
          ofrecería «Pedir amistad» — la misma mentira sobre un permiso que el M5
          evitó no pintando ningún botón mientras no se supiera la relación.
        -->
        <section class="bloque bloque--buscar">
          <h2 class="bloque__titulo">
            <i class="pi pi-search"></i> Buscar personas
          </h2>
          <p class="bloque__nota">
            Por el <strong>principio</strong> de su nombre de usuario, desde tres letras. Quien haya
            apagado «Aparecer en el buscador» en su privacidad no sale aquí, y no hay forma de
            distinguirlo de que no exista: eso es lo que hace que el interruptor sirva de algo. El
            tuyo se cambia en el panel de privacidad de la portada.
          </p>

          <form class="buscador" @submit.prevent="buscar">
            <InputText
              v-model="consulta"
              class="buscador__campo"
              placeholder="nombre de usuario"
              aria-label="Buscar personas por su nombre de usuario"
              :disabled="amigos.buscando"
            />
            <Button
              type="submit"
              label="Buscar"
              icon="pi pi-search"
              size="small"
              :loading="amigos.buscando"
              :disabled="amigos.buscando"
            />
            <Button
              v-if="amigos.busquedaHecha || consulta"
              type="button"
              label="Limpiar"
              icon="pi pi-times"
              size="small"
              severity="secondary"
              text
              @click="limpiarBusqueda"
            />
          </form>

          <!--
            Aquí aterriza el 422 de «escribe al menos 3 caracteres», que es el
            error más frecuente de esta pantalla porque se escribe mientras se
            teclea. El mensaje es el del backend tal cual: el mínimo NO se replica
            en el cliente, por lo mismo que el resto de este store no reescribe los
            mensajes del servidor.
          -->
          <p v-if="amigos.errorBusqueda" class="amigos__error amigos__error--busqueda">
            <i class="pi pi-exclamation-triangle"></i> {{ amigos.errorBusqueda }}
          </p>

          <ul v-else-if="amigos.resultados.length" class="filas">
            <li v-for="persona in amigos.resultados" :key="persona.username" class="fila">
              <PersonaFila :persona="persona" />

              <div class="fila__acciones">
                <!--
                  UN SOLO BOTÓN Y NUNCA DOS, y por la misma razón que en el perfil
                  público del M5: `relacionCon()` devuelve UN valor de cuatro, así
                  que esta cadena `v-if`/`v-else-if` es excluyente por
                  construcción. Y el estado sale de las listas que ya están
                  cargadas, así que en cuanto `pedir()` mete la fila en `enviadas`
                  este botón se convierte solo en «Solicitud enviada» sin volver a
                  buscar nada.
                -->
                <Button
                  v-if="amigos.relacionCon(persona.username).amistad === 'nada'"
                  label="Pedir amistad"
                  icon="pi pi-user-plus"
                  size="small"
                  :disabled="amigos.amistadOcupada(persona.username)"
                  @click="amigos.pedir(persona.username)"
                />
                <span v-else class="fila__estado">
                  {{ ESTADO_DE_LA_RELACION[amigos.relacionCon(persona.username).amistad] }}
                </span>
              </div>
            </li>
          </ul>

          <!--
            «Nadie que empiece por X» y no «sin resultados»: lo segundo no dice
            sobre qué, y la respuesta correcta a una búsqueda vacía es volver a
            escribir. Sale solo si ya se buscó algo — antes de la primera búsqueda
            una lista vacía significa otra cosa.
          -->
          <p v-else-if="amigos.busquedaHecha" class="bloque__vacio">
            Nadie con una cuenta que empiece por «{{ amigos.consulta }}». Puede que no exista, o que
            haya preferido no aparecer.
          </p>
        </section>

        <section class="bloque">
          <h2 class="bloque__titulo">
            Amigos
            <span class="bloque__cuenta">{{ amigos.contadores.friends }}</span>
          </h2>

          <p v-if="!amigos.amigos.length" class="bloque__vacio">
            Todavía no tienes ningún amigo aquí. Se pide amistad desde el perfil de la otra persona,
            en <code>/user/su-nombre</code>.
          </p>

          <ul v-else class="filas">
            <li v-for="fila in amigos.amigos" :key="fila.friendshipId" class="fila">
              <PersonaFila :persona="fila.user" :desde="fila.since" />

              <div class="fila__acciones">
                <Button
                  label="Quitar amistad"
                  icon="pi pi-user-minus"
                  size="small"
                  severity="danger"
                  text
                  :disabled="amigos.estaMoviendo(fila.friendshipId)"
                  @click="amigos.deshacer(fila)"
                />
              </div>
            </li>
          </ul>
        </section>

        <!--
          LAS ENVIADAS, con «retirar» y no con «cancelar»: lo que hace es
          `friend_remove`, la misma acción que deshacer una amistad. Es la
          enmienda del 2026-09-14 al plan —antes `friend_remove` solo valía sobre
          una amistad aceptada y quien enviaba una solicitud no tenía forma de
          retirarla, porque rechazar es solo del destinatario—.
        -->
        <section v-if="amigos.enviadas.length" class="bloque">
          <h2 class="bloque__titulo">
            Solicitudes enviadas
            <span class="bloque__cuenta">{{ amigos.contadores.sent }}</span>
          </h2>
          <p class="bloque__nota">
            Están esperando respuesta. No hay nada más que hacer con ellas, salvo retirarlas.
          </p>

          <ul class="filas">
            <li v-for="fila in amigos.enviadas" :key="fila.friendshipId" class="fila">
              <PersonaFila :persona="fila.user" :desde="fila.since" />

              <div class="fila__acciones">
                <Button
                  label="Retirar"
                  icon="pi pi-undo"
                  size="small"
                  severity="secondary"
                  text
                  :disabled="amigos.estaMoviendo(fila.friendshipId)"
                  @click="amigos.retirar(fila)"
                />
              </div>
            </li>
          </ul>
        </section>

        <!--
          SEGUIDOS. Va en la misma pantalla porque es la otra lista de gente que
          el usuario administra, y en un bloque APARTE con su propio texto porque
          no es una amistad a medias: seguir no se pide, no se acepta y **no da
          ningún acceso**. Si algún día estas filas se mezclaran con las de
          arriba, el nivel «amigos» habría empezado a significar otra cosa.
        -->
        <section class="bloque">
          <h2 class="bloque__titulo">
            Sigues a
            <span class="bloque__cuenta">{{ amigos.siguiendo.length }}</span>
          </h2>
          <p class="bloque__nota">
            Un marcador para volver a un perfil sin buscarlo. No le pide permiso a nadie y
            <strong>no te deja ver nada</strong> que su dueño no enseñe ya en público.
          </p>

          <p v-if="amigos.errorSeguidos" class="amigos__error">
            <i class="pi pi-exclamation-triangle"></i> {{ amigos.errorSeguidos }}
          </p>

          <p v-else-if="!amigos.siguiendo.length" class="bloque__vacio">
            No sigues a nadie. El botón de seguir está en el perfil público de cada persona.
          </p>

          <ul v-else class="filas">
            <li v-for="fila in amigos.siguiendo" :key="fila.username" class="fila">
              <PersonaFila :persona="fila" :desde="fila.since" />

              <div class="fila__acciones">
                <Button
                  label="Dejar de seguir"
                  icon="pi pi-eye-slash"
                  size="small"
                  severity="secondary"
                  text
                  :disabled="amigos.estaSoltando(fila.username)"
                  @click="amigos.dejarDeSeguir(fila)"
                />
              </div>
            </li>
          </ul>
        </section>
      </template>
    </main>

    <!--
      El aviso discreto, con las mismas clases y el mismo comportamiento que
      `CollectionAviso.vue`: no interrumpe, no roba el foco y se va solo. Va
      aquí dentro y no reutiliza aquel componente porque aquel lee el `aviso` de
      los dos stores de colección y darle un tercer modo sería tocar un
      componente compartido para una pantalla que no tiene nada que ver.
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
import { h, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { RouterLink, useRouter } from 'vue-router'
import Button from 'primevue/button'
import InputText from 'primevue/inputtext'
import Skeleton from 'primevue/skeleton'

import { useFriendsStore } from '@/stores/friends'

/**
 * `/friends`: tus amigos, las solicitudes que te han hecho, las que has hecho tú
 * y a quién sigues.
 *
 * **Es la única ruta de este plan que va TRAS EL GUARD**, y es correcto: no es el
 * perfil de nadie, es tu lista. Todo lo que pinta sale de dos acciones `POST`
 * normales (`friend_list` y `follow_list`) por `apiCall`, no de las rutas
 * públicas: aquí siempre hay sesión.
 *
 * Dos decisiones de esta pantalla que no se ven en el markup:
 *
 *  - **Las recibidas van primero.** El plan deja las notificaciones fuera de
 *    alcance con una condición: «las solicitudes pendientes se ven al entrar en
 *    `/friends`, con un contador». Si hubiera que bajar para verlas, entrar aquí
 *    no sería verlas y esa condición dejaría de cumplirse.
 *  - **Los amigos y los seguidos son dos bloques y nunca una lista mezclada.**
 *    Son dos relaciones distintas en todo lo que importa, y la amistad es la
 *    única que **concede acceso**. Cada bloque dice por escrito lo que su
 *    relación hace, porque «seguir» en otras apps significa cosas que aquí no
 *    significa.
 *
 * **Y desde el M6 hay un buscador**, que es lo que hacía falta para que las cinco
 * acciones de amistad tuvieran puerta de entrada: `friend_request` va por nombre
 * EXACTO, así que hasta entonces solo se llegaba a alguien sabiéndoselo de
 * memoria. Pedir amistad desde un resultado **no estrena ninguna acción**: llama
 * al `pedir()` que el M5 puso en el store para el perfil público.
 *
 * Dos cosas del buscador que tampoco se ven en el markup:
 *
 *  - **El mínimo de tres caracteres lo dice el backend**, no esta vista. Se
 *    manda lo que haya y se enseña el 422 tal cual, por lo mismo que el 422 de
 *    `follow_add` en el perfil público: replicar la regla aquí sería un segundo
 *    juego que se desincroniza del primero.
 *  - **Quien apagó «Aparecer en el buscador» no sale, y no se puede distinguir
 *    de que no exista.** El backend contesta lo mismo en los dos casos —200 con
 *    lista vacía— y el texto del vacío lo dice con esas palabras: si la interfaz
 *    dijera «esta persona existe pero no sale», el interruptor no serviría de
 *    nada.
 */

/** Lo que tarda el aviso en irse solo, igual que en `CollectionAviso.vue`. */
const DURACION_AVISO_MS = 3500

/**
 * Qué se dice en un resultado cuando ya hay una relación, en un solo sitio.
 *
 * No hay entrada para `'nada'` a propósito: ese es el único caso que pinta un
 * botón, y tenerlo aquí invitaría a pintar un texto Y un botón a la vez.
 */
const ESTADO_DE_LA_RELACION = {
  amigos: 'Ya sois amigos',
  enviada: 'Solicitud enviada',
  recibida: 'Te ha pedido amistad'
}

const router = useRouter()
const amigos = useFriendsStore()

/**
 * Lo que hay escrito en el campo, **en la vista y no en el store**.
 *
 * El store guarda `consulta`, que es lo último que se BUSCÓ; esto es lo que se
 * está escribiendo, que no es lo mismo: el texto del vacío dice «nadie empieza
 * por X» y esa X tiene que ser lo que se buscó, no lo que el usuario haya
 * seguido tecleando después de ver el resultado.
 */
const consulta = ref('')

/** Lanza la búsqueda. El mínimo de tres caracteres lo contesta el backend. */
function buscar() {
  amigos.buscar(consulta.value)
}

/** Vacía el campo y el resultado, **sin tocar ninguna de las cuatro listas**. */
function limpiarBusqueda() {
  consulta.value = ''
  amigos.limpiarBusqueda()
}

/**
 * Una persona del listado: su avatar, su nombre y el enlace a su perfil.
 *
 * Se define aquí como componente funcional y no como `.vue` aparte porque lo usan
 * las cuatro listas de ESTA vista y nadie más; sacarlo a `components/` le daría
 * una vida propia que todavía no tiene.
 *
 * **El `username` es obligatorio en el enlace y siempre llega**: es la clave
 * pública del proyecto y el backend lo selecciona en las dos consultas. Aun así
 * el `router-link` solo se pinta si existe — un `router-link` con un `params`
 * obligatorio a `undefined` **lanza al resolver** y tumbaría el render entero de
 * la pantalla, que es exactamente el fallo que se llevó `/decks` por delante.
 *
 * Y **no hay ningún `email` que pintar**: el backend no lo manda en ninguno de
 * los dos listados. Si algún día llegara, el fallo estaría allí.
 */
function PersonaFila(props) {
  const persona = props.persona ?? {}
  const nombre = persona.displayName || persona.username || 'Sin nombre'

  const hijos = [
    persona.avatarUrl
      ? h('img', { class: 'persona__avatar', src: persona.avatarUrl, alt: '' })
      : h('span', { class: 'persona__avatar persona__avatar--vacio' }, [
        h('i', { class: 'pi pi-user' })
      ]),
    h('span', { class: 'persona__datos' }, [
      h('strong', { class: 'persona__nombre' }, nombre),
      persona.username
        ? h(
          RouterLink,
          {
            class: 'persona__enlace',
            to: { name: 'publicProfile', params: { username: persona.username } }
          },
          () => `@${persona.username}`
        )
        : null,
      props.desde ? h('small', { class: 'persona__desde' }, `desde ${fecha(props.desde)}`) : null
    ])
  ]

  return h('div', { class: 'persona' }, hijos)
}

PersonaFila.props = ['persona', 'desde']

const FORMATO_FECHA = new Intl.DateTimeFormat('es-ES', {
  day: 'numeric',
  month: 'long',
  year: 'numeric'
})

/**
 * La fecha que manda MySQL (`2026-09-14 06:47:45`), legible.
 *
 * Se le mete la `T` porque Safari no parsea el formato con espacio y devuelve
 * `Invalid Date`; y si aun así no se pudiera leer, se enseña la cadena cruda en
 * vez de un «Invalid Date» que no dice nada.
 */
function fecha(valor) {
  if (!valor) {
    return ''
  }

  const d = new Date(String(valor).replace(' ', 'T'))

  return Number.isNaN(d.getTime()) ? String(valor) : FORMATO_FECHA.format(d)
}

let temporizador = null

watch(
  () => amigos.aviso,
  (aviso) => {
    clearTimeout(temporizador)

    if (aviso) {
      temporizador = setTimeout(() => { amigos.aviso = null }, DURACION_AVISO_MS)
    }
  },
  { immediate: true }
)

onMounted(() => {
  // `forzar` SIEMPRE al entrar aquí, aunque la portada ya hubiera cargado el
  // contador: esta es la pantalla donde el plan dice que se ven las solicitudes
  // pendientes, así que tiene que traer lo de ahora mismo y no lo de la última
  // vez que se pasó por la portada. Además, la portada no pide `follow_list`.
  amigos.cargar()
})

onBeforeUnmount(() => {
  clearTimeout(temporizador)
  // Un aviso pendiente no debe reaparecer al volver a montar la vista. Las listas
  // NO se limpian: el contador de la portada vive en este mismo store y vaciarlo
  // al salir lo dejaría a cero hasta la siguiente petición.
  amigos.aviso = null
})
</script>

<style scoped>
.amigos__bar {
  display: flex;
  align-items: center;
  gap: 0.75rem;
  padding: 0.75rem 1.25rem;
  border-bottom: 1px solid var(--p-content-border-color);
}

.amigos__titulo {
  margin: 0;
  font-size: 1.25rem;
}

.amigos__seguidores {
  display: inline-flex;
  align-items: center;
  gap: 0.4rem;
  margin-left: auto;
  font-size: 0.8rem;
  color: var(--p-text-muted-color);
}

.amigos__main {
  max-width: 820px;
  margin: 0 auto;
  padding: 1.5rem 1rem;
}

.amigos__esqueletos {
  display: flex;
  flex-direction: column;
  gap: 0.75rem;
}

.amigos__error {
  display: flex;
  align-items: center;
  gap: 0.5rem;
  color: var(--p-red-500);
  font-size: 0.85rem;
}

.bloque {
  padding: 1rem 1.1rem;
  margin-bottom: 1rem;
  border: 1px solid var(--p-content-border-color);
  border-radius: 8px;
}

.bloque__titulo {
  display: flex;
  align-items: center;
  gap: 0.5rem;
  margin: 0;
  font-size: 0.95rem;
}

.bloque__cuenta {
  min-width: 1.5rem;
  padding: 0.05rem 0.45rem;
  border-radius: 999px;
  font-size: 0.75rem;
  text-align: center;
  color: var(--p-text-muted-color);
  background: var(--p-content-border-color);
}

.bloque__cuenta--atencion {
  color: var(--p-primary-contrast-color);
  background: var(--p-primary-color);
}

.bloque__nota {
  margin: 0.4rem 0 0;
  font-size: 0.75rem;
  line-height: 1.4;
  color: var(--p-text-muted-color);
}

.buscador {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 0.5rem;
  margin-top: 0.75rem;
}

.buscador__campo {
  flex: 1 1 14rem;
  min-width: 0;
}

.fila__estado {
  font-size: 0.78rem;
  color: var(--p-text-muted-color);
}

.amigos__error--busqueda {
  margin: 0.6rem 0 0;
}

.bloque__vacio {
  margin: 0.6rem 0 0;
  font-size: 0.8rem;
  color: var(--p-text-muted-color);
}

.filas {
  display: flex;
  flex-direction: column;
  gap: 0.5rem;
  margin: 0.75rem 0 0;
  padding: 0;
  list-style: none;
}

.fila {
  display: flex;
  align-items: center;
  gap: 0.75rem;
  flex-wrap: wrap;
  padding: 0.5rem 0;
  border-top: 1px solid var(--p-content-border-color);
}

.fila__acciones {
  display: flex;
  align-items: center;
  gap: 0.25rem;
  margin-left: auto;
}

.persona {
  display: flex;
  align-items: center;
  gap: 0.6rem;
  min-width: 0;
}

.persona__avatar {
  width: 36px;
  height: 36px;
  flex: none;
  border-radius: 50%;
}

.persona__avatar--vacio {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  color: var(--p-text-muted-color);
  background: var(--p-content-border-color);
}

.persona__datos {
  display: flex;
  flex-direction: column;
  min-width: 0;
}

.persona__nombre {
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
  font-size: 0.9rem;
}

.persona__enlace {
  font-size: 0.75rem;
  color: var(--p-primary-color);
  text-decoration: none;
}

.persona__enlace:hover {
  text-decoration: underline;
}

.persona__desde {
  font-size: 0.68rem;
  color: var(--p-text-muted-color);
}

/* El aviso, calcado de `CollectionAviso.vue`: no interrumpe y se va solo. */
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
