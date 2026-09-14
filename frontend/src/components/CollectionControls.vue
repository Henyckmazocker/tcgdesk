<template>
  <div class="controles" :class="{ 'controles--compacto': compacto }">
    <!--
      La cantidad se edita con botones y con el campo: el paso de uno en uno es
      el gesto habitual (acabo de abrir un sobre) y escribir a mano sirve para
      un recuento. El commit del campo va en `blur` y `enter`, no en cada
      pulsación: escribir "12" pasaría por el 1 y guardaría un 1.
    -->
    <div class="controles__cantidad">
      <Button
        icon="pi pi-minus"
        text
        rounded
        size="small"
        :disabled="ocupado"
        :aria-label="`Quitar un ejemplar de ${item.name}`"
        @click="guardarCantidad(borrador - 1)"
      />
      <InputNumber
        v-model="borrador"
        :min="0"
        :max="9999"
        :disabled="ocupado"
        :input-style="{ width: '3rem', textAlign: 'center' }"
        :aria-label="`Ejemplares de ${item.name}`"
        @blur="guardarCantidad(borrador)"
        @keyup.enter="guardarCantidad(borrador)"
      />
      <Button
        icon="pi pi-plus"
        text
        rounded
        size="small"
        :disabled="ocupado"
        :aria-label="`Añadir un ejemplar de ${item.name}`"
        @click="guardarCantidad(borrador + 1)"
      />
    </div>

    <!--
      Cambiar el estado NO es un update normal: el backend puede fundir esta
      línea con otra que ya estuviera en ese estado, y entonces esta desaparece
      de la lista sumada a la otra. El store lo resuelve mirando el id que
      vuelve.
    -->
    <Select
      :model-value="item.condition"
      :options="CONDICIONES"
      option-label="label"
      option-value="value"
      size="small"
      :disabled="ocupado"
      :aria-label="`Estado de ${item.name}`"
      class="controles__estado"
      @update:model-value="guardarCondicion"
    />

    <Button
      v-if="!compacto"
      icon="pi pi-trash"
      text
      rounded
      size="small"
      severity="danger"
      :disabled="ocupado"
      :aria-label="`Quitar ${item.name} de ${deseos ? 'tu lista de deseos' : 'la colección'}`"
      @click="lista.quitar(item)"
    />
  </div>
</template>

<script setup>
import { computed, ref, watch } from 'vue'
import Button from 'primevue/button'
import InputNumber from 'primevue/inputnumber'
import Select from 'primevue/select'

import { CONDICIONES } from '@/constants/collection'
import { useCollectionStore } from '@/stores/collection'
import { useWishlistStore } from '@/stores/wishlist'

const props = defineProps({
  item: { type: Object, required: true },
  compacto: { type: Boolean, default: false },
  /**
   * De qué lista es esta línea. La colección y los deseos son dos conjuntos
   * excluyentes con un store propio cada uno —y una Pinia es un singleton—, así
   * que el componente no puede adivinarlo: lo declara quien lo monta.
   *
   * Los dos stores exponen los mismos cuatro miembros con la misma firma
   * (`estaGuardando`, `cambiarCantidad`, `cambiarCondicion`, `quitar`), y eso es
   * justo lo que permite que este editor de UNA fila valga para las dos listas
   * sin duplicarlo.
   */
  deseos: { type: Boolean, default: false }
})

/**
 * Los dos `use*Store()` se llaman SIEMPRE, nunca dentro de un `if`: son ganchos
 * de `setup()` y el orden de las llamadas no puede depender de un prop.
 * Instanciar el store que no se usa no cuesta ninguna petición.
 */
const coleccion = useCollectionStore()
const deseados = useWishlistStore()

const lista = computed(() => (props.deseos ? deseados : coleccion))

const borrador = ref(props.item.quantity)

const ocupado = computed(() => lista.value.estaGuardando(props.item.id))

// El scroll infinito recicla nodos y una fusión cambia el item bajo el mismo
// componente: sin esto, el campo seguiría enseñando la cantidad de la anterior.
watch(
  () => [props.item.id, props.item.quantity],
  () => {
    borrador.value = props.item.quantity
  }
)

async function guardarCantidad(cantidad) {
  const valor = Number.isFinite(cantidad) ? Math.max(0, Math.min(9999, cantidad)) : props.item.quantity

  if (valor === props.item.quantity) {
    borrador.value = props.item.quantity
    return
  }

  borrador.value = valor

  // Cero borra la fila: es lo que hace el backend y la lista lo refleja
  // quitando la carta, en vez de dejar un fantasma con 0 ejemplares.
  const guardado = await lista.value.cambiarCantidad(props.item, valor)

  if (!guardado) {
    borrador.value = props.item.quantity
  }
}

function guardarCondicion(condicion) {
  if (condicion) {
    lista.value.cambiarCondicion(props.item, condicion)
  }
}
</script>

<style scoped>
.controles {
  display: flex;
  align-items: center;
  gap: 0.25rem;
  flex-wrap: wrap;
}

.controles__cantidad {
  display: flex;
  align-items: center;
  gap: 0.1rem;
}

.controles__estado {
  min-width: 6.5rem;
}

.controles--compacto .controles__estado {
  min-width: 5.5rem;
}
</style>
