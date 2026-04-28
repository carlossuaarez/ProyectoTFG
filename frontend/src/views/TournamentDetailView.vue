<template>
  <section v-if="loading" class="state">Cargando torneo...</section>
 
  <section v-else-if="requiresAccessCode && !tournament" class="state state-private">
    <h2>Este torneo es privado</h2>
    <p>Introduce el código de acceso para continuar.</p>
 
    <div class="private-unlock">
      <input v-model.trim="accessCodeInput" placeholder="Código de acceso" autocomplete="one-time-code" />
      <button type="button" @click="unlockPrivateTournament">Acceder</button>
    </div>
 
    <p v-if="error" class="msg error">{{ error }}</p>
  </section>
 
  <section v-else-if="error" class="state state-error">
    <p>{{ error }}</p>
    <button type="button" @click="fetchTournament">Reintentar</button>
  </section>
 
  <section v-else-if="tournament" class="detail-page">
    <article class="main-card">
      <div class="head">
        <span class="badge" :class="tournament.type">
          {{ tournament.type === 'esports' ? 'e-Sports' : 'Deporte' }}
        </span>
        <span class="visibility-chip" :class="tournament.visibility">
          {{ tournament.visibility === 'private' ? 'Privado' : 'Público' }}
        </span>
      </div>
 
      <h1>{{ tournament.name }}</h1>
      <p class="game">{{ tournament.game }}</p>
      <p class="description">{{ tournament.description || 'Sin descripción.' }}</p>
 
      <div class="info-grid">
        <div class="info-item">
          <span>Inicio</span>
          <strong>{{ formatDateTime(tournament.start_date, tournament.start_time) }}</strong>
        </div>
        <div class="info-item">
          <span>Equipos</span>
          <strong>{{ teams.length }} / {{ tournament.max_teams }}</strong>
        </div>
        <div class="info-item">
          <span>Formato</span>
          <strong>{{ tournament.format === 'single_elim' ? 'Eliminatoria simple' : 'Liga' }}</strong>
        </div>
        <div class="info-item">
          <span>Premio</span>
          <strong>{{ tournament.prize || 'Sin premio' }}</strong>
        </div>
      </div>
 
      <div class="location-box">
        <h3>Ubicación</h3>
        <p v-if="isOnline"><strong>Online</strong></p>
        <template v-else>
          <p><strong>{{ tournament.location_name || 'Ubicación pendiente' }}</strong></p>
          <p class="muted">{{ tournament.location_address || '' }}</p>
          <div v-if="mapEmbedUrl" class="map-wrapper">
            <iframe
              title="Mapa del torneo"
              :src="mapEmbedUrl"
              loading="lazy"
              referrerpolicy="no-referrer-when-downgrade"
            />
          </div>
        </template>
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
          <input v-model.trim="teamName" placeholder="Nombre del equipo" />
          <button type="button" :disabled="joinLoading" @click="joinTournament">
            {{ joinLoading ? 'Inscribiendo...' : 'Inscribir' }}
          </button>
        </div>
 
        <div v-if="tournament.visibility === 'private'" class="private-note">
          Torneo privado: usando código guardado en esta sesión.
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
import { ref, onMounted, watch, computed } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useAuthStore } from '../stores/auth'
import { storeToRefs } from 'pinia'
import api from '../services/api'
 
const route = useRoute()
const router = useRouter()
 
const authStore = useAuthStore()
const { token } = storeToRefs(authStore)
 
const tournament = ref(null)
const teams = ref([])
 
const loading = ref(true)
const error = ref('')
 
const requiresAccessCode = ref(false)
const accessCodeInput = ref('')
 
const teamName = ref('')
const joinLoading = ref(false)
const joinError = ref('')
const joinSuccess = ref('')
const alreadyJoined = ref(false)
 
const ACCESS_CODE_STORAGE_KEY = 'tourneyhub_private_codes'
 
function readCodeMap() {
  try {
    const raw = sessionStorage.getItem(ACCESS_CODE_STORAGE_KEY)
    return raw ? JSON.parse(raw) : {}
  } catch {
    return {}
  }
}
 
function saveAccessCodeForTournament(tournamentId, code) {
  const map = readCodeMap()
  map[String(tournamentId)] = code
  sessionStorage.setItem(ACCESS_CODE_STORAGE_KEY, JSON.stringify(map))
}
 
function getAccessCodeForTournament(tournamentId) {
  const map = readCodeMap()
  return String(map[String(tournamentId)] || '')
}
 
const isOnline = computed(() => Number(tournament.value?.is_online || 0) === 1)
 
const mapEmbedUrl = computed(() => {
  if (!tournament.value || isOnline.value) return ''
 
  const lat = Number(tournament.value.location_lat)
  const lng = Number(tournament.value.location_lng)
  if (!Number.isFinite(lat) || !Number.isFinite(lng)) return ''
 
  const delta = 0.01
  const left = lng - delta
  const right = lng + delta
  const top = lat + delta
  const bottom = lat - delta
  const bbox = `${left},${bottom},${right},${top}`
  const marker = `${lat},${lng}`
 
  return `https://www.openstreetmap.org/export/embed.html?bbox=${encodeURIComponent(bbox)}&layer=mapnik&marker=${encodeURIComponent(marker)}`
})
 
function getQueryAccessCode() {
  const code = route.query.code
  return typeof code === 'string' ? code.trim() : ''
}
 
function getCurrentAccessCode() {
  const fromSession = getAccessCodeForTournament(route.params.id)
  if (fromSession) return fromSession
  return getQueryAccessCode()
}
 
async function fetchTournament() {
  loading.value = true
  error.value = ''
  joinError.value = ''
  joinSuccess.value = ''
 
  try {
    const queryCode = getQueryAccessCode()
    if (queryCode) {
      saveAccessCodeForTournament(route.params.id, queryCode)
    }
 
    const code = getCurrentAccessCode()
    const config = code
      ? { headers: { 'X-Tournament-Code': code } }
      : undefined
 
    const res = await api.get(`/tournaments/${route.params.id}`, config)
    tournament.value = res.data
    teams.value = res.data.teams || []
    requiresAccessCode.value = false
    accessCodeInput.value = ''
 
    // Si venía por query, lo limpiamos para no dejarlo en URL/historial
    if (queryCode) {
      const newQuery = { ...route.query }
      delete newQuery.code
      router.replace({ path: route.path, query: newQuery })
    }
  } catch (err) {
    tournament.value = null
    teams.value = []
 
    if (err.response?.status === 403 && err.response?.data?.requires_access_code) {
      requiresAccessCode.value = true
      error.value = err.response?.data?.error || 'Torneo privado: introduce código.'
    } else if (err.response?.status === 404) {
      error.value = 'Torneo no encontrado.'
    } else {
      error.value = err.response?.data?.error || 'No se pudo cargar el detalle del torneo.'
    }
  } finally {
    loading.value = false
  }
}
 
function unlockPrivateTournament() {
  const code = accessCodeInput.value.trim().replace(/[^a-zA-Z0-9]/g, '').toUpperCase()
  if (!code) {
    error.value = 'Introduce un código de acceso.'
    return
  }
 
  saveAccessCodeForTournament(route.params.id, code)
  fetchTournament()
}
 
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
    const payload = { team_name: name }
 
    if (tournament.value?.visibility === 'private') {
      const code = getAccessCodeForTournament(route.params.id)
      if (!code) {
        joinError.value = 'Este torneo es privado y requiere código.'
        joinLoading.value = false
        return
      }
      payload.access_code = code
    }
 
    await api.post(`/tournaments/${route.params.id}/join`, payload)
 
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
 
function formatDateTime(date, time) {
  if (!date) return '-'
  const parsed = new Date(date)
  if (Number.isNaN(parsed.getTime())) return '-'
  const datePart = parsed.toLocaleDateString('es-ES')
  const hhmm = String(time || '00:00:00').slice(0, 5)
  return `${datePart} · ${hhmm}`
}
 
watch(
  () => route.params.id,
  () => {
    fetchTournament()
  }
)
 
onMounted(fetchTournament)
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
.visibility-chip {
  border-radius: 999px;
  padding: 0.25rem 0.65rem;
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

.visibility-chip.public {
  background: #e2e8f0;
  color: #334155;
}

.visibility-chip.private {
  background: #fee2e2;
  color: #991b1b;
}

h1 {
  font-size: clamp(1.4rem, 2.4vw, 2rem);
  margin-bottom: 0.2rem;
}

.game {
  color: var(--muted);
  margin-bottom: 0.45rem;
}

.description {
  margin-bottom: 0.85rem;
  color: #334155;
}

.info-grid {
  display: grid;
  gap: 0.7rem;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  margin-bottom: 0.9rem;
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

.location-box {
  border-top: 1px solid var(--border);
  padding-top: 0.8rem;
}

.location-box h3 {
  margin-bottom: 0.35rem;
}

.muted {
  color: #64748b;
}

.map-wrapper {
  margin-top: 0.65rem;
  border: 1px solid var(--border);
  border-radius: 10px;
  overflow: hidden;
  height: 260px;
}

.map-wrapper iframe {
  width: 100%;
  height: 100%;
  border: 0;
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

.private-note {
  margin-top: 0.45rem;
  font-size: 0.82rem;
  color: #7c2d12;
  background: #ffedd5;
  border: 1px solid #fed7aa;
  padding: 0.35rem 0.5rem;
  border-radius: 8px;
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

.state-private {
  border: 1px solid #fed7aa;
  background: #fff7ed;
}

.private-unlock {
  margin-top: 0.8rem;
  display: flex;
  gap: 0.5rem;
  justify-content: center;
}

.private-unlock input {
  width: min(300px, 100%);
  border: 1px solid var(--border);
  border-radius: 10px;
  padding: 0.6rem 0.7rem;
}

.private-unlock button {
  border: none;
  border-radius: 10px;
  background: #0ea5e9;
  color: #fff;
  font-weight: 700;
  padding: 0.6rem 0.9rem;
  cursor: pointer;
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

  .join-row,
  .private-unlock {
    flex-direction: column;
  }
}
</style>