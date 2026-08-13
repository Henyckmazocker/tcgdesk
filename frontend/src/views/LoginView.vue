<template>
  <div class="login">
    <div class="login__card">
      <h1 class="login__title">TCGDesk</h1>
      <p class="login__subtitle">Tu colección de cartas, en local y sin depender de nadie.</p>

      <!-- Paso 1: entrar con Google -->
      <template v-if="!auth.needsUsername">
        <div ref="googleButton" class="login__google"></div>

        <Button
          v-if="isNative()"
          label="Entrar con Google"
          icon="pi pi-google"
          :loading="auth.isLoading"
          @click="signInNative"
        />
      </template>

      <!-- Paso 2: elegir nombre de usuario (primer acceso) -->
      <form v-else class="login__register" @submit.prevent="completeRegistration">
        <p class="login__welcome">
          Hola<span v-if="auth.googleProfile?.display_name">, {{ auth.googleProfile.display_name }}</span>.
          Elige tu nombre de usuario.
        </p>
        <p class="login__hint">
          Será tu perfil público y <strong>no se puede cambiar</strong>.
          Entre 3 y 32 caracteres: letras, números, guion y guion bajo.
        </p>

        <InputText
          v-model="username"
          placeholder="p. ej. davidca"
          autocomplete="off"
          autofocus
          :invalid="Boolean(auth.error)"
        />

        <Button
          type="submit"
          label="Crear cuenta"
          :loading="auth.isLoading"
          :disabled="!isUsernameValid"
        />
        <Button label="Cancelar" severity="secondary" text @click="auth.clearSession()" />
      </form>

      <Message v-if="auth.error" severity="error" :closable="false">{{ auth.error }}</Message>
      <Message v-if="googleError" severity="warn" :closable="false">{{ googleError }}</Message>
    </div>
  </div>
</template>

<script setup>
import { computed, onMounted, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import Button from 'primevue/button'
import InputText from 'primevue/inputtext'
import Message from 'primevue/message'
import { useAuthStore } from '@/stores/auth'
import { useGoogleAuth } from '@/composables/useGoogleAuth'

const auth = useAuthStore()
// Desestructurado: los refs devueltos dentro de un objeto plano NO se
// desenvuelven en la plantilla; como bindings de primer nivel de <script setup>, sí.
const { error: googleError, isNative, initialize, renderButton, signInNative: nativeSignIn } = useGoogleAuth()
const router = useRouter()
const route = useRoute()

const googleButton = ref(null)
const username = ref('')

// Mismo patrón que valida AuthController.php; se comprueba aquí solo para no
// mandar una petición condenada, no como sustituto de la validación real.
const isUsernameValid = computed(() => /^[a-zA-Z0-9_-]{3,32}$/.test(username.value))

function goToTarget() {
  router.replace(route.query.redirect || '/')
}

async function handleCredential(idToken) {
  if (await auth.login(idToken)) {
    goToTarget()
  }
}

async function signInNative() {
  const idToken = await nativeSignIn()
  if (idToken) {
    await handleCredential(idToken)
  }
}

async function completeRegistration() {
  if (await auth.completeRegistration(username.value)) {
    goToTarget()
  }
}

onMounted(async () => {
  if (await initialize(handleCredential)) {
    renderButton(googleButton.value)
  }
})
</script>

<style scoped>
.login {
  display: flex;
  align-items: center;
  justify-content: center;
  min-height: 100vh;
  padding: 1rem;
}

.login__card {
  display: flex;
  flex-direction: column;
  gap: 1rem;
  align-items: center;
  width: 100%;
  max-width: 26rem;
  padding: 2rem;
  border: 1px solid var(--p-content-border-color);
  border-radius: 12px;
}

.login__title {
  margin: 0;
  font-size: 2rem;
}

.login__subtitle,
.login__hint {
  margin: 0;
  color: var(--p-text-muted-color);
  text-align: center;
}

.login__hint {
  font-size: 0.85rem;
}

.login__register {
  display: flex;
  flex-direction: column;
  gap: 0.75rem;
  width: 100%;
}
</style>
