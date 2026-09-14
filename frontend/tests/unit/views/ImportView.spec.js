import { beforeEach, describe, expect, it, vi } from 'vitest'
import { nextTick } from 'vue'
import { flushPromises } from '@vue/test-utils'

import ImportView from '@/views/ImportView.vue'
import { apiCall } from '@/services/api'

import { SESION, montarVista } from '../../helpers'

import previewFixture from '../../fixtures/import_preview.json'
import applyFixture from '../../fixtures/import_apply.json'

/**
 * `views/ImportView.vue` — 1.276 líneas, la vista más cara del plan.
 *
 * Es, con `CardView`, **una de las dos que llaman a la capa de red desde la
 * propia vista** en vez de por un store: `ImportView.vue:314` y `:368` hacen sus
 * `apiCall` a mano, así que aquí el doble de `@/services/api` es lo único que
 * corta la red. No hay store de importación porque no hay nada que compartir: el
 * `preview` vive y muere dentro de esta pantalla.
 *
 * Las tres decisiones que se prueban, que son las tres del encabezado del
 * fichero y ninguna es cosmética:
 *
 *  - **Los conflictos van arriba y destacados**, y las resueltas plegadas.
 *    Enterrarlos bajo 3.000 filas correctas equivale a no enseñarlos, y entonces
 *    se aceptan a ciegas.
 *  - **Cada motivo se pinta distinto** porque no piden lo mismo: en `ambiguous`
 *    FALTA información y hay que elegir; en `mismatch` SOBRA —dos datos exactos
 *    que se contradicen— y hay que decidir a cuál se hace caso; `not_found` e
 *    `invalid` no se arreglan aquí.
 *  - **Lo que no se decide, no se importa.** El estado por defecto de un
 *    conflicto es «sin decidir», y eso cuenta como descartado.
 */

vi.mock('@/services/api', () => ({
  apiCall: vi.fn(),
  catalogGet: vi.fn()
}))

/** Copia profunda: la vista muta lo que recibe y las fixturas son de todos. */
function fixtura(json) {
  return structuredClone(json)
}

/** Un doble que responde según la ACCIÓN, que es como funciona el endpoint único. */
function respondeSegunAccion(mapa) {
  apiCall.mockImplementation((accion) =>
    Promise.resolve(
      mapa[accion] ?? { status: 'error', message: `Sin doble para ${accion}.`, http_code: 500 }
    )
  )
}

async function montarImport() {
  const montaje = await montarVista(ImportView, { ruta: '/import', estado: SESION })

  await flushPromises()
  await nextTick()

  return montaje
}

/** Pega una lista y pulsa «Previsualizar»: es el paso 1 entero. */
async function hastaLaPrevisualizacion(lista = '4 Lightning Bolt (M10) 146\n2 Sol Ring') {
  const montaje = await montarImport()

  await montaje.wrapper.find('.caja__texto').setValue(lista)
  await montaje.wrapper.find('.origen__acciones button').trigger('click')
  await flushPromises()
  await nextTick()

  return montaje
}

beforeEach(() => {
  apiCall.mockReset()
  respondeSegunAccion({
    import_preview: fixtura(previewFixture),
    import_apply: fixtura(applyFixture)
  })
})

describe('el paso 1: de dónde sale la lista', () => {
  it('monta sin lanzar y avisa de que esto SUMA antes de tocar nada', async () => {
    const { wrapper, errores } = await montarImport()

    expect(errores).toEqual([])

    // Quien reimporta su ManaBox esperando «sincronizar» duplica cantidades,
    // porque el UNIQUE KEY suma. Se anuncia antes, no después
    // (`ImportView.vue:13-17`).
    expect(wrapper.find('.aviso').text()).toContain('no es una sincronización')
    expect(wrapper.find('.origen').exists()).toBe(true)
  })

  it('sin contenido no se puede previsualizar', async () => {
    const { wrapper } = await montarImport()

    expect(wrapper.find('.origen__acciones button').attributes('disabled')).toBeDefined()

    await wrapper.find('.caja__texto').setValue('2 Sol Ring')
    await nextTick()

    expect(wrapper.find('.origen__acciones button').attributes('disabled')).toBeUndefined()
  })

  it('previsualizar manda el contenido pegado y el timeout largo', async () => {
    await hastaLaPrevisualizacion('2 Sol Ring')

    // 2 minutos: una importación grande es UNA petición con megas de cuerpo, y
    // trocearla rompería la transacción única (`ImportView.vue:50-51`).
    expect(apiCall).toHaveBeenCalledWith(
      'import_preview',
      { content: '2 Sol Ring', filename: '' },
      expect.objectContaining({ timeout: 120000 })
    )
  })

  it('un fichero se lee en local y su nombre y tamaño se enseñan antes de subirlo', async () => {
    const { wrapper } = await montarImport()

    const contenido = '4 Lightning Bolt\n2 Sol Ring\n'
    const fichero = new File([contenido], 'manabox.csv', { type: 'text/csv' })
    const input = wrapper.find('.caja__fichero')

    Object.defineProperty(input.element, 'files', { value: [fichero] })
    await input.trigger('change')

    // El nombre sale YA, pero el contenido no: `FileReader` es asíncrono —leer
    // 5 MB en un móvil no es instantáneo— y por eso la barra empieza en la
    // lectura y no en la petición (`ImportView.vue:278-282`). Hasta que
    // `onload` no llega, «Previsualizar» sigue deshabilitado, que es
    // exactamente lo que se espera de él.
    expect(wrapper.find('.caja__elegido').text()).toContain('manabox.csv')
    expect(wrapper.find('.origen__acciones button').attributes('disabled')).toBeDefined()

    await vi.waitFor(() =>
      expect(wrapper.find('.origen__acciones button').attributes('disabled')).toBeUndefined()
    )

    await wrapper.find('.origen__acciones button').trigger('click')
    await flushPromises()

    expect(apiCall).toHaveBeenCalledWith(
      'import_preview',
      { content: contenido, filename: 'manabox.csv' },
      expect.anything()
    )
  })

  it('un fichero por encima del máximo se rechaza aquí, sin gastar la petición', async () => {
    const { wrapper } = await montarImport()

    const fichero = new File(['x'], 'enorme.csv', { type: 'text/csv' })

    // El backend lo rechazaría igual: el aviso local existe para no mandar 20 MB
    // que se van a tirar (`ImportView.vue:53-54`).
    Object.defineProperty(fichero, 'size', { value: 11 * 1024 * 1024 })

    const input = wrapper.find('.caja__fichero')

    Object.defineProperty(input.element, 'files', { value: [fichero] })
    await input.trigger('change')
    await nextTick()

    expect(wrapper.find('.origen__error').text()).toContain('el máximo son 10.0 MB')
    expect(wrapper.find('.caja__elegido').exists()).toBe(false)
    expect(apiCall).not.toHaveBeenCalled()
  })

  it('un error de la previsualización se queda en el paso 1, con su mensaje', async () => {
    respondeSegunAccion({
      import_preview: { status: 'error', message: 'No se ha reconocido el formato.', http_code: 422 }
    })

    const { wrapper } = await hastaLaPrevisualizacion()

    expect(wrapper.find('.origen').exists()).toBe(true)
    expect(wrapper.find('.origen__error').text()).toContain('No se ha reconocido el formato.')
  })

  it('un 401 y un 429 se traducen: el mensaje del backend no sirve tal cual', async () => {
    respondeSegunAccion({
      import_preview: { status: 'error', message: 'Unauthorized', http_code: 401 }
    })

    const { wrapper } = await hastaLaPrevisualizacion()

    expect(wrapper.find('.origen__error').text()).toContain('Tu sesión ha caducado')

    respondeSegunAccion({
      import_preview: { status: 'error', message: 'Too many requests', http_code: 429 }
    })

    await wrapper.find('.origen__acciones button').trigger('click')
    await flushPromises()
    await nextTick()

    expect(wrapper.find('.origen__error').text()).toContain('Espera un minuto')
  })
})

describe('el paso 2: la previsualización', () => {
  it('pinta el resumen con las cifras del backend y el formato detectado', async () => {
    const { wrapper, errores } = await hastaLaPrevisualizacion()

    const datos = fixtura(previewFixture).data

    expect(errores).toEqual([])
    expect(wrapper.find('.preview').exists()).toBe(true)

    // `plaintext` → «Texto plano»: la etiqueta la traduce la vista
    // (`ImportView.vue:100-105`).
    expect(wrapper.find('.importar__formato').text()).toBe('Texto plano')

    const resumen = wrapper.find('.resumen').text()

    expect(resumen).toContain(`${datos.total} líneas leídas`)
    expect(resumen).toContain(`${datos.summary.resolvedCount} reconocidas`)
    expect(resumen).toContain(`${datos.summary.conflictCount} sin resolver`)
    expect(resumen).toContain(`${datos.summary.assumedCount} con edición asumida`)
  })

  it('los conflictos van ARRIBA y las resueltas empiezan plegadas', async () => {
    const { wrapper } = await hastaLaPrevisualizacion()

    const html = wrapper.html()

    expect(html.indexOf('conflictos__titulo')).toBeGreaterThan(-1)
    expect(html.indexOf('conflictos__titulo')).toBeLessThan(html.indexOf('resueltas__cabecera'))

    // Plegadas porque hay conflictos: con cero, la vista las abre sola
    // (`ImportView.vue:353`).
    expect(wrapper.find('.resueltas__cuerpo').exists()).toBe(false)

    await wrapper.find('.resueltas__cabecera').trigger('click')
    await nextTick()

    expect(wrapper.findAll('.p-datatable-tbody > tr'))
      .toHaveLength(fixtura(previewFixture).data.resolved.length)
  })

  it('sin ningún conflicto, las resueltas salen ya abiertas', async () => {
    const preview = fixtura(previewFixture)

    preview.data.conflicts = []
    preview.data.summary.conflictCount = 0

    respondeSegunAccion({ import_preview: preview })

    const { wrapper } = await hastaLaPrevisualizacion()

    expect(wrapper.find('.conflictos').exists()).toBe(false)
    expect(wrapper.find('.resueltas__cuerpo').exists()).toBe(true)
  })

  it('cada motivo se pinta distinto y enseña la línea CRUDA del fichero', async () => {
    const { wrapper } = await hastaLaPrevisualizacion()

    const conflictos = fixtura(previewFixture).data.conflicts
    const articulos = wrapper.findAll('.conflicto')

    expect(articulos).toHaveLength(conflictos.length)

    // `not_found`: no hay nada que ofrecer, así que no se pinta ni un candidato.
    expect(conflictos[0].reason).toBe('not_found')
    expect(articulos[0].classes()).toContain('conflicto--not-found')
    expect(articulos[0].find('.conflicto__motivo').text())
      .toContain('No se ha encontrado esa carta')
    expect(articulos[0].findAll('.candidato')).toHaveLength(0)

    // Lo crudo, SIN normalizar: es lo único que le permite al usuario reconocer
    // su línea (`ImportView.vue:221-228`).
    expect(articulos[0].find('.conflicto__crudo').text()).toBe(conflictos[0].raw.linea)
    expect(articulos[0].find('.conflicto__linea').text()).toBe(`línea ${conflictos[0].line}`)
  })

  it('el mismatch enseña las DOS cartas enfrentadas, etiquetadas por lo que las trajo', async () => {
    const { wrapper } = await hastaLaPrevisualizacion()

    const conflictos = fixtura(previewFixture).data.conflicts
    const mismatch = wrapper.findAll('.conflicto')[1]

    // Es el conflicto que evita meter *Shyft* creyendo que es *Lim-Dûl's Vault*:
    // `ICE 96` **es** Shyft, y el nombre dice otra cosa (`CLAUDE.md`).
    expect(conflictos[1].reason).toBe('mismatch')
    expect(mismatch.classes()).toContain('conflicto--mismatch')

    const lados = mismatch.findAll('.desacuerdo__lado')

    expect(lados).toHaveLength(2)
    expect(lados[0].find('.desacuerdo__que').text()).toContain('NOMBRE')
    expect(lados[0].text()).toContain("Lim-Dûl's Vault")
    expect(lados[1].find('.desacuerdo__que').text()).toContain('(SET) número')
    expect(lados[1].text()).toContain('Shyft')
  })
})

describe('lo que no se decide, no se importa', () => {
  /** Las líneas y los ejemplares que el botón de confirmar dice que va a mandar. */
  function loQueDiceElBoton(wrapper) {
    return wrapper.find('.preview__confirmar button').text()
  }

  it('los conflictos arrancan sin decidir, y el botón cuenta SOLO las resueltas', async () => {
    const { wrapper } = await hastaLaPrevisualizacion()

    const datos = fixtura(previewFixture).data
    const ejemplares = datos.resolved.reduce((suma, fila) => suma + fila.quantity, 0)

    // El aviso es explícito: no se descarta en silencio.
    expect(wrapper.find('.preview__pendientes').text())
      .toContain(`${datos.conflicts.length} conflictos sin decidir: se descartarán`)

    expect(loQueDiceElBoton(wrapper))
      .toContain(`Importar ${datos.resolved.length} líneas (${ejemplares} ejemplares)`)
  })

  it('elegir un candidato del mismatch lo suma al lote y baja los pendientes', async () => {
    const { wrapper } = await hastaLaPrevisualizacion()

    const datos = fixtura(previewFixture).data
    const mismatch = wrapper.findAll('.conflicto')[1]

    await mismatch.findAll('.candidato')[0].trigger('click')
    await nextTick()

    expect(mismatch.findAll('.candidato')[0].classes()).toContain('candidato--elegido')
    expect(wrapper.find('.preview__pendientes').text()).toContain('1 conflictos sin decidir')
    expect(loQueDiceElBoton(wrapper)).toContain(`Importar ${datos.resolved.length + 1} líneas`)
  })

  it('descartar una resuelta la saca del lote, y se puede recuperar', async () => {
    const { wrapper } = await hastaLaPrevisualizacion()

    const datos = fixtura(previewFixture).data

    await wrapper.find('.resueltas__cabecera').trigger('click')
    await nextTick()

    const botonDescartar = () =>
      wrapper.findAll('.p-datatable-tbody > tr')[0].findAll('button').at(-1)

    await botonDescartar().trigger('click')
    await nextTick()

    expect(wrapper.find('.resueltas__cabecera').text()).toContain('1 descartadas')
    expect(loQueDiceElBoton(wrapper)).toContain(`Importar ${datos.resolved.length - 1} líneas`)

    await botonDescartar().trigger('click')
    await nextTick()

    expect(loQueDiceElBoton(wrapper)).toContain(`Importar ${datos.resolved.length} líneas`)
  })

  it('aplicar manda SOLO las filas confirmadas, con las cinco dimensiones y la zona', async () => {
    const { wrapper } = await hastaLaPrevisualizacion()

    const datos = fixtura(previewFixture).data

    await wrapper.find('.preview__confirmar button').trigger('click')
    await flushPromises()

    const [accion, cuerpo] = apiCall.mock.calls.at(-1)

    expect(accion).toBe('import_apply')
    expect(cuerpo.rows).toHaveLength(datos.resolved.length)
    expect(cuerpo.deck).toBeUndefined()

    // La forma del contrato, y **la zona viaja siempre**: a la colección no le
    // afecta y es lo único que reparte el mazo si se crea (`ImportView.vue:200-211`).
    expect(cuerpo.rows[0]).toEqual({
      printingUuid: datos.resolved[0].printingUuid,
      finish: datos.resolved[0].finish,
      language: datos.resolved[0].language,
      condition: datos.resolved[0].condition,
      quantity: datos.resolved[0].quantity,
      board: datos.resolved[0].board
    })
  })
})

describe('crear además un mazo', () => {
  async function conLaCasillaMarcada() {
    const montaje = await hastaLaPrevisualizacion()

    await montaje.wrapper.find('#como-mazo').setValue(true)
    await nextTick()

    return montaje
  }

  it('sin nombre no se puede importar: el backend devolvería 422', async () => {
    const { wrapper } = await conLaCasillaMarcada()

    expect(wrapper.find('.mazo__campos').exists()).toBe(true)
    expect(wrapper.find('.preview__confirmar button').attributes('disabled')).toBeDefined()
    expect(wrapper.find('.preview__confirmar').text())
      .toContain('Ponle un nombre al mazo o desmarca la casilla')
  })

  it('marcada, aparece la columna Zona: antes sería una columna de ruido', async () => {
    const { wrapper } = await conLaCasillaMarcada()

    await wrapper.find('.resueltas__cabecera').trigger('click')
    await nextTick()

    // La colección no tiene zonas: enseñar «Principal» en todas las filas de un
    // CSV de ManaBox no dice nada (`ImportView.vue:248-252`).
    expect(wrapper.find('.p-datatable-thead').text()).toContain('Zona')
  })

  it('el mazo viaja en la MISMA petición que las filas, y sin formato no manda format', async () => {
    const { wrapper } = await conLaCasillaMarcada()

    await wrapper.findAll('.mazo__campo input')[0].setValue('  Atraxa  ')
    await nextTick()

    await wrapper.find('.preview__confirmar button').trigger('click')
    await flushPromises()

    const cuerpo = apiCall.mock.calls.at(-1)[1]

    // En la MISMA transacción que la colección: o entran las dos cosas o no
    // entra ninguna (`ImportView.vue:364-372`). Y `building` por defecto, porque
    // decir `built` de más haría que el mazo consumiera colección y disparara
    // avisos de sobreasignación falsos (`ImportView.vue:128-134`).
    expect(cuerpo.deck).toEqual({ name: 'Atraxa', status: 'building' })
    expect(cuerpo.rows.length).toBeGreaterThan(0)
  })
})

describe('el paso 3: hecho', () => {
  it('enseña el parte con las cifras del backend y lleva al mazo recién creado', async () => {
    const { wrapper, router } = await hastaLaPrevisualizacion()

    await wrapper.find('.preview__confirmar button').trigger('click')
    await flushPromises()
    await nextTick()

    const datos = fixtura(applyFixture).data
    const cifras = wrapper.find('.hecho__cifras').text()

    expect(wrapper.find('.hecho__titulo').text()).toBe('Importación terminada')
    expect(cifras).toContain(`${datos.inserted}`)
    expect(cifras).toContain(`${datos.updated}`)
    expect(cifras).toContain(`${datos.totalQuantity}`)
    expect(cifras).toContain(datos.deck.name)

    await wrapper.findAll('.hecho__acciones button')[0].trigger('click')

    await vi.waitFor(() => expect(router.currentRoute.value.name).toBe('deck'))
    expect(router.currentRoute.value.params.id).toBe(String(datos.deck.id))
  })

  it('sin mazo creado no se ofrece el botón de verlo', async () => {
    const aplicado = fixtura(applyFixture)

    delete aplicado.data.deck

    respondeSegunAccion({
      import_preview: fixtura(previewFixture),
      import_apply: aplicado
    })

    const { wrapper } = await hastaLaPrevisualizacion()

    await wrapper.find('.preview__confirmar button').trigger('click')
    await flushPromises()
    await nextTick()

    expect(wrapper.findAll('.hecho__acciones button')).toHaveLength(2)
    expect(wrapper.find('.hecho__acciones').text()).not.toContain('Ver el mazo')
  })

  it('un error al aplicar se queda en la previsualización, sin perder lo decidido', async () => {
    respondeSegunAccion({
      import_preview: fixtura(previewFixture),
      import_apply: { status: 'error', message: 'La transacción falló.', http_code: 500 }
    })

    const { wrapper } = await hastaLaPrevisualizacion()

    await wrapper.findAll('.conflicto')[1].findAll('.candidato')[0].trigger('click')
    await nextTick()

    await wrapper.find('.preview__confirmar button').trigger('click')
    await flushPromises()
    await nextTick()

    expect(wrapper.find('.preview').exists()).toBe(true)
    expect(wrapper.text()).toContain('La transacción falló.')
    // La elección sigue puesta: volver a pulsar no obliga a rehacerla.
    expect(wrapper.findAll('.conflicto')[1].findAll('.candidato')[0].classes())
      .toContain('candidato--elegido')
  })
})

describe('volver atrás', () => {
  it('desde la previsualización reinicia el paso 1 en vez de salir de la vista', async () => {
    const { wrapper, router } = await hastaLaPrevisualizacion()

    await wrapper.find('.importar__bar button').trigger('click')
    await nextTick()

    expect(wrapper.find('.origen').exists()).toBe(true)
    expect(wrapper.find('.caja__texto').element.value).toBe('')
    expect(router.currentRoute.value.name).toBe('import')
  })

  it('desde el paso 1 sí sale a la portada', async () => {
    const { wrapper, router } = await montarImport()

    await wrapper.find('.importar__bar button').trigger('click')

    await vi.waitFor(() => expect(router.currentRoute.value.name).toBe('home'))
  })
})

/**
 * **La casilla «guardar todo en la lista de deseos»** — la puerta de entrada
 * masiva barata: un checkbox y **cero backend**, porque `import_apply` acepta
 * `is_wishlist` como bandera del lote desde el primer día
 * (`ApplyImport.php:111,236`).
 *
 * Es una bandera del LOTE y no de cada fila: una lista de la compra se pega
 * entera, y decidirlo carta a carta convertiría el gesto en 200 casillas.
 */
describe('a la lista de deseos', () => {
  async function conLaCasillaDeDeseos() {
    const montaje = await hastaLaPrevisualizacion()

    await montaje.wrapper.find('#a-deseos').setValue(true)
    await nextTick()

    return montaje
  }

  it('sin marcarla, `is_wishlist` NO viaja: el backend ya lo trata como false', async () => {
    const { wrapper } = await hastaLaPrevisualizacion()

    expect(wrapper.find('#a-deseos').exists()).toBe(true)

    await wrapper.find('.preview__confirmar button').trigger('click')
    await flushPromises()

    const cuerpo = apiCall.mock.calls.at(-1)[1]

    // `filter_var($peticion['is_wishlist'] ?? false, …)`: mandar un `false`
    // explícito solo ensucia el cuerpo de una importación de 20.000 líneas.
    expect(cuerpo).not.toHaveProperty('is_wishlist')
  })

  it('marcada, manda `is_wishlist: true` en la MISMA petición y con las mismas filas', async () => {
    const { wrapper } = await conLaCasillaDeDeseos()

    await wrapper.find('.preview__confirmar button').trigger('click')
    await flushPromises()

    const llamadas = apiCall.mock.calls.filter(([accion]) => accion === 'import_apply')
    const cuerpo = llamadas.at(-1)[1]

    // Una sola: el lote entero en una transacción, igual que la importación
    // normal. No hay endpoint nuevo ni segunda llamada a `collection_add`.
    expect(llamadas).toHaveLength(1)
    expect(cuerpo.is_wishlist).toBe(true)
    expect(cuerpo.rows.length).toBeGreaterThan(0)
    expect(apiCall).not.toHaveBeenCalledWith('collection_add', expect.anything())
  })

  it('no estorba a la del mazo: las dos son banderas del mismo lote', async () => {
    const { wrapper } = await conLaCasillaDeDeseos()

    await wrapper.find('#como-mazo').setValue(true)
    await nextTick()

    // El aviso de lo que significa marcarlas a la vez: el mazo se crea, pero
    // sus cartas quedan DESEADAS y no poseídas, así que las pedirá todas.
    expect(wrapper.find('.deseos__nota').text()).toContain('deseadas y no poseídas')

    await wrapper.findAll('.mazo__campo input')[0].setValue('Lista de la compra')
    await nextTick()

    await wrapper.find('.preview__confirmar button').trigger('click')
    await flushPromises()

    const cuerpo = apiCall.mock.calls.at(-1)[1]

    expect(cuerpo.is_wishlist).toBe(true)
    expect(cuerpo.deck).toEqual({ name: 'Lista de la compra', status: 'building' })
  })

  it('al terminar dice DÓNDE han caído las cartas, y lleva a la lista de deseos', async () => {
    const { wrapper } = await conLaCasillaDeDeseos()

    await wrapper.find('.preview__confirmar button').trigger('click')
    await flushPromises()
    await nextTick()

    // Decir «en tu colección» tras una importación a deseos sería mentir sobre
    // lo que el usuario acaba de elegir.
    const cifras = wrapper.find('.hecho__cifras').text()

    expect(cifras).toContain('tu lista de deseos')
    expect(cifras).not.toContain('tu colección')
    expect(wrapper.find('.hecho__acciones').text()).toContain('Ver mi lista de deseos')
    expect(wrapper.find('.hecho__acciones').text()).not.toContain('Ver mi colección')
  })

  it('sin marcarla, el resumen final sigue hablando de la colección', async () => {
    const { wrapper } = await hastaLaPrevisualizacion()

    await wrapper.find('.preview__confirmar button').trigger('click')
    await flushPromises()
    await nextTick()

    expect(wrapper.find('.hecho__cifras').text()).toContain('tu colección')
    expect(wrapper.find('.hecho__acciones').text()).toContain('Ver mi colección')
  })

  it('«importar otro fichero» la desmarca: la siguiente lista empieza limpia', async () => {
    const { wrapper } = await conLaCasillaDeDeseos()

    await wrapper.find('.preview__confirmar button').trigger('click')
    await flushPromises()
    await nextTick()

    // Es el botón de reiniciar del paso 3.
    const acciones = wrapper.findAll('.hecho__acciones button')

    await acciones[acciones.length - 1].trigger('click')
    await nextTick()

    // Una casilla pegajosa metería la colección de la siguiente importación en
    // la lista de deseos sin que nadie se diera cuenta.
    expect(wrapper.find('.caja__texto').exists()).toBe(true)

    await wrapper.find('.caja__texto').setValue('4 Lightning Bolt (M10) 146')
    await wrapper.find('.origen__acciones button').trigger('click')
    await flushPromises()
    await nextTick()

    expect(wrapper.find('#a-deseos').element.checked).toBe(false)
  })
})
