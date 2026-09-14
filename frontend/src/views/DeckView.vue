<template>
  <div class="mazo">
    <header class="mazo__bar">
      <Button
        icon="pi pi-arrow-left"
        text
        rounded
        aria-label="Volver a mis mazos"
        @click="router.push({ name: 'decks' })"
      />
      <h1 class="mazo__titulo">{{ mazos.mazo?.name || 'Mazo' }}</h1>
    </header>

    <p v-if="mazos.error" class="mazo__error">
      <i class="pi pi-exclamation-triangle"></i> {{ mazos.error }}
    </p>

    <div v-if="mazos.cargando" class="mazo__main">
      <Skeleton height="6rem" />
      <Skeleton height="18rem" />
    </div>

    <main v-else-if="mazos.mazo" class="mazo__main">
      <!--
        CABECERA: el estado y el formato se editan aquí mismo. La edición es
        PARCIAL —se manda solo lo que se toca—, así que cambiar el estado no
        puede borrar el nombre ni las notas.
      -->
      <section class="cabecera">
        <div class="cabecera__campos">
          <label class="cabecera__campo cabecera__campo--nombre">
            <span>Nombre</span>
            <InputText
              v-model="nombre"
              fluid
              @blur="guardarNombre"
              @keyup.enter="guardarNombre"
            />
          </label>

          <label class="cabecera__campo">
            <span>Estado</span>
            <Select
              :model-value="mazos.mazo.status"
              :options="ESTADOS_MAZO"
              option-label="label"
              option-value="value"
              fluid
              @update:model-value="mazos.actualizar(mazos.mazo.id, { status: $event })"
            />
          </label>

          <label class="cabecera__campo cabecera__campo--formato">
            <span>Formato</span>
            <!--
              Se elige de la lista, y se sigue pudiendo escribir (`editable`).
              Las opciones NO son una lista escrita aquí: vienen en `deck_get`
              desde `mtg_format`, que destila la ingesta, así que no pueden
              desincronizarse del dato con el que se valida la legalidad. Y
              `mtg_deck.format` sigue siendo texto libre acotado: un formato
              casero es legítimo, y si no existe el aviso de abajo lo dice.
            -->
            <Select
              v-model="formato"
              :options="mazos.legality.formatosDisponibles"
              editable
              filter
              placeholder="commander, modern, standard…"
              fluid
              @change="formatoElegido"
              @blur="guardarFormato"
              @keyup.enter="guardarFormato"
            />
          </label>
        </div>

        <dl class="cabecera__cifras">
          <div>
            <dt>Cartas</dt>
            <!-- Sin tokens: el backend ya los deja fuera del recuento. -->
            <dd>{{ mazos.mazo.cards }}</dd>
          </div>
          <div>
            <dt>Valor</dt>
            <dd>{{ euros(mazos.valueEur) }}</dd>
          </div>
          <div>
            <dt>Te faltan</dt>
            <dd :class="{ 'cabecera__faltan': mazos.missing > 0 }">{{ mazos.missing }}</dd>
          </div>
          <div v-if="mazos.missing > 0">
            <dt>Completarlo</dt>
            <dd>{{ euros(mazos.missingValueEur) }}</dd>
          </div>
        </dl>
      </section>

      <!--
        EL ENLACE PÚBLICO DEL MAZO.
        Compartir es un acto explícito sobre ESTE mazo y no pregunta por la
        privacidad del perfil: quien abra el enlace ve el mazo entero, tenga
        cuenta o no. Lo que NO viaja es el cruce con tu colección —lo que te
        falta, los conflictos, qué copias tienes libres—, que lo deja fuera el
        backend y no esta vista.
      -->
      <section class="compartir">
        <h2 class="compartir__titulo">
          <i class="pi pi-share-alt"></i> Compartir este mazo por enlace
        </h2>

        <p class="compartir__nota">
          Quien tenga el enlace ve el mazo <strong>sin necesitar cuenta</strong>. No enseña nada de
          tu colección: ni lo que te falta, ni lo que tienes libre. El enlace acaba en el historial
          del navegador y en cualquier sitio donde lo pegues, así que la forma de cortarlo es
          dejar de compartirlo.
        </p>

        <!-- Con enlace a la vista: la URL, «copiar» y «dejar de compartir». -->
        <div v-if="mazos.enlaceCompartido" class="compartir__enlace">
          <InputText
            ref="campoEnlace"
            class="compartir__url"
            :model-value="mazos.enlaceCompartido.url"
            readonly
            aria-label="Enlace público de este mazo"
            @focus="seleccionarTodo"
          />

          <Button
            class="compartir__copiar"
            :label="copiado ? 'Copiado' : 'Copiar'"
            :icon="copiado ? 'pi pi-check' : 'pi pi-copy'"
            size="small"
            severity="secondary"
            @click="copiarEnlace"
          />

          <Button
            class="compartir__revocar"
            label="Dejar de compartir"
            icon="pi pi-link"
            size="small"
            severity="danger"
            outlined
            :loading="mazos.compartiendo"
            :disabled="mazos.compartiendo"
            @click="mazos.dejarDeCompartir(mazos.mazo.id)"
          />
        </div>

        <!-- Sin enlace a la vista. Ojo: eso NO significa «sin compartir». -->
        <div v-else class="compartir__acciones">
          <Button
            class="compartir__boton"
            label="Compartir por enlace"
            icon="pi pi-share-alt"
            size="small"
            :loading="mazos.compartiendo"
            :disabled="mazos.compartiendo"
            @click="mazos.compartir(mazos.mazo.id)"
          />

          <Button
            class="compartir__revocar"
            label="Dejar de compartir"
            icon="pi pi-link"
            size="small"
            severity="danger"
            text
            :disabled="mazos.compartiendo"
            @click="mazos.dejarDeCompartir(mazos.mazo.id)"
          />
        </div>

        <!--
          La honestidad que cuesta una línea: al recargar, la app NO sabe si el
          mazo seguía compartido —`deck_get` no devuelve `share_token`—, así que
          «compartir» genera un enlace NUEVO y mata el anterior. Por eso «dejar
          de compartir» está siempre, también aquí: es idempotente y es lo único
          que corta un enlace que ya no se tiene a mano.
        -->
        <p class="compartir__olvido">
          <template v-if="mazos.enlaceCompartido">
            Este enlace solo se enseña mientras no salgas de la pantalla: cópialo antes de irte.
            Volver a compartir crea otro y <strong>mata este</strong>.
          </template>
          <template v-else>
            Al recargar, TCGDesk no recuerda si este mazo estaba compartido. «Compartir» genera un
            enlace <strong>nuevo</strong> y deja muerto cualquier anterior; «dejar de compartir»
            mata el que hubiera, lo veas o no.
          </template>
        </p>

        <p v-if="errorAlCopiar" class="compartir__error">
          <i class="pi pi-exclamation-triangle"></i> {{ errorAlCopiar }}
        </p>
      </section>

      <!--
        LO QUE FALTA, Y LA PUERTA A LA LISTA DE DESEOS.
        Va pegado a la cabecera porque es la continuación de la cifra «te
        faltan»: aquí es donde se decide comprar. El botón escribe en la LISTA
        DE DESEOS y no toca el mazo — el análisis informa, no actúa.
      -->
      <section v-if="lineasQueFaltan.length > 0" class="faltan">
        <h2 class="faltan__titulo">
          <i class="pi pi-shopping-cart"></i>
          Te faltan {{ mazos.missing }} ejemplar(es) en {{ lineasQueFaltan.length }} línea(s)
        </h2>

        <p class="faltan__nota">
          Se guardan en tu lista de deseos con <strong>la versión exacta</strong> que pide el mazo
          —acabado, idioma y estado—, que es lo único que hace que al comprarlas el mazo deje de
          pedirlas. El mazo no cambia: querer una carta no es tenerla.
        </p>

        <Button
          class="faltan__boton"
          label="Mandar lo que falta a deseos"
          icon="pi pi-heart"
          size="small"
          :loading="deseos.anadiendoLote"
          :disabled="deseos.anadiendoLote"
          @click="mandarAFaltantesADeseos"
        />
      </section>

      <!--
        EL AVISO DE SOBREASIGNACIÓN. Informa, no cambia nada solo: el backend no
        desmonta mazos por su cuenta. Solo se enseñan los conflictos en los que
        este mazo está metido, pero se nombran TODOS los implicados: un conflicto
        es siempre entre varios y hay que poder ir al otro.
      -->
      <section v-if="conflictosDeEsteMazo.length > 0" class="conflicto">
        <h2 class="conflicto__titulo">
          <i class="pi pi-exclamation-triangle"></i>
          Este mazo se pelea con otros por las mismas cartas
        </h2>

        <ul class="conflicto__lista">
          <li v-for="linea in conflictosDeEsteMazo" :key="claveDe(linea)">
            <strong>{{ linea.name }}</strong>
            ({{ etiquetaAcabado(linea.finish) }} · {{ linea.language }} ·
            {{ etiquetaCondicion(linea.condition) }}):
            piden {{ linea.claimed }} y tienes {{ linea.inCollection }}.
            <span class="conflicto__mazos">
              <template v-for="(implicado, i) in linea.decks" :key="implicado.id">
                <span v-if="i > 0"> · </span>
                <router-link
                  v-if="implicado.id !== mazos.mazo.id"
                  :to="{ name: 'deck', params: { id: implicado.id } }"
                >{{ implicado.name }}</router-link>
                <span v-else>este mazo</span>
                <small> ({{ implicado.claimed }})</small>
              </template>
            </span>
          </li>
        </ul>

        <Button
          v-if="mazos.mazo.status === 'built'"
          label="Desmontar este mazo"
          icon="pi pi-inbox"
          size="small"
          severity="warn"
          @click="mazos.desmontar(mazos.mazo.id)"
        />
      </section>

      <!--
        LEGALIDAD, QUE ES UN AVISO Y NUNCA UN BLOQUEO. No hay botón que pulsar,
        no impide guardar nada y no cambia el mazo: **un mazo ilegal es un mazo
        que existe**. Las marcas por carta van en la tabla; esto es el resumen.
      -->
      <section v-if="avisoDeLegalidad" class="legalidad">
        <h2 class="legalidad__titulo">
          <i class="pi pi-info-circle"></i>
          {{ avisoDeLegalidad.titulo }}
        </h2>

        <ul class="legalidad__lista">
          <li v-for="linea in avisoDeLegalidad.lineas" :key="linea">{{ linea }}</li>
        </ul>

        <p class="legalidad__nota">Es un aviso: el mazo se guarda igual.</p>
      </section>

      <!-- EL BUSCADOR: un clic sobre un resultado es una carta en el mazo. -->
      <DeckCardSearch :zona-por-defecto="zonaPorDefecto" />

      <p v-if="mazos.cartas.length === 0" class="mazo__vacio">
        <i class="pi pi-inbox"></i> El mazo está vacío. Busca una carta arriba y púlsala.
      </p>

      <!-- LAS CARTAS, agrupadas por zona y con la tabla ordenable. -->
      <section v-for="zona in mazos.zonas" :key="zona.value" class="zona">
        <h2 class="zona__titulo">
          {{ zona.label }}
          <small>{{ copiasDe(zona) }} carta(s)</small>
          <small v-if="!zona.cuenta" class="zona__nota">
            — los tokens no cuentan para el tamaño ni para el valor
          </small>
        </h2>

        <DataTable
          :value="zona.cartas"
          data-key="id"
          size="small"
          striped-rows
          sort-field="name"
          :sort-order="1"
        >
          <Column header="" style="width: 3rem">
            <template #body="{ data }">
              <router-link
                :to="{ name: 'card', params: { uuid: data.printingUuid } }"
                class="zona__mini"
              >
                <CardImage :scryfall-id="data.scryfallId" :nombre="data.name" tamano="small" />
              </router-link>
            </template>
          </Column>

          <Column field="name" header="Carta" sortable>
            <template #body="{ data }">
              <router-link
                :to="{ name: 'card', params: { uuid: data.printingUuid } }"
                class="zona__nombre"
              >{{ data.name }}</router-link>
              <!--
                La marca de legalidad va pegada al nombre porque es de la CARTA,
                no de la edición ni de la versión: cuatro líneas del mismo Sol
                Ring llevan la misma marca. Solo sale lo que hay que avisar; lo
                legal no se marca.
              -->
              <Tag
                v-if="mazos.legalidadDe(data)"
                class="zona__legalidad"
                :value="etiquetaLegalidad(mazos.legalidadDe(data))"
                :severity="severidadLegalidad(mazos.legalidadDe(data))"
              />
              <small class="zona__sub">{{ data.setName }} · {{ data.collectorNumber }}</small>
            </template>
          </Column>

          <!--
            CAMBIAR DE VERSIÓN, DE UN CLIC. El desplegable no es un formulario de
            cuatro campos: se puebla con `deck_card_variants`, o sea con las
            versiones que TIENES de esa carta, con cuántas hay y cuántas quedan
            libres. Se pide al abrirlo y no antes: cuarenta filas serían cuarenta
            peticiones de algo que casi nadie mira.
          -->
          <Column header="Versión" style="min-width: 15rem">
            <template #body="{ data }">
              <Select
                :model-value="versionActual(data)"
                :options="opcionesDe(data)"
                option-label="label"
                option-value="value"
                :loading="mazos.cargandoVariantes.includes(data.id)"
                :disabled="mazos.estaGuardando(data.id)"
                size="small"
                fluid
                :aria-label="`Versión de ${data.name}`"
                @before-show="mazos.cargarVariantes(data)"
                @update:model-value="cambiar(data, $event)"
              >
                <template #option="{ option }">
                  <div class="version">
                    <span>{{ option.label }}</span>
                    <small v-if="option.tienes !== null">
                      tienes {{ option.tienes }} · {{ option.libres }} libres
                    </small>
                    <small v-else class="version__ajena">no la tienes</small>
                  </div>
                </template>
              </Select>
            </template>
          </Column>

          <Column header="Copias" style="width: 9rem">
            <template #body="{ data }">
              <DeckCountControl :carta="data" />
            </template>
          </Column>

          <Column header="Tienes" style="width: 8rem">
            <template #body="{ data }">
              <span v-if="!zona.cuenta" class="zona__tenue">—</span>
              <span v-else-if="faltanDe(data) > 0" class="zona__faltan">
                faltan {{ faltanDe(data) }}
              </span>
              <span v-else class="zona__ok"><i class="pi pi-check"></i> completo</span>
            </template>
          </Column>

          <!-- Precio unitario y precio × cantidad, los dos ordenables. -->
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

          <Column header="" style="width: 3rem">
            <template #body="{ data }">
              <Button
                icon="pi pi-trash"
                text
                rounded
                size="small"
                severity="danger"
                :disabled="mazos.estaGuardando(data.id)"
                :aria-label="`Quitar ${data.name} del mazo`"
                @click="mazos.quitar(data)"
              />
            </template>
          </Column>
        </DataTable>
      </section>
    </main>

    <DeckAviso />
    <!--
      El aviso de los deseos es OTRO componente y lee OTRO store: mandar cartas
      a la lista de deseos no es un cambio del mazo, y colarlo por `DeckAviso`
      haría que el editor hablara en nombre de una lista que no es suya. Cada
      vista monta el suyo, que es lo que `CollectionAviso` declara en su cabecera.
    -->
    <CollectionAviso deseos />
  </div>
</template>

<script setup>
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import Button from 'primevue/button'
import Column from 'primevue/column'
import DataTable from 'primevue/datatable'
import InputText from 'primevue/inputtext'
import Select from 'primevue/select'
import Skeleton from 'primevue/skeleton'
import Tag from 'primevue/tag'

import CardImage from '@/components/CardImage.vue'
import CollectionAviso from '@/components/CollectionAviso.vue'
import DeckAviso from '@/components/DeckAviso.vue'
import DeckCardSearch from '@/components/DeckCardSearch.vue'
import DeckCountControl from '@/components/DeckCountControl.vue'
import {
  ESTADOS_MAZO,
  etiquetaAcabado,
  etiquetaCondicion,
  etiquetaLegalidad,
  severidadLegalidad
} from '@/constants/collection'
import { useDeckStore } from '@/stores/deck'
import { useWishlistStore } from '@/stores/wishlist'

/**
 * El editor de un mazo.
 *
 * Tres cosas que este fichero **no** hace, y son deliberadas:
 *
 *  - **No calcula precios.** `priceEur`, `lineValue` y `valueEur` los da el
 *    backend, que une el precio por `(printing_uuid, finish)`. Una carta sin
 *    cotización dice «sin precio» y **sigue apareciendo**: no desaparece de la
 *    tabla ni cuenta como 0 € en la columna.
 *  - **No suma los tokens.** El backend ya los deja fuera del recuento y del
 *    valor; aquí solo se evita pedirles cuentas de disponibilidad.
 *  - **No cambia el estado de nadie por su cuenta.** El aviso de
 *    sobreasignación ofrece «desmontar», que es un `deck_update` normal, y lo
 *    pulsa el usuario.
 *
 * Y **no guarda el enlace público**: `deck_get` no devuelve `share_token`, así
 * que al recargar nadie sabe si el mazo seguía compartido. La interfaz lo dice
 * en vez de fingir que se acuerda, y por eso «dejar de compartir» se ofrece
 * también cuando no hay ningún enlace en pantalla — es idempotente en el backend
 * y es lo único que mata un enlace que ya se te fue de las manos.
 *
 * Y desde el plan de la lista de deseos, **«mandar lo que falta a deseos»**, que
 * es lo mismo con otro nombre: escribe en la LISTA DE DESEOS del usuario y no
 * toca ni el mazo ni `mtg_deck`. Querer una carta no es tenerla —el cruce del
 * consumo lleva `is_wishlist = 0` en cinco sitios—, así que el mazo sigue
 * diciendo exactamente lo que decía hasta que el usuario cumpla el deseo.
 */

const router = useRouter()
const route = useRoute()
const mazos = useDeckStore()
const deseos = useWishlistStore()

const nombre = ref('')
const formato = ref('')

/** «Copiado» en el botón, hasta que el enlace cambie o se falle al copiar. */
const copiado = ref(false)
/** Lo que se le dice al usuario cuando el navegador no deja copiar solo. */
const errorAlCopiar = ref(null)
const campoEnlace = ref(null)

/**
 * La zona a la que va lo que se añada con un clic: la del mazo si ya tiene
 * comandante o sideboard no cambia nada — el principal es siempre lo esperado.
 */
const zonaPorDefecto = 'main'

/**
 * Las líneas del análisis a las que les falta algo.
 *
 * Sale de `availability`, que es el `faltantes()` del backend
 * (`MySqlDeckRepository.php:643-671`): ya viene **sin tokens** —`board <>
 * 'tokens'` está en la consulta— y con `missing` recortado a 0, así que aquí no
 * hay que volver a filtrar ninguna de las dos cosas. Lo que sí se filtra es lo
 * que está completo: `availability` trae **todas** las líneas del mazo.
 */
const lineasQueFaltan = computed(() => mazos.availability.filter((l) => l.missing > 0))

const conflictosDeEsteMazo = computed(() =>
  mazos.conflicts.filter((linea) =>
    (linea.decks || []).some((d) => d.id === mazos.mazo?.id)
  )
)

/**
 * El resumen de legalidad, o null si no hay nada que decir.
 *
 * Tres casos, y el orden importa:
 *
 *  - **Sin formato no se dice nada.** `mtg_deck.format` es NULLable y un mazo
 *    sin formato es un mazo perfectamente válido.
 *  - **Un formato que `mtg_legality` no conoce se dice y no se marca.** El
 *    formato lo teclea el usuario; con «edh» en vez de «commander» no hay ni una
 *    fila y las cien cartas saldrían «no permitida»: una alarma falsa y entera.
 *  - **Y si todo está en orden, tampoco se dice nada**: un panel que repite «el
 *    mazo es legal» en cada carga es ruido, no información.
 *
 * `restricted` se nombra —sale del mismo cruce y callarlo diría «legal» de una
 * carta que no lo es sin más—, pero **la regla de una copia no se implementa**:
 * eso sería validar, y esto avisa.
 */
const avisoDeLegalidad = computed(() => {
  const legalidad = mazos.legality

  if (!legalidad.format) {
    return null
  }

  if (!legalidad.known) {
    return {
      titulo: `«${legalidad.format}» no es un formato conocido`,
      lineas: [
        'No hay legalidades para ese nombre, así que no se marca ninguna carta. ' +
          'Los formatos van como los escribe MTGJSON, en minúsculas: commander, modern, legacy…'
      ]
    }
  }

  const lineas = []

  if (legalidad.banned > 0) {
    lineas.push(`${legalidad.banned} carta(s) prohibida(s) en ${legalidad.format}.`)
  }

  if (legalidad.restricted > 0) {
    lineas.push(
      `${legalidad.restricted} carta(s) restringida(s) en ${legalidad.format}: ` +
        'el formato permite una sola copia de cada una.'
    )
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

function euros(valor) {
  return `${Number(valor ?? 0).toFixed(2)} €`
}

function claveDe(linea) {
  return [linea.printingUuid, linea.finish, linea.language, linea.condition].join('|')
}

/** Copias de una zona, que no es lo mismo que líneas: una línea lleva N. */
function copiasDe(zona) {
  return zona.cartas.reduce((suma, c) => suma + c.count, 0)
}

function faltanDe(carta) {
  return mazos.disponibilidadDe(carta)?.missing ?? 0
}

function etiquetaVersion(finish, language, condition) {
  return `${etiquetaAcabado(finish)} · ${language} · ${etiquetaCondicion(condition)}`
}

function versionActual(carta) {
  return [carta.finish, carta.language, carta.condition].join('|')
}

/**
 * Las opciones del desplegable: **tus** versiones de esa carta.
 *
 * La que el mazo pide ahora va siempre la primera, incluso si no la tienes —el
 * mazo puede declarar una carta que aún no está en las cajas—, o el desplegable
 * abriría en blanco y parecería que la línea no tiene versión.
 */
function opcionesDe(carta) {
  const actual = versionActual(carta)

  const suyas = (mazos.variantes[carta.id] || []).map((v) => ({
    value: [v.finish, v.language, v.condition_grade].join('|'),
    label: etiquetaVersion(v.finish, v.language, v.condition_grade),
    finish: v.finish,
    language: v.language,
    condition_grade: v.condition_grade,
    tienes: v.quantity,
    libres: v.free
  }))

  if (suyas.some((o) => o.value === actual)) {
    return suyas
  }

  return [
    {
      value: actual,
      label: etiquetaVersion(carta.finish, carta.language, carta.condition),
      finish: carta.finish,
      language: carta.language,
      condition_grade: carta.condition,
      tienes: null,
      libres: 0
    },
    ...suyas
  ]
}

/** Elegir otra versión dispara `deck_card_change`; el store funde si toca. */
function cambiar(carta, valor) {
  const elegida = opcionesDe(carta).find((o) => o.value === valor)

  if (!elegida) {
    return
  }

  mazos.cambiarVersion(carta, {
    finish: elegida.finish,
    language: elegida.language,
    condition_grade: elegida.condition_grade
  })
}

/**
 * Lo que falta, a la lista de deseos, **con las cuatro dimensiones exactas**.
 *
 * Las cuatro salen del análisis y no de valores por defecto, y ese es el hito
 * entero: el consumo del mazo cruza la colección por `printing_uuid`, `finish`,
 * `language` y `condition_grade` con `is_wishlist = 0`
 * (`MySqlDeckRepository.php:187-200`), así que un deseo nacido en
 * `normal`/`English`/`NM` no cerraría el hueco de un `foil` en japonés y el mazo
 * te seguiría pidiendo la carta después de haberla comprado.
 *
 * **Después no se refresca el mazo**, y no es un olvido: los deseos no cuentan
 * como colección, así que `missing` vale exactamente lo mismo antes y después.
 * Pedir `deck_get` otra vez sería una petición para pintar los mismos números.
 */
async function mandarAFaltantesADeseos() {
  await deseos.desearLote(
    lineasQueFaltan.value.map((linea) => ({
      printingUuid: linea.printingUuid,
      finish: linea.finish,
      language: linea.language,
      condition: linea.condition,
      quantity: linea.missing
    }))
  )
}

/**
 * El enlace al portapapeles, con el plan B escrito.
 *
 * `navigator.clipboard` **no existe siempre**: es API de contexto seguro, así
 * que en `http://` que no sea localhost el navegador ni la define. Y cuando
 * existe puede rechazar la escritura (permiso denegado, la pestaña sin foco).
 * Los dos casos acaban igual: se dice que no se pudo y el enlace **sigue en
 * pantalla, seleccionable**, que es el plan B de verdad — un botón que no hace
 * nada y no lo cuenta es peor que no tener botón.
 */
async function copiarEnlace() {
  const url = mazos.enlaceCompartido?.url

  if (!url) {
    return
  }

  copiado.value = false
  errorAlCopiar.value = null

  if (!navigator.clipboard?.writeText) {
    errorAlCopiar.value =
      'Tu navegador no deja copiar desde aquí. Selecciona el enlace y cópialo a mano.'
    return
  }

  try {
    await navigator.clipboard.writeText(url)
    copiado.value = true
  } catch {
    errorAlCopiar.value =
      'No se pudo copiar automáticamente. Selecciona el enlace y cópialo a mano.'
  }
}

/** Un clic en el campo selecciona la URL entera: copiar a mano sin arrastrar. */
function seleccionarTodo(evento) {
  evento.target?.select?.()
}

/** Otro enlace es otro «copiar»: el «Copiado» del anterior ya no dice la verdad. */
watch(
  () => mazos.enlaceCompartido?.token,
  () => {
    copiado.value = false
    errorAlCopiar.value = null
  }
)

function guardarNombre() {
  const valor = nombre.value.trim()

  if (!valor || valor === mazos.mazo?.name) {
    nombre.value = mazos.mazo?.name || ''
    return
  }

  mazos.actualizar(mazos.mazo.id, { name: valor })
}

/**
 * Elegir de la lista guarda en el acto; teclear, no.
 *
 * El `Select` editable emite `change` **también en cada pulsación** —su
 * `onEditableInput` llama al mismo `updateModel()` que el clic en una opción—,
 * así que guardar aquí sin mirar quién lo disparó mandaría un `deck_update` por
 * letra. El `originalEvent` es lo que los separa: un `input` es el usuario
 * tecleando, y eso ya lo guardan el `blur` y el Enter.
 */
function formatoElegido(evento) {
  if (evento.originalEvent?.type === 'input') {
    return
  }

  guardarFormato()
}

function guardarFormato() {
  // El `Select` con `editable` devuelve el texto TAL CUAL, sin normalizar, y los
  // formatos de `mtg_legality` van en minúsculas: sin esto, «Commander» escrito
  // a mano volvería a salir con `known: false`.
  const valor = (formato.value || '').trim().toLowerCase()

  if (valor === (mazos.mazo?.format || '')) {
    return
  }

  formato.value = valor
  mazos.actualizar(mazos.mazo.id, { format: valor || null })
}

/** La cabecera sigue al mazo que hay, no al que había cuando se montó la vista. */
watch(
  () => mazos.mazo,
  (mazo) => {
    nombre.value = mazo?.name || ''
    formato.value = mazo?.format || ''
  },
  { immediate: true }
)

watch(
  () => route.params.id,
  (id) => {
    if (id) {
      mazos.cargar(Number(id))
    }
  }
)

onMounted(() => {
  mazos.cargar(Number(route.params.id))
  // Lo que hay en las cajas, para que el clic del buscador pueda resolver
  // acabado, idioma y estado sin preguntar nada.
  mazos.cargarIndiceColeccion()
})

onBeforeUnmount(() => mazos.limpiarFicha())
</script>

<style scoped>
.mazo {
  padding-bottom: 3rem;
}

.mazo__bar {
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

.mazo__titulo {
  flex: 1;
  margin: 0;
  font-size: 1.05rem;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}

.mazo__main {
  display: flex;
  flex-direction: column;
  gap: 1rem;
  padding: 1rem 0.75rem;
}

.mazo__error {
  display: flex;
  align-items: center;
  gap: 0.4rem;
  padding: 0 0.75rem;
  color: var(--p-red-500);
  font-size: 0.85rem;
}

.mazo__vacio {
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

.cabecera__campos {
  display: flex;
  flex-wrap: wrap;
  gap: 0.75rem;
  flex: 1 1 22rem;
}

.cabecera__campo {
  display: flex;
  flex-direction: column;
  gap: 0.2rem;
  flex: 1 1 9rem;
  font-size: 0.75rem;
}

.cabecera__campo--nombre {
  flex: 2 1 14rem;
}

.cabecera__campo > span {
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

.cabecera__faltan {
  color: var(--p-orange-500, #f97316);
}

/*
 * Compartir se pinta NEUTRO, como «lo que falta»: publicar un mazo no es un
 * problema ni una alarma, es una decisión. Lo que sí destaca es la línea que
 * avisa de que el enlace no se recuerda al recargar.
 */
.compartir {
  display: flex;
  flex-direction: column;
  align-items: flex-start;
  gap: 0.5rem;
  padding: 0.75rem;
  border: 1px solid var(--p-content-border-color);
  border-radius: 8px;
  background: var(--p-content-background);
}

.compartir__titulo {
  display: flex;
  align-items: center;
  gap: 0.4rem;
  margin: 0;
  font-size: 0.9rem;
}

.compartir__nota,
.compartir__olvido {
  margin: 0;
  font-size: 0.8rem;
  color: var(--p-text-muted-color);
}

.compartir__enlace,
.compartir__acciones {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 0.5rem;
  width: 100%;
}

.compartir__url {
  flex: 1 1 22rem;
  font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
  font-size: 0.78rem;
}

.compartir__error {
  display: flex;
  align-items: center;
  gap: 0.4rem;
  margin: 0;
  color: var(--p-red-500);
  font-size: 0.8rem;
}

/*
 * Lo que falta se pinta NEUTRO, no en rojo: que a un mazo en construcción le
 * falten cartas no es un error, es el estado normal. El aviso de
 * sobreasignación de abajo sí es naranja, porque aquello sí es un problema.
 */
.faltan {
  display: flex;
  flex-direction: column;
  align-items: flex-start;
  gap: 0.5rem;
  padding: 0.75rem;
  border: 1px solid var(--p-content-border-color);
  border-radius: 8px;
  background: var(--p-content-background);
}

.faltan__titulo {
  display: flex;
  align-items: center;
  gap: 0.4rem;
  margin: 0;
  font-size: 0.9rem;
}

.faltan__nota {
  margin: 0;
  font-size: 0.8rem;
  color: var(--p-text-muted-color);
}

.conflicto {
  display: flex;
  flex-direction: column;
  align-items: flex-start;
  gap: 0.5rem;
  padding: 0.75rem;
  border: 1px solid var(--p-orange-400, #fb923c);
  border-radius: 8px;
  background: color-mix(in srgb, var(--p-orange-500, #f97316) 8%, transparent);
}

.conflicto__titulo {
  display: flex;
  align-items: center;
  gap: 0.4rem;
  margin: 0;
  font-size: 0.9rem;
}

.conflicto__lista {
  margin: 0;
  padding-left: 1.1rem;
  font-size: 0.8rem;
}

.conflicto__mazos small {
  color: var(--p-text-muted-color);
}

/*
 * El aviso de legalidad es DISCRETO a propósito: informa y no interrumpe. No
 * lleva botón porque no hay nada que decidir — el mazo se guarda igual.
 */
.legalidad {
  display: flex;
  flex-direction: column;
  gap: 0.4rem;
  padding: 0.75rem;
  border: 1px solid var(--p-surface-300, #d4d4d8);
  border-left: 3px solid var(--p-primary-color);
  border-radius: 8px;
  background: color-mix(in srgb, var(--p-primary-color) 6%, transparent);
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
}

.legalidad__nota {
  margin: 0;
  font-size: 0.75rem;
  color: var(--p-text-muted-color);
}

.zona__legalidad {
  margin-left: 0.4rem;
  font-size: 0.7rem;
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

.zona__sub {
  display: block;
  font-size: 0.7rem;
  color: var(--p-text-muted-color);
}

.zona__faltan {
  color: var(--p-orange-500, #f97316);
  font-size: 0.8rem;
}

.zona__ok {
  color: var(--p-green-500, #22c55e);
  font-size: 0.8rem;
}

.zona__tenue,
.zona__sin-precio {
  color: var(--p-text-muted-color);
}

.version {
  display: flex;
  flex-direction: column;
}

.version small {
  font-size: 0.7rem;
  color: var(--p-text-muted-color);
}

.version__ajena {
  font-style: italic;
}
</style>
