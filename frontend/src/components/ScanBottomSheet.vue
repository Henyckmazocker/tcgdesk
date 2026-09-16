<!--
  El menú inferior de `/scan`: los ajustes de sesión, lo detectado y la
  escritura.

  ES LA MITAD ÚTIL DEL ESCÁNER. La cámara y el parser dicen qué carta es; esto
  es lo único que la mete en la colección, y por eso todo aquí está pensado para
  que confirmar cueste **un toque**: si registrar una carta costara abrir un
  diálogo, vaciar un binder de 360 seguiría sin hacerlo nadie.

  Tres cosas que explican su forma y que no son cosméticas:

   1. **Los ajustes van arriba y plegados, con el destino primero.** Se eligen
      una vez y valen para todo lo que se escanee después. Plegados porque esta
      pantalla es una cámara, no un formulario: abiertos taparían justo lo que
      hay que encuadrar.
   2. **Las dudas se pintan con `ImportCandidate.vue`, el mismo componente de
      `/import`.** La taxonomía de conflictos —`ambiguous`, `not_found`,
      `invalid`, `mismatch`— es la que el resolvedor ya tenía probada, y el
      escáner no inventa ni un concepto nuevo. El `PrintingSelect` va **hermano**
      del botón del candidato y jamás dentro: un `Select` anidado en un `<button>`
      es HTML inválido y, peor, abrirlo dispararía el `@click` del botón.
   3. **Una fila ya escrita pierde el check y gana un `+1`.** Es lo que impide
      que dejar el móvil apuntando tres segundos triplique una carta, y a la vez
      lo que permite registrar la segunda copia de verdad cuando la hay. Quién
      está escrita lo dice el `Set` del store, nunca un campo de la fila: así una
      **re-detección** de algo ya escrito nace marcada, aunque su fila sea nueva.

  Y una restricción del entorno: la previsualización de la cámara va **detrás**
  del webview, así que este menú flota encima de ella. De ahí los fondos
  semitransparentes y el alto acotado — taparla entera sería escanear a ciegas.
-->
<template>
  <section class="hoja" aria-label="Cartas detectadas">
    <!-- ── LOS AJUSTES DE SESIÓN ──────────────────────────────────────── -->
    <button type="button" class="hoja__plegable" @click="alternarAjustes">
      <i :class="escaner.ajustesAbiertos ? 'pi pi-chevron-down' : 'pi pi-chevron-right'"></i>
      <span class="hoja__resumen">{{ resumenAjustes }}</span>
      <small v-if="escaner.totalEscrito" class="hoja__contador">
        {{ escaner.totalEscrito }} escritas
      </small>
    </button>

    <div v-if="escaner.ajustesAbiertos" class="ajustes">
      <label class="ajustes__campo">
        <span>Destino</span>
        <Select
          :model-value="destinoSimple"
          :options="DESTINOS"
          option-label="label"
          option-value="value"
          size="small"
          fluid
          aria-label="Destino de lo que escanees"
          @update:model-value="cambiarDestino"
        />
      </label>

      <!-- Solo cuando hace falta: un desplegable de mazos vacío en la colección
           sería un control que no hace nada el 90 % de las veces. -->
      <label v-if="escaner.destinoEsMazo" class="ajustes__campo">
        <span>Mazo</span>
        <Select
          :model-value="escaner.mazoDestino"
          :options="opcionesDeMazo"
          option-label="label"
          option-value="value"
          :loading="mazos.cargandoLista"
          placeholder="Elige un mazo"
          size="small"
          fluid
          aria-label="Mazo donde meter las cartas"
          @update:model-value="escaner.fijarDestino('mazo', $event)"
        />
      </label>

      <label class="ajustes__campo">
        <span>Acabado</span>
        <Select
          :model-value="escaner.ajustes.finish"
          :options="ACABADOS"
          option-label="label"
          option-value="value"
          size="small"
          fluid
          aria-label="Acabado por defecto"
          @update:model-value="escaner.fijarDimension('finish', $event)"
        />
      </label>

      <label class="ajustes__campo">
        <span>Idioma</span>
        <Select
          :model-value="escaner.ajustes.language"
          :options="IDIOMAS"
          option-label="label"
          option-value="value"
          filter
          size="small"
          fluid
          aria-label="Idioma por defecto"
          @update:model-value="escaner.fijarDimension('language', $event)"
        />
      </label>

      <label class="ajustes__campo">
        <span>Estado</span>
        <Select
          :model-value="escaner.ajustes.condition"
          :options="CONDICIONES"
          option-label="label"
          option-value="value"
          size="small"
          fluid
          aria-label="Estado por defecto"
          @update:model-value="escaner.fijarDimension('condition', $event)"
        />
      </label>

      <!--
        El acabado de sesión NO siempre se aplica, y decirlo aquí evita el
        «¿por qué ha guardado normal si puse foil?»: si la impresión solo
        admite uno, ese gana, porque el catálogo lo sabe mejor que el ajuste.
      -->
      <p class="ajustes__nota">
        El acabado solo se aplica si esa impresión admite más de uno; si no,
        manda el catálogo.
      </p>

      <!--
        ── MANOS LIBRES ────────────────────────────────────────────────────
        Va el ÚLTIMO de los ajustes y con su aviso debajo, no por estética:
        es el único que hace que la app escriba en tu colección sin que toques
        nada, y **arranca apagado en cada sesión** aunque el resto de ajustes se
        conserven. Encenderlo es una decisión, no un valor por defecto.

        Solo entra lo que la verja deja CIERTO: una carta con siete ediciones
        posibles se queda esperando en el menú, porque escribirla sería meter una
        edición inventada.
      -->
      <label class="ajustes__campo ajustes__campo--manos">
        <span>Manos libres</span>
        <ToggleSwitch
          :model-value="escaner.ajustes.manosLibres"
          aria-label="Escribir sola la carta cuando la edición sea segura"
          @update:model-value="escaner.fijarManosLibres($event)"
        />
      </label>

      <p class="ajustes__nota">
        Con esto encendido, las cartas cuya <strong>edición sea segura</strong> se
        guardan solas y el móvil vibra. Las dudosas te esperan aquí. Siempre
        puedes deshacer.
      </p>
    </div>

    <p v-if="escaner.aviso" class="hoja__aviso" :class="`hoja__aviso--${escaner.aviso.tipo}`">
      {{ escaner.aviso.texto }}
    </p>

    <!-- ── LO DETECTADO ───────────────────────────────────────────────── -->
    <p v-if="!escaner.filas.length" class="hoja__vacio">
      Nada detectado todavía. Apunta a una carta y aparecerá aquí.
    </p>

    <ul v-else class="hoja__filas">
      <li v-for="fila in escaner.filas" :key="fila.id" class="fila" :class="clasesDeFila(fila)">
        <!-- La casilla, o lo que la sustituye cuando ya está escrita. -->
        <div class="fila__marca">
          <span v-if="fila.estado === 'pendiente'" class="fila__esperando">
            <i class="pi pi-spin pi-spinner"></i>
          </span>

          <template v-else-if="escaner.estaEscrita(fila)">
            <span class="fila__escrita" :title="`${fila.copias} escritas desde esta fila`">
              <i class="pi pi-check-circle"></i>
            </span>
            <Button
              label="+1"
              size="small"
              severity="secondary"
              :loading="fila.escribiendo"
              :aria-label="`Añadir otra copia de ${fila.name}`"
              class="fila__mas"
              @click="escaner.sumarUna(fila)"
            />
            <!--
              DESHACER, y solo en lo que se escribió SOLO. Es la única red del
              modo manos libres: sin esto hay que ir a buscar la carta a
              `/collection` sabiendo cuál fue, y con el móvil en la mano y quince
              cartas por delante eso no pasa.

              Resta una copia, no borra la línea: si ya tenías tres, deshacer no
              puede llevarse las cuatro.
            -->
            <Button
              v-if="fila.escritaSola"
              icon="pi pi-undo"
              size="small"
              severity="danger"
              text
              :loading="fila.escribiendo"
              :aria-label="`Deshacer ${fila.name}`"
              class="fila__deshacer"
              @click="escaner.deshacer(fila)"
            />
          </template>

          <Checkbox
            v-else
            :model-value="false"
            binary
            :disabled="fila.escribiendo || !fila.printingUuid"
            :aria-label="`Guardar ${fila.name || 'esta carta'}`"
            @update:model-value="escaner.escribir(fila)"
          />
        </div>

        <div class="fila__cuerpo">
          <p class="fila__nombre">
            {{ fila.name || '(sin nombre legible)' }}
            <span v-if="fila.copias > 1" class="fila__copias">× {{ fila.copias }}</span>
          </p>

          <p class="fila__meta">
            <template v-if="fila.setCode">
              <span class="fila__set">{{ fila.setCode }}</span>
              <span v-if="fila.collectorNumber">#{{ fila.collectorNumber }}</span>
            </template>
            <span v-if="precioDeFila(fila) !== null" class="fila__precio">
              {{ precioDeFila(fila).toFixed(2) }} €
            </span>
            <span v-if="fila.assumedPrinting" class="fila__asumida">
              edición asumida de {{ fila.printingCount }}
            </span>
            <span v-else-if="fila.corregida" class="fila__asumida">edición elegida a mano</span>
            <span v-else-if="fila.certaintySource === 'art'" class="fila__asumida">
              edición identificada por su arte
            </span>
          </p>

          <!--
            LA EDICIÓN ASUMIDA SE PUEDE CORREGIR, y es la razón por la que este
            plan dependía del de «Impresiones de una Carta»: una carta anterior
            a 2015 no lleva el bloque de la esquina, resuelve por nombre y el
            resolvedor le elige la impresión más barata. Sin este desplegable se
            registraría la edición equivocada sin que nadie se enterase.

            **Y sigue a la vista cuando la impresión la cerró `art`**, que es lo
            que el M3 añadió: ORB acierta con margen ≥ 1,5 y con 0 errores sobre
            la verdad de campo, pero apagar el desplegable dejaría su única
            equivocación posible sin forma de arreglarse.
          -->
          <SelectorDeImpresion
            v-if="
              fila.printingUuid &&
              (fila.assumedPrinting || fila.corregida || fila.certaintySource === 'art') &&
              fila.printingCount > 1
            "
            :printing-uuid="fila.impresionAsumida ?? fila.printingUuid"
            :printing-count="fila.printingCount"
            :nombre="fila.name || 'esta carta'"
            :elegida="fila.printingUuid"
            @elegir="escaner.corregirImpresion(fila, $event)"
          />

          <!-- LAS DUDAS, con el mismo vocabulario que `/import`. -->
          <div v-if="fila.estado === 'duda'" class="duda">
            <p class="duda__motivo">
              <i :class="motivoDe(fila).icono"></i> {{ motivoDe(fila).etiqueta }}
            </p>

            <div v-if="fila.candidates.length" class="duda__candidatos">
              <template v-for="(candidato, k) in fila.candidates" :key="k">
                <button
                  type="button"
                  class="candidato"
                  :class="{ 'candidato--elegido': fila.eleccion === k }"
                  :disabled="!candidato.printingUuid"
                  @click="escaner.elegirCandidato(fila, k)"
                >
                  <CandidatoEtiqueta :candidato="candidato" />
                </button>
                <!-- Hermano del botón, nunca dentro: ver la cabecera. -->
                <SelectorDeImpresion
                  v-if="fila.eleccion === k && candidato.assumedPrinting && candidato.printingCount > 1"
                  :printing-uuid="candidato.printingUuid"
                  :printing-count="candidato.printingCount"
                  :nombre="candidato.name || 'esta carta'"
                  :elegida="fila.printingUuid"
                  @elegir="escaner.corregirImpresion(fila, $event)"
                />
              </template>
            </div>
          </div>

          <!-- Las tres dimensiones, editables **antes** de confirmar. -->
          <div v-if="fila.printingUuid && !escaner.estaEscrita(fila)" class="fila__dimensiones">
            <Select
              :model-value="fila.finish"
              :options="acabadosDeFila(fila)"
              option-label="label"
              option-value="value"
              size="small"
              :aria-label="`Acabado de ${fila.name}`"
              @update:model-value="escaner.cambiarDimension(fila, 'finish', $event)"
            />
            <Select
              :model-value="fila.language"
              :options="IDIOMAS"
              option-label="label"
              option-value="value"
              filter
              size="small"
              :aria-label="`Idioma de ${fila.name}`"
              @update:model-value="escaner.cambiarDimension(fila, 'language', $event)"
            />
            <Select
              :model-value="fila.condition"
              :options="CONDICIONES"
              option-label="label"
              option-value="value"
              size="small"
              :aria-label="`Estado de ${fila.name}`"
              @update:model-value="escaner.cambiarDimension(fila, 'condition', $event)"
            />
          </div>

          <p v-if="fila.error" class="fila__error">
            <i class="pi pi-exclamation-triangle"></i> {{ fila.error }}
          </p>
        </div>
      </li>
    </ul>
  </section>
</template>

<script setup>
import { computed } from 'vue'
import Button from 'primevue/button'
import Checkbox from 'primevue/checkbox'
import Select from 'primevue/select'
import ToggleSwitch from 'primevue/toggleswitch'

import CandidatoEtiqueta from '@/components/ImportCandidate.vue'
import SelectorDeImpresion from '@/components/PrintingSelect.vue'
import { ACABADOS, CONDICIONES, IDIOMAS } from '@/constants/collection'
import { useDeckStore } from '@/stores/deck'
import { DESTINOS, acabadosDe, precioDe, useScanStore } from '@/stores/scan'

/**
 * Los cuatro motivos de conflicto, con las mismas palabras que `/import`.
 *
 * Se repiten aquí en corto y no se importan de `ImportView.vue`: allí cada
 * motivo lleva un párrafo de ayuda que en una pantalla de móvil sobre la cámara
 * no cabe. Lo que **no** cambia son los cuatro valores, que salen de
 * `CardResolution.php` y los sirve `scan_resolve` tal cual.
 */
const MOTIVOS = {
  mismatch: {
    icono: 'pi pi-directions-alt',
    etiqueta: 'El nombre y el número no dicen la misma carta'
  },
  ambiguous: {
    icono: 'pi pi-question-circle',
    etiqueta: 'Varias cartas casan con eso'
  },
  not_found: {
    icono: 'pi pi-search-minus',
    etiqueta: 'No se ha reconocido la carta'
  },
  invalid: {
    icono: 'pi pi-ban',
    etiqueta: 'La lectura no sirve'
  }
}

const MOTIVO_DESCONOCIDO = { icono: 'pi pi-question', etiqueta: 'Hace falta que decidas tú' }

const escaner = useScanStore()
const mazos = useDeckStore()

/** El valor del desplegable: `{mazo: id}` se enseña como `'mazo'`. */
const destinoSimple = computed(() =>
  escaner.destinoEsMazo ? 'mazo' : escaner.ajustes.destino
)

const opcionesDeMazo = computed(() =>
  mazos.mazos.map((m) => ({ label: m.name, value: m.id }))
)

/** Lo que se lee con los ajustes plegados: el destino manda. */
const resumenAjustes = computed(() => {
  if (escaner.destinoEsMazo) {
    const elegido = mazos.mazos.find((m) => m.id === escaner.mazoDestino)

    return elegido ? `Al mazo «${elegido.name}»` : 'Elige un mazo'
  }

  return escaner.ajustes.destino === 'deseos' ? 'A la lista de deseos' : 'A mi colección'
})

function alternarAjustes() {
  escaner.ajustesAbiertos = !escaner.ajustesAbiertos
}

/**
 * Los mazos se piden **al elegir el destino «mazo»** y no al montar: en una
 * pantalla que va a estar la mayoría de las veces escribiendo en la colección,
 * pedir la lista de mazos siempre es una petición de más con la cámara viva.
 */
async function cambiarDestino(valor) {
  escaner.fijarDestino(valor)

  if (valor === 'mazo') await escaner.cargarMazos()
}

function motivoDe(fila) {
  return MOTIVOS[fila.reason] ?? MOTIVO_DESCONOCIDO
}

/** El precio del acabado elegido. `priceEur` habla de `normal`, no de `nonfoil`. */
function precioDeFila(fila) {
  return precioDe(fila.priceEur, fila.finish)
}

/** Solo los acabados que esa impresión admite: `finishes` habla de `nonfoil`. */
function acabadosDeFila(fila) {
  const posibles = acabadosDe(fila.finishes)

  return ACABADOS.filter((a) => posibles.includes(a.value))
}

function clasesDeFila(fila) {
  return {
    'fila--escrita': escaner.estaEscrita(fila),
    'fila--duda': fila.estado === 'duda',
    'fila--pendiente': fila.estado === 'pendiente'
  }
}
</script>

<style scoped>
/*
  Alto acotado y desplazamiento propio: el menú flota sobre la previsualización
  nativa y crecer sin límite dejaría la cámara sin sitio donde verse.
*/
.hoja {
  display: flex;
  flex: 1 1 auto;
  flex-direction: column;
  gap: 0.4rem;
  min-width: 0;
  max-height: 46vh;
  overflow-y: auto;
  color: #fff;
}

.hoja__plegable {
  display: flex;
  align-items: center;
  gap: 0.5rem;
  width: 100%;
  padding: 0.2rem 0;
  border: 0;
  background: none;
  color: inherit;
  font: inherit;
  font-size: 0.85rem;
  text-align: left;
}

.hoja__resumen {
  font-weight: 600;
}

.hoja__contador {
  margin-left: auto;
  opacity: 0.75;
  font-size: 0.7rem;
}

.ajustes {
  display: flex;
  flex-wrap: wrap;
  gap: 0.4rem 0.6rem;
  padding: 0.3rem 0 0.5rem;
  border-bottom: 1px solid rgb(255 255 255 / 20%);
}

.ajustes__campo {
  display: flex;
  flex-direction: column;
  gap: 0.15rem;
  flex: 1 1 8rem;
  font-size: 0.68rem;
  text-transform: uppercase;
  letter-spacing: 0.04em;
  opacity: 0.85;
}

.ajustes__nota {
  flex: 1 1 100%;
  margin: 0;
  font-size: 0.7rem;
  line-height: 1.3;
  opacity: 0.7;
}

.hoja__aviso {
  margin: 0;
  padding: 0.3rem 0.5rem;
  border-radius: 5px;
  font-size: 0.75rem;
  background: rgb(120 0 0 / 80%);
}

.hoja__aviso--ok {
  background: rgb(0 90 40 / 80%);
}

.hoja__vacio {
  margin: 0;
  font-size: 0.78rem;
  opacity: 0.8;
}

.hoja__filas {
  display: flex;
  flex-direction: column;
  gap: 0.35rem;
  margin: 0;
  padding: 0;
  list-style: none;
}

.fila {
  display: flex;
  gap: 0.5rem;
  padding: 0.35rem 0;
  border-top: 1px solid rgb(255 255 255 / 12%);
}

.fila--escrita {
  opacity: 0.75;
}

.fila__marca {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 0.25rem;
  padding-top: 0.15rem;
}

.fila__escrita {
  color: #6f6;
}

.fila__mas {
  padding: 0.1rem 0.35rem;
}

.fila__cuerpo {
  flex: 1;
  min-width: 0;
}

.fila__nombre {
  margin: 0;
  font-size: 0.9rem;
  font-weight: 600;
}

.fila__copias {
  font-weight: 400;
  opacity: 0.8;
}

.fila__meta {
  display: flex;
  flex-wrap: wrap;
  gap: 0.4rem;
  margin: 0.1rem 0 0;
  font-size: 0.72rem;
  opacity: 0.85;
  font-variant-numeric: tabular-nums;
}

.fila__set {
  font-weight: 600;
}

.fila__asumida {
  color: #fc6;
}

.fila__dimensiones {
  display: flex;
  flex-wrap: wrap;
  gap: 0.3rem;
  margin-top: 0.3rem;
}

.duda {
  margin-top: 0.3rem;
}

.duda__motivo {
  margin: 0 0 0.25rem;
  font-size: 0.72rem;
  color: #fc6;
}

.duda__candidatos {
  display: flex;
  flex-direction: column;
  gap: 0.25rem;
}

.candidato {
  padding: 0.3rem 0.45rem;
  border: 1px solid rgb(255 255 255 / 35%);
  border-radius: 5px;
  background: rgb(255 255 255 / 8%);
  color: inherit;
  text-align: left;
  font: inherit;
}

.candidato--elegido {
  border-color: #6f6;
  background: rgb(0 120 40 / 35%);
}

.candidato:disabled {
  opacity: 0.5;
}

.fila__error {
  margin: 0.25rem 0 0;
  font-size: 0.72rem;
  color: #f99;
}
</style>
