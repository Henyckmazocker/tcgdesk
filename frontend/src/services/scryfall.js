/**
 * URLs de imagen de carta.
 *
 * Es lo ÚNICO de la app que sale a internet en tiempo de ejecución. El catálogo,
 * los precios y las búsquedas viven en local; las imágenes no se guardan porque
 * son ~100 KB por carta y 110.384 printings no caben en ningún sitio razonable.
 *
 * No hace falta llamar a la API de Scryfall para obtenerlas: la URL se compone
 * a partir del `scryfallId` que MTGJSON ya nos dio, siguiendo el esquema del CDN
 * (dos primeros caracteres del id como carpetas). Cero peticiones extra.
 *
 * Las imágenes son copyright de Wizards of the Coast: se muestran tal cual, sin
 * recortes ni marcas de agua, como exige la Fan Content Policy.
 */

const CDN = 'https://cards.scryfall.io'

/** Tamaños que publica el CDN, de menos a más peso. */
export const TAMANOS = ['small', 'normal', 'large']

/**
 * @param {string|null} scryfallId
 * @param {'small'|'normal'|'large'} tamano
 * @returns {string|null} null si la carta no tiene id (no todas lo traen)
 */
export function imagenDeCarta(scryfallId, tamano = 'normal') {
  if (!scryfallId || scryfallId.length < 2) {
    return null
  }

  return `${CDN}/${tamano}/front/${scryfallId[0]}/${scryfallId[1]}/${scryfallId}.jpg`
}

export default { imagenDeCarta, TAMANOS }
