<template>
  <section class="page">
    <header class="page-header">
      <div>
        <h1>Equipos del torneo</h1>
        <p v-if="tournamentName">{{ tournamentName }}</p>
      </div>

      <router-link :to="`/tournaments/${route.params.id}`" class="back-link">Volver al torneo</router-link>
    </header>

    <section v-if="requiresAccessCode && !loaded" class="state-box state-private">
      <h2>Torneo privado</h2>
      <p>Introduce el código para gestionar equipos.</p>
      <div class="private-row">
        <input v-model.trim="accessCodeInput" placeholder="Código de acceso" autocomplete="one-time-code" />
        <button type="button" @click="unlockPrivateTournament">Acceder</button>
      </div>
      <p v-if="error" class="msg error">{{ error }}</p>
    </section>

    <section v-else>
      <div v-if="loading" class="state-box">Cargando equipos...</div>

      <div v-else-if="error" class="state-box state-error">
        <p>{{ error }}</p>
        <button type="button" @click="fetchTeams">Reintentar</button>
      </div>

      <template v-else>
        <article class="create-team-box">
          <h2>Crear equipo</h2>
          <p>
            Si el torneo está completo, tu equipo entrará automáticamente en
            <strong>lista de espera</strong>.
          </p>

          <form class="create-form" @submit.prevent="createTeamOrWaitlist">
            <div class="input-group">
              <label for="teamName">Nombre del equipo</label>
              <input id="teamName" v-model.trim="form.team_name" required />
            </div>

            <div class="input-group">
              <label for="teamLogo">Logo / avatar del equipo (URL opcional)</label>
              <input id="teamLogo" v-model.trim="form.team_logo_url" placeholder="https://..." />
            </div>

            <div class="input-row">
              <div class="input-group">
                <label for="teamColor">Color del equipo</label>
                <input id="teamColor" v-model="form.team_color" type="color" />
              </div>

              <div class="input-group">
                <label for="teamCapacity">Plazas del equipo</label>
                <input id="teamCapacity" v-model.number="form.capacity" type="number" min="3" max="15" required />
              </div>
            </div>

            <p v-if="createMessage" class="msg success">{{ createMessage }}</p>
            <p v-if="createError" class="msg error">{{ createError }}</p>

            <button type="submit" class="submit-btn" :disabled="creating">
              {{ creating ? 'Procesando...' : 'Crear equipo / entrar en espera' }}
            </button>
          </form>

          <div v-if="waitlistInfo.position > 0" class="waitlist-box">
            <strong>Lista de espera activa</strong>
            <p>Tu posición actual: #{{ waitlistInfo.position }} (equipo: {{ waitlistInfo.team_name }})</p>
          </div>
        </article>

        <article class="teams-board">
          <div class="board-head">
            <h2>Equipos</h2>
            <span class="counter">{{ teams.length }}</span>
          </div>

          <p class="waitlist-chip">
            En lista de espera del torneo: <strong>{{ waitlistPending }}</strong>
          </p>

          <div v-if="teams.length === 0" class="state-box">
            Todavía no hay equipos registrados.
          </div>

          <div v-else class="teams-grid">
            <section v-for="team in teams" :key="team.id" class="team-block">
              <TeamRosterCard
                :team="team"
                :can-manage="canManage(team)"
                :can-change-roles="canChangeRoles(team)"
                @validate-member="(memberId) => validateMember(team.id, memberId)"
                @change-role="(payload) => changeMemberRole(team.id, payload)"
              />

              <div v-if="canManage(team)" class="invite-box">
                <h4>Invitar jugadores</h4>
                <div class="invite-row">
                  <div class="input-group small">
                    <label>Usos máximos</label>
                    <input v-model.number="inviteForm(team.id).max_uses" type="number" min="1" max="500" />
                  </div>
                  <div class="input-group small">
                    <label>Caduca (días)</label>
                    <input v-model.number="inviteForm(team.id).expires_in_days" type="number" min="1" max="30" />
                  </div>
                </div>

                <button type="button" class="mini-action" @click="generateInvite(team.id)" :disabled="inviteLoading[team.id]">
                  {{ inviteLoading[team.id] ? 'Generando...' : 'Generar invitación' }}
                </button>

                <p v-if="inviteError[team.id]" class="msg error">{{ inviteError[team.id] }}</p>

                <div v-if="inviteResult[team.id]" class="invite-result">
                  <p><strong>Código:</strong> {{ inviteResult[team.id].invite_code }}</p>
                  <p class="break"><strong>Enlace:</strong> {{ inviteResult[team.id].join_url }}</p>
                  <button type="button" class="copy-btn" @click="copyInviteLink(inviteResult[team.id].join_url)">
                    Copiar enlace
                  </button>
                </div>
              </div>
            </section>
          </div>
        </article>
      </template>
    </section>
  </section>
</template>

<script setup>
import { ref, reactive, computed, onMounted } from 'vue'
import { useRoute } from 'vue-router'
import { useAuthStore } from '../stores/auth'
import { storeToRefs } from 'pinia'
import api from '../services/api'
import TeamRosterCard from '../components/TeamRosterCard.vue'

const route = useRoute()
const authStore = useAuthStore()
const { payload } = storeToRefs(authStore)

const ACCESS_CODE_STORAGE_KEY = 'tourneyhub_private_codes'

const loading = ref(true)
const loaded = ref(false)
const error = ref('')
const teams = ref([])
const waitlistPending = ref(0)
const tournament = ref(null)

const requiresAccessCode = ref(false)
const accessCodeInput = ref('')

const creating = ref(false)
const createMessage = ref('')
const createError = ref('')
const waitlistInfo = reactive({
  position: 0,
  team_name: ''
})

const form = reactive({
  team_name: '',
  team_logo_url: '',
  team_color: '#0EA5E9',
  capacity: 5
})

const inviteForms = reactive({})
const inviteResult = reactive({})
const inviteError = reactive({})
const inviteLoading = reactive({})

const myUserId = computed(() => Number(payload.value?.id || 0))
const tournamentName = computed(() => String(tournament.value?.name || ''))

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

function sanitizeCode(value) {
  return String(value || '').trim().replace(/[^a-zA-Z0-9]/g, '').toUpperCase()
}

function getHeadersWithCode() {
  const code = getAccessCodeForTournament(route.params.id)
  if (!code) return undefined
  return { headers: { 'X-Tournament-Code': code } }
}

function inviteForm(teamId) {
  if (!inviteForms[teamId]) {
    inviteForms[teamId] = {
      max_uses: 25,
      expires_in_days: 7
    }
  }
  return inviteForms[teamId]
}

function canManage(team) {
  const role = String(team.current_user_role || '')
  return (role === 'captain' || role === 'co_captain') && team.current_user_pending_validation !== true
}

function canChangeRoles(team) {
  const role = String(team.current_user_role || '')
  return role === 'captain' && team.current_user_pending_validation !== true
}

async function fetchTeams() {
  loading.value = true
  error.value = ''

  try {
    const config = getHeadersWithCode()
    const res = await api.get(`/tournaments/${route.params.id}/teams`, config)

    tournament.value = res.data?.tournament || null
    teams.value = Array.isArray(res.data?.teams) ? res.data.teams : []
    waitlistPending.value = Number(res.data?.waitlist_pending || 0)

    requiresAccessCode.value = false
    loaded.value = true
  } catch (err) {
    if (err.response?.status === 403 && err.response?.data?.requires_access_code) {
      requiresAccessCode.value = true
      error.value = err.response?.data?.error || 'Debes introducir el código privado.'
    } else {
      error.value = err.response?.data?.error || 'No se pudieron cargar los equipos.'
    }
  } finally {
    loading.value = false
  }
}

function unlockPrivateTournament() {
  const code = sanitizeCode(accessCodeInput.value)
  if (!code) {
    error.value = 'Introduce un código válido.'
    return
  }
  saveAccessCodeForTournament(route.params.id, code)
  fetchTeams()
}

async function createTeamOrWaitlist() {
  creating.value = true
  createMessage.value = ''
  createError.value = ''
  waitlistInfo.position = 0
  waitlistInfo.team_name = ''

  try {
    const payloadCreate = {
      team_name: form.team_name.trim(),
      team_logo_url: form.team_logo_url.trim(),
      team_color: form.team_color,
      capacity: Number(form.capacity),
      access_code: getAccessCodeForTournament(route.params.id)
    }

    const res = await api.post(`/tournaments/${route.params.id}/team-entry`, payloadCreate)

    if (res.data?.waitlisted) {
      createMessage.value = res.data?.message || 'Añadido a lista de espera.'
      waitlistInfo.position = Number(res.data?.waitlist_position || 0)
      waitlistInfo.team_name = String(res.data?.team_name || form.team_name)
    } else {
      createMessage.value = res.data?.message || 'Equipo creado correctamente.'
      form.team_name = ''
      form.team_logo_url = ''
      form.team_color = '#0EA5E9'
      form.capacity = 5
    }

    await fetchTeams()
  } catch (err) {
    createError.value = err.response?.data?.error || 'No se pudo crear el equipo.'
  } finally {
    creating.value = false
  }
}

async function validateMember(teamId, memberId) {
  try {
    await api.patch(`/teams/${teamId}/members/${memberId}/validate`)
    await fetchTeams()
  } catch (err) {
    window.alert(err.response?.data?.error || 'No se pudo validar al miembro.')
  }
}

async function changeMemberRole(teamId, payloadRole) {
  try {
    await api.patch(`/teams/${teamId}/members/${payloadRole.memberId}/role`, {
      role: payloadRole.role
    })
    await fetchTeams()
  } catch (err) {
    window.alert(err.response?.data?.error || 'No se pudo cambiar el rol.')
  }
}

async function generateInvite(teamId) {
  inviteLoading[teamId] = true
  inviteError[teamId] = ''
  inviteResult[teamId] = null

  const formData = inviteForm(teamId)

  try {
    const res = await api.post(`/teams/${teamId}/invites`, {
      max_uses: Number(formData.max_uses),
      expires_in_days: Number(formData.expires_in_days)
    })
    inviteResult[teamId] = res.data
  } catch (err) {
    inviteError[teamId] = err.response?.data?.error || 'No se pudo generar la invitación.'
  } finally {
    inviteLoading[teamId] = false
  }
}

async function copyInviteLink(link) {
  try {
    await navigator.clipboard.writeText(link)
    window.alert('Enlace copiado')
  } catch {
    window.alert('No se pudo copiar automáticamente. Copia el enlace manualmente.')
  }
}

onMounted(() => {
  const codeFromQuery = sanitizeCode(route.query.code)
  if (codeFromQuery) {
    saveAccessCodeForTournament(route.params.id, codeFromQuery)
  }
  fetchTeams()
})
</script>

<style scoped>
.page-header {
  display: flex;
  justify-content: space-between;
  align-items: flex-end;
  gap: 0.8rem;
  margin-bottom: 0.9rem;
}

.page-header p {
  color: var(--muted);
}

.back-link {
  text-decoration: none;
  border: 1px solid var(--border);
  border-radius: 10px;
  padding: 0.45rem 0.65rem;
  background: #fff;
  color: #334155;
  font-weight: 700;
}

.create-team-box,
.teams-board {
  background: var(--surface);
  border: 1px solid var(--border);
  border-radius: var(--radius);
  box-shadow: var(--shadow-sm);
  padding: 0.9rem;
  margin-bottom: 1rem;
}

.create-team-box h2,
.teams-board h2 {
  margin-bottom: 0.3rem;
}

.create-form {
  margin-top: 0.65rem;
  display: grid;
  gap: 0.6rem;
}

.input-group {
  display: grid;
  gap: 0.3rem;
}

.input-group.small label {
  font-size: 0.8rem;
}

.input-group label {
  font-weight: 700;
  font-size: 0.88rem;
}

.input-group input {
  border: 1px solid var(--border);
  border-radius: 10px;
  padding: 0.55rem 0.65rem;
  background: #fff;
}

.input-row {
  display: grid;
  grid-template-columns: 140px 1fr;
  gap: 0.65rem;
}

.submit-btn {
  border: none;
  border-radius: 10px;
  padding: 0.65rem 0.8rem;
  font-weight: 700;
  color: #fff;
  background: linear-gradient(135deg, #0ea5e9, #06b6d4);
  cursor: pointer;
}

.submit-btn:disabled {
  opacity: 0.7;
  cursor: not-allowed;
}

.waitlist-box {
  margin-top: 0.7rem;
  border: 1px solid #fde68a;
  background: #fffbeb;
  color: #92400e;
  border-radius: 10px;
  padding: 0.55rem 0.7rem;
}

.board-head {
  display: flex;
  align-items: center;
  justify-content: space-between;
}

.counter {
  border: 1px solid var(--border);
  border-radius: 999px;
  padding: 0.2rem 0.55rem;
  font-size: 0.78rem;
  font-weight: 700;
  background: #f8fafc;
}

.waitlist-chip {
  margin: 0.45rem 0 0.8rem;
  font-size: 0.9rem;
  color: #334155;
}

.teams-grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(360px, 1fr));
  gap: 0.8rem;
}

.team-block {
  display: grid;
  gap: 0.55rem;
}

.invite-box {
  border: 1px dashed #cbd5e1;
  border-radius: 10px;
  padding: 0.62rem;
  background: #f8fafc;
}

.invite-box h4 {
  margin-bottom: 0.42rem;
}

.invite-row {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 0.55rem;
}

.mini-action,
.copy-btn {
  margin-top: 0.45rem;
  border: 1px solid #93c5fd;
  background: #eff6ff;
  color: #1d4ed8;
  border-radius: 8px;
  padding: 0.38rem 0.6rem;
  font-weight: 700;
  cursor: pointer;
}

.invite-result {
  margin-top: 0.45rem;
  border: 1px solid #dbeafe;
  background: #f0f9ff;
  border-radius: 8px;
  padding: 0.45rem;
}

.break {
  word-break: break-all;
}

.state-box {
  background: var(--surface);
  border: 1px dashed #cbd5e1;
  border-radius: var(--radius);
  padding: 1rem;
  text-align: center;
  color: var(--muted);
}

.state-private {
  border: 1px solid #fed7aa;
  background: #fff7ed;
}

.private-row {
  margin-top: 0.5rem;
  display: flex;
  gap: 0.45rem;
  justify-content: center;
}

.private-row input {
  width: min(290px, 100%);
  border: 1px solid var(--border);
  border-radius: 10px;
  padding: 0.55rem 0.65rem;
  text-transform: uppercase;
}

.private-row button {
  border: none;
  border-radius: 10px;
  background: #0ea5e9;
  color: #fff;
  font-weight: 700;
  padding: 0.55rem 0.75rem;
  cursor: pointer;
}

.state-error {
  border-color: #fecaca;
  background: #fff1f2;
  color: #991b1b;
}

.msg {
  margin-top: 0.3rem;
  font-size: 0.88rem;
  font-weight: 600;
}

.msg.error {
  color: #b91c1c;
}

.msg.success {
  color: #166534;
}

@media (max-width: 700px) {
  .input-row,
  .invite-row,
  .private-row {
    grid-template-columns: 1fr;
    display: grid;
  }

  .teams-grid {
    grid-template-columns: 1fr;
  }
}
</style>