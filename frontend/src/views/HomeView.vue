<template>
  <div class="home">
    <header class="home__bar">
      <h1 class="home__title">TCGDesk</h1>
      <nav class="home__nav">
        <router-link :to="{ name: 'catalog' }" class="home__link">
          <i class="pi pi-search"></i> Catálogo
        </router-link>
      </nav>
      <div class="home__user">
        <img v-if="auth.user?.avatar_url" :src="auth.user.avatar_url" class="home__avatar" alt="">
        <span>{{ auth.displayName }}</span>
        <Button label="Salir" severity="secondary" text size="small" @click="logout" />
      </div>
    </header>

    <main class="home__main">
      <section class="home__accesos">
        <!--
          Un acceso por zona de la app. La colección todavía no tiene el suyo:
          registrar cartas lo trae el Plan - Colección y Vistas.
        -->
        <router-link :to="{ name: 'catalog' }" class="acceso">
          <i class="pi pi-search acceso__icono"></i>
          <span class="acceso__titulo">Catálogo</span>
          <span class="acceso__nota">Busca entre 110.384 cartas en diez idiomas, con precio en euros</span>
        </router-link>

        <router-link :to="{ name: 'catalog', query: { sort: 'price_desc' } }" class="acceso">
          <i class="pi pi-euro acceso__icono"></i>
          <span class="acceso__titulo">Las más caras</span>
          <span class="acceso__nota">El catálogo ordenado por precio de Cardmarket</span>
        </router-link>

        <router-link :to="{ name: 'catalog', query: { sort: 'release' } }" class="acceso">
          <i class="pi pi-sparkles acceso__icono"></i>
          <span class="acceso__titulo">Novedades</span>
          <span class="acceso__nota">Las ediciones más recientes, primero</span>
        </router-link>
      </section>

      <div class="home__empty">
        <i class="pi pi-inbox home__icon"></i>
        <p>Tu colección está vacía.</p>
        <small>Añadir cartas a tu colección llega en el siguiente plan.</small>
      </div>
    </main>
  </div>
</template>

<script setup>
import { useRouter } from 'vue-router'
import Button from 'primevue/button'
import { useAuthStore } from '@/stores/auth'

const auth = useAuthStore()
const router = useRouter()

async function logout() {
  await auth.logout()
  router.replace('/login')
}
</script>

<style scoped>
.home__bar {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 1rem;
  padding: 0.75rem 1.25rem;
  border-bottom: 1px solid var(--p-content-border-color);
}

.home__title {
  margin: 0;
  font-size: 1.25rem;
}

.home__user {
  display: flex;
  align-items: center;
  gap: 0.75rem;
}

.home__avatar {
  width: 32px;
  height: 32px;
  border-radius: 50%;
}

.home__nav {
  display: flex;
  gap: 1rem;
  margin-left: auto;
  margin-right: 1rem;
}

.home__link {
  display: inline-flex;
  align-items: center;
  gap: 0.4rem;
  color: var(--p-text-color);
  text-decoration: none;
  font-size: 0.9rem;
}

.home__link:hover {
  color: var(--p-primary-color);
}

.home__main {
  max-width: 900px;
  margin: 0 auto;
  padding: 1.5rem 1rem;
}

.home__accesos {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
  gap: 1rem;
}

.acceso {
  display: flex;
  flex-direction: column;
  gap: 0.35rem;
  padding: 1.1rem;
  border: 1px solid var(--p-content-border-color);
  border-radius: 8px;
  text-decoration: none;
  color: inherit;
  transition: border-color 0.15s ease, transform 0.15s ease;
}

.acceso:hover {
  border-color: var(--p-primary-color);
  transform: translateY(-2px);
}

.acceso__icono {
  font-size: 1.4rem;
  color: var(--p-primary-color);
}

.acceso__titulo {
  font-weight: 600;
}

.acceso__nota {
  font-size: 0.8rem;
  line-height: 1.35;
  color: var(--p-text-muted-color);
}

.home__empty {
  display: flex;
  flex-direction: column;
  gap: 0.5rem;
  align-items: center;
  justify-content: center;
  min-height: 40vh;
  color: var(--p-text-muted-color);
}

.home__icon {
  font-size: 2.5rem;
}
</style>
