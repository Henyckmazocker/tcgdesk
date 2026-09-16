<template>
  <div class="importar">
    <header class="importar__bar">
      <Button icon="pi pi-arrow-left" text rounded aria-label="Volver" @click="volverAtras" />
      <h1 class="importar__titulo">Importar colección</h1>
      <span v-if="preview" class="importar__formato">{{ etiquetaFormato(preview.format) }}</span>
    </header>

    <!-- =====================================================================
         PASO 1 — ORIGEN: un fichero exportado o una lista pegada
         ================================================================== -->
    <section v-if="paso === 'origen'" class="origen">
      <!--
        El aviso NO es decorativo. Lo pide el plan explícitamente: quien reimporta
        su ManaBox esperando "sincronizar" va a duplicar cantidades, porque el
        UNIQUE KEY suma. Se anuncia antes de tocar nada, no después.
      -->
      <div class="aviso">
        <i class="pi pi-info-circle"></i>
        <p>
          Esta importación <strong>añade</strong> a lo que ya tienes. Si importas dos veces el mismo
          fichero, las cantidades se <strong>suman</strong>: no es una sincronización.
        </p>
      </div>

      <div class="origen__opciones">
        <div class="caja">
          <h2 class="caja__titulo"><i class="pi pi-upload"></i> Sube tu exportación</h2>
          <p class="caja__nota">
            CSV de ManaBox, Moxfield o Archidekt, o un .txt con una lista. Se detecta solo.
          </p>
          <input
            ref="inputFichero"
            class="caja__fichero"
            type="file"
            accept=".csv,.txt,text/csv,text/plain"
            @change="alElegirFichero"
          >
          <p v-if="nombreFichero" class="caja__elegido">
            <i class="pi pi-file"></i> {{ nombreFichero }} · {{ enMegas(tamanoFichero) }}
          </p>
        </div>

        <div class="origen__o"><span>o</span></div>

        <div class="caja">
          <h2 class="caja__titulo"><i class="pi pi-align-left"></i> Pega una lista</h2>
          <p class="caja__nota">Copiada de una web, de MTG Arena o escrita a mano.</p>
          <Textarea
            v-model="pegado"
            class="caja__texto"
            rows="8"
            placeholder="4 Lightning Bolt (M10) 146&#10;4x Counterspell&#10;2 Sol Ring"
            @input="nombreFichero = ''"
          />
        </div>
      </div>

      <!--
        El selector solo aparece cuando el backend ya ha dicho qué formatos
        conoce, que es al fallar la detección o tras una previsualización. Así no
        se replica aquí una lista de formatos que se desincronizaría sola.
      -->
      <div v-if="formatos.length" class="origen__formato">
        <label for="formato">Formato</label>
        <Select
          id="formato"
          v-model="formatoForzado"
          :options="opcionesDeFormato"
          option-label="label"
          option-value="value"
        />
      </div>

      <Message v-if="error" severity="error" :closable="false" class="origen__error">{{ error }}</Message>

      <div class="origen__acciones">
        <Button
          label="Previsualizar"
          icon="pi pi-eye"
          :disabled="!hayContenido || cargando"
          @click="previsualizar"
        />
      </div>

      <BarraDeProgreso v-if="cargando" :fase="fase" :valor="valorProgreso" :lineas="lineasEstimadas" />
    </section>

    <!-- =====================================================================
         PASO 2 — PREVISUALIZACIÓN: el alto obligatorio del pipeline
         ================================================================== -->
    <section v-else-if="paso === 'preview'" class="preview">
      <div class="resumen">
        <span class="chip chip--total">{{ preview.total }} líneas leídas</span>
        <span class="chip chip--ok">{{ preview.summary.resolvedCount }} reconocidas</span>
        <span v-if="preview.summary.conflictCount" class="chip chip--conflicto">
          {{ preview.summary.conflictCount }} sin resolver
        </span>
        <span v-if="preview.summary.assumedCount" class="chip chip--asumida">
          {{ preview.summary.assumedCount }} con edición asumida
        </span>
        <span class="chip">{{ preview.summary.totalQuantity }} ejemplares</span>
      </div>

      <!-- ---------------------------------------------------------------
           LOS CONFLICTOS VAN ARRIBA Y DESTACADOS. Es lo que pide el hito:
           son lo único que necesita al usuario, y enterrarlos bajo 3.000
           filas correctas es lo mismo que no enseñarlos.
           ------------------------------------------------------------ -->
      <div v-if="preview.conflicts.length" class="conflictos">
        <h2 class="conflictos__titulo">
          <i class="pi pi-exclamation-triangle"></i>
          {{ preview.conflicts.length }} líneas necesitan que decidas tú
        </h2>
        <p class="conflictos__nota">
          Nada de esto se importa por su cuenta. Lo que no elijas se
          <strong>descarta</strong>: el resolvedor prefiere no importar una carta antes que importar
          otra distinta.
        </p>

        <article
          v-for="(conflicto, i) in preview.conflicts"
          :key="'c' + i"
          class="conflicto"
          :class="'conflicto--' + motivoDe(conflicto).clase"
        >
          <header class="conflicto__cabecera">
            <span class="conflicto__motivo">
              <i :class="motivoDe(conflicto).icono"></i> {{ motivoDe(conflicto).etiqueta }}
            </span>
            <span class="conflicto__linea">línea {{ conflicto.line }}</span>
          </header>

          <p class="conflicto__crudo"><code>{{ crudoDe(conflicto) }}</code></p>
          <p class="conflicto__ayuda">{{ motivoDe(conflicto).ayuda }}</p>

          <!-- INVALID: la fila vino mal del parser. Se enseña QUÉ campo falla. -->
          <p v-if="conflicto.reason === 'invalid'" class="conflicto__campos">
            <i class="pi pi-times"></i>
            <span v-for="(campo, j) in camposMalos(conflicto)" :key="j" class="conflicto__campo">
              {{ campo }}
            </span>
          </p>

          <!-- MISMATCH: dos datos exactos que se contradicen. Se enseñan las DOS
               cartas, etiquetadas por lo que las trajo, porque es el conflicto que
               evita meter Shyft creyendo que es Lim-Dûl's Vault. -->
          <div v-else-if="conflicto.reason === 'mismatch' && conflicto.candidates.length > 1" class="desacuerdo">
            <div class="desacuerdo__lado">
              <h3 class="desacuerdo__que">Lo que dice el <strong>NOMBRE</strong></h3>
              <template v-for="(cand, k) in conflicto.candidates.slice(0, -1)" :key="'n' + k">
                <button
                  type="button"
                  class="candidato"
                  :class="{ 'candidato--elegido': eleccion[i] === k }"
                  @click="elegir(i, k)"
                >
                  <CandidatoEtiqueta :candidato="candidatoAPintar(i, k, cand)" />
                </button>
                <!--
                  HERMANO del botón y jamás dentro: un `Select` anidado en un
                  `<button>` es HTML inválido y, peor, abrirlo dispararía el
                  `@click` del botón y reelegiría el candidato.
                -->
                <SelectorDeImpresion
                  v-if="eleccion[i] === k && cand.assumedPrinting && cand.printingCount > 1"
                  :printing-uuid="cand.printingUuid"
                  :printing-count="cand.printingCount"
                  :nombre="cand.name"
                  :elegida="impresionConflicto[i] ?? null"
                  @elegir="corregirConflicto(i, $event)"
                />
              </template>
            </div>
            <div class="desacuerdo__lado">
              <h3 class="desacuerdo__que">Lo que dice el <strong>(SET) número</strong></h3>
              <button
                type="button"
                class="candidato"
                :class="{ 'candidato--elegido': eleccion[i] === conflicto.candidates.length - 1 }"
                @click="elegir(i, conflicto.candidates.length - 1)"
              >
                <CandidatoEtiqueta
                  :candidato="candidatoAPintar(i, conflicto.candidates.length - 1, conflicto.candidates[conflicto.candidates.length - 1])"
                />
              </button>
              <!--
                Este lado casi nunca lo necesita —viene de un (SET) número, o
                sea de una impresión concreta—, pero el desplegable se pinta con
                la misma condición que el otro lado y no con una suposición: el
                día que un candidato de aquí llegue con edición asumida, se
                corrige igual que los demás.
              -->
              <SelectorDeImpresion
                v-if="eleccion[i] === conflicto.candidates.length - 1
                  && conflicto.candidates[conflicto.candidates.length - 1].assumedPrinting
                  && conflicto.candidates[conflicto.candidates.length - 1].printingCount > 1"
                :printing-uuid="conflicto.candidates[conflicto.candidates.length - 1].printingUuid"
                :printing-count="conflicto.candidates[conflicto.candidates.length - 1].printingCount"
                :nombre="conflicto.candidates[conflicto.candidates.length - 1].name"
                :elegida="impresionConflicto[i] ?? null"
                @elegir="corregirConflicto(i, $event)"
              />
            </div>
          </div>

          <!-- AMBIGUOUS (y el mismatch degenerado de un solo candidato): elige uno. -->
          <div v-else-if="conflicto.candidates.length" class="candidatos">
            <template v-for="(cand, k) in conflicto.candidates" :key="'k' + k">
              <button
                type="button"
                class="candidato"
                :class="{ 'candidato--elegido': eleccion[i] === k }"
                :disabled="!cand.printingUuid"
                @click="elegir(i, k)"
              >
                <CandidatoEtiqueta :candidato="candidatoAPintar(i, k, cand)" />
              </button>
              <SelectorDeImpresion
                v-if="eleccion[i] === k && cand.assumedPrinting && cand.printingCount > 1"
                :printing-uuid="cand.printingUuid"
                :printing-count="cand.printingCount"
                :nombre="cand.name"
                :elegida="impresionConflicto[i] ?? null"
                @elegir="corregirConflicto(i, $event)"
              />
            </template>
          </div>

          <footer class="conflicto__pie">
            <span class="conflicto__cantidad">{{ conflicto.quantity }} × {{ conflicto.finish }} · {{ conflicto.language }} · {{ conflicto.condition }}</span>
            <Button
              :label="eleccion[i] === null ? 'Descartada' : 'Descartar línea'"
              :icon="eleccion[i] === null ? 'pi pi-check' : 'pi pi-trash'"
              severity="secondary"
              :outlined="eleccion[i] !== null"
              size="small"
              @click="descartarConflicto(i)"
            />
          </footer>
        </article>
      </div>

      <!-- ---------------------------------------------------------------
           LAS RESUELTAS, PLEGADAS. Son las que no necesitan nada del usuario.
           ------------------------------------------------------------ -->
      <div class="resueltas">
        <button type="button" class="resueltas__cabecera" @click="resueltasAbiertas = !resueltasAbiertas">
          <i :class="resueltasAbiertas ? 'pi pi-chevron-down' : 'pi pi-chevron-right'"></i>
          <span>{{ preview.resolved.length }} líneas reconocidas</span>
          <small>{{ resueltasDescartadas }} descartadas</small>
        </button>

        <div v-if="resueltasAbiertas" class="resueltas__cuerpo">
          <div v-if="preview.summary.assumedCount" class="resueltas__filtro">
            <Button
              :label="soloAsumidas ? 'Ver todas' : `Ver solo las ${preview.summary.assumedCount} de edición asumida`"
              icon="pi pi-filter"
              text
              size="small"
              @click="soloAsumidas = !soloAsumidas"
            />
            <small>
              La línea no decía edición, así que se ha elegido la <strong>impresión más barata</strong>
              de esa carta. Si no te vale, descártala y añádela desde el catálogo.
            </small>
          </div>

          <DataTable :value="resueltasVisibles" data-key="idx" size="small" striped-rows paginator :rows="25">
            <Column field="line" header="Línea" style="width: 5rem" />
            <Column field="name" header="Carta">
              <template #body="{ data }">
                <span>{{ data.name }}</span>
                <!--
                  La marca se conserva mientras nadie corrija la edición, porque
                  mientras tanto sigue siendo verdad; en cuanto se elige una
                  impresión concreta deja de serlo y desaparece.
                -->
                <Tag
                  v-if="data.assumedPrinting && !impresionResuelta[data.idx]"
                  value="edición asumida"
                  severity="warn"
                  class="tabla__tag"
                />
              </template>
            </Column>
            <Column field="setCode" header="Edición">
              <template #body="{ data }">
                <!--
                  Corregida la edición, este código es el de la impresión que
                  YA NO se va a importar: se tacha en vez de borrarlo, porque
                  enseñarlo tal cual sería mentir y quitarlo dejaría al usuario
                  sin saber qué acaba de cambiar. La edición buena la enseña el
                  desplegable de la columna de al lado.
                -->
                <span :class="{ 'tabla__anulado': impresionResuelta[data.idx] }">{{ data.setCode }}</span>
                <small
                  v-if="data.assumedPrinting && !impresionResuelta[data.idx]"
                  class="tabla__sub"
                >de {{ data.printingCount }}</small>
              </template>
            </Column>
            <!--
              La corrección de la edición asumida. Solo aparece donde hay algo
              que elegir: una carta con una sola impresión no ofrece nada, y
              pintarle un desplegable vacío sería una petición tirada por cada
              carta nunca reimpresa, que son muchas.
            -->
            <Column header="Cambiar edición" style="width: 17rem">
              <template #body="{ data }">
                <SelectorDeImpresion
                  v-if="data.assumedPrinting && data.printingCount > 1"
                  :printing-uuid="data.printingUuid"
                  :printing-count="data.printingCount"
                  :nombre="data.name"
                  :elegida="impresionResuelta[data.idx] ?? null"
                  @elegir="corregirResuelta(data.idx, $event)"
                />
              </template>
            </Column>
            <Column field="finish" header="Acabado">
              <template #body="{ data }">{{ etiquetaAcabado(data.finish) }}</template>
            </Column>
            <Column field="language" header="Idioma" />
            <Column field="condition" header="Estado" />
            <!--
              La zona solo importa si se va a crear el mazo: la colección no
              tiene zonas y enseñar «Principal» en todas las filas de un CSV de
              ManaBox sería una columna de ruido.
            -->
            <Column v-if="comoMazo" field="board" header="Zona" style="width: 8rem">
              <template #body="{ data }">{{ etiquetaZona(data.board) }}</template>
            </Column>
            <Column field="quantity" header="Cant." style="width: 5rem" />
            <Column header="" style="width: 4rem">
              <template #body="{ data }">
                <Button
                  :icon="descartadas[data.idx] ? 'pi pi-replay' : 'pi pi-trash'"
                  :severity="descartadas[data.idx] ? 'secondary' : 'danger'"
                  text
                  rounded
                  size="small"
                  :aria-label="descartadas[data.idx] ? 'Recuperar' : 'Descartar'"
                  @click="alternarDescarte(data.idx)"
                />
              </template>
            </Column>
          </DataTable>
        </div>
      </div>

      <!-- ---------------------------------------------------------------
           A LA LISTA DE DESEOS.
           La puerta de entrada masiva: pegar una lista de la compra entera.
           Es la MISMA petición y el mismo lote; lo único que cambia es en qué
           conjunto caen las líneas, porque `is_wishlist` no es un filtro sino
           el selector de dos conjuntos excluyentes. El backend lo acepta como
           bandera del lote desde el primer día (`ApplyImport.php:111,236`): no
           hace falta endpoint nuevo.
           ------------------------------------------------------------ -->
      <section class="deseos">
        <label class="deseos__casilla">
          <Checkbox v-model="aDeseos" binary :input-id="'a-deseos'" />
          <span>
            Guardar todo en la lista de deseos
            <small>
              Cartas que <strong>quieres</strong>, no que tienes: van a <code>/wishlist</code> y
              <strong>no</strong> cuentan como colección. Ni suman a tu valor ni completan ningún
              mazo hasta que las marques como compradas.
            </small>
          </span>
        </label>

        <p v-if="aDeseos && comoMazo" class="deseos__nota">
          Con las dos casillas marcadas el mazo se crea igual, pero sus cartas quedan
          <strong>deseadas y no poseídas</strong>: el mazo las pedirá todas. Es la lista de la
          compra de un mazo que todavía no existe en las cajas.
        </p>
      </section>

      <!-- ---------------------------------------------------------------
           IMPORTAR TAMBIÉN COMO MAZO (M7).
           La casilla va aquí y no en el paso 1 porque es una decisión sobre
           LO QUE SE VA A ESCRIBIR, y aquí ya se ve qué zonas trae la lista:
           las cabeceras `Deck` / `Sideboard` / `Commander` del texto pegado
           salen en la columna «Zona» de la tabla de arriba.
           ------------------------------------------------------------ -->
      <section class="mazo">
        <label class="mazo__casilla">
          <Checkbox v-model="comoMazo" binary :input-id="'como-mazo'" />
          <span>
            Crear además un mazo con estas cartas
            <small>
              Las cartas se suman igual a tu colección. El mazo se crea aparte, con las zonas que
              diga la lista (<code>Deck</code>, <code>Sideboard</code>, <code>Commander</code>).
            </small>
          </span>
        </label>

        <div v-if="comoMazo" class="mazo__campos">
          <label class="mazo__campo">
            <span>Nombre del mazo</span>
            <InputText v-model="mazo.name" placeholder="Atraxa, Praetors' Voice" fluid />
          </label>

          <label class="mazo__campo">
            <span>Estado</span>
            <Select
              v-model="mazo.status"
              :options="ESTADOS_MAZO"
              option-label="label"
              option-value="value"
              fluid
            />
          </label>

          <label class="mazo__campo">
            <span>Formato</span>
            <!--
              Texto libre, igual que en `/decks`: los formatos son los de
              `mtg_legality` y una lista propia se desincronizaría con MTGJSON.
            -->
            <InputText v-model="mazo.format" placeholder="commander, modern…" fluid />
          </label>
        </div>

        <p v-if="comoMazo" class="mazo__nota">
          Cada importación crea <strong>su</strong> mazo: si vuelves a importar esta lista tendrás
          dos mazos, y las cantidades de la colección se sumarán.
        </p>
      </section>

      <Message v-if="error" severity="error" :closable="false">{{ error }}</Message>

      <footer class="preview__acciones">
        <Button label="Empezar de nuevo" icon="pi pi-times" severity="secondary" text @click="reiniciar" />
        <div class="preview__confirmar">
          <span v-if="sinDecidir" class="preview__pendientes">
            {{ sinDecidir }} conflictos sin decidir: se descartarán
          </span>
          <span v-if="faltaElNombreDelMazo" class="preview__pendientes">
            Ponle un nombre al mazo o desmarca la casilla
          </span>
          <Button
            :label="`Importar ${filasAImportar.length} líneas (${ejemplaresAImportar} ejemplares)`"
            icon="pi pi-check"
            :disabled="!filasAImportar.length || cargando || faltaElNombreDelMazo"
            @click="aplicar"
          />
        </div>
      </footer>

      <BarraDeProgreso v-if="cargando" :fase="fase" :valor="valorProgreso" :lineas="filasAImportar.length" />
    </section>

    <!-- =====================================================================
         PASO 3 — HECHO
         ================================================================== -->
    <section v-else class="hecho">
      <i class="pi pi-check-circle hecho__icono"></i>
      <h2 class="hecho__titulo">Importación terminada</h2>
      <ul class="hecho__cifras">
        <!--
          Decir «en tu colección» tras una importación a deseos sería mentir
          sobre dónde han caído las cartas, que es justo lo que el usuario acaba
          de elegir.
        -->
        <li>
          <strong>{{ resultado.inserted }}</strong> líneas nuevas en
          {{ aDeseos ? 'tu lista de deseos' : 'tu colección' }}
        </li>
        <li><strong>{{ resultado.updated }}</strong> líneas que ya tenías y han sumado</li>
        <li><strong>{{ resultado.totalQuantity }}</strong> ejemplares añadidos en total</li>
        <li v-if="resultado.deck">
          y el mazo <strong>{{ resultado.deck.name }}</strong> con
          <strong>{{ resultado.deck.cards }}</strong> cartas
        </li>
      </ul>
      <div class="hecho__acciones">
        <Button
          v-if="resultado.deck"
          label="Ver el mazo"
          icon="pi pi-clone"
          @click="router.push({ name: 'deck', params: { id: resultado.deck.id } })"
        />
        <Button
          v-if="aDeseos"
          label="Ver mi lista de deseos"
          icon="pi pi-heart"
          @click="router.push({ name: 'wishlist' })"
        />
        <Button
          v-else
          label="Ver mi colección"
          icon="pi pi-th-large"
          @click="router.push({ name: 'collection' })"
        />
        <Button label="Importar otro fichero" icon="pi pi-upload" severity="secondary" text @click="reiniciar" />
      </div>
    </section>
  </div>
</template>

<script setup>
import { computed, ref } from 'vue'
import { useRouter } from 'vue-router'
import Button from 'primevue/button'
import Checkbox from 'primevue/checkbox'
import Column from 'primevue/column'
import DataTable from 'primevue/datatable'
import InputText from 'primevue/inputtext'
import Message from 'primevue/message'
import Select from 'primevue/select'
import Tag from 'primevue/tag'
import Textarea from 'primevue/textarea'

import BarraDeProgreso from '@/components/ImportProgress.vue'
import CandidatoEtiqueta from '@/components/ImportCandidate.vue'
import SelectorDeImpresion from '@/components/PrintingSelect.vue'
import { ESTADOS_MAZO, etiquetaAcabado, etiquetaZona } from '@/constants/collection'
import { apiCall } from '@/services/api'

/**
 * La vista `/import`: las dos mitades del alto obligatorio del pipeline.
 *
 * ```
 * fichero o pegado → import_preview → PREVISUALIZACIÓN → import_apply → colección
 *                                            ↑
 *                            aquí se arreglan los conflictos
 * ```
 *
 * Tres decisiones de esta vista, ninguna cosmética:
 *
 *  - **Los conflictos van arriba y destacados**, y las resueltas plegadas. Los
 *    conflictos son lo único que necesita al usuario; enterrarlos bajo 3.000
 *    filas correctas equivale a no enseñarlos, y entonces se aceptan a ciegas.
 *  - **Cada motivo se pinta distinto**, porque no piden lo mismo. En `ambiguous`
 *    FALTA información y hay que elegir; en `mismatch` SOBRA —dos datos exactos
 *    que se contradicen— y hay que decidir a cuál se hace caso; `not_found` e
 *    `invalid` no se arreglan aquí, se arreglan en el fichero.
 *  - **Lo que no se decide, no se importa.** El estado por defecto de un
 *    conflicto es "sin decidir", y eso cuenta como descartado. La regla de oro
 *    del plan es que ninguna línea se escribe sin que el resolvedor esté seguro
 *    o el usuario haya elegido.
 *
 * Y desde M7, la casilla **«crear además un mazo»**: manda `deck: {name, status,
 * format}` en el mismo `import_apply`, junto a las `rows`. El backend lo crea en
 * la MISMA transacción que escribe la colección, así que o entran las dos cosas
 * o no entra ninguna. **Cada importación crea su mazo**: reimportar la misma
 * lista suma las cantidades de la colección y deja un segundo mazo, porque el
 * nombre lo pone el usuario cada vez y fusionar en el de antes sería adivinar.
 *
 * Y la segunda bandera del lote, la de la lista de deseos: **`is_wishlist: true`
 * en el mismo `import_apply`**, que es lo que convierte esta pantalla en la
 * puerta de entrada masiva a `/wishlist` — se pega una lista de la compra
 * entera. **Cero backend**: `ApplyImport.php:111,236` la acepta desde el primer
 * día y se la pasa a cada línea. No es un filtro ni un matiz: `is_wishlist`
 * elige uno de dos conjuntos excluyentes, así que lo que se importa a deseos
 * **no** cuenta como colección ni completa ningún mazo.
 */

/** 2 minutos: una importación grande es UNA petición con megas de cuerpo. */
const TIMEOUT_MS = 120000

/** Aviso local antes de mandar 20 MB que el backend va a rechazar igual. */
const MAXIMO_BYTES = 10 * 1024 * 1024

/**
 * Cómo se pinta cada motivo del contrato. La clase la usa el CSS y el texto es
 * lo que le dice al usuario qué se espera de él, que es distinto en cada uno.
 */
const MOTIVOS = {
  mismatch: {
    clase: 'mismatch',
    icono: 'pi pi-directions-alt',
    etiqueta: 'El nombre y el (SET) número no dicen la misma carta',
    ayuda:
      'Aquí no falta información: sobra. La línea trae dos datos exactos que se contradicen, ' +
      'así que elige a cuál se hace caso. Es lo que evita meter una carta creyendo que es otra.'
  },
  ambiguous: {
    clase: 'ambiguous',
    icono: 'pi pi-question-circle',
    etiqueta: 'Varias cartas casan con ese nombre',
    ayuda: 'Falta información para elegir por ti. Elige una carta o descarta la línea.'
  },
  not_found: {
    clase: 'not-found',
    icono: 'pi pi-search-minus',
    etiqueta: 'No se ha encontrado esa carta',
    ayuda:
      'Ningún paso del resolvedor la reconoció y no hay nada que ofrecerte. ' +
      'Corrige el nombre en el fichero y vuelve a previsualizar, o descarta la línea.'
  },
  invalid: {
    clase: 'invalid',
    icono: 'pi pi-ban',
    etiqueta: 'La fila viene mal del fichero',
    ayuda:
      'Un campo de esa fila no es un valor que exista. No se importa con un valor por defecto ' +
      'a propósito: caer a "Near Mint" cuando el fichero decía otra cosa falsearía tu valoración al alza.'
  }
}

const MOTIVO_DESCONOCIDO = {
  clase: 'ambiguous',
  icono: 'pi pi-question-circle',
  etiqueta: 'Sin resolver',
  ayuda: 'Elige una carta o descarta la línea.'
}

const FORMATOS = {
  manabox: 'ManaBox',
  moxfield: 'Moxfield',
  archidekt: 'Archidekt',
  plaintext: 'Texto plano'
}

const router = useRouter()

const paso = ref('origen')
const pegado = ref('')
const contenidoFichero = ref('')
const nombreFichero = ref('')
const tamanoFichero = ref(0)
const formatoForzado = ref('')
const formatos = ref([])
const inputFichero = ref(null)

const cargando = ref(false)
const fase = ref('resolviendo')
const valorProgreso = ref(null)
const error = ref('')

const preview = ref(null)
const resultado = ref(null)
const resueltasAbiertas = ref(false)
const soloAsumidas = ref(false)

/**
 * La casilla «crear además un mazo» y sus tres campos.
 *
 * `status` arranca en `building` —el defecto del backend—: una lista recién
 * pegada casi nunca está montada en una caja, y decir `built` de más haría que
 * el mazo consumiera colección y disparara avisos de sobreasignación falsos.
 */
const comoMazo = ref(false)
const mazo = ref({ name: '', status: 'building', format: '' })

/**
 * La casilla «guardar todo en la lista de deseos».
 *
 * Es **cero backend**: `import_apply` acepta `is_wishlist` como bandera del lote
 * (`ApplyImport.php:111,236`) y lo pasa a cada `CollectionItem`. Y es una
 * bandera del LOTE y no de cada fila a propósito: una lista de la compra se pega
 * entera, y decidir carta a carta convertiría el gesto en 200 casillas.
 *
 * Arranca en `false` porque lo normal al importar es registrar lo que ya tienes;
 * desearlo es la excepción, y una casilla marcada de fábrica metería la colección
 * de alguien en la lista de deseos sin que se diera cuenta.
 */
const aDeseos = ref(false)

/** Índice de fila resuelta → true si el usuario la ha quitado del lote. */
const descartadas = ref({})
/** Índice de conflicto → índice del candidato elegido, o null si se descarta. */
const eleccion = ref({})

/**
 * La corrección de la **edición asumida**, en dos mapas indexados igual que los
 * dos de arriba: por índice de fila resuelta y por índice de conflicto.
 *
 * Son dos y no uno porque las dos superficies no comparten índice —la fila 3 de
 * `resolved` y el conflicto 3 no tienen nada que ver—, y fundirlos en una clave
 * compuesta solo serviría para inventar una forma nueva de indexar el mismo
 * estado que `descartadas` y `eleccion` ya llevan.
 *
 * Un valor aquí **anula** el `printingUuid` que trajo la previsualización, y
 * solo eso: nada más del payload de `import_apply` cambia.
 */
const impresionResuelta = ref({})
const impresionConflicto = ref({})

const contenido = computed(() => (nombreFichero.value ? contenidoFichero.value : pegado.value))
const hayContenido = computed(() => contenido.value.trim() !== '')

const opcionesDeFormato = computed(() => [
  { label: 'Detección automática', value: '' },
  ...formatos.value.map((f) => ({ label: etiquetaFormato(f), value: f }))
])

/** Solo para el texto de la barra de progreso, no para el backend. */
const lineasEstimadas = computed(() => contenido.value.split('\n').length)

const resueltasVisibles = computed(() => {
  const filas = preview.value.resolved.map((r, idx) => ({ ...r, idx }))

  return soloAsumidas.value ? filas.filter((r) => r.assumedPrinting) : filas
})

const resueltasDescartadas = computed(() => Object.values(descartadas.value).filter(Boolean).length)

const sinDecidir = computed(
  () => preview.value.conflicts.filter((c, i) => eleccion.value[i] === undefined).length
)

/**
 * Lo que se manda a `import_apply`: SOLO lo confirmado.
 *
 * Un conflicto sin decidir no entra —el estado por defecto es descartar—, y un
 * candidato sin `printingUuid` tampoco: sin impresión no hay nada que escribir.
 *
 * Y es aquí, en los dos únicos sitios donde se lee un `printingUuid`, donde
 * aterriza la corrección de la edición asumida: se anula el uuid y no se toca
 * nada más, así que una previsualización en la que nadie abra un desplegable
 * manda byte a byte lo mismo que mandaba antes de este hito.
 */
const filasAImportar = computed(() => {
  const filas = []

  preview.value.resolved.forEach((fila, i) => {
    if (!descartadas.value[i]) {
      filas.push(aFilaDelContrato(impresionResuelta.value[i] ?? fila.printingUuid, fila))
    }
  })

  preview.value.conflicts.forEach((conflicto, i) => {
    const k = eleccion.value[i]
    const candidato = k === undefined || k === null ? null : conflicto.candidates[k]

    if (candidato?.printingUuid) {
      filas.push(
        aFilaDelContrato(impresionConflicto.value[i] ?? candidato.printingUuid, conflicto)
      )
    }
  })

  return filas
})

const ejemplaresAImportar = computed(() =>
  filasAImportar.value.reduce((total, f) => total + f.quantity, 0)
)

/** Un mazo sin nombre no es un mazo: el backend devolvería 422. */
const faltaElNombreDelMazo = computed(() => comoMazo.value && mazo.value.name.trim() === '')

function aFilaDelContrato(printingUuid, origen) {
  return {
    printingUuid,
    finish: origen.finish,
    language: origen.language,
    condition: origen.condition,
    quantity: origen.quantity,
    // La zona que dijo la lista pegada. Viaja siempre: a la colección no le
    // afecta —no tiene zonas— y es lo único que reparte el mazo si se crea.
    board: origen.board
  }
}

function etiquetaFormato(nombre) {
  return FORMATOS[nombre] ?? nombre
}

function motivoDe(conflicto) {
  return MOTIVOS[conflicto.reason] ?? MOTIVO_DESCONOCIDO
}

/**
 * Lo que decía el fichero, **sin normalizar**: es lo único que le permite al
 * usuario reconocer su línea.
 *
 * De texto plano llega la línea entera; de un CSV llega el registro completo con
 * sus catorce columnas, y volcarlas todas convierte el conflicto en un muro. Por
 * eso, cuando el registro trae las columnas que identifican una carta, se
 * recompone en la forma corta que el usuario ya sabe leer.
 */
function crudoDe(conflicto) {
  const crudo = conflicto.raw ?? {}

  if (crudo.linea) {
    return crudo.linea
  }

  const nombre = crudo.name ?? crudo.nombre

  if (nombre) {
    const set = crudo['set code'] ?? crudo.setCode ?? crudo.edition ?? ''
    const numero = crudo['collector number'] ?? crudo.collectorNumber ?? ''

    return [conflicto.quantity, nombre, set && `(${set})`, numero].filter(Boolean).join(' ')
  }

  const valores = Object.values(crudo).filter((v) => v !== null && v !== '')

  return valores.length ? valores.join(' · ') : '(la línea llegó vacía)'
}

/** `detail` viene como "campo: motivo; campo: motivo". */
function camposMalos(conflicto) {
  return (conflicto.detail ?? '').split(';').map((t) => t.trim()).filter(Boolean)
}

function enMegas(bytes) {
  return `${(bytes / 1048576).toFixed(1)} MB`
}

function alElegirFichero(evento) {
  const fichero = evento.target.files?.[0]

  if (!fichero) {
    return
  }

  error.value = ''

  if (fichero.size > MAXIMO_BYTES) {
    error.value = `Ese fichero pesa ${enMegas(fichero.size)} y el máximo son ${enMegas(MAXIMO_BYTES)}.`
    return
  }

  nombreFichero.value = fichero.name
  tamanoFichero.value = fichero.size
  pegado.value = ''

  // Leer 5 MB de texto no es instantáneo en un móvil: la barra de progreso
  // empieza aquí, no cuando arranca la petición.
  cargando.value = true
  fase.value = 'leyendo'
  valorProgreso.value = 0

  const lector = new FileReader()

  lector.onprogress = (e) => {
    if (e.lengthComputable) {
      valorProgreso.value = Math.round((e.loaded / e.total) * 100)
    }
  }

  lector.onload = () => {
    contenidoFichero.value = String(lector.result ?? '')
    cargando.value = false
    valorProgreso.value = null
  }

  lector.onerror = () => {
    cargando.value = false
    valorProgreso.value = null
    error.value = 'No se ha podido leer ese fichero.'
    nombreFichero.value = ''
  }

  lector.readAsText(fichero)
}

async function previsualizar() {
  error.value = ''
  cargando.value = true
  fase.value = 'subiendo'
  valorProgreso.value = 0

  const respuesta = await apiCall(
    'import_preview',
    {
      content: contenido.value,
      filename: nombreFichero.value,
      ...(formatoForzado.value ? { format: formatoForzado.value } : {})
    },
    {
      timeout: TIMEOUT_MS,
      onUploadProgress: (e) => {
        if (e.total) {
          valorProgreso.value = Math.round((e.loaded / e.total) * 100)
        }

        // Subido todo: lo que queda es el servidor resolviendo contra el
        // catálogo, y de eso no hay porcentaje que enseñar.
        if (!e.total || e.loaded >= e.total) {
          fase.value = 'resolviendo'
          valorProgreso.value = null
        }
      }
    }
  )

  cargando.value = false
  valorProgreso.value = null

  if (respuesta.data?.formats) {
    formatos.value = respuesta.data.formats
  }

  if (respuesta.status !== 'success') {
    error.value = mensajeDe(respuesta)
    return
  }

  preview.value = respuesta.data
  descartadas.value = {}
  eleccion.value = {}
  impresionResuelta.value = {}
  impresionConflicto.value = {}
  resueltasAbiertas.value = respuesta.data.conflicts.length === 0
  soloAsumidas.value = false
  paso.value = 'preview'
}

async function aplicar() {
  error.value = ''
  cargando.value = true
  fase.value = 'aplicando'
  valorProgreso.value = null

  // Todas las filas en UNA petición: trocearlas rompería la transacción única
  // que impide una importación a medias, y además chocaría con el rate limit.
  // `deck` viaja en el mismo sitio y en la misma petición, porque el backend lo
  // crea dentro de esa misma transacción.
  //
  // `is_wishlist` solo se manda si está marcada: el backend lo lee con
  // `filter_var(… ?? false)`, así que un `false` explícito sobra y ensucia el
  // cuerpo de una importación de 20.000 líneas.
  const respuesta = await apiCall(
    'import_apply',
    {
      rows: filasAImportar.value,
      ...(aDeseos.value ? { is_wishlist: true } : {}),
      ...(comoMazo.value ? { deck: datosDelMazo() } : {})
    },
    { timeout: TIMEOUT_MS }
  )

  cargando.value = false

  if (respuesta.status !== 'success') {
    error.value = mensajeDe(respuesta)
    return
  }

  resultado.value = respuesta.data
  paso.value = 'hecho'
}

/** Lo que espera `import_apply`: el formato en blanco es «sin formato». */
function datosDelMazo() {
  const formato = mazo.value.format.trim()

  return {
    name: mazo.value.name.trim(),
    status: mazo.value.status,
    ...(formato ? { format: formato } : {})
  }
}

function mensajeDe(respuesta) {
  if (respuesta.http_code === 401) {
    return 'Tu sesión ha caducado. Vuelve a entrar y repite la importación.'
  }

  if (respuesta.http_code === 429) {
    return 'Demasiadas peticiones seguidas. Espera un minuto y vuelve a intentarlo.'
  }

  return respuesta.message || 'No se ha podido completar la importación.'
}

function elegir(i, k) {
  eleccion.value = { ...eleccion.value, [i]: k }

  // Cambiar de candidato tira la edición corregida del anterior. No es celo:
  // la corrección es un uuid de OTRA carta, y arrastrarlo importaría la
  // impresión de un candidato que el usuario acaba de descartar.
  const resto = { ...impresionConflicto.value }

  delete resto[i]
  impresionConflicto.value = resto
}

/** Guarda la impresión elegida a mano para la fila resuelta `idx`. */
function corregirResuelta(idx, printingUuid) {
  impresionResuelta.value = { ...impresionResuelta.value, [idx]: printingUuid }
}

/** Lo mismo para el candidato elegido del conflicto `i`. */
function corregirConflicto(i, printingUuid) {
  impresionConflicto.value = { ...impresionConflicto.value, [i]: printingUuid }
}

/**
 * El candidato tal como se pinta, que no siempre es el que llegó.
 *
 * Cuando ya se ha corregido a mano su edición, la marca «edición asumida de N»
 * y el código de edición que trajo la previsualización han dejado de ser
 * verdad: la edición que se va a importar es la que enseña el desplegable de al
 * lado. Se apagan en una copia, y no tocando `ImportCandidate.vue`, que es una
 * etiqueta y tiene que seguir siéndolo.
 */
function candidatoAPintar(i, k, candidato) {
  return eleccion.value[i] === k && impresionConflicto.value[i]
    ? { ...candidato, assumedPrinting: false, setCode: '' }
    : candidato
}

function descartarConflicto(i) {
  eleccion.value = { ...eleccion.value, [i]: eleccion.value[i] === null ? undefined : null }
}

function alternarDescarte(idx) {
  descartadas.value = { ...descartadas.value, [idx]: !descartadas.value[idx] }
}

function reiniciar() {
  paso.value = 'origen'
  preview.value = null
  resultado.value = null
  error.value = ''
  descartadas.value = {}
  eleccion.value = {}
  impresionResuelta.value = {}
  impresionConflicto.value = {}
  comoMazo.value = false
  aDeseos.value = false
  mazo.value = { name: '', status: 'building', format: '' }
  pegado.value = ''
  contenidoFichero.value = ''
  nombreFichero.value = ''
  tamanoFichero.value = 0

  if (inputFichero.value) {
    inputFichero.value.value = ''
  }
}

function volverAtras() {
  if (paso.value === 'preview') {
    reiniciar()
    return
  }

  router.push('/')
}
</script>

<style scoped>
.importar {
  padding: 1rem;
  max-width: 1100px;
  margin: 0 auto;
}

.importar__bar {
  display: flex;
  align-items: center;
  gap: 0.75rem;
  margin-bottom: 1.25rem;
}

.importar__titulo {
  font-size: 1.4rem;
  margin: 0;
  flex: 1;
}

.importar__formato {
  font-size: 0.8rem;
  padding: 0.2rem 0.6rem;
  border-radius: 999px;
  background: var(--p-surface-200);
}

/* ---- Paso 1 -------------------------------------------------------------- */
.aviso {
  display: flex;
  gap: 0.6rem;
  align-items: flex-start;
  padding: 0.75rem 1rem;
  border-radius: 8px;
  background: var(--p-surface-100);
  border-left: 4px solid var(--p-primary-color);
  margin-bottom: 1.25rem;
}

.aviso p {
  margin: 0;
  font-size: 0.9rem;
}

.origen__opciones {
  display: grid;
  grid-template-columns: 1fr auto 1fr;
  gap: 1rem;
  align-items: stretch;
}

.origen__o {
  display: flex;
  align-items: center;
  color: var(--p-text-muted-color);
  font-size: 0.85rem;
}

.caja {
  border: 1px solid var(--p-surface-300);
  border-radius: 10px;
  padding: 1rem;
}

.caja__titulo {
  font-size: 1rem;
  margin: 0 0 0.25rem;
}

.caja__nota {
  font-size: 0.82rem;
  color: var(--p-text-muted-color);
  margin: 0 0 0.75rem;
}

.caja__fichero {
  width: 100%;
  font-size: 0.85rem;
}

.caja__elegido {
  font-size: 0.85rem;
  margin: 0.6rem 0 0;
}

.caja__texto {
  width: 100%;
  font-family: ui-monospace, monospace;
  font-size: 0.85rem;
}

.origen__formato {
  display: flex;
  align-items: center;
  gap: 0.6rem;
  margin-top: 1rem;
  font-size: 0.9rem;
}

.origen__error {
  margin-top: 1rem;
}

.origen__acciones {
  margin-top: 1.25rem;
}

/* ---- Paso 2: resumen ----------------------------------------------------- */
.resumen {
  display: flex;
  flex-wrap: wrap;
  gap: 0.5rem;
  margin-bottom: 1.25rem;
}

.chip {
  font-size: 0.85rem;
  padding: 0.3rem 0.7rem;
  border-radius: 999px;
  background: var(--p-surface-200);
}

.chip--ok {
  background: var(--p-green-100);
  color: var(--p-green-800);
}

.chip--conflicto {
  background: var(--p-red-100);
  color: var(--p-red-800);
  font-weight: 600;
}

.chip--asumida {
  background: var(--p-yellow-100);
  color: var(--p-yellow-800);
}

/* ---- Paso 2: conflictos -------------------------------------------------- */
.conflictos__titulo {
  font-size: 1.05rem;
  display: flex;
  align-items: center;
  gap: 0.5rem;
  margin: 0 0 0.25rem;
  color: var(--p-red-600);
}

.conflictos__nota {
  font-size: 0.85rem;
  color: var(--p-text-muted-color);
  margin: 0 0 1rem;
}

.conflicto {
  border: 1px solid var(--p-surface-300);
  border-left: 5px solid var(--p-surface-400);
  border-radius: 10px;
  padding: 0.9rem 1rem;
  margin-bottom: 0.9rem;
  background: var(--p-surface-0);
}

/* Cada motivo con su color: no piden lo mismo del usuario. */
.conflicto--mismatch {
  border-left-color: var(--p-purple-500);
  background: var(--p-purple-50);
}

.conflicto--ambiguous {
  border-left-color: var(--p-orange-400);
}

.conflicto--not-found {
  border-left-color: var(--p-surface-500);
}

.conflicto--invalid {
  border-left-color: var(--p-red-500);
}

.conflicto__cabecera {
  display: flex;
  justify-content: space-between;
  align-items: center;
  gap: 0.5rem;
  font-size: 0.85rem;
  font-weight: 600;
}

.conflicto__linea {
  color: var(--p-text-muted-color);
  font-weight: 400;
}

.conflicto__crudo {
  margin: 0.5rem 0 0.25rem;
  font-size: 0.85rem;
  word-break: break-word;
}

.conflicto__crudo code {
  background: var(--p-surface-100);
  padding: 0.15rem 0.4rem;
  border-radius: 4px;
}

.conflicto__ayuda {
  font-size: 0.82rem;
  color: var(--p-text-muted-color);
  margin: 0 0 0.6rem;
}

.conflicto__campos {
  display: flex;
  flex-wrap: wrap;
  gap: 0.4rem;
  font-size: 0.82rem;
  margin: 0 0 0.6rem;
  color: var(--p-red-600);
}

.conflicto__campo {
  background: var(--p-red-100);
  padding: 0.15rem 0.5rem;
  border-radius: 4px;
}

.conflicto__pie {
  display: flex;
  justify-content: space-between;
  align-items: center;
  gap: 0.5rem;
  margin-top: 0.7rem;
  font-size: 0.8rem;
  color: var(--p-text-muted-color);
}

.desacuerdo {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 0.75rem;
}

.desacuerdo__que {
  font-size: 0.78rem;
  text-transform: uppercase;
  letter-spacing: 0.04em;
  color: var(--p-text-muted-color);
  margin: 0 0 0.35rem;
}

.candidatos {
  display: flex;
  flex-wrap: wrap;
  gap: 0.5rem;
}

.candidato {
  text-align: left;
  border: 1px solid var(--p-surface-300);
  background: var(--p-surface-0);
  border-radius: 8px;
  padding: 0.45rem 0.7rem;
  cursor: pointer;
  font: inherit;
}

.candidato:hover:not(:disabled) {
  border-color: var(--p-primary-color);
}

.candidato:disabled {
  opacity: 0.5;
  cursor: not-allowed;
}

.candidato--elegido {
  border-color: var(--p-primary-color);
  box-shadow: 0 0 0 2px var(--p-primary-color);
}

/* ---- Paso 2: resueltas --------------------------------------------------- */
.resueltas {
  margin-top: 1.5rem;
  border: 1px solid var(--p-surface-300);
  border-radius: 10px;
  overflow: hidden;
}

.resueltas__cabecera {
  display: flex;
  align-items: center;
  gap: 0.6rem;
  width: 100%;
  padding: 0.75rem 1rem;
  background: var(--p-surface-100);
  border: 0;
  cursor: pointer;
  font: inherit;
  text-align: left;
}

.resueltas__cabecera small {
  margin-left: auto;
  color: var(--p-text-muted-color);
}

.resueltas__cuerpo {
  padding: 0.75rem;
}

.resueltas__filtro {
  display: flex;
  align-items: center;
  gap: 0.75rem;
  flex-wrap: wrap;
  margin-bottom: 0.5rem;
}

.resueltas__filtro small {
  color: var(--p-text-muted-color);
  font-size: 0.8rem;
}

.tabla__tag {
  margin-left: 0.4rem;
  font-size: 0.7rem;
}

.tabla__sub {
  display: block;
  color: var(--p-text-muted-color);
  font-size: 0.75rem;
}

.tabla__anulado {
  text-decoration: line-through;
  color: var(--p-text-muted-color);
}

/* ---- Paso 2: pie --------------------------------------------------------- */
.preview__acciones {
  display: flex;
  justify-content: space-between;
  align-items: center;
  gap: 1rem;
  flex-wrap: wrap;
  margin-top: 1.25rem;
  position: sticky;
  bottom: 0;
  background: var(--p-content-background, var(--p-surface-0));
  padding: 0.75rem 0;
}

.preview__confirmar {
  display: flex;
  align-items: center;
  gap: 0.75rem;
}

.preview__pendientes {
  font-size: 0.82rem;
  color: var(--p-orange-600);
}

/* ---- A la lista de deseos ------------------------------------------------ */
/* Misma caja que la del mazo, porque son la misma clase de decisión: dos
   banderas del lote entero que se deciden con la previsualización delante. */
.deseos {
  margin-top: 1.5rem;
  padding: 1rem;
  border: 1px solid var(--p-surface-300);
  border-radius: 10px;
}

.deseos__casilla {
  display: flex;
  align-items: flex-start;
  gap: 0.7rem;
  cursor: pointer;
}

.deseos__casilla small {
  display: block;
  margin-top: 0.2rem;
  color: var(--p-text-muted-color);
  font-size: 0.8rem;
}

.deseos__nota {
  margin: 0.8rem 0 0;
  color: var(--p-text-muted-color);
  font-size: 0.8rem;
}

/* ---- Importar también como mazo (M7) ------------------------------------- */
.mazo {
  margin-top: 1.5rem;
  padding: 1rem;
  border: 1px solid var(--p-surface-300);
  border-radius: 10px;
}

.mazo__casilla {
  display: flex;
  align-items: flex-start;
  gap: 0.7rem;
  cursor: pointer;
}

.mazo__casilla small {
  display: block;
  margin-top: 0.2rem;
  color: var(--p-text-muted-color);
  font-size: 0.8rem;
}

.mazo__campos {
  display: flex;
  flex-wrap: wrap;
  gap: 1rem;
  margin-top: 1rem;
}

.mazo__campo {
  display: flex;
  flex-direction: column;
  gap: 0.3rem;
  flex: 1 1 12rem;
  font-size: 0.85rem;
}

.mazo__nota {
  margin: 0.8rem 0 0;
  font-size: 0.8rem;
  color: var(--p-text-muted-color);
}

/* ---- Paso 3 -------------------------------------------------------------- */
.hecho {
  text-align: center;
  padding: 3rem 1rem;
}

.hecho__icono {
  font-size: 3rem;
  color: var(--p-green-500);
}

.hecho__titulo {
  margin: 0.75rem 0;
}

.hecho__cifras {
  list-style: none;
  padding: 0;
  margin: 0 0 1.5rem;
  font-size: 0.95rem;
}

.hecho__cifras li {
  margin: 0.25rem 0;
}

.hecho__acciones {
  display: flex;
  justify-content: center;
  gap: 0.75rem;
  flex-wrap: wrap;
}

@media (max-width: 800px) {
  .origen__opciones {
    grid-template-columns: 1fr;
  }

  .origen__o {
    justify-content: center;
  }

  .desacuerdo {
    grid-template-columns: 1fr;
  }
}
</style>
