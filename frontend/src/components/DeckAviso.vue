<template>
  <Transition name="aviso">
    <div
      v-if="mazos.aviso"
      class="aviso"
      :class="`aviso--${mazos.aviso.tipo}`"
      role="status"
      aria-live="polite"
    >
      <i :class="mazos.aviso.tipo === 'error' ? 'pi pi-exclamation-triangle' : 'pi pi-check-circle'"></i>
      <span>{{ mazos.aviso.texto }}</span>
    </div>
  </Transition>
</template>

<script setup>
import { onBeforeUnmount, watch } from 'vue'

import { useDeckStore } from '@/stores/deck'

/**
 * La confirmación discreta del editor de mazos.
 *
 * Es el gemelo de `CollectionAviso` y por el mismo motivo: **no interrumpe**.
 * No es un diálogo, no roba el foco, no tiene botón que pulsar y se va solo. Si
 * añadir una carta obligara a cerrar algo, el clic de cerrar contaría y montar
 * un Commander de 100 cartas costaría 200 clics — que es justo el riesgo que
 * este plan mide.
 *
 * No se reutiliza `CollectionAviso` porque aquella lee del store de colección:
 * el aviso de «carta añadida al mazo» no es un cambio de la colección, y
 * mezclarlos haría que el editor pisara los avisos de `/collection`.
 */

/** Lo que tarda en irse solo. Bastante para leerlo, poco para no estorbar. */
const DURACION_MS = 3500

const mazos = useDeckStore()

let temporizador = null

watch(
  () => mazos.aviso,
  (aviso) => {
    clearTimeout(temporizador)

    if (aviso) {
      temporizador = setTimeout(() => {
        mazos.aviso = null
      }, DURACION_MS)
    }
  },
  { immediate: true }
)

onBeforeUnmount(() => clearTimeout(temporizador))
</script>

<style scoped>
.aviso {
  position: fixed;
  left: 50%;
  bottom: 1.25rem;
  z-index: 50;
  display: flex;
  align-items: center;
  gap: 0.5rem;
  transform: translateX(-50%);
  max-width: 90vw;
  padding: 0.6rem 1rem;
  border-radius: 6px;
  font-size: 0.85rem;
  color: var(--p-primary-contrast-color);
  background: var(--p-primary-color);
  box-shadow: 0 4px 16px rgb(0 0 0 / 25%);
  /* No interrumpe: tampoco intercepta el clic siguiente. */
  pointer-events: none;
}

.aviso--error {
  color: #fff;
  background: var(--p-red-500);
}

.aviso-enter-active,
.aviso-leave-active {
  transition: opacity 0.2s ease, transform 0.2s ease;
}

.aviso-enter-from,
.aviso-leave-to {
  opacity: 0;
  transform: translate(-50%, 0.5rem);
}
</style>
