<template>
  <section class="page">
    <header class="page-header">
      <h1>Mis torneos</h1>
      <p>Torneos en los que estás inscrito y torneos que has creado.</p>
    </header>

    <div v-if="loading" class="state-box">Cargando tus torneos...</div>

    <div v-else-if="error" class="state-box state-error">
      <p>{{ error }}</p>
      <button type="button" @click="fetchMyTournaments">Reintentar</button>
    </div>

    <div v-else class="sections">
      <!-- TORNEOS INSCRITO -->
      <section class="block">
        <div class="block-head">
          <h2>Inscrito</h2>
          <span class="counter">{{ joinedTournaments.length }}</span>
        </div>

        <div v-if="joinedTournaments.length === 0" class="state-box">
          <p>Aún no estás inscrito en ningún torneo.</p>
          <router-link to="/search-tournaments">Buscar torneos</router-link>
        </div>

        <div v-else class="my-grid">
          <article v-for="t in joinedTournaments" :key="`joined-${t.id}`" class="my-card">
            <div class="top-row">
              <span class="badge" :class="t.type">{{ t.type === 'esports' ? 'e-Sports' : 'Deporte' }}</span>
              <span class="visibility" :class="t.visibility">{{ t.visibility === 'private' ? 'Privado' : 'Público' }}</span>
            </div>

            <h3>{{ t.name }}</h3>
            <p class="muted">{{ t.game }}</p>
            <p class="creator">Creador: <strong>{{ creatorLabel(t) }}</strong></p>

            <div class="info-grid">
              <div>
                <span class="label">Inicio</span>
                <strong>{{ formatDateTime(t.start_date, t.start_time) }}</strong>
              </div>
              <div>
                <span class="label">Equipos</span>
                <strong v-if="Number(t.is_full) === 1">COMPLETO</strong>
                <strong v-else>{{ Number(t.teams_count || 0) }} / {{ t.max_teams }}</strong>
              </div>
              <div>
                <span class="label">Formato</span>
                <strong>{{ t.format === 'single_elim' ? 'Eliminatoria' : 'Liga' }}</strong>
              </div>
              <div>
                <span class="label">Ubicación</span>
                <strong>{{ Number(t.is_online) === 1 ? 'Online' : (t.location_name || 'Pendiente') }}</strong>
              </div>
            </div>

            <div class="my-team-box">
              <span class="label">Mi equipo</span>
              <strong>{{ t.my_team_name || '-' }}</strong>
            </div>

            <router-link class="open-btn" :to="`/tournaments/${t.id}`">Abrir torneo</router-link>
          </article>
        </div>
      </section>

      <!-- TORNEOS CREADOS -->
      <section class="block">
        <div class="block-head">
          <h2>Creados por mí</h2>
          <span class="counter">{{ createdTournaments.length }}</span>
        </div>

        <div v-if="createdTournaments.length === 0" class="state-box">
          <p>Aún no has creado torneos.</p>
          <router-link to="/create-tournament">Crear torneo</router-link>
        </div>

        <div v-else class="my-grid">
          <article v-for="t in createdTournaments" :key="`created-${t.id}`" class="my-card">
            <div class="top-row">
              <span class="badge" :class="t.type">{{ t.type === 'esports' ? 'e-Sports' : 'Deporte' }}</span>
              <span class="visibility" :class="t.visibility">{{ t.visibility === 'private' ? 'Privado' : 'Público' }}</span>
            </div>

            <h3>{{ t.name }}</h3>
            <p class="muted">{{ t.game }}</p>

            <div class="info-grid">
              <div>
                <span class="label">Inicio</span>
                <strong>{{ formatDateTime(t.start_date, t.start_time) }}</strong>
              </div>
              <div>
                <span class="label">Equipos</span>
                <strong v-if="Number(t.is_full) === 1">COMPLETO</strong>
                <strong v-else>{{ Number(t.teams_count || 0) }} / {{ t.max_teams }}</strong>
              </div>
              <div>
                <span class="label">Formato</span>
                <strong>{{ t.format === 'single_elim' ? 'Eliminatoria' : 'Liga' }}</strong>
              </div>
              <div>
                <span class="label">Ubicación</span>
                <strong>{{ Number(t.is_online) === 1 ? 'Online' : (t.location_name || 'Pendiente') }}</strong>
              </div>
            </div>

            <router-link class="open-btn" :to="`/tournaments/${t.id}`">Gestionar torneo</router-link>
          </article>
        </div>
      </section>
    </div>
  </section>
</template>

<script setup>
import { ref, onMounted } from 'vue'
import api from '../services/api'

const joinedTournaments = ref([])
const createdTournaments = ref([])
const loading = ref(true)
const error = ref('')

async function fetchMyTournaments() {
  loading.value = true
  error.value = ''
  try {
    const res = await api.get('/tournaments/mine')
    const data = res.data || {}

    joinedTournaments.value = Array.isArray(data.joined) ? data.joined : []
    createdTournaments.value = Array.isArray(data.created) ? data.created : []
  } catch (err) {
    error.value = err.response?.data?.error || 'No se pudieron cargar tus torneos.'
  } finally {
    loading.value = false
  }
}

function creatorLabel(tournament) {
  const username = String(tournament?.created_by_username || '').trim()
  if (username) return `@${username}`
  const id = Number(tournament?.created_by || 0)
  return id > 0 ? `Usuario #${id}` : 'Desconocido'
}

function formatDateTime(date, time) {
  if (!date) return '-'
  const parsed = new Date(date)
  if (Number.isNaN(parsed.getTime())) return '-'
  const datePart = parsed.toLocaleDateString('es-ES')
  const hhmm = String(time || '00:00:00').slice(0, 5)
  return `${datePart} · ${hhmm}`
}

onMounted(fetchMyTournaments)
</script>

<style scoped>
.page-header {
  margin-bottom: 0.9rem;
}

.page-header h1 {
  margin-bottom: 0.2rem;
}

.page-header p {
  color: var(--muted);
}

.sections {
  display: grid;
  gap: 1.1rem;
}

.block {
  background: var(--surface);
  border: 1px solid var(--border);
  border-radius: var(--radius);
  box-shadow: var(--shadow-sm);
  padding: 0.9rem;
}

.block-head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  margin-bottom: 0.8rem;
}

.block-head h2 {
  font-size: 1.05rem;
}

.counter {
  border: 1px solid var(--border);
  background: #f8fafc;
  border-radius: 999px;
  padding: 0.18rem 0.55rem;
  font-size: 0.78rem;
  font-weight: 700;
  color: #334155;
}

.my-grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(310px, 1fr));
  gap: 0.9rem;
}

.my-card {
  background: var(--surface);
  border: 1px solid var(--border);
  border-radius: var(--radius);
  box-shadow: var(--shadow-sm);
  padding: 1rem;
  display: flex;
  flex-direction: column;
  min-height: 300px;
}

.top-row {
  display: flex;
  justify-content: space-between;
  margin-bottom: 0.7rem;
}

.badge,
.visibility {
  border-radius: 999px;
  padding: 0.25rem 0.6rem;
  font-size: 0.78rem;
  font-weight: 700;
}

.badge.sports {
  background: #dcfce7;
  color: #166534;
}

.badge.esports {
  background: #dbeafe;
  color: #1d4ed8;
}

.visibility.public {
  background: #e2e8f0;
  color: #334155;
}

.visibility.private {
  background: #fee2e2;
  color: #991b1b;
}

h3 {
  margin-bottom: 0.2rem;
}

.muted {
  color: var(--muted);
  margin-bottom: 0.2rem;
}

.creator {
  color: #334155;
  margin-bottom: 0.65rem;
  font-size: 0.9rem;
}

.info-grid {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 0.55rem;
  margin-bottom: 0.7rem;
}

.info-grid > div {
  border: 1px solid var(--border);
  border-radius: 10px;
  padding: 0.5rem 0.58rem;
  background: var(--surface-soft);
}

.label {
  display: block;
  font-size: 0.78rem;
  color: var(--muted);
  margin-bottom: 0.1rem;
}

.my-team-box {
  border: 1px solid #bae6fd;
  background: #f0f9ff;
  border-radius: 10px;
  padding: 0.55rem 0.62rem;
  margin-bottom: 0.8rem;
}

.open-btn {
  margin-top: auto;
  text-decoration: none;
  text-align: center;
  border-radius: 10px;
  padding: 0.62rem;
  font-weight: 700;
  color: #fff;
  background: linear-gradient(135deg, #0ea5e9, #06b6d4);
}

.state-box {
  background: var(--surface);
  border: 1px dashed #cbd5e1;
  border-radius: var(--radius);
  padding: 1rem;
  text-align: center;
  color: var(--muted);
}

.state-box a {
  text-decoration: none;
  color: #0284c7;
  font-weight: 700;
}

.state-error {
  border-color: #fecaca;
  background: #fff1f2;
  color: #991b1b;
}

.state-error button {
  margin-top: 0.6rem;
  border: 1px solid #ef4444;
  color: #b91c1c;
  background: #fff;
  border-radius: 10px;
  padding: 0.45rem 0.8rem;
  cursor: pointer;
  font-weight: 600;
}

@media (max-width: 700px) {
  .info-grid {
    grid-template-columns: 1fr;
  }
}
</style>