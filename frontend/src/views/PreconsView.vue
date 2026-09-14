<template>
  <div class="precons">
    <header class="precons__bar">
      <Button icon="pi pi-arrow-left" text rounded aria-label="Volver" @click="router.push('/')" />
      <h1 class="precons__titulo">Precons</h1>
      <Button
        :icon="panelAbierto ? 'pi pi-filter-slash' : 'pi pi-filter'"
        :badge="precons.filtrosActivos ? String(precons.filtrosActivos) : null"
        text
        rounded
        aria-label="Filtros"
        @click="panelAbierto = !panelAbierto"
      />
    </header>

    <div class="precons__buscador">
      <IconField>
        <InputIcon class="pi pi-search" />
        <InputText
          v-model="textoBuscado"
          placeholder="Busca una caja por su nombre: Sneak Attack, Arcane Maelstrom…"
          fluid
          @keyup.enter="aplicar"
        />
      </IconField>
    </div>

    <section v-if="panelAbierto" class="precons__filtros">
      <!--
        Los tipos vienen del backend con su recuento y su marca `playable`; aquí
        no hay ni una cadena de tipo escrita a mano. Van agrupados para que se
        vea de un golpe qué es un mazo y qué es un producto.
      -->
      <Select
        v-model="filtros.type"
        :options="opcionesTipos"
        option-label="label"
        option-value="value"
        option-group-label="label"
        option-group-children="items"
        placeholder="Tipo de mazo"
        filter
        show-clear
        class="precons__filtro"
      />
      <Select
        v-model="filtros.set"
        :options="opcionesEdiciones"
        option-label="label"
        option-value="value"
        placeholder="Edición"
        filter
        show-clear
        class="precons__filtro"
      />

      <div class="precons__acciones">
        <Button label="Aplicar" icon="pi pi-check" size="small" @click="aplicar" />
        <Button label="Limpiar" icon="pi pi-times" severity="secondary" text size="small" @click="limpiar" />
      </div>
    </section>

    <!--
      EL CONMUTADOR HONESTO. MTGJSON publica 3.029 «mazos» y los tres tipos más
      numerosos no lo son: Secret Lair Drop son cartas sueltas, MTGO Redemption
      es el canje de una edición digital y Bundle Land Pack son las tierras de
      una caja. Por defecto se enseñan solo los que se juegan, y esta línea dice
      en voz alta cuántos se están escondiendo y por qué. Quién es jugable lo
      decide el backend (`PreconPlayability`), no esta vista.
    -->
    <div class="precons__todo">
      <ToggleSwitch v-model="verTodo" input-id="ver-todo" @update:model-value="aplicar" />
      <label for="ver-todo">
        Ver todo
        <small v-if="precons.ocultosPorDefecto > 0">
          — {{ verTodo ? 'incluyendo' : 'ahora se ocultan' }}
          {{ precons.ocultosPorDefecto }} productos que no son mazos
          (Secret Lair, canjes de MTGO, packs de tierras…)
        </small>
      </label>
    </div>

    <p v-if="precons.error" class="precons__error">
      <i class="pi pi-exclamation-triangle"></i> {{ precons.error }}
    </p>

    <div v-if="precons.cargando" class="precons__rejilla">
      <Skeleton v-for="n in 12" :key="n" height="7.5rem" />
    </div>

    <p v-else-if="precons.vacio" class="precons__vacio">
      <i class="pi pi-inbox"></i>
      Ningún precon coincide con esa búsqueda.
    </p>

    <div v-else class="precons__rejilla">
      <!--
        La clave y el enlace van por `fileName`: es la clave natural de
        `mtg_precon` y hay cajas homónimas en ediciones distintas. Nunca puede
        ser undefined —el backend siempre lo manda—, que es lo que tumbaría el
        render entero de un `router-link` con un `params` obligatorio vacío.
      -->
      <article
        v-for="precon in precons.items"
        :key="precon.fileName"
        class="precon"
        role="link"
        tabindex="0"
        @click="abrir(precon)"
        @keyup.enter="abrir(precon)"
      >
        <h2 class="precon__nombre" :title="precon.name">{{ precon.name }}</h2>

        <Tag
          :value="precon.deckType"
          :severity="esJugable(precon.deckType) ? 'info' : 'warn'"
          class="precon__tipo"
        />

        <span class="precon__edicion">
          {{ precon.setName || precon.setCode }}
          <small>· {{ precon.setCode }}</small>
        </span>

        <span class="precon__meta">
          <span>{{ fecha(precon.releaseDate) }}</span>
          <!--
            `cardCount = 0` no es un error: cinco precons son productos de solo
            fichas y las fichas no cuentan ejemplares. Se dice, no se esconde.
          -->
          <span v-if="precon.cardCount > 0">{{ precon.cardCount }} cartas</span>
          <span v-else class="precon__solo-fichas">solo fichas</span>
        </span>
      </article>
    </div>

    <!-- Centinela del scroll infinito: cuando entra en pantalla, se pide más. -->
    <div ref="centinela" class="precons__centinela">
      <ProgressSpinner v-if="precons.cargandoMas" style="width: 2rem; height: 2rem" />
      <span v-else-if="!precons.hayMas && precons.items.length > 0" class="precons__fin">
        No hay más resultados
      </span>
    </div>
  </div>
</template>

<script setup>
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import Button from 'primevue/button'
import IconField from 'primevue/iconfield'
import InputIcon from 'primevue/inputicon'
import InputText from 'primevue/inputtext'
import ProgressSpinner from 'primevue/progressspinner'
import Select from 'primevue/select'
import Skeleton from 'primevue/skeleton'
import Tag from 'primevue/tag'
import ToggleSwitch from 'primevue/toggleswitch'

import { usePreconStore } from '@/stores/precons'

/**
 * El catálogo de mazos preconstruidos.
 *
 * Copia el patrón de `CatalogView.vue` —rejilla, scroll infinito por cursor y
 * filtros en la query string— porque es el mismo problema. Lo que cambia es el
 * filtro de tipo, que aquí **tiene que ser honesto**: de los 48 tipos que
 * publica MTGJSON, cinco no son mazos y suman 1.048 de las 3.029 cajas. El
 * defecto enseña solo lo jugable y el conmutador «ver todo» destapa el resto;
 * la clasificación la manda el backend en `playable`, aquí no se copia ninguna
 * cadena de tipo.
 */

const router = useRouter()
const route = useRoute()
const precons = usePreconStore()

const panelAbierto = ref(false)
const centinela = ref(null)
const textoBuscado = ref('')
const verTodo = ref(false)
const filtros = ref({ type: '', set: '' })

let observador = null

/** Los tipos del backend, agrupados por «esto es un mazo» y «esto no lo es». */
const opcionesTipos = computed(() => {
  const grupo = (label, tipos) => ({
    label,
    items: tipos.map((t) => ({ label: `${t.type} (${t.count})`, value: t.type }))
  })

  const grupos = [grupo('Mazos', precons.tiposJugables)]

  // Los productos solo se ofrecen con el «ver todo» puesto: elegir uno con el
  // filtro honesto activo daría cero resultados sin explicar por qué.
  if (verTodo.value && precons.tiposNoJugables.length > 0) {
    grupos.push(grupo('No son mazos', precons.tiposNoJugables))
  }

  return grupos
})

/** Las 295 ediciones que tienen precon, con cuántas cajas trae cada una. */
const opcionesEdiciones = computed(() =>
  precons.ediciones.map((e) => ({
    label: `${e.name || e.code} (${e.count})`,
    value: e.code
  }))
)

/** ¿El tipo de esta caja es un mazo? Lo dice la faceta, no una lista de aquí. */
function esJugable(tipo) {
  const faceta = precons.tipos.find((t) => t.type === tipo)

  return faceta ? faceta.playable : true
}

/** La fecha de salida, en formato de por aquí. Puede venir a null. */
function fecha(iso) {
  if (!iso) {
    return 'sin fecha'
  }

  const [anio, mes, dia] = iso.split('-')

  return `${dia}/${mes}/${anio}`
}

function abrir(precon) {
  router.push({ name: 'precon', params: { fileName: precon.fileName } })
}

/** Los filtros van a la query string, y de ahí al store. */
function aplicar() {
  router.replace({
    name: 'precons',
    query: {
      q: textoBuscado.value || undefined,
      type: filtros.value.type || undefined,
      set: filtros.value.set || undefined,
      // La ausencia de `all` es el defecto honesto: solo mazos.
      all: verTodo.value ? '1' : undefined
    }
  })
}

function limpiar() {
  textoBuscado.value = ''
  filtros.value = { type: '', set: '' }
  verTodo.value = false
  router.replace({ name: 'precons', query: {} })
}

/** La query string es la fuente de verdad: se lee al entrar y al navegar. */
function sincronizarDesdeQuery() {
  precons.desdeQuery(route.query)

  textoBuscado.value = precons.filtros.q
  filtros.value = { type: precons.filtros.type, set: precons.filtros.set }
  verTodo.value = precons.filtros.todo

  return precons.buscar()
}

watch(() => route.query, sincronizarDesdeQuery)

onMounted(() => {
  sincronizarDesdeQuery()

  // IntersectionObserver y no un listener de scroll: no dispara en cada píxel y
  // funciona igual dentro del WebView de Capacitor.
  observador = new IntersectionObserver(
    (entradas) => {
      if (entradas[0].isIntersecting) {
        precons.cargarMas()
      }
    },
    { rootMargin: '400px' }
  )

  if (centinela.value) {
    observador.observe(centinela.value)
  }
})

onBeforeUnmount(() => observador?.disconnect())
</script>

<style scoped>
.precons {
  padding-bottom: 2rem;
}

.precons__bar {
  position: sticky;
  top: 0;
  z-index: 10;
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 0.5rem;
  padding: 0.5rem 0.75rem;
  background: var(--p-content-background);
  border-bottom: 1px solid var(--p-content-border-color);
}

.precons__titulo {
  margin: 0;
  font-size: 1.1rem;
}

.precons__buscador {
  padding: 0.75rem;
}

.precons__filtros {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
  gap: 0.75rem;
  padding: 0 0.75rem 0.75rem;
}

.precons__filtro {
  width: 100%;
}

.precons__acciones {
  display: flex;
  gap: 0.5rem;
  align-items: center;
  grid-column: 1 / -1;
}

.precons__todo {
  display: flex;
  align-items: center;
  gap: 0.6rem;
  padding: 0 0.75rem 0.75rem;
  font-size: 0.85rem;
}

.precons__todo small {
  color: var(--p-text-muted-color);
}

.precons__rejilla {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(240px, 1fr));
  gap: 0.75rem;
  padding: 0 0.75rem;
}

.precon {
  display: flex;
  flex-direction: column;
  align-items: flex-start;
  gap: 0.35rem;
  padding: 0.75rem;
  border: 1px solid var(--p-content-border-color);
  border-radius: 8px;
  background: var(--p-content-background);
  cursor: pointer;
  transition: transform 0.15s ease, border-color 0.15s ease;
}

.precon:hover,
.precon:focus-visible {
  transform: translateY(-2px);
  border-color: var(--p-primary-color);
  outline: none;
}

.precon__nombre {
  margin: 0;
  font-size: 0.95rem;
  line-height: 1.2;
}

.precon__tipo {
  font-size: 0.7rem;
}

.precon__edicion {
  font-size: 0.8rem;
  color: var(--p-text-color);
}

.precon__edicion small,
.precon__meta {
  color: var(--p-text-muted-color);
}

.precon__meta {
  display: flex;
  gap: 0.75rem;
  font-size: 0.72rem;
}

.precon__solo-fichas {
  font-style: italic;
}

.precons__vacio,
.precons__error {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 0.5rem;
  padding: 3rem 1rem;
  color: var(--p-text-muted-color);
}

.precons__error {
  color: var(--p-red-500);
}

.precons__centinela {
  display: flex;
  align-items: center;
  justify-content: center;
  min-height: 4rem;
}

.precons__fin {
  font-size: 0.8rem;
  color: var(--p-text-muted-color);
}
</style>
