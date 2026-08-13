<template>
  <figure v-if="puntos.length > 1" class="sparkline">
    <figcaption class="sparkline__cabecera">
      <span>{{ etiqueta }}</span>
      <span class="sparkline__rango">
        {{ formato(minimo) }} – {{ formato(maximo) }}
      </span>
    </figcaption>

    <svg
      :viewBox="`0 0 ${ANCHO} ${ALTO}`"
      class="sparkline__svg"
      preserveAspectRatio="none"
      role="img"
      :aria-label="`Evolución del precio ${etiqueta}: de ${formato(primero)} a ${formato(ultimo)}`"
    >
      <!-- El área bajo la curva ayuda a leer la tendencia de un vistazo. -->
      <path :d="areaPath" class="sparkline__area" />
      <path :d="lineaPath" class="sparkline__linea" />
      <circle :cx="ANCHO" :cy="yDe(ultimo)" r="2.5" class="sparkline__ultimo" />
    </svg>

    <div class="sparkline__pie">
      <span>{{ puntos[0].date }}</span>
      <strong :class="tendencia">{{ formato(ultimo) }}</strong>
      <span>{{ puntos[puntos.length - 1].date }}</span>
    </div>
  </figure>

  <p v-else class="sparkline__sin-datos">
    Sin histórico suficiente para {{ etiqueta }}.
  </p>
</template>

<script setup>
import { computed } from 'vue'

/**
 * Gráfica de evolución de precio, en SVG a mano.
 *
 * Sin librería de charts a propósito: chart.js sumaría ~200 KB al bundle —que
 * también viaja dentro del APK— para dibujar una línea. Aquí solo hacen falta
 * dos `path`, y el histórico ya viene ordenado por fecha del backend.
 */

const props = defineProps({
  /** @type {Array<{date: string, priceEur: number}>} */
  historico: { type: Array, default: () => [] },
  etiqueta: { type: String, default: 'normal' }
})

const ANCHO = 300
const ALTO = 60

const puntos = computed(() => props.historico)

const valores = computed(() => puntos.value.map((p) => p.priceEur))
const minimo = computed(() => Math.min(...valores.value))
const maximo = computed(() => Math.max(...valores.value))
const primero = computed(() => valores.value[0])
const ultimo = computed(() => valores.value[valores.value.length - 1])

const tendencia = computed(() => {
  if (ultimo.value > primero.value) return 'sube'
  if (ultimo.value < primero.value) return 'baja'
  return ''
})

function xDe(indice) {
  return (indice / (puntos.value.length - 1)) * ANCHO
}

function yDe(valor) {
  const rango = maximo.value - minimo.value

  // Si el precio no se movió en todo el periodo, el rango es 0 y la división
  // daría NaN: se dibuja plano por el centro, que es lo que de verdad pasó.
  if (rango === 0) {
    return ALTO / 2
  }

  return ALTO - ((valor - minimo.value) / rango) * (ALTO - 6) - 3
}

const lineaPath = computed(() =>
  puntos.value
    .map((p, i) => `${i === 0 ? 'M' : 'L'} ${xDe(i).toFixed(2)} ${yDe(p.priceEur).toFixed(2)}`)
    .join(' ')
)

const areaPath = computed(() => `${lineaPath.value} L ${ANCHO} ${ALTO} L 0 ${ALTO} Z`)

function formato(valor) {
  return `${Number(valor).toFixed(2)} €`
}
</script>

<style scoped>
.sparkline {
  margin: 0 0 1rem;
}

.sparkline__cabecera {
  display: flex;
  justify-content: space-between;
  font-size: 0.75rem;
  color: var(--p-text-muted-color);
  text-transform: capitalize;
}

.sparkline__svg {
  width: 100%;
  height: 60px;
  overflow: visible;
}

.sparkline__linea {
  fill: none;
  stroke: var(--p-primary-color);
  stroke-width: 1.5;
  vector-effect: non-scaling-stroke;
}

.sparkline__area {
  fill: var(--p-primary-color);
  opacity: 0.12;
  stroke: none;
}

.sparkline__ultimo {
  fill: var(--p-primary-color);
}

.sparkline__pie {
  display: flex;
  justify-content: space-between;
  align-items: baseline;
  font-size: 0.72rem;
  color: var(--p-text-muted-color);
  font-variant-numeric: tabular-nums;
}

.sparkline__pie strong {
  font-size: 0.95rem;
  color: var(--p-text-color);
}

.sparkline__pie .sube {
  color: var(--p-green-500);
}

.sparkline__pie .baja {
  color: var(--p-red-500);
}

.sparkline__sin-datos {
  font-size: 0.8rem;
  color: var(--p-text-muted-color);
}
</style>
