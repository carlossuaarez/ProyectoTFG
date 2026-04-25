<template>
  <div class="card">
    <h3>{{ tournament.name }}</h3>
    <p>{{ tournament.game }} ({{ tournament.type }})</p>
    <p>Inicio: {{ tournament.start_date }}</p>
    <router-link :to="'/tournaments/' + tournament.id">Ver detalles</router-link>
  </div>
</template>

<script setup>
defineProps(['tournament'])
</script>

<style scoped>
.card {
  background: white;
  padding: 1rem;
  border-radius: 8px;
  box-shadow: 0 2px 6px rgba(0,0,0,0.1);
}
</style>