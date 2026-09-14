<template>
  <div class="sets">
    <header class="sets__bar">
      <Button icon="pi pi-arrow-left" text rounded aria-label="Volver" @click="router.push('/')" />
      <h1 class="sets__titulo">Ediciones</h1>

      <!--
        EL CONMUTADOR tengo / quiero. Son dos conjuntos excluyentes de la misma
        tabla, no un filtro: por eso es un `SelectButton` de dos posiciones y no
        una casilla de «incluir deseos».
      -->
      <SelectButton
        :model-value="modo"
        :options="MODOS"
        option-label="label"
        option-value="value"
        :allow-empty="false"
        aria-labelledby="Qué contar en cada edición"
        class="sets__modo"
        @update:model-value="cambiarModo"
      />

      <router-link
        :to="{ name: esDeseos ? 'wishlist' : 'collection' }"
        class="sets__enlace"
      >{{ esDeseos ? 'Mi lista de deseos' : 'Mi colección' }}</router-link>
    </header>

    <p v-if="error" class="sets__error">
      <i class="pi pi-exclamation-triangle"></i> {{ error }}
    </p>

    <div v-if="cargando && !progreso" class="sets__lista">
      <Skeleton v-for="n in 8" :key="n" height="3.5rem" />
    </div>

    <template v-else-if="progreso && progreso.sets.length">
      <!-- El "23 de 868" es lo que da tamaño al progreso: 23 ediciones a secas
           no dicen si vas empezando o si te falta poco.

           En modo deseos ese marco no aplica: "23 de 868" leería una lista de
           la compra como un avance de colección, y "completas" contaría como
           terminada una edición de la que solo quieres una carta. Lo que se
           dice entonces es cuántas ediciones tocas y cuánto quieres de ellas. -->
      <section v-if="esDeseos" class="sets__resumen">
        <span>
          <strong>{{ totales.startedSets }}</strong>
          {{ totales.startedSets === 1 ? 'edición en tu lista' : 'ediciones en tu lista' }}
        </span>
        <span><strong>{{ totales.ownedPrintings }}</strong> cartas distintas</span>
        <span><strong>{{ totales.totalCopies }}</strong> ejemplares</span>
      </section>

      <section v-else class="sets__resumen">
        <span>
          <strong>{{ totales.startedSets }}</strong> de {{ totales.catalogSets }} ediciones empezadas
        </span>
        <span><strong>{{ totales.ownedPrintings }}</strong> impresiones distintas</span>
        <span><strong>{{ totales.completedSets }}</strong> completas</span>
        <span v-if="totales.unsizedSets">
          <strong>{{ totales.unsizedSets }}</strong> sin tamaño declarado
        </span>
      </section>

      <ul class="sets__lista">
        <li v-for="set in progreso.sets" :key="set.setCode" class="set">
          <div class="set__cabecera">
            <router-link
              :to="{ name: esDeseos ? 'wishlist' : 'collection', query: { set: set.setCode } }"
              class="set__nombre"
            >
              {{ set.setName }}
              <small class="set__codigo">{{ set.setCode }}</small>
            </router-link>

            <!--
              **NINGÚN PORCENTAJE EN MODO DESEOS.** El backend lo manda igual
              —`collection_sets` divide sin saber de qué conjunto habla— y por
              eso hay que decidirlo aquí: `ownedPrintings / totalSetSize` sobre
              una lista de deseos da un número que PARECE progreso y no lo es.
              Querer 8 cartas de una edición de 1.067 no es llevar un 0,7 % de
              nada. El número que sí dice algo es cuántas quieres, y es el que
              ocupa su sitio.
            -->
            <span v-if="esDeseos" class="set__cuantas">
              {{ set.copies }} {{ set.copies === 1 ? 'ejemplar' : 'ejemplares' }}
            </span>
            <span
              v-else
              class="set__porcentaje"
              :class="{ 'set__porcentaje--sin': set.percent === null }"
            >
              {{ porcentaje(set) }}
            </span>
          </div>

          <!--
            La barra solo existe cuando hay porcentaje. `mtg_set.total_set_size`
            es nullable: sin tamaño no se puede dividir, así que no se dibuja una
            barra vacía —que se leería como un 0 %— sino que se dice que no se
            sabe de cuántas cartas consta la edición.

            En modo deseos no hay barra de ninguna clase: una barra ES un
            porcentaje dibujado, y esconder la cifra para pintar el mismo dato
            de lado sería hacer trampa con la regla del plan.
          -->
          <template v-if="!esDeseos">
            <div v-if="set.percent !== null" class="set__pista">
              <div
                class="set__relleno"
                :class="{ 'set__relleno--completo': set.complete }"
                :style="{ width: ancho(set.percent) }"
              ></div>
            </div>
            <div v-else class="set__pista set__pista--desconocida"></div>
          </template>

          <div class="set__pie">
            <!-- "4 de 143 cartas" es un avance, y sobre deseos no lo hay: se
                 dice cuántas cartas distintas quieres y ya. -->
            <span v-if="esDeseos">
              {{ set.ownedPrintings }}
              {{ set.ownedPrintings === 1 ? 'carta distinta' : 'cartas distintas' }}
            </span>
            <template v-else>
              <span v-if="set.totalSetSize !== null">
                {{ set.ownedPrintings }} de {{ set.totalSetSize }} cartas
              </span>
              <span v-else class="set__sin-tamano">
                {{ set.ownedPrintings }} cartas · esta edición no declara cuántas tiene
              </span>
              <span>{{ set.copies }} ejemplares</span>
            </template>
            <span class="set__valor">{{ euros(set.valueEur) }}</span>
          </div>
        </li>
      </ul>
    </template>

    <p v-else class="sets__vacio">
      <i class="pi pi-inbox"></i>
      <span v-if="esDeseos">Todavía no quieres ninguna carta de ninguna edición.</span>
      <span v-else>Todavía no tienes cartas de ninguna edición.</span>
      <Button label="Ir al catálogo" icon="pi pi-search" text size="small" @click="router.push('/catalog')" />
    </p>
  </div>
</template>

<script setup>
import { computed, onMounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import Button from 'primevue/button'
import SelectButton from 'primevue/selectbutton'
import Skeleton from 'primevue/skeleton'

import { useCollectionStore } from '@/stores/collection'
import { useWishlistStore } from '@/stores/wishlist'

/**
 * Cuánto llevas de cada edición — y, en la otra posición, cuánto QUIERES.
 *
 * El porcentaje es el del plan: `COUNT(DISTINCT printing) / total_set_size`, con
 * el numerador y el denominador a la vista para que se pueda comprobar de un
 * vistazo. Solo salen las ediciones en las que hay algo — las 868 del catálogo
 * con un 0 % serían ruido, y el recuento de arriba ya da esa escala.
 *
 * La barra es CSS, sin librería de gráficas, igual que el dashboard.
 *
 * **Y en modo deseos NO se pinta porcentaje.** Es la reserva que el plan dejó
 * anotada al aceptar este conmutador: el progreso por edición cuenta lo que
 * tienes, y sobre una lista de deseos la misma división da un número que parece
 * avance y no lo es. Se oculta la columna entera —cifra y barra— en vez de
 * enseñar un dato sin sentido; lo que ocupa su sitio es cuántas quieres.
 */

const MODOS = [
  { label: 'Tengo', value: 'coleccion' },
  { label: 'Quiero', value: 'deseos' }
]

const router = useRouter()

/**
 * Los dos `use*Store()` se llaman SIEMPRE y el conmutador solo elige cuál se
 * lee: son ganchos de `setup()` y no pueden colgar de un `if`. Es el mismo
 * patrón que ya usan `CollectionControls` y `CollectionAviso` con su prop
 * `deseos`.
 */
const coleccion = useCollectionStore()
const deseos = useWishlistStore()

const modo = ref('coleccion')
const esDeseos = computed(() => modo.value === 'deseos')

const almacen = computed(() => (esDeseos.value ? deseos : coleccion))

const progreso = computed(() => almacen.value.progreso)
const totales = computed(() => almacen.value.progreso?.totals ?? {})
const cargando = computed(() => almacen.value.cargandoProgreso)
const error = computed(() => almacen.value.errorProgreso)

/**
 * Cada cambio de modo vuelve a pedir su lado, sin cachear el anterior: es el
 * mismo criterio que el valor de la colección —los precios cambian a diario y
 * la lista de deseos cambia con cada corazón—, y la respuesta trae el valor en
 * euros de cada edición, que es justo lo que caducaría.
 */
function cambiarModo(nuevo) {
  modo.value = nuevo
  cargar()
}

function cargar() {
  return almacen.value.cargarProgreso()
}

const FORMATO_EUR = new Intl.NumberFormat('es-ES', {
  style: 'currency',
  currency: 'EUR',
  minimumFractionDigits: 2,
  maximumFractionDigits: 2
})

function euros(valor) {
  return valor === null || valor === undefined ? 'sin precio' : FORMATO_EUR.format(valor)
}

/** Sin tamaño declarado no hay porcentaje: se dice, no se inventa un 0 %. */
function porcentaje(set) {
  return set.percent === null ? 'sin tamaño' : `${set.percent.toString().replace('.', ',')} %`
}

/**
 * El ancho de la barra se queda en el 100 % aunque el porcentaje lo pase: una
 * edición puede declarar menos cartas de las que el catálogo le cuenta, y el
 * número se enseña tal cual, pero la barra no se sale de su carril.
 */
function ancho(percent) {
  return `${Math.min(percent, 100)}%`
}

onMounted(() => cargar())
</script>

<style scoped>
.sets {
  padding-bottom: 2rem;
}

.sets__bar {
  position: sticky;
  top: 0;
  z-index: 10;
  display: flex;
  align-items: center;
  gap: 0.5rem;
  padding: 0.5rem 0.75rem;
  background: var(--p-content-background);
  border-bottom: 1px solid var(--p-content-border-color);
}

.sets__titulo {
  margin: 0;
  font-size: 1.1rem;
}

.sets__modo {
  margin-left: auto;
}

.sets__enlace {
  font-size: 0.8rem;
  color: var(--p-primary-color);
  text-decoration: none;
}

.sets__enlace:hover {
  text-decoration: underline;
}

.sets__resumen {
  display: flex;
  flex-wrap: wrap;
  gap: 0.35rem 1.25rem;
  max-width: 900px;
  margin: 0 auto;
  padding: 0.9rem 0.75rem 0;
  font-size: 0.8rem;
  color: var(--p-text-muted-color);
}

.sets__resumen strong {
  color: var(--p-text-color);
  font-variant-numeric: tabular-nums;
}

.sets__lista {
  display: flex;
  flex-direction: column;
  gap: 0.9rem;
  max-width: 900px;
  margin: 0 auto;
  padding: 0.9rem 0.75rem;
  list-style: none;
}

.set__cabecera {
  display: flex;
  align-items: baseline;
  justify-content: space-between;
  gap: 0.75rem;
}

.set__nombre {
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
  font-size: 0.9rem;
  color: inherit;
  text-decoration: none;
}

.set__nombre:hover {
  text-decoration: underline;
}

.set__codigo {
  margin-left: 0.35rem;
  font-size: 0.7rem;
  color: var(--p-text-muted-color);
}

.set__porcentaje {
  white-space: nowrap;
  font-size: 0.9rem;
  font-variant-numeric: tabular-nums;
}

/* El número que ocupa el sitio del porcentaje en modo deseos. */
.set__cuantas {
  white-space: nowrap;
  font-size: 0.85rem;
  color: var(--p-text-muted-color);
  font-variant-numeric: tabular-nums;
}

/* "Sin tamaño" no es un valor: se marca como ausencia, igual que "sin precio". */
.set__porcentaje--sin {
  font-size: 0.78rem;
  font-style: italic;
  opacity: 0.7;
}

.set__pista {
  height: 8px;
  margin: 0.3rem 0 0.25rem;
  border-radius: 999px;
  background: var(--p-content-border-color);
  overflow: hidden;
}

.set__pista--desconocida {
  background: repeating-linear-gradient(
    45deg,
    var(--p-content-border-color),
    var(--p-content-border-color) 4px,
    transparent 4px,
    transparent 8px
  );
}

.set__relleno {
  height: 100%;
  border-radius: 999px;
  background: var(--p-primary-color);
}

.set__relleno--completo {
  background: var(--p-green-500);
}

.set__pie {
  display: flex;
  flex-wrap: wrap;
  gap: 0.25rem 1rem;
  font-size: 0.72rem;
  color: var(--p-text-muted-color);
}

.set__sin-tamano {
  font-style: italic;
}

.set__valor {
  margin-left: auto;
  font-variant-numeric: tabular-nums;
}

.sets__vacio,
.sets__error {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 0.5rem;
  padding: 3rem 1rem;
  color: var(--p-text-muted-color);
}

.sets__error {
  padding: 1rem;
  color: var(--p-red-500);
}
</style>
