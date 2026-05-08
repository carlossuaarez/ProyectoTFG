<template>
  <section class="hero">
    <div class="hero-content">
      <span class="eyebrow">Plataforma para organizadores de eventos</span>
      <h1>Crea torneos profesionales de deportes y e-sports</h1>
      <p>
        Gestiona equipos, plazas e inscripciones desde una única web. Diseñado para academias,
        clubes, asociaciones y organizadores independientes.
      </p>

      <div class="hero-actions">
        <router-link to="/search-tournaments" class="btn btn-primary">Buscar torneos</router-link>
        <router-link to="/create-tournament" class="btn btn-secondary">Crear torneo</router-link>
      </div>

      <section class="private-access-card">
        <h3>Entrar con código privado</h3>
        <p>Si te han compartido un código, introdúcelo para abrir directamente ese torneo.</p>

        <form class="private-access-form" @submit.prevent="resolvePrivateCode">
          <input
            v-model.trim="privateCodeInput"
            type="text"
            maxlength="16"
            autocomplete="one-time-code"
            placeholder="Ej: ENUESMM6"
            :disabled="privateCodeLoading"
          />
          <button type="submit" :disabled="privateCodeLoading">
            {{ privateCodeLoading ? 'Buscando...' : 'Acceder' }}
          </button>
        </form>

        <p v-if="privateCodeError" class="private-msg error">{{ privateCodeError }}</p>
      </section>
    </div>
  </section>

  <section class="tournaments-block">
    <div class="section-head">
      <h2>Torneos destacados</h2>
      <router-link to="/search-tournaments">Ver todos</router-link>
    </div>

    <div v-if="loading" class="state-box">Cargando torneos...</div>
    <div v-else-if="error" class="state-box state-error">{{ error }}</div>
    <div v-else-if="featuredTournaments.length === 0" class="state-box">
      No hay torneos destacados todavía.
    </div>
    <div v-else class="cards-grid">
      <TournamentCard v-for="t in featuredTournaments" :key="`featured-${t.id}`" :tournament="t" />
    </div>
  </section>

  <section class="tournaments-block">
    <div class="section-head">
      <h2>Próximos torneos</h2>
      <router-link to="/search-tournaments">Ir a buscador</router-link>
    </div>

    <div v-if="loading" class="state-box">Cargando torneos...</div>
    <div v-else-if="error" class="state-box state-error">{{ error }}</div>
    <div v-else-if="homeTournaments.length === 0" class="state-box">
      No hay torneos públicos disponibles.
    </div>
    <div v-else class="cards-grid">
      <TournamentCard v-for="t in homeTournaments" :key="`home-${t.id}`" :tournament="t" />
    </div>
  </section>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue'
import { useRouter } from 'vue-router'
import api from '../services/api'
import { publicGet } from '../services/publicApi'
import TournamentCard from '../components/TournamentCard.vue'

const ACCESS_CODE_STORAGE_KEY = 'tourneyhub_private_codes'

const router = useRouter()
const privateCodeInput = ref('')
const privateCodeLoading = ref(false)
const privateCodeError = ref('')

const tournaments = ref([])
const loading = ref(true)
const error = ref('')

function normalizeTournament(t) {
  const teamsCount = Number(t.teams_count ?? 0)
  const maxTeams = Number(t.max_teams || 0)
  return {
    id: t.id,
    name: t.name || 'Torneo sin nombre',
    description: t.description || '',
    game: t.game || '',
    type: t.type || 'sports',
    max_teams: maxTeams,
    format: t.format || 'single_elim',
    start_date: t.start_date || null,
    start_time: t.start_time || '00:00:00',
    prize: t.prize || '',
    location_name: t.location_name || '',
    is_online: Number(t.is_online || 0),
    visibility: t.visibility || 'public',
    created_by: Number(t.created_by || 0),
    created_by_username: String(t.created_by_username || ''),
    teams_count: teamsCount,
    is_full: Number(t.is_full ?? (maxTeams > 0 && teamsCount >= maxTeams ? 1 : 0)),
  }
}

function startTimestamp(t) {
  const datePart = String(t.start_date || '').trim()
  if (!datePart) return Number.MAX_SAFE_INTEGER
  const timePart = String(t.start_time || '00:00:00').slice(0, 8)
  const parsed = new Date(`${datePart}T${timePart}`)
  return Number.isNaN(parsed.getTime()) ? Number.MAX_SAFE_INTEGER : parsed.getTime()
}

const normalizedTournaments = computed(() => tournaments.value.map(normalizeTournament))

const featuredTournaments = computed(() => {
  return [...normalizedTournaments.value]
    .filter((t) => t.visibility === 'public')
    .sort((a, b) => {
      const teamsCmp = Number(b.teams_count || 0) - Number(a.teams_count || 0)
      if (teamsCmp !== 0) return teamsCmp
      return startTimestamp(a) - startTimestamp(b)
    })
    .slice(0, 3)
})

const homeTournaments = computed(() => {
  return [...normalizedTournaments.value]
    .filter((t) => t.visibility === 'public')
    .sort((a, b) => startTimestamp(a) - startTimestamp(b))
    .slice(0, 9)
})

// Listado público de torneos: usa fetch nativo (sin axios) a través de publicApi.js.
// Es el endpoint que demuestra "comunicación asíncrona cliente/servidor" con
// la API nativa del navegador, alineado con el recurso de attacomsian sobre XHR/fetch.
async function fetchHomeTournaments() {
  loading.value = true
  error.value = ''
  try {
    const data = await publicGet('/tournaments', { silent: true })
    tournaments.value = Array.isArray(data) ? data : []
  } catch (err) {
    error.value = err?.data?.error || err?.message || 'No se pudieron cargar los torneos de inicio.'
  } finally {
    loading.value = false
  }
}

function sanitizeCode(value) {
  return String(value || '').trim().replace(/[^a-zA-Z0-9]/g, '').toUpperCase()
}

function saveAccessCodeForTournament(tournamentId, code) {
  try {
    const raw = sessionStorage.getItem(ACCESS_CODE_STORAGE_KEY)
    const map = raw ? JSON.parse(raw) : {}
    map[String(tournamentId)] = code
    sessionStorage.setItem(ACCESS_CODE_STORAGE_KEY, JSON.stringify(map))
  } catch {}
}

async function resolvePrivateCode() {
  privateCodeError.value = ''

  const code = sanitizeCode(privateCodeInput.value)
  if (!code || code.length < 6) {
    privateCodeError.value = 'Introduce un código privado válido.'
    return
  }

  privateCodeLoading.value = true
  try {
    const res = await api.post('/tournaments/private/resolve', { access_code: code }, { silent: true })
    const tournamentId = Number(res?.data?.tournament_id || 0)

    if (!Number.isInteger(tournamentId) || tournamentId <= 0) {
      privateCodeError.value = 'No se pudo resolver el torneo para ese código.'
      return
    }

    saveAccessCodeForTournament(tournamentId, code)
    await router.push(`/tournaments/${tournamentId}`)
  } catch (err) {
    if (err.response?.status === 404) {
      privateCodeError.value = 'Código incorrecto o torneo no disponible.'
    } else if (err.response?.status === 429) {
      privateCodeError.value = 'Demasiados intentos. Espera un momento.'
    } else {
      privateCodeError.value = err.response?.data?.error || 'No se pudo validar el código privado.'
    }
  } finally {
    privateCodeLoading.value = false
  }
}

onMounted(fetchHomeTournaments)
</script>

<style scoped>
.hero {
  margin-bottom: 1.3rem;
}

.hero-content {
  background: var(--surface);
  border: 1px solid var(--border);
  border-radius: var(--radius);
  box-shadow: var(--shadow-sm);
  padding: 1.25rem;
}

.eyebrow {
  display: inline-block;
  font-size: 0.82rem;
  letter-spacing: 0.05em;
  text-transform: uppercase;
  color: #0369a1;
  font-weight: 700;
  margin-bottom: 0.45rem;
}

.hero-content h1 {
  font-size: clamp(1.8rem, 2.8vw, 2.6rem);
  line-height: 1.15;
  margin-bottom: 0.7rem;
}

.hero-content p {
  color: var(--muted);
  max-width: 65ch;
}

.hero-actions {
  margin-top: 1rem;
  display: flex;
  gap: 0.75rem;
  flex-wrap: wrap;
}

.btn {
  text-decoration: none;
  border-radius: 10px;
  padding: 0.75rem 1rem;
  font-weight: 700;
}

.btn-primary {
  background: linear-gradient(135deg, #0ea5e9, #06b6d4);
  color: #fff;
}

.btn-secondary {
  background: #f8fafc;
  color: #0f172a;
  border: 1px solid var(--border);
}

.private-access-card {
  margin-top: 1rem;
  border: 1px solid #dbeafe;
  background: #f8fbff;
  border-radius: 12px;
  padding: 0.85rem;
}

.private-access-card h3 {
  margin: 0 0 0.35rem;
  font-size: 1rem;
}

.private-access-card p {
  margin: 0 0 0.55rem;
  color: #475569;
}

.private-access-form {
  display: flex;
  gap: 0.55rem;
  flex-wrap: wrap;
}

.private-access-form input {
  flex: 1;
  min-width: 180px;
  border: 1px solid var(--border);
  border-radius: 10px;
  padding: 0.58rem 0.7rem;
  text-transform: uppercase;
}

.private-access-form button {
  border: none;
  border-radius: 10px;
  background: #0ea5e9;
  color: #fff;
  font-weight: 700;
  padding: 0.58rem 0.9rem;
  cursor: pointer;
}

.private-access-form button:disabled {
  opacity: 0.7;
  cursor: not-allowed;
}

.private-msg.error {
  margin-top: 0.45rem;
  color: #b91c1c;
  font-size: 0.88rem;
  font-weight: 600;
}

.tournaments-block {
  background: var(--surface);
  border: 1px solid var(--border);
  border-radius: var(--radius);
  box-shadow: var(--shadow-sm);
  padding: 1rem;
  margin-bottom: 1rem;
}

.section-head {
  display: flex;
  justify-content: space-between;
  align-items: baseline;
  gap: 0.7rem;
  margin-bottom: 0.8rem;
}

.section-head a {
  text-decoration: none;
  color: #0284c7;
  font-weight: 700;
}

.cards-grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
  gap: 0.9rem;
}

.state-box {
  border: 1px dashed #cbd5e1;
  border-radius: 10px;
  padding: 0.9rem;
  text-align: center;
  color: #64748b;
}

.state-error {
  border-color: #fecaca;
  color: #991b1b;
  background: #fff1f2;
}

@media (max-width: 768px) {
  .private-access-form {
    flex-direction: column;
  }
}
</style>