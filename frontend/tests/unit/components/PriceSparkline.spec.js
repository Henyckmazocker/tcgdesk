import { describe, expect, it } from 'vitest'

import PriceSparkline from '@/components/PriceSparkline.vue'

import { SESION, montarVista } from '../../helpers'

import cartaFixture from '../../fixtures/catalog_card.json'

/**
 * `components/PriceSparkline.vue` — la única gráfica del proyecto, en SVG a mano.
 *
 * Sin librería de charts a propósito: chart.js sumaría ~200 KB al bundle —que
 * también viaja dentro del APK— para dibujar una línea.
 *
 * Lo que se prueba es su aritmética, que es lo único que hay aquí:
 *
 *  - **El histórico NO se ordena en el cliente**: viene ya ordenado por fecha del
 *    backend, así que el primer y el último punto son los extremos del periodo.
 *  - **Un rango de 0 no puede dar NaN.** Si el precio no se movió en todo el
 *    periodo, `(valor - minimo) / rango` sería una división por cero: se dibuja
 *    plano por el centro, que es lo que de verdad pasó (`PriceSparkline.vue:73-83`).
 *  - **Con menos de dos puntos no se dibuja nada**: una línea de un solo punto no
 *    es una tendencia.
 */

/** El histórico capturado, quedándonos con una sola serie (`normal`). */
const HISTORICO = cartaFixture.priceHistory.filter((punto) => punto.finish === 'normal')

function montarGrafica(props = {}) {
  return montarVista(PriceSparkline, {
    ruta: '/catalog',
    estado: SESION,
    props: { historico: structuredClone(HISTORICO), etiqueta: 'Normal', ...props }
  })
}

describe('la gráfica', () => {
  it('pinta el rango, las dos fechas extremas y el último precio', async () => {
    const { wrapper, errores } = await montarGrafica()

    const valores = HISTORICO.map((p) => p.priceEur)
    const minimo = Math.min(...valores)
    const maximo = Math.max(...valores)

    expect(errores).toEqual([])
    expect(wrapper.find('.sparkline__cabecera').text())
      .toBe(`Normal${minimo.toFixed(2)} € – ${maximo.toFixed(2)} €`)

    const pie = wrapper.find('.sparkline__pie')

    // Las fechas salen tal cual del backend, en los extremos del array: el
    // orden lo pone el SQL, no el cliente.
    expect(pie.text()).toContain(HISTORICO[0].date)
    expect(pie.text()).toContain(HISTORICO[HISTORICO.length - 1].date)
    expect(pie.find('strong').text()).toBe(`${valores[valores.length - 1].toFixed(2)} €`)
  })

  it('la tendencia sale de comparar el último con el primero, no de una media', async () => {
    const subiendo = [
      { date: '2026-01-01', priceEur: 1 },
      { date: '2026-01-02', priceEur: 9 },
      { date: '2026-01-03', priceEur: 2 }
    ]

    const { wrapper } = await montarGrafica({ historico: subiendo })

    // 2 > 1 → sube, aunque por el medio haya pasado por 9.
    expect(wrapper.find('.sparkline__pie strong').classes()).toContain('sube')

    const { wrapper: bajando } = await montarGrafica({
      historico: [...subiendo].reverse()
    })

    expect(bajando.find('.sparkline__pie strong').classes()).toContain('baja')
  })

  it('dibuja un punto por fecha y cierra el área bajo la curva', async () => {
    const { wrapper } = await montarGrafica({
      historico: [
        { date: '2026-01-01', priceEur: 1 },
        { date: '2026-01-02', priceEur: 3 },
        { date: '2026-01-03', priceEur: 2 }
      ]
    })

    const linea = wrapper.find('.sparkline__linea').attributes('d')
    const area = wrapper.find('.sparkline__area').attributes('d')

    // Tres puntos: un `M` y dos `L`, repartidos de 0 a 300 (el ancho del viewBox).
    expect(linea.startsWith('M 0.00 ')).toBe(true)
    expect(linea.match(/L /g)).toHaveLength(2)
    expect(linea).toContain('L 300.00 ')

    // El área es la misma línea cerrada contra el suelo del viewBox.
    expect(area).toBe(`${linea} L 300 60 L 0 60 Z`)

    // Y el círculo del último valor va pegado al borde derecho.
    expect(wrapper.find('.sparkline__ultimo').attributes('cx')).toBe('300')
  })

  it('un precio que no se movió se dibuja PLANO por el centro, y no en NaN', async () => {
    const { wrapper } = await montarGrafica({
      historico: [
        { date: '2026-01-01', priceEur: 2.5 },
        { date: '2026-01-02', priceEur: 2.5 },
        { date: '2026-01-03', priceEur: 2.5 }
      ]
    })

    const linea = wrapper.find('.sparkline__linea').attributes('d')

    // Mitad del alto (60 / 2 = 30) en los tres puntos, y ni un `NaN` en el path.
    expect(linea).toBe('M 0.00 30.00 L 150.00 30.00 L 300.00 30.00')
    expect(linea).not.toContain('NaN')
    expect(wrapper.find('.sparkline__pie strong').classes()).toEqual([])
  })

  it('el aria-label cuenta la evolución, porque el SVG no se lee', async () => {
    const { wrapper } = await montarGrafica({
      historico: [
        { date: '2026-01-01', priceEur: 1.2 },
        { date: '2026-01-02', priceEur: 3.4 }
      ]
    })

    expect(wrapper.find('svg').attributes('aria-label'))
      .toBe('Evolución del precio Normal: de 1.20 € a 3.40 €')
    expect(wrapper.find('svg').attributes('role')).toBe('img')
  })
})

describe('cuando no hay histórico que dibujar', () => {
  it('con un solo punto no se dibuja una tendencia que no existe', async () => {
    const { wrapper } = await montarGrafica({
      historico: [{ date: '2026-01-01', priceEur: 1.2 }]
    })

    expect(wrapper.find('svg').exists()).toBe(false)
    expect(wrapper.find('.sparkline__sin-datos').text())
      .toBe('Sin histórico suficiente para Normal.')
  })

  it('sin ningún punto tampoco, y lo dice con la etiqueta que le toca', async () => {
    const { wrapper } = await montarGrafica({ historico: [], etiqueta: 'Foil' })

    expect(wrapper.find('.sparkline__sin-datos').text())
      .toBe('Sin histórico suficiente para Foil.')
  })
})
