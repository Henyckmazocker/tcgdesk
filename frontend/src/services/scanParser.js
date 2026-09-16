/**
 * El parser del bloque de la esquina de una carta de Magic.
 *
 * Entra lo que devuelve `TextRecognition.processImage()` —`{ text, blocks }`,
 * con sus líneas y sus cajas— y sale la lectura que consume `scan_resolve`:
 *
 *     { name, setCode, collectorNumber, rarity, language }
 *
 * Aquí **no se identifica ninguna carta**. Eso lo hace `CardResolver` en PHP, en
 * cuatro pasos y con la regla «ante la duda, conflicto», y esa frontera es lo
 * que impide que el escáner tenga sus propias reglas de identificación. Este
 * fichero solo traduce lo que está impreso al vocabulario de la base de datos.
 *
 * EL BLOQUE QUE SE LEE. Desde el marco M15 (2014-2015) la esquina inferior
 * izquierda de una carta son dos líneas:
 *
 *     0123/0281 R
 *     BLB · EN · 🖌 Nombre del Artista
 *
 * Antes de eso no hay bloque: el número de coleccionista aparece por primera vez
 * en *Exodus* (1998) y toda carta anterior no tiene ninguno impreso. Por eso
 * **el caso de que no case no es un error**: es la mitad del catálogo. Cuando no
 * casa, esto devuelve solo el `name` y deja que resuelvan los pasos 3 y 4 del
 * resolvedor, que es exactamente para lo que existen.
 *
 * Y EL NOMBRE VA SIEMPRE QUE SE TENGA, aunque el número se haya leído entero.
 * No es redundancia: el paso 2 de `CardResolver` (`CardResolver.php:152-163`)
 * contrasta el nombre contra el par `(set, número)` y manda a conflicto si
 * discrepan. Con OCR eso es un regalo —un dígito mal leído apunta a otra carta
 * **que existe**, y sin el contraste se escribiría en la colección en silencio—,
 * así que quitar el nombre «porque ya tenemos el número» sería quitar la única
 * red que hay debajo.
 */

import { idiomaDesdeCodigoImpreso, rarezaDesdeLetraImpresa } from '@/constants/collection'

/**
 * Las dos regex **ya no son las del plan**, y el motivo está medido, no supuesto
 * (plan enmendado el 2026-09-15). Las del plan se escribieron contra la carta
 * ideal —`BLB · EN · 🖌 Artista`— y **ML Kit no entrega eso**: sobre cartas
 * reales de este proyecto devolvió `2X2 EN ScoTT M. FIscHER`, **sin el punto
 * medio**, y `155/331C` tan a menudo como `155/331 C`. Con los patrones del plan:
 *
 *   155/331 C                 → NUMERO  ✅
 *   155/331C                  → NUMERO  ❌  el `\s+` era obligatorio
 *   2X2 EN ScoTT M. FIscHER   → EDICION ❌  la línea BUENA no casaba nunca
 *   Ilust. Rob Alexander      → EDICION ⚠️  set=«Ilust», idioma=«Rob»
 *
 * Ese último es el que se veía en pantalla: el punto de la abreviatura española
 * «Ilust.» hacía de separador y `[A-Z]{2,3}` se tragaba cualquier palabra corta,
 * así que el idioma **aparecía y desaparecía** según el OCR leyera `Ilust.` o
 * `Ilust`. Dos cambios lo cierran:
 *
 *  - **El separador admite espacio**, porque es lo que el OCR entrega de verdad.
 *  - **El idioma se ancla a los once códigos que existen** y deja de ser
 *    `[A-Z]{2,3}`. Esto es lo que mata el falso positivo de raíz: por muy laxo
 *    que sea el separador, `Rob` ya no puede pasar por idioma. La lista se
 *    escribe aquí y no se deriva de `IDIOMA_POR_CODIGO_IMPRESO` a propósito: una
 *    regex construida en tiempo de ejecución desde un objeto es más difícil de
 *    leer y de comparar con la spec que once códigos a la vista. Si se añade un
 *    idioma, se tocan los dos sitios — y hay un test que lo exige.
 *
 * La rigidez que queda **sigue siendo la funcionalidad**: un patrón laxo no deja
 * de fallar, falla escribiendo una carta equivocada. `LINEA_NUMERO` sigue anclada
 * al final (`$`) para que una línea que arrastre basura del fondo no cuele su
 * rareza, y `LINEA_EDICION` no lo va porque después del idioma viene el artista.
 *
 * `[CURMSTBL]` acepta una `B` para la que no hay traducción (ver
 * `RAREZA_POR_LETRA_IMPRESA`): la línea casa, el número se aprovecha y la rareza
 * sale `null`.
 */
export const LINEA_NUMERO = /^\s*(\d+)([a-z★]?)\s*\/\s*\d+\s*([CURMSTBL])\s*$/i

/** Los once códigos de idioma impresos. Anclar aquí es lo que impide que una
 *  palabra cualquiera del nombre del artista pase por idioma. */
const CODIGOS_IDIOMA = 'EN|ES|FR|DE|IT|PT|JA|KO|RU|ZHS|ZHT'

/**
 * **Sin la bandera `i`, y esto no es un detalle: es lo que impide escribir el
 * idioma equivocado en la colección.** (Corregido el 2026-09-15, tras escanear
 * cartas españolas de marco viejo.)
 *
 * Admitir el **espacio** como separador —que hubo que hacerlo, porque es lo que
 * el OCR entrega— abrió una puerta que con el separador rígido estaba cerrada:
 * el texto de reglas de una carta en español está lleno de palabras de dos
 * letras que **son códigos de idioma válidos**. Medido sobre 462 líneas reales:
 *
 *   «Busca en tu biblioteca hasta dos»      → set=Busca,  idioma=EN  ❌
 *   «cartas de tierra básica, muéstralas»   → set=cartas, idioma=DE  ❌
 *   «una de ellas en el campo de»           → set=una,    idioma=DE  ❌
 *
 * Una carta española se habría guardado como **inglesa o alemana, en silencio**,
 * que es exactamente la clase de fallo que este plan existe para evitar.
 *
 * La defensa es que **los códigos van impresos en mayúsculas** —`WOC•EN`,
 * `2X2 EN`, `BLB · EN`— y la prosa no. Quitar la `i` mató los seis falsos
 * positivos y conservó los siete aciertos reales, comprobado también contra las
 * sesiones anteriores: 8 de 8, sin pérdidas.
 */
export const LINEA_EDICION = new RegExp(
  `^\\s*([A-Z0-9]{3,6})\\s*[·•.\\-\\s]\\s*(${CODIGOS_IDIOMA})\\b`
)

/**
 * La **forma nueva** del bloque, la de 2022 en adelante (plan enmendado el
 * 2026-09-15). Wizards dejó de imprimir el total de la edición y puso la rareza
 * **delante**:
 *
 *   forma vieja (hasta ~2021)      forma nueva (2022+)
 *   0123/0281 R                    R 0131
 *
 * Medido sobre *Sanctum Weaver* (`WOC`, 2023): de 286 líneas leídas por ML Kit,
 * `LINEA_NUMERO` casó **cero**, porque busca una barra que esas cartas no
 * imprimen. El catálogo confirma la lectura: `collector_number` 131, `rarity`
 * rare, y el OCR entregó `R O131` / `RO131`.
 *
 * El grupo del número admite `O`, `l` e `I` porque el OCR los confunde con `0` y
 * `1` a esa altura de tipo; `corregirDigitos()` los traduce **solo dentro de este
 * grupo**, nunca en el resto de la línea — si se aplicase al texto entero,
 * convertiría el nombre del artista en números.
 *
 * **El espacio entre la rareza y el número es opcional** (`RO131` se leyó tal
 * cual), y el ancla `$` del final sigue siendo obligatoria: sin ella, la `R` de
 * cualquier palabra suelta seguida de cifras colaría.
 *
 * **Dos restricciones que NO están en la forma vieja, y las dos cazan falsos
 * positivos medidos:**
 *
 *  - **La rareza es `[CURMS]`, sin `T`/`B`/`L`.** Esas tres solo existen en
 *    marcos antiguos, que imprimen la forma vieja; admitirlas aquí no gana un
 *    solo caso real y sí abre la puerta a basura.
 *  - **El número lleva de 3 a 5 dígitos**, porque esta forma los imprime
 *    **rellenos de ceros** (`0131`, nunca `131`). Con `{2,5}` colaba `L99`, que
 *    es lo que queda del copyright `TM & © 1999-2002 Wizards of the Coast` de las
 *    cartas viejas cuando el OCR lo destroza: habría escrito rareza *bonus* y
 *    número 99 sobre una carta que no es ninguna de las dos cosas.
 *
 * > ⚠️ El otro falso amigo de este bloque es el `0/2` que aparece justo debajo:
 * > es la **fuerza/resistencia** de la criatura, no el número de coleccionista.
 * > Por eso este patrón NO admite barras, y por eso `LINEA_NUMERO` exige el total
 * > de la edición detrás de la suya.
 */
export const LINEA_RAREZA_NUMERO = /^\s*([CURMS])\s*([0-9OIl]{3,5})([a-z★]?)\s*$/

/** `O`→`0` y `l`/`I`→`1`, las dos confusiones que comete el OCR con el tipo de 6
 *  puntos de la esquina. Se aplica SOLO al grupo numérico. */
function corregirDigitos(texto) {
  return String(texto).replace(/[OIl]/g, (c) => (c === 'O' ? '0' : '1'))
}

/**
 * La tercera traducción, que no es una tabla sino una regla: **el número impreso
 * lleva ceros a la izquierda y la base de datos no.**
 * `mtg_printing.collector_number` es `VARCHAR(16)` y guarda `117`, no `0117`, así
 * que mandar el número tal cual impreso no casaría ni una carta.
 *
 * Los ceros se quitan **conservando el sufijo**, que es justo el motivo de que
 * la columna no sea numérica: existen `12a`, `117★` y `S1`. (El `S1` no llega
 * hasta aquí —`LINEA_NUMERO` exige empezar por dígito— pero explica por qué esto
 * no puede ser un `parseInt`.)
 *
 * Un número de solo ceros conserva uno: `parseInt` daría `0` y `''` sería peor.
 *
 * @param {string|null|undefined} impreso el número tal cual se leyó (`0012a`)
 * @returns {string|null} el número como lo guarda la BD (`12a`), o `null`
 */
export function normalizarNumeroImpreso(impreso) {
  if (impreso === null || impreso === undefined) return null
  const casa = /^\s*(\d+)\s*([a-z★]?)\s*$/i.exec(String(impreso))
  if (!casa) return null
  // El lookahead es lo que salva el `0000`: quita ceros mientras quede un dígito
  // detrás, nunca el último.
  const digitos = casa[1].replace(/^0+(?=\d)/, '')
  return `${digitos}${casa[2].toLowerCase()}`
}

/**
 * Todas las líneas de todos los bloques, en una sola lista y con su caja.
 *
 * Se trabaja **por líneas y no por bloques**: ML Kit agrupa en un bloque lo que
 * está pegado, así que las dos líneas de la esquina suelen venir en el mismo
 * bloque —y su `text` unido por `\n`— mientras que el nombre viene en otro. Una
 * regex anclada a `^`/`$` contra el texto de un bloque de dos líneas no casaría
 * nunca.
 *
 * Se defiende de lo que falte: un bloque sin `lines` y una línea sin
 * `boundingBox` son formas que el plugin admite en su tipado, y aquí llega lo
 * que haya leído una cámara movida.
 */
function aplanarLineas(resultado) {
  const bloques = Array.isArray(resultado?.blocks) ? resultado.blocks : []
  const lineas = []

  for (const bloque of bloques) {
    const propias = Array.isArray(bloque?.lines) ? bloque.lines : []
    for (const linea of propias) {
      const texto = typeof linea?.text === 'string' ? linea.text : ''
      if (!texto.trim()) continue
      lineas.push({
        texto,
        // `Infinity` y no `0` para las líneas sin caja: sin coordenada no se
        // puede afirmar que estén arriba, y `0` las coronaría como nombre.
        arriba: typeof linea?.boundingBox?.top === 'number' ? linea.boundingBox.top : Infinity
      })
    }
  }

  return lineas
}

/**
 * ¿Puede esta línea ser el nombre de la carta?
 *
 * Es un filtro de descarte, no de reconocimiento: no hay forma de saber si una
 * cadena es un nombre de carta sin el catálogo, y consultarlo aquí sería
 * reimplementar el paso 3 del resolvedor en JavaScript. Lo único que se descarta
 * es lo que **seguro** no es un nombre: lo que no tiene ni una letra (el coste
 * de maná, los números sueltos, los símbolos del marco) y lo que mide menos de
 * dos caracteres.
 */
/**
 * Los scripts en los que puede estar escrito un título de carta.
 *
 * **Es la lista de `IDIOMAS` traducida a alfabetos, y no una más larga por si
 * acaso.** Hasta el 2026-09-16 esto era `/[a-zà-ÿ]/i`, o sea «que lleve una
 * letra latina», y eso **descartaba el título entero de las cartas japonesas,
 * chinas, coreanas y rusas**: `クローンの軍勢` no tiene ni una. Medido capturando
 * el store del Realme, el escáner acababa tomando por nombre la línea del
 * artista (`sld·jp 新川洋司/yon shinkawa`) o el texto de reglas, porque son las
 * que sí llevan algo latino. El título no salía ni una vez.
 *
 * `Phyrexian` no está y no es un olvido: no es un script Unicode —se imprime
 * con glifos propios— así que no hay rango que poner.
 */
const SCRIPTS_DE_TITULO =
  /[\p{Script=Latin}\p{Script=Han}\p{Script=Hiragana}\p{Script=Katakana}\p{Script=Hangul}\p{Script=Cyrillic}]/u

/**
 * Los scripts en los que **un solo carácter ya es un nombre entero**.
 *
 * El mínimo de dos caracteres es correcto para un alfabeto —una `t` suelta del
 * OCR no es un título— y **falso para el japonés**: medido contra la BD el
 * 2026-09-16, hay **1.630 filas con nombre de un carácter**, y son justo las
 * cartas que más se escanean — 山 *Mountain* (418), 島 *Island* (411),
 * 沼 *Swamp* (401), 森 *Forest* (400). Aplicarles el mínimo las descarta a todas
 * sin un solo error visible.
 */
const SCRIPTS_DE_UN_CARACTER =
  /[\p{Script=Han}\p{Script=Hiragana}\p{Script=Katakana}\p{Script=Hangul}]/u

function puedeSerNombre(texto) {
  const limpio = texto.trim()
  if (!limpio) return false
  if (SCRIPTS_DE_UN_CARACTER.test(limpio)) return true
  if (limpio.length < 2) return false
  return SCRIPTS_DE_TITULO.test(limpio)
}

/**
 * El nombre es **la línea más alta** de las que no son la esquina.
 *
 * Es una heurística y conviene que esté dicho: en una carta el título va arriba
 * del todo, encima del arte, y el resto del texto (tipo, reglas, esquina) queda
 * por debajo. No se filtra por tamaño de letra ni por posición absoluta porque
 * eso dependería de a qué distancia se sostenga el móvil.
 *
 * Lo que sí es seguro es el empate: si ninguna línea trae caja —`Infinity` para
 * todas— el orden se queda como vino, o sea el orden de lectura de ML Kit, que
 * es de arriba abajo. El `sort` de JavaScript es estable desde ES2019.
 */
function elegirNombre(lineas) {
  const candidatas = lineas
    .filter((l) => !LINEA_NUMERO.test(l.texto) && !LINEA_EDICION.test(l.texto))
    .filter((l) => puedeSerNombre(l.texto))

  if (!candidatas.length) return null

  const masAlta = [...candidatas].sort((a, b) => a.arriba - b.arriba)[0]
  return masAlta.texto.trim()
}

/**
 * Lee una detección de ML Kit y devuelve la lectura para `scan_resolve`.
 *
 * Los cinco campos salen siempre, con `null` en lo que no se haya podido leer:
 * una forma estable es lo que permite que el menú del M3 pinte la fila sin
 * preguntarse qué claves existen, y `null` es lo que el contrato espera para
 * «esto no se sabe» (nunca `''`, que el backend leería como un valor).
 *
 * @param {{text?: string, blocks?: Array}|null} resultado lo que devuelve
 *   `TextRecognition.processImage()`
 * @returns {{name: string|null, setCode: string|null, collectorNumber: string|null,
 *   rarity: string|null, language: string|null}}
 */
export function parsearLectura(resultado) {
  const lectura = {
    name: null,
    setCode: null,
    collectorNumber: null,
    rarity: null,
    language: null
  }

  const lineas = aplanarLineas(resultado)
  if (!lineas.length) return lectura

  for (const { texto } of lineas) {
    const numero = LINEA_NUMERO.exec(texto)
    // Solo la PRIMERA línea que case manda. Una segunda casando es una carta
    // vecina metiéndose en el fotograma, y elegir entre las dos sería adivinar.
    if (numero && lectura.collectorNumber === null) {
      lectura.collectorNumber = normalizarNumeroImpreso(`${numero[1]}${numero[2]}`)
      lectura.rarity = rarezaDesdeLetraImpresa(numero[3])
      continue
    }

    // La forma NUEVA del bloque, y va después de la vieja a propósito: la vieja
    // es más específica —exige la barra y el total— así que si una línea casa
    // las dos, gana la que trae más información.
    const rarezaNumero = LINEA_RAREZA_NUMERO.exec(texto)
    if (rarezaNumero && lectura.collectorNumber === null) {
      lectura.collectorNumber = normalizarNumeroImpreso(
        `${corregirDigitos(rarezaNumero[2])}${rarezaNumero[3]}`
      )
      lectura.rarity = rarezaDesdeLetraImpresa(rarezaNumero[1])
      continue
    }

    const edicion = LINEA_EDICION.exec(texto)
    if (edicion && lectura.setCode === null) {
      // El código de edición se manda en MAYÚSCULAS porque así lo guarda
      // `mtg_set.code`. Y va aunque no sea el `code` de MTGJSON —promos y
      // ediciones especiales divergen—: entonces el paso 2 no casará y la fila
      // caerá al paso 3 por nombre, que es el comportamiento correcto y no un
      // fallo del OCR.
      lectura.setCode = edicion[1].toUpperCase()
      lectura.language = idiomaDesdeCodigoImpreso(edicion[2])
    }
  }

  lectura.name = elegirNombre(lineas)

  return lectura
}

/**
 * ¿Se leyó el bloque de la esquina, o esto va a resolverse por nombre?
 *
 * Lo usa la vista para decirlo en pantalla en vez de dejar cuatro huecos vacíos
 * que parecen un fallo. Basta con el par `(set, número)`: es lo que el paso 2
 * necesita, y la rareza y el idioma son contraste, no identidad.
 */
export function tieneBloqueDeEsquina(lectura) {
  return Boolean(lectura?.setCode && lectura?.collectorNumber)
}

/** ¿Hay algo que enseñar? Un fotograma movido no lee nada y no es un error. */
export function lecturaVacia(lectura) {
  return !lectura?.name && !tieneBloqueDeEsquina(lectura)
}
