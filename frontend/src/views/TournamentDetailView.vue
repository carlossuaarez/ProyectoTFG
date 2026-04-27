<template>
  <div v-if="tournament" class="detail-container">
    <div class="tournament-info">
      <span class="type-badge" :class="tournament.type">{{ tournament.type === 'esports' ? '🎮 e-Sports' : '⚽ Deporte' }}</span>
      <h2>{{ tournament.name }}</h2>
      <p class="game">{{ tournament.game }}</p>
      <div class="info-grid">
        <div class="info-item">
          <strong>Formato:</strong> {{ tournament.format === 'single_elim' ? 'Eliminatoria simple' : 'Liga' }}
        </div>
        <div class="info-item">
          <strong>Plazas:</strong> {{ teams.length }}/{{ tournament.max_teams }}
        </div>
        <div class="info-item">
          <strong>Inicio:</strong> {{ new Date(tournament.start_date).toLocaleDateString() }}
        </div>
        <div class="info-item">
          <strong>Premio:</strong> {{ tournament.prize || 'Sin premio' }}
        </div>
      </div>
    </div>

    <div class="teams-section">
      <h3>Equipos inscritos</h3>
      <ul v-if="teams.length" class="team-list">
        <li v-for="team in teams" :key="team.id">{{ team.name }}</li>
      </ul>
      <p v-else>No hay equipos aún.</p>
    </div>

    <div v-if="token && !alreadyJoined" class="join-section">
      <h3>Inscribir equipo</h3>
      <div class="join-form">
        <input v-model="teamName" placeholder="Nombre de tu equipo" />
        <button @click="joinTournament" class="join-btn">Inscribir</button>
      </div>
    </div>
    <p v-else-if="!token" class="login-prompt">Inicia sesión para inscribirte.</p>
  </div>
  <div v-else class="loading">Cargando...</div>
</template>

<script setup>
import { ref, onMounted } from 'vue'
import { useRoute } from 'vue-router'
import api from '../services/api'
import { useAuthStore } from '../stores/auth'
import { storeToRefs } from 'pinia'

const route = useRoute()
const tournament = ref(null)
const teams = ref([])
const teamName = ref('')
const authStore = useAuthStore()
const { token } = storeToRefs(authStore)
const alreadyJoined = ref(false) // simplificado

onMounted(async () => {
  try {
    const res = await api.get(`/tournaments/${route.params.id}`)
    tournament.value = res.data
    teams.value = res.data.teams || []
  } catch (err) {
    console.error(err)
  }
})

async function joinTournament() {
  if (!teamName.value) return alert('Escribe un nombre de equipo')
  try {
    await api.post(`/tournaments/${route.params.id}/join`, { team_name: teamName.value })
    alert('Equipo inscrito correctamente')
    const res = await api.get(`/tournaments/${route.params.id}`)
    teams.value = res.data.teams
    teamName.value = ''
  } catch (err) {
    alert(err.response?.data?.error || 'Error al inscribir')
  }
}
</script>

<style scoped>
.detail-container {
  max-width: 800px;
  margin: 0 auto;
  background: white;
  border-radius: 16px;
  padding: 2rem;
  box-shadow: 0 4px 20px rgba(0,0,0,0.08);
}
.type-badge {
  display: inline-block;
  padding: 0.3rem 1rem;
  border-radius: 20px;
  font-weight: 600;
  margin-bottom: 1rem;
}
.type-badge.sports { background: #e8f5e9; color: #2e7d32; }
.type-badge.esports { background: #e3f2fd; color: #1565c0; }
.tournament-info h2 {
  font-size: 2rem;
  margin-bottom: 0.5rem;
}
.game {
  color: #666;
  font-size: 1.2rem;
  margin-bottom: 1.5rem;
}
.info-grid {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 1rem;
  margin-bottom: 2rem;
}
.info-item {
  background: #f8f9fa;
  padding: 0.8rem;
  border-radius: 8px;
}
.teams-section {
  margin: 2rem 0;
}
.team-list {
  list-style: none;
  display: flex;
  flex-wrap: wrap;
  gap: 0.5rem;
}
.team-list li {
  background: #e8f0fe;
  padding: 0.4rem 1rem;
  border-radius: 20px;
  font-size: 0.9rem;
}
.join-section {
  margin-top: 2rem;
  padding: 1.5rem;
  background: #f0f2f5;
  border-radius: 12px;
}
.join-form {
  display: flex;
  gap: 0.5rem;
  margin-top: 1rem;
}
.join-form input {
  flex: 1;
  padding: 0.6rem 1rem;
  border: 2px solid #ddd;
  border-radius: 8px;
}
.join-btn {
  background: #e94560;
  color: white;
  border: none;
  padding: 0 1.5rem;
  border-radius: 8px;
  font-weight: 600;
  cursor: pointer;
}
.login-prompt {
  text-align: center;
  margin-top: 2rem;
}
@media (max-width: 600px) {
  .info-grid {
    grid-template-columns: 1fr;
  }
  .join-form {
    flex-direction: column;
  }
}
</style>