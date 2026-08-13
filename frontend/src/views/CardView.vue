<template>
  <div class="ficha">
    <header class="ficha__bar">
      <Button icon="pi pi-arrow-left" text rounded aria-label="Volver" @click="volver" />
      <h1 class="ficha__titulo">{{ carta?.name || 'Carta' }}</h1>
      <span></span>
    </header>

    <div v-if="cargando" class="ficha__cuerpo">
      <Skeleton class="ficha__esqueleto" />
      <div class="ficha__datos">
        <Skeleton width="60%" height="1.5rem" class="mb-2" />
        <Skeleton width="40%" height="1rem" />
      </div>
    </div>

    <p v-else-if="noEncontrada" class="ficha__error">
      <i class="pi pi-search-minus"></i>
      Esta carta no existe en el catálogo.
    </p>

    <p v-else-if="error" class="ficha__error">
      <i class="pi pi-exclamation-triangle"></i>
      {{ error }}
    </p>

    <div v-else-if="carta" class="ficha__cuerpo">
      <CardImage
        :scryfall-id="carta.scryfallId"
        :nombre="carta.name"
        tamano="normal"
        class="ficha__imagen"
      />

      <div class="ficha__datos">
        <p class="ficha__linea">
          <span class="ficha__coste">{{ carta.manaCost }}</span>
          <Tag :value="carta.rarity" :severity="severidadRareza(carta.rarity)" />
        </p>

        <p class="ficha__tipo">{{ carta.typeLine }}</p>
        <p class="ficha__edicion">{{ carta.setName }} · nº {{ carta.collectorNumber }}</p>

        <p v-if="carta.oracleText" class="ficha__texto">{{ carta.oracleText }}</p>

        <section class="ficha__seccion">
          <h2>Precio</h2>
          <div v-if="acabadosConPrecio.length" class="ficha__precios">
            <span v-for="a in acabadosConPrecio" :key="a.clave" class="ficha__precio">
              {{ a.etiqueta }}: <strong>{{ a.valor.toFixed(2) }} €</strong>
            </span>
          </div>
          <p v-else class="ficha__sin-precio">Sin precio en Cardmarket.</p>

          <PriceSparkline
            v-for="serie in seriesDePrecio"
            :key="serie.etiqueta"
            :historico="serie.puntos"
            :etiqueta="serie.etiqueta"
          />
        </section>

        <section v-if="carta.localizedNames?.length" class="ficha__seccion">
          <h2>Nombres ({{ carta.localizedNames.length }} idiomas)</h2>
          <ul class="ficha__idiomas">
            <li v-for="n in carta.localizedNames" :key="n.language">
              <span class="ficha__idioma">{{ n.language }}</span> {{ n.name }}
            </li>
          </ul>
        </section>

        <section v-if="legalidadesOrdenadas.length" class="ficha__seccion">
          <h2>Legalidad</h2>
          <div class="ficha__legalidades">
            <Tag
              v-for="l in legalidadesOrdenadas"
              :key="l.formato"
              :value="l.formato"
              :severity="l.estado === 'legal' ? 'success' : l.estado === 'banned' ? 'danger' : 'secondary'"
            />
          </div>
        </section>
      </div>
    </div>
  </div>
</template>

<script setup>
import { computed, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import Button from 'primevue/button'
import Skeleton from 'primevue/skeleton'
import Tag from 'primevue/tag'

import CardImage from '@/components/CardImage.vue'
import PriceSparkline from '@/components/PriceSparkline.vue'
import { catalogGet } from '@/services/api'

const route = useRoute()
const router = useRouter()

const carta = ref(null)
const cargando = ref(true)
const error = ref(null)
const noEncontrada = ref(false)

const ETIQUETAS_ACABADO = { normal: 'Normal', foil: 'Foil', etched: 'Etched' }

const acabadosConPrecio = computed(() => {
  if (!carta.value) return []

  return Object.entries(carta.value.priceEur || {})
    .filter(([, valor]) => valor !== null && valor !== undefined)
    .map(([clave, valor]) => ({ clave, etiqueta: ETIQUETAS_ACABADO[clave] || clave, valor }))
})

/** El histórico viene mezclado por acabado; la gráfica es una por acabado. */
const seriesDePrecio = computed(() => {
  const porAcabado = {}

  for (const punto of carta.value?.priceHistory || []) {
    porAcabado[punto.finish] = porAcabado[punto.finish] || []
    porAcabado[punto.finish].push(punto)
  }

  return Object.entries(porAcabado).map(([acabado, puntos]) => ({
    etiqueta: ETIQUETAS_ACABADO[acabado] || acabado,
    puntos
  }))
})

const legalidadesOrdenadas = computed(() =>
  Object.entries(carta.value?.legalities || {})
    .map(([formato, estado]) => ({ formato, estado }))
    // Primero donde se puede jugar: es lo que se mira.
    .sort((a, b) => (a.estado === 'legal' ? -1 : 1) - (b.estado === 'legal' ? -1 : 1))
)

function severidadRareza(rareza) {
  return { mythic: 'danger', rare: 'warn', uncommon: 'info' }[rareza] || 'secondary'
}

/** Volver al catálogo conservando los filtros que traía el usuario. */
function volver() {
  if (window.history.length > 1) {
    router.back()
    return
  }

  router.push({ name: 'catalog' })
}

async function cargar(uuid) {
  cargando.value = true
  error.value = null
  noEncontrada.value = false

  const respuesta = await catalogGet(`/cards/${encodeURIComponent(uuid)}`)

  if (respuesta.error === 'printing_not_found') {
    noEncontrada.value = true
  } else if (respuesta.error) {
    error.value = 'No se pudo cargar la carta.'
  } else {
    carta.value = respuesta
  }

  cargando.value = false
}

watch(() => route.params.uuid, (uuid) => uuid && cargar(uuid), { immediate: true })
</script>

<style scoped>
.ficha__bar {
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

.ficha__titulo {
  margin: 0;
  font-size: 1rem;
  text-align: center;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.ficha__cuerpo {
  display: grid;
  grid-template-columns: minmax(0, 320px) minmax(0, 1fr);
  gap: 1.5rem;
  padding: 1rem 0.75rem 3rem;
  max-width: 900px;
  margin: 0 auto;
}

@media (max-width: 700px) {
  .ficha__cuerpo {
    grid-template-columns: 1fr;
  }
}

.ficha__esqueleto {
  aspect-ratio: 488 / 680;
  height: auto !important;
}

.ficha__linea {
  display: flex;
  align-items: center;
  gap: 0.75rem;
  margin: 0 0 0.5rem;
}

.ficha__coste {
  font-family: monospace;
  font-size: 0.9rem;
}

.ficha__tipo {
  margin: 0 0 0.25rem;
  font-weight: 600;
}

.ficha__edicion {
  margin: 0 0 1rem;
  font-size: 0.85rem;
  color: var(--p-text-muted-color);
}

.ficha__texto {
  margin: 0 0 1.5rem;
  white-space: pre-line;
  line-height: 1.5;
}

.ficha__seccion {
  margin-bottom: 1.75rem;
}

.ficha__seccion h2 {
  margin: 0 0 0.6rem;
  font-size: 0.8rem;
  text-transform: uppercase;
  letter-spacing: 0.04em;
  color: var(--p-text-muted-color);
}

.ficha__precios {
  display: flex;
  flex-wrap: wrap;
  gap: 1rem;
  margin-bottom: 1rem;
  font-size: 0.9rem;
}

.ficha__sin-precio {
  margin: 0 0 1rem;
  font-size: 0.85rem;
  color: var(--p-text-muted-color);
}

.ficha__idiomas {
  margin: 0;
  padding: 0;
  list-style: none;
  font-size: 0.85rem;
  line-height: 1.7;
}

.ficha__idioma {
  display: inline-block;
  min-width: 9rem;
  color: var(--p-text-muted-color);
}

.ficha__legalidades {
  display: flex;
  flex-wrap: wrap;
  gap: 0.4rem;
}

.ficha__error {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 0.75rem;
  padding: 4rem 1rem;
  color: var(--p-text-muted-color);
}
</style>
