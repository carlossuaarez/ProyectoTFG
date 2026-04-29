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
      <p class="creator">Creado por <strong>{{ creatorLabel }}</strong></p>
      <p class="description">{{ tournament.description || 'Sin descripción.' }}</p>

      <div class="info-grid">
        <div class="info-item">
          <span>Inicio</span>
          <strong>{{ formatDateTime(tournament.start_date, tournament.start_time) }}</strong>
        </div>
        <div class="info-item">
          <span>Equipos</span>
          <strong>
            {{ teams.length }} / {{ tournament.max_teams }}
            <template v-if="isFullTournament"> · COMPLETO</template>
          </strong>
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

      <div v-if="canEdit" class="owner-actions">
        <button type="button" class="edit-toggle-btn" @click="toggleEditMode">
          {{ editMode ? 'Cancelar edición' : 'Editar torneo' }}
        </button>
      </div>

      <section v-if="editMode" class="edit-panel">
        <h3>Editar datos del torneo</h3>

        <form @submit.prevent="updateTournament" class="edit-form">
          <div class="edit-grid">
            <div class="input-group">
              <label for="edit-name">Nombre</label>
              <input id="edit-name" v-model.trim="editForm.name" required />
            </div>

            <div class="input-group">
              <label for="edit-game">Disciplina / juego</label>
              <input id="edit-game" v-model.trim="editForm.game" required />
            </div>

            <div class="input-group full">
              <label for="edit-description">Descripción (opcional)</label>
              <textarea id="edit-description" v-model.trim="editForm.description" rows="4" />
            </div>

            <div class="input-group">
              <label for="edit-type">Categoría</label>
              <select id="edit-type" v-model="editForm.type" required>
                <option value="sports">Deporte</option>
                <option value="esports">e-Sports</option>
              </select>
            </div>

            <div class="input-group">
              <label for="edit-format">Formato</label>
              <select id="edit-format" v-model="editForm.format" required>
                <option value="single_elim">Eliminatoria simple</option>
                <option value="league">Liga</option>
              </select>
            </div>

            <div class="input-group">
              <label for="edit-start-date">Fecha inicio</label>
              <input id="edit-start-date" v-model="editForm.start_date" :min="todayYmd" type="date" required />
            </div>

            <div class="input-group">
              <label for="edit-start-time">Hora inicio</label>
              <input id="edit-start-time" v-model="editForm.start_time" type="time" required />
            </div>

            <div class="input-group">
              <label for="edit-prize">Premio (opcional)</label>
              <input id="edit-prize" v-model.trim="editForm.prize" />
            </div>

            <div class="input-group">
              <label for="edit-visibility">Visibilidad</label>
              <select id="edit-visibility" v-model="editForm.visibility" required>
                <option value="public">Público</option>
                <option value="private">Privado</option>
              </select>
            </div>

            <div class="input-group">
              <label for="edit-max-teams">Nº máximo equipos</label>
              <input id="edit-max-teams" :value="tournament.max_teams" disabled />
              <p class="readonly-warning">Este valor no se puede modificar una vez creado el torneo.</p>
            </div>
          </div>

          <div v-if="editForm.type === 'esports'" class="hint-box">
            Al guardar en e-Sports, la ubicación se marcará automáticamente como <strong>Online</strong>.
          </div>

          <div v-else class="edit-grid">
            <div class="input-group">
              <label for="edit-location-name">Lugar</label>
              <input id="edit-location-name" v-model.trim="editForm.location_name" required />
            </div>

            <div class="input-group">
              <label for="edit-location-address">Dirección</label>
              <input id="edit-location-address" v-model.trim="editForm.location_address" />
            </div>

            <div class="input-group">
              <label for="edit-lat">Latitud</label>
              <input id="edit-lat" v-model="editForm.location_lat" type="number" step="0.000001" required />
            </div>

            <div class="input-group">
              <label for="edit-lng">Longitud</label>
              <input id="edit-lng" v-model="editForm.location_lng" type="number" step="0.000001" required />
            </div>
          </div>

          <p v-if="updateError" class="msg error">{{ updateError }}</p>
          <p v-if="updateSuccess" class="msg success">{{ updateSuccess }}</p>
          <p v-if="generatedPrivateCode" class="msg success">
            Nuevo código privado generado: <strong>{{ generatedPrivateCode }}</strong>
          </p>

          <div class="edit-actions">
            <button type="button" class="ghost-btn" @click="cancelEdit">Cancelar</button>
            <button type="submit" class="save-btn" :disabled="updateLoading">
              {{ updateLoading ? 'Guardando...' : 'Guardar cambios' }}
            </button>
          </div>
        </form>
      </section>
    </article>

    <article class="side-card">
      <h2>Equipos inscritos</h2>

      <ul v-if="teams.length > 0" class="team-list">
        <li v-for="team in teams" :key="team.id">{{ team.name }}</li>
      </ul>
      <p v-else class="empty">Todavía no hay equipos inscritos.</p>

      <div v-if="canJoin && !isFullTournament" class="join-box">
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

      <p v-else-if="isFullTournament" class="full-note">
        COMPLETO: este torneo ya no admite más equipos.
      </p>

      <p v-else-if="isAdminPreview && tournament.visibility === 'private'" class="admin-preview-note">
        Vista de administración: puedes consultar la información del torneo privado sin código, pero no inscribirte.
      </p>

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
const { token, isAdmin, payload } = storeToRefs(authStore)

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

const editMode = ref(false)
const updateLoading = ref(false)
const updateError = ref('')
const updateSuccess = ref('')
const generatedPrivateCode = ref('')

const ACCESS_CODE_STORAGE_KEY = 'tourneyhub_private_codes'

const editForm = ref({
  name: '',
  description: '',
  game: '',
  type: 'sports',
  format: 'single_elim',
  start_date: '',
  start_time: '18:00',
  prize: '',
  visibility: 'public',
  location_name: '',
  location_address: '',
  location_lat: '',
  location_lng: '',
})

function getTodayLocalYmd() {
  const d = new Date()
  d.setMinutes(d.getMinutes() - d.getTimezoneOffset())
  return d.toISOString().slice(0, 10)
}
const todayYmd = getTodayLocalYmd()

const isAdminPreview = computed(() => route.query.admin_preview === '1' && isAdmin.value === true)

const canJoin = computed(() => {
  if (!token.value || alreadyJoined.value) return false
  if (isAdminPreview.value && tournament.value?.visibility === 'private') return false
  return true
})

const canEdit = computed(() => {
  if (!token.value || !tournament.value) return false

  const tokenUserId = Number(payload.value?.id || 0)
  const tournamentOwnerId = Number(tournament.value?.created_by || 0)

  if (tokenUserId > 0 && tokenUserId === tournamentOwnerId) return true

  // fallback por si backend marca owner_preview
  return Boolean(tournament.value?.owner_preview)
})

const creatorLabel = computed(() => {
  const username = String(tournament.value?.created_by_username || '').trim()
  if (username) return `@${username}`

  const createdBy = Number(tournament.value?.created_by || 0)
  return createdBy > 0 ? `Usuario #${createdBy}` : 'Desconocido'
})

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
const isFullTournament = computed(() => {
  const current = Number(tournament.value?.teams_count ?? teams.value.length)
  const max = Number(tournament.value?.max_teams || 0)
  return max > 0 && current >= max
})

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
  // En modo vista admin no usamos código, forzamos bypass por rol admin en backend.
  if (isAdminPreview.value) return ''

  const fromSession = getAccessCodeForTournament(route.params.id)
  if (fromSession) return fromSession
  return getQueryAccessCode()
}

function syncEditFormFromTournament() {
  if (!tournament.value) return
  editForm.value = {
    name: String(tournament.value.name || ''),
    description: String(tournament.value.description || ''),
    game: String(tournament.value.game || ''),
    type: String(tournament.value.type || 'sports'),
    format: String(tournament.value.format || 'single_elim'),
    start_date: String(tournament.value.start_date || ''),
    start_time: String(tournament.value.start_time || '18:00:00').slice(0, 5),
    prize: String(tournament.value.prize || ''),
    visibility: String(tournament.value.visibility || 'public'),
    location_name: String(tournament.value.location_name || ''),
    location_address: String(tournament.value.location_address || ''),
    location_lat: tournament.value.location_lat ?? '',
    location_lng: tournament.value.location_lng ?? '',
  }
}

async function fetchTournament() {
  loading.value = true
  error.value = ''
  joinError.value = ''
  joinSuccess.value = ''

  try {
    const queryCode = getQueryAccessCode()
    if (queryCode && !isAdminPreview.value) {
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

    syncEditFormFromTournament()

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

function toggleEditMode() {
  if (!editMode.value) {
    syncEditFormFromTournament()
    updateError.value = ''
    updateSuccess.value = ''
    generatedPrivateCode.value = ''
  }
  editMode.value = !editMode.value
}

function cancelEdit() {
  editMode.value = false
  updateError.value = ''
  updateSuccess.value = ''
  generatedPrivateCode.value = ''
  syncEditFormFromTournament()
}

function validateEditForm() {
  if (editForm.value.name.trim().length < 3) return 'El nombre debe tener al menos 3 caracteres.'
  if (!editForm.value.game.trim()) return 'La disciplina/juego es obligatorio.'
  if (!['sports', 'esports'].includes(editForm.value.type)) return 'Categoría no válida.'
  if (!['single_elim', 'league'].includes(editForm.value.format)) return 'Formato no válido.'
  if (!editForm.value.start_date || editForm.value.start_date < todayYmd) return 'La fecha no puede ser anterior a hoy.'
  if (!/^(?:[01]\d|2[0-3]):[0-5]\d$/.test(editForm.value.start_time)) return 'Hora no válida.'

  if (editForm.value.type === 'sports') {
    if (!editForm.value.location_name.trim()) return 'Debes indicar el lugar.'
    const lat = Number(editForm.value.location_lat)
    const lng = Number(editForm.value.location_lng)
    if (!Number.isFinite(lat) || lat < -90 || lat > 90) return 'Latitud no válida.'
    if (!Number.isFinite(lng) || lng < -180 || lng > 180) return 'Longitud no válida.'
  }

  return ''
}

async function updateTournament() {
  updateError.value = ''
  updateSuccess.value = ''
  generatedPrivateCode.value = ''

  const validationError = validateEditForm()
  if (validationError) {
    updateError.value = validationError
    return
  }

  updateLoading.value = true
  try {
    const isEsports = editForm.value.type === 'esports'

    const payloadUpdate = {
      name: editForm.value.name.trim(),
      description: editForm.value.description.trim(),
      game: editForm.value.game.trim(),
      type: editForm.value.type,
      format: editForm.value.format,
      start_date: editForm.value.start_date,
      start_time: editForm.value.start_time,
      prize: editForm.value.prize.trim(),
      visibility: editForm.value.visibility,
      is_online: isEsports ? 1 : 0,
      location_name: isEsports ? 'Online' : editForm.value.location_name.trim(),
      location_address: isEsports ? 'Online' : editForm.value.location_address.trim(),
      location_lat: isEsports ? null : Number(editForm.value.location_lat),
      location_lng: isEsports ? null : Number(editForm.value.location_lng),
      max_teams: Number(tournament.value?.max_teams || 0), // solo para mantener consistencia, backend bloqueará cambios
    }

    const res = await api.put(`/tournaments/${route.params.id}`, payloadUpdate)

    const newPrivateCode = String(res.data?.private_access_code || '')
    if (newPrivateCode) {
      generatedPrivateCode.value = newPrivateCode
      saveAccessCodeForTournament(route.params.id, newPrivateCode)
    }

    updateSuccess.value = res.data?.message || 'Torneo actualizado correctamente.'
    editMode.value = false

    await fetchTournament()
  } catch (err) {
    updateError.value = err.response?.data?.error || 'No se pudo actualizar el torneo.'
  } finally {
    updateLoading.value = false
  }
}

async function joinTournament() {
  const name = teamName.value.trim()
  if (!name) {
    joinError.value = 'Escribe un nombre de equipo.'
    joinSuccess.value = ''
    return
  }

  if (isAdminPreview.value && tournament.value?.visibility === 'private') {
    joinError.value = 'En vista admin no está permitida la inscripción en torneos privados.'
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
    editMode.value = false
    fetchTournament()
  }
)

watch(
  () => route.query.admin_preview,
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
  margin-bottom: 0.2rem;
}

.creator {
  color: #334155;
  margin-bottom: 0.5rem;
  font-size: 0.92rem;
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

.owner-actions {
  margin-top: 0.9rem;
}

.edit-toggle-btn {
  border: 1px solid #93c5fd;
  background: #eff6ff;
  color: #1d4ed8;
  border-radius: 10px;
  padding: 0.5rem 0.8rem;
  font-weight: 700;
  cursor: pointer;
}

.edit-panel {
  margin-top: 0.9rem;
  border: 1px solid #dbeafe;
  border-radius: 12px;
  background: #f8fbff;
  padding: 0.85rem;
}

.edit-panel h3 {
  margin-bottom: 0.7rem;
}

.edit-form {
  display: grid;
  gap: 0.7rem;
}

.edit-grid {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 0.7rem;
}

.input-group {
  display: grid;
  gap: 0.35rem;
}

.input-group.full {
  grid-column: 1 / -1;
}

.input-group label {
  font-weight: 700;
  font-size: 0.88rem;
  color: #334155;
}

.input-group input,
.input-group select,
.input-group textarea {
  border: 1px solid #cbd5e1;
  border-radius: 10px;
  padding: 0.58rem 0.7rem;
  background: #fff;
}

.input-group textarea {
  resize: vertical;
}

.readonly-warning {
  margin-top: 0.25rem;
  border: 1px solid #fed7aa;
  border-radius: 8px;
  background: #fff7ed;
  color: #9a3412;
  font-size: 0.82rem;
  font-weight: 600;
  padding: 0.45rem 0.55rem;
}

.hint-box {
  border: 1px dashed #cbd5e1;
  border-radius: 10px;
  padding: 0.6rem 0.7rem;
  color: #334155;
  background: #f8fafc;
}

.edit-actions {
  display: flex;
  justify-content: flex-end;
  gap: 0.55rem;
}

.ghost-btn {
  border: 1px solid #cbd5e1;
  background: #fff;
  color: #334155;
  border-radius: 10px;
  padding: 0.55rem 0.8rem;
  font-weight: 700;
  cursor: pointer;
}

.save-btn {
  border: none;
  border-radius: 10px;
  background: linear-gradient(135deg, #0ea5e9, #06b6d4);
  color: #fff;
  font-weight: 700;
  padding: 0.55rem 0.9rem;
  cursor: pointer;
}

.save-btn:disabled {
  opacity: 0.7;
  cursor: not-allowed;
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

.admin-preview-note {
  margin-top: 0.75rem;
  border: 1px solid #fde68a;
  background: #fffbeb;
  color: #92400e;
  border-radius: 10px;
  padding: 0.65rem 0.75rem;
  font-size: 0.88rem;
  font-weight: 600;
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

@media (max-width: 700px) {
  .info-grid,
  .edit-grid {
    grid-template-columns: 1fr;
  }

  .join-row,
  .private-unlock {
    flex-direction: column;
  }
}
</style>