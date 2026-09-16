<template>
  <div ref="raiz" class="carta-img">
    <!--
      El hueco va SIEMPRE debajo, y la imagen encima cuando existe. La primera
      versión ocultaba la imagen con opacity:0 hasta el evento `load`, y ese
      evento NO llega si el navegador ya la tiene en caché o si Vue recicla el
      nodo al hacer scroll: la imagen estaba cargada y no se veía.
    -->
    <div class="carta-img__hueco">
      <i class="pi pi-image"></i>
      <span v-if="fallo || !url">{{ nombre }}</span>
    </div>

    <img
      v-if="url && !fallo"
      :src="url"
      :alt="nombre"
      loading="lazy"
      decoding="async"
      @error="fallo = true"
    >
  </div>

  <!--
    La ampliación vive FUERA del árbol del componente y no es un descuido:
    `.carta-img` tiene `overflow: hidden` para recortar las esquinas redondeadas
    y las tablas de PrimeVue recortan otra vez, así que cualquier overlay que
    nazca aquí dentro se corta. Colgando de `body` no hay nada que lo recorte.

    `v-if` y no `v-show`: una tabla de 100 filas son 100 `CardImage` y cero
    overlays mientras nadie apunte a ninguno.

    `aria-hidden` + `alt=""` porque es decorativo — el nombre de la carta ya lo
    anuncia el `alt` de la miniatura de arriba y el texto de la fila.
  -->
  <Teleport to="body">
    <div v-if="ampliada" class="carta-zoom" :style="estiloPosicion" aria-hidden="true">
      <img :src="urlAmpliada" alt="" decoding="async">
    </div>
  </Teleport>
</template>

<script setup>
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { imagenDeCarta } from '@/services/scryfall'

const props = defineProps({
  scryfallId: { type: String, default: null },
  nombre: { type: String, default: '' },
  tamano: { type: String, default: 'normal' },

  // Por defecto `true` para que las diez instancias de la app hereden la
  // ampliación sin tocar ni una vista. La única excepción se escribe donde se
  // entiende sola: `CardView.vue`, que ya pinta la carta a 320 px.
  ampliable: { type: Boolean, default: true }
})

const fallo = ref(false)

const url = computed(() => imagenDeCarta(props.scryfallId, props.tamano))

/** La misma carta a `?size=normal`, que es lo que se ve ampliado. */
const urlAmpliada = computed(() => imagenDeCarta(props.scryfallId, 'normal'))

// Al reutilizar el componente con otra carta (el scroll infinito recicla nodos)
// hay que olvidar el fallo de la anterior, o una carta sin imagen dejaría
// marcadas de por vida todas las que pasen por ese nodo. Y hay que cerrar la
// ampliación en curso: si no, un nodo reciclado a mitad de gesto abre la
// ampliación DE OTRA CARTA.
watch(url, () => {
  fallo.value = false
  cerrarAmpliacion()
})

/* ── La ampliación ────────────────────────────────────────────────────────── */

const RETARDO_HOVER = 350 // ms — por debajo, barrer una rejilla dispara diez ampliaciones
const RETARDO_PULSACION = 450 // ms — por debajo se confunde con un toque lento
const TOLERANCIA_ARRASTRE = 10 // px — más allá, es scroll: se aborta el gesto
const ANCHO_AMPLIADA = 340 // px
const MARGEN_VENTANA = 8 // px — aire mínimo contra el borde

/** El alto sale de la proporción real de la carta: 340 × 680/488 → 474 px. */
const ALTO_AMPLIADA = Math.round((ANCHO_AMPLIADA * 680) / 488)

/** Separación entre la miniatura y la ampliación (la tabla de colocación). */
const SEPARACION = 12

const ampliada = ref(false)
const estiloPosicion = ref(null)

let temporizador = null
let precarga = null

/**
 * Anclada al elemento, con volteo: derecha → izquierda → centrada encima o
 * debajo. Se calcula UNA vez, al abrir, desde el rect del viewport, que es
 * exactamente lo que `position: fixed` necesita.
 */
function calcularPosicion(rect) {
  const anchoVentana = window.innerWidth
  const altoVentana = window.innerHeight

  let izquierda = rect.right + SEPARACION
  let arriba = null

  if (izquierda + ANCHO_AMPLIADA + MARGEN_VENTANA > anchoVentana) {
    const volteada = rect.left - SEPARACION - ANCHO_AMPLIADA

    if (volteada >= MARGEN_VENTANA) {
      izquierda = volteada
    } else {
      // Ni a un lado ni al otro: centrada sobre la miniatura, encima si cabe y
      // debajo si no.
      izquierda = rect.left + rect.width / 2 - ANCHO_AMPLIADA / 2
      const encima = rect.top - SEPARACION - ALTO_AMPLIADA
      arriba = encima >= MARGEN_VENTANA ? encima : rect.bottom + SEPARACION
    }
  }

  if (arriba === null) {
    arriba = rect.top + rect.height / 2 - ALTO_AMPLIADA / 2
  }

  return {
    width: `${ANCHO_AMPLIADA}px`,
    left: `${recortar(izquierda, ANCHO_AMPLIADA, anchoVentana)}px`,
    top: `${recortar(arriba, ALTO_AMPLIADA, altoVentana)}px`
  }
}

function recortar(valor, tamano, ventana) {
  return Math.max(MARGEN_VENTANA, Math.min(valor, ventana - tamano - MARGEN_VENTANA))
}

function cancelarTemporizador() {
  if (temporizador !== null) {
    clearTimeout(temporizador)
    temporizador = null
  }

  if (precarga !== null) {
    precarga.onerror = null
    precarga = null
  }
}

function cerrarAmpliacion() {
  cancelarTemporizador()
  ampliada.value = false

  // El gesto táctil muere con la ampliación, pase lo que pase: al soltar, al
  // cancelar, al arrastrar, al hacer scroll y al reciclarse el nodo con otra
  // carta. Así ningún `pointerup` tardío arma la bandera de un gesto que ya
  // no existe.
  origenPulsacion = null
}

function abrirAmpliacion() {
  const rect = raiz.value?.getBoundingClientRect()

  if (!rect) {
    return
  }

  estiloPosicion.value = calcularPosicion(rect)
  ampliada.value = true
}

/**
 * La espera hace doble trabajo: mientras corre el temporizador, el navegador ya
 * se está bajando la imagen grande, así que al abrir suele estar decodificada.
 *
 * Y si esa precarga falla —una carta sin copia local y sin red— se cancela la
 * apertura entera: un rectángulo gris de 340×474 es peor que no abrir nada.
 */
function arrancarAmpliacion(retardo) {
  cancelarTemporizador()

  if (!urlAmpliada.value) {
    return
  }

  const imagen = new Image()
  imagen.onerror = cancelarTemporizador
  imagen.src = urlAmpliada.value
  precarga = imagen

  temporizador = setTimeout(() => {
    temporizador = null
    abrirAmpliacion()
  }, retardo)
}

function alEntrarPuntero(evento) {
  // `pointerenter` y no `mouseenter`: un solo tipo de evento para ratón, dedo y
  // lápiz. El dedo lo atiende `alPulsarPuntero()`, con su propio retardo.
  if (evento.pointerType !== 'mouse') {
    return
  }

  arrancarAmpliacion(RETARDO_HOVER)
}

function alSalirPuntero() {
  // Sin retardo y sin «puente»: dentro del overlay no hay nada hacia lo que
  // mover el ratón, así que no hay que darle tiempo a llegar.
  cerrarAmpliacion()
}

/* ── La pulsación larga en táctil ─────────────────────────────────────────── */

/**
 * El punto donde nació la pulsación, o `null` si no hay gesto táctil en curso.
 *
 * Hace de bandera y de origen a la vez, y por eso es `null` y no un par de
 * coordenadas sueltas: `pointerup`, `pointermove` y `contextmenu` también los
 * dispara el ratón, y preguntar por este valor es lo que distingue «hay un dedo
 * encima» de «hay un cursor encima» sin repetir el filtro de `pointerType` en
 * cada manejador.
 */
let origenPulsacion = null

/**
 * `pointerdown` con cualquier puntero que NO sea el ratón —dedo o lápiz— arranca
 * los 450 ms. El camino es el mismo que el del hover: `arrancarAmpliacion()`,
 * con su precarga que cancela la apertura si la imagen no llega.
 */
function alPulsarPuntero(evento) {
  if (evento.pointerType === 'mouse') {
    return
  }

  // Un gesto nuevo empieza limpio. Si el `click` del anterior nunca llegó —el
  // navegador puede suprimirlo tras sacar su menú contextual—, la bandera se
  // habría quedado armada y se habría comido el siguiente toque, que sí es de
  // verdad.
  debeTragarClick = false

  origenPulsacion = { x: evento.clientX, y: evento.clientY }
  arrancarAmpliacion(RETARDO_PULSACION)
}

/**
 * Alejarse más de `TOLERANCIA_ARRASTRE` del origen **no es una pulsación: es un
 * scroll**, y aborta el gesto entero. Sin esto, recorrer con el dedo una tabla
 * de 100 filas abre una ampliación por fila.
 */
function alMoverPuntero(evento) {
  if (origenPulsacion === null) {
    return
  }

  const dx = evento.clientX - origenPulsacion.x
  const dy = evento.clientY - origenPulsacion.y

  if (Math.hypot(dx, dy) <= TOLERANCIA_ARRASTRE) {
    return
  }

  // Se cierra además de cancelar el temporizador: si el dedo se va después de
  // los 450 ms, la ampliación sobraba igual — y al no llegar a `pointerup` con
  // el gesto vivo, la bandera no se arma y el clic vuelve a pasar.
  cerrarAmpliacion()
}

/**
 * La salida del gesto. **Aquí y solo aquí se arma `debeTragarClick`**, y solo si
 * la ampliación llegó a abrirse: el `click` que el navegador dispara a
 * continuación no es un toque del usuario, es el residuo de la pulsación larga,
 * y dejarlo pasar navega a la ficha o —lo caro— mete una carta en el mazo.
 */
function alSoltarPuntero() {
  if (origenPulsacion === null) {
    return
  }

  if (ampliada.value) {
    debeTragarClick = true
  }

  cerrarAmpliacion()
}

/** `pointercancel` cierra igual, pero sin armar nada: tras él no hay `click`. */
function alCancelarPuntero() {
  cerrarAmpliacion()
}

/**
 * Mientras el dedo sigue abajo, el menú contextual se cancela: Android saca su
 * «abrir imagen en una pestaña nueva» justo encima de la ampliación y a la misma
 * altura de tiempo que el propio gesto.
 *
 * Se mira `origenPulsacion` y no `ampliada`: en el escritorio el botón derecho
 * sobre una carta ampliada tiene que seguir abriendo el menú del navegador.
 */
function alMenuContextual(evento) {
  if (origenPulsacion === null) {
    return
  }

  evento.preventDefault()
}

/**
 * La supresión del clic — **armada por gesto, nunca incondicional**.
 *
 * Todo el gesto táctil de la ampliación depende de que `CardImage`, **desde
 * dentro**, pueda impedir que el `click` que nace en el `<img>` al soltar el
 * dedo llegue al contenedor que lo envuelve. Y los envoltorios son de tres
 * formas distintas, una de ellas cara: el `<button>` de `DeckCardSearch.vue:47`
 * **añade la carta al mazo**, y eso no se deshace con un clic.
 *
 * El listener va en **fase de captura** sobre la raíz: la captura baja de
 * `window` al `<img>`, así que este manejador corre ANTES de que el evento
 * llegue a su destino y `stopPropagation()` lo mata antes de que pueda burbujear
 * hacia el `<button>`, el `router-link` o el `div role="link"`. M0 lo validó
 * contra los tres envoltorios reales —`router-link` incluido—.
 *
 * Y va con `preventDefault()` además, que M0 midió innecesario **en jsdom**: el
 * `router-link` pinta un `<a href="#/card/…">` de verdad, y la navegación por el
 * `href` no es un manejador que `stopPropagation()` pueda cortar, es la **acción
 * por defecto** del navegador. En un Android real, tragarse el clic sin
 * cancelarlo cambiaría el hash igualmente. Cuesta una línea y solo corre cuando
 * la bandera está armada.
 *
 * **Pero solo traga cuando `debeTragarClick` está armada, y nace en `false`**:
 * un clic normal sigue navegando a la ficha y sigue añadiendo la carta al mazo
 * exactamente igual que antes de que existiera esta feature. El spike de M0 la
 * dejó incondicional y eso mataba las dos cosas en toda la app, que es justo lo
 * que este plan declara intocable.
 *
 * **Quien la arma es `alSoltarPuntero()`**, la salida de la pulsación larga en
 * táctil, al soltar el dedo si la ampliación llegó a abrirse; este mismo
 * manejador la desarma acto seguido para que el clic siguiente vuelva a pasar.
 *
 * Se registra a mano y no con `@click.capture.stop` en la plantilla precisamente
 * porque tiene que poder consultar esa bandera.
 *
 * Lo protegen seis tests de regresión, dos por envoltorio
 * (`DeckCardSearch.spec.js`, `DeckView.spec.js` y `CatalogView.spec.js`): uno
 * afirma que el clic pelado **sí** llega —sí navega, sí añade al mazo— y su par
 * que la pulsación larga **no**.
 */
const raiz = ref(null)

/** La arma `alSoltarPuntero()` al soltar una pulsación que abrió la ampliación. */
let debeTragarClick = false

function tragarClick(evento) {
  if (!debeTragarClick) {
    return
  }

  debeTragarClick = false
  evento.stopPropagation()
  evento.preventDefault()
}

onMounted(() => {
  // La guarda va aquí, al principio: con `ampliable: false` no se registra ni un
  // listener, en vez de registrarlos y preguntar dentro de cada manejador.
  if (!props.ampliable) {
    return
  }

  raiz.value?.addEventListener('click', tragarClick, true)
  raiz.value?.addEventListener('pointerenter', alEntrarPuntero)
  raiz.value?.addEventListener('pointerleave', alSalirPuntero)

  // El gesto táctil. Van todos sobre la raíz y no sobre `window` porque el
  // navegador hace **captura implícita del puntero** en el elemento que recibió
  // el `pointerdown` táctil: el `pointermove` y el `pointerup` siguen llegando
  // aquí aunque el dedo se haya ido a otro sitio de la pantalla.
  raiz.value?.addEventListener('pointerdown', alPulsarPuntero)
  raiz.value?.addEventListener('pointermove', alMoverPuntero)
  raiz.value?.addEventListener('pointerup', alSoltarPuntero)
  raiz.value?.addEventListener('pointercancel', alCancelarPuntero)
  raiz.value?.addEventListener('contextmenu', alMenuContextual)

  // Cierre defensivo: `capture` para enterarse también del scroll de un
  // contenedor interno (una tabla), que no burbujea. Sin esto, un overlay se
  // queda flotando sobre una lista que ya se ha movido.
  window.addEventListener('scroll', cerrarAmpliacion, { capture: true, passive: true })
})

onBeforeUnmount(() => {
  if (!props.ampliable) {
    return
  }

  raiz.value?.removeEventListener('click', tragarClick, true)
  raiz.value?.removeEventListener('pointerenter', alEntrarPuntero)
  raiz.value?.removeEventListener('pointerleave', alSalirPuntero)
  raiz.value?.removeEventListener('pointerdown', alPulsarPuntero)
  raiz.value?.removeEventListener('pointermove', alMoverPuntero)
  raiz.value?.removeEventListener('pointerup', alSoltarPuntero)
  raiz.value?.removeEventListener('pointercancel', alCancelarPuntero)
  raiz.value?.removeEventListener('contextmenu', alMenuContextual)
  window.removeEventListener('scroll', cerrarAmpliacion, { capture: true })

  // Para que una ampliación no sobreviva a la navegación.
  cerrarAmpliacion()
})
</script>

<style scoped>
.carta-img {
  position: relative;
  aspect-ratio: 488 / 680; /* la proporción real de una carta de Magic */
  border-radius: 4.75% / 3.5%;
  overflow: hidden;
  background: var(--p-content-background);
}

.carta-img img {
  position: relative;
  z-index: 1;
  width: 100%;
  height: 100%;
  object-fit: cover;
  animation: carta-img-aparecer 0.25s ease;
}

@keyframes carta-img-aparecer {
  from { opacity: 0; }
  to   { opacity: 1; }
}

.carta-img__hueco {
  position: absolute;
  inset: 0;
  z-index: 0;
  display: flex;
  flex-direction: column;
  gap: 0.5rem;
  align-items: center;
  justify-content: center;
  padding: 0.75rem;
  text-align: center;
  font-size: 0.75rem;
  line-height: 1.3;
  color: var(--p-text-muted-color);
  background: var(--p-content-border-color);
}

.carta-img__hueco .pi {
  font-size: 1.5rem;
  opacity: 0.5;
}

/*
  `z-index: 40` y no 60: el techo del proyecto son los avisos, en 50, y las
  barras pegajosas están en 10. 40 deja la ampliación por encima de las barras
  —que es el problema real— y por debajo de un toast, que es información que hay
  que poder leer.

  `pointer-events: none` no es cosmético: si el overlay cae bajo el cursor, roba
  el `pointerleave` de la miniatura y entra en un bucle abrir/cerrar. Dentro no
  hay nada que pulsar, así que es gratis.

  El ancho lo pone `estiloPosicion` desde `ANCHO_AMPLIADA`, para no tener el
  mismo número escrito en dos sitios.
*/
.carta-zoom {
  position: fixed;
  z-index: 40;
  pointer-events: none;
  border-radius: 4.75% / 3.5%;
  overflow: hidden;
  box-shadow: 0 0.5rem 1.5rem rgb(0 0 0 / 35%);
  animation: carta-img-aparecer 0.12s ease;
}

.carta-zoom img {
  display: block;
  width: 100%;
  height: auto;
}
</style>
