<template>
  <div class="cantidad">
    <Button
      icon="pi pi-minus"
      text
      rounded
      size="small"
      :disabled="ocupado"
      :aria-label="`Quitar una copia de ${carta.name}`"
      @click="guardar(borrador - 1)"
    />
    <InputNumber
      v-model="borrador"
      :min="0"
      :max="9999"
      :disabled="ocupado"
      :input-style="{ width: '2.6rem', textAlign: 'center' }"
      :aria-label="`Copias de ${carta.name} en el mazo`"
      @blur="guardar(borrador)"
      @keyup.enter="guardar(borrador)"
    />
    <Button
      icon="pi pi-plus"
      text
      rounded
      size="small"
      :disabled="ocupado"
      :aria-label="`Añadir una copia de ${carta.name}`"
      @click="guardar(borrador + 1)"
    />
  </div>
</template>

<script setup>
import { computed, ref, watch } from 'vue'
import Button from 'primevue/button'
import InputNumber from 'primevue/inputnumber'

import { useDeckStore } from '@/stores/deck'

/**
 * Cuántas copias lleva una línea del mazo.
 *
 * El commit va en `blur` y en `enter`, **no en cada pulsación**: escribir «12»
 * pasa por el 1 y guardaría un 1. Es la misma razón —y el mismo gesto— que en
 * `CollectionControls`.
 *
 * **Cero borra la línea**, igual que en la colección: una línea a 0 contaría
 * como carta del mazo y falsearía el tamaño.
 */

const props = defineProps({
  carta: { type: Object, required: true }
})

const mazos = useDeckStore()

const borrador = ref(props.carta.count)

const ocupado = computed(() => mazos.estaGuardando(props.carta.id))

// La fila puede venir cambiada del servidor (una suma, una fusión): el borrador
// tiene que seguir a lo que hay, no quedarse con lo que el usuario tecleó.
watch(
  () => props.carta.count,
  (valor) => {
    borrador.value = valor
  }
)

async function guardar(cantidad) {
  const valor = Number.isFinite(cantidad) ? cantidad : props.carta.count

  if (valor === props.carta.count) {
    borrador.value = props.carta.count
    return
  }

  const guardado = await mazos.fijarCantidad(props.carta, Math.max(0, Math.min(9999, valor)))

  if (!guardado) {
    borrador.value = props.carta.count
  }
}
</script>

<style scoped>
.cantidad {
  display: flex;
  align-items: center;
  gap: 0.1rem;
}
</style>
