<template>
  <div class="precon">
    <header class="precon__bar">
      <Button
        icon="pi pi-arrow-left"
        text
        rounded
        aria-label="Volver a los precons"
        @click="volver"
      />
      <h1 class="precon__titulo">{{ precons.ficha?.precon?.name || 'Precon' }}</h1>
    </header>

    <p v-if="precons.errorFicha" class="precon__error">
      <i class="pi pi-exclamation-triangle"></i> {{ precons.errorFicha }}
    </p>

    <div v-if="precons.cargandoFicha" class="precon__main">
      <Skeleton height="6rem" />
      <Skeleton height="18rem" />
    </div>

    <main v-else-if="precons.ficha" class="precon__main">
      <section class="cabecera">
        <div class="cabecera__datos">
          <Tag :value="cabecera.deckType" severity="info" />
          <span class="cabecera__edicion">
            {{ cabecera.setName || cabecera.setCode }} <small>· {{ cabecera.setCode }}</small>
          </span>
          <span class="cabecera__fecha">{{ fecha(cabecera.releaseDate) }}</span>
          <a
            v-if="cabecera.sourceUrl"
            :href="cabecera.sourceUrl"
            target="_blank"
            rel="noopener"
            class="cabecera__fuente"
          >
            <i class="pi pi-external-link"></i> lista oficial
          </a>
        </div>

        <dl class="cabecera__cifras">
          <div>
            <dt>Cartas</dt>
            <!-- Sin tokens: el backend ya los deja fuera del recuento. -->
            <dd>{{ precons.ficha.cardsTotal }}</dd>
          </div>
          <div>
            <dt>Valor</dt>
            <dd>{{ euros(precons.ficha.valueEur) }}</dd>
          </div>
        </dl>
      </section>

      <!--
        LOS DOS BOTONES DE UN CLIC (M5 y M7). Un clic aquí es la caja entera
        dentro: los ejemplares sumados a la colección y el mazo montado en
        `/decks`, las dos cosas en una sola transacción del backend.

        El segundo botón es **la misma acción con una bandera** —la caja a la
        lista de deseos en vez de a la colección—, y por eso está aquí al lado y
        no en otra pantalla: es la lista de la compra de una caja que todavía no
        has comprado, y se decide en el mismo momento y mirando lo mismo.

        **El aviso de al lado NO es decorativo y no se quita.** MTGJSON publica
        `uuid`, `count`, `isFoil` e `isEtched` de un precon, y NO publica ni el
        idioma ni el estado de las cartas: el backend los da por `English` y `NM`
        —el mismo defecto que el botón «Añadir» de la ficha de catálogo— y el
        plan exige que la UI lo diga en voz alta en vez de dejarlo implícito.
        Quien compre la caja en japonés tiene que enterarse ANTES de pulsar.
      -->
      <section class="caja">
        <div class="caja__accion">
          <Button
            label="Meter la caja en mi colección"
            icon="pi pi-box"
            :loading="importandoALaColeccion"
            :disabled="importables === 0 || enVuelo"
            @click="meterLaCaja"
          />
          <Button
            label="La quiero: a mi lista de deseos"
            icon="pi pi-heart"
            severity="secondary"
            :loading="importandoADeseos"
            :disabled="importables === 0 || enVuelo"
            @click="quererLaCaja"
          />
          <small v-if="importables === 0" class="caja__imposible">
            Ninguna de las cartas de esta caja está todavía en tu catálogo.
          </small>
        </div>

        <p class="caja__asuncion">
          <i class="pi pi-info-circle"></i>
          Se sumarán <strong>{{ precons.ficha.cardsTotal }} ejemplares</strong> a tu colección
          <strong>como {{ POR_DEFECTO.language }} y en estado {{ POR_DEFECTO.condition }}</strong>
          —MTGJSON no publica ni el idioma ni el estado de las cartas de una caja—, y se creará
          el mazo «{{ cabecera.name }}» ya <strong>construido</strong>. Las fichas van en el mazo
          pero no en tu inventario, y pulsarlo dos veces <strong>suma cantidades</strong> (y deja
          otro mazo).
        </p>

        <!--
          Y lo que hace el segundo botón, que NO es obvio y hay que decirlo: el
          mazo nace **en construcción** y no construido. Un mazo construido
          consume colección, así que uno hecho con 100 cartas que solo deseas
          diría estar montado con cartas que no tienes; «en construcción» es el
          estado que calcula lo que falta, y te dirá que te faltan las 100.
        -->
        <p class="caja__asuncion">
          <i class="pi pi-heart"></i>
          Si la quieres pero todavía no la tienes, las
          <strong>{{ precons.ficha.cardsTotal }} cartas</strong> van a tu
          <strong>lista de deseos</strong> y <strong>ninguna</strong> a tu colección, y el mazo
          «{{ cabecera.name }}» se crea <strong>en construcción</strong>, pidiéndotelas todas.
          Cuando compres la caja de verdad, el otro botón <strong>suma</strong> sin duplicar nada:
          querer una carta y tenerla son dos líneas distintas.
        </p>

        <p v-if="precons.errorImport" class="caja__error">
          <i class="pi pi-exclamation-triangle"></i> {{ precons.errorImport }}
        </p>

        <!--
          El parte del clic se queda en pantalla en vez de irse solo como el
          aviso de «Añadir»: aquí se han escrito 100 cartas y dos tablas, y el
          usuario tiene que poder leer los números y saltar al mazo.
        -->
        <div v-if="precons.resultadoImport" class="caja__parte" role="status">
          <p class="caja__parte-texto">
            <i class="pi pi-check-circle"></i> {{ precons.resultadoImport.mensaje }}
          </p>
          <router-link
            v-if="precons.resultadoImport.isWishlist"
            :to="{ name: 'wishlist' }"
            class="caja__parte-enlace"
          >Ver mi lista de deseos <i class="pi pi-arrow-right"></i></router-link>
          <router-link
            v-if="precons.resultadoImport.deck?.id"
            :to="{ name: 'deck', params: { id: precons.resultadoImport.deck.id } }"
            class="caja__parte-enlace"
          >Ver el mazo <i class="pi pi-arrow-right"></i></router-link>
        </div>
      </section>

      <!--
        LAS CARTAS QUE EL CATÁLOGO NO CONOCE. 254 filas de `mtg_precon_card`
        tienen un `printing_uuid` que todavía no está en `mtg_printing` porque
        MTGJSON publica los mazos de una edición antes de que nadie reimporte
        `AllPrintings`. **No es un error y no se esconde**: la lista las enseña,
        el valor sale corto y aquí se dice por qué y cómo se arregla.
      -->
      <section v-if="precons.ficha.unknownPrintings > 0" class="aviso">
        <h2 class="aviso__titulo">
          <i class="pi pi-info-circle"></i>
          {{ precons.ficha.unknownPrintings }} línea(s) de este mazo no están en tu catálogo
        </h2>
        <p class="aviso__texto">
          Salen abajo con su cantidad pero sin nombre ni precio, así que
          <strong>el valor total se queda corto</strong>. Se arregla reimportando el
          catálogo (<code>catalog:import</code>): MTGJSON publica las cajas antes que
          las cartas de la edición.
        </p>
      </section>

      <!-- Solo fichas: los cinco precons con `cardCount = 0` y su explicación. -->
      <p v-if="precons.ficha.cardsTotal === 0" class="precon__nota">
        <i class="pi pi-info-circle"></i>
        Este producto es solo de fichas (tokens), y las fichas no cuentan ejemplares
        ni suman valor. No es un fallo de la ingesta.
      </p>

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
                El enlace a la ficha de la carta solo existe si el catálogo la
                conoce: un `router-link` a `/card/<uuid>` que el backend no tiene
                llevaría a una ficha en blanco.
              -->
              <router-link
                v-if="data.known"
                :to="{ name: 'card', params: { uuid: data.printingUuid } }"
                class="zona__mini"
              >
                <CardImage :scryfall-id="data.scryfallId" :nombre="data.name" tamano="small" />
              </router-link>
            </template>
          </Column>

          <Column field="name" header="Carta" sortable>
            <template #body="{ data }">
              <template v-if="data.known">
                <router-link
                  :to="{ name: 'card', params: { uuid: data.printingUuid } }"
                  class="zona__nombre"
                >{{ data.name }}</router-link>
                <small class="zona__sub">{{ data.setName }} · {{ data.collectorNumber }}</small>
              </template>

              <template v-else>
                <span class="zona__desconocida">Carta no importada todavía</span>
                <small class="zona__sub">{{ data.printingUuid }}</small>
              </template>
            </template>
          </Column>

          <Column field="finish" header="Acabado" style="width: 7rem">
            <template #body="{ data }">{{ etiquetaAcabado(data.finish) }}</template>
          </Column>

          <Column field="count" header="Copias" sortable style="width: 6rem" />

          <Column field="priceEur" header="Precio" sortable style="width: 7rem">
            <template #body="{ data }">
              <span :class="{ 'zona__sin-precio': data.priceEur === null }">
                {{ data.priceEur === null ? 'sin precio' : euros(data.priceEur) }}
              </span>
            </template>
          </Column>

          <Column field="lineValue" header="Precio × copias" sortable style="width: 8rem">
            <template #body="{ data }">{{ euros(data.lineValue) }}</template>
          </Column>
        </DataTable>
      </section>
    </main>
  </div>
</template>

<script setup>
import { computed, onBeforeUnmount, onMounted, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import Button from 'primevue/button'
import Column from 'primevue/column'
import DataTable from 'primevue/datatable'
import Skeleton from 'primevue/skeleton'
import Tag from 'primevue/tag'

import CardImage from '@/components/CardImage.vue'
import { POR_DEFECTO, ZONAS, etiquetaAcabado } from '@/constants/collection'
import { usePreconStore } from '@/stores/precons'
import { useWishlistStore } from '@/stores/wishlist'

/**
 * La ficha de un precon: su lista completa, con precios y valor.
 *
 * Tres cosas que este fichero **no** hace, igual que `DeckView.vue`:
 *
 *  - **No calcula precios.** `priceEur`, `lineValue` y `valueEur` los da el
 *    backend, que une el precio por `(printing_uuid, finish)`. Una carta sin
 *    cotización dice «sin precio» y sigue apareciendo.
 *  - **No suma los tokens.** El backend ya los deja fuera del recuento y del
 *    valor; por eso cinco precons de solo fichas salen con 0 cartas sin que sea
 *    un error, y la vista lo dice.
 *  - **No esconde las cartas huérfanas.** Las líneas cuyo `printing_uuid` no
 *    está todavía en `mtg_printing` llegan con `known: false`, se listan igual y
 *    se avisa arriba de que el valor sale corto.
 *
 * Lo que sí hace, desde M5, es **el botón de un clic**: una sola llamada a
 * `precon_add_to_collection` mete la caja entera en la colección y deja el mazo
 * montado. La vista no reparte cartas ni calcula nada de eso —lo hace el backend
 * en una transacción—; lo único suyo es **decir en voz alta lo que se asume**:
 * `English` y `NM`, porque MTGJSON no publica ni idioma ni condición.
 *
 * Y desde M7 el segundo botón, que es **la misma llamada con `is_wishlist`**: la
 * caja entera a la lista de deseos. Lo único que esta vista hace de más es
 * **rellenar el corazón** de esas cartas en el store de deseos: la escritura no
 * pasó por él —la hace el store de precons, porque la caja escribe además el
 * mazo—, así que sin esto el catálogo seguiría pintándolas en contorno hasta el
 * siguiente refresco, que es justo lo que M6 arregló.
 */

const router = useRouter()
const route = useRoute()
const precons = usePreconStore()
const wishlist = useWishlistStore()

const cabecera = computed(() => precons.ficha?.precon ?? {})

const enVuelo = computed(() => precons.importando === String(route.params.fileName ?? ''))

// Cada botón se pinta cargando solo si el clic fue suyo: los dos comparten el
// guardia del doble clic, pero girar los dos a la vez diría que se están
// escribiendo dos cosas cuando solo se escribe una.
const importandoALaColeccion = computed(() => enVuelo.value && precons.destinoEnVuelo === 'coleccion')
const importandoADeseos = computed(() => enVuelo.value && precons.destinoEnVuelo === 'deseos')

/**
 * Cuántas líneas se pueden importar de verdad: las que el catálogo conoce.
 *
 * Las huérfanas —`known: false`— no tienen fila en `mtg_printing`, y tanto la
 * colección como el mazo tienen clave foránea contra ella. El backend las salta
 * y las cuenta; aquí solo sirve para no ofrecer un botón que no puede escribir
 * nada.
 */
const importables = computed(
  () => (precons.ficha?.cards ?? []).filter((carta) => carta.known).length
)

function meterLaCaja() {
  precons.importar(String(route.params.fileName ?? ''))
}

/**
 * La caja entera a la lista de deseos.
 *
 * Los `printing_uuid` que se marcan son los del getter del store y no todos los
 * de la ficha: las fichas y las huérfanas no llegan a escribirse, y rellenarles
 * el corazón sería decir que están en una lista donde no están. Se leen **antes**
 * de la llamada porque salir de la ficha la vacía.
 */
async function quererLaCaja() {
  const uuids = precons.uuidsImportables

  if (await precons.desearCaja(String(route.params.fileName ?? ''))) {
    wishlist.marcarDeseados(uuids)
  }
}

/**
 * Las cartas agrupadas por zona, en el orden del ENUM y solo las que tienen
 * cartas. El backend ya las agrupa en `boards`; aquí solo se les pone etiqueta.
 */
const zonas = computed(() =>
  ZONAS.map((zona) => ({
    ...zona,
    cartas: (precons.ficha?.boards?.[zona.value] ?? []).map((carta) => ({
      ...carta,
      // `data-key` de la tabla: la PK real es de cuatro columnas, y un mismo
      // printing puede aparecer en normal y en foil dentro de la misma zona.
      clave: `${carta.printingUuid}|${carta.board}|${carta.finish}`
    }))
  })).filter((zona) => zona.cartas.length > 0)
)

function ejemplaresDe(zona) {
  return zona.cartas.reduce((suma, carta) => suma + carta.count, 0)
}

function euros(valor) {
  return `${Number(valor ?? 0).toFixed(2)} €`
}

function fecha(iso) {
  if (!iso) {
    return 'sin fecha'
  }

  const [anio, mes, dia] = iso.split('-')

  return `${dia}/${mes}/${anio}`
}

/**
 * Volver conserva los filtros: se vuelve a la entrada anterior del historial si
 * la hay, y a `/precons` limpio si se entró por un enlace directo.
 */
function volver() {
  if (window.history.state?.back) {
    router.back()
    return
  }

  router.push({ name: 'precons' })
}

watch(
  () => route.params.fileName,
  (fileName) => {
    if (fileName) {
      precons.cargarFicha(String(fileName))
    }
  }
)

onMounted(() => precons.cargarFicha(String(route.params.fileName ?? '')))

onBeforeUnmount(() => precons.limpiarFicha())
</script>

<style scoped>
.precon {
  padding-bottom: 3rem;
}

.precon__bar {
  position: sticky;
  top: 0;
  z-index: 10;
  display: flex;
  align-items: center;
  gap: 0.5rem;
  padding: 0.5rem 0.75rem;
  background: var(--p-content-background);
  border-bottom: 1px solid var(--p-content-border-color);
}

.precon__titulo {
  flex: 1;
  margin: 0;
  font-size: 1.05rem;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}

.precon__main {
  display: flex;
  flex-direction: column;
  gap: 1rem;
  padding: 1rem 0.75rem;
}

.precon__error {
  display: flex;
  align-items: center;
  gap: 0.4rem;
  padding: 0 0.75rem;
  color: var(--p-red-500);
  font-size: 0.85rem;
}

.precon__nota {
  display: flex;
  align-items: center;
  gap: 0.4rem;
  margin: 0;
  color: var(--p-text-muted-color);
  font-size: 0.85rem;
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

.cabecera__edicion small,
.cabecera__fecha {
  color: var(--p-text-muted-color);
}

.cabecera__fuente {
  font-size: 0.8rem;
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

.caja {
  display: flex;
  flex-direction: column;
  gap: 0.5rem;
  padding: 0.75rem;
  border: 1px solid var(--p-content-border-color);
  border-radius: 8px;
  background: var(--p-content-background);
}

.caja__accion {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 0.5rem;
}

.caja__imposible,
.caja__asuncion {
  margin: 0;
  font-size: 0.78rem;
  color: var(--p-text-muted-color);
}

.caja__asuncion {
  line-height: 1.45;
}

.caja__error {
  display: flex;
  align-items: center;
  gap: 0.4rem;
  margin: 0;
  color: var(--p-red-500);
  font-size: 0.85rem;
}

.caja__parte {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  justify-content: space-between;
  gap: 0.5rem;
  padding: 0.5rem 0.6rem;
  border-radius: 6px;
  background: color-mix(in srgb, var(--p-primary-color) 8%, transparent);
}

.caja__parte-texto {
  margin: 0;
  font-size: 0.82rem;
  line-height: 1.45;
}

.caja__parte-enlace {
  font-size: 0.82rem;
  white-space: nowrap;
}

.aviso {
  display: flex;
  flex-direction: column;
  gap: 0.4rem;
  padding: 0.75rem;
  border: 1px solid var(--p-surface-300, #d4d4d8);
  border-left: 3px solid var(--p-primary-color);
  border-radius: 8px;
  background: color-mix(in srgb, var(--p-primary-color) 6%, transparent);
}

.aviso__titulo {
  display: flex;
  align-items: center;
  gap: 0.4rem;
  margin: 0;
  font-size: 0.9rem;
}

.aviso__texto {
  margin: 0;
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

.zona__mini {
  display: block;
  width: 2.1rem;
}

.zona__nombre {
  display: block;
  color: inherit;
  font-size: 0.85rem;
}

.zona__desconocida {
  display: block;
  font-size: 0.85rem;
  font-style: italic;
  color: var(--p-text-muted-color);
}

.zona__sub {
  display: block;
  font-size: 0.7rem;
  color: var(--p-text-muted-color);
}

.zona__sin-precio {
  color: var(--p-text-muted-color);
}
</style>
