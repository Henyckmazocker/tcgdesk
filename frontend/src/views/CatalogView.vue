<template>
  <div class="catalogo">
    <header class="catalogo__bar">
      <Button icon="pi pi-arrow-left" text rounded aria-label="Volver" @click="router.push('/')" />
      <h1 class="catalogo__titulo">Catálogo</h1>
      <Button
        :icon="panelAbierto ? 'pi pi-filter-slash' : 'pi pi-filter'"
        :badge="catalogo.filtrosActivos ? String(catalogo.filtrosActivos) : null"
        text
        rounded
        aria-label="Filtros"
        @click="panelAbierto = !panelAbierto"
      />
    </header>

    <div class="catalogo__buscador">
      <IconField>
        <InputIcon class="pi pi-search" />
        <InputText
          v-model="textoBuscado"
          placeholder="Busca en cualquier idioma: luz de destierro, 太陽の指輪…"
          fluid
          @keyup.enter="aplicar"
        />
      </IconField>
    </div>

    <section v-if="panelAbierto" class="catalogo__filtros">
      <Select
        v-model="filtros.set"
        :options="opcionesSets"
        option-label="label"
        option-value="value"
        placeholder="Edición"
        filter
        show-clear
        class="catalogo__filtro"
      />
      <Select
        v-model="filtros.rarity"
        :options="RAREZAS"
        option-label="label"
        option-value="value"
        placeholder="Rareza"
        show-clear
        class="catalogo__filtro"
      />
      <MultiSelect
        v-model="coloresSeleccionados"
        :options="COLORES"
        option-label="label"
        option-value="value"
        placeholder="Colores"
        display="chip"
        class="catalogo__filtro"
      />
      <InputNumber v-model="filtros.price_min" placeholder="€ mín." :min="0" class="catalogo__filtro" />
      <InputNumber v-model="filtros.price_max" placeholder="€ máx." :min="0" class="catalogo__filtro" />
      <Select
        v-model="filtros.sort"
        :options="ORDENES"
        option-label="label"
        option-value="value"
        class="catalogo__filtro"
      />

      <div class="catalogo__acciones">
        <Button label="Aplicar" icon="pi pi-check" size="small" @click="aplicar" />
        <Button label="Limpiar" icon="pi pi-times" severity="secondary" text size="small" @click="limpiar" />
      </div>
    </section>

    <p v-if="catalogo.error" class="catalogo__error">
      <i class="pi pi-exclamation-triangle"></i> {{ catalogo.error }}
    </p>

    <!-- Primera carga: esqueletos con la forma real de la rejilla. -->
    <div v-if="catalogo.cargando" class="catalogo__rejilla">
      <Skeleton v-for="n in 12" :key="n" height="0" class="catalogo__esqueleto" />
    </div>

    <p v-else-if="catalogo.vacio" class="catalogo__vacio">
      <i class="pi pi-inbox"></i>
      Ninguna carta coincide con esa búsqueda.
    </p>

    <div v-else class="catalogo__rejilla">
      <article
        v-for="carta in catalogo.items"
        :key="carta.uuid"
        class="carta"
        role="link"
        tabindex="0"
        @click="abrir(carta)"
        @keyup.enter="abrir(carta)"
      >
        <CardImage :scryfall-id="carta.scryfallId" :nombre="carta.name" tamano="small" />
        <div class="carta__pie">
          <span class="carta__nombre">{{ carta.name }}</span>
          <span class="carta__meta">
            {{ carta.setCode }} · {{ carta.collectorNumber }}
            <span v-if="precioDe(carta)" class="carta__precio">{{ precioDe(carta) }}</span>
          </span>
        </div>
      </article>
    </div>

    <!-- Centinela del scroll infinito: cuando entra en pantalla, se pide más. -->
    <div ref="centinela" class="catalogo__centinela">
      <ProgressSpinner v-if="catalogo.cargandoMas" style="width: 2rem; height: 2rem" />
      <span v-else-if="!catalogo.hayMas && catalogo.items.length > 0" class="catalogo__fin">
        No hay más resultados
      </span>
    </div>
  </div>
</template>

<script setup>
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import Button from 'primevue/button'
import InputText from 'primevue/inputtext'
import InputNumber from 'primevue/inputnumber'
import IconField from 'primevue/iconfield'
import InputIcon from 'primevue/inputicon'
import Select from 'primevue/select'
import MultiSelect from 'primevue/multiselect'
import Skeleton from 'primevue/skeleton'
import ProgressSpinner from 'primevue/progressspinner'

import CardImage from '@/components/CardImage.vue'
import { useCatalogStore } from '@/stores/catalog'

const RAREZAS = [
  { label: 'Común', value: 'common' },
  { label: 'Infrecuente', value: 'uncommon' },
  { label: 'Rara', value: 'rare' },
  { label: 'Mítica', value: 'mythic' },
  { label: 'Especial', value: 'special' },
  { label: 'Bonus', value: 'bonus' }
]

const COLORES = [
  { label: 'Blanco', value: 'W' },
  { label: 'Azul', value: 'U' },
  { label: 'Negro', value: 'B' },
  { label: 'Rojo', value: 'R' },
  { label: 'Verde', value: 'G' }
]

const ORDENES = [
  { label: 'Relevancia', value: 'relevance' },
  { label: 'Nombre', value: 'name' },
  { label: 'Más recientes', value: 'release' },
  { label: 'Rareza', value: 'rarity' },
  { label: 'Precio ↑', value: 'price_asc' },
  { label: 'Precio ↓', value: 'price_desc' }
]

const router = useRouter()
const route = useRoute()
const catalogo = useCatalogStore()

const panelAbierto = ref(false)
const centinela = ref(null)
const textoBuscado = ref('')
const filtros = ref({ set: '', rarity: '', price_min: null, price_max: null, sort: 'relevance' })
const coloresSeleccionados = ref([])

let observador = null

const opcionesSets = computed(() =>
  catalogo.sets.map((s) => ({ label: `${s.name} (${s.code})`, value: s.code }))
)

/** El precio que se enseña en la rejilla: el del acabado que exista. */
function precioDe(carta) {
  const p = carta.priceEur?.normal ?? carta.priceEur?.foil ?? carta.priceEur?.etched

  return p === null || p === undefined ? null : `${p.toFixed(2)} €`
}

function abrir(carta) {
  router.push({ name: 'card', params: { uuid: carta.uuid } })
}

/** Los filtros van a la query string, y de ahí al store. */
function aplicar() {
  const query = {
    q: textoBuscado.value || undefined,
    set: filtros.value.set || undefined,
    rarity: filtros.value.rarity || undefined,
    colors: coloresSeleccionados.value.join('') || undefined,
    price_min: filtros.value.price_min ?? undefined,
    price_max: filtros.value.price_max ?? undefined,
    sort: filtros.value.sort !== 'relevance' ? filtros.value.sort : undefined
  }

  router.replace({ name: 'catalog', query })
}

function limpiar() {
  textoBuscado.value = ''
  filtros.value = { set: '', rarity: '', price_min: null, price_max: null, sort: 'relevance' }
  coloresSeleccionados.value = []
  router.replace({ name: 'catalog', query: {} })
}

/** La query string es la fuente de verdad: se lee al entrar y al navegar. */
function sincronizarDesdeQuery() {
  catalogo.desdeQuery(route.query)

  textoBuscado.value = catalogo.filtros.q
  filtros.value = {
    set: catalogo.filtros.set,
    rarity: catalogo.filtros.rarity,
    price_min: catalogo.filtros.price_min === '' ? null : Number(catalogo.filtros.price_min),
    price_max: catalogo.filtros.price_max === '' ? null : Number(catalogo.filtros.price_max),
    sort: catalogo.filtros.sort
  }
  coloresSeleccionados.value = catalogo.filtros.colors ? catalogo.filtros.colors.split('') : []

  return catalogo.buscar()
}

watch(() => route.query, sincronizarDesdeQuery)

onMounted(() => {
  catalogo.cargarSets()
  sincronizarDesdeQuery()

  // IntersectionObserver y no un listener de scroll: no dispara en cada píxel y
  // funciona igual dentro del WebView de Capacitor.
  observador = new IntersectionObserver(
    (entradas) => {
      if (entradas[0].isIntersecting) {
        catalogo.cargarMas()
      }
    },
    { rootMargin: '400px' } // se pide antes de llegar al final, para que no se note
  )

  if (centinela.value) {
    observador.observe(centinela.value)
  }
})

onBeforeUnmount(() => observador?.disconnect())
</script>

<style scoped>
.catalogo {
  padding-bottom: 2rem;
}

.catalogo__bar {
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

.catalogo__titulo {
  margin: 0;
  font-size: 1.1rem;
}

.catalogo__buscador {
  padding: 0.75rem;
}

.catalogo__filtros {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(160px, 1fr));
  gap: 0.75rem;
  padding: 0 0.75rem 0.75rem;
}

.catalogo__filtro {
  width: 100%;
}

.catalogo__acciones {
  display: flex;
  gap: 0.5rem;
  align-items: center;
  grid-column: 1 / -1;
}

.catalogo__rejilla {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(150px, 1fr));
  gap: 1rem;
  padding: 0 0.75rem;
}

.catalogo__esqueleto {
  aspect-ratio: 488 / 680;
  height: auto !important;
  border-radius: 4.75% / 3.5%;
}

.carta {
  cursor: pointer;
  transition: transform 0.15s ease;
}

.carta:hover,
.carta:focus-visible {
  transform: translateY(-3px);
  outline: none;
}

.carta__pie {
  display: flex;
  flex-direction: column;
  padding-top: 0.4rem;
  font-size: 0.78rem;
  line-height: 1.25;
}

.carta__nombre {
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.carta__meta {
  display: flex;
  justify-content: space-between;
  gap: 0.5rem;
  color: var(--p-text-muted-color);
  font-size: 0.72rem;
}

.carta__precio {
  font-variant-numeric: tabular-nums;
}

.catalogo__vacio,
.catalogo__error {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 0.5rem;
  padding: 3rem 1rem;
  color: var(--p-text-muted-color);
}

.catalogo__error {
  color: var(--p-red-500);
}

.catalogo__centinela {
  display: flex;
  align-items: center;
  justify-content: center;
  min-height: 4rem;
}

.catalogo__fin {
  font-size: 0.8rem;
  color: var(--p-text-muted-color);
}
</style>
