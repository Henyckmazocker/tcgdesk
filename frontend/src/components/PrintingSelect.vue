<template>
  <div class="impresiones">
    <Select
      :model-value="elegida ?? printingUuid"
      :options="opciones"
      option-label="etiqueta"
      option-value="uuid"
      :loading="cargando"
      :placeholder="marcador"
      :aria-label="`Cambiar la edición de ${nombre}`"
      size="small"
      filter
      :filter-placeholder="'Edición, número…'"
      class="impresiones__select"
      @before-show="cargar"
      @change="$emit('elegir', $event.value)"
    >
      <!--
        Dos líneas por opción: la edición manda, el número y el precio son el
        desempate. Un desplegable de 71 «Lightning Bolt» idénticos no sirve de
        nada; lo que distingue una impresión de otra es de dónde salió.
      -->
      <template #option="{ option }">
        <span class="impresion">
          <span class="impresion__set">{{ option.setName }} <em>({{ option.setCode }})</em></span>
          <small class="impresion__meta">
            #{{ option.collectorNumber }} · {{ option.precio }}
          </small>
        </span>
      </template>

      <template #empty>
        <span class="impresiones__vacio">{{ error || 'Sin impresiones que ofrecer.' }}</span>
      </template>

      <!--
        El pie solo aparece cuando el backend dijo que quedan más. Y aparece
        porque tiene que aparecer: un `Forest` tiene 949 impresiones y la
        primera página son 100, así que sin esto las otras 849 no existirían
        para quien las busca — justo el callejón sin salida que este plan abrió
        el endpoint para cerrar.
      -->
      <template v-if="cursor" #footer>
        <div class="impresiones__pie">
          <Button
            :label="`Ver más (${opciones.length} de ${printingCount})`"
            icon="pi pi-angle-down"
            text
            size="small"
            :loading="cargando"
            @click="cargarMas"
          />
        </div>
      </template>
    </Select>

    <!--
      El aviso de error va FUERA del desplegable a propósito: si la petición
      falla, el overlay ni siquiera tiene opciones que enseñar, y un mensaje que
      solo se lee con el desplegable abierto no se lee.
    -->
    <small v-if="error && !opciones.length" class="impresiones__error">
      <i class="pi pi-exclamation-triangle"></i> {{ error }}
    </small>
  </div>
</template>

<script setup>
import { computed, ref } from 'vue'
import Button from 'primevue/button'
import Select from 'primevue/select'

import { catalogGet } from '@/services/api'

/**
 * El desplegable que corrige una **edición asumida** antes de importarla.
 *
 * Cuando la línea pegada no dice edición —`4 Lightning Bolt`—, el resolvedor
 * elige por ti la impresión **más barata** de esa carta. Es el defecto correcto
 * (valorar de menos antes que inflar), pero es una elección, no un dato: este
 * componente es lo único que permite decir «no, la mía es esta».
 *
 * Tres cosas que no son de gusto:
 *
 *  - **No vive dentro de `ImportCandidate.vue`.** Ese componente se pinta
 *    siempre dentro de un `<button>` (`ImportView.vue:159`, `:170`, `:186`), y
 *    un `Select` ahí dentro es HTML inválido —contenido interactivo anidado— y
 *    además no funciona: abrirlo dispara el `@click` del botón y reelige el
 *    candidato. Por eso es componente propio, y por eso `ImportView` lo monta
 *    como **hermano** del botón y nunca dentro.
 *  - **Carga al desplegarse, no al montarse.** Una previsualización con 300
 *    filas de edición asumida montaría 300 de estos; pedir el endpoint en
 *    `onMounted` serían 300 peticiones para enseñar cero desplegables abiertos.
 *    `@before-show` las convierte en una por desplegable que alguien abre de
 *    verdad, y `cargadas` impide repetirla al reabrirlo.
 *  - **Se pide con `catalogGet`, no con `apiCall`.** `/api/catalog/cards/{uuid}/printings`
 *    es una ruta `GET` del catálogo (la divergencia 3), no una acción del
 *    endpoint único.
 *
 * Lo que emite es el `printingUuid` concreto y nada más: quién lo guarda y
 * dónde aterriza en el payload de `import_apply` es cosa de `ImportView`.
 */
const props = defineProps({
  /** La impresión que el resolvedor asumió. Es lo que sale marcado de inicio. */
  printingUuid: { type: String, required: true },
  /** Cuántas impresiones tiene la carta. Solo para decir «N de M» en el pie. */
  printingCount: { type: Number, default: 0 },
  /** El nombre de la carta, para el `aria-label`. Llega con el ` // ` entero. */
  nombre: { type: String, required: true },
  /** La impresión ya elegida a mano, o `null` si aún manda la asumida. */
  elegida: { type: String, default: null }
})

defineEmits(['elegir'])

/**
 * El tope es el del router (`LIMITE_MAXIMO = 100`), no el defecto de 60.
 *
 * Con 100 se cubre de una tirada la inmensa mayoría de las cartas reimpresas
 * —`Lightning Bolt` son 71— y solo las tierras básicas necesitan el pie.
 */
const LIMITE = 100

const opciones = ref([])
const cargando = ref(false)
const cargadas = ref(false)
const cursor = ref(null)
const error = ref('')

const marcador = computed(() =>
  props.printingCount > 1 ? `Elegir otra de las ${props.printingCount}` : 'Elegir edición'
)

async function cargar() {
  if (cargadas.value || cargando.value) {
    return
  }

  cargadas.value = true
  await pedir(null)
}

async function cargarMas() {
  if (cargando.value || !cursor.value) {
    return
  }

  await pedir(cursor.value)
}

async function pedir(desde) {
  cargando.value = true
  error.value = ''

  const respuesta = await catalogGet(`/cards/${props.printingUuid}/printings`, {
    limit: LIMITE,
    cursor: desde
  })

  cargando.value = false

  // El catálogo contesta JSON también en los errores (404 `printing_not_found`,
  // o `network_error` si no hubo respuesta): sin `items` no hay nada que pintar.
  if (!respuesta || !Array.isArray(respuesta.items)) {
    error.value = 'No se pudieron cargar las impresiones de esta carta.'
    // Una carga fallida no puede quedarse marcada como hecha: reabrir el
    // desplegable tiene que volver a intentarlo.
    cargadas.value = desde !== null

    return
  }

  opciones.value = desde === null
    ? respuesta.items.map(aOpcion)
    : [...opciones.value, ...respuesta.items.map(aOpcion)]
  cursor.value = respuesta.nextCursor ?? null
}

/**
 * Del contrato de `aContrato()` a lo que se pinta.
 *
 * `etiqueta` existe porque es lo que filtra el `filter` del `Select` y lo que
 * se lee en el campo una vez cerrado; el `#option` la desglosa en dos líneas.
 */
function aOpcion(carta) {
  return {
    uuid: carta.uuid,
    setCode: carta.setCode,
    setName: carta.setName,
    collectorNumber: carta.collectorNumber,
    precio: precioDe(carta),
    etiqueta: `${carta.setName} (${carta.setCode}) · ${carta.collectorNumber} · ${precioDe(carta)}`
  }
}

/**
 * El precio, leído del **contrato** y no de los alias del SQL: es `priceEur`
 * con sus tres acabados, nunca `priceNormal`.
 *
 * Un printing sin cotizar vale *no se sabe*, no 0 — por eso se cae al siguiente
 * acabado antes de rendirse, y cuando no hay ninguno lo dice con palabras.
 */
function precioDe(carta) {
  const precio = carta.priceEur?.normal ?? carta.priceEur?.foil ?? carta.priceEur?.etched

  return precio === null || precio === undefined ? 'sin precio' : `${Number(precio).toFixed(2)} €`
}
</script>

<style scoped>
.impresiones {
  display: flex;
  flex-direction: column;
  gap: 0.2rem;
}

.impresiones__select {
  max-width: 16rem;
}

.impresion {
  display: flex;
  flex-direction: column;
  gap: 0.1rem;
}

.impresion__set {
  font-size: 0.85rem;
}

.impresion__set em {
  font-style: normal;
  color: var(--p-text-muted-color);
}

.impresion__meta {
  font-size: 0.72rem;
  color: var(--p-text-muted-color);
}

.impresiones__pie {
  display: flex;
  justify-content: center;
  padding: 0.25rem;
}

.impresiones__vacio {
  display: block;
  padding: 0.5rem 0.75rem;
  font-size: 0.8rem;
  color: var(--p-text-muted-color);
}

.impresiones__error {
  color: var(--p-red-500);
  font-size: 0.72rem;
}
</style>
