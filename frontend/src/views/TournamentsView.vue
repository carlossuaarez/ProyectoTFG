<template>
  <div>
    <h2>Torneos disponibles</h2>
    <div v-if="tournaments.length === 0">
      No hay torneos aún. <router-link to="/login">Inicia sesión para crear uno</router-link>
    </div>
    <div class="grid-container">
      <TournamentCard v-for="t in tournaments" :key="t.id" :tournament="t" />
    </div>
  </div>
</template>

<script setup>
import { ref, onMounted } from 'vue'
import api from '../services/api'
import TournamentCard from '../components/TournamentCard.vue'

const tournaments = ref([])

onMounted(async () => {
  try {
    const res = await api.get('/tournaments')
    tournaments.value = res.data
  } catch (err) {
    console.error(err)
  }
})
</script>

<style scoped>
.grid-container {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
  gap: 1.5rem;
  margin-top: 1rem;
}
@media (max-width: 600px) {
  .grid-container {
    grid-template-columns: 1fr;
  }
}
</style>