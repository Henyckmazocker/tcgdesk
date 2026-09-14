<template>
  <div class="compartido">
    <header class="compartido__bar">
      <span class="compartido__marca">TCGDesk</span>
      <h1 class="compartido__titulo">{{ mazo?.name || 'Mazo compartido' }}</h1>
    </header>

    <div v-if="perfil.cargandoMazo" class="compartido__main">
      <Skeleton height="5rem" />
      <Skeleton height="18rem" />
    </div>

    <!--
      UN SOLO MENSAJE PARA LOS TRES CASOS, porque el backend manda un solo 404
      para los tres: token mal formado, token que nunca existió y token revocado.
      Distinguirlos aquí sería deshacer desde el cliente lo que el backend hace a
      propósito —un 403 confirmaría que el token existe, que es justo lo que un
      atacante quiere saber—. Así que se dice lo único que se sabe: el enlace no
      lleva a ningún sitio.
    -->
    <main v-else-if="perfil.errorMazo === 'no_existe'" class="compartido__main">
      <p class="compartido__vacio">
        <i class="pi pi-link"></i>
        Este enlace no lleva a ningún mazo. Puede que nunca haya existido o que
        su dueño haya dejado de compartirlo.
      </p>
    </main>

    <main v-else-if="perfil.errorMazo" class="compartido__main">
      <p class="compartido__error">
        <i class="pi pi-exclamation-triangle"></i> {{ perfil.errorMazo }}
      </p>
    </main>

    <main v-else-if="mazo" class="compartido__main">
      <section class="cabecera">
        <div class="cabecera__datos">
          <Tag
            :value="etiquetaEstadoMazo(mazo.status)"
            :severity="severidadEstadoMazo(mazo.status)"
          />
          <span class="cabecera__formato">{{ mazo.format || 'sin formato' }}</span>
        </div>

        <dl class="cabecera__cifras">
          <div>
            <dt>Cartas</dt>
            <dd>{{ mazo.cards }}</dd>
          </div>
          <div>
            <dt>Líneas</dt>
            <dd>{{ mazo.cardLines }}</dd>
          </div>
          <div>
            <dt>Valor</dt>
            <dd>{{ euros(perfil.mazo.valueEur) }}</dd>
          </div>
        </dl>
      </section>

      <!--
        LO QUE ESTE MAZO NO TRAE, y no es un olvido: el cruce con la colección de
        su dueño —cuánto le falta, qué copias tiene libres, qué mazos se pelean
        por la misma carta— NO viaja por este enlace. Compartir un mazo es un
        acto explícito sobre ese mazo; su inventario es otra cosa, y colarlo por
        la puerta de al lado sería publicar la colección de alguien que solo
        quiso enseñar una lista.
      -->
      <section v-if="avisoDeLegalidad" class="legalidad">
        <h2 class="legalidad__titulo">
          <i class="pi pi-info-circle"></i> {{ avisoDeLegalidad.titulo }}
        </h2>
        <ul class="legalidad__lista">
          <li v-for="linea in avisoDeLegalidad.lineas" :key="linea">{{ linea }}</li>
        </ul>
      </section>

      <section v-for="zona in zonas" :key="zona.value" class="zona">
        <h2 class="zona__titulo">
          {{ zona.label }}
          <small>{{ ejemplaresDe(zona) }} carta(s)</small>
          <small v-if="!zona.cuenta" class="zona__nota">
            — los tokens no cuentan para el tamaño ni para el valor
          </small>
        </h2>

        <DataTable
          :value="zona.cartas"
          data-key="clave"
          size="small"
          striped-rows
          sort-field="name"
          :sort-order="1"
        >
          <Column header="" style="width: 3rem">
            <template #body="{ data }">
              <!--
                Sin enlace a `/card/:uuid`: esa ruta está tras el guard de
                sesión y quien abre este enlace puede no tener cuenta. Un mazo
                compartido cuyo primer clic pide iniciar sesión no está
                compartido.
              -->
              <CardImage :scryfall-id="data.scryfallId" :nombre="data.name" tamano="small" />
            </template>
          </Column>

          <Column field="name" header="Carta" sortable>
            <template #body="{ data }">
              <span class="zona__nombre">{{ data.name }}</span>
              <small class="zona__sub">{{ data.setName }} · {{ data.collectorNumber }}</small>
            </template>
          </Column>

          <Column field="finish" header="Acabado" style="width: 7rem">
            <template #body="{ data }">{{ etiquetaAcabado(data.finish) }}</template>
          </Column>

          <Column field="count" header="Copias" sortable style="width: 6rem" />

          <Column field="lineValue" header="Valor" sortable style="width: 7rem">
            <template #body="{ data }">{{ euros(data.lineValue) }}</template>
          </Column>
        </DataTable>
      </section>
    </main>
  </div>
</template>

<script setup>
import { computed, onBeforeUnmount, onMounted, watch } from 'vue'
import { useRoute } from 'vue-router'
import Column from 'primevue/column'
import DataTable from 'primevue/datatable'
import Skeleton from 'primevue/skeleton'
import Tag from 'primevue/tag'

import CardImage from '@/components/CardImage.vue'
import {
  ZONAS,
  etiquetaAcabado,
  etiquetaEstadoMazo,
  severidadEstadoMazo
} from '@/constants/collection'
import { usePublicProfileStore } from '@/stores/publicProfile'

/**
 * El mazo de un enlace compartido: `/shared/deck/:token`, `meta: { public: true }`.
 *
 * Es la hermana pública de `DeckView`, con dos diferencias que la definen:
 *
 *  - **No edita nada.** No hay buscador, ni contadores, ni desplegable de
 *    formato: todo lo que este plan abre es lectura.
 *  - **No enseña el cruce con la colección.** Ni lo que falta, ni los
 *    conflictos, ni lo que hay libre. El backend ni siquiera lo manda; aquí se
 *    dice para que nadie lo eche de menos y lo «arregle».
 *
 * Tampoco depende del perfil ni de la privacidad de su dueño: compartir un mazo
 * es un acto explícito sobre ese mazo, así que un mazo con enlace se ve entero
 * aunque el perfil de quien lo comparte esté cerrado a cal y canto.
 */

const route = useRoute()
const perfil = usePublicProfileStore()

const token = computed(() => String(route.params.token ?? ''))

/** La cabecera del mazo. El resto del sobre son `boards`, `cards` y el valor. */
const mazo = computed(() => perfil.mazo?.deck ?? null)

/**
 * Las cartas agrupadas por zona, en el orden del ENUM y solo las que llevan
 * algo. El backend ya las agrupa en `boards`; aquí solo se les pone etiqueta.
 */
const zonas = computed(() =>
  ZONAS.map((zona) => ({
    ...zona,
    cartas: (perfil.mazo?.boards?.[zona.value] ?? []).map((carta) => ({
      ...carta,
      // La PK real de la línea es de cuatro columnas: una misma impresión puede
      // estar en la misma zona en normal y en foil.
      clave: `${carta.printingUuid}|${carta.board}|${carta.finish}|${carta.condition}`
    }))
  })).filter((zona) => zona.cartas.length > 0)
)

/**
 * El resumen de legalidad, con el mismo criterio que `DeckView`: **avisa, no
 * bloquea**, y si no hay nada que decir no dice nada —un panel que repite «el
 * mazo es legal» en cada carga es ruido—.
 *
 * Un formato que `mtg_legality` no conoce se nombra y no marca ninguna carta:
 * el formato lo teclea el dueño, y con «edh» en vez de «commander» las cien
 * cartas saldrían «no permitida», que es una alarma falsa y entera.
 */
const avisoDeLegalidad = computed(() => {
  const legalidad = perfil.mazo?.legality

  if (!legalidad?.format) {
    return null
  }

  if (!legalidad.known) {
    return {
      titulo: `«${legalidad.format}» no es un formato conocido`,
      lineas: ['No hay legalidades para ese nombre, así que no se marca ninguna carta.']
    }
  }

  const lineas = []

  if (legalidad.banned > 0) {
    lineas.push(`${legalidad.banned} carta(s) prohibida(s) en ${legalidad.format}.`)
  }

  if (legalidad.restricted > 0) {
    lineas.push(`${legalidad.restricted} carta(s) restringida(s) en ${legalidad.format}.`)
  }

  if (legalidad.notLegal > 0) {
    lineas.push(`${legalidad.notLegal} carta(s) que no se pueden jugar en ${legalidad.format}.`)
  }

  if (legalidad.belowMinimum) {
    lineas.push(
      `El mazo tiene ${legalidad.size} carta(s) —sin contar tokens— y ` +
        `${legalidad.format} pide al menos ${legalidad.minSize}.`
    )
  }

  return lineas.length > 0
    ? { titulo: `Avisos de legalidad en ${legalidad.format}`, lineas }
    : null
})

function ejemplaresDe(zona) {
  return zona.cartas.reduce((suma, carta) => suma + carta.count, 0)
}

const FORMATO_EUR = new Intl.NumberFormat('es-ES', {
  style: 'currency',
  currency: 'EUR',
  minimumFractionDigits: 2,
  maximumFractionDigits: 2
})

function euros(valor) {
  return valor === null || valor === undefined ? 'sin precio' : FORMATO_EUR.format(valor)
}

watch(token, (nuevo) => {
  if (nuevo) {
    perfil.cargarMazoCompartido(nuevo)
  }
})

onMounted(() => perfil.cargarMazoCompartido(token.value))

onBeforeUnmount(() => perfil.limpiar())
</script>

<style scoped>
.compartido {
  padding-bottom: 3rem;
}

.compartido__bar {
  position: sticky;
  top: 0;
  z-index: 10;
  display: flex;
  align-items: center;
  gap: 0.75rem;
  padding: 0.6rem 0.9rem;
  background: var(--p-content-background);
  border-bottom: 1px solid var(--p-content-border-color);
}

.compartido__marca {
  font-weight: 700;
  letter-spacing: 0.02em;
}

.compartido__titulo {
  flex: 1;
  margin: 0;
  font-size: 1.05rem;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}

.compartido__main {
  display: flex;
  flex-direction: column;
  gap: 1rem;
  max-width: 60rem;
  margin: 0 auto;
  padding: 1rem 0.75rem;
}

.compartido__error {
  display: flex;
  align-items: center;
  gap: 0.4rem;
  margin: 0;
  color: var(--p-red-500);
  font-size: 0.85rem;
}

.compartido__vacio {
  display: flex;
  align-items: center;
  gap: 0.5rem;
  margin: 2rem 0;
  color: var(--p-text-muted-color);
  line-height: 1.5;
}

.cabecera {
  display: flex;
  flex-wrap: wrap;
  align-items: flex-start;
  justify-content: space-between;
  gap: 1rem;
  padding: 0.75rem;
  border: 1px solid var(--p-content-border-color);
  border-radius: 8px;
  background: var(--p-content-background);
}

.cabecera__datos {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 0.75rem;
  font-size: 0.85rem;
}

.cabecera__formato {
  color: var(--p-text-muted-color);
}

.cabecera__cifras {
  display: flex;
  gap: 1.25rem;
  margin: 0;
}

.cabecera__cifras dt {
  font-size: 0.68rem;
  text-transform: uppercase;
  letter-spacing: 0.04em;
  color: var(--p-text-muted-color);
}

.cabecera__cifras dd {
  margin: 0;
  font-size: 1.05rem;
  font-weight: 600;
}

.legalidad {
  display: flex;
  flex-direction: column;
  gap: 0.35rem;
  padding: 0.7rem 0.85rem;
  border: 1px solid var(--p-content-border-color);
  border-left: 3px solid var(--p-primary-color);
  border-radius: 8px;
}

.legalidad__titulo {
  display: flex;
  align-items: center;
  gap: 0.4rem;
  margin: 0;
  font-size: 0.9rem;
}

.legalidad__lista {
  margin: 0;
  padding-left: 1.1rem;
  font-size: 0.8rem;
  color: var(--p-text-muted-color);
}

.zona__titulo {
  display: flex;
  align-items: baseline;
  gap: 0.4rem;
  margin: 0 0 0.35rem;
  font-size: 0.95rem;
}

.zona__titulo small,
.zona__nota {
  font-size: 0.72rem;
  font-weight: 400;
  color: var(--p-text-muted-color);
}

.zona__nombre {
  display: block;
  font-size: 0.85rem;
}

.zona__sub {
  display: block;
  font-size: 0.7rem;
  color: var(--p-text-muted-color);
}
</style>
