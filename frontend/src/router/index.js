import { createRouter, createWebHashHistory } from 'vue-router'
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
