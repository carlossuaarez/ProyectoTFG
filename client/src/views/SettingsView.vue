<template>
  <section class="settings-page">
    <article class="settings-card">
      <h1>Configuración</h1>
      <p>Esta sección está vacía de momento. Próximamente añadiremos opciones aquí.</p>
    </article>
  </section>
</template>

<script setup>
</script>

<style scoped>
.settings-page {
  display: flex;
  justify-content: center;
}

.settings-card {
  width: min(680px, 100%);
  background: var(--surface);
  border: 1px solid var(--border);
  border-radius: var(--radius);
  box-shadow: var(--shadow-md);
  padding: 1rem;
}

.settings-card h1 {
  margin-bottom: 0.35rem;
}

.settings-card p {
  color: var(--muted);
}
</style>