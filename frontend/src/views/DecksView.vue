<template>
  <div class="mazos">
    <header class="mazos__bar">
      <Button icon="pi pi-arrow-left" text rounded aria-label="Volver" @click="router.push('/')" />
      <h1 class="mazos__titulo">Mis mazos</h1>

      <Button label="Nuevo mazo" icon="pi pi-plus" size="small" @click="abrirCreacion" />
    </header>

    <main class="mazos__main">
      <p v-if="mazos.errorLista" class="mazos__error">
        <i class="pi pi-exclamation-triangle"></i> {{ mazos.errorLista }}
      </p>

      <!--
        EL AVISO DE SOBREASIGNACIÓN. Informa y no cambia nada por su cuenta: el
        backend jamás desmonta un mazo solo, así que el único que decide es el
        usuario, y decide aquí. Un conflicto es siempre entre varios mazos, por
        eso se nombran todos los implicados.
      -->
      <section v-if="mazos.hayConflicto" class="conflicto">
        <h2 class="conflicto__titulo">
          <i class="pi pi-exclamation-triangle"></i>
          Tus mazos construidos piden más cartas de las que tienes
        </h2>

        <ul class="conflicto__lista">
          <li v-for="linea in mazos.conflicts" :key="claveConflicto(linea)" class="conflicto__linea">
            <div class="conflicto__carta">
              <strong>{{ linea.name }}</strong>
              <small>
                {{ linea.setCode }} · {{ etiquetaAcabado(linea.finish) }} · {{ linea.language }}
                · {{ etiquetaCondicion(linea.condition) }}
              </small>
              <small>
                Piden {{ linea.claimed }} y tienes {{ linea.inCollection }}:
                <strong>faltan {{ linea.missing }}</strong>.
              </small>
            </div>

            <div class="conflicto__mazos">
              <span v-for="implicado in linea.decks" :key="implicado.id" class="conflicto__mazo">
                <router-link :to="{ name: 'deck', params: { id: implicado.id } }">
                  {{ implicado.name }}
                </router-link>
                <small>pide {{ implicado.claimed }}</small>
                <Button
                  label="Desmontar"
                  icon="pi pi-inbox"
                  size="small"
                  severity="warn"
                  text
                  @click="desmontar(implicado)"
                />
              </span>
            </div>
          </li>
        </ul>
      </section>

      <div v-if="mazos.cargandoLista" class="mazos__rejilla">
        <Skeleton v-for="n in 6" :key="n" height="7rem" />
      </div>

      <p v-else-if="mazos.mazos.length === 0" class="mazos__vacio">
        <i class="pi pi-inbox"></i>
        <span>Todavía no tienes ningún mazo.</span>
        <small>Crea uno y móntalo con el buscador: un clic por carta.</small>
        <Button label="Crear mi primer mazo" icon="pi pi-plus" text size="small" @click="abrirCreacion" />
      </p>

      <div v-else class="mazos__rejilla">
        <article v-for="mazo in mazos.mazos" :key="mazo.id" class="mazo">
          <router-link :to="{ name: 'deck', params: { id: mazo.id } }" class="mazo__enlace">
            <header class="mazo__cabecera">
              <span class="mazo__nombre" :title="mazo.name">{{ mazo.name }}</span>
              <Tag
                :value="etiquetaEstadoMazo(mazo.status)"
                :severity="severidadEstadoMazo(mazo.status)"
              />
            </header>

            <p class="mazo__formato">{{ mazo.format || 'sin formato' }}</p>

            <dl class="mazo__cifras">
              <div>
                <dt>Cartas</dt>
                <!-- Sin tokens: los excluye el propio backend del recuento. -->
                <dd>{{ mazo.cards }}</dd>
              </div>
              <div>
                <dt>Valor</dt>
                <dd>{{ euros(mazo.valueEur) }}</dd>
              </div>
              <div>
                <dt>Te faltan</dt>
                <dd :class="{ 'mazo__faltan': mazo.missingCount > 0 }">{{ mazo.missingCount }}</dd>
              </div>
            </dl>
          </router-link>

          <footer class="mazo__pie">
            <Button
              icon="pi pi-trash"
              severity="danger"
              text
              size="small"
              :aria-label="`Borrar ${mazo.name}`"
              @click="pedirBorrado(mazo)"
            />
          </footer>
        </article>
      </div>
    </main>

    <!-- Crear un mazo: solo el nombre es obligatorio. -->
    <Dialog v-model:visible="creando" modal header="Nuevo mazo" :style="{ width: '24rem' }">
      <div class="formulario">
        <label class="formulario__campo">
          <span>Nombre</span>
          <InputText v-model="nuevo.name" fluid autofocus @keyup.enter="crear" />
        </label>

        <label class="formulario__campo">
          <span>Estado</span>
          <Select
            v-model="nuevo.status"
            :options="ESTADOS_MAZO"
            option-label="label"
            option-value="value"
            fluid
          />
        </label>

        <label class="formulario__campo">
          <span>Formato</span>
          <!--
            Texto libre y no una lista propia: los formatos son los de
            `mtg_legality`, en minúsculas y salidos de MTGJSON. Inventar aquí un
            desplegable sería inventarse el vocabulario del que M6 depende.
          -->
          <InputText v-model="nuevo.format" placeholder="commander, modern, standard…" fluid />
        </label>

        <label class="formulario__campo">
          <span>Notas</span>
          <Textarea v-model="nuevo.notes" rows="2" auto-resize fluid />
        </label>
      </div>

      <template #footer>
        <Button label="Cancelar" text severity="secondary" @click="creando = false" />
        <Button label="Crear" icon="pi pi-check" :disabled="!nuevo.name.trim()" @click="crear" />
      </template>
    </Dialog>

    <!--
      Borrar pregunta SIEMPRE por las cartas: «he deshecho la lista» y «he
      vendido el mazo entero» son cosas distintas y el backend no adivina cuál
      es. Por defecto NO se toca la colección.
    -->
    <Dialog v-model:visible="borrando" modal header="Borrar el mazo" :style="{ width: '26rem' }">
      <p class="borrado__texto">
        Se va a borrar <strong>{{ aBorrar?.name }}</strong>. Esto no se puede deshacer.
      </p>

      <label class="borrado__opcion">
        <Checkbox v-model="borrarConCartas" binary />
        <span>
          Descontar también sus {{ aBorrar?.cards }} carta(s) de mi colección
          <small>Márcalo solo si has vendido o regalado las cartas de verdad.</small>
        </span>
      </label>

      <template #footer>
        <Button label="Cancelar" text severity="secondary" @click="borrando = false" />
        <Button label="Borrar" icon="pi pi-trash" severity="danger" @click="borrar" />
      </template>
    </Dialog>

    <DeckAviso />
  </div>
</template>

<script setup>
import { onMounted, reactive, ref } from 'vue'
import { useRouter } from 'vue-router'
import Button from 'primevue/button'
import Checkbox from 'primevue/checkbox'
import Dialog from 'primevue/dialog'
import InputText from 'primevue/inputtext'
import Select from 'primevue/select'
import Skeleton from 'primevue/skeleton'
import Tag from 'primevue/tag'
import Textarea from 'primevue/textarea'

import DeckAviso from '@/components/DeckAviso.vue'
import {
  ESTADOS_MAZO,
  etiquetaAcabado,
  etiquetaCondicion,
  etiquetaEstadoMazo,
  severidadEstadoMazo
} from '@/constants/collection'
import { useDeckStore } from '@/stores/deck'

/**
 * La rejilla de mazos.
 *
 * Enseña de cada uno lo que pide el hito —nombre, estado, número de cartas y
 * valor en euros— y, encima de todo, **el aviso de sobreasignación** si lo hay.
 *
 * El valor NO se calcula aquí: `valueEur` lo trae el backend, que une el precio
 * por `(printing_uuid, finish)` y trata el precio desconocido como cero al sumar
 * pero nunca al mostrarlo. Y `cards` ya viene sin tokens.
 */

const router = useRouter()
const mazos = useDeckStore()

const creando = ref(false)
const borrando = ref(false)
const aBorrar = ref(null)
const borrarConCartas = ref(false)

const nuevo = reactive({ name: '', status: 'building', format: '', notes: '' })

function euros(valor) {
  return `${Number(valor ?? 0).toFixed(2)} €`
}

function claveConflicto(linea) {
  return [linea.printingUuid, linea.finish, linea.language, linea.condition].join('|')
}

function abrirCreacion() {
  nuevo.name = ''
  nuevo.status = 'building'
  nuevo.format = ''
  nuevo.notes = ''
  creando.value = true
}

async function crear() {
  if (!nuevo.name.trim()) {
    return
  }

  const mazo = await mazos.crear({
    name: nuevo.name.trim(),
    status: nuevo.status,
    format: nuevo.format.trim() || null,
    notes: nuevo.notes.trim() || null
  })

  creando.value = false

  if (mazo) {
    router.push({ name: 'deck', params: { id: mazo.id } })
  }
}

function pedirBorrado(mazo) {
  aBorrar.value = mazo
  borrarConCartas.value = false
  borrando.value = true
}

async function borrar() {
  const borrado = await mazos.borrar(aBorrar.value.id, borrarConCartas.value)

  borrando.value = false

  if (borrado) {
    await mazos.cargarConflictos()
  }
}

/** «Desmontar» es un `deck_update` normal con `status: 'dismantled'`. Nada más. */
function desmontar(implicado) {
  return mazos.actualizar(implicado.id, { status: 'dismantled' })
}

onMounted(() => mazos.listar())
</script>

<style scoped>
.mazos {
  padding-bottom: 2rem;
}

.mazos__bar {
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

.mazos__titulo {
  flex: 1;
  margin: 0;
  font-size: 1.05rem;
}

.mazos__main {
  display: flex;
  flex-direction: column;
  gap: 1rem;
  padding: 1rem 0.75rem;
}

.mazos__error {
  display: flex;
  align-items: center;
  gap: 0.4rem;
  margin: 0;
  color: var(--p-red-500);
  font-size: 0.85rem;
}

.mazos__vacio {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 0.4rem;
  padding: 3rem 1rem;
  color: var(--p-text-muted-color);
}

.mazos__vacio .pi {
  font-size: 2rem;
}

.mazos__rejilla {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(15rem, 1fr));
  gap: 0.75rem;
}

.mazo {
  display: flex;
  flex-direction: column;
  border: 1px solid var(--p-content-border-color);
  border-radius: 8px;
  background: var(--p-content-background);
  overflow: hidden;
}

.mazo__enlace {
  display: flex;
  flex-direction: column;
  gap: 0.4rem;
  padding: 0.75rem;
  color: inherit;
  text-decoration: none;
}

.mazo__enlace:hover {
  background: var(--p-content-hover-background);
}

.mazo__cabecera {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 0.5rem;
}

.mazo__nombre {
  font-weight: 600;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}

.mazo__formato {
  margin: 0;
  font-size: 0.75rem;
  color: var(--p-text-muted-color);
}

.mazo__cifras {
  display: flex;
  gap: 1.25rem;
  margin: 0;
}

.mazo__cifras dt {
  font-size: 0.68rem;
  text-transform: uppercase;
  letter-spacing: 0.04em;
  color: var(--p-text-muted-color);
}

.mazo__cifras dd {
  margin: 0;
  font-size: 1rem;
  font-weight: 600;
}

.mazo__faltan {
  color: var(--p-orange-500, #f97316);
}

.mazo__pie {
  display: flex;
  justify-content: flex-end;
  padding: 0 0.4rem 0.4rem;
}

.conflicto {
  padding: 0.75rem;
  border: 1px solid var(--p-orange-400, #fb923c);
  border-radius: 8px;
  background: color-mix(in srgb, var(--p-orange-500, #f97316) 8%, transparent);
}

.conflicto__titulo {
  display: flex;
  align-items: center;
  gap: 0.4rem;
  margin: 0 0 0.5rem;
  font-size: 0.9rem;
}

.conflicto__lista {
  display: flex;
  flex-direction: column;
  gap: 0.6rem;
  margin: 0;
  padding: 0;
  list-style: none;
}

.conflicto__linea {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  justify-content: space-between;
  gap: 0.5rem;
}

.conflicto__carta {
  display: flex;
  flex-direction: column;
  font-size: 0.78rem;
}

.conflicto__carta small {
  color: var(--p-text-muted-color);
}

.conflicto__mazos {
  display: flex;
  flex-wrap: wrap;
  gap: 0.75rem;
}

.conflicto__mazo {
  display: flex;
  align-items: center;
  gap: 0.35rem;
  font-size: 0.8rem;
}

.conflicto__mazo small {
  color: var(--p-text-muted-color);
}

.formulario {
  display: flex;
  flex-direction: column;
  gap: 0.75rem;
}

.formulario__campo {
  display: flex;
  flex-direction: column;
  gap: 0.25rem;
  font-size: 0.8rem;
}

.formulario__campo > span {
  color: var(--p-text-muted-color);
}

.borrado__texto {
  margin: 0 0 0.75rem;
  font-size: 0.9rem;
}

.borrado__opcion {
  display: flex;
  align-items: flex-start;
  gap: 0.5rem;
  font-size: 0.85rem;
}

.borrado__opcion small {
  display: block;
  color: var(--p-text-muted-color);
  font-size: 0.75rem;
}
</style>
