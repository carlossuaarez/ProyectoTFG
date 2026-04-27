<template>
  <section v-if="loading" class="state">Cargando torneo...</section>

  <section v-else-if="error" class="state state-error">
    <p>{{ error }}</p>
    <button type="button" @click="fetchTournament">Reintentar</button>
  </section>

  <section v-else-if="tournament" class="detail-page">
    <article class="main-card">
      <div class="head">
        <span class="badge" :class="tournament.type">
          <Gamepad2 v-if="isEsports(tournament.type)" class="badge-icon" />
          <Dumbbell v-else class="badge-icon" />
          {{ formatType(tournament.type) }}
        </span>
        <span class="format-chip">{{ formatTournamentFormat(tournament.format) }}</span>
      </div>

      <h1>{{ tournament.name }}</h1>
      <p class="game">{{ tournament.game }}</p>

      <div class="info-grid">
        <div class="info-item">
          <span>Inicio</span>
          <strong>{{ formatDate(tournament.start_date) }}</strong>
        </div>
        <div class="info-item">
          <span>Equipos</span>
          <strong>{{ teams.length }} / {{ tournament.max_teams }}</strong>
        </div>
        <div class="info-item">
          <span>Formato</span>
          <strong>{{ formatTournamentFormat(tournament.format) }}</strong>
        </div>
        <div class="info-item">
          <span>Premio</span>
          <strong>{{ tournament.prize || 'Sin premio' }}</strong>
        </div>
      </div>
    </article>

    <article class="side-card">
      <h2>Equipos inscritos</h2>

      <ul v-if="teams.length > 0" class="team-list">
        <li v-for="team in teams" :key="team.id">{{ team.name }}</li>
      </ul>
      <p v-else class="empty">Todavía no hay equipos inscritos.</p>

      <div v-if="token && !alreadyJoined" class="join-box">
        <h3>Inscribir equipo</h3>
        <div class="join-row">
          <input v-model="teamName" placeholder="Nombre del equipo" />
          <button type="button" :disabled="joinLoading" @click="joinTournament">
            {{ joinLoading ? 'Inscribiendo...' : 'Inscribir' }}
          </button>
        </div>
        <p v-if="joinError" class="msg error">{{ joinError }}</p>
        <p v-if="joinSuccess" class="msg success">{{ joinSuccess }}</p>
      </div>

      <p v-else-if="!token" class="login-tip">
        Inicia sesión para inscribir un equipo en este torneo.
      </p>
    </article>
  </section>
</template>

<script setup>
import { ref, onMounted } from 'vue'
import { useRoute } from 'vue-router'
import { useAuthStore } from '../stores/auth'
import { storeToRefs } from 'pinia'
import api from '../services/api'
import { Gamepad2, Dumbbell } from 'lucide-vue-next'

const route = useRoute()
const authStore = useAuthStore()
const { token } = storeToRefs(authStore)

const tournament = ref(null)
const teams = ref([])

const loading = ref(true)
const error = ref('')
const teamName = ref('')
const joinLoading = ref(false)
const joinError = ref('')
const joinSuccess = ref('')
const alreadyJoined = ref(false)

async function fetchTournament() {
  loading.value = true
  error.value = ''
  joinError.value = ''
  joinSuccess.value = ''
  try {
    const res = await api.get(`/tournaments/${route.params.id}`)
    tournament.value = res.data
    teams.value = res.data.teams || []
  } catch {
    error.value = 'No se pudo cargar el detalle del torneo.'
  } finally {
    loading.value = false
  }
}

onMounted(fetchTournament)

async function joinTournament() {
  const name = teamName.value.trim()
  if (!name) {
    joinError.value = 'Escribe un nombre de equipo.'
    joinSuccess.value = ''
    return
  }

  joinLoading.value = true
  joinError.value = ''
  joinSuccess.value = ''

  try {
    await api.post(`/tournaments/${route.params.id}/join`, { team_name: name })
    joinSuccess.value = 'Equipo inscrito correctamente.'
    teamName.value = ''
    alreadyJoined.value = true
    await fetchTournament()
  } catch (err) {
    joinError.value = err.response?.data?.error || 'Error al inscribir el equipo.'
  } finally {
    joinLoading.value = false
  }
}

function isEsports(type) {
  return type === 'esports'
}

function formatType(type) {
  return isEsports(type) ? 'e-Sports' : 'Deporte'
}

function formatTournamentFormat(format) {
  return format === 'single_elim' ? 'Eliminatoria simple' : 'Liga'
}

function formatDate(date) {
  return new Date(date).toLocaleDateString('es-ES')
}
</script>

<style scoped>
.detail-page {
  display: grid;
  grid-template-columns: 1.5fr 1fr;
  gap: 1rem;
}

.main-card,
.side-card {
  background: var(--surface);
  border: 1px solid var(--border);
  border-radius: var(--radius);
  box-shadow: var(--shadow-sm);
  padding: 1rem;
}

.head {
  display: flex;
  justify-content: space-between;
  gap: 0.5rem;
  margin-bottom: 0.7rem;
}

.badge,
.format-chip {
  border-radius: 999px;
  padding: 0.25rem 0.65rem;
  font-size: 0.78rem;
  font-weight: 700;
}

.badge {
  display: inline-flex;
  align-items: center;
  gap: 0.35rem;
}

.badge-icon {
  width: 14px;
  height: 14px;
}

.badge.sports {
  background: #dcfce7;
  color: #166534;
}

.badge.esports {
  background: #dbeafe;
  color: #1d4ed8;
}

.format-chip {
  background: #f1f5f9;
  color: #334155;
}

h1 {
  font-size: clamp(1.4rem, 2.4vw, 2rem);
  margin-bottom: 0.25rem;
}

.game {
  color: var(--muted);
  margin-bottom: 0.8rem;
}

.info-grid {
  display: grid;
  gap: 0.7rem;
  grid-template-columns: repeat(2, minmax(0, 1fr));
}

.info-item {
  border: 1px solid var(--border);
  border-radius: 12px;
  padding: 0.6rem 0.7rem;
  background: var(--surface-soft);
}

.info-item span {
  display: block;
  color: var(--muted);
  font-size: 0.8rem;
}

.side-card h2 {
  margin-bottom: 0.65rem;
  font-size: 1.1rem;
}

.team-list {
  list-style: none;
  display: flex;
  flex-wrap: wrap;
  gap: 0.45rem;
  margin-bottom: 0.85rem;
}

.team-list li {
  border-radius: 999px;
  background: #e0f2fe;
  color: #0c4a6e;
  padding: 0.3rem 0.65rem;
  font-size: 0.86rem;
  font-weight: 600;
}

.empty {
  color: var(--muted);
  margin-bottom: 0.85rem;
}

.join-box {
  border-top: 1px solid var(--border);
  padding-top: 0.85rem;
}

.join-box h3 {
  margin-bottom: 0.55rem;
}

.join-row {
  display: flex;
  gap: 0.5rem;
}

.join-row input {
  flex: 1;
  border: 1px solid var(--border);
  border-radius: 10px;
  padding: 0.58rem 0.7rem;
}

.join-row button {
  border: none;
  border-radius: 10px;
  background: linear-gradient(135deg, #0ea5e9, #06b6d4);
  color: #fff;
  font-weight: 700;
  padding: 0.58rem 0.82rem;
  cursor: pointer;
}

.join-row button:disabled {
  opacity: 0.65;
  cursor: not-allowed;
}

.login-tip {
  margin-top: 0.85rem;
  color: #475569;
}

.msg {
  margin-top: 0.5rem;
  font-size: 0.88rem;
}

.msg.error {
  color: #b91c1c;
}

.msg.success {
  color: #166534;
}

.state {
  text-align: center;
  padding: 1.2rem;
  border: 1px dashed #cbd5e1;
  border-radius: var(--radius);
  background: var(--surface);
}

.state-error {
  border: 1px solid #fecaca;
  background: #fff1f2;
  color: #991b1b;
}

.state-error button {
  margin-top: 0.6rem;
  border: 1px solid #ef4444;
  background: white;
  color: #b91c1c;
  border-radius: 10px;
  padding: 0.42rem 0.75rem;
  cursor: pointer;
}

@media (max-width: 980px) {
  .detail-page {
    grid-template-columns: 1fr;
  }
}

@media (max-width: 650px) {
  .info-grid {
    grid-template-columns: 1fr;
  }

  .join-row {
    flex-direction: column;
  }
}
</style>