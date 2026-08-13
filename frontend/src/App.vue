<template>
  <router-view />
</template>

<script setup>
// Sin lógica de arranque: la sesión se rehidrata en main.js ANTES de instalar el
// router. Hacerlo aquí, en onMounted, llegaba tarde — el guard ya había mandado
// a /login a quien tenía sesión válida.
</script>

<style>
:root {
  color-scheme: light dark;
}

body {
  margin: 0;
  font-family: system-ui, -apple-system, 'Segoe UI', Roboto, sans-serif;
}
</style>
