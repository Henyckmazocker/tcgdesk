/**
 * El vocabulario de la colección, en un solo sitio.
 *
 * Los tres primeros no son etiquetas decorativas: `finish`, `language` y
 * `condition` están dentro del `UNIQUE KEY uq_item`, así que un valor mal
 * escrito no da un error visible — crea una línea nueva y parte en dos una carta
 * que el usuario cree tener junta. Los valores son exactamente los de los
 * objetos de valor del backend (`Finish`, `CardLanguage`, `Condition`), y por
 * eso viven aquí y no repetidos en cada vista: desde M4 los escriben tres sitios
 * (la colección, el catálogo y la ficha de carta) en vez de uno.
 */

/** `etched` es un acabado real, con precio propio: no es "un foil raro". */
export const ACABADOS = [
  { label: 'Normal', value: 'normal' },
  { label: 'Foil', value: 'foil' },
  { label: 'Etched', value: 'etched' }
]

/** Los idiomas van con el nombre largo de MTGJSON, que es lo que guarda la BD. */
export const IDIOMAS = [
  { label: 'Inglés', value: 'English' },
  { label: 'Español', value: 'Spanish' },
  { label: 'Francés', value: 'French' },
  { label: 'Alemán', value: 'German' },
  { label: 'Italiano', value: 'Italian' },
  { label: 'Portugués (Brasil)', value: 'Portuguese (Brazil)' },
  { label: 'Japonés', value: 'Japanese' },
  { label: 'Coreano', value: 'Korean' },
  { label: 'Ruso', value: 'Russian' },
  { label: 'Chino simplificado', value: 'Chinese Simplified' },
  { label: 'Chino tradicional', value: 'Chinese Traditional' },
  { label: 'Phyrexiano', value: 'Phyrexian' }
]

/**
 * El código de idioma **impreso en la carta** → el `value` de `IDIOMAS`.
 *
 * Vive aquí, pegado a la lista de arriba, y **no** en `services/scanParser.js`,
 * que es su único consumidor. El motivo es que el destino de cada entrada no es
 * una cadena cualquiera: es literalmente un `value` de `IDIOMAS`, o sea un
 * nombre largo de MTGJSON que viaja **dentro del `UNIQUE KEY uq_item`**. Con la
 * tabla en el parser, el día que la BD renombrara un idioma —`Portuguese
 * (Brazil)` es el candidato evidente— esto seguiría apuntando al nombre viejo
 * **sin dar ningún error**: la carta escaneada se escribiría en una línea propia
 * y el usuario vería su colección partida en dos sin saber por qué.
 *
 * `Phyrexian` no está, y no es un olvido: es un idioma de la BD que **no tiene
 * código en el bloque de la esquina**, así que no hay nada impreso que traducir.
 *
 * `ZHS` y `ZHT` son los únicos códigos de tres letras, y por eso la
 * `LINEA_EDICION` del parser acepta de dos a tres.
 */
export const IDIOMA_POR_CODIGO_IMPRESO = Object.freeze({
  EN: 'English',
  ES: 'Spanish',
  FR: 'French',
  DE: 'German',
  IT: 'Italian',
  PT: 'Portuguese (Brazil)',
  JA: 'Japanese',
  KO: 'Korean',
  RU: 'Russian',
  ZHS: 'Chinese Simplified',
  ZHT: 'Chinese Traditional'
})

/**
 * @param {string|null|undefined} codigo el código tal cual salió del OCR
 * @returns {string|null} el `value` de `IDIOMAS`, o `null` si no es uno de los
 *   once. **Null y no un valor por defecto**: inventarse un idioma es peor que
 *   no mandar ninguno, porque el idioma forma parte de la identidad de la línea
 *   de colección y un `English` supuesto sobre una carta alemana no se ve.
 */
export function idiomaDesdeCodigoImpreso(codigo) {
  if (!codigo) return null
  return IDIOMA_POR_CODIGO_IMPRESO[String(codigo).toUpperCase()] ?? null
}

/**
 * El `value` de `IDIOMAS` → el **script de ML Kit** con el que hay que leer esa
 * carta (`@capacitor-mlkit/text-recognition`, que ofrece `LATIN`, `CHINESE`,
 * `JAPANESE`, `KOREAN` y `DEVANAGARI`).
 *
 * Vive aquí, pegado a la lista de arriba, **por el mismo motivo que
 * `IDIOMA_POR_CODIGO_IMPRESO`**: la clave no es una cadena cualquiera, es un
 * `value` de `IDIOMAS`. El día que la lista cambie —añadir un idioma, renombrar
 * `Portuguese (Brazil)`— esto tiene que cambiar al lado y no en una vista.
 *
 * **Solo están los que NO son latinos.** El resto —las ocho lenguas latinas y
 * `Phyrexian`, que se imprime en un alfabeto inventado que ningún modelo lee—
 * caen al `LATIN` por defecto de `scriptDeOcr()`: enumerarlos sería una lista
 * que se olvida de actualizar el día que entre un idioma nuevo, y el defecto
 * correcto para un idioma nuevo desconocido es el latino.
 */
export const SCRIPT_OCR_POR_IDIOMA = Object.freeze({
  Japanese: 'JAPANESE',
  Korean: 'KOREAN',
  'Chinese Simplified': 'CHINESE',
  'Chinese Traditional': 'CHINESE'
})

/** El script latino, que es el defecto y el que lee ocho de los once idiomas. */
export const SCRIPT_OCR_POR_DEFECTO = 'LATIN'

/**
 * El script de ML Kit para un idioma de `IDIOMAS`.
 *
 * **Sale del SELECTOR, nunca del idioma que detecta el resolvedor**, y no es una
 * preferencia: el OCR ocurre **antes** de la resolución, así que cuando hay que
 * elegir el modelo la detección todavía no existe. Es el único punto de toda la
 * cadena donde el selector de idioma es insustituible.
 *
 * @param {string|null|undefined} idioma el `value` de `IDIOMAS`
 * @returns {string} el script, con `LATIN` por defecto — un idioma que no esté
 *   en el mapa se lee en latino, que es lo que hacía el escáner entero hasta el
 *   M9 y nunca es peor que no leer nada.
 */
export function scriptDeOcr(idioma) {
  if (!idioma) return SCRIPT_OCR_POR_DEFECTO
  return SCRIPT_OCR_POR_IDIOMA[idioma] ?? SCRIPT_OCR_POR_DEFECTO
}

/** La escala de Cardmarket, la misma del ENUM y del objeto de valor Condition. */
export const CONDICIONES = [
  { label: 'Mint', value: 'M' },
  { label: 'Near Mint', value: 'NM' },
  { label: 'Excellent', value: 'EX' },
  { label: 'Good', value: 'GD' },
  { label: 'Light Played', value: 'LP' },
  { label: 'Played', value: 'PL' },
  { label: 'Poor', value: 'PO' }
]

/**
 * Los valores por defecto del camino de un clic.
 *
 * Los aplica el backend cuando el payload no los trae (`CollectionItem`), así
 * que el cliente NO los manda al añadir rápido; están aquí solo para que el
 * selector avanzado abra ya puesto en lo mismo que habría hecho el clic simple.
 */
export const POR_DEFECTO = Object.freeze({
  finish: 'normal',
  language: 'English',
  condition: 'NM',
  quantity: 1
})

/**
 * La escala de rareza, en el orden en que la enseña el backend.
 *
 * Vive aquí desde M5 porque el dashboard y `/sets` son el tercer y cuarto sitio
 * que necesitan traducirla. Desde el 2026-09-14 es la ÚNICA definición: las
 * copias locales de `CatalogView` y `CollectionView` se borraron y las dos
 * vistas importan de aquí, así que `/catalog`, `/collection` y `/wishlist`
 * listan las seis rarezas en este mismo orden, de mítica a común.
 */
export const RAREZAS = [
  { label: 'Mítica', value: 'mythic' },
  { label: 'Rara', value: 'rare' },
  { label: 'Infrecuente', value: 'uncommon' },
  { label: 'Común', value: 'common' },
  { label: 'Especial', value: 'special' },
  { label: 'Bonus', value: 'bonus' }
]

export function etiquetaRareza(valor) {
  return RAREZAS.find((r) => r.value === valor)?.label ?? valor
}

/**
 * La letra de rareza **impresa en la esquina** → el `value` de `RAREZAS`, que es
 * a su vez el ENUM de `mtg_printing.rarity`.
 *
 * Va aquí por lo mismo que `IDIOMA_POR_CODIGO_IMPRESO` y no por simetría: el
 * destino de cada entrada es un `value` de la lista de arriba, y separarlos es
 * dejar que se desincronicen en silencio. Y la letra **no es** el valor del
 * ENUM: mandar `R` a `scan_resolve` no resolvería a *rare*, no casaría nada.
 *
 * `T` y `L` solo salen en cartas antiguas y las dos caen en `bonus`, que es el
 * cajón donde MTGJSON deja lo que no es ninguna de las cinco rarezas de
 * siempre. **No hay entrada para `B`** aunque la regex del parser acepte esa
 * letra: el plan no le dio destino y ponerle uno a ojo escribiría una rareza
 * inventada, así que una `B` deja la rareza a `null` y el número sigue valiendo.
 */
export const RAREZA_POR_LETRA_IMPRESA = Object.freeze({
  C: 'common',
  U: 'uncommon',
  R: 'rare',
  M: 'mythic',
  S: 'special',
  T: 'bonus',
  L: 'bonus'
})

/**
 * @param {string|null|undefined} letra la letra tal cual salió del OCR
 * @returns {string|null} el `value` de `RAREZAS`, o `null` si la letra no tiene
 *   destino. La rareza es un dato de contraste, no de identidad: sin ella el
 *   resolvedor sigue casando por `(set, número)`, así que `null` no rompe nada.
 */
export function rarezaDesdeLetraImpresa(letra) {
  if (!letra) return null
  return RAREZA_POR_LETRA_IMPRESA[String(letra).toUpperCase()] ?? null
}

/**
 * Los cinco colores de maná, en orden WUBRG (el canónico de MTG y el que usa
 * el backend).
 *
 * Centralizada el 2026-09-14 por el mismo motivo que `RAREZAS`: estaba copiada
 * byte a byte en `CatalogView`, `CollectionView` y `WishlistView`. No lleva
 * `etiquetaColor()` porque nadie traduce un color suelto todavía: las tres
 * vistas solo la usan como `:options` de su filtro.
 */
export const COLORES = [
  { label: 'Blanco', value: 'W' },
  { label: 'Azul', value: 'U' },
  { label: 'Negro', value: 'B' },
  { label: 'Rojo', value: 'R' },
  { label: 'Verde', value: 'G' }
]

export function etiquetaAcabado(valor) {
  return ACABADOS.find((a) => a.value === valor)?.label ?? valor
}

/**
 * Las siete zonas de `mtg_deck_card.board`, en el orden del ENUM.
 *
 * Viven aquí y no en las vistas de mazo por lo mismo que los tres primeros
 * bloques de este fichero: son valores que viajan DENTRO de `uq_deck_card`, así
 * que una zona mal escrita no da error visible — parte una carta en dos líneas.
 *
 * **`tokens` no cuenta para nada** y por eso lleva su marca: ni consume
 * colección, ni suma al valor, ni cuenta para el tamaño del mazo. El backend ya
 * lo excluye de todos los agregados (`Board::esPoseible()`); la UI solo tiene
 * que no sumarlo donde no toca ni pedir cuentas de lo que no tienes.
 */
export const ZONAS = [
  { label: 'Principal', value: 'main', cuenta: true },
  { label: 'Sideboard', value: 'side', cuenta: true },
  { label: 'Comandante', value: 'commander', cuenta: true },
  { label: 'Compañero', value: 'companion', cuenta: true },
  { label: 'Planos', value: 'planes', cuenta: true },
  { label: 'Esquemas', value: 'schemes', cuenta: true },
  { label: 'Tokens', value: 'tokens', cuenta: false }
]

/**
 * Los tres estados de un mazo, que **no son simétricos**: solo `built` consume
 * colección, `building` calcula lo que falta y `dismantled` es puro archivo.
 * Escribir «todo lo que no esté desmontado» por inercia metería los mazos a
 * medias en el consumo y avisaría de conflictos que no existen.
 */
export const ESTADOS_MAZO = [
  { label: 'Construido', value: 'built', severity: 'success' },
  { label: 'En construcción', value: 'building', severity: 'info' },
  { label: 'Desmontado', value: 'dismantled', severity: 'secondary' }
]

/**
 * Los cuatro estatus de `mtg_legality.status`, con su etiqueta y su severidad.
 *
 * **`not_legal` es la AUSENCIA de fila**, no un valor que la ingesta escriba:
 * `SELECT DISTINCT status` sobre la tabla devuelve solo `legal`, `banned` y
 * `restricted`. El backend lo traduce (`LegalityStatus::desdeFila()`) y aquí
 * llega ya como estatus.
 *
 * `legal` está en la lista por completitud pero **no se pinta**: el backend
 * manda solo lo que hay que marcar, porque marcar las cien cartas legales de un
 * Commander sería ruido y no aviso.
 *
 * Las severidades son las de `CardView.vue`, que es el otro —y hasta ahora
 * único— sitio de la app que lee `mtg_legality`: rojo lo prohibido, gris lo que
 * no se puede jugar ahí. `restricted` va en ámbar porque no es ninguna de las
 * dos cosas: se puede jugar, con una sola copia. **Se marca y ya**: la regla de
 * «máximo 1 copia» no se implementa, porque eso sería validar y esto avisa.
 */
export const LEGALIDADES = [
  { label: 'Legal', value: 'legal', severity: 'success' },
  { label: 'Prohibida', value: 'banned', severity: 'danger' },
  { label: 'Restringida', value: 'restricted', severity: 'warn' },
  { label: 'No permitida', value: 'not_legal', severity: 'secondary' }
]

export function etiquetaLegalidad(valor) {
  return LEGALIDADES.find((l) => l.value === valor)?.label ?? valor
}

export function severidadLegalidad(valor) {
  return LEGALIDADES.find((l) => l.value === valor)?.severity ?? 'secondary'
}

export function etiquetaZona(valor) {
  return ZONAS.find((z) => z.value === valor)?.label ?? valor
}

export function etiquetaEstadoMazo(valor) {
  return ESTADOS_MAZO.find((e) => e.value === valor)?.label ?? valor
}

export function severidadEstadoMazo(valor) {
  return ESTADOS_MAZO.find((e) => e.value === valor)?.severity ?? 'secondary'
}

export function etiquetaCondicion(valor) {
  return CONDICIONES.find((c) => c.value === valor)?.label ?? valor
}

/**
 * Las condiciones **de peor a mejor**, que es el orden en el que el modo «coge
 * lo que tenga» elige: las cartas buenas se guardan y las jugadas se juegan.
 * Se deriva de `CONDICIONES` en vez de reescribirse para que añadir un grado
 * nuevo no obligue a acordarse de dos sitios.
 */
export const CONDICIONES_DE_PEOR_A_MEJOR = CONDICIONES.map((c) => c.value).reverse()
