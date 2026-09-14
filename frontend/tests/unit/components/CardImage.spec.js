import { describe, expect, it } from 'vitest'
import { nextTick } from 'vue'

import CardImage from '@/components/CardImage.vue'
import { imagenDeCarta } from '@/services/scryfall'

import { SESION, montarVista } from '../../helpers'

import cartaFixture from '../../fixtures/catalog_card.json'

/**
 * `components/CardImage.vue` — la miniatura de una carta.
 *
 * Tiene más lógica de la que parece, y toda sale de dos fallos reales:
 *
 *  - **El hueco va SIEMPRE debajo y la imagen encima cuando existe.** La primera
 *    versión ocultaba la imagen con `opacity: 0` hasta el evento `load`, y ese
 *    evento **no llega** si el navegador ya la tiene en caché o si Vue recicla el
 *    nodo al hacer scroll: la imagen estaba cargada y no se veía
 *    (`CardImage.vue:4-8`).
 *  - **El fallo se olvida al cambiar de carta.** El scroll infinito recicla
 *    nodos, así que una carta sin imagen dejaría marcadas de por vida todas las
 *    que pasaran por ese nodo (`CardImage.vue:39-44`).
 *
 * Se monta con el mismo helper que las vistas —`montarVista`— para que haya un
 * solo sitio que sepa montar en esta suite, aunque este componente no necesite
 * ni router ni Pinia. **No se dobla `@/services/api`**: aquí no hay red, solo la
 * composición de una URL a partir del `scryfallId` que MTGJSON ya dio.
 */

const SCRYFALL_ID = cartaFixture.scryfallId

function montarImagen(props = {}) {
  return montarVista(CardImage, {
    ruta: '/catalog',
    estado: SESION,
    props: { scryfallId: SCRYFALL_ID, nombre: cartaFixture.name, tamano: 'small', ...props }
  })
}

describe('la URL', () => {
  it('apunta al backend propio y no al CDN de Scryfall', async () => {
    const { wrapper, errores } = await montarImagen()

    const img = wrapper.find('img')

    expect(errores).toEqual([])
    // La decisión de servir la copia local o redirigir al CDN no la puede tomar
    // el navegador —no sabe qué hay bajo `storage/`—, así que la toma
    // `GET /api/images/{scryfall_id}` (`services/scryfall.js:1-21`).
    expect(img.attributes('src')).toBe(imagenDeCarta(SCRYFALL_ID, 'small'))
    expect(img.attributes('src')).toContain(`/api/images/${SCRYFALL_ID}?size=small`)
    expect(img.attributes('src')).not.toContain('scryfall.io')
  })

  it('el alt es el nombre de la carta, y la carga es perezosa', async () => {
    const { wrapper } = await montarImagen()

    const img = wrapper.find('img')

    // Sin `loading="lazy"`, una colección en rejilla son miles de peticiones al
    // servidor de golpe (`CollectionView.vue:136-138`).
    expect(img.attributes('alt')).toBe(cartaFixture.name)
    expect(img.attributes('loading')).toBe('lazy')
    expect(img.attributes('decoding')).toBe('async')
  })

  it('sin scryfallId no hay imagen: se queda el hueco con el nombre', async () => {
    // No todas las cartas traen `scryfallId` (`services/scryfall.js:29-35`).
    const { wrapper } = await montarImagen({ scryfallId: null })

    expect(wrapper.find('img').exists()).toBe(false)
    expect(wrapper.find('.carta-img__hueco span').text()).toBe(cartaFixture.name)
  })
})

describe('cuando la imagen no carga', () => {
  it('un error del <img> deja el hueco con el nombre, no un icono roto', async () => {
    const { wrapper } = await montarImagen()

    await wrapper.find('img').trigger('error')
    await nextTick()

    expect(wrapper.find('img').exists()).toBe(false)
    expect(wrapper.find('.carta-img__hueco span').text()).toBe(cartaFixture.name)
  })

  it('cambiar de carta OLVIDA el fallo de la anterior', async () => {
    const { wrapper } = await montarImagen()

    await wrapper.find('img').trigger('error')
    await nextTick()

    expect(wrapper.find('img').exists()).toBe(false)

    // El scroll infinito recicla nodos: sin el `watch` de `CardImage.vue:42-44`,
    // esta carta heredaría el fallo de la que ocupó el nodo antes.
    await wrapper.setProps({ scryfallId: '177ee102-d981-4fc3-9f09-9dd07755f22c' })
    await nextTick()

    expect(wrapper.find('img').exists()).toBe(true)
    expect(wrapper.find('img').attributes('src'))
      .toContain('177ee102-d981-4fc3-9f09-9dd07755f22c')
  })
})
