<template>
  <div class="form-page">
    <div class="form-card">
      <h2>Crear nuevo torneo</h2>
      <form @submit.prevent="createTournament">
        <div class="input-group">
          <label>Nombre del torneo</label>
          <input v-model="name" placeholder="Ej: Copa Primavera 2024" required />
        </div>
        <div class="input-group">
          <label>Juego / Deporte</label>
          <input v-model="game" placeholder="Ej: Fútbol, Valorant" required />
        </div>
        <div class="input-group">
          <label>Tipo</label>
          <select v-model="type" required>
            <option value="">Selecciona tipo</option>
            <option value="sports">Deportes</option>
            <option value="esports">e-Sports</option>
          </select>
        </div>
        <div class="input-row">
          <div class="input-group">
            <label>Nº máximo equipos</label>
            <input v-model="max_teams" type="number" min="2" required />
          </div>
          <div class="input-group">
            <label>Formato</label>
            <select v-model="format" required>
              <option value="single_elim">Eliminatoria simple</option>
              <option value="league">Liga</option>
            </select>
          </div>
        </div>
        <div class="input-row">
          <div class="input-group">
            <label>Fecha de inicio</label>
            <input v-model="start_date" type="date" required />
          </div>
          <div class="input-group">
            <label>Premio (opcional)</label>
            <input v-model="prize" placeholder="Ej: 500€" />
          </div>
        </div>
        <button type="submit" class="submit-btn">Crear torneo</button>
      </form>
    </div>
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
.form-page {
  display: flex;
  justify-content: center;
}
.form-card {
  background: white;
  padding: 2rem;
  border-radius: 16px;
  box-shadow: 0 4px 20px rgba(0,0,0,0.08);
  width: 100%;
  max-width: 600px;
}
.form-card h2 {
  margin-bottom: 1.5rem;
}
.input-group {
  margin-bottom: 1rem;
}
.input-group label {
  display: block;
  font-weight: 600;
  margin-bottom: 0.3rem;
}
.input-group input, .input-group select {
  width: 100%;
  padding: 0.6rem 1rem;
  border: 2px solid #e0e0e0;
  border-radius: 8px;
}
.input-row {
  display: flex;
  gap: 1rem;
}
.input-row > * {
  flex: 1;
}
.submit-btn {
  width: 100%;
  padding: 0.8rem;
  background: linear-gradient(135deg, #302b63, #24243e);
  color: white;
  border: none;
  border-radius: 8px;
  font-weight: 600;
  margin-top: 1rem;
  cursor: pointer;
}
.submit-btn:hover {
  opacity: 0.9;
}
@media (max-width: 500px) {
  .input-row {
    flex-direction: column;
    gap: 0;
  }
}
</style>