<template>
  <section class="form-page">
    <article class="form-card">
      <header class="form-header">
        <h1>Crear nuevo torneo</h1>
        <p>Configura la competición con categoría, ubicación y acceso público/privado.</p>
      </header>
 
      <form @submit.prevent="createTournament">
        <!-- (mantén tu formulario igual; aquí solo dejo la versión completa con fixes de enlace privado) -->
        <fieldset>
          <legend>Categoría y disciplina</legend>
 
          <div class="input-row">
            <div class="input-group">
              <label for="category">Categoría</label>
              <select id="category" v-model="category" required>
                <option value="sports">Deporte</option>
                <option value="esports">e-Sports</option>
              </select>
            </div>
 
            <div class="input-group">
              <label for="discipline">Deporte / Juego popular</label>
              <select id="discipline" v-model="selectedDiscipline" required>
                <option value="">Selecciona una opción</option>
                <option v-for="item in disciplineOptions" :key="item.value" :value="item.value">
                  {{ item.label }}
                </option>
                <option value="custom">Personalizado</option>
              </select>
            </div>
          </div>
 
          <div v-if="selectedDiscipline === 'custom'" class="input-group">
            <label for="customDiscipline">Nombre personalizado</label>
            <input id="customDiscipline" v-model.trim="customDiscipline" placeholder="Ej: Rocket League" />
          </div>
        </fieldset>
 
        <fieldset>
          <legend>Datos del torneo</legend>
 
          <div class="input-group">
            <label for="name">Nombre del torneo</label>
            <input id="name" v-model.trim="name" required />
          </div>
 
          <div class="input-group">
            <label for="description">Descripción</label>
            <textarea id="description" v-model.trim="description" rows="4" required />
          </div>
 
          <div class="input-row">
            <div class="input-group">
              <label for="maxTeams">Nº máximo de equipos</label>
              <input id="maxTeams" v-model.number="max_teams" type="number" min="2" max="128" required />
            </div>
 
            <div class="input-group">
              <label for="format">Formato</label>
              <select id="format" v-model="format" required>
                <option value="single_elim">Eliminatoria simple</option>
                <option value="league">Liga</option>
              </select>
            </div>
          </div>
 
          <div class="input-row">
            <div class="input-group">
              <label for="startDate">Fecha de inicio</label>
              <input id="startDate" v-model="start_date" :min="todayYmd" type="date" required />
            </div>
 
            <div class="input-group">
              <label for="startTime">Hora de inicio</label>
              <input id="startTime" v-model="start_time" type="time" required />
            </div>
          </div>
 
          <div class="input-group">
            <label for="prize">Premio (opcional)</label>
            <input id="prize" v-model.trim="prize" />
          </div>
        </fieldset>
 
        <fieldset>
          <legend>Ubicación</legend>
 
          <template v-if="category === 'esports'">
            <p class="hint-box">Para e-Sports, el torneo se marcará como <strong>Online</strong>.</p>
          </template>
 
          <template v-else>
            <div class="input-group">
              <label for="locationName">Lugar</label>
              <input id="locationName" v-model.trim="location_name" required />
            </div>
 
            <div class="input-group">
              <label for="locationAddress">Dirección</label>
              <input id="locationAddress" v-model.trim="location_address" />
            </div>
 
            <div class="input-row">
              <div class="input-group">
                <label for="lat">Latitud</label>
                <input id="lat" v-model="location_lat" type="number" step="0.000001" required />
              </div>
              <div class="input-group">
                <label for="lng">Longitud</label>
                <input id="lng" v-model="location_lng" type="number" step="0.000001" required />
              </div>
            </div>
 
            <button type="button" class="ghost-btn" @click="searchLocation" :disabled="searchingLocation">
              {{ searchingLocation ? 'Buscando ubicación...' : 'Buscar ubicación en mapa' }}
            </button>
 
            <p v-if="locationSearchError" class="msg error">{{ locationSearchError }}</p>
 
            <div v-if="mapEmbedUrl" class="map-wrapper">
              <iframe title="Mapa del torneo" :src="mapEmbedUrl" loading="lazy" referrerpolicy="no-referrer-when-downgrade" />
            </div>
          </template>
        </fieldset>
 
        <fieldset>
          <legend>Privacidad</legend>
 
          <div class="input-group">
            <label for="visibility">Visibilidad</label>
            <select id="visibility" v-model="visibility" required>
              <option value="public">Público</option>
              <option value="private">Privado (código)</option>
            </select>
          </div>
 
          <div v-if="visibility === 'private'" class="input-group">
            <label for="privateCode">Código privado (opcional)</label>
            <input id="privateCode" v-model.trim="privateCode" placeholder="si vacío se genera" />
          </div>
        </fieldset>
 
        <p v-if="errorMessage" class="msg error">{{ errorMessage }}</p>
        <p v-if="successMessage" class="msg success">{{ successMessage }}</p>
 
        <button type="submit" class="submit-btn" :disabled="loading">
          {{ loading ? 'Creando torneo...' : 'Crear torneo' }}
        </button>
      </form>
 
      <section v-if="createdTournamentId" class="result-box">
        <h3>Torneo creado</h3>
 
        <p v-if="createdPrivateCode">
          Código privado: <strong>{{ createdPrivateCode }}</strong>
        </p>
        <p v-if="createdPrivateCode" class="security-note">
          Comparte el código por canal privado. El enlace/QR ya no incluye el código.
        </p>
 
        <p v-if="createdTournamentLink">
          Enlace directo:
          <a :href="createdTournamentLink" target="_blank" rel="noopener noreferrer">{{ createdTournamentLink }}</a>
        </p>
 
        <img v-if="qrImageUrl" class="qr-image" :src="qrImageUrl" alt="QR del torneo" />
 
        <router-link class="open-link" :to="createdTournamentPath">Abrir torneo</router-link>
      </section>
    </article>
  </section>
</template>
 
<script setup>
import { ref, computed, watch } from 'vue'
import api from '../services/api'
 
const ACCESS_CODE_STORAGE_KEY = 'tourneyhub_private_codes'
 
const sportsPopular = [
  { value: 'Fútbol', label: 'Fútbol' },
  { value: 'Baloncesto', label: 'Baloncesto' },
  { value: 'Tenis', label: 'Tenis' },
  { value: 'Pádel', label: 'Pádel' },
  { value: 'Voleibol', label: 'Voleibol' }
]
 
const esportsPopular = [
  { value: 'Fortnite', label: 'Fortnite' },
  { value: 'EA Sports FC', label: 'FIFA / EA FC' },
  { value: 'League of Legends', label: 'League of Legends' },
  { value: 'Valorant', label: 'Valorant' },
  { value: 'Counter-Strike 2', label: 'Counter-Strike 2' }
]
 
function getTodayLocalYmd() {
  const d = new Date()
  d.setMinutes(d.getMinutes() - d.getTimezoneOffset())
  return d.toISOString().slice(0, 10)
}
 
function saveAccessCodeForTournament(tournamentId, code) {
  try {
    const raw = sessionStorage.getItem(ACCESS_CODE_STORAGE_KEY)
    const map = raw ? JSON.parse(raw) : {}
    map[String(tournamentId)] = code
    sessionStorage.setItem(ACCESS_CODE_STORAGE_KEY, JSON.stringify(map))
  } catch {}
}
 
const todayYmd = getTodayLocalYmd()
 
const category = ref('sports')
const selectedDiscipline = ref('')
const customDiscipline = ref('')
 
const name = ref('')
const description = ref('')
const max_teams = ref(8)
const format = ref('single_elim')
const start_date = ref(todayYmd)
const start_time = ref('18:00')
const prize = ref('')
 
const location_name = ref('')
const location_address = ref('')
const location_lat = ref('')
const location_lng = ref('')
const searchingLocation = ref(false)
const locationSearchError = ref('')
 
const visibility = ref('public')
const privateCode = ref('')
 
const loading = ref(false)
const errorMessage = ref('')
const successMessage = ref('')
 
const createdTournamentId = ref(null)
const createdPrivateCode = ref('')
 
const disciplineOptions = computed(() => category.value === 'esports' ? esportsPopular : sportsPopular)
 
const resolvedGame = computed(() => {
  if (selectedDiscipline.value === 'custom') return customDiscipline.value.trim()
  return selectedDiscipline.value.trim()
})
 
const mapEmbedUrl = computed(() => {
  const lat = Number(location_lat.value)
  const lng = Number(location_lng.value)
  if (!Number.isFinite(lat) || !Number.isFinite(lng)) return ''
  const delta = 0.01
  const bbox = `${lng - delta},${lat - delta},${lng + delta},${lat + delta}`
  return `https://www.openstreetmap.org/export/embed.html?bbox=${encodeURIComponent(bbox)}&layer=mapnik&marker=${encodeURIComponent(`${lat},${lng}`)}`
})
 
const createdTournamentPath = computed(() => createdTournamentId.value ? `/tournaments/${createdTournamentId.value}` : '')
const createdTournamentLink = computed(() => createdTournamentId.value ? `${window.location.origin}/tournaments/${createdTournamentId.value}` : '')
const qrImageUrl = computed(() => createdTournamentLink.value ? `https://quickchart.io/qr?size=260&text=${encodeURIComponent(createdTournamentLink.value)}` : '')
 
watch(category, (newValue) => {
  selectedDiscipline.value = ''
  customDiscipline.value = ''
  locationSearchError.value = ''
 
  if (newValue === 'esports') {
    location_name.value = 'Online'
    location_address.value = 'Online'
    location_lat.value = ''
    location_lng.value = ''
  } else {
    location_name.value = ''
    location_address.value = ''
  }
})
 
async function searchLocation() {
  locationSearchError.value = ''
  const q = [location_name.value, location_address.value].map((s) => String(s || '').trim()).filter(Boolean).join(', ')
  if (!q) {
    locationSearchError.value = 'Escribe al menos el nombre del lugar.'
    return
  }
 
  searchingLocation.value = true
  try {
    const url = `https://nominatim.openstreetmap.org/search?format=json&limit=1&q=${encodeURIComponent(q)}`
    const res = await fetch(url)
    const data = await res.json()
 
    if (!Array.isArray(data) || data.length === 0) {
      locationSearchError.value = 'No se encontró esa ubicación.'
      return
    }
 
    location_lat.value = Number(data[0].lat).toFixed(6)
    location_lng.value = Number(data[0].lon).toFixed(6)
  } catch {
    locationSearchError.value = 'No se pudo consultar el mapa.'
  } finally {
    searchingLocation.value = false
  }
}
 
function validateBeforeSubmit() {
  if (!resolvedGame.value) return 'Selecciona un deporte/juego.'
  if (name.value.trim().length < 3) return 'El nombre debe tener al menos 3 caracteres.'
  if (description.value.trim().length < 10) return 'La descripción debe tener al menos 10 caracteres.'
  if (!start_date.value || start_date.value < todayYmd) return 'La fecha no puede ser anterior a hoy.'
  if (!/^(?:[01]\d|2[0-3]):[0-5]\d$/.test(start_time.value)) return 'Hora no válida.'
 
  if (category.value === 'sports') {
    if (!location_name.value.trim()) return 'Debes indicar el lugar.'
    const lat = Number(location_lat.value)
    const lng = Number(location_lng.value)
    if (!Number.isFinite(lat) || !Number.isFinite(lng)) return 'Debes indicar lat/lng válidas.'
  }
 
  if (visibility.value === 'private' && privateCode.value.trim()) {
    const clean = privateCode.value.trim().replace(/[^a-zA-Z0-9]/g, '')
    if (clean.length < 6 || clean.length > 16) return 'El código privado debe tener entre 6 y 16 caracteres.'
  }
 
  return ''
}
 
async function createTournament() {
  loading.value = true
  errorMessage.value = ''
  successMessage.value = ''
  createdTournamentId.value = null
  createdPrivateCode.value = ''
 
  const validationError = validateBeforeSubmit()
  if (validationError) {
    errorMessage.value = validationError
    loading.value = false
    return
  }
 
  const isEsports = category.value === 'esports'
 
  const payload = {
    name: name.value.trim(),
    description: description.value.trim(),
    game: resolvedGame.value,
    type: category.value,
    max_teams: Number(max_teams.value),
    format: format.value,
    start_date: start_date.value,
    start_time: start_time.value,
    prize: prize.value.trim(),
    visibility: visibility.value,
    access_code: visibility.value === 'private' ? privateCode.value.trim() : '',
    is_online: isEsports,
    location_name: isEsports ? 'Online' : location_name.value.trim(),
    location_address: isEsports ? 'Online' : location_address.value.trim(),
    location_lat: isEsports ? null : Number(location_lat.value),
    location_lng: isEsports ? null : Number(location_lng.value)
  }
 
  try {
    const res = await api.post('/tournaments', payload)

    createdTournamentId.value = res.data?.id || null
    createdPrivateCode.value = res.data?.private_access_code || ''
 
    if (createdTournamentId.value && createdPrivateCode.value) {
      saveAccessCodeForTournament(createdTournamentId.value, createdPrivateCode.value)
    }
 
    successMessage.value = createdPrivateCode.value
      ? 'Torneo privado creado. Código guardado en esta sesión.'
      : 'Torneo creado correctamente.'
  } catch (err) {
    errorMessage.value = err.response?.data?.error || 'Error al crear el torneo.'
  } finally {
    loading.value = false
  }
}
</script>

<style scoped>
.form-page {
  display: flex;
  justify-content: center;
}

.form-card {
  width: min(900px, 100%);
  background: var(--surface);
  border: 1px solid var(--border);
  border-radius: var(--radius);
  box-shadow: var(--shadow-md);
  padding: 1rem;
}

.form-header {
  margin-bottom: 0.9rem;
}

.form-header h1 {
  font-size: clamp(1.35rem, 2.3vw, 1.9rem);
  margin-bottom: 0.2rem;
}

.form-header p {
  color: var(--muted);
}

fieldset {
  border: 1px solid var(--border);
  border-radius: 12px;
  padding: 0.85rem;
  margin-bottom: 0.85rem;
}

legend {
  font-weight: 700;
  color: #334155;
  padding: 0 0.25rem;
}

.input-group {
  display: grid;
  gap: 0.35rem;
  margin-bottom: 0.75rem;
}

.input-group:last-child {
  margin-bottom: 0;
}

.input-row {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 0.75rem;
}

label {
  font-weight: 700;
  font-size: 0.9rem;
}

input,
select,
textarea {
  border: 1px solid var(--border);
  border-radius: 10px;
  padding: 0.62rem 0.72rem;
  background: #fff;
}

input:focus,
select:focus,
textarea:focus {
  outline: 2px solid rgba(6, 182, 212, 0.25);
  border-color: #06b6d4;
}

textarea {
  resize: vertical;
}

.hint-box {
  border: 1px dashed #cbd5e1;
  border-radius: 10px;
  padding: 0.7rem;
  color: #334155;
  background: #f8fafc;
}

.help {
  color: #64748b;
  font-size: 0.8rem;
}

.ghost-btn {
  border: 1px solid var(--border);
  border-radius: 10px;
  padding: 0.58rem 0.8rem;
  background: #fff;
  cursor: pointer;
  font-weight: 600;
}

.ghost-btn:disabled {
  opacity: 0.65;
  cursor: not-allowed;
}

.map-wrapper {
  margin-top: 0.7rem;
  border: 1px solid var(--border);
  border-radius: 10px;
  overflow: hidden;
  height: 280px;
}

.map-wrapper iframe {
  width: 100%;
  height: 100%;
  border: 0;
}

.submit-btn {
  width: 100%;
  border: none;
  border-radius: 10px;
  padding: 0.75rem 1rem;
  font-weight: 700;
  color: #fff;
  background: linear-gradient(135deg, #0ea5e9, #06b6d4);
  cursor: pointer;
}

.submit-btn:disabled {
  opacity: 0.72;
  cursor: not-allowed;
}

.msg {
  margin: 0.3rem 0 0.75rem;
  font-weight: 600;
}

.msg.error {
  color: #b91c1c;
}

.msg.success {
  color: #166534;
}

.result-box {
  margin-top: 1rem;
  border: 1px solid #bbf7d0;
  background: #f0fdf4;
  border-radius: 12px;
  padding: 0.85rem;
}

.result-box h3 {
  margin-bottom: 0.45rem;
}

.result-box a {
  color: #0369a1;
  word-break: break-all;
}

.qr-image {
  margin-top: 0.7rem;
  width: 220px;
  max-width: 100%;
  border: 1px solid #d1d5db;
  border-radius: 8px;
  background: #fff;
}

.open-link {
  margin-top: 0.7rem;
  display: inline-block;
  text-decoration: none;
  border: 1px solid #0284c7;
  color: #0369a1;
  border-radius: 8px;
  padding: 0.45rem 0.7rem;
  font-weight: 700;
}

.security-note {
  margin-top: 0.25rem;
  color: #7c2d12;
  font-size: 0.9rem;
}

@media (max-width: 760px) {
  .input-row {
    grid-template-columns: 1fr;
  }
}
</style>