import { describe, expect, it } from 'vitest'

import ImportCandidate from '@/components/ImportCandidate.vue'

import { montarVista } from '../../helpers'

import previewFixture from '../../fixtures/import_preview.json'

/**
 * `components/ImportCandidate.vue` — la etiqueta de un candidato de conflicto.
 *
 * Son 45 líneas sin lógica, y aun así es donde se lee lo único que el usuario
 * tiene para decidir entre dos cartas que se llaman igual. Lo que se protege
 * son sus **tres formas**, porque las tres significan cosas distintas y la
 * plantilla las decide con un `v-if`/`v-else-if` que es fácil de invertir sin
 * que nada falle:
 *
 *  1. **Impresión concreta** — trae `setCode`: se pinta el código y nada más.
 *  2. **Edición asumida** — trae `assumedPrinting: true` y `printingCount`: se
 *     pinta «edición asumida de N». Callárselo cambiaría un fallo silencioso
 *     (importar otra carta) por otro (importar otra edición), que es justo lo
 *     que el componente existe para evitar.
 *  3. **Sin impresión en el catálogo** — no trae `printingUuid`: ese texto
 *     literal, que es lo que `ImportView.vue:213` usa para deshabilitar el
 *     botón. Sin él, el usuario elegiría un candidato que no se puede importar.
 *
 * **Este componente NO se tocó en el plan de Impresiones** —el desplegable vive
 * en `PrintingSelect.vue`, que se monta como hermano del `<button>`—, así que
 * estos tests fijan lo que ya hacía, no lo que se acaba de añadir.
 *
 * Los candidatos salen de `import_preview.json`, la respuesta literal del
 * backend, y no se escriben a mano: uno inventado traería las claves que quien
 * escribe el test cree que existen, que es el pecado que tumbó `/decks` y
 * `DeckCardSearch`. La excepción son las dos formas que esa previsualización no
 * produce, marcadas donde aparecen y con el porqué.
 */

/** Los candidatos reales de la previsualización capturada. */
const CONFLICTOS = previewFixture.data.conflicts

/** El primer candidato del catálogo que cumple `predicado`, o revienta el test. */
function candidatoQue(predicado) {
  for (const conflicto of CONFLICTOS) {
    const encontrado = (conflicto.candidates ?? []).find(predicado)

    if (encontrado) {
      return structuredClone(encontrado)
    }
  }

  throw new Error('La fixtura de previsualización no trae ese tipo de candidato.')
}

async function montarEtiqueta(candidato) {
  return montarVista(ImportCandidate, { ruta: '/import', props: { candidato } })
}

describe('las tres formas de un candidato', () => {
  it('con impresión concreta enseña el nombre y el código de edición, sin marcas', async () => {
    const candidato = candidatoQue((c) => c.setCode && !c.assumedPrinting)

    const { wrapper, errores } = await montarEtiqueta(candidato)

    expect(errores).toEqual([])
    expect(wrapper.find('.candidato-etq__nombre').text()).toBe(candidato.name)
    expect(wrapper.find('.candidato-etq__meta').text()).toContain(candidato.setCode)
    expect(wrapper.text()).not.toContain('edición asumida')
    expect(wrapper.text()).not.toContain('sin impresión en el catálogo')
  })

  it('con edición asumida lo dice y dice de cuántas', async () => {
    const candidato = candidatoQue((c) => c.assumedPrinting === true)

    const { wrapper } = await montarEtiqueta(candidato)

    // El número no es decorativo: es lo que distingue «asumida entre 2» de
    // «asumida entre 71», y es lo que decide si `ImportView` pinta siquiera el
    // desplegable (`printingCount > 1`).
    expect(candidato.printingCount).toBeGreaterThan(1)
    expect(wrapper.text()).toContain(`edición asumida de ${candidato.printingCount}`)

    // Y sigue enseñando la edición que se va a importar si el usuario no toca
    // nada: la marca avisa, no sustituye al dato.
    expect(wrapper.find('.candidato-etq__meta').text()).toContain(candidato.setCode)
  })

  it('sin impresión en el catálogo lo dice con ese texto literal', async () => {
    // Es lo que `ImportView.vue:213` mira para deshabilitar el botón: el
    // candidato identifica una carta que existe, pero no una fila que importar.
    //
    // La ÚNICA forma que la previsualización capturada no contiene, y por eso
    // es la única que se escribe a mano: nace cuando `AssumedPrintingChooser`
    // no encuentra impresión para ese `(oracle_id, acabado)`
    // (`AssumedPrintingChooser.php:85`), y provocarla exigiría una carta que no
    // exista en el acabado pedido. Lo que se escribe son tres claves del
    // contrato, no una respuesta del backend.
    const candidato = { name: 'Una Carta Sin Printing', printingUuid: null, setCode: null }

    const { wrapper } = await montarEtiqueta(candidato)

    expect(wrapper.find('.candidato-etq__meta').text()).toBe('sin impresión en el catálogo')
  })

  it('el aviso de asumida NO aparece por tener setCode vacío', async () => {
    // El `v-else-if` de la plantilla es fácil de leer al revés. Un candidato sin
    // `setCode` pero CON `printingUuid` no es ninguno de los dos casos raros:
    // no hay edición que enseñar y tampoco falta la impresión.
    const { wrapper } = await montarEtiqueta({
      name: 'Carta rara',
      printingUuid: 'u-1',
      setCode: null
    })

    expect(wrapper.text()).not.toContain('sin impresión en el catálogo')
    expect(wrapper.text()).not.toContain('edición asumida')
  })

  it('el nombre de una doble cara llega entero, con su ` // `', async () => {
    // El paso 3b de `CardResolver` parte por ` // ` para resolver; la etiqueta
    // hace lo contrario y lo enseña tal cual, porque las dos caras son la misma
    // carta y enseñar solo la frontal haría irreconocible la elección.
    const { wrapper } = await montarEtiqueta({
      name: 'Ulvenwald Captive // Ulvenwald Abomination',
      printingUuid: 'u-2',
      setCode: 'EMN'
    })

    expect(wrapper.find('.candidato-etq__nombre').text()).toBe(
      'Ulvenwald Captive // Ulvenwald Abomination'
    )
  })
})
