<template>
  <div class="form-container">
    <h2>Crear Torneo</h2>
    <form @submit.prevent="createTournament">
      <input v-model="name" placeholder="Nombre del torneo" required />
      <input v-model="game" placeholder="Juego/Deporte (ej: Fútbol, Valorant)" required />
      <select v-model="type" required>
        <option value="">Tipo</option>
        <option value="sports">Deportes</option>
        <option value="esports">e-Sports</option>
      </select>
      <input v-model="max_teams" type="number" placeholder="Nº máximo de equipos" required />
      <select v-model="format" required>
        <option value="single_elim">Eliminatoria simple</option>
        <option value="league">Liga</option>
      </select>
      <input v-model="start_date" type="date" required />
      <input v-model="prize" placeholder="Premio (opcional)" />
      <button type="submit">Crear torneo</button>
    </form>
  </div>
</template>

<script setup>
import { ref } from 'vue'
import api from '../services/api'
import { useRouter } from 'vue-router'

const name = ref('')
const game = ref('')
const type = ref('')
const max_teams = ref(8)
const format = ref('single_elim')
const start_date = ref('')
const prize = ref('')
const router = useRouter()

async function createTournament() {
  try {
    await api.post('/tournaments', {
      name: name.value,
      game: game.value,
      type: type.value,
      max_teams: max_teams.value,
      format: format.value,
      start_date: start_date.value,
      prize: prize.value
    })
    alert('Torneo creado')
    router.push('/tournaments')
  } catch (err) {
    alert(err.response?.data?.error || 'Error al crear torneo')
  }
}
</script>

<style scoped>
.form-container {
  max-width: 400px;
  margin: 2rem auto;
  padding: 2rem;
  background: white;
  border-radius: 8px;
  box-shadow: 0 2px 8px rgba(0,0,0,0.1);
}
input, select {
  display: block;
  width: 100%;
  margin-bottom: 1rem;
  padding: 0.5rem;
  box-sizing: border-box;
}
button {
  width: 100%;
  padding: 0.7rem;
  background: #2c3e50;
  color: white;
  border: none;
  border-radius: 4px;
  cursor: pointer;
}
</style>