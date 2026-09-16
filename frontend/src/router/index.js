import { createRouter, createWebHashHistory } from 'vue-router'
import { Capacitor } from '@capacitor/core'
import { useAuthStore } from '@/stores/auth'

/**
 * `createWebHashHistory` y no `createWebHistory`, igual que en el resto de
 * repos: con Capacitor la app se sirve desde file:// o capacitor://, donde el
 * history mode de HTML5 no resuelve rutas.
 */
const routes = [
  {
    path: '/login',
    name: 'login',
    component: () => import('@/views/LoginView.vue'),
    meta: { public: true }
  },
  {
    path: '/',
    name: 'home',
    component: () => import('@/views/HomeView.vue')
  },
  {
    path: '/catalog',
    name: 'catalog',
    component: () => import('@/views/CatalogView.vue')
  },
  {
    path: '/collection',
    name: 'collection',
    component: () => import('@/views/CollectionView.vue')
  },
  // Ruta propia y no un filtro de `/collection`: `is_wishlist` no recorta la
  // lista, ELIGE el conjunto —el `WHERE ci.is_wishlist = :is_wishlist` está
  // siempre en la consulta—. Un desplegable "deseos" junto a uno de "rareza"
  // mentiría sobre lo que hace.
  {
    path: '/wishlist',
    name: 'wishlist',
    component: () => import('@/views/WishlistView.vue')
  },
  {
    path: '/import',
    name: 'import',
    component: () => import('@/views/ImportView.vue')
  },
  {
    path: '/sets',
    name: 'sets',
    component: () => import('@/views/SetsView.vue')
  },
  {
    path: '/card/:uuid',
    name: 'card',
    component: () => import('@/views/CardView.vue')
  },
  {
    path: '/precons',
    name: 'precons',
    component: () => import('@/views/PreconsView.vue')
  },
  // El `fileName` va en la ruta y no en la query por lo mismo que el id del
  // mazo: un precon es un recurso y su enlace tiene que poder compartirse. Y es
  // `fileName` —`SneakAttack_ZNC`— y no el nombre: hay cajas homónimas en
  // ediciones distintas, y esta es la clave natural de `mtg_precon`.
  {
    path: '/precon/:fileName',
    name: 'precon',
    component: () => import('@/views/PreconView.vue')
  },
  {
    path: '/decks',
    name: 'decks',
    component: () => import('@/views/DecksView.vue')
  },
  // El id va en la ruta y no en la query porque un mazo es un recurso: el
  // enlace tiene que poder compartirse y volver al mismo sitio al recargar.
  {
    path: '/deck/:id',
    name: 'deck',
    component: () => import('@/views/DeckView.vue')
  },
  /**
   * `/friends` — tus amigos, tus solicitudes y a quién sigues.
   *
   * **Va SIN `meta.public`, o sea tras el guard**, y es la única ruta del Plan -
   * Amigos y Seguimiento que está de este lado. Es correcto y es lo que dice el
   * plan: no es el perfil de nadie, es TU lista. Todo lo que pinta sale de
   * `friend_list` y `follow_list`, dos acciones `POST` con `AuthMiddleware` cuya
   * única entrada es el `user_id` de la sesión: sin sesión no hay nada que
   * enseñar, ni siquiera vacío.
   *
   * Y va ANTES del catch-all de abajo, como las públicas: aquel redirige a `/`
   * todo lo que no case, así que una ruta declarada después no se alcanza nunca.
   */
  {
    path: '/friends',
    name: 'friends',
    component: () => import('@/views/FriendsView.vue')
  },
  /**
   * `/scan` — el escáner por cámara.
   *
   * **Sin `meta.public`**, y no por inercia: el escáner acaba escribiendo en TU
   * colección, así que sin sesión no hay nada que escanear ni dónde dejarlo. Es
   * el mismo criterio que `/friends`.
   *
   * Y **antes del catch-all** de abajo, que redirige a `/` todo lo que no case:
   * declarada después no se alcanzaría nunca.
   *
   * Tiene su enlace en el menú de `HomeView.vue` **cuando la app corre en
   * nativo**, y eso es parte de la ruta y no un adorno: el webview de Capacitor
   * **no tiene barra de direcciones** y el manifest solo declara el
   * intent-filter `MAIN`/`LAUNCHER`, así que no hay deep link por el que entrar.
   * Una ruta sin enlace funciona en `npm run dev` tecleando el hash y es
   * **inalcanzable en el APK**. Lo aprendió el spike del M0 a golpes.
   *
   * Y **`beforeEnter` la cierra en web**, que es la otra mitad de lo mismo: la
   * vista vive de `CameraPreview` y del OCR de ML Kit, dos plugins **nativos**
   * que en el navegador no existen. Esconder el enlace no basta —el hash se
   * teclea, y un enlace viejo en el historial sigue ahí—, y montar la vista
   * fuera del APK solo da una pantalla negra con un error de plugin. Se vuelve a
   * la portada, que es de donde se venía.
   *
   * La plataforma se pregunta a `Capacitor.isNativePlatform()` y a nada más,
   * igual que en `composables/useGoogleAuth.js:25`: el `userAgent` y el ancho de
   * pantalla dirían que sí en un móvil con el navegador abierto, donde tampoco
   * hay plugins.
   */
  {
    path: '/scan',
    name: 'scan',
    component: () => import('@/views/ScanView.vue'),
    beforeEnter: () => (Capacitor.isNativePlatform() ? true : { name: 'home' })
  },
  /**
   * LAS DOS RUTAS PÚBLICAS, y las únicas junto a `/login` que llevan
   * `meta: { public: true }`.
   *
   * El guard de abajo ya respeta esa marca tal cual, así que **aquí no cambia
   * ninguna lógica**: el router solo gana dos rutas. Lo que sí importa es que
   * vayan ANTES del catch-all —que redirige a `/` todo lo que no case— o nunca
   * se alcanzarían.
   *
   * Ninguna de las dos puede depender del store de sesión para cargar: quien
   * las abre viene de un enlace pegado fuera y puede no tener nada en
   * `localStorage`. Lo que necesitan lo piden por `publicGet`.
   */
  {
    path: '/user/:username',
    name: 'publicProfile',
    component: () => import('@/views/PublicProfileView.vue'),
    meta: { public: true }
  },
  // El token va en la URL y acaba en el historial del navegador y en cualquier
  // proxy. Es inherente a «compartir por enlace», y por eso existe la acción de
  // dejar de compartir, que lo invalida.
  {
    path: '/shared/deck/:token',
    name: 'sharedDeck',
    component: () => import('@/views/SharedDeckView.vue'),
    meta: { public: true }
  },
  {
    path: '/:pathMatch(.*)*',
    redirect: '/'
  }
]

const router = createRouter({
  history: createWebHashHistory(),
  routes
})

router.beforeEach((to) => {
  const auth = useAuthStore()

  if (!to.meta.public && !auth.isAuthenticated) {
    // `redirect` para volver adonde ibas después de entrar.
    return { name: 'login', query: to.fullPath === '/' ? {} : { redirect: to.fullPath } }
  }

  if (to.name === 'login' && auth.isAuthenticated) {
    return { name: 'home' }
  }

  return true
})

export default router
