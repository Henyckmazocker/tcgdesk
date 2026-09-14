<template>
  <div class="home">
    <header class="home__bar">
      <h1 class="home__title">TCGDesk</h1>

      <!--
        El menú de la app vive aquí, en la portada, y no en App.vue: cada vista
        tiene ya su propia barra con su botón de volver a `/`, así que un menú
        global se duplicaría con ellas (y con el login, que no debe tenerlo).
        Desde aquí se llega a las tres zonas sin teclear una URL.
      -->
      <nav class="home__nav">
        <router-link :to="{ name: 'catalog' }" class="home__link">
          <i class="pi pi-search"></i> Catálogo
        </router-link>
        <router-link :to="{ name: 'collection' }" class="home__link">
          <i class="pi pi-th-large"></i> Mi colección
        </router-link>
        <router-link :to="{ name: 'wishlist' }" class="home__link">
          <i class="pi pi-heart"></i> Lista de deseos
        </router-link>
        <router-link :to="{ name: 'decks' }" class="home__link">
          <i class="pi pi-clone"></i> Mis mazos
        </router-link>
        <router-link :to="{ name: 'precons' }" class="home__link">
          <i class="pi pi-box"></i> Precons
        </router-link>
        <router-link :to="{ name: 'sets' }" class="home__link">
          <i class="pi pi-book"></i> Ediciones
        </router-link>
        <router-link :to="{ name: 'import' }" class="home__link">
          <i class="pi pi-upload"></i> Importar
        </router-link>

        <!--
          EL CONTADOR DE SOLICITUDES PENDIENTES, y va aquí porque este menú ES la
          barra de la app: `App.vue` son veinte líneas con un `<router-view />` y
          nada más, así que la única navegación global que existe es esta rejilla
          de enlaces. El plan lo pedía «en la barra»; esta es la barra.

          Sale SOLO con solicitudes esperando: un cero permanente enseña a no
          mirarlo, y entonces el día que haya un uno tampoco se mira. Y son las
          recibidas y no las enviadas —`stores/friends.js`, getter `pendientes`—:
          las enviadas esperan a la otra persona y no piden nada de ti.
        -->
        <router-link :to="{ name: 'friends' }" class="home__link">
          <i class="pi pi-users"></i> Amigos
          <span v-if="amigos.pendientes > 0" class="home__badge">{{ amigos.pendientes }}</span>
        </router-link>
      </nav>

      <div class="home__user">
        <img v-if="auth.user?.avatar_url" :src="auth.user.avatar_url" class="home__avatar" alt="">
        <span>{{ auth.displayName }}</span>
        <Button label="Salir" severity="secondary" text size="small" @click="logout" />
      </div>
    </header>

    <main class="home__main">
      <p v-if="coleccion.errorResumen" class="home__error">
        <i class="pi pi-exclamation-triangle"></i> {{ coleccion.errorResumen }}
      </p>

      <!-- Primera carga: esqueletos con la forma de las tarjetas. -->
      <section v-if="coleccion.cargandoResumen && !resumen" class="home__tarjetas">
        <Skeleton v-for="n in 4" :key="n" height="5.5rem" />
      </section>

      <template v-else-if="tieneCartas">
        <!-- LAS CIFRAS -->
        <section class="home__tarjetas">
          <article class="tarjeta tarjeta--principal">
            <span class="tarjeta__etiqueta">Valor de la colección</span>
            <strong class="tarjeta__cifra">{{ euros(totales.valueEur) }}</strong>
            <span class="tarjeta__nota">
              A precio de Cardmarket de hoy. Se recalcula en cada visita: nunca se guarda.
            </span>
          </article>

          <article class="tarjeta">
            <span class="tarjeta__etiqueta">Ejemplares</span>
            <strong class="tarjeta__cifra">{{ totales.totalCopies }}</strong>
            <span class="tarjeta__nota">en {{ totales.uniqueItems }} líneas de colección</span>
          </article>

          <article class="tarjeta">
            <span class="tarjeta__etiqueta">Cartas distintas</span>
            <strong class="tarjeta__cifra">{{ totales.uniqueCards }}</strong>
            <span class="tarjeta__nota">{{ totales.uniquePrintings }} impresiones distintas</span>
          </article>

          <!--
            Una carta sin precio en Cardmarket cuenta como carta y como
            ejemplares, y solo deja de sumar euros. Sin este dato un total bajo
            sería ambiguo: no se sabría si es que no tienes nada caro o si es
            que faltan precios.
          -->
          <article class="tarjeta">
            <span class="tarjeta__etiqueta">Sin precio</span>
            <strong class="tarjeta__cifra">{{ totales.itemsWithoutPrice }}</strong>
            <span class="tarjeta__nota">
              {{ totales.itemsWithoutPrice === 1 ? 'línea no cotiza' : 'líneas no cotizan' }} en
              Cardmarket. Cuentan como cartas; no suman euros.
            </span>
          </article>
        </section>

        <div class="home__columnas">
          <!-- TOP 10 -->
          <section class="panel">
            <header class="panel__cabecera">
              <h2 class="panel__titulo">Tus joyas</h2>
              <router-link :to="{ name: 'collection' }" class="panel__enlace">Ver la colección</router-link>
            </header>

            <p v-if="!resumen.topCards.length" class="panel__vacio">
              Ninguna de tus cartas tiene precio en Cardmarket todavía.
            </p>

            <ol v-else class="joyas">
              <li v-for="carta in resumen.topCards" :key="carta.id" class="joya">
                <div class="joya__imagen" role="link" tabindex="0" @click="abrir(carta)" @keyup.enter="abrir(carta)">
                  <CardImage :scryfall-id="carta.scryfallId" :nombre="carta.name" tamano="small" />
                </div>
                <div class="joya__datos">
                  <a class="joya__nombre" href="#" @click.prevent="abrir(carta)">{{ carta.name }}</a>
                  <small class="joya__meta">
                    {{ carta.setCode }} · {{ etiquetaAcabado(carta.finish) }}
                    <template v-if="carta.quantity > 1"> · ×{{ carta.quantity }}</template>
                  </small>
                </div>
                <span class="joya__precio">{{ euros(carta.priceEur) }}</span>
              </li>
            </ol>
          </section>

          <div class="home__desgloses">
            <!-- POR EDICIÓN -->
            <section class="panel">
              <header class="panel__cabecera">
                <h2 class="panel__titulo">Por edición</h2>
                <router-link :to="{ name: 'sets' }" class="panel__enlace">
                  {{ resumen.bySet.length }} ediciones
                </router-link>
              </header>

              <ul class="barras">
                <li v-for="fila in edicionesVisibles" :key="fila.setCode" class="barra">
                  <div class="barra__fila">
                    <span class="barra__nombre" :title="fila.setName">{{ fila.setName }}</span>
                    <span class="barra__valor">{{ euros(fila.valueEur) }}</span>
                  </div>
                  <div class="barra__pista">
                    <div class="barra__relleno" :style="{ width: ancho(fila.valueEur, maxEdicion) }"></div>
                  </div>
                  <small class="barra__nota">{{ fila.copies }} ejemplares</small>
                </li>
              </ul>
            </section>

            <!-- POR RAREZA -->
            <section class="panel">
              <header class="panel__cabecera">
                <h2 class="panel__titulo">Por rareza</h2>
              </header>

              <ul class="barras">
                <li v-for="fila in resumen.byRarity" :key="fila.rarity" class="barra">
                  <div class="barra__fila">
                    <span class="barra__nombre">{{ etiquetaRareza(fila.rarity) }}</span>
                    <span class="barra__valor">{{ euros(fila.valueEur) }}</span>
                  </div>
                  <div class="barra__pista">
                    <div class="barra__relleno" :style="{ width: ancho(fila.valueEur, maxRareza) }"></div>
                  </div>
                  <small class="barra__nota">{{ fila.copies }} ejemplares en {{ fila.items }} líneas</small>
                </li>
              </ul>
            </section>
          </div>
        </div>
      </template>

      <!--
        TUS MAZOS. La fila de M7: cuántos mazos hay de cada estado y cuánto vale
        lo construido. Va fuera del bloque de `tieneCartas` porque un mazo puede
        existir sin colección —una decklist importada como mazo a la lista de
        deseos, por ejemplo— y ahí el panel sigue teniendo algo que decir.
      -->
      <section v-if="tieneCartas || mazos.mazos.length" class="panel panel--mazos">
        <header class="panel__cabecera">
          <h2 class="panel__titulo">Tus mazos</h2>
          <router-link :to="{ name: 'decks' }" class="panel__enlace">Ver los mazos</router-link>
        </header>

        <p v-if="mazos.errorLista" class="home__error">
          <i class="pi pi-exclamation-triangle"></i> {{ mazos.errorLista }}
        </p>

        <p v-else-if="!mazos.mazos.length" class="panel__vacio">
          Todavía no tienes ningún mazo. También puedes crear uno pegando una decklist en
          <router-link :to="{ name: 'import' }">Importar</router-link>.
        </p>

        <div v-else class="mazos">
          <article v-for="estado in mazos.porEstado" :key="estado.value" class="mazos__estado">
            <strong class="mazos__cifra">{{ estado.cuantos }}</strong>
            <span class="mazos__etiqueta">{{ estado.label }}</span>
          </article>

          <!--
            Solo lo CONSTRUIDO. Los tres estados no son simétricos: sumar los
            mazos a medias diría que tienes montado lo que estás juntando.
          -->
          <article class="mazos__estado mazos__estado--valor">
            <strong class="mazos__cifra">{{ euros(mazos.valorConstruido) }}</strong>
            <span class="mazos__etiqueta">valor de lo construido</span>
          </article>
        </div>
      </section>

      <!-- Colección vacía: los accesos siguen siendo el camino a empezar. -->
      <div v-else class="home__empty">
        <i class="pi pi-inbox home__icon"></i>
        <p>Tu colección está vacía.</p>
        <small>Busca una carta en el catálogo y pulsa «Añadir»: un clic, una carta.</small>
        <small>¿Ya la tienes en ManaBox, Moxfield o Archidekt?
          <router-link :to="{ name: 'import' }">Impórtala de una vez</router-link>.
        </small>
      </div>

      <!--
        EL PANEL DE PRIVACIDAD, y va aquí y no en una ruta propia.
        Esta es la única vista con la navegación de la app y con la identidad del
        usuario —su avatar, su nombre y el botón de salir, arriba—, así que es
        donde encaja una preferencia de cuenta: el perfil público es la otra cara
        de ese mismo bloque. Y el router no gana una ruta más: las únicas dos que
        estrenó este plan son las públicas, que es lo que dice el propio plan.
      -->
      <PrivacyPanel class="home__privacidad" />

      <section class="home__accesos">
        <router-link :to="{ name: 'catalog' }" class="acceso">
          <i class="pi pi-search acceso__icono"></i>
          <span class="acceso__titulo">Catálogo</span>
          <span class="acceso__nota">Busca entre 110.384 cartas en diez idiomas, con precio en euros</span>
        </router-link>

        <router-link :to="{ name: 'collection' }" class="acceso">
          <i class="pi pi-th-large acceso__icono"></i>
          <span class="acceso__titulo">Mi colección</span>
          <span class="acceso__nota">Tus cartas con filtros, orden por precio y edición en línea</span>
        </router-link>

        <router-link :to="{ name: 'wishlist' }" class="acceso">
          <i class="pi pi-heart acceso__icono"></i>
          <span class="acceso__titulo">Lista de deseos</span>
          <span class="acceso__nota">Lo que quieres comprar, y el botón de «ya la tengo» cuando llega</span>
        </router-link>

        <router-link :to="{ name: 'decks' }" class="acceso">
          <i class="pi pi-clone acceso__icono"></i>
          <span class="acceso__titulo">Mis mazos</span>
          <span class="acceso__nota">Monta tus mazos con un clic por carta y mira qué te falta</span>
        </router-link>

        <router-link :to="{ name: 'precons' }" class="acceso">
          <i class="pi pi-box acceso__icono"></i>
          <span class="acceso__titulo">Precons</span>
          <span class="acceso__nota">Los mazos oficiales de MTGJSON con su lista y su precio</span>
        </router-link>

        <router-link :to="{ name: 'sets' }" class="acceso">
          <i class="pi pi-book acceso__icono"></i>
          <span class="acceso__titulo">Ediciones</span>
          <span class="acceso__nota">Cuánto llevas de cada edición, en porcentaje</span>
        </router-link>

        <router-link :to="{ name: 'import' }" class="acceso">
          <i class="pi pi-upload acceso__icono"></i>
          <span class="acceso__titulo">Importar</span>
          <span class="acceso__nota">Sube tu CSV de ManaBox, Moxfield o Archidekt, o pega una lista</span>
        </router-link>

        <router-link :to="{ name: 'friends' }" class="acceso">
          <i class="pi pi-users acceso__icono"></i>
          <span class="acceso__titulo">Amigos</span>
          <span class="acceso__nota">
            <template v-if="amigos.pendientes > 0">
              Tienes {{ amigos.pendientes }}
              {{ amigos.pendientes === 1 ? 'solicitud' : 'solicitudes' }} esperando respuesta
            </template>
            <template v-else>
              Quién ve lo que tienes en «amigos», y los perfiles que sigues
            </template>
          </span>
        </router-link>
      </section>
    </main>
  </div>
</template>

<script setup>
import { computed, onMounted } from 'vue'
import { useRouter } from 'vue-router'
import Button from 'primevue/button'
import Skeleton from 'primevue/skeleton'

import CardImage from '@/components/CardImage.vue'
import PrivacyPanel from '@/components/PrivacyPanel.vue'
import { etiquetaAcabado, etiquetaRareza } from '@/constants/collection'
import { useAuthStore } from '@/stores/auth'
import { useCollectionStore } from '@/stores/collection'
import { useDeckStore } from '@/stores/deck'
import { useFriendsStore } from '@/stores/friends'

/**
 * El dashboard: cuánto vale la colección y de dónde sale ese valor.
 *
 * Todo lo que se ve aquí sale de UNA llamada, `collection_value`, que el backend
 * recalcula sobre los precios de hoy. No hay ningún total guardado en ninguna
 * columna, y por eso tampoco se cachea aquí entre visitas.
 *
 * Las gráficas son barras dibujadas con CSS, sin librería: el repo ya resuelve
 * así su única gráfica (`PriceSparkline.vue`, SVG a mano) y meter chart.js
 * sumaría ~200 KB al bundle —que también viaja dentro del APK— para pintar unos
 * rectángulos.
 */

/** Cuántas ediciones se enseñan aquí antes de mandar a `/sets`. */
const EDICIONES_EN_PORTADA = 6

const auth = useAuthStore()
const router = useRouter()
const coleccion = useCollectionStore()
const mazos = useDeckStore()
const amigos = useFriendsStore()

const resumen = computed(() => coleccion.resumen)
const totales = computed(() => coleccion.resumen?.totals ?? {})
const tieneCartas = computed(() => (coleccion.resumen?.totals?.uniqueItems ?? 0) > 0)

const edicionesVisibles = computed(() => (resumen.value?.bySet ?? []).slice(0, EDICIONES_EN_PORTADA))

/** La barra más larga marca la escala; el resto se mide contra ella. */
const maxEdicion = computed(() => Math.max(...edicionesVisibles.value.map((f) => f.valueEur), 0))
const maxRareza = computed(() => Math.max(...(resumen.value?.byRarity ?? []).map((f) => f.valueEur), 0))

const FORMATO_EUR = new Intl.NumberFormat('es-ES', {
  style: 'currency',
  currency: 'EUR',
  minimumFractionDigits: 2,
  maximumFractionDigits: 2
})

/**
 * Un precio ausente NO es un cero: si alguna vez llega un `null` aquí, dice
 * "sin precio", que es la regla del plan para toda la app.
 */
function euros(valor) {
  return valor === null || valor === undefined ? 'sin precio' : FORMATO_EUR.format(valor)
}

/**
 * El ancho de la barra. Con el máximo a 0 —una colección entera sin precio— la
 * división daría NaN y la barra se rompería: se queda a cero, que es la verdad.
 */
function ancho(valor, maximo) {
  return maximo > 0 ? `${(valor / maximo) * 100}%` : '0%'
}

function abrir(carta) {
  router.push({ name: 'card', params: { uuid: carta.printingUuid } })
}

async function logout() {
  await auth.logout()
  router.replace('/login')
}

onMounted(() => {
  coleccion.valorar()
  // Los mazos van por su lado: son otra llamada y no pueden retrasar el valor
  // de la colección, que es lo primero que el usuario viene a ver.
  mazos.listar()
  // Y el contador de solicitudes, por el suyo. Es UNA acción (`friend_list`) y
  // no dos: aquí no se pinta a quién sigues, así que pedir `follow_list` desde
  // la portada sería una petición para un número que nadie enseña. Un fallo no
  // deja ni aviso ni error en pantalla: el usuario no ha pedido nada de esto.
  amigos.cargarContador()
})
</script>

<style scoped>
.home__bar {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 1rem;
  padding: 0.75rem 1.25rem;
  border-bottom: 1px solid var(--p-content-border-color);
}

.home__title {
  margin: 0;
  font-size: 1.25rem;
}

.home__user {
  display: flex;
  align-items: center;
  gap: 0.75rem;
}

.home__avatar {
  width: 32px;
  height: 32px;
  border-radius: 50%;
}

.home__nav {
  display: flex;
  flex-wrap: wrap;
  gap: 1rem;
  margin-left: auto;
  margin-right: 1rem;
}

.home__link {
  display: inline-flex;
  align-items: center;
  gap: 0.4rem;
  color: var(--p-text-color);
  text-decoration: none;
  font-size: 0.9rem;
}

.home__link:hover {
  color: var(--p-primary-color);
}

.home__badge {
  min-width: 1.15rem;
  padding: 0.05rem 0.35rem;
  border-radius: 999px;
  font-size: 0.7rem;
  line-height: 1.4;
  text-align: center;
  color: var(--p-primary-contrast-color);
  background: var(--p-primary-color);
}

.home__main {
  max-width: 1100px;
  margin: 0 auto;
  padding: 1.5rem 1rem;
}

.home__tarjetas {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
  gap: 1rem;
}

.tarjeta {
  display: flex;
  flex-direction: column;
  gap: 0.2rem;
  padding: 1rem 1.1rem;
  border: 1px solid var(--p-content-border-color);
  border-radius: 8px;
}

.tarjeta--principal {
  border-color: var(--p-primary-color);
}

.tarjeta__etiqueta {
  font-size: 0.75rem;
  text-transform: uppercase;
  letter-spacing: 0.04em;
  color: var(--p-text-muted-color);
}

.tarjeta__cifra {
  font-size: 1.7rem;
  line-height: 1.2;
  font-variant-numeric: tabular-nums;
}

.tarjeta--principal .tarjeta__cifra {
  color: var(--p-primary-color);
}

.tarjeta__nota {
  font-size: 0.72rem;
  line-height: 1.35;
  color: var(--p-text-muted-color);
}

.home__columnas {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
  gap: 1rem;
  margin-top: 1rem;
}

.home__desgloses {
  display: flex;
  flex-direction: column;
  gap: 1rem;
}

.panel {
  padding: 1rem 1.1rem;
  border: 1px solid var(--p-content-border-color);
  border-radius: 8px;
}

.panel__cabecera {
  display: flex;
  align-items: baseline;
  justify-content: space-between;
  gap: 0.5rem;
  margin-bottom: 0.75rem;
}

.panel__titulo {
  margin: 0;
  font-size: 0.95rem;
}

.panel__enlace {
  font-size: 0.75rem;
  color: var(--p-primary-color);
  text-decoration: none;
}

.panel__enlace:hover {
  text-decoration: underline;
}

.panel__vacio {
  margin: 0;
  font-size: 0.8rem;
  color: var(--p-text-muted-color);
}

.joyas {
  display: flex;
  flex-direction: column;
  gap: 0.5rem;
  margin: 0;
  padding: 0;
  list-style: none;
}

.joya {
  display: flex;
  align-items: center;
  gap: 0.6rem;
}

.joya__imagen {
  width: 2.4rem;
  flex: none;
  cursor: pointer;
}

.joya__datos {
  display: flex;
  flex-direction: column;
  min-width: 0;
}

.joya__nombre {
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
  font-size: 0.85rem;
  color: inherit;
  text-decoration: none;
}

.joya__nombre:hover {
  text-decoration: underline;
}

.joya__meta {
  font-size: 0.7rem;
  color: var(--p-text-muted-color);
}

.joya__precio {
  margin-left: auto;
  white-space: nowrap;
  font-size: 0.85rem;
  font-variant-numeric: tabular-nums;
}

.barras {
  display: flex;
  flex-direction: column;
  gap: 0.6rem;
  margin: 0;
  padding: 0;
  list-style: none;
}

.barra__fila {
  display: flex;
  justify-content: space-between;
  gap: 0.5rem;
  font-size: 0.78rem;
}

.barra__nombre {
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.barra__valor {
  white-space: nowrap;
  font-variant-numeric: tabular-nums;
}

.barra__pista {
  height: 6px;
  margin: 0.2rem 0 0.15rem;
  border-radius: 999px;
  background: var(--p-content-border-color);
  overflow: hidden;
}

.barra__relleno {
  height: 100%;
  border-radius: 999px;
  background: var(--p-primary-color);
}

.barra__nota {
  font-size: 0.68rem;
  color: var(--p-text-muted-color);
}

/* ---- Tus mazos (M7) ------------------------------------------------------ */
.panel--mazos {
  margin-top: 1.25rem;
}

.mazos {
  display: flex;
  flex-wrap: wrap;
  gap: 1.5rem;
}

.mazos__estado {
  display: flex;
  flex-direction: column;
  min-width: 7rem;
}

.mazos__cifra {
  font-size: 1.5rem;
  line-height: 1.2;
}

.mazos__etiqueta {
  font-size: 0.8rem;
  color: var(--p-text-muted-color);
}

.mazos__estado--valor {
  margin-left: auto;
  text-align: right;
}

.home__privacidad {
  margin-top: 1.25rem;
}

.home__accesos {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
  gap: 1rem;
  margin-top: 1.25rem;
}

.acceso {
  display: flex;
  flex-direction: column;
  gap: 0.35rem;
  padding: 1.1rem;
  border: 1px solid var(--p-content-border-color);
  border-radius: 8px;
  text-decoration: none;
  color: inherit;
  transition: border-color 0.15s ease, transform 0.15s ease;
}

.acceso:hover {
  border-color: var(--p-primary-color);
  transform: translateY(-2px);
}

.acceso__icono {
  font-size: 1.4rem;
  color: var(--p-primary-color);
}

.acceso__titulo {
  font-weight: 600;
}

.acceso__nota {
  font-size: 0.8rem;
  line-height: 1.35;
  color: var(--p-text-muted-color);
}

.home__empty {
  display: flex;
  flex-direction: column;
  gap: 0.5rem;
  align-items: center;
  justify-content: center;
  min-height: 30vh;
  color: var(--p-text-muted-color);
}

.home__icon {
  font-size: 2.5rem;
}

.home__error {
  display: flex;
  align-items: center;
  gap: 0.5rem;
  color: var(--p-red-500);
  font-size: 0.85rem;
}
</style>
