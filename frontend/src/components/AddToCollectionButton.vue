<template>
  <div class="anadir" :class="{ 'anadir--compacto': compacto }">
    <!--
      EL CAMINO PRINCIPAL. Un clic aquí es una carta en la colección: llama al
      store y ya está. Ni diálogo, ni confirmación, ni un segundo botón que
      pulsar. Es lo que hace que diez cartas cuesten diez clics.
    -->
    <Button
      :label="compacto ? null : 'Añadir'"
      icon="pi pi-plus"
      size="small"
      :loading="ocupado"
      :aria-label="`Añadir ${nombre} a mi colección`"
      class="anadir__principal"
      @click.stop="anadirRapido"
    />

    <!--
      EL CORAZÓN. Va aquí, entre el grande y el de opciones, porque es el mismo
      gesto sobre la otra lista: un clic = un deseo. No abre nada, no pregunta
      cantidad y no deja ningún "modo deseos" puesto para la carta siguiente.

      RELLENO cuando la carta ya está en la lista. Un corazón que es siempre un
      contorno no dice nada: pulsarlo dos veces suma un segundo deseo sin avisar
      de que ya había uno.
    -->
    <Button
      :icon="deseada ? 'pi pi-heart-fill' : 'pi pi-heart'"
      size="small"
      :severity="deseada ? 'danger' : 'secondary'"
      text
      :loading="deseando"
      :aria-label="deseada
        ? `${nombre} ya está en tu lista de deseos — querer otra`
        : `Añadir ${nombre} a mi lista de deseos`"
      :title="deseada ? 'Ya la quieres' : 'La quiero'"
      class="anadir__corazon"
      :class="{ 'anadir__corazon--relleno': deseada }"
      @click.stop="desearRapido"
    />

    <!--
      Y el camino de al lado, fuera del principal: acabado, idioma, estado y
      cantidad. Está escondido tras "Opciones" porque son las cinco dimensiones
      que hacen falta para importar, no para registrar una carta a mano.
    -->
    <Button
      icon="pi pi-sliders-h"
      size="small"
      severity="secondary"
      text
      :disabled="ocupado"
      :aria-label="`Opciones para añadir ${nombre}`"
      title="Opciones"
      class="anadir__opciones"
      @click.stop="alternarOpciones"
    />

    <Popover ref="opciones" @click.stop>
      <div class="anadir__panel">
        <h3 class="anadir__titulo">Añadir con opciones</h3>

        <label class="anadir__campo">
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

        <label class="anadir__campo">
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

        <label class="anadir__campo">
          <span>Estado</span>
          <Select
            v-model="avanzado.condition"
            :options="CONDICIONES"
            option-label="label"
            option-value="value"
            size="small"
            fluid
          />
        </label>

        <label class="anadir__campo">
          <span>Cantidad</span>
          <InputNumber
            v-model="avanzado.quantity"
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
          :loading="ocupado"
          class="anadir__confirmar"
          @click="anadirConOpciones"
        />
      </div>
    </Popover>
  </div>
</template>

<script setup>
import { computed, onMounted, reactive, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import Button from 'primevue/button'
import InputNumber from 'primevue/inputnumber'
import Popover from 'primevue/popover'
import Select from 'primevue/select'

import { ACABADOS, CONDICIONES, IDIOMAS, POR_DEFECTO } from '@/constants/collection'
import { useAuthStore } from '@/stores/auth'
import { useCollectionStore } from '@/stores/collection'
import { useWishlistStore } from '@/stores/wishlist'

/**
 * El botón "Añadir" del catálogo, tanto en la rejilla como en la ficha.
 *
 * **La mitigación del riesgo del plan vive en este componente**: si añadir una
 * carta costara cinco clics nadie registraría nada. Por eso el botón grande
 * llama directamente al store con solo el `printing_uuid` y el backend pone los
 * valores por defecto (`normal` / `English` / `NM`, cantidad 1); la
 * confirmación es un aviso que se va solo (`CollectionAviso`) y no pide cerrar
 * nada.
 *
 * Añadir la misma carta dos veces **suma a 2** y no se impide: el upsert del
 * backend lo resuelve, y bloquearlo aquí sería justamente lo contrario de lo
 * que hace falta cuando abres un sobre con tres copias.
 *
 * **El corazón hereda esa misma promesa** sobre la otra lista: un clic es un
 * deseo, por `collection_add` con `is_wishlist` —no hace falta ningún endpoint
 * nuevo—. Lo que NO comparte es el estado de "en vuelo": `estaAnadiendo` y
 * `estaDeseando` indexan los dos por `printing_uuid`, así que con uno solo
 * pulsar el corazón dejaría muerto el botón "Añadir" de la misma carta.
 *
 * **Y se pinta relleno si la carta ya está deseada.** Eso sí necesitó una
 * acción nueva (`collection_wished_uuids`), porque el catálogo NO tiene sesión:
 * `GET /api/catalog/cards` se desvía antes de construir `Application`
 * (`backend/public/index.php:30-43`) y no puede venir anotado con la lista de
 * deseos de nadie. El cruce lo hace el cliente contra el `Set` del store.
 *
 * **La lista se pide al montar y una sola vez**, no una por tarjeta: la guarda
 * está en el store (`cargarDeseados()`), así que las 60 tarjetas de una página
 * de catálogo son UNA petición. Se pide desde aquí y no desde las vistas para
 * que el corazón se baste solo dondequiera que se monte —la rejilla, la ficha,
 * el buscador del mazo—, que es lo que hace que las tres vean lo mismo.
 *
 * **Sobre la sesión:** hoy `/catalog` y `/card/:uuid` ya están detrás del guard
 * de `router/index.js`, así que quien ve este botón tiene sesión. Aun así el
 * clic comprueba `isAuthenticated` y, si no la hay, manda a `/login` con el
 * `redirect` de vuelta en lugar de disparar un 401: el botón se ve siempre
 * —esconderlo dejaría el catálogo sin decir para qué sirve— y el clic no se
 * pierde. Si algún día el catálogo se abre al público, esto ya funciona.
 */

const props = defineProps({
  printingUuid: { type: String, required: true },
  nombre: { type: String, default: 'esta carta' },
  /**
   * Los acabados que existen de este printing, tal como los da el catálogo:
   * `{ foil, nonfoil, etched }`. Sirve para no ofrecer un foil de una carta que
   * nunca se imprimió en foil — un acabado inexistente se guardaría igual y
   * valoraría con un precio que no hay.
   */
  finishes: { type: Object, default: null },
  /** En la rejilla el botón va sin texto: no hay sitio y la carta ya se ve. */
  compacto: { type: Boolean, default: false }
})

const router = useRouter()
const route = useRoute()
const auth = useAuthStore()
const coleccion = useCollectionStore()
const deseos = useWishlistStore()

const opciones = ref(null)

const avanzado = reactive({ ...POR_DEFECTO })

const ocupado = computed(() => coleccion.estaAnadiendo(props.printingUuid))

/** El corazón tiene su propio "en vuelo": ver el docblock de arriba. */
const deseando = computed(() => deseos.estaDeseando(props.printingUuid))

/** ¿Ya la quieres? Es lo único que separa el corazón relleno del contorno. */
const deseada = computed(() => deseos.esDeseada(props.printingUuid))

// Sin sesión no se pide: el catálogo se ve igual y un 401 por algo que el
// usuario no ha pulsado sería ruido. Al entrar, el corazón se rellena solo en
// cuanto la vista se vuelva a montar.
onMounted(() => {
  if (auth.isAuthenticated) {
    deseos.cargarDeseados()
  }
})

const acabadosPosibles = computed(() => {
  if (!props.finishes) {
    return ACABADOS
  }

  const disponibles = {
    normal: props.finishes.nonfoil,
    foil: props.finishes.foil,
    etched: props.finishes.etched
  }

  const filtrados = ACABADOS.filter((a) => disponibles[a.value])

  // Si el catálogo no marca ninguno, se ofrecen todos antes que un desplegable
  // vacío que impediría añadir la carta.
  return filtrados.length > 0 ? filtrados : ACABADOS
})

/** Sin sesión no se llama a la API: se manda a entrar y luego se vuelve aquí. */
function exigeSesion() {
  if (auth.isAuthenticated) {
    return false
  }

  router.push({ name: 'login', query: { redirect: route.fullPath } })

  return true
}

/** UN clic = UNA carta. Aquí no puede aparecer nunca un paso intermedio. */
function anadirRapido() {
  if (exigeSesion()) {
    return
  }

  coleccion.anadir(props.printingUuid)
}

/** UN clic = UN deseo. La misma regla que arriba, sobre la otra lista. */
function desearRapido() {
  if (exigeSesion()) {
    return
  }

  deseos.desear(props.printingUuid)
}

function alternarOpciones(evento) {
  if (exigeSesion()) {
    return
  }

  // El panel se abre siempre en los valores por defecto: es lo que habría hecho
  // el clic simple, y así "Opciones" es un retoque, no un formulario en blanco.
  Object.assign(avanzado, POR_DEFECTO)

  if (!acabadosPosibles.value.some((a) => a.value === avanzado.finish)) {
    avanzado.finish = acabadosPosibles.value[0].value
  }

  opciones.value?.toggle(evento)
}

async function anadirConOpciones(evento) {
  const anadida = await coleccion.anadir(props.printingUuid, {
    finish: avanzado.finish,
    language: avanzado.language,
    condition: avanzado.condition,
    quantity: avanzado.quantity || 1
  })

  if (anadida) {
    opciones.value?.hide(evento)
  }
}
</script>

<style scoped>
.anadir {
  display: flex;
  align-items: center;
  gap: 0.15rem;
}

.anadir--compacto .anadir__principal {
  flex: 1;
}

.anadir__panel {
  display: flex;
  flex-direction: column;
  gap: 0.6rem;
  min-width: 15rem;
}

.anadir__titulo {
  margin: 0;
  font-size: 0.75rem;
  text-transform: uppercase;
  letter-spacing: 0.04em;
  color: var(--p-text-muted-color);
}

.anadir__campo {
  display: flex;
  flex-direction: column;
  gap: 0.2rem;
  font-size: 0.78rem;
}

.anadir__campo > span {
  color: var(--p-text-muted-color);
}

.anadir__confirmar {
  align-self: flex-end;
}

/*
 * El relleno se ve por el icono (`pi-heart-fill`) y por el color de `danger`;
 * esto solo lo asienta, para que en la rejilla —donde el botón es diminuto y
 * está sobre la carta— se distinga de un vistazo y no haya que compararlo con
 * el de al lado.
 */
.anadir__corazon--relleno {
  opacity: 1;
}
</style>
