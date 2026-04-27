<template>
  <div>
    <div class="header">
      <h2>Torneos disponibles</h2>
      <div class="filters">
        <select v-model="filterType">
          <option value="all">Todos</option>
          <option value="sports">Deportes</option>
          <option value="esports">e-Sports</option>
        </select>
      </div>
    </div>
    <div v-if="filteredTournaments.length === 0" class="empty">
      <p>No hay torneos aún. <router-link to="/create-tournament">Crea el primero</router-link></p>
    </div>
    <div class="grid-container" v-else>
      <TournamentCard v-for="t in filteredTournaments" :key="t.id" :tournament="t" />
    </div>
  </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue'
import api from '../services/api'
import TournamentCard from '../components/TournamentCard.vue'

const tournaments = ref([])
const filterType = ref('all')

onMounted(async () => {
  try {
    const res = await api.get('/tournaments')
    tournaments.value = res.data
  } catch (err) {
    console.error(err)
  }
})

const filteredTournaments = computed(() => {
  if (filterType.value === 'all') return tournaments.value
  return tournaments.value.filter(t => t.type === filterType.value)
})
</script>

<style scoped>
.header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  margin-bottom: 2rem;
  flex-wrap: wrap;
}
.header h2 {
  font-size: 2rem;
}
.filters select {
  padding: 0.5rem 1rem;
  border-radius: 8px;
  border: 2px solid #ccc;
  font-size: 1rem;
}
.grid-container {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
  gap: 2rem;
}
.empty {
  text-align: center;
  padding: 3rem;
}
@media (max-width: 600px) {
  .header h2 {
    font-size: 1.5rem;
  }
}
</style>