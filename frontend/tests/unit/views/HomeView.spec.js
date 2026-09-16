import { beforeEach, describe, expect, it, vi } from 'vitest'
import { nextTick } from 'vue'
import { flushPromises } from '@vue/test-utils'

import HomeView from '@/views/HomeView.vue'
import { apiCall, catalogGet } from '@/services/api'

import { SESION, montarVista } from '../../helpers'

import valorFixture from '../../fixtures/collection_value.json'
import deckListFixture from '../../fixtures/deck_list.json'
import privacyGetFixture from '../../fixtures/privacy_get.json'
import logoutFixture from '../../fixtures/logout.json'
import friendListFixture from '../../fixtures/friend_list.json'
import friendListVacioFixture from '../../fixtures/friend_list_vacio.json'

/**
 * `views/HomeView.vue` — el dashboard.
 *
 * Lo que esta vista decide, que es lo único que se mira aquí:
 *
 *  - **No calcula ni un euro.** `valueEur`, `topCards`, `bySet` y `byRarity` los
 *    da `collection_value`, que el backend recalcula sobre los precios de hoy y
 *    **nunca** lee de una columna guardada. Aquí solo se formatean
 *    (`HomeView.vue:297-310`), así que las cifras de este fichero se leen de la
 *    fixtura capturada en vez de escribirse a mano.
 *  - **Dos llamadas que van por su lado** (`HomeView.vue:329-334`): el valor de
 *    la colección y la lista de mazos. Los mazos no pueden retrasar lo primero
 *    que el usuario viene a ver, y por eso el panel de mazos sale **aunque la
 *    colección esté vacía**: una decklist importada es un mazo sin cartas
 *    propias (`HomeView.vue:171`).
 *  - **Un `null` no es un cero.** Una carta que no cotiza dice «sin precio» y
 *    sigue contando como carta: es la regla del plan para toda la app.
 *  - **Y la barra no se rompe con la colección entera sin precio**: con el
 *    máximo a 0 la división daría NaN y se queda al 0 % (`HomeView.vue:316-318`).
 */

/**
 * Se doblan las dos funciones de I/O y nada más. El resto del módulo se conserva
 * porque `CardImage` compone la URL de cada joya con `API_BASE`
 * (`services/scryfall.js:23,42`): un doble sin esa constante deja `undefined` en
 * cada `<img>` y el render se llena de errores que no son de esta vista.
 */
vi.mock('@/services/api', async (importarOriginal) => ({
  ...(await importarOriginal()),
  apiCall: vi.fn(),
  catalogGet: vi.fn()
}))

/**
 * LA PLATAFORMA, que aquí es un interruptor.
 *
 * La vista y el router preguntan a `Capacitor.isNativePlatform()` —el mismo
 * patrón de `composables/useGoogleAuth.js:25`— y de esa respuesta depende si
 * `/scan` existe. Se dobla el módulo entero porque jsdom siempre diría «web»: sin
 * esto, la cara nativa no se podría probar y el enlace del APK quedaría sin red.
 *
 * `vi.hoisted` porque el factory de `vi.mock` se iza por encima de las
 * constantes del fichero. Y se dobla **solo** `isNativePlatform`, conservando el
 * resto del módulo: `registerPlugin` lo llama todo plugin de Capacitor al
 * importarse —`@codetrix-studio/capacitor-google-auth` desde `LoginView.vue`, a
 * donde llega el test de salir—, y un doble sin él revienta esa navegación.
 */
const plataforma = vi.hoisted(() => ({ nativo: false }))

vi.mock('@capacitor/core', async (importarOriginal) => {
  const original = await importarOriginal()

  return {
    ...original,
    Capacitor: { ...original.Capacitor, isNativePlatform: () => plataforma.nativo }
  }
})

/** Copia profunda: el store muta lo que recibe y las fixturas son de todos. */
function fixtura(json) {
  return structuredClone(json)
}

/**
 * Un doble que responde según la ACCIÓN, que es como funciona el endpoint
 * único. La vista dispara dos acciones en el mismo `onMounted`, así que una cola
 * de `mockResolvedValueOnce` se rompería al cambiar el orden.
 */
function respondeSegunAccion(mapa) {
  apiCall.mockImplementation((accion) =>
    Promise.resolve(
      mapa[accion] ?? { status: 'error', message: `Sin doble para ${accion}.`, http_code: 500 }
    )
  )
}

/** El mismo formateador que usa la vista: nada de euros escritos a mano. */
const EUROS = new Intl.NumberFormat('es-ES', {
  style: 'currency',
  currency: 'EUR',
  minimumFractionDigits: 2,
  maximumFractionDigits: 2
})

async function montarHome(opciones = {}) {
  const montaje = await montarVista(HomeView, { ruta: '/', estado: SESION, ...opciones })

  await flushPromises()
  await nextTick()

  return montaje
}

beforeEach(() => {
  // Web por defecto, que es donde corre la suite y donde corre el navegador.
  // Quien pruebe la cara nativa lo enciende en su propio test.
  plataforma.nativo = false
  apiCall.mockReset()
  catalogGet.mockReset()
  respondeSegunAccion({
    collection_value: fixtura(valorFixture),
    deck_list: fixtura(deckListFixture),
    // El panel de privacidad se monta aquí y pide lo suyo en su `onMounted`; su
    // comportamiento lo prueba `components/PrivacyPanel.spec.js`.
    privacy_get: fixtura(privacyGetFixture),
    // El contador de solicitudes pendientes, que vive en el menú de esta vista
    // porque la barra global que el plan mencionaba no existe. Por defecto, sin
    // ninguna: el número solo sale cuando hay algo esperando.
    friend_list: fixtura(friendListVacioFixture)
  })
})

describe('el primer render', () => {
  it('monta sin lanzar y pide el valor de la colección y la lista de mazos', async () => {
    const { wrapper, errores } = await montarHome()

    expect(errores).toEqual([])

    // Dos llamadas separadas y ninguna anidada: los mazos no retrasan el valor.
    expect(apiCall).toHaveBeenCalledWith('collection_value')
    expect(apiCall).toHaveBeenCalledWith('deck_list')

    // La barra de navegación es lo que da acceso a las zonas sin teclear una
    // URL, y va aquí y no en `App.vue` a propósito (`HomeView.vue:6-11`).
    // Siete desde la lista de deseos, que es una RUTA y no un filtro de
    // `/collection`: si dejara de estar en el menú, no habría forma de llegar.
    // Ocho desde `/friends`, por lo mismo: es la única puerta a esa pantalla.
    //
    // El noveno, `/scan`, DEPENDE DE LA PLATAFORMA y por eso no se cuenta aquí
    // sino en los dos tests de abajo. Las dos caras siguen siendo ciertas a la
    // vez: en el APK el enlace es obligatorio —el webview de Capacitor no tiene
    // barra de direcciones ni deep link, así que una ruta sin enlace es
    // **inalcanzable allí**—, y en web no debe existir, porque la vista vive de
    // dos plugins nativos que el navegador no tiene.
    //
    // El enlace del spike M0 se excluye a propósito: es temporal y se va con
    // `src/spike/`, así que contarlo pondría este test en rojo el día que se
    // borre por hacer justo lo que está escrito que hay que hacer.
    const definitivos = wrapper.findAll('.home__nav a:not(.home__link--spike)')
    expect(definitivos.map((a) => a.attributes('href')))
      .toEqual(expect.arrayContaining(['#/wishlist', '#/friends']))
    expect(wrapper.find('.home__user').text()).toContain(SESION.auth.user.display_name)
  })

  it('monta el panel de privacidad, que es donde vive una preferencia de cuenta', async () => {
    const { wrapper } = await montarHome()

    // Va aquí y no en una ruta propia: esta es la única vista con la navegación
    // de la app y con la identidad del usuario —su avatar, su nombre y el botón
    // de salir—, y el perfil público es la otra cara de ese mismo bloque. Las
    // únicas rutas que estrenó este plan son las dos públicas.
    expect(apiCall).toHaveBeenCalledWith('privacy_get')
    expect(wrapper.find('.privacidad').exists()).toBe(true)
    // Seis filas desde el M6: las cinco secciones de contenido y el sexto
    // selector, «Aparecer en el buscador», que es donde se apaga el buscador de
    // `/friends`. Quien las distingue una a una es `PrivacyPanel.spec.js`; aquí
    // lo único que importa es que el panel entero llegue montado.
    expect(wrapper.findAll('.privacidad .seccion')).toHaveLength(6)
    expect(wrapper.findAll('.privacidad .seccion--busqueda')).toHaveLength(1)
  })

  it('pinta las cuatro cifras con lo que dio el backend, sin recalcular nada', async () => {
    const { wrapper } = await montarHome()

    const totales = fixtura(valorFixture).data.totals
    const cifras = wrapper.findAll('.tarjeta__cifra').map((c) => c.text())

    expect(cifras).toEqual([
      EUROS.format(totales.valueEur),
      String(totales.totalCopies),
      String(totales.uniqueCards),
      String(totales.itemsWithoutPrice)
    ])

    // La línea que no cotiza cuenta como carta y solo deja de sumar euros: sin
    // este dato un total bajo sería ambiguo (`HomeView.vue:73-78`).
    expect(wrapper.text()).toContain('Cuentan como cartas; no suman euros.')
  })

  it('las joyas salen en el orden del backend y enlazan a la ficha de su impresión', async () => {
    const { wrapper, router } = await montarHome()

    const joyas = fixtura(valorFixture).data.topCards
    const filas = wrapper.findAll('.joya')

    expect(filas).toHaveLength(joyas.length)
    expect(filas[0].find('.joya__nombre').text()).toBe(joyas[0].name)
    expect(filas[0].find('.joya__precio').text()).toBe(EUROS.format(joyas[0].priceEur))

    // El clic lleva a `/card/:uuid` por el `printingUuid` —la impresión concreta
    // que tienes—, no por el `oracleId` (`HomeView.vue:320-322`).
    await filas[0].find('.joya__nombre').trigger('click')

    // `vi.waitFor` y no un `flushPromises`: el componente de destino es un
    // `import()` perezoso (`router/index.js:37-41`).
    await vi.waitFor(() => expect(router.currentRoute.value.name).toBe('card'))
    expect(router.currentRoute.value.params.uuid).toBe(joyas[0].printingUuid)
  })

  it('los desgloses se cortan a seis ediciones y la barra se mide contra la mayor', async () => {
    const { wrapper } = await montarHome()

    const datos = fixtura(valorFixture).data
    const barras = wrapper.findAll('.barras')

    // Seis en portada; el resto está en `/sets`, y el enlace lo dice con su
    // número total (`HomeView.vue:280,123-125`).
    const ediciones = barras[0].findAll('.barra')

    expect(ediciones).toHaveLength(6)
    expect(wrapper.find('.panel__enlace').text()).toBeTruthy()
    expect(wrapper.text()).toContain(`${datos.bySet.length} ediciones`)

    // La mayor marca la escala: va al 100 % y el resto se mide contra ella.
    const mayor = Math.max(...datos.bySet.slice(0, 6).map((f) => f.valueEur))
    const anchos = ediciones.map((b) => b.find('.barra__relleno').attributes('style'))

    expect(anchos[0]).toContain('width: 100%')
    expect(anchos).toHaveLength(6)
    expect(datos.bySet[0].valueEur).toBe(mayor)

    // Y una fila por rareza de las que trajo el backend, sin inventar las que
    // no tienes.
    expect(barras[1].findAll('.barra')).toHaveLength(datos.byRarity.length)
  })
})

describe('los casos que no son el feliz', () => {
  it('una colección entera sin precio deja las barras a cero y no en NaN', async () => {
    // Con el máximo a 0 la división daría NaN y la barra se rompería
    // (`HomeView.vue:316-318`). Se altera la fixtura EN MEMORIA.
    const valor = fixtura(valorFixture)

    valor.data.bySet.forEach((fila) => { fila.valueEur = 0 })
    valor.data.byRarity.forEach((fila) => { fila.valueEur = 0 })

    respondeSegunAccion({ collection_value: valor, deck_list: fixtura(deckListFixture) })

    const { wrapper, errores } = await montarHome()

    const anchos = wrapper.findAll('.barra__relleno').map((b) => b.attributes('style'))

    expect(errores).toEqual([])
    expect(anchos.every((estilo) => estilo.includes('width: 0%'))).toBe(true)
  })

  it('sin cartas ni mazos sale el vacío con el camino para empezar', async () => {
    const valor = fixtura(valorFixture)

    valor.data.totals.uniqueItems = 0

    respondeSegunAccion({
      collection_value: valor,
      deck_list: { status: 'success', message: 'Mazos.', data: { decks: [], count: 0 }, http_code: 200 }
    })

    const { wrapper } = await montarHome()

    expect(wrapper.find('.home__empty').exists()).toBe(true)
    expect(wrapper.findAll('.tarjeta')).toHaveLength(0)
    expect(wrapper.find('.panel--mazos').exists()).toBe(false)
  })

  it('sin cartas pero con mazos, el panel de mazos ocupa el sitio del vacío', async () => {
    // Un mazo puede existir sin colección —una decklist importada como mazo—, y
    // ahí el panel sigue teniendo algo que decir. El `v-else` del vacío cuelga
    // de ese mismo `v-if` (`HomeView.vue:171,204`), así que o sale uno o sale el
    // otro: con mazos, el vacío **no** se pinta.
    const valor = fixtura(valorFixture)

    valor.data.totals.uniqueItems = 0

    respondeSegunAccion({ collection_value: valor, deck_list: fixtura(deckListFixture) })

    const { wrapper } = await montarHome()

    expect(wrapper.findAll('.tarjeta')).toHaveLength(0)
    expect(wrapper.find('.panel--mazos').exists()).toBe(true)
    expect(wrapper.find('.home__empty').exists()).toBe(false)
  })

  it('el panel de mazos cuenta los tres estados y solo valora lo construido', async () => {
    const { wrapper } = await montarHome()

    const mazos = fixtura(deckListFixture).data.decks
    const construidos = mazos.filter((m) => m.status === 'built')
    const estados = wrapper.findAll('.mazos__estado')

    // Los tres estados salen SIEMPRE, también con 0: un hueco que aparece y
    // desaparece se lee peor que un cero (`stores/deck.js:209-219`).
    expect(estados).toHaveLength(4) // tres estados + el valor
    expect(estados[0].text()).toContain(String(construidos.length))
    expect(estados[0].text()).toContain('Construido')
    expect(estados[1].text()).toContain('0')
    expect(estados[1].text()).toContain('En construcción')

    // Y solo los `built` suman: los tres estados no son simétricos y sumar los
    // `building` diría que tienes montado lo que estás juntando.
    const valorConstruido = construidos.reduce((suma, m) => suma + m.valueEur, 0)

    expect(wrapper.find('.mazos__estado--valor').text()).toContain(EUROS.format(valorConstruido))
  })

  it('un error del backend se enseña y no tumba el resto de la pantalla', async () => {
    respondeSegunAccion({
      collection_value: { status: 'error', message: 'No autorizado.', http_code: 401 },
      deck_list: { status: 'error', message: 'No autorizado.', http_code: 401 }
    })

    const { wrapper, errores } = await montarHome()

    expect(errores).toEqual([])
    expect(wrapper.find('.home__error').text()).toContain('No autorizado.')
    // Los accesos siguen ahí: son el camino a empezar aunque no haya nada.
    expect(wrapper.findAll('.acceso')).toHaveLength(8)
  })
})

/**
 * EL CONTADOR DE SOLICITUDES PENDIENTES, que el plan pedía «en la barra».
 *
 * Esa barra no existe: `App.vue` son veinte líneas con un `<router-view />` y
 * nada más, así que la navegación global de la app es esta rejilla de enlaces de
 * la portada. Aquí es donde va, y esto es lo que se comprueba.
 */
describe('el contador de solicitudes pendientes', () => {
  it('la portada pide `friend_list` y SOLO esa: aquí no se pinta a quién sigues', async () => {
    await montarHome()

    expect(apiCall).toHaveBeenCalledWith('friend_list')
    // `follow_list` sería una petición para un número que esta pantalla no
    // enseña, encima de las dos que ya hace.
    expect(apiCall).not.toHaveBeenCalledWith('follow_list')
  })

  it('con solicitudes esperando, el número sale junto al enlace de Amigos', async () => {
    respondeSegunAccion({
      collection_value: fixtura(valorFixture),
      deck_list: fixtura(deckListFixture),
      privacy_get: fixtura(privacyGetFixture),
      friend_list: fixtura(friendListFixture)
    })

    const { wrapper } = await montarHome()

    const cuentas = fixtura(friendListFixture).data.counts

    expect(wrapper.find('.home__badge').text()).toBe(String(cuentas.pending))
    // Las RECIBIDAS y no las enviadas: la fixtura trae una de cada, así que
    // sumarlas daría un 2. Las enviadas esperan a la otra persona.
    expect(cuentas.sent).toBe(1)
    expect(wrapper.find('.home__badge').text()).not.toBe('2')

    // Y el acceso grande lo dice con palabras, para quien no mire el menú.
    const acceso = wrapper.findAll('.acceso').find((a) => a.text().includes('Amigos'))

    expect(acceso.text()).toMatch(/1 solicitud esperando respuesta/i)
  })

  it('sin solicitudes NO hay número: un cero permanente enseña a no mirarlo', async () => {
    respondeSegunAccion({
      collection_value: fixtura(valorFixture),
      deck_list: fixtura(deckListFixture),
      privacy_get: fixtura(privacyGetFixture),
      friend_list: fixtura(friendListVacioFixture)
    })

    const { wrapper } = await montarHome()

    expect(wrapper.find('.home__badge').exists()).toBe(false)
    expect(wrapper.findAll('.home__nav a').map((a) => a.attributes('href'))).toContain('#/friends')
  })

  it('un fallo del listado NO pinta ningún error en la portada', async () => {
    // El usuario no ha pedido nada de esto: un aviso de amistades encima del
    // valor de su colección sería ruido por algo que nadie miraba.
    respondeSegunAccion({
      collection_value: fixtura(valorFixture),
      deck_list: fixtura(deckListFixture),
      privacy_get: fixtura(privacyGetFixture),
      friend_list: { status: 'error', message: 'No autorizado.', http_code: 401 }
    })

    const { wrapper, errores } = await montarHome()

    expect(errores).toEqual([])
    expect(wrapper.find('.home__badge').exists()).toBe(false)
    expect(wrapper.find('.home__error').exists()).toBe(false)
  })

  it('el enlace lleva de verdad a /friends', async () => {
    const { wrapper, router } = await montarHome()

    const enlace = wrapper.findAll('.home__nav a').find((a) => a.attributes('href') === '#/friends')

    await enlace.trigger('click')

    // `vi.waitFor` y no `flushPromises`: el componente de destino es un
    // `import()` perezoso (`router/index.js`).
    await vi.waitFor(() => expect(router.currentRoute.value.name).toBe('friends'))
  })
})

describe('salir', () => {
  it('cierra la sesión por logout y lleva a /login', async () => {
    respondeSegunAccion({
      collection_value: fixtura(valorFixture),
      deck_list: fixtura(deckListFixture),
      logout: fixtura(logoutFixture)
    })

    const { wrapper, router, pinia } = await montarHome()

    await wrapper.find('.home__user button').trigger('click')
    await flushPromises()

    expect(apiCall).toHaveBeenCalledWith('logout')

    // El guard manda a `/login` solo si la sesión ya está limpia: si `logout()`
    // no vaciara el estado, `router/index.js:91-93` rebotaría a `/`.
    await vi.waitFor(() => expect(router.currentRoute.value.name).toBe('login'))
    expect(pinia.state.value.auth.isAuthenticated).toBe(false)
  })
})

/**
 * EL ESCÁNER, QUE SOLO SE OFRECE DONDE FUNCIONA.
 *
 * `/scan` vive de `CameraPreview` y del OCR de ML Kit, dos plugins **nativos**:
 * en el navegador no hay nada que enseñar. Las dos caras son ciertas a la vez y
 * por eso van dos tests y no uno —el comentario del recuento de arriba explica
 * el porqué de cada una—:
 *
 *  - **en el APK**, el enlace es obligatorio: el webview de Capacitor no tiene
 *    barra de direcciones ni deep link, así que una ruta sin enlace es
 *    inalcanzable;
 *  - **en web**, ni enlace ni ruta.
 *
 * La plataforma se dobla arriba (`plataforma.nativo`), nunca por `userAgent` ni
 * por ancho de pantalla: un móvil con el navegador tampoco tiene los plugins.
 */
describe('el escáner solo donde funciona', () => {
  /** Los `href` del menú de escritorio, que es la lista de destinos ofrecidos. */
  function destinos(wrapper) {
    return wrapper.findAll('.home__nav a:not(.home__link--spike)').map((a) => a.attributes('href'))
  }

  it('en web el menú son OCHO enlaces y ninguno es /scan', async () => {
    const { wrapper } = await montarHome()

    const enlaces = destinos(wrapper)

    expect(enlaces).toHaveLength(8)
    expect(enlaces).not.toContain('#/scan')
    expect(enlaces).toEqual(expect.arrayContaining(['#/wishlist', '#/friends']))
  })

  it('en nativo son NUEVE, con /scan entre ellos', async () => {
    plataforma.nativo = true

    const { wrapper } = await montarHome()

    const enlaces = destinos(wrapper)

    expect(enlaces).toHaveLength(9)
    expect(enlaces).toContain('#/scan')
  })

  it('en web, teclear #/scan devuelve a la portada', async () => {
    // El enlace escondido no basta: el hash se teclea y un enlace viejo sigue en
    // el historial. Sin el guard, la vista se monta y muere con un error de
    // plugin que no explica nada.
    const { router } = await montarHome()

    await router.push('/scan')

    expect(router.currentRoute.value.name).toBe('home')
  })

  it('en nativo, el guard de /scan deja pasar', async () => {
    plataforma.nativo = true

    const { router } = await montarHome()

    // Se llama al guard DIRECTAMENTE en vez de navegar: `router.push('/scan')`
    // resolvería el `import()` perezoso de `ScanView.vue`, que arrastra tres
    // plugins de Capacitor y pediría doblarlos aquí para probar una línea del
    // router. Lo que importa es lo que devuelve el guard.
    const scan = router.getRoutes().find((ruta) => ruta.name === 'scan')
    const guardas = [scan.beforeEnter].flat().filter(Boolean)

    expect(guardas).toHaveLength(1)
    expect(guardas[0]()).toBe(true)

    plataforma.nativo = false
    expect(guardas[0]()).toEqual({ name: 'home' })
  })
})

/**
 * LA NAVEGACIÓN RECOGIDA, para pantallas estrechas.
 *
 * Los nueve enlaces en una fila con `flex-wrap` se apilan y se comen la pantalla
 * de un móvil antes de que se vea un euro de la colección. Por debajo de los
 * 800px la fila se esconde por CSS y sale un botón que abre un `Menu` de
 * PrimeVue en modo `popup`.
 *
 * Quién se ve lo decide el media query, así que **los dos bloques están siempre
 * montados**: aquí no se mide el ancho —`css: false` en `vitest.config.js`, y
 * jsdom no hace layout—, se comprueba que el desplegable ofrece lo mismo que la
 * fila y que el aviso de solicitudes no se pierde dentro.
 *
 * OJO: el popup se teletransporta al `document.body` y NO está en
 * `wrapper.html()`. Se monta con `attachTo` y se busca allí.
 */
describe('la navegación recogida en móvil', () => {
  /** Abre el desplegable de verdad y devuelve sus enlaces, ya en el `body`. */
  async function abrirMenu(wrapper) {
    await wrapper.find('.home__hamburguesa').trigger('click')
    await flushPromises()
    await nextTick()

    return [...document.body.querySelectorAll('.home__menu a')]
  }

  it('cerrado no pinta nada: el popup no existe hasta que se abre', async () => {
    const { wrapper } = await montarHome({ attachTo: document.body })

    expect(wrapper.find('.home__hamburguesa').exists()).toBe(true)
    expect(document.body.querySelectorAll('.home__menu a')).toHaveLength(0)
  })

  it('abierto ofrece los MISMOS destinos que la fila, y con sus href', async () => {
    const { wrapper } = await montarHome({ attachTo: document.body })

    const fila = wrapper.findAll('.home__nav a').map((a) => a.attributes('href'))
    const menu = (await abrirMenu(wrapper)).map((a) => a.getAttribute('href'))

    // La misma lista y en el mismo orden: las dos caras salen de `DESTINOS`, y
    // dos listas escritas a mano se desincronizan solas.
    expect(menu).toEqual(fila)
    expect(menu).toHaveLength(8)
    expect(menu).not.toContain('#/scan')
  })

  it('en nativo el desplegable también lleva /scan', async () => {
    plataforma.nativo = true

    const { wrapper } = await montarHome({ attachTo: document.body })

    expect((await abrirMenu(wrapper)).map((a) => a.getAttribute('href'))).toContain('#/scan')
  })

  it('un destino del desplegable navega de verdad', async () => {
    const { wrapper, router } = await montarHome({ attachTo: document.body })

    const enlaces = await abrirMenu(wrapper)
    const amigos = enlaces.find((a) => a.getAttribute('href') === '#/friends')

    amigos.dispatchEvent(new MouseEvent('click', { bubbles: true }))

    // `vi.waitFor` y no `flushPromises`: el componente de destino es un
    // `import()` perezoso (`router/index.js`).
    await vi.waitFor(() => expect(router.currentRoute.value.name).toBe('friends'))
  })

  it('con solicitudes esperando, el número sale en el BOTÓN cerrado', async () => {
    // Un aviso que hay que abrir un menú para ver no avisa: el badge va también
    // sobre la hamburguesa, y es el mismo número que el del enlace de Amigos.
    respondeSegunAccion({
      collection_value: fixtura(valorFixture),
      deck_list: fixtura(deckListFixture),
      privacy_get: fixtura(privacyGetFixture),
      friend_list: fixtura(friendListFixture)
    })

    const { wrapper } = await montarHome({ attachTo: document.body })

    const cuentas = fixtura(friendListFixture).data.counts

    expect(wrapper.find('.home__badge--boton').text()).toBe(String(cuentas.pending))

    // Y dentro, junto a su destino, para saber de qué es el aviso.
    const dentro = (await abrirMenu(wrapper))
      .find((a) => a.getAttribute('href') === '#/friends')

    expect(dentro.querySelector('.home__badge').textContent.trim()).toBe(String(cuentas.pending))
  })

  it('sin solicitudes, el botón va limpio', async () => {
    const { wrapper } = await montarHome({ attachTo: document.body })

    expect(wrapper.find('.home__badge--boton').exists()).toBe(false)
  })
})
