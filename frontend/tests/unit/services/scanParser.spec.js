import { describe, expect, it } from 'vitest'

import {
  LINEA_EDICION,
  LINEA_NUMERO,
  LINEA_RAREZA_NUMERO,
  lecturaVacia,
  normalizarNumeroImpreso,
  parsearLectura,
  tieneBloqueDeEsquina
} from '@/services/scanParser'
import {
  IDIOMAS,
  RAREZAS,
  idiomaDesdeCodigoImpreso,
  rarezaDesdeLetraImpresa
} from '@/constants/collection'
import lecturasFixture from '../../fixtures/scan_mlkit_lecturas.json'

/**
 * ⚠️ LEE ESTO ANTES DE AÑADIR UN CASO: **lo que entra aquí son bloques de ML Kit
 * capturados del móvil, y no se escriben ni se corrigen a mano.**
 *
 * La sección ✅ del «Plan - Escáner de Cartas por Cámara» lo exige así, y el
 * motivo el proyecto ya lo pagó con el `deckId` de `/decks`: *una fixture
 * redactada por quien escribió el parser trae los mismos errores que el parser*.
 * La fixture es `tests/fixtures/scan_mlkit_lecturas.json` y su procedencia está
 * en `tests/fixtures/README.md` — sesión del 2026-09-14/15 en un Realme RMX1993,
 * 250 vueltas del bucle, 56 lecturas con texto, 67 vacías, tres cartas.
 *
 * **Las erratas del OCR son el dato.** `Llasura`, `ScoTT M. FIscHER`, `212` por
 * `2X2`, `MO331` por el número: nada de eso se arregla al copiarlo. Arreglarlo
 * convertiría la fixture en una carta ideal, que es justo lo que no existe
 * delante de la cámara.
 *
 * QUÉ ES REAL Y QUÉ NO, dicho sin adornos: `logcat` corta cada mensaje en 4023
 * caracteres, así que el `blocks` con sus `boundingBox` **solo sobrevivió en las
 * lecturas cortas** (tres de las once). El campo `text` —que va primero en el
 * JSON— sobrevivió entero en todas, y de ahí salen las `lineas`, que son
 * literales. Para las lecturas truncadas, este fichero **reconstruye la forma**
 * `{text, blocks}` a partir de esas líneas literales: el CONTENIDO es capturado,
 * el ENVOLTORIO es de aquí. Y esa reconstrucción no se da por buena: el primer
 * `describe` la contrasta contra las tres lecturas que sí traen `blocks` reales.
 *
 * Lo único que sigue sin probarse aquí, y que solo dice el móvil, es el bucle
 * entero: que `captureSample` → fichero → `processImage` devuelva esto a ~2
 * vueltas por segundo. Ese es el `*Hecho cuando:*` del M1 y se ejercita con la
 * cámara, no con vitest.
 */

/** Una lectura de la fixture por su `id`, y revienta si no está: un `id` mal
 *  escrito daría `undefined` y el test pasaría sin probar nada. */
function lecturaReal(id) {
  const encontrada = lecturasFixture.lecturas.find((l) => l.id === id)
  if (!encontrada) throw new Error(`No hay lectura "${id}" en la fixture`)
  return encontrada
}

/**
 * La detección con la forma que consume `parsearLectura()`.
 *
 * Si la captura trae `blocks` reales, se usan **tal cual**, con sus cajas. Si no
 * (mensaje cortado por `logcat`), se envuelve cada línea literal en un bloque de
 * una línea y **sin `boundingBox`** — no inventarse coordenadas es la parte
 * importante: sin caja, `elegirNombre()` cae en el orden de lectura de ML Kit,
 * que es de arriba abajo, en vez de en unos números redondos que nos vendrían
 * bien.
 */
function deteccion(lectura) {
  if (Array.isArray(lectura.blocks)) return { text: lectura.text, blocks: lectura.blocks }
  return {
    text: lectura.text,
    blocks: lectura.lineas.map((texto) => ({ text: texto, lines: [{ text: texto }] }))
  }
}

/** Todas las líneas de todas las lecturas capturadas, tal cual se leyeron. */
function todasLasLineasReales() {
  return lecturasFixture.lecturas.flatMap((l) => l.lineas)
}

function parsearReal(id) {
  return parsearLectura(deteccion(lecturaReal(id)))
}

describe('la fixture es lo que ML Kit devolvió, y la reconstrucción se contrasta', () => {
  it('las lecturas con blocks reales aplanan exactamente a sus `lineas`', () => {
    // Esto es lo que autoriza a reconstruir `{text, blocks}` desde `lineas` en
    // las lecturas que `logcat` cortó: en las que llegaron enteras, el aplanado
    // de `blocks[].lines[].text` y el `text` partido por `\n` COINCIDEN. Si ML
    // Kit dejara de agrupar así, esto salta antes que ningún test de parseo.
    const conBloques = lecturasFixture.lecturas.filter((l) => Array.isArray(l.blocks))
    expect(conBloques.length).toBeGreaterThanOrEqual(3)

    for (const lectura of conBloques) {
      const planas = lectura.blocks.flatMap((b) => (b.lines || []).map((l) => l.text))
      expect(planas).toEqual(lectura.lineas)
      expect(lectura.lineas).toEqual(lectura.text === '' ? [] : lectura.text.split('\n'))
    }
  })

  it('las once lecturas traen su texto literal y su procedencia', () => {
    expect(lecturasFixture.lecturas).toHaveLength(11)
    expect(lecturasFixture._procedencia).toMatch(/Realme RMX1993/)

    for (const lectura of lecturasFixture.lecturas) {
      expect(typeof lectura.text).toBe('string')
      expect(lectura.lineas).toEqual(lectura.text === '' ? [] : lectura.text.split('\n'))
    }
  })
})

describe('carta moderna — Rampant Growth (2X2, marco M15, inglés)', () => {
  it('lee la esquina entera cuando el OCR deja el espacio: `155/331 C`', () => {
    expect(parsearReal('rampant_growth_155_331_C_con_espacio')).toEqual({
      name: 'Rampant Growth',
      setCode: '2X2',
      collectorNumber: '155',
      rarity: 'common',
      language: 'English'
    })
  })

  it('lee la MISMA esquina cuando el OCR se come el espacio: `155/331C`', () => {
    // Este es el caso que la regex del plan (`\s+` antes de la rareza) no casaba
    // nunca, y el OCR lo entrega tan a menudo como el otro. El resultado tiene
    // que ser idéntico al de arriba.
    expect(parsearReal('rampant_growth_155_331C_sin_espacio')).toEqual({
      name: 'Rampant Growth',
      setCode: '2X2',
      collectorNumber: '155',
      rarity: 'common',
      language: 'English'
    })
  })

  it('las dos formas reales de la línea del número casan igual', () => {
    expect(LINEA_NUMERO.exec('155/331 C').slice(1, 4)).toEqual(['155', '', 'C'])
    expect(LINEA_NUMERO.exec('155/331C').slice(1, 4)).toEqual(['155', '', 'C'])
  })

  it('la línea de edición·idioma real NO trae punto medio, y aun así casa', () => {
    // El plan dibujaba `BLB · EN · 🖌 Artista`. ML Kit entrega esto:
    expect(LINEA_EDICION.exec('2X2 EN ScoTT M. FIscHER').slice(1, 3)).toEqual(['2X2', 'EN'])
    expect(LINEA_EDICION.exec('LTR EN MARKO MANEV').slice(1, 3)).toEqual(['LTR', 'EN'])
  })

  it('si el OCR pega el idioma al artista, se pierde la edición y NO se adivina', () => {
    // `2X2 ENScoTT M. FIscHER`: sin separador no hay edición. Queda el número,
    // y la fila cae al paso 3 del resolvedor por nombre — que es un resultado
    // correcto, no un fallo. Inventar aquí el `2X2` sería escribir en la
    // colección una edición que nadie leyó.
    const lectura = parsearReal('rampant_growth_edicion_pegada_al_artista')

    expect(lectura.collectorNumber).toBe('155')
    expect(lectura.rarity).toBe('common')
    expect(lectura.setCode).toBeNull()
    expect(lectura.language).toBeNull()
    expect(tieneBloqueDeEsquina(lectura)).toBe(false)
  })

  it('un código de edición mal leído viaja tal cual y lo descarta el paso 2', () => {
    // El OCR leyó `212 EN SCOTE M SscHER`: `2X2` convertido en `212`. El parser
    // no tiene catálogo y no puede saberlo; lo manda, el paso 2 no casa y la
    // carta se resuelve por nombre. Corregirlo aquí sería identificar cartas en
    // JavaScript, que es exactamente la frontera que este fichero no cruza.
    const lectura = parsearReal('rampant_growth_set_mal_leido_212')

    expect(lectura.setCode).toBe('212')
    expect(lectura.collectorNumber).toBe('155')
    expect(lectura.name).toBe('Rampant Growth')
  })

  it('un fotograma movido devuelve solo el nombre y no rompe nada', () => {
    const lectura = parsearReal('rampant_growth_solo_nombre')

    expect(lectura.name).toBe('Rampant Growth')
    expect(tieneBloqueDeEsquina(lectura)).toBe(false)
    expect(lecturaVacia(lectura)).toBe(false)
  })
})

describe('carta moderna — Tom Bombadil (LTR), con la forma NUEVA del bloque', () => {
  /**
   * Este `describe` afirmaba lo contrario hasta el 2026-09-15, y su historia es
   * la lección: daba por **ilegible** el `MO331` de esta carta y esperaba
   * `collectorNumber` y `rarity` a `null`, porque `LINEA_NUMERO` —la única que
   * había entonces— busca `123/456 R` y aquí no hay barra.
   *
   * No era ilegible: era **la forma nueva del bloque**, la de 2022 en adelante,
   * que imprime la rareza delante y sin el total. `MO331` es `M 0331`, y el
   * catálogo lo confirma: **Tom Bombadil en LTR, número 331, rareza mythic**
   * (hay seis impresiones suyas en ese set —234, 331, 685, 745, 745z y 823— y
   * 331 es una de ellas).
   *
   * Se deja escrito porque es la trampa de fondo de todo este parser: **un test
   * que afirma una limitación envejece peor que uno que afirma un
   * comportamiento**. Este llegó a estar en verde certificando un fallo.
   */
  it('lee número y rareza del `MO331`, que es la forma nueva y no un ilegible', () => {
    const lectura = parsearReal('tom_bombadil_numero_ilegible')

    expect(lectura.name).toBe('Tom Bombadil')
    expect(lectura.setCode).toBe('LTR')
    expect(lectura.language).toBe('English')
    // `M` + `O331` → la `O` es un cero mal leído → 0331 → 331.
    expect(lectura.collectorNumber).toBe('331')
    expect(lectura.rarity).toBe('mythic')
    expect(tieneBloqueDeEsquina(lectura)).toBe(true)
  })

  it('`MO331` no casa la forma VIEJA, y `4/4` no casa ninguna de las dos', () => {
    // La forma vieja sigue exigiendo su barra y su total: `MO331` no es asunto
    // suyo, y `4/4` —la fuerza/resistencia— no es de nadie.
    expect(LINEA_NUMERO.test('MO331')).toBe(false)
    expect(LINEA_NUMERO.test('4/4')).toBe(false)
    expect(LINEA_RAREZA_NUMERO.test('4/4')).toBe(false)
  })

  it('el copyright destrozado de una carta vieja NO pasa por número', () => {
    // `L99` es lo que queda de `TM & © 1999-2002 Wizards of the Coast` cuando el
    // OCR lo parte. Con el patrón a `{2,5}` dígitos colaba y escribía rareza
    // *bonus* y número 99; el mínimo de 3 y la rareza acotada a `[CURMS]` lo
    // cierran por partida doble.
    expect(LINEA_RAREZA_NUMERO.test('L99')).toBe(false)
    expect(LINEA_RAREZA_NUMERO.test('R99')).toBe(false)
  })
})

describe('REGRESIÓN — la prosa española no es una edición ni un idioma', () => {
  /**
   * EL SEGUNDO BUG DE ESTE PARSER, y lo introdujo el arreglo del primero.
   *
   * Admitir el **espacio** como separador era obligatorio —el OCR entrega
   * `WOC•EN` pero también `2X2 EN`— y abrió una puerta que el separador rígido
   * tenía cerrada: el texto de reglas de una carta en español está lleno de
   * palabras de dos letras que **son códigos de idioma válidos**. Medido sobre
   * 462 líneas reales de tres cartas españolas de marco viejo, seis falsos
   * positivos distintos.
   *
   * La defensa es que los códigos van impresos **en mayúsculas** y la prosa no,
   * así que `LINEA_EDICION` perdió la bandera `i`. Estos tests son el candado.
   */
  it('«Busca en tu biblioteca» NO es la edición Busca en inglés', () => {
    expect(LINEA_EDICION.test('Busca en tu biblioteca hasta dos')).toBe(false)
    expect(LINEA_EDICION.test('cartas de tierra básica, muéstralas')).toBe(false)
    expect(LINEA_EDICION.test('una de ellas en el campo de')).toBe(false)
  })

  it('y las líneas de verdad, que van en mayúsculas, siguen casando', () => {
    expect(LINEA_EDICION.exec('WOC•EN KIMONAS THEODOSSIOU').slice(1, 3)).toEqual(['WOC', 'EN'])
    expect(LINEA_EDICION.exec('WOC-EN KIMONAS THEODOSSIOU').slice(1, 3)).toEqual(['WOC', 'EN'])
    expect(LINEA_EDICION.exec('2X2 EN ScoTT M. FIscHER').slice(1, 3)).toEqual(['2X2', 'EN'])
  })

  it('un código en minúsculas NO se acepta, aunque parezca inofensivo', () => {
    // Es la tolerancia que causó el fallo. Una carta real nunca lo imprime así,
    // y aceptarlo vuelve a abrir la puerta a la prosa.
    expect(LINEA_EDICION.test('blb · es · Alguien')).toBe(false)
  })
})

describe('REGRESIÓN — `Ilust. Rob Alexander` no es una edición ni un idioma', () => {
  /**
   * EL TEST MÁS IMPORTANTE DE ESTE FICHERO. Es el bug que David vio en la
   * pantalla del móvil: el idioma «me iba cambiando entre la versión francesa y
   * la inglesa» sobre una carta que no tiene idioma impreso en ninguna parte.
   *
   * La causa está medida: el punto de la abreviatura española «Ilust.» hacía de
   * separador y el `[A-Z]{2,3}` del plan se tragaba cualquier palabra corta, así
   * que `Rob` pasaba por código de idioma — y el idioma aparecía y desaparecía
   * según el OCR leyera `Ilust.` o `Ilust`.
   */
  it('así casaba la regex del plan, y por eso existe este describe', () => {
    // La regex ORIGINAL del plan, escrita aquí a propósito y solo aquí: es la
    // caracterización del bug. Si alguien la devolviera al parser, los tests de
    // abajo se ponen rojos. (El plan escribe la clase como `[·•.\-]`; aquí va sin
    // la barra porque es el mismo carácter y ESLint no admite el escape inútil.)
    const LINEA_EDICION_DEL_PLAN = /^\s*([A-Z0-9]{3,6})\s*[·•.-]\s*([A-Z]{2,3})\b/i
    const casaba = LINEA_EDICION_DEL_PLAN.exec('Ilust. Rob Alexander')

    expect(casaba.slice(1, 3)).toEqual(['Ilust', 'Rob'])
  })

  it('la regex de ahora NO casa la línea del ilustrador, con punto o sin él', () => {
    // Las cuatro formas que el OCR entregó de esa misma línea.
    expect(LINEA_EDICION.test('Ilust. Rob Alexander')).toBe(false)
    expect(LINEA_EDICION.test('Ilust Rob Alexander')).toBe(false)
    expect(LINEA_EDICION.test('Ilust, Rob Alexander')).toBe(false)
    expect(LINEA_EDICION.test('Ilst Rob Alexander')).toBe(false)
  })

  it('la lectura real de esa carta sale SIN idioma y SIN edición', () => {
    const lectura = parsearReal('llanura_ilust_con_punto')

    expect(lectura.language).toBeNull()
    expect(lectura.setCode).toBeNull()
  })

  it('ninguna línea capturada inventa una edición: solo salen las tres reales', () => {
    // Barrido sobre TODAS las líneas de las once lecturas. Es el test que cierra
    // el falso positivo de raíz: no basta con que `Ilust.` no case, hace falta
    // que no case nada que no sea una línea de edición·idioma de verdad. Las
    // tres que salen son las que están impresas en las tres cartas (y `212` es
    // el `2X2` mal leído, que se documenta arriba).
    const encontradas = todasLasLineasReales()
      .map((texto) => LINEA_EDICION.exec(texto))
      .filter(Boolean)
      .map((casa) => `${casa[1].toUpperCase()}|${casa[2].toUpperCase()}`)

    expect([...new Set(encontradas)].sort()).toEqual(['212|EN', '2X2|EN', 'LTR|EN'])
  })

  it('ninguna línea capturada inventa un número: solo sale el de la 2X2', () => {
    // La línea del copyright de la Llanura acaba en `331350`, que tiene forma de
    // número de coleccionista y no lo es. El ancla `$` de `LINEA_NUMERO` es lo
    // que lo impide.
    const encontrados = todasLasLineasReales()
      .map((texto) => LINEA_NUMERO.exec(texto))
      .filter(Boolean)
      .map((casa) => `${casa[1]}${casa[2]}|${casa[3].toUpperCase()}`)

    expect([...new Set(encontrados)]).toEqual(['155|C'])
    expect(LINEA_NUMERO.test('TH &O 1993-2002 Wizards of the Coast, Inc. 331350')).toBe(false)
    expect(LINEA_NUMERO.test('TH &O 1993-2002 Wizards of the Coast, Inc: 331/350')).toBe(false)
  })
})

describe('carta anterior a 2015 — Llanura española de marco viejo (~2002)', () => {
  it('devuelve SOLO el nombre, y eso no es un fallo', () => {
    // Es el `*Hecho cuando:*` del M1 para la carta sin bloque de esquina: el
    // número de coleccionista aparece en *Exodus* (1998) y el bloque entero en
    // el marco M15 (2014-2015). Media colección no lo lleva impreso y se
    // resuelve por nombre en los pasos 3 y 4, que existen para esto.
    const lectura = parsearReal('llanura_ilust_con_punto')

    expect(lectura).toEqual({
      name: 'Llanura',
      setCode: null,
      collectorNumber: null,
      rarity: null,
      language: null
    })
    expect(tieneBloqueDeEsquina(lectura)).toBe(false)
    expect(lecturaVacia(lectura)).toBe(false)
  })

  it('tampoco se rompe con la línea de copyright entera delante', () => {
    const lectura = parsearReal('llanura_ilust_sin_punto')

    expect(lectura).toEqual({
      name: 'Llanura',
      setCode: null,
      collectorNumber: null,
      rarity: null,
      language: null
    })
  })

  it('con el nombre mal leído devuelve el nombre mal leído, sin adivinar', () => {
    // `Llasura` y `Tlanura` son lo que el OCR entregó. El parser no tiene
    // catálogo y corregir aquí sería reimplementar el paso 3 en JavaScript: lo
    // honesto es mandar lo leído y que el resolvedor decida (y mande a conflicto
    // si no encuentra nada).
    expect(parsearReal('llanura_degradada_llasura').name).toBe('Llasura')
    expect(parsearReal('llanura_degradada_tlanura').name).toBe('Tlanura')
  })

  it('el nombre sale de la línea MÁS ALTA cuando la captura trae cajas reales', () => {
    // Estas tres lecturas llegaron enteras a `logcat`, con sus `boundingBox` de
    // verdad: aquí `elegirNombre()` sí está eligiendo por coordenada y no por
    // orden de lectura.
    const conCajas = lecturaReal('llanura_ilust_con_punto')
    expect(Array.isArray(conCajas.blocks)).toBe(true)
    expect(conCajas.blocks[0].lines[0].boundingBox.top).toEqual(expect.any(Number))

    expect(parsearLectura(deteccion(conCajas)).name).toBe('Llanura')
  })
})

describe('el fotograma vacío, que fue más de la cuarta parte de la sesión', () => {
  it('67 de las 250 vueltas no leyeron nada, y eso devuelve cinco null', () => {
    const lectura = parsearReal('fotograma_vacio')

    expect(lectura).toEqual({
      name: null,
      setCode: null,
      collectorNumber: null,
      rarity: null,
      language: null
    })
    expect(lecturaVacia(lectura)).toBe(true)
  })
})

describe('traducción 1 — el número impreso lleva ceros a la izquierda y la BD no', () => {
  it('quita los ceros conservando el sufijo', () => {
    // `mtg_printing.collector_number` es VARCHAR(16) y guarda `117`, no `0117`:
    // mandar el número tal cual impreso no casaría ni una carta.
    expect(normalizarNumeroImpreso('0117')).toBe('117')
    expect(normalizarNumeroImpreso('0012a')).toBe('12a')
    expect(normalizarNumeroImpreso('117★')).toBe('117★')
    expect(normalizarNumeroImpreso('117')).toBe('117')
  })

  it('conserva un cero cuando el número es todo ceros, que es lo que un parseInt perdería', () => {
    expect(normalizarNumeroImpreso('0000')).toBe('0')
  })

  it('normaliza el sufijo a minúscula, porque la regex lo acepta en las dos cajas', () => {
    expect(normalizarNumeroImpreso('0012A')).toBe('12a')
  })

  it('devuelve null en lo que no es un número impreso', () => {
    // `S1` existe como número de coleccionista y es parte del motivo de que la
    // columna no sea numérica, pero NO entra por aquí: `LINEA_NUMERO` exige
    // empezar por dígito. Lo que llega y no encaja se descarta, no se adivina.
    expect(normalizarNumeroImpreso('S1')).toBeNull()
    expect(normalizarNumeroImpreso('')).toBeNull()
    expect(normalizarNumeroImpreso(null)).toBeNull()
    expect(normalizarNumeroImpreso(undefined)).toBeNull()
  })
})

describe('traducción 2 — la letra de rareza no es el valor del ENUM', () => {
  it('traduce las cinco letras vivas y las dos de cartas viejas', () => {
    expect(rarezaDesdeLetraImpresa('C')).toBe('common')
    expect(rarezaDesdeLetraImpresa('U')).toBe('uncommon')
    expect(rarezaDesdeLetraImpresa('R')).toBe('rare')
    expect(rarezaDesdeLetraImpresa('M')).toBe('mythic')
    expect(rarezaDesdeLetraImpresa('S')).toBe('special')
    expect(rarezaDesdeLetraImpresa('T')).toBe('bonus')
    expect(rarezaDesdeLetraImpresa('L')).toBe('bonus')
  })

  it('cada destino es un value de RAREZAS, que es el ENUM de mtg_printing.rarity', () => {
    // Este es el test que hace que la tabla pueda vivir junto a `RAREZAS`: si
    // alguien añade una letra apuntando a una rareza inventada, salta aquí.
    const valores = RAREZAS.map((r) => r.value)
    for (const letra of ['C', 'U', 'R', 'M', 'S', 'T', 'L']) {
      expect(valores).toContain(rarezaDesdeLetraImpresa(letra))
    }
  })

  it('no inventa rareza para lo que no la tiene', () => {
    // `B` la acepta la regex del plan pero no tiene destino escrito: mejor sin
    // rareza que con una rareza adivinada, que viajaría al resolvedor como dato.
    expect(rarezaDesdeLetraImpresa('B')).toBeNull()
    expect(rarezaDesdeLetraImpresa('X')).toBeNull()
    expect(rarezaDesdeLetraImpresa(null)).toBeNull()
  })

  it('acepta la letra en minúscula, porque el OCR no garantiza la caja', () => {
    // Y no es teoría: la sesión del Realme entregó `ScoTT`, `FIscHER` y
    // `Llasura` — la caja de las letras es lo primero que se pierde.
    expect(rarezaDesdeLetraImpresa('r')).toBe('rare')
  })
})

describe('traducción 3 — el código de idioma impreso no es el nombre de MTGJSON', () => {
  it('traduce los once códigos', () => {
    expect(idiomaDesdeCodigoImpreso('EN')).toBe('English')
    expect(idiomaDesdeCodigoImpreso('ES')).toBe('Spanish')
    expect(idiomaDesdeCodigoImpreso('FR')).toBe('French')
    expect(idiomaDesdeCodigoImpreso('DE')).toBe('German')
    expect(idiomaDesdeCodigoImpreso('IT')).toBe('Italian')
    expect(idiomaDesdeCodigoImpreso('PT')).toBe('Portuguese (Brazil)')
    expect(idiomaDesdeCodigoImpreso('JA')).toBe('Japanese')
    expect(idiomaDesdeCodigoImpreso('KO')).toBe('Korean')
    expect(idiomaDesdeCodigoImpreso('RU')).toBe('Russian')
    expect(idiomaDesdeCodigoImpreso('ZHS')).toBe('Chinese Simplified')
    expect(idiomaDesdeCodigoImpreso('ZHT')).toBe('Chinese Traditional')
  })

  it('cada destino es un value de IDIOMAS, que es lo que guarda la BD', () => {
    // El idioma viaja DENTRO del `UNIQUE KEY uq_item`: uno mal escrito no da
    // error, parte la carta en dos líneas de colección. Por eso la tabla vive
    // pegada a `IDIOMAS` y por eso esto se comprueba.
    const valores = IDIOMAS.map((i) => i.value)
    for (const codigo of ['EN', 'ES', 'FR', 'DE', 'IT', 'PT', 'JA', 'KO', 'RU', 'ZHS', 'ZHT']) {
      expect(valores).toContain(idiomaDesdeCodigoImpreso(codigo))
    }
  })

  it('acepta minúsculas y rechaza lo que no es un código', () => {
    expect(idiomaDesdeCodigoImpreso('es')).toBe('Spanish')
    expect(idiomaDesdeCodigoImpreso('XX')).toBeNull()
    expect(idiomaDesdeCodigoImpreso('')).toBeNull()
    expect(idiomaDesdeCodigoImpreso(null)).toBeNull()
  })

  it('los once códigos de la regex son los once de la tabla, sin desincronizar', () => {
    // `CODIGOS_IDIOMA` se escribe a mano en `scanParser.js` en vez de derivarse
    // de `IDIOMA_POR_CODIGO_IMPRESO`, por legibilidad. Este test es el precio de
    // esa decisión: si se añade un idioma en un sitio y no en el otro, salta.
    for (const codigo of ['EN', 'ES', 'FR', 'DE', 'IT', 'PT', 'JA', 'KO', 'RU', 'ZHS', 'ZHT']) {
      expect(LINEA_EDICION.exec(`BLB ${codigo} Artista`).slice(1, 3)).toEqual(['BLB', codigo])
    }
    expect(LINEA_EDICION.test('BLB XX Artista')).toBe(false)
  })
})

/**
 * ENTRADAS SINTÉTICAS — escritas a mano y declaradas como tales.
 *
 * Aquí abajo el contenido es inventado, y por eso lo que se prueba está acotado
 * a cosas que **no dependen de qué lea el OCR**: el punto medio del plan (que la
 * sesión del Realme no llegó a entregar nunca, pero que las cartas sí llevan
 * impreso), el ancla final de `LINEA_NUMERO` y la forma que devuelve
 * `parsearLectura` ante entradas rotas.
 *
 * Lo que estos tests **no** prueban: nada sobre cómo parte ML Kit una carta de
 * verdad. Eso son los `describe` de arriba, y si alguna vez se contradicen,
 * **manda la fixture**.
 */
describe('entradas sintéticas — el punto medio y los bordes de la forma', () => {
  /** La FORMA sale del tipado del plugin (`TextBlock`/`TextLine`); el CONTENIDO
   *  es inventado, que es justo lo que estos tests no dan por bueno. */
  function deteccionSintetica(...pares) {
    return {
      text: pares.map(([texto]) => texto).join('\n'),
      blocks: [
        {
          text: pares.map(([texto]) => texto).join('\n'),
          lines: pares.map(([texto, arriba]) => ({
            text: texto,
            boundingBox:
              arriba === undefined ? undefined : { left: 0, top: arriba, right: 100, bottom: arriba + 20 }
          }))
        }
      ]
    }
  }

  it('LINEA_EDICION sigue casando el punto medio que el plan dibujaba', () => {
    // La sesión del Realme no entregó ni un `·`, pero las cartas lo llevan
    // impreso: aceptar el espacio no puede haber roto el separador de siempre.
    expect(LINEA_EDICION.exec('BLB · EN · 🖌 Nombre del Artista').slice(1, 3)).toEqual(['BLB', 'EN'])
    expect(LINEA_EDICION.exec('M21•ES').slice(1, 3)).toEqual(['M21', 'ES'])
    expect(LINEA_EDICION.exec('ZNR-ZHS · Alguien').slice(1, 3)).toEqual(['ZNR', 'ZHS'])
  })

  it('LINEA_EDICION exige de tres a seis caracteres de edición', () => {
    expect(LINEA_EDICION.test('AB · EN')).toBe(false)
  })

  it('LINEA_NUMERO va anclada al final, y la rigidez es la funcionalidad', () => {
    // Un patrón laxo no deja de fallar: falla escribiendo otra carta. Si la
    // línea arrastra texto del fondo, se descarta entera y la carta cae al paso
    // 3 por nombre, que es un resultado correcto y no un fallo.
    expect(LINEA_NUMERO.test('0123/0281 R ALGO MÁS')).toBe(false)
    expect(LINEA_NUMERO.test('0123/0281')).toBe(false)
    expect(LINEA_NUMERO.test('Lightning Bolt')).toBe(false)
    expect(LINEA_NUMERO.exec('12a/281 U').slice(1, 4)).toEqual(['12', 'a', 'U'])
    expect(LINEA_NUMERO.exec('  117★ / 291  M ').slice(1, 4)).toEqual(['117', '★', 'M'])
  })

  it('aprovecha el número aunque la rareza no tenga traducción', () => {
    const lectura = parsearLectura(deteccionSintetica(
      ['Forest', 40],
      ['0272/0281 B', 600],
      ['BLB · ES · 🖌 Alguien', 620]
    ))

    expect(lectura.collectorNumber).toBe('272')
    expect(lectura.rarity).toBeNull()
    // El código de edición se manda en mayúsculas porque así lo guarda
    // `mtg_set.code`.
    expect(lectura.setCode).toBe('BLB')
    expect(lectura.language).toBe('Spanish')
  })

  it('se queda con la PRIMERA esquina que case: una segunda es otra carta en el encuadre', () => {
    const lectura = parsearLectura(deteccionSintetica(
      ['Island', 40],
      ['0264/0281 C', 600],
      ['BLB · EN · 🖌 Alguien', 620],
      ['0117/0281 R', 900],
      ['2X2 · ES · 🖌 Otro', 920]
    ))

    expect(lectura.collectorNumber).toBe('264')
    expect(lectura.setCode).toBe('BLB')
    expect(lectura.language).toBe('English')
  })

  it('sin cajas se queda con el orden de lectura de ML Kit, que es de arriba abajo', () => {
    const lectura = parsearLectura(deteccionSintetica(
      ['Counterspell'],
      ['Instant'],
      ['Counter target spell.']
    ))

    expect(lectura.name).toBe('Counterspell')
  })

  it('descarta como nombre lo que no tiene ni una letra', () => {
    // El coste de maná y los números sueltos del marco salen como líneas
    // propias y ninguno es un nombre.
    const lectura = parsearLectura(deteccionSintetica(
      ['3', 10],
      ['*', 20],
      ['Serra Angel', 40]
    ))

    expect(lectura.name).toBe('Serra Angel')
  })

  it('una entrada rota no rompe nada: cinco campos a null', () => {
    const vacia = { name: null, setCode: null, collectorNumber: null, rarity: null, language: null }

    expect(parsearLectura({ text: '', blocks: [] })).toEqual(vacia)
    expect(parsearLectura({})).toEqual(vacia)
    expect(parsearLectura(null)).toEqual(vacia)
    // Un bloque sin `lines` es forma válida según el tipado del plugin.
    expect(parsearLectura({ blocks: [{ text: 'x' }] })).toEqual(vacia)
    // Y líneas en blanco no son líneas.
    expect(parsearLectura(deteccionSintetica(['   ', 10]))).toEqual(vacia)

    expect(lecturaVacia(vacia)).toBe(true)
    expect(lecturaVacia(null)).toBe(true)
  })

  it('tieneBloqueDeEsquina pide el par entero, que es lo que necesita el paso 2', () => {
    expect(tieneBloqueDeEsquina({ setCode: 'BLB', collectorNumber: '117' })).toBe(true)
    expect(tieneBloqueDeEsquina({ setCode: 'BLB', collectorNumber: null })).toBe(false)
    expect(tieneBloqueDeEsquina({ setCode: null, collectorNumber: '117' })).toBe(false)
  })
})

describe('REGRESIÓN — un título sin letras latinas SÍ es un nombre', () => {
  /**
   * EL TERCER BUG DE ESTE PARSER, y el más silencioso de los tres.
   *
   * `puedeSerNombre()` exigía `/[a-zà-ÿ]/i` —«que lleve una letra latina»— así
   * que el título de una carta japonesa, china, coreana o rusa **no llegaba
   * siquiera a ser candidato**, y `elegirNombre()` se quedaba con la línea más
   * alta de las que sí llevaban algo latino: normalmente el crédito del
   * artista.
   *
   * Las cadenas de abajo NO están inventadas: son las que se capturaron del
   * store del Realme el 2026-09-16 por DevTools, con el selector en japonés y
   * el modelo `Jpan_ctc` ya cargando bien. El escáner tomaba por nombre
   * `sld·jp 新川洋司/yon shinkawa`, que es la línea del artista.
   *
   * El nombre japonés de la prueba es real y resuelve: `隊商の夜番` es
   * *Caravan Vigil*, verificado contra el resolvedor el mismo día.
   */
  const conLineas = (lineas) => ({
    blocks: [{ lines: lineas.map(([text, top]) => ({ text, boundingBox: { top } })) }]
  })

  it('el título japonés gana a la línea del artista, que es lo que ganaba antes', () => {
    const lectura = parsearLectura(
      conLineas([
        ['隊商の夜番', 40],
        ['sld·jp 新川洋司/yon shinkawa', 900]
      ])
    )

    expect(lectura.name).toBe('隊商の夜番')
  })

  it('y lo mismo en chino, coreano y ruso, que tenían el fallo sin haberse probado', () => {
    for (const titulo of ['平地', '섬', 'Равнина']) {
      expect(parsearLectura(conLineas([[titulo, 40], ['Ilust. Rob Alexander', 900]])).name).toBe(titulo)
    }
  })

  it('UN SOLO ideograma es un nombre entero: 山 es Mountain', () => {
    // El mínimo de dos caracteres es correcto para un alfabeto y falso para el
    // japonés. Medido contra la BD: 1.630 filas con nombre de un carácter, y son
    // las cuatro tierras básicas —山 418, 島 411, 沼 401, 森 400—, o sea lo que
    // más se escanea. Con el mínimo aplicado no se leía ninguna.
    for (const titulo of ['山', '島', '沼', '森']) {
      expect(parsearLectura(conLineas([[titulo, 40], ['Ilust. Rob Alexander', 900]])).name).toBe(titulo)
    }
  })

  it('pero una letra suelta del OCR sigue sin serlo', () => {
    expect(parsearLectura(conLineas([['t', 10], ['Llanura', 40]])).name).toBe('Llanura')
  })

  it('lo que NO lleva ningún alfabeto sigue sin ser nombre', () => {
    // El bloque de esquina y la línea de fuerza/resistencia no pueden coronarse
    // como título por muy arriba que el OCR los coloque.
    expect(parsearLectura(conLineas([['3/4', 10], ['Llanura', 40]])).name).toBe('Llanura')
  })
})
