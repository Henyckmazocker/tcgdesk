<template>
  <span class="candidato-etq">
    <span class="candidato-etq__nombre">{{ candidato.name }}</span>
    <span class="candidato-etq__meta">
      <template v-if="candidato.setCode">{{ candidato.setCode }}</template>
      <template v-else-if="!candidato.printingUuid">sin impresión en el catálogo</template>
      <template v-if="candidato.assumedPrinting">
        · edición asumida de {{ candidato.printingCount }}
      </template>
    </span>
  </span>
</template>

<script setup>
/**
 * Un candidato de conflicto, tal como se le ofrece al usuario para elegirlo.
 *
 * Enseña la edición y, cuando la trae asumida, lo dice: un candidato de los
 * pasos por nombre identifica la CARTA, no la impresión, así que elegirlo mete
 * la **más barata** de sus ediciones. Callárselo sería cambiar un fallo
 * silencioso (importar otra carta) por otro (importar otra edición).
 */
defineProps({
  candidato: { type: Object, required: true }
})
</script>

<style scoped>
.candidato-etq {
  display: flex;
  flex-direction: column;
  gap: 0.1rem;
}

.candidato-etq__nombre {
  font-weight: 600;
  font-size: 0.9rem;
}

.candidato-etq__meta {
  font-size: 0.75rem;
  color: var(--p-text-muted-color);
}
</style>
