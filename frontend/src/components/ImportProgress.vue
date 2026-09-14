<template>
  <div class="progreso" role="status" aria-live="polite">
    <ProgressBar v-if="valor === null" mode="indeterminate" class="progreso__barra" />
    <ProgressBar v-else :value="valor" class="progreso__barra" />
    <p class="progreso__texto">{{ texto }}</p>
  </div>
</template>

<script setup>
import { computed } from 'vue'
import ProgressBar from 'primevue/progressbar'

/**
 * La barra de progreso de la importación.
 *
 * Existe porque el hito la pide «para ficheros grandes», y un fichero grande
 * pasa por **tres esperas distintas** que sin esto son la misma pantalla
 * congelada: leer el fichero en el navegador, subir sus megas y esperar a que el
 * servidor resuelva contra el catálogo.
 *
 * Las dos primeras tienen porcentaje real (FileReader y `onUploadProgress` de
 * axios). La tercera **no lo tiene y no se finge**: una barra que avanza sola
 * miente sobre cuánto queda, así que ahí se pone en modo indeterminado y lo que
 * informa es el texto.
 */
const props = defineProps({
  /** 'leyendo' | 'subiendo' | 'resolviendo' | 'aplicando' */
  fase: { type: String, required: true },
  /** Porcentaje 0-100, o null cuando no se puede saber. */
  valor: { type: Number, default: null },
  /** Cuántas líneas hay en juego, para que la espera tenga tamaño. */
  lineas: { type: Number, default: 0 }
})

const texto = computed(() => {
  const cuantas = props.lineas ? `${props.lineas.toLocaleString('es-ES')} líneas` : 'el fichero'

  switch (props.fase) {
    case 'leyendo':
      return `Leyendo el fichero… ${props.valor ?? 0} %`
    case 'subiendo':
      return `Enviando ${cuantas}… ${props.valor ?? 0} %`
    case 'aplicando':
      return `Escribiendo ${cuantas} en tu colección…`
    default:
      return `Resolviendo ${cuantas} contra el catálogo. No cierres esta página.`
  }
})
</script>

<style scoped>
.progreso {
  margin-top: 1.25rem;
}

.progreso__barra {
  height: 0.6rem;
}

.progreso__texto {
  font-size: 0.85rem;
  color: var(--p-text-muted-color);
  margin: 0.5rem 0 0;
}
</style>
