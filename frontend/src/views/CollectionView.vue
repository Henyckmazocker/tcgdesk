<template>
  <div class="coleccion">
    <header class="coleccion__bar">
      <Button icon="pi pi-arrow-left" text rounded aria-label="Volver" @click="router.push('/')" />
      <h1 class="coleccion__titulo">Mi colección</h1>

      <div class="coleccion__acciones-bar">
        <SelectButton
          :model-value="coleccion.vista"
          :options="VISTAS"
          option-label="label"
          option-value="value"
          :allow-empty="false"
          aria-labelledby="Modo de vista"
          @update:model-value="cambiarVista"
        >
          <template #option="{ option }">
            <i :class="option.icon" :title="option.label"></i>
          </template>
        </SelectButton>

        <Button
          :icon="panelAbierto ? 'pi pi-filter-slash' : 'pi pi-filter'"
          :badge="coleccion.filtrosActivos ? String(coleccion.filtrosActivos) : null"
          text
          rounded
          aria-label="Filtros"
          @click="panelAbierto = !panelAbierto"
        />
      </div>
    </header>

    <!--
      El panel es persistente: los filtros viven en la query string, así que
      recargar la página (o compartir el enlace) los conserva.
    -->
    <section v-if="panelAbierto" class="coleccion__filtros">
      <Select
        v-model="filtros.set"
        :options="opcionesSets"
        option-label="label"
        option-value="value"
        placeholder="Edición"
        filter
        show-clear
        class="coleccion__filtro"
      />
      <Select
        v-model="filtros.rarity"
        :options="RAREZAS"
        option-label="label"
        option-value="value"
        placeholder="Rareza"
        show-clear
        class="coleccion__filtro"
      />
      <MultiSelect
        v-model="coloresSeleccionados"
        :options="COLORES"
        option-label="label"
        option-value="value"
        placeholder="Colores"
        display="chip"
        class="coleccion__filtro"
      />
      <Select
        v-model="filtros.finish"
        :options="ACABADOS"
        option-label="label"
        option-value="value"
        placeholder="Acabado"
        show-clear
        class="coleccion__filtro"
      />
      <Select
        v-model="filtros.language"
        :options="IDIOMAS"
        option-label="label"
        option-value="value"
        placeholder="Idioma"
        filter
        show-clear
        class="coleccion__filtro"
      />
      <Select
        v-model="filtros.condition"
        :options="CONDICIONES"
        option-label="label"
        option-value="value"
        placeholder="Estado"
        show-clear
        class="coleccion__filtro"
      />
      <InputNumber v-model="filtros.price_min" placeholder="€ mín." :min="0" class="coleccion__filtro" />
      <InputNumber v-model="filtros.price_max" placeholder="€ máx." :min="0" class="coleccion__filtro" />
      <Select
        v-model="filtros.sort"
        :options="ORDENES"
        option-label="label"
        option-value="value"
        class="coleccion__filtro"
      />

      <div class="coleccion__acciones">
        <Button label="Aplicar" icon="pi pi-check" size="small" @click="aplicar" />
        <Button label="Limpiar" icon="pi pi-times" severity="secondary" text size="small" @click="limpiar" />
      </div>
    </section>

    <p v-if="coleccion.error" class="coleccion__error">
      <i class="pi pi-exclamation-triangle"></i> {{ coleccion.error }}
    </p>

    <!-- Primera carga: esqueletos con la forma real de la rejilla. -->
    <div v-if="coleccion.cargando" class="coleccion__rejilla">
      <Skeleton v-for="n in 12" :key="n" height="0" class="coleccion__esqueleto" />
    </div>

    <p v-else-if="coleccion.vacio" class="coleccion__vacio">
      <i class="pi pi-inbox"></i>
      <span v-if="coleccion.filtrosActivos">Ninguna carta de tu colección coincide con esos filtros.</span>
      <span v-else>Tu colección está vacía. Busca una carta en el catálogo y añádela.</span>
      <Button label="Ir al catálogo" icon="pi pi-search" text size="small" @click="router.push('/catalog')" />
    </p>

    <!-- REJILLA -->
    <div v-else-if="coleccion.vista === 'grid'" class="coleccion__rejilla">
      <article v-for="item in coleccion.items" :key="item.id" class="carta">
        <div
          class="carta__imagen"
          role="link"
          tabindex="0"
          @click="abrir(item)"
          @keyup.enter="abrir(item)"
        >
          <!-- Lazy-load obligatorio: una colección grande en rejilla son miles
               de peticiones al CDN si se renderizan todas de golpe. Lo resuelve
               CardImage con loading="lazy". -->
          <CardImage :scryfall-id="item.scryfallId" :nombre="item.name" tamano="small" />
          <span v-if="item.finish !== 'normal'" class="carta__acabado">{{ etiquetaAcabado(item.finish) }}</span>
        </div>

        <div class="carta__pie">
          <span class="carta__nombre" :title="item.name">{{ item.name }}</span>
          <span class="carta__meta">
            <span>{{ item.setCode }} · {{ item.collectorNumber }} · {{ item.language }}</span>
            <span class="carta__precio" :class="{ 'carta__precio--sin': item.priceEur === null }">
              {{ precioDe(item) }}
            </span>
          </span>
          <CollectionControls :item="item" compacto />
        </div>
      </article>
    </div>

    <!-- TABLA -->
    <div v-else class="coleccion__tabla">
      <DataTable :value="coleccion.items" data-key="id" size="small" striped-rows>
        <Column header="" style="width: 3rem">
          <template #body="{ data }">
            <div class="tabla__miniatura" role="link" tabindex="0" @click="abrir(data)" @keyup.enter="abrir(data)">
              <CardImage :scryfall-id="data.scryfallId" :nombre="data.name" tamano="small" />
            </div>
          </template>
        </Column>
        <Column field="name" header="Carta">
          <template #body="{ data }">
            <a class="tabla__nombre" href="#" @click.prevent="abrir(data)">{{ data.name }}</a>
            <small class="tabla__sub">{{ data.setName }} · {{ data.collectorNumber }}</small>
          </template>
        </Column>
        <Column field="rarity" header="Rareza">
          <template #body="{ data }">{{ etiquetaRareza(data.rarity) }}</template>
        </Column>
        <Column field="finish" header="Acabado">
          <template #body="{ data }">{{ etiquetaAcabado(data.finish) }}</template>
        </Column>
        <Column field="language" header="Idioma" />
        <Column header="Precio">
          <template #body="{ data }">
            <span :class="{ 'carta__precio--sin': data.priceEur === null }">{{ precioDe(data) }}</span>
          </template>
        </Column>
        <Column header="Valor">
          <template #body="{ data }">{{ data.lineValue.toFixed(2) }} €</template>
        </Column>
        <Column header="Cantidad y estado" style="min-width: 15rem">
          <template #body="{ data }">
            <CollectionControls :item="data" />
          </template>
        </Column>
      </DataTable>
    </div>

    <!-- Centinela del scroll infinito: cuando entra en pantalla, se pide más. -->
    <div ref="centinela" class="coleccion__centinela">
      <ProgressSpinner v-if="coleccion.cargandoMas" style="width: 2rem; height: 2rem" />
      <span v-else-if="!coleccion.hayMas && coleccion.items.length > 0" class="coleccion__fin">
        No hay más cartas
      </span>
    </div>

    <!--
      Aviso de las ediciones en línea. Desde M4 es un componente compartido, para
      que el "Añadir" del catálogo y de la ficha enseñen exactamente lo mismo.
    -->
    <CollectionAviso />
  </div>
</template>

<script setup>
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import Button from 'primevue/button'
import Column from 'primevue/column'
import DataTable from 'primevue/datatable'
import InputNumber from 'primevue/inputnumber'
import MultiSelect from 'primevue/multiselect'
import ProgressSpinner from 'primevue/progressspinner'
import Select from 'primevue/select'
import SelectButton from 'primevue/selectbutton'
import Skeleton from 'primevue/skeleton'

import CardImage from '@/components/CardImage.vue'
import CollectionAviso from '@/components/CollectionAviso.vue'
import CollectionControls from '@/components/CollectionControls.vue'
import { ACABADOS, COLORES, CONDICIONES, IDIOMAS, RAREZAS, etiquetaAcabado, etiquetaRareza } from '@/constants/collection'
import { useCatalogStore } from '@/stores/catalog'
import { useCollectionStore } from '@/stores/collection'

const VISTAS = [
  { label: 'Rejilla', value: 'grid', icon: 'pi pi-th-large' },
  { label: 'Tabla', value: 'table', icon: 'pi pi-list' }
]

const ORDENES = [
  { label: 'Precio ↓', value: 'price_desc' },
  { label: 'Precio ↑', value: 'price_asc' },
  { label: 'Nombre', value: 'name' },
  { label: 'Fecha de salida', value: 'release' },
  { label: 'Rareza', value: 'rarity' },
  { label: 'Cantidad', value: 'quantity' },
  { label: 'Añadidas', value: 'added' }
]

const router = useRouter()
const route = useRoute()
const coleccion = useCollectionStore()
const catalogo = useCatalogStore()

const panelAbierto = ref(false)
const centinela = ref(null)
const filtros = ref(estadoDeFiltrosVacio())
const coloresSeleccionados = ref([])

let observador = null

const opcionesSets = computed(() =>
  catalogo.sets.map((s) => ({ label: `${s.name} (${s.code})`, value: s.code }))
)

function estadoDeFiltrosVacio() {
  return {
    set: '',
    rarity: '',
    finish: '',
    language: '',
    condition: '',
    price_min: null,
    price_max: null,
    sort: 'price_desc'
  }
}

/**
 * El precio de la línea. `null` NO es cero: una carta que no cotiza en
 * Cardmarket dice "sin precio", jamás "0 €" — y sigue apareciendo en la lista.
 */
function precioDe(item) {
  return item.priceEur === null ? 'sin precio' : `${item.priceEur.toFixed(2)} €`
}

function abrir(item) {
  router.push({ name: 'card', params: { uuid: item.printingUuid } })
}

/** Los filtros van a la query string, y de ahí al store. */
function aplicar() {
  router.replace({
    name: 'collection',
    query: {
      set: filtros.value.set || undefined,
      rarity: filtros.value.rarity || undefined,
      colors: coloresSeleccionados.value.join('') || undefined,
      finish: filtros.value.finish || undefined,
      language: filtros.value.language || undefined,
      condition: filtros.value.condition || undefined,
      price_min: filtros.value.price_min ?? undefined,
      price_max: filtros.value.price_max ?? undefined,
      sort: filtros.value.sort !== 'price_desc' ? filtros.value.sort : undefined,
      view: coleccion.vista === 'table' ? 'table' : undefined
    }
  })
}

function limpiar() {
  filtros.value = estadoDeFiltrosVacio()
  coloresSeleccionados.value = []
  router.replace({
    name: 'collection',
    query: coleccion.vista === 'table' ? { view: 'table' } : {}
  })
}

function cambiarVista(vista) {
  coleccion.vista = vista
  aplicar()
}

/** La query string es la fuente de verdad: se lee al entrar y al navegar. */
function sincronizarDesdeQuery() {
  coleccion.desdeQuery(route.query)

  filtros.value = {
    set: coleccion.filtros.set,
    rarity: coleccion.filtros.rarity,
    finish: coleccion.filtros.finish,
    language: coleccion.filtros.language,
    condition: coleccion.filtros.condition,
    price_min: coleccion.filtros.price_min === '' ? null : Number(coleccion.filtros.price_min),
    price_max: coleccion.filtros.price_max === '' ? null : Number(coleccion.filtros.price_max),
    sort: coleccion.filtros.sort
  }
  coloresSeleccionados.value = coleccion.filtros.colors ? coleccion.filtros.colors.split('') : []

  return coleccion.buscar()
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
        coleccion.cargarMas()
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
.coleccion {
  padding-bottom: 2rem;
}

.coleccion__bar {
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

.coleccion__titulo {
  margin: 0;
  font-size: 1.1rem;
}

.coleccion__acciones-bar {
  display: flex;
  align-items: center;
  gap: 0.5rem;
}

.coleccion__filtros {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(160px, 1fr));
  gap: 0.75rem;
  padding: 0.75rem;
}

.coleccion__filtro {
  width: 100%;
}

.coleccion__acciones {
  display: flex;
  gap: 0.5rem;
  align-items: center;
  grid-column: 1 / -1;
}

.coleccion__rejilla {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(170px, 1fr));
  gap: 1rem;
  padding: 0.75rem;
}

.coleccion__esqueleto {
  aspect-ratio: 488 / 680;
  height: auto !important;
  border-radius: 4.75% / 3.5%;
}

.coleccion__tabla {
  padding: 0 0.75rem;
  overflow-x: auto;
}

.carta__imagen {
  position: relative;
  cursor: pointer;
}

.carta__acabado {
  position: absolute;
  top: 0.35rem;
  right: 0.35rem;
  z-index: 2;
  padding: 0.05rem 0.35rem;
  border-radius: 999px;
  font-size: 0.65rem;
  text-transform: uppercase;
  letter-spacing: 0.03em;
  color: var(--p-primary-contrast-color);
  background: var(--p-primary-color);
}

.carta__pie {
  display: flex;
  flex-direction: column;
  gap: 0.25rem;
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
  white-space: nowrap;
}

/* "sin precio" no es un cero: se marca como ausencia, no como valor. */
.carta__precio--sin {
  font-style: italic;
  opacity: 0.7;
}

.tabla__miniatura {
  width: 2.6rem;
  cursor: pointer;
}

.tabla__nombre {
  display: block;
  color: inherit;
  text-decoration: none;
}

.tabla__nombre:hover {
  text-decoration: underline;
}

.tabla__sub {
  display: block;
  color: var(--p-text-muted-color);
  font-size: 0.7rem;
}

.coleccion__vacio,
.coleccion__error {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 0.5rem;
  padding: 3rem 1rem;
  color: var(--p-text-muted-color);
}

.coleccion__error {
  color: var(--p-red-500);
}

.coleccion__centinela {
  display: flex;
  align-items: center;
  justify-content: center;
  min-height: 4rem;
}

.coleccion__fin {
  font-size: 0.8rem;
  color: var(--p-text-muted-color);
}
</style>
