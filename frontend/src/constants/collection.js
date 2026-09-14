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
 * que necesitan traducirla. `CatalogView` y `CollectionView` siguen con su copia
 * local: unificarlas es un cambio de esas dos vistas y no toca en este hito.
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
