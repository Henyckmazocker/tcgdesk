<!--
  `/scan` — el escáner por cámara, carta suelta y en vivo.

  QUÉ HACE Y QUÉ NO, porque la diferencia es deliberada. Esta pantalla apunta,
  lee, **pinta lo que ha leído** y se lo pasa al store, que es quien habla con
  `scan_resolve`. Lo que NO hace, y no es un descuido: **no escribe nada en la
  colección ni decide nada sobre la carta**. No resuelve impresiones, no elige
  entre candidatos y no sabe qué es una edición asumida; eso vive en
  `stores/scan.js` y en el backend. Si aquí sale el número equivocado, el culpable
  está en esta pantalla o en `services/scanParser.js`, y en ningún otro sitio.

  LA RESOLUCIÓN VA SUELTA, SIN `await`, desde el M3: la vuelta dispara
  `escaner.detectar()` y sigue. El ritmo del bucle era «lo que tarda ML Kit más
  lo que tarda la red», y la red no aporta nada a la vuelta siguiente. Lo que
  impide que eso dispare tres peticiones por segundo no es el `await` —nunca lo
  fue— sino el `enVuelo` del store, que reconoce la lectura repetida por su clave
  y devuelve sin preguntar.

  EL BUCLE ES EL DEL SPIKE M0, consolidado. `@capacitor-mlkit/text-recognition`
  expone un solo método, `processImage({ path, script })`, y solo acepta una
  **ruta de fichero local**: no hay entrada de stream, así que «cámara en tiempo
  real» es en realidad

      captureSample() → JPEG temporal en disco → processImage(path) → bloques

  y cada vuelta paga tres cruces del puente de JavaScript. El tercero es el que
  el plan no previó: `captureSample()` devuelve **siempre base64, nunca una
  ruta** —el `storeToFile` de `start()` solo afecta a `capture()`—, así que el
  JPEG temporal lo escribe la app con `@capacitor/filesystem`. De ahí que haya un
  tercer plugin y de ahí que haya ficheros que borrar.

  LO QUE HAY QUE SABER ANTES DE TOCAR NADA AQUÍ, y son las tres trampas que el
  plan marca como «de las que cuestan una tarde»:

  1. La previsualización nativa va **detrás** del webview (`toBack: true`), así
     que el fondo tiene que ser transparente o la cámara no se ve y parece que el
     plugin no ha arrancado. Se pone al montar y **se deshace en `onUnmounted`**,
     que es el único gancho por el que pasa salir de la vista con el botón de
     atrás de Android; `App.vue` son veinte líneas y no sabe nada de esto.
  2. Los JPEG temporales se acumulan: a 3 fps son 180 ficheros por minuto. Cada
     vuelta borra el suyo en un `finally` —que es lo único que limpia si el OCR
     lanza— y al salir se vacía el directorio entero.
  3. El permiso de cámara **se pide en tiempo de ejecución** desde Android 6 y el
     rechazo hay que pintarlo. Una pantalla negra sin explicación es el bug que
     más tiempo cuesta de todos.
-->
<template>
  <!--
    Todo lo que se ve son cajas con fondo propio flotando sobre un contenedor
    transparente: la previsualización no se pinta aquí, va por detrás, y lo único
    que esta plantilla puede hacer por ella es no taparla.
  -->
  <div class="scan">
    <header class="scan__barra panel">
      <button type="button" class="scan__boton" @click="volver">
        <i class="pi pi-arrow-left"></i> Volver
      </button>
      <h1 class="scan__titulo">Escanear una carta</h1>
      <span class="scan__pulso" :class="{ 'scan__pulso--vivo': bucleVivo }"></span>
    </header>

    <!-- EL RECHAZO DEL PERMISO SE PINTA. El plugin lo pide solo, pero si Android
         lo deniega se limita a rechazar la promesa y la pantalla se queda negra
         sobre negro. -->
    <div v-if="permisoDenegado" class="panel panel--error">
      <h2>Sin permiso de cámara</h2>
      <p>
        Android ha denegado el acceso a la cámara, así que no hay nada que
        escanear. Si marcaste «No volver a preguntar», el botón de abajo no
        sirve: hay que concederlo a mano en Ajustes → Aplicaciones → TCGDesk →
        Permisos.
      </p>
      <p class="scan__detalle">{{ ultimoError }}</p>
      <button type="button" class="scan__boton" @click="arrancarCamara">Pedirlo otra vez</button>
    </div>

    <div v-else-if="ultimoError && !camaraViva" class="panel panel--error">
      <h2>La cámara no arrancó</h2>
      <p class="scan__detalle">{{ ultimoError }}</p>
      <button type="button" class="scan__boton" @click="arrancarCamara">Reintentar</button>
    </div>

    <!-- LA MIRILLA. No recorta nada ni se le pasa a ML Kit —el OCR recibe el
         fotograma entero—: es una guía para que la esquina inferior izquierda,
         que es donde está el bloque que se lee, caiga dentro del encuadre. -->
    <div v-else class="scan__mirilla" aria-hidden="true">
      <span class="scan__esquina"></span>
    </div>

    <footer class="scan__pie">
      <!-- LO LEÍDO. Es el producto entero de este hito. -->
      <div class="panel scan__lectura">
        <p v-if="!lectura" class="scan__vacio">
          Apunta a una carta. Aquí aparecerá lo que se lea de ella.
        </p>

        <template v-else>
          <p class="scan__nombre">{{ lectura.name || '(sin nombre legible)' }}</p>

          <dl v-if="tieneEsquina" class="scan__campos">
            <div><dt>Edición</dt><dd>{{ lectura.setCode }}</dd></div>
            <div><dt>Número</dt><dd>{{ lectura.collectorNumber }}</dd></div>
            <div><dt>Rareza</dt><dd>{{ etiquetaRareza(lectura.rarity) || '—' }}</dd></div>
            <div><dt>Idioma</dt><dd>{{ etiquetaIdioma(lectura.language) }}</dd></div>
          </dl>

          <!--
            Que no haya bloque de esquina NO es un fallo, y decirlo aquí evita
            leerlo como tal: el número de coleccionista solo existe desde
            *Exodus* (1998) y el bloque completo desde el marco M15 (2014-2015).
            Una carta anterior se resuelve por nombre, y para eso están los pasos
            3 y 4 del resolvedor.
          -->
          <p v-else class="scan__aviso">
            Sin bloque de edición. Se resolverá por nombre: es lo normal en cartas
            anteriores a 2015, que no lo llevan impreso.
          </p>
        </template>
      </div>

      <!--
        EL MENÚ INFERIOR (M3): los ajustes de sesión, lo detectado y la
        escritura. Va DENTRO de un panel con fondo propio, como todo lo demás de
        esta pantalla: el contenedor es transparente a propósito para que la
        previsualización nativa —que se pinta detrás del webview— se vea.
      -->
      <div class="panel scan__hoja">
        <ScanBottomSheet />
      </div>

      <div class="panel scan__mandos">
        <button type="button" class="scan__boton" @click="alternarBucle">
          {{ bucleVivo ? 'Pausar' : 'Reanudar' }}
        </button>
        <button type="button" class="scan__boton" @click="limpiar">Limpiar</button>
        <button type="button" class="scan__boton" @click="verCrudo = !verCrudo">
          {{ verCrudo ? 'Ocultar texto' : 'Ver texto' }}
        </button>
      </div>

      <!-- El texto crudo del OCR, plegado. Es lo que dice si un campo vacío es
           culpa del parser o de que la cámara no leyó nada. -->
      <pre v-if="verCrudo" class="panel scan__crudo">{{ textoCrudo || '(sin texto)' }}</pre>
    </footer>
  </div>
</template>

<script setup>
import { computed, onMounted, onUnmounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import { CameraPreview } from '@capacitor-community/camera-preview'
import { TextRecognition } from '@capacitor-mlkit/text-recognition'
import { Filesystem, Directory } from '@capacitor/filesystem'

import ScanBottomSheet from '@/components/ScanBottomSheet.vue'
import { IDIOMAS, etiquetaRareza, scriptDeOcr } from '@/constants/collection'
import { lecturaVacia, parsearLectura, tieneBloqueDeEsquina } from '@/services/scanParser'
import { useScanStore } from '@/stores/scan'

const router = useRouter()
const escaner = useScanStore()

/**
 * El directorio de los temporales es PROPIO y no la raíz de la caché: al salir
 * se vacía entero, y vaciar la caché de la app se llevaría por delante lo que
 * guarde cualquier otro plugin.
 */
const DIR_TEMPORALES = 'scan'

/**
 * EL SCRIPT DEL OCR SALE DEL SELECTOR DE IDIOMA, y el mapa vive junto a
 * `IDIOMAS` (`scriptDeOcr()`). Hasta el M9 esto era una constante `'LATIN'`, y
 * era el único eslabón roto del japonés: el paso 3c del resolvedor ya casa
 * `隊商の夜番` con *Caravan Vigil* y el M8 ya detecta el idioma, pero el modelo
 * latino no leía un solo carácter que mandarle.
 *
 * Los cinco modelos de script viajan dentro del APK quiera uno o no —`Latn_ctc`,
 * `Jpan_ctc`, `Hani_ctc`, `Kore_ctc`, `Deva_ctc`, es lo que midió el M0—, así
 * que activarlos no suma un solo byte.
 *
 * **Ni dos pasadas ni autodetección por prueba y error.** Leer en latino y
 * reintentar en japonés si sale vacío está vetado en el plan: el bucle da ~2
 * vueltas por segundo y el OCR es lo más caro de la vuelta, así que doblarlo
 * cambia un fallo que se ve —no lee japonés— por uno que no se ve, el escáner
 * que se arrastra.
 */
function scriptDeEstaVuelta() {
  return scriptDeOcr(escaner.ajustes.language)
}

/** Cada cuánto se para el bucle tras un fallo, para no fundir la batería. */
const RESPIRO_TRAS_FALLO_MS = 250

const lectura = ref(null)
const textoCrudo = ref('')
const vueltas = ref(0)
const bucleVivo = ref(false)
const camaraViva = ref(false)
const permisoDenegado = ref(false)
const ultimoError = ref('')
const verCrudo = ref(false)

/**
 * Banderas de control del bucle, en `let` y no en `ref`: que Vue las observe no
 * aporta nada salvo repintados a 3 por segundo.
 */
let cancelado = false
let desmontada = false

const tieneEsquina = computed(() => tieneBloqueDeEsquina(lectura.value))

/** El idioma se guarda con el nombre largo de MTGJSON y se enseña en español. */
function etiquetaIdioma(valor) {
  if (!valor) return '—'
  return IDIOMAS.find((i) => i.value === valor)?.label ?? valor
}

/**
 * EL FONDO TRANSPARENTE NO ES COSMÉTICA. `toBack: true` mete la
 * previsualización detrás del webview, así que si `html`, `body` o `#app` llevan
 * color la cámara no se ve y parece que el plugin no ha arrancado.
 *
 * Se guardan los valores previos en vez de asumir `''` para no pisar lo que
 * ponga el tema de PrimeVue, y se deshace en `onUnmounted` porque esta vista es
 * el único sitio del código que sabe que esto pasó.
 */
let fondosPrevios = null

function ponerFondoTransparente() {
  const raiz = document.getElementById('app')
  fondosPrevios = {
    html: document.documentElement.style.background,
    body: document.body.style.background,
    app: raiz ? raiz.style.background : null
  }
  document.documentElement.style.background = 'transparent'
  document.body.style.background = 'transparent'
  if (raiz) raiz.style.background = 'transparent'
}

function restaurarFondo() {
  if (!fondosPrevios) return
  const raiz = document.getElementById('app')
  document.documentElement.style.background = fondosPrevios.html
  document.body.style.background = fondosPrevios.body
  if (raiz && fondosPrevios.app !== null) raiz.style.background = fondosPrevios.app
  fondosPrevios = null
}

async function arrancarCamara() {
  permisoDenegado.value = false
  ultimoError.value = ''
  try {
    await CameraPreview.start({
      position: 'rear',
      toBack: true,
      // Sin `width`/`height` el plugin coge la pantalla entera, que es lo que se
      // quiere: cuanto más píxel le llegue a ML Kit, mejor lee el número de
      // coleccionista, que es un tipo de 6 puntos en una esquina.
      disableAudio: true,
      lockAndroidOrientation: true
    })
    camaraViva.value = true
    arrancarBucle()
  } catch (e) {
    // El plugin rechaza la promesa TANTO si el permiso se deniega COMO si la
    // cámara falla por otra cosa, y el mensaje es lo único que los distingue.
    // Se separan porque la salida del usuario es distinta: una se arregla en
    // Ajustes y la otra no.
    const mensaje = String(e && e.message ? e.message : e)
    ultimoError.value = mensaje
    camaraViva.value = false
    if (/permission|denied|denegad/i.test(mensaje)) permisoDenegado.value = true
  }
}

/**
 * UNA VUELTA: capturar, escribir el JPEG, leerlo con ML Kit y parsear.
 *
 * El `finally` que borra el fichero no es higiene opcional. A 3 fps son 180
 * ficheros por minuto, y con OCR sobre fotogramas movidos el bucle lanza a
 * menudo: el `finally` es lo ÚNICO que los limpia cuando eso pasa.
 */
async function unaVuelta() {
  let nombreTemporal = null
  let rutaTemporal = null
  let leida = null
  // El JPEG de esta vuelta, que es lo que ORB empareja contra las impresiones
  // candidatas. Se declara fuera del `try` porque quien lo usa es la resolución,
  // que va DESPUÉS del `finally` que borra el fichero temporal — a ORB le sirven
  // los bytes en memoria y no la ruta, así que no hay nada que esperar.
  let foto = null

  try {
    const muestra = await CameraPreview.captureSample({ quality: 85 })

    foto = muestra.value

    // `Directory.Cache` es el sitio correcto: es dato regenerable y Android
    // puede purgarlo si le hace falta espacio.
    nombreTemporal = `${DIR_TEMPORALES}/v${Date.now()}-${vueltas.value}.jpg`
    const escrito = await Filesystem.writeFile({
      path: nombreTemporal,
      data: muestra.value,
      directory: Directory.Cache,
      recursive: true
    })
    rutaTemporal = escrito.uri

    const resultado = await TextRecognition.processImage({
      path: rutaTemporal,
      script: scriptDeEstaVuelta()
    })

    leida = anotarLectura(resultado)

    vueltas.value += 1
  } finally {
    if (rutaTemporal) {
      try {
        // Se borra por `path` + `directory` y no por la uri devuelta: es la
        // forma que el plugin acepta en las dos plataformas.
        await Filesystem.deleteFile({ path: nombreTemporal, directory: Directory.Cache })
      } catch {
        // Un borrado fallido no puede tumbar el escaneo. Lo que quede se lo
        // lleva el vaciado del directorio al salir.
      }
    }
  }

  /*
    LA RESOLUCIÓN VA FUERA DEL `finally` Y **SIN `await`**, y las dos cosas
    importan por motivos distintos.

    Fuera del `finally`: el JPEG temporal ya está borrado cuando se dispara, así
    que esperar a la red no acumula ficheros en `Directory.Cache`.

    Y sin `await` desde el M3: hasta ahora la vuelta se bloqueaba hasta que el
    backend contestaba, así que el ritmo del bucle era «lo que tarda ML Kit MÁS
    lo que tarda la red», y la red no aporta nada a la vuelta siguiente — la
    lectura que la vuelta 8 necesita no depende del veredicto de la 7. Soltarla
    devuelve al bucle esos milisegundos enteros.

    Y no descontrola nada, que era el miedo razonable de cuando se escribió
    aguardando: **el `enVuelo` del store ya impide las peticiones duplicadas**.
    Una carta a la que se apunta tres segundos son ~6 vueltas con la misma clave
    de lectura, y solo la primera sale a la red; las otras cinco vuelven del
    store sin preguntar. Lo que el `await` frenaba no eran peticiones repetidas,
    era el propio escaneo.

    Los errores se recogen igualmente: `detectar()` no lanza —el store convierte
    el fallo en `aviso` y retira la fila—, pero un `catch` vacío aquí es lo que
    impide que un rechazo inesperado suba a una promesa sin capturar y mate la
    cadena del bucle.
  */
  if (!leida) return

  escaner
    .detectar(leida, foto)
    .catch(() => {
      // Nunca debería llegar aquí; si llega, la cadena del bucle sigue viva.
    })
}

/**
 * Una lectura vacía **no borra la anterior**, y esa es la única decisión de
 * interfaz que hay aquí: a tres vueltas por segundo, un solo fotograma movido
 * —y hay muchos— vaciaría la pantalla justo mientras el usuario la está
 * leyendo. Se conserva lo último que se leyó de verdad hasta que haya otra cosa
 * que enseñar, y `Limpiar` está ahí para descartarla a mano.
 */
function anotarLectura(resultado) {
  textoCrudo.value = resultado?.text || ''
  const leida = parsearLectura(resultado)
  if (lecturaVacia(leida)) return null

  lectura.value = leida

  // Lo que se devuelve es lo que va al backend. Se devuelve en vez de mandarse
  // aquí para que la llamada quede FUERA del `finally` que borra el temporal.
  return leida
}

/**
 * El bucle es una cadena de promesas y NO un `setInterval`: con temporizador las
 * vueltas se solaparían en cuanto una pasara del intervalo —y pasan— y se
 * acabarían apilando capturas contra una cámara que no da abasto. Aquí la vuelta
 * siguiente empieza cuando termina la anterior.
 */
async function arrancarBucle() {
  if (bucleVivo.value) return
  bucleVivo.value = true
  cancelado = false

  while (!cancelado && !desmontada) {
    try {
      await unaVuelta()
    } catch (e) {
      ultimoError.value = String(e && e.message ? e.message : e)
      // Una vuelta que falla no para el escaneo —un fotograma movido hace fallar
      // el OCR y es parte normal del barrido— pero se cede el hilo para no
      // convertir un fallo permanente en un bucle cerrado que funde la batería y
      // congela la interfaz.
      await new Promise((r) => setTimeout(r, RESPIRO_TRAS_FALLO_MS))
    }
  }

  bucleVivo.value = false
}

function alternarBucle() {
  if (bucleVivo.value) cancelado = true
  else arrancarBucle()
}

/**
 * CON LA PANTALLA APAGADA NO SE ESCANEA.
 *
 * Medido el 2026-09-15 con `adb`: con el móvil en reposo —`Display Power: OFF`—
 * el bucle **seguía dando vueltas**, capturando fotogramas negros, pasándolos
 * por ML Kit y escribiendo un JPEG por vuelta en `Directory.Cache`. Ni un dato
 * útil y toda la batería del mundo: a ~366 ms por vuelta son casi tres capturas
 * por segundo contra una pantalla apagada.
 *
 * Se atiende con `visibilitychange` y no con un plugin nuevo: el webview sigue el
 * ciclo de vida de la Activity, así que apagar la pantalla o mandar la app al
 * fondo lo marca oculto. Es API del navegador y funciona igual en `npm run dev`,
 * donde cambiar de pestaña produce exactamente el mismo efecto.
 *
 * **La cámara se para también, no solo el bucle.** Dejarla viva con la pantalla
 * apagada es el otro consumo, y además Android puede quitársela a una app en
 * segundo plano: al volver, el bucle estaría capturando contra una cámara que ya
 * no es suya. Arrancarla de nuevo al volver es más barato que descubrirlo con un
 * error por vuelta.
 */
async function alCambiarVisibilidad() {
  if (desmontada) return

  if (document.hidden) {
    cancelado = true

    try {
      await CameraPreview.stop()
    } catch {
      // Parar una cámara que ya no estaba viva no es un error que importe aquí.
    }

    camaraViva.value = false

    return
  }

  // ## De vuelta, y hay que ESPERAR a que el bucle anterior haya salido
  //
  // `cancelado = true` no para el bucle: le pide que pare, y la vuelta en curso
  // todavía tiene que terminar su captura, su OCR y su borrado. Si se rearranca
  // durante ese hueco, `arrancarBucle()` se encuentra `bucleVivo` a `true`,
  // vuelve sin hacer nada, y un instante después el bucle viejo sale por su
  // cuenta: **cámara viva y bucle muerto**, que en pantalla es una
  // previsualización que se ve y no reconoce nada.
  //
  // Esperar a que `bucleVivo` baje evita también el caso contrario —dos bucles
  // encadenando capturas contra la misma cámara—, que es el que se comió un
  // worker entero de Vitest antes de que esto estuviera aquí.
  while (bucleVivo.value && !desmontada) {
    await new Promise((r) => setTimeout(r, 0))
  }

  if (desmontada) return

  if (!camaraViva.value) await arrancarCamara()
}

/**
 * «Limpiar» vacía lo que se ve, **no la memoria de lo escrito**: las cartas ya
 * registradas siguen en la colección, así que olvidarlas aquí haría que la
 * siguiente pasada del bucle las escribiera otra vez.
 */
function limpiar() {
  lectura.value = null
  textoCrudo.value = ''
  escaner.limpiarFilas()
}

/**
 * La segunda mitad de la limpieza: el `finally` de cada vuelta borra el fichero
 * de esa vuelta, pero lo que quede de una vuelta muerta a medias solo se lo
 * lleva esto.
 */
async function vaciarTemporales() {
  try {
    await Filesystem.rmdir({ path: DIR_TEMPORALES, directory: Directory.Cache, recursive: true })
  } catch {
    // Si el directorio no llegó a existir, no hay nada que limpiar.
  }
}

function volver() {
  router.push('/')
}

onMounted(() => {
  // Sesión de escaneo limpia: sin filas y sin memoria de lo escrito. Los
  // ajustes SÍ se conservan —destino y dimensiones son lo único que el usuario
  // eligió a mano— y por eso no se reinicia el store entero.
  escaner.empezarSesion()
  ponerFondoTransparente()
  document.addEventListener('visibilitychange', alCambiarVisibilidad)
  arrancarCamara()
})

/**
 * El desmontaje va en el orden inverso al montaje, y el orden importa: primero
 * se para el bucle —o seguiría capturando contra una cámara que ya no está y
 * llenaría el log de rechazos—, luego la cámara, y solo entonces se devuelve el
 * fondo. Devolverlo antes dejaría un fotograma con la interfaz pintada encima de
 * una previsualización todavía viva.
 *
 * `onUnmounted` es el único gancho por el que pasa salir de aquí: el botón de
 * atrás de Android no dispara ningún otro.
 */
onUnmounted(async () => {
  desmontada = true
  cancelado = true
  // Antes que nada: el listener vive en `document`, así que sobreviviría a la
  // vista y estaría rearrancando la cámara desde una pantalla que ya no existe.
  document.removeEventListener('visibilitychange', alCambiarVisibilidad)
  try {
    await CameraPreview.stop()
  } catch {
    // Parar una cámara que ya no estaba viva no es un error que importe aquí.
  }
  camaraViva.value = false
  restaurarFondo()
  await vaciarTemporales()
})
</script>

<style scoped>
/*
  El contenedor NO lleva fondo, y es deliberado: cualquier color aquí tapa la
  previsualización nativa, que se pinta detrás del webview. Solo los paneles
  tienen fondo, y semitransparente, para no perder de vista lo que se enfoca.
*/
.scan {
  position: fixed;
  inset: 0;
  display: flex;
  flex-direction: column;
  justify-content: space-between;
  gap: 0.5rem;
  padding: 0.5rem;
  background: transparent;
  color: #fff;
  /* El contenedor no recibe puntero para que los huecos entre paneles dejen
     tocar (y ver) la previsualización; los paneles lo reactivan. */
  pointer-events: none;
}

.panel {
  pointer-events: auto;
  background: rgba(0, 0, 0, 0.72);
  border-radius: 8px;
  padding: 0.6rem 0.75rem;
}

.panel--error {
  background: rgba(120, 0, 0, 0.92);
}

.scan__barra {
  display: flex;
  align-items: center;
  gap: 0.75rem;
}

.scan__titulo {
  margin: 0;
  font-size: 0.95rem;
  font-weight: 600;
}

/* El punto verde dice que el bucle está vivo sin gastar una línea de texto. */
.scan__pulso {
  width: 10px;
  height: 10px;
  margin-left: auto;
  border-radius: 50%;
  background: #666;
}

.scan__pulso--vivo {
  background: #6f6;
}

.scan__mirilla {
  position: relative;
  flex: 1;
  margin: 0 12%;
  border: 2px solid rgba(255, 255, 255, 0.55);
  border-radius: 10px;
}

/* La esquina inferior izquierda marcada: es donde está el bloque que se lee. */
.scan__esquina {
  position: absolute;
  left: -2px;
  bottom: -2px;
  width: 38%;
  height: 12%;
  border-left: 3px solid #6f6;
  border-bottom: 3px solid #6f6;
  border-bottom-left-radius: 10px;
}

.scan__pie {
  display: flex;
  flex-direction: column;
  gap: 0.5rem;
  /* El pie puede crecer, pero no hasta comerse el encuadre: la mirilla y la
     previsualización tienen que seguir viéndose mientras se confirma. */
  max-height: 72vh;
  overflow: hidden;
}

.scan__hoja {
  display: flex;
  min-height: 0;
}

.scan__nombre {
  margin: 0 0 0.35rem;
  font-size: 1.05rem;
  font-weight: 600;
}

.scan__campos {
  display: flex;
  flex-wrap: wrap;
  gap: 0.75rem 1.25rem;
  margin: 0;
}

.scan__campos dt {
  font-size: 0.65rem;
  text-transform: uppercase;
  letter-spacing: 0.04em;
  opacity: 0.7;
}

.scan__campos dd {
  margin: 0;
  font-size: 0.95rem;
  font-variant-numeric: tabular-nums;
}

.scan__aviso,
.scan__vacio,
.scan__detalle {
  margin: 0;
  font-size: 0.78rem;
  line-height: 1.35;
  opacity: 0.85;
}

.scan__detalle {
  word-break: break-all;
}

.scan__mandos {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 0.5rem;
}

.scan__crudo {
  max-height: 22vh;
  overflow: auto;
  margin: 0;
  font-size: 0.7rem;
  white-space: pre-wrap;
  word-break: break-word;
}

.scan__boton {
  font: inherit;
  font-size: 0.8rem;
  padding: 0.35rem 0.7rem;
  border: 1px solid #888;
  border-radius: 6px;
  background: #222;
  color: #fff;
}

h2 {
  margin: 0 0 0.4rem;
  font-size: 0.95rem;
}
</style>
