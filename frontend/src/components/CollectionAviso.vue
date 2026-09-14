<template>
  <Transition name="aviso">
    <div
      v-if="lista.aviso"
      class="aviso"
      :class="`aviso--${lista.aviso.tipo}`"
      role="status"
      aria-live="polite"
    >
      <i :class="lista.aviso.tipo === 'error' ? 'pi pi-exclamation-triangle' : 'pi pi-check-circle'"></i>
      <span>{{ lista.aviso.texto }}</span>
    </div>
  </Transition>
</template>

<script setup>
import { computed, onBeforeUnmount, watch } from 'vue'

import { useCollectionStore } from '@/stores/collection'
import { useWishlistStore } from '@/stores/wishlist'

/**
 * La confirmación discreta de la colección.
 *
 * **Discreta quiere decir que no interrumpe**: no es un diálogo, no roba el
 * foco, no tiene botón que pulsar y se va sola. Eso es lo que exige el hito de
 * "un clic = una carta": si añadir obligara a cerrar algo, el clic de cerrar
 * contaría y diez cartas volverían a costar veinte clics.
 *
 * No usa el `Toast` de PrimeVue a propósito: `ToastService` no está registrado
 * en `main.js` y M3 ya resolvió el mismo problema con este aviso en
 * `CollectionView`. Aquí solo se extrae a un componente para que el catálogo y
 * la ficha de carta enseñen exactamente el mismo aviso, en vez de tener cada
 * vista el suyo.
 *
 * La fuente es siempre el `aviso` de UN store, así que un aviso nuevo pisa al
 * anterior en vez de apilar mensajes: añadiendo diez cartas seguidas se ve la
 * última, no una pila de diez.
 *
 * Desde el plan de la lista de deseos ese store puede ser el de la colección o
 * el de los deseos, y lo dice el prop `deseos`. **Cada vista monta el suyo**: si
 * este componente leyera los dos avisos a la vez, el de `/collection` —que sigue
 * viva en el historial con su Pinia intacta— se pintaría encima de `/wishlist`.
 */

/** Lo que tarda en irse solo. Bastante para leerlo, poco para no estorbar. */
const DURACION_MS = 3500

const props = defineProps({
  /** `true` para el aviso de `/wishlist`; por defecto, el de la colección. */
  deseos: { type: Boolean, default: false }
})

// Los dos ganchos se llaman siempre, nunca dentro de un `if`; ver
// `CollectionControls.vue`, que hace lo mismo y por el mismo motivo.
const coleccion = useCollectionStore()
const deseados = useWishlistStore()

const lista = computed(() => (props.deseos ? deseados : coleccion))

let temporizador = null

watch(
  () => lista.value.aviso,
  (aviso) => {
    clearTimeout(temporizador)

    if (aviso) {
      temporizador = setTimeout(() => {
        lista.value.aviso = null
      }, DURACION_MS)
    }
  },
  { immediate: true }
)

onBeforeUnmount(() => {
  clearTimeout(temporizador)
  // Un aviso pendiente no debe reaparecer al volver a montar la vista.
  lista.value.aviso = null
})
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
