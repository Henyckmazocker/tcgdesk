<template>
  <section class="buscador">
    <div class="buscador__cabecera">
      <IconField class="buscador__campo">
        <InputIcon class="pi pi-search" />
        <InputText
          v-model="texto"
          placeholder="Busca una carta y púlsala para meterla en el mazo"
          fluid
          aria-label="Buscar cartas del catálogo"
        />
      </IconField>

      <label class="buscador__zona">
        <span>Zona</span>
        <Select
          v-model="zona"
          :options="ZONAS"
          option-label="label"
          option-value="value"
          size="small"
        />
      </label>
    </div>

    <p v-if="mazos.indiceParcial" class="buscador__nota">
      <i class="pi pi-info-circle"></i>
      Tu colección es demasiado grande para resolverla entera aquí: el clic añadirá
      la carta con los valores por defecto y podrás cambiar la versión en la tabla.
    </p>

    <div v-if="mazos.buscando" class="buscador__lista">
      <Skeleton v-for="n in 4" :key="n" height="3rem" class="buscador__esqueleto" />
    </div>

    <p v-else-if="texto.trim().length >= 2 && mazos.resultados.length === 0" class="buscador__vacio">
      <i class="pi pi-inbox"></i> Ninguna carta del catálogo coincide con «{{ texto }}».
    </p>

    <ul v-else-if="mazos.resultados.length > 0" class="buscador__lista">
      <!--
        TODA la fila es el botón de añadir. Un clic aquí es una carta en el mazo:
        el acabado, el idioma y el estado los resuelve el store desde tu
        colección (peor estado primero) y genera varias líneas si hace falta.
      -->
      <li v-for="carta in mazos.resultados" :key="carta.uuid" class="resultado">
        <button
          type="button"
          class="resultado__principal"
          :disabled="mazos.estaAnadiendo(carta.uuid)"
          :aria-label="`Añadir ${carta.name} al mazo`"
          @click="anadir(carta)"
        >
          <span class="resultado__mini">
            <CardImage :scryfall-id="carta.scryfallId" :nombre="carta.name" tamano="small" />
          </span>

          <span class="resultado__texto">
            <span class="resultado__nombre">{{ carta.name }}</span>
            <small class="resultado__meta">
              {{ carta.setCode }} · {{ carta.collectorNumber }} · {{ etiquetaRareza(carta.rarity) }}
              · {{ precioDe(carta) }}
            </small>
          </span>

          <!--
            Cuántas tienes LIBRES para este mazo: lo que hay en las cajas menos
            lo que este mazo ya reclama. El número global —lo que descuentan
            todos tus mazos construidos— lo dice el desplegable de cada línea,
            que lo pregunta al backend.
          -->
          <span class="resultado__tenencia">
            <Tag
              v-if="mazos.totalLibres(carta.uuid) > 0"
              :value="`${mazos.totalLibres(carta.uuid)} libres`"
              severity="success"
            />
            <Tag
              v-else-if="mazos.enColeccion(carta.uuid) > 0"
              :value="`${mazos.enColeccion(carta.uuid)} en uso`"
              severity="warn"
            />
            <Tag v-else value="no la tienes" severity="secondary" />
          </span>

          <i class="pi pi-plus resultado__mas"></i>
        </button>

        <!--
          Y el camino de al lado: las cinco dimensiones dichas a mano. Mismo
          patrón que `AddToCollectionButton`, y por el mismo motivo — es un
          retoque, no el camino principal.
        -->
        <Button
          icon="pi pi-sliders-h"
          size="small"
          severity="secondary"
          text
          :disabled="mazos.estaAnadiendo(carta.uuid)"
          :aria-label="`Opciones para añadir ${carta.name}`"
          title="Opciones"
          @click.stop="abrirOpciones($event, carta)"
        />
      </li>
    </ul>

    <div v-if="mazos.resultados.length > 0 && mazos.cursorResultados" class="buscador__mas">
      <Button
        label="Ver más resultados"
        icon="pi pi-angle-down"
        text
        size="small"
        :loading="mazos.buscandoMas"
        @click="mazos.masResultados()"
      />
    </div>

    <Popover ref="opciones">
      <div class="opciones__panel">
        <h3 class="opciones__titulo">Añadir con opciones</h3>
        <p class="opciones__carta">{{ elegida?.name }}</p>

        <label class="opciones__campo">
          <span>Acabado</span>
          <Select
            v-model="avanzado.finish"
            :options="acabadosPosibles"
            option-label="label"
            option-value="value"
            size="small"
            fluid
          />
        </label>

        <label class="opciones__campo">
          <span>Idioma</span>
          <Select
            v-model="avanzado.language"
            :options="IDIOMAS"
            option-label="label"
            option-value="value"
            filter
            size="small"
            fluid
          />
        </label>

        <label class="opciones__campo">
          <span>Estado</span>
          <Select
            v-model="avanzado.condition_grade"
            :options="CONDICIONES"
            option-label="label"
            option-value="value"
            size="small"
            fluid
          />
        </label>

        <label class="opciones__campo">
          <span>Zona</span>
          <Select
            v-model="avanzado.board"
            :options="ZONAS"
            option-label="label"
            option-value="value"
            size="small"
            fluid
          />
        </label>

        <label class="opciones__campo">
          <span>Cantidad</span>
          <InputNumber
            v-model="avanzado.count"
            :min="1"
            :max="9999"
            show-buttons
            button-layout="horizontal"
            size="small"
            :input-style="{ width: '3rem', textAlign: 'center' }"
          />
        </label>

        <Button
          label="Añadir"
          icon="pi pi-plus"
          size="small"
          class="opciones__confirmar"
          @click="anadirConOpciones"
        />
      </div>
    </Popover>
  </section>
</template>

<script setup>
import { computed, onBeforeUnmount, reactive, ref, watch } from 'vue'
import Button from 'primevue/button'
import IconField from 'primevue/iconfield'
import InputIcon from 'primevue/inputicon'
import InputNumber from 'primevue/inputnumber'
import InputText from 'primevue/inputtext'
import Popover from 'primevue/popover'
import Select from 'primevue/select'
import Skeleton from 'primevue/skeleton'
import Tag from 'primevue/tag'

import CardImage from '@/components/CardImage.vue'
import { ACABADOS, CONDICIONES, IDIOMAS, ZONAS, etiquetaRareza } from '@/constants/collection'
import { useDeckStore } from '@/stores/deck'

/**
 * El buscador embebido del editor de mazos.
 *
 * Lee el catálogo por `catalogGet` (a través del store), que es la ruta GET
 * pública con p95 de 8 ms: aquí no hay nada que autorizar, y por eso sale sin
 * credenciales.
 *
 * **Un clic sobre el resultado es una carta en el mazo.** Es el modo «coge lo
 * que tenga» adoptado en M0: `finish`, `language` y `condition_grade` los
 * resuelve el store desde tu colección —primero lo libre y en peor estado, que
 * las cartas buenas se guardan y las jugadas se juegan— y reparte en varias
 * líneas si hace falta. El selector explícito de las cinco dimensiones vive
 * detrás de «Opciones», exactamente como en `AddToCollectionButton`.
 */

/** Lo que se espera a que pares de teclear. Menos, y se busca por cada letra. */
const RETARDO_MS = 300

const props = defineProps({
  /** La zona a la que va lo que se añada con un clic. */
  zonaPorDefecto: { type: String, default: 'main' }
})

const mazos = useDeckStore()

const texto = ref('')
const zona = ref(props.zonaPorDefecto)
const opciones = ref(null)
const elegida = ref(null)

const avanzado = reactive({
  finish: 'normal',
  language: 'English',
  condition_grade: 'NM',
  board: 'main',
  count: 1
})

let temporizador = null

/** Un acabado que la carta nunca tuvo se guardaría igual y valdría un precio
 *  que no existe: si el catálogo dice cuáles hay, se ofrecen solo esos. */
const acabadosPosibles = computed(() => {
  const carta = elegida.value

  if (!carta) {
    return ACABADOS
  }

  const disponibles = {
    normal: carta.finishes?.nonfoil,
    foil: carta.finishes?.foil,
    etched: carta.finishes?.etched
  }

  const filtrados = ACABADOS.filter((a) => disponibles[a.value])

  return filtrados.length > 0 ? filtrados : ACABADOS
})

/** El precio del acabado normal, que es el que enseña la fila del resultado. */
function precioDe(carta) {
  const precio = carta.priceEur?.normal ?? carta.priceEur?.foil ?? carta.priceEur?.etched

  return precio === null || precio === undefined ? 'sin precio' : `${Number(precio).toFixed(2)} €`
}

watch(texto, (valor) => {
  clearTimeout(temporizador)
  temporizador = setTimeout(() => mazos.buscarCartas(valor), RETARDO_MS)
})

watch(
  () => props.zonaPorDefecto,
  (valor) => {
    zona.value = valor
  }
)

function anadir(carta) {
  mazos.anadirResuelto(carta.uuid, 1, zona.value)
}

function abrirOpciones(evento, carta) {
  elegida.value = carta

  // El panel abre en lo que habría hecho el clic simple, para que «Opciones»
  // sea un retoque y no un formulario en blanco.
  avanzado.finish = 'normal'
  avanzado.language = 'English'
  avanzado.condition_grade = 'NM'
  avanzado.board = zona.value
  avanzado.count = 1

  if (!acabadosPosibles.value.some((a) => a.value === avanzado.finish)) {
    avanzado.finish = acabadosPosibles.value[0].value
  }

  opciones.value?.toggle(evento)
}

async function anadirConOpciones(evento) {
  if (!elegida.value) {
    return
  }

  const anadida = await mazos.anadirConOpciones(elegida.value.uuid, {
    finish: avanzado.finish,
    language: avanzado.language,
    condition_grade: avanzado.condition_grade,
    board: avanzado.board,
    count: avanzado.count || 1
  })

  if (anadida) {
    opciones.value?.hide(evento)
  }
}

onBeforeUnmount(() => clearTimeout(temporizador))
</script>

<style scoped>
.buscador {
  display: flex;
  flex-direction: column;
  gap: 0.6rem;
}

.buscador__cabecera {
  display: flex;
  align-items: flex-end;
  gap: 0.6rem;
  flex-wrap: wrap;
}

.buscador__campo {
  flex: 1 1 18rem;
}

.buscador__zona {
  display: flex;
  flex-direction: column;
  gap: 0.15rem;
  font-size: 0.72rem;
  color: var(--p-text-muted-color);
}

.buscador__nota,
.buscador__vacio {
  display: flex;
  align-items: center;
  gap: 0.4rem;
  margin: 0;
  font-size: 0.8rem;
  color: var(--p-text-muted-color);
}

.buscador__lista {
  display: flex;
  flex-direction: column;
  gap: 0.3rem;
  max-height: 26rem;
  overflow-y: auto;
  margin: 0;
  padding: 0;
  list-style: none;
}

.buscador__esqueleto {
  flex: 0 0 auto;
}

.buscador__mas {
  display: flex;
  justify-content: center;
}

.resultado {
  display: flex;
  align-items: center;
  gap: 0.25rem;
}

.resultado__principal {
  flex: 1;
  display: flex;
  align-items: center;
  gap: 0.6rem;
  padding: 0.35rem 0.5rem;
  border: 1px solid transparent;
  border-radius: 6px;
  background: transparent;
  color: inherit;
  font: inherit;
  text-align: left;
  cursor: pointer;
}

.resultado__principal:hover:not(:disabled),
.resultado__principal:focus-visible {
  border-color: var(--p-primary-color);
  background: var(--p-content-hover-background);
}

.resultado__principal:disabled {
  opacity: 0.5;
  cursor: progress;
}

.resultado__mini {
  flex: 0 0 2.1rem;
  width: 2.1rem;
}

.resultado__texto {
  flex: 1;
  display: flex;
  flex-direction: column;
  min-width: 0;
}

.resultado__nombre {
  font-size: 0.88rem;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}

.resultado__meta {
  font-size: 0.72rem;
  color: var(--p-text-muted-color);
}

.resultado__tenencia {
  flex: 0 0 auto;
  font-size: 0.72rem;
}

.resultado__mas {
  flex: 0 0 auto;
  color: var(--p-primary-color);
}

.opciones__panel {
  display: flex;
  flex-direction: column;
  gap: 0.6rem;
  min-width: 15rem;
}

.opciones__titulo {
  margin: 0;
  font-size: 0.75rem;
  text-transform: uppercase;
  letter-spacing: 0.04em;
  color: var(--p-text-muted-color);
}

.opciones__carta {
  margin: 0;
  font-size: 0.85rem;
  font-weight: 600;
}

.opciones__campo {
  display: flex;
  flex-direction: column;
  gap: 0.2rem;
  font-size: 0.78rem;
}

.opciones__campo > span {
  color: var(--p-text-muted-color);
}

.opciones__confirmar {
  align-self: flex-end;
}
</style>
