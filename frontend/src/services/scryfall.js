/**
 * URLs de imagen de carta.
 *
 * Desde el M6 del Plan - Colección y Vistas, **la app no apunta al CDN de
 * Scryfall: apunta a su propio backend**, y es él quien decide. La regla del plan
 * es «servir la copia local cuando exista, la URL de Scryfall cuando no», y esa
 * decisión no puede tomarla el navegador —no sabe qué hay bajo `storage/`—, así
 * que la toma `GET /api/images/{scryfall_id}`:
 *
 *   - hay copia local → 200 con los bytes del JPEG y caché de un año
 *   - no la hay       → 302 al CDN, sin cachear, para que la próxima visita ya se
 *                       lleve la copia local en cuanto `images:cache` la baje
 *
 * Que la decisión viva en el servidor es lo que hace que la colección se vea
 * entera **sin internet**: en modo avión el 200 sigue saliendo y el 302 es el
 * único que falla, exactamente en las cartas que aún no se han bajado.
 *
 * Sigue sin haber una sola llamada a la API de Scryfall: la URL se compone desde
 * el `scryfallId` que MTGJSON ya nos dio. Y las imágenes se muestran **tal cual**,
 * sin recortes ni marcas de agua, como exige la Fan Content Policy.
 */

import { API_BASE } from './api'

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

  const size = TAMANOS.includes(tamano) ? tamano : 'normal'

  // `size` solo lo usa el backend para componer el 302 cuando todavía no hay
  // copia local; si la hay, sirve la que tenga y el navegador la escala.
  return `${API_BASE}/api/images/${encodeURIComponent(scryfallId)}?size=${size}`
}

export default { imagenDeCarta, TAMANOS }
