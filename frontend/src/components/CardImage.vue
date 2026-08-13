<template>
  <div class="carta-img">
    <!--
      El hueco va SIEMPRE debajo, y la imagen encima cuando existe. La primera
      versión ocultaba la imagen con opacity:0 hasta el evento `load`, y ese
      evento NO llega si el navegador ya la tiene en caché o si Vue recicla el
      nodo al hacer scroll: la imagen estaba cargada y no se veía.
    -->
    <div class="carta-img__hueco">
      <i class="pi pi-image"></i>
      <span v-if="fallo || !url">{{ nombre }}</span>
    </div>

    <img
      v-if="url && !fallo"
      :src="url"
      :alt="nombre"
      loading="lazy"
      decoding="async"
      @error="fallo = true"
    >
  </div>
</template>

<script setup>
import { computed, ref, watch } from 'vue'
import { imagenDeCarta } from '@/services/scryfall'

const props = defineProps({
  scryfallId: { type: String, default: null },
  nombre: { type: String, default: '' },
  tamano: { type: String, default: 'normal' }
})

const fallo = ref(false)

const url = computed(() => imagenDeCarta(props.scryfallId, props.tamano))

// Al reutilizar el componente con otra carta (el scroll infinito recicla nodos)
// hay que olvidar el fallo de la anterior, o una carta sin imagen dejaría
// marcadas de por vida todas las que pasen por ese nodo.
watch(url, () => {
  fallo.value = false
})
</script>

<style scoped>
.carta-img {
  position: relative;
  aspect-ratio: 488 / 680; /* la proporción real de una carta de Magic */
  border-radius: 4.75% / 3.5%;
  overflow: hidden;
  background: var(--p-content-background);
}

.carta-img img {
  position: relative;
  z-index: 1;
  width: 100%;
  height: 100%;
  object-fit: cover;
  animation: carta-img-aparecer 0.25s ease;
}

@keyframes carta-img-aparecer {
  from { opacity: 0; }
  to   { opacity: 1; }
}

.carta-img__hueco {
  position: absolute;
  inset: 0;
  z-index: 0;
  display: flex;
  flex-direction: column;
  gap: 0.5rem;
  align-items: center;
  justify-content: center;
  padding: 0.75rem;
  text-align: center;
  font-size: 0.75rem;
  line-height: 1.3;
  color: var(--p-text-muted-color);
  background: var(--p-content-border-color);
}

.carta-img__hueco .pi {
  font-size: 1.5rem;
  opacity: 0.5;
}
</style>
