<template>
  <div class="deseos">
    <header class="deseos__bar">
      <Button icon="pi pi-arrow-left" text rounded aria-label="Volver" @click="router.push('/')" />
      <h1 class="deseos__titulo">Lista de deseos</h1>

      <div class="deseos__acciones-bar">
        <SelectButton
          :model-value="deseos.vista"
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
          :badge="deseos.filtrosActivos ? String(deseos.filtrosActivos) : null"
          text
          rounded
          aria-label="Filtros"
          @click="panelAbierto = !panelAbierto"
        />
      </div>
    </header>

    <!--
      Los mismos filtros que `/collection`, y **ninguno de deseos**: aquí no hay
      nada que elegir, porque la lista ya ES el conjunto de deseos. El panel es
      persistente y viaja en la query string, así que recargar los conserva.
    -->
    <section v-if="panelAbierto" class="deseos__filtros">
      <Select
        v-model="filtros.set"
        :options="opcionesSets"
        option-label="label"
        option-value="value"
        placeholder="Edición"
        filter
        show-clear
        class="deseos__filtro"
      />
      <Select
        v-model="filtros.rarity"
        :options="RAREZAS"
        option-label="label"
        option-value="value"
        placeholder="Rareza"
        show-clear
        class="deseos__filtro"
      />
      <MultiSelect
        v-model="coloresSeleccionados"
        :options="COLORES"
        option-label="label"
        option-value="value"
        placeholder="Colores"
        display="chip"
        class="deseos__filtro"
      />
      <Select
        v-model="filtros.finish"
        :options="ACABADOS"
        option-label="label"
        option-value="value"
        placeholder="Acabado"
        show-clear
        class="deseos__filtro"
      />
      <Select
        v-model="filtros.language"
        :options="IDIOMAS"
        option-label="label"
        option-value="value"
        placeholder="Idioma"
        filter
        show-clear
        class="deseos__filtro"
      />
      <Select
        v-model="filtros.condition"
        :options="CONDICIONES"
        option-label="label"
        option-value="value"
        placeholder="Estado"
        show-clear
        class="deseos__filtro"
      />
      <InputNumber v-model="filtros.price_min" placeholder="€ mín." :min="0" class="deseos__filtro" />
      <InputNumber v-model="filtros.price_max" placeholder="€ máx." :min="0" class="deseos__filtro" />
      <Select
        v-model="filtros.sort"
        :options="ORDENES"
        option-label="label"
        option-value="value"
        class="deseos__filtro"
      />

      <div class="deseos__acciones">
        <Button label="Aplicar" icon="pi pi-check" size="small" @click="aplicar" />
        <Button label="Limpiar" icon="pi pi-times" severity="secondary" text size="small" @click="limpiar" />
      </div>
    </section>

    <!--
      EL VALOR DE LO QUE QUIERES
      -------------------------------------------------------------------------
      Es `collection_value` con `is_wishlist: true` — la misma llamada del
      dashboard sobre el otro conjunto de la tabla—, así que las cifras de aquí
      y las de `/` no pueden contradecirse: las calcula el mismo código.

      El total va arriba y el desglose detrás de un botón, y no al revés: esta
      pantalla es una LISTA, y un tablero de seis paneles antes de la primera
      carta convertiría la lista en el pie de página de su propio resumen. Lo
      que casi siempre se viene a mirar es una cifra —cuánto cuesta comprarlo
      todo—; el reparto por edición, por rareza y el top 10 es la respuesta a la
      pregunta siguiente, y se pide.
    -->
    <p v-if="deseos.errorResumen" class="deseos__error">
      <i class="pi pi-exclamation-triangle"></i> {{ deseos.errorResumen }}
    </p>

    <section v-if="deseos.cargandoResumen && !resumen" class="valor">
      <Skeleton height="4.5rem" />
    </section>

    <section v-else-if="tieneValor" class="valor">
      <div class="valor__cifras">
        <article class="valor__tarjeta valor__tarjeta--principal">
          <span class="valor__etiqueta">Lo que cuesta tu lista</span>
          <strong class="valor__total">{{ euros(totales.valueEur) }}</strong>
          <span class="valor__nota">
            A precio de Cardmarket de hoy. Se recalcula en cada visita: nunca se guarda.
          </span>
        </article>

        <article class="valor__tarjeta">
          <span class="valor__etiqueta">Ejemplares</span>
          <strong class="valor__cifra">{{ totales.totalCopies }}</strong>
          <span class="valor__nota">
            que quieres, en {{ totales.uniqueItems }}
            {{ totales.uniqueItems === 1 ? 'línea' : 'líneas' }}
          </span>
        </article>

        <!--
          Una carta sin precio sigue contando como carta y solo deja de sumar
          euros. Sin este dato, un total bajo sería ambiguo: no se sabría si es
          que no quieres nada caro o si es que faltan precios.
        -->
        <article class="valor__tarjeta">
          <span class="valor__etiqueta">Sin precio</span>
          <strong class="valor__cifra">{{ totales.itemsWithoutPrice }}</strong>
          <span class="valor__nota">
            {{ totales.itemsWithoutPrice === 1 ? 'línea no cotiza' : 'líneas no cotizan' }}
            en Cardmarket. Cuentan como cartas; no suman euros.
          </span>
        </article>

        <Button
          :label="desgloseAbierto ? 'Ocultar el desglose' : 'Ver el desglose'"
          :icon="desgloseAbierto ? 'pi pi-chevron-up' : 'pi pi-chevron-down'"
          icon-pos="right"
          severity="secondary"
          text
          size="small"
          class="valor__conmutador"
          @click="desgloseAbierto = !desgloseAbierto"
        />
      </div>

      <div v-if="desgloseAbierto" class="valor__desglose">
        <!-- POR EDICIÓN -->
        <section class="valor__panel">
          <header class="valor__panel-cabecera">
            <h2 class="valor__panel-titulo">Por edición</h2>
            <router-link :to="{ name: 'sets' }" class="valor__panel-enlace">
              {{ resumen.bySet.length }}
              {{ resumen.bySet.length === 1 ? 'edición' : 'ediciones' }}
            </router-link>
          </header>

          <ul class="barras">
            <li v-for="fila in edicionesVisibles" :key="fila.setCode" class="barra">
              <div class="barra__fila">
                <span class="barra__nombre" :title="fila.setName">{{ fila.setName }}</span>
                <span class="barra__valor">{{ euros(fila.valueEur) }}</span>
              </div>
              <div class="barra__pista">
                <div class="barra__relleno" :style="{ width: ancho(fila.valueEur, maxEdicion) }"></div>
              </div>
              <small class="barra__nota">{{ fila.copies }} ejemplares</small>
            </li>
          </ul>
        </section>

        <!-- POR RAREZA -->
        <section class="valor__panel">
          <header class="valor__panel-cabecera">
            <h2 class="valor__panel-titulo">Por rareza</h2>
          </header>

          <ul class="barras">
            <li v-for="fila in resumen.byRarity" :key="fila.rarity" class="barra">
              <div class="barra__fila">
                <span class="barra__nombre">{{ etiquetaRareza(fila.rarity) }}</span>
                <span class="barra__valor">{{ euros(fila.valueEur) }}</span>
              </div>
              <div class="barra__pista">
                <div class="barra__relleno" :style="{ width: ancho(fila.valueEur, maxRareza) }"></div>
              </div>
              <small class="barra__nota">{{ fila.copies }} ejemplares en {{ fila.items }} líneas</small>
            </li>
          </ul>
        </section>

        <!--
          EL TOP 10. Por precio de la CARTA y no por valor de la línea, que es
          lo que decide el backend: «lo más caro que quiero» es el Black Lotus,
          no las cuatro islas que suman más entre todas. Las que no cotizan
          quedan fuera: no es que valgan poco, es que no se sabe.
        -->
        <section class="valor__panel">
          <header class="valor__panel-cabecera">
            <h2 class="valor__panel-titulo">Lo más caro que quieres</h2>
          </header>

          <p v-if="!resumen.topCards.length" class="valor__panel-vacio">
            Ninguna de las cartas que quieres tiene precio en Cardmarket todavía.
          </p>

          <ol v-else class="joyas">
            <li v-for="carta in resumen.topCards" :key="carta.id" class="joya">
              <div class="joya__datos">
                <a class="joya__nombre" href="#" @click.prevent="abrir(carta)">{{ carta.name }}</a>
                <small class="joya__meta">
                  {{ carta.setCode }} · {{ etiquetaAcabado(carta.finish) }}
                  <template v-if="carta.quantity > 1"> · ×{{ carta.quantity }}</template>
                </small>
              </div>
              <span class="joya__precio">{{ euros(carta.priceEur) }}</span>
            </li>
          </ol>
        </section>
      </div>
    </section>

    <p v-if="deseos.error" class="deseos__error">
      <i class="pi pi-exclamation-triangle"></i> {{ deseos.error }}
    </p>

    <!-- Primera carga: esqueletos con la forma real de la rejilla. -->
    <div v-if="deseos.cargando" class="deseos__rejilla">
      <Skeleton v-for="n in 12" :key="n" height="0" class="deseos__esqueleto" />
    </div>

    <p v-else-if="deseos.vacio" class="deseos__vacio">
      <i class="pi pi-heart"></i>
      <span v-if="deseos.filtrosActivos">Ningún deseo coincide con esos filtros.</span>
      <span v-else>Tu lista de deseos está vacía. Busca una carta en el catálogo y pulsa el corazón.</span>
      <Button label="Ir al catálogo" icon="pi pi-search" text size="small" @click="router.push('/catalog')" />
    </p>

    <!-- REJILLA -->
    <div v-else-if="deseos.vista === 'grid'" class="deseos__rejilla">
      <article v-for="item in deseos.items" :key="item.id" class="carta">
        <div
          class="carta__imagen"
          role="link"
          tabindex="0"
          @click="abrir(item)"
          @keyup.enter="abrir(item)"
        >
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
          <CollectionControls :item="item" deseos compacto />
          <Button
            icon="pi pi-check"
            label="Ya la tengo"
            size="small"
            severity="success"
            text
            :disabled="deseos.estaGuardando(item.id)"
            :aria-label="`Ya tengo ${item.name}`"
            class="carta__cumplir"
            @click.stop="abrirCumplir($event, item)"
          />
        </div>
      </article>
    </div>

    <!-- TABLA -->
    <div v-else class="deseos__tabla">
      <DataTable :value="deseos.items" data-key="id" size="small" striped-rows>
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
            <CollectionControls :item="data" deseos />
          </template>
        </Column>
        <Column header="" style="width: 9rem">
          <template #body="{ data }">
            <Button
              icon="pi pi-check"
              label="Ya la tengo"
              size="small"
              severity="success"
              text
              :disabled="deseos.estaGuardando(data.id)"
              :aria-label="`Ya tengo ${data.name}`"
              @click.stop="abrirCumplir($event, data)"
            />
          </template>
        </Column>
      </DataTable>
    </div>

    <!--
      «YA LA TENGO», con su cantidad y su estado.
      -------------------------------------------------------------------------
      UN solo `Popover` para toda la lista y no uno por fila: son cientos de
      líneas y cada `Popover` monta su overlay. Se abre apuntando al botón que
      lo pidió, y `objetivo` dice sobre qué deseo se está trabajando.

      La cantidad se ofrece porque el movimiento PARCIAL es el caso nuevo de
      todo el plan —quieres cuatro y compras una—, y el máximo es lo que
      quedaba: pedir más de lo deseado es un 422 del backend, así que el
      selector no debe poder llegar ahí.

      El estado arranca en el que se deseaba: si no se toca, no se manda, y el
      backend lo conserva.
    -->
    <Popover ref="cumplidor">
      <div v-if="objetivo" class="cumplir__panel">
        <h3 class="cumplir__titulo">Ya la tengo</h3>
        <p class="cumplir__carta">{{ objetivo.name }}</p>

        <label class="cumplir__campo">
          <span>Cuántas</span>
          <InputNumber
            v-model="cumplimiento.cantidad"
            :min="1"
            :max="objetivo.quantity"
            show-buttons
            button-layout="horizontal"
            size="small"
            :input-style="{ width: '3rem', textAlign: 'center' }"
            :aria-label="`Cuántas de ${objetivo.name} ya tienes`"
          />
          <small class="cumplir__nota">de las {{ objetivo.quantity }} que querías</small>
        </label>

        <label class="cumplir__campo">
          <span>Estado</span>
          <Select
            v-model="cumplimiento.condicion"
            :options="CONDICIONES"
            option-label="label"
            option-value="value"
            size="small"
            fluid
            :aria-label="`Estado de ${objetivo.name}`"
          />
        </label>

        <Button
          label="Pasar a mi colección"
          icon="pi pi-check"
          size="small"
          :loading="deseos.estaGuardando(objetivo.id)"
          class="cumplir__confirmar"
          @click="confirmarCumplir"
        />
      </div>
    </Popover>

    <!-- Centinela del scroll infinito: cuando entra en pantalla, se pide más. -->
    <div ref="centinela" class="deseos__centinela">
      <ProgressSpinner v-if="deseos.cargandoMas" style="width: 2rem; height: 2rem" />
      <span v-else-if="!deseos.hayMas && deseos.items.length > 0" class="deseos__fin">
        No hay más deseos
      </span>
    </div>

    <!-- El aviso de ESTA lista: `deseos` elige el store, ver CollectionAviso. -->
    <CollectionAviso deseos />
  </div>
</template>

<script setup>
import { computed, onBeforeUnmount, onMounted, reactive, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import Button from 'primevue/button'
import Column from 'primevue/column'
import DataTable from 'primevue/datatable'
import InputNumber from 'primevue/inputnumber'
import MultiSelect from 'primevue/multiselect'
import Popover from 'primevue/popover'
import ProgressSpinner from 'primevue/progressspinner'
import Select from 'primevue/select'
import SelectButton from 'primevue/selectbutton'
import Skeleton from 'primevue/skeleton'

import CardImage from '@/components/CardImage.vue'
import CollectionAviso from '@/components/CollectionAviso.vue'
import CollectionControls from '@/components/CollectionControls.vue'
import { ACABADOS, COLORES, CONDICIONES, IDIOMAS, RAREZAS, etiquetaAcabado, etiquetaRareza } from '@/constants/collection'
import { useCatalogStore } from '@/stores/catalog'
import { useWishlistStore } from '@/stores/wishlist'

/**
 * `/wishlist` — lo que quieres, y el gesto que lo cierra.
 *
 * Es la gemela de `CollectionView` sobre el otro conjunto de la misma tabla, y
 * **es una ruta y no un filtro** a propósito: `is_wishlist` no recorta la lista,
 * elige cuál se lee. Lo que esta vista tiene y aquella no es «ya la tengo», que
 * no es un `UPDATE` sino un movimiento de fila dentro de `uq_item`.
 *
 * De ahí el único cuidado propio de esta pantalla: **el `id` de la línea de
 * deseo puede dejar de existir** cuando se cumple. Quién manda es el `origen`
 * de la respuesta —lo resuelve `wishlist.cumplir()`—, no el `item.id` que se
 * mandó, igual que ya pasa al cambiar de estado en `CollectionControls`.
 */

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
const deseos = useWishlistStore()
const catalogo = useCatalogStore()

const panelAbierto = ref(false)
const centinela = ref(null)
const filtros = ref(estadoDeFiltrosVacio())
const coloresSeleccionados = ref([])

/** El desglose arranca cerrado: esta pantalla es una lista, no un tablero. */
const desgloseAbierto = ref(false)

const resumen = computed(() => deseos.resumen)
const totales = computed(() => deseos.resumen?.totals ?? {})
const tieneValor = computed(() => (deseos.resumen?.totals?.uniqueItems ?? 0) > 0)

/** Cuántas ediciones se enseñan aquí antes de mandar a `/sets`. */
const EDICIONES_VISIBLES = 6

const edicionesVisibles = computed(() => (resumen.value?.bySet ?? []).slice(0, EDICIONES_VISIBLES))

/** La barra más larga marca la escala; el resto se mide contra ella. */
const maxEdicion = computed(() => Math.max(...edicionesVisibles.value.map((f) => f.valueEur), 0))
const maxRareza = computed(() => Math.max(...(resumen.value?.byRarity ?? []).map((f) => f.valueEur), 0))

const cumplidor = ref(null)
/** El deseo sobre el que está abierto el panel de «ya la tengo». */
const objetivo = ref(null)
const cumplimiento = reactive({ cantidad: 1, condicion: 'NM' })

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

const FORMATO_EUR = new Intl.NumberFormat('es-ES', {
  style: 'currency',
  currency: 'EUR',
  minimumFractionDigits: 2,
  maximumFractionDigits: 2
})

/** Misma regla que `precioDe`, en la moneda de la cabecera: `null` no es cero. */
function euros(valor) {
  return valor === null || valor === undefined ? 'sin precio' : FORMATO_EUR.format(valor)
}

/**
 * El ancho de la barra. Con el máximo a 0 —una lista entera sin precio— la
 * división daría NaN y la barra se rompería: se queda a cero, que es la verdad.
 */
function ancho(valor, maximo) {
  return maximo > 0 ? `${(valor / maximo) * 100}%` : '0%'
}

function abrir(item) {
  router.push({ name: 'card', params: { uuid: item.printingUuid } })
}

/** Los filtros van a la query string, y de ahí al store. */
function aplicar() {
  router.replace({
    name: 'wishlist',
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
      view: deseos.vista === 'table' ? 'table' : undefined
    }
  })
}

function limpiar() {
  filtros.value = estadoDeFiltrosVacio()
  coloresSeleccionados.value = []
  router.replace({
    name: 'wishlist',
    query: deseos.vista === 'table' ? { view: 'table' } : {}
  })
}

function cambiarVista(vista) {
  deseos.vista = vista
  aplicar()
}

/**
 * Abre «ya la tengo» sobre un deseo concreto.
 *
 * El panel se siembra en «una, en el estado que querías», que es el caso
 * normal: compras una carta y es la que pediste. Así confirmar sin tocar nada
 * hace lo que el usuario espera.
 */
function abrirCumplir(evento, item) {
  objetivo.value = item
  cumplimiento.cantidad = 1
  cumplimiento.condicion = item.condition

  cumplidor.value?.toggle(evento)
}

async function confirmarCumplir(evento) {
  const item = objetivo.value

  if (!item) {
    return
  }

  const cumplido = await deseos.cumplir(item, {
    cantidad: cumplimiento.cantidad || 1,
    // Solo viaja si de verdad cambió: si no se manda, el backend conserva el
    // estado que se deseaba, que es justo lo que dice el panel.
    condicion: cumplimiento.condicion !== item.condition ? cumplimiento.condicion : null
  })

  if (cumplido) {
    cumplidor.value?.hide(evento)
    objetivo.value = null
  }
}

/** La query string es la fuente de verdad: se lee al entrar y al navegar. */
function sincronizarDesdeQuery() {
  deseos.desdeQuery(route.query)

  filtros.value = {
    set: deseos.filtros.set,
    rarity: deseos.filtros.rarity,
    finish: deseos.filtros.finish,
    language: deseos.filtros.language,
    condition: deseos.filtros.condition,
    price_min: deseos.filtros.price_min === '' ? null : Number(deseos.filtros.price_min),
    price_max: deseos.filtros.price_max === '' ? null : Number(deseos.filtros.price_max),
    sort: deseos.filtros.sort
  }
  coloresSeleccionados.value = deseos.filtros.colors ? deseos.filtros.colors.split('') : []

  return deseos.buscar()
}

watch(() => route.query, sincronizarDesdeQuery)

onMounted(() => {
  catalogo.cargarSets()
  // El orden importa: la LISTA primero, porque es lo que el usuario viene a
  // ver; el valor va detrás y por su cuenta, sin retrasarla.
  sincronizarDesdeQuery()
  // Solo al entrar, y NO al cambiar de filtro: `collection_value` no acepta
  // filtros —vale la lista entera— y repetirlo sería la misma cifra otra vez.
  deseos.valorar()

  // IntersectionObserver y no un listener de scroll: no dispara en cada píxel y
  // funciona igual dentro del WebView de Capacitor.
  observador = new IntersectionObserver(
    (entradas) => {
      if (entradas[0].isIntersecting) {
        deseos.cargarMas()
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
.deseos {
  padding-bottom: 2rem;
}

.deseos__bar {
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

.deseos__titulo {
  margin: 0;
  font-size: 1.1rem;
}

.deseos__acciones-bar {
  display: flex;
  align-items: center;
  gap: 0.5rem;
}

/* ---- La cabecera del valor ---------------------------------------------- */
.valor {
  max-width: 1100px;
  margin: 0 auto;
  padding: 0.9rem 0.75rem 0;
}

.valor__cifras {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(190px, 1fr));
  align-items: start;
  gap: 0.75rem;
}

.valor__tarjeta {
  display: flex;
  flex-direction: column;
  gap: 0.2rem;
  padding: 0.8rem 0.9rem;
  border: 1px solid var(--p-content-border-color);
  border-radius: 8px;
}

.valor__tarjeta--principal {
  border-color: var(--p-primary-color);
}

.valor__etiqueta {
  font-size: 0.72rem;
  text-transform: uppercase;
  letter-spacing: 0.04em;
  color: var(--p-text-muted-color);
}

.valor__total {
  font-size: 1.6rem;
  font-variant-numeric: tabular-nums;
}

.valor__cifra {
  font-size: 1.25rem;
  font-variant-numeric: tabular-nums;
}

.valor__nota {
  font-size: 0.7rem;
  line-height: 1.3;
  color: var(--p-text-muted-color);
}

.valor__conmutador {
  align-self: center;
  justify-self: start;
}

.valor__desglose {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(260px, 1fr));
  gap: 0.75rem;
  margin-top: 0.75rem;
}

.valor__panel {
  padding: 0.8rem 0.9rem;
  border: 1px solid var(--p-content-border-color);
  border-radius: 8px;
}

.valor__panel-cabecera {
  display: flex;
  align-items: baseline;
  justify-content: space-between;
  gap: 0.5rem;
  margin-bottom: 0.6rem;
}

.valor__panel-titulo {
  margin: 0;
  font-size: 0.9rem;
}

.valor__panel-enlace {
  font-size: 0.75rem;
  color: var(--p-primary-color);
  text-decoration: none;
}

.valor__panel-enlace:hover {
  text-decoration: underline;
}

.valor__panel-vacio {
  margin: 0;
  font-size: 0.78rem;
  color: var(--p-text-muted-color);
}

.barras {
  display: flex;
  flex-direction: column;
  gap: 0.6rem;
  margin: 0;
  padding: 0;
  list-style: none;
}

.barra__fila {
  display: flex;
  align-items: baseline;
  justify-content: space-between;
  gap: 0.5rem;
  font-size: 0.78rem;
}

.barra__nombre {
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.barra__valor {
  white-space: nowrap;
  font-variant-numeric: tabular-nums;
}

.barra__pista {
  height: 6px;
  margin: 0.2rem 0 0.15rem;
  border-radius: 999px;
  background: var(--p-content-border-color);
  overflow: hidden;
}

.barra__relleno {
  height: 100%;
  border-radius: 999px;
  background: var(--p-primary-color);
}

.barra__nota {
  font-size: 0.68rem;
  color: var(--p-text-muted-color);
}

.joyas {
  display: flex;
  flex-direction: column;
  gap: 0.4rem;
  margin: 0;
  padding: 0;
  list-style: none;
  counter-reset: joya;
}

.joya {
  display: flex;
  align-items: baseline;
  gap: 0.5rem;
}

.joya::before {
  counter-increment: joya;
  content: counter(joya) '.';
  min-width: 1.3rem;
  font-size: 0.7rem;
  color: var(--p-text-muted-color);
}

.joya__datos {
  display: flex;
  flex-direction: column;
  min-width: 0;
}

.joya__nombre {
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
  font-size: 0.8rem;
  color: inherit;
  text-decoration: none;
}

.joya__nombre:hover {
  text-decoration: underline;
}

.joya__meta {
  font-size: 0.68rem;
  color: var(--p-text-muted-color);
}

.joya__precio {
  margin-left: auto;
  white-space: nowrap;
  font-size: 0.8rem;
  font-variant-numeric: tabular-nums;
}

.deseos__filtros {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(160px, 1fr));
  gap: 0.75rem;
  padding: 0.75rem;
}

.deseos__filtro {
  width: 100%;
}

.deseos__acciones {
  display: flex;
  gap: 0.5rem;
  align-items: center;
  grid-column: 1 / -1;
}

.deseos__rejilla {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(170px, 1fr));
  gap: 1rem;
  padding: 0.75rem;
}

.deseos__esqueleto {
  aspect-ratio: 488 / 680;
  height: auto !important;
  border-radius: 4.75% / 3.5%;
}

.deseos__tabla {
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

.carta__cumplir {
  align-self: flex-start;
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

.cumplir__panel {
  display: flex;
  flex-direction: column;
  gap: 0.6rem;
  min-width: 15rem;
}

.cumplir__titulo {
  margin: 0;
  font-size: 0.75rem;
  text-transform: uppercase;
  letter-spacing: 0.04em;
  color: var(--p-text-muted-color);
}

.cumplir__carta {
  margin: 0;
  font-size: 0.85rem;
  font-weight: 600;
}

.cumplir__campo {
  display: flex;
  flex-direction: column;
  gap: 0.2rem;
  font-size: 0.78rem;
}

.cumplir__campo > span {
  color: var(--p-text-muted-color);
}

.cumplir__nota {
  color: var(--p-text-muted-color);
  font-size: 0.7rem;
}

.cumplir__confirmar {
  align-self: flex-end;
}

.deseos__vacio,
.deseos__error {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 0.5rem;
  padding: 3rem 1rem;
  color: var(--p-text-muted-color);
}

.deseos__error {
  color: var(--p-red-500);
}

.deseos__centinela {
  display: flex;
  align-items: center;
  justify-content: center;
  min-height: 4rem;
}

.deseos__fin {
  font-size: 0.8rem;
  color: var(--p-text-muted-color);
}
</style>
