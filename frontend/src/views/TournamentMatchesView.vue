<template>
  <section class="page">
    <header class="page-header">
      <div>
        <h1>Bracket y partidos</h1>
        <p v-if="tournamentName">{{ tournamentName }}</p>
        <p v-else>Gestiona cruces, estado de partido y resultados.</p>
      </div>

      <div class="header-actions">
        <router-link :to="`/tournaments/${route.params.id}`" class="ghost-link">
          Volver al torneo
        </router-link>
        <button
          v-if="permissions.can_bootstrap"
          type="button"
          class="primary-btn"
          :disabled="bootstrapping"
          @click="bootstrapBracket"
        >
          {{ bootstrapping ? 'Generando...' : 'Generar bracket inicial' }}
        </button>
      </div>
    </header>

    <section v-if="requiresAccessCode && !loaded" class="state-box state-private">
      <h2>Torneo privado</h2>
      <p>Introduce el código para consultar los partidos.</p>
      <div class="private-row">
        <input v-model.trim="accessCodeInput" placeholder="Código de acceso" autocomplete="one-time-code" />
        <button type="button" @click="unlockPrivateTournament">Acceder</button>
      </div>
      <p v-if="error" class="msg error">{{ error }}</p>
    </section>

    <section v-else>
      <div v-if="loading" class="state-box">Cargando bracket...</div>

      <div v-else-if="error" class="state-box state-error">
        <p>{{ error }}</p>
        <button type="button" @click="fetchMatches">Reintentar</button>
      </div>

      <template v-else>
        <article class="timeline-card">
          <h2>Timeline de fases</h2>

          <div v-if="phaseTimeline.length === 0" class="empty-inline">
            Aún no hay rondas generadas para este torneo.
          </div>

          <ol v-else class="timeline">
            <li
              v-for="phase in phaseTimeline"
              :key="phase.round_number"
              :class="{ current: phase.in_progress_count > 0 }"
            >
              <div class="timeline-head">
                <strong>{{ phase.phase_label }}</strong>
                <span>R{{ phase.round_number }}</span>
              </div>
              <div class="timeline-meta">
                <span>{{ phase.finalized_count }}/{{ phase.total_matches }} finalizados</span>
                <span>{{ phase.in_progress_count }} en juego</span>
                <span>{{ phase.pending_count }} pendientes</span>
              </div>
              <div class="phase-progress">
                <div
                  class="phase-progress-fill"
                  :style="{ width: `${phaseProgress(phase)}%` }"
                />
              </div>
            </li>
          </ol>
        </article>

        <article class="bracket-card">
          <h2>Bracket</h2>

          <div v-if="rounds.length === 0" class="state-box">
            <p>No hay partidos creados todavía.</p>
            <p v-if="permissions.can_bootstrap" class="hint">
              Pulsa <strong>Generar bracket inicial</strong> para crear los cruces automáticos.
            </p>
          </div>

          <div v-else class="bracket-scroll">
            <div class="round-column" v-for="round in rounds" :key="round.round_number">
              <header class="round-head">
                <h3>{{ round.phase_label }}</h3>
                <small>Ronda {{ round.round_number }}</small>
              </header>

              <article
                v-for="match in round.matches"
                :key="match.id"
                class="match-card"
                :class="`status-${match.status}`"
              >
                <div class="match-top">
                  <span class="match-id">Partido #{{ match.id }}</span>
                  <span class="status-chip" :class="`status-${match.status}`">
                    {{ statusLabel(match.status) }}
                  </span>
                </div>

                <div class="team-row" :class="{ winner: match.winner_team_id === match.team_a_id }">
                  <span class="team-name">{{ match.team_a_name || 'Por definir' }}</span>
                  <strong class="score">{{ match.score_a }}</strong>
                </div>
                <div class="team-row" :class="{ winner: match.winner_team_id === match.team_b_id }">
                  <span class="team-name">{{ match.team_b_name || 'Por definir' }}</span>
                  <strong class="score">{{ match.score_b }}</strong>
                </div>

                <p v-if="match.open_disputes_count > 0" class="dispute-chip">
                  {{ match.open_disputes_count }} disputa(s) abierta(s)
                </p>

                <router-link class="open-match-btn" :to="matchCenterLink(match.id)">
                  Abrir centro de partido
                </router-link>
              </article>
            </div>
          </div>
        </article>
      </template>
    </section>
  </section>
</template>

<script setup>
import { ref, reactive, onMounted } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import api from '../services/api'

const route = useRoute()
const router = useRouter()

const ACCESS_CODE_STORAGE_KEY = 'tourneyhub_private_codes'

const loading = ref(true)
const loaded = ref(false)
const error = ref('')
const requiresAccessCode = ref(false)
const accessCodeInput = ref('')
const bootstrapping = ref(false)

const tournamentName = ref('')
const rounds = ref([])
const phaseTimeline = ref([])
const permissions = reactive({
  can_manage: false,
  can_bootstrap: false
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

function sanitizeCode(value) {
  return String(value || '').trim().replace(/[^a-zA-Z0-9]/g, '').toUpperCase()
}

function getHeadersWithCode() {
  const code = getAccessCodeForTournament(route.params.id)
  if (!code) return undefined
  return { headers: { 'X-Tournament-Code': code } }
}

function matchCenterLink(matchId) {
  const code = getAccessCodeForTournament(route.params.id)
  if (!code) return `/matches/${matchId}`
  return `/matches/${matchId}?code=${encodeURIComponent(code)}`
}

function statusLabel(status) {
  if (status === 'in_progress') return 'En juego'
  if (status === 'finalized') return 'Finalizado'
  return 'Pendiente'
}

function phaseProgress(phase) {
  const total = Number(phase.total_matches || 0)
  if (total <= 0) return 0
  const finalized = Number(phase.finalized_count || 0)
  return Math.max(0, Math.min(100, Math.round((finalized / total) * 100)))
}

async function fetchMatches() {
  loading.value = true
  error.value = ''

  try {
    const config = getHeadersWithCode()
    const res = await api.get(`/tournaments/${route.params.id}/matches`, config)
    tournamentName.value = String(res.data?.tournament?.name || '')
    rounds.value = Array.isArray(res.data?.rounds) ? res.data.rounds : []
    phaseTimeline.value = Array.isArray(res.data?.phase_timeline) ? res.data.phase_timeline : []
    permissions.can_manage = Boolean(res.data?.permissions?.can_manage)
    permissions.can_bootstrap = Boolean(res.data?.permissions?.can_bootstrap)
    requiresAccessCode.value = false
    loaded.value = true
  } catch (err) {
    if (err.response?.status === 403 && err.response?.data?.requires_access_code) {
      requiresAccessCode.value = true
      error.value = err.response?.data?.error || 'Debes introducir el código privado.'
    } else {
      error.value = err.response?.data?.error || 'No se pudieron cargar los partidos.'
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
  fetchMatches()
}

async function bootstrapBracket() {
  bootstrapping.value = true
  error.value = ''
  try {
    const payload = {}
    const code = getAccessCodeForTournament(route.params.id)
    if (code) payload.access_code = code
    const res = await api.post(`/tournaments/${route.params.id}/matches/bootstrap`, payload)
    if (res.data?.message) {
      // reusar caja de error para feedback breve visual
      error.value = ''
    }
    await fetchMatches()
  } catch (err) {
    error.value = err.response?.data?.error || 'No se pudo generar el bracket.'
  } finally {
    bootstrapping.value = false
  }
}

onMounted(() => {
  const codeFromQuery = sanitizeCode(route.query.code)
  if (codeFromQuery) {
    saveAccessCodeForTournament(route.params.id, codeFromQuery)
    router.replace({ path: route.path, query: {} })
  }
  fetchMatches()
})
</script>

<style scoped>
.page-header {
  display: flex;
  align-items: flex-end;
  justify-content: space-between;
  gap: 0.8rem;
  margin-bottom: 0.9rem;
}

.page-header p {
  color: var(--muted);
}

.header-actions {
  display: flex;
  align-items: center;
  gap: 0.55rem;
  flex-wrap: wrap;
}

.ghost-link {
  text-decoration: none;
  border: 1px solid var(--border);
  border-radius: 10px;
  padding: 0.5rem 0.7rem;
  background: #fff;
  color: #334155;
  font-weight: 700;
}

.primary-btn {
  border: none;
  border-radius: 10px;
  background: linear-gradient(135deg, #0ea5e9, #06b6d4);
  color: #fff;
  font-weight: 700;
  padding: 0.5rem 0.8rem;
  cursor: pointer;
}

.primary-btn:disabled {
  opacity: 0.7;
  cursor: not-allowed;
}

.timeline-card,
.bracket-card {
  background: var(--surface);
  border: 1px solid var(--border);
  border-radius: var(--radius);
  box-shadow: var(--shadow-sm);
  padding: 0.9rem;
  margin-bottom: 0.9rem;
}

.timeline {
  list-style: none;
  display: grid;
  gap: 0.65rem;
}

.timeline li {
  border: 1px solid var(--border);
  border-radius: 10px;
  padding: 0.55rem 0.65rem;
  background: #fff;
}

.timeline li.current {
  border-color: #93c5fd;
  background: #f0f9ff;
}

.timeline-head {
  display: flex;
  justify-content: space-between;
  gap: 0.6rem;
}

.timeline-meta {
  margin-top: 0.25rem;
  display: flex;
  flex-wrap: wrap;
  gap: 0.55rem;
  color: #475569;
  font-size: 0.85rem;
}

.phase-progress {
  margin-top: 0.45rem;
  height: 8px;
  border-radius: 999px;
  background: #e2e8f0;
  overflow: hidden;
}

.phase-progress-fill {
  height: 100%;
  background: linear-gradient(90deg, #22c55e, #16a34a);
}

.bracket-scroll {
  overflow-x: auto;
  display: flex;
  gap: 0.8rem;
  padding-bottom: 0.25rem;
}

.round-column {
  min-width: 290px;
  width: 290px;
  display: grid;
  gap: 0.6rem;
}

.round-head {
  border: 1px solid var(--border);
  border-radius: 10px;
  background: #f8fafc;
  padding: 0.5rem 0.6rem;
}

.round-head h3 {
  margin: 0;
  font-size: 0.98rem;
}

.round-head small {
  color: #64748b;
}

.match-card {
  border: 1px solid var(--border);
  border-radius: 12px;
  padding: 0.6rem;
  background: #fff;
  display: grid;
  gap: 0.35rem;
}

.match-card.status-in_progress {
  border-color: #93c5fd;
}

.match-card.status-finalized {
  border-color: #86efac;
}

.match-top {
  display: flex;
  justify-content: space-between;
  gap: 0.4rem;
  font-size: 0.8rem;
}

.match-id {
  color: #64748b;
}

.status-chip {
  border-radius: 999px;
  padding: 0.15rem 0.5rem;
  font-weight: 700;
}

.status-chip.status-pending {
  background: #f1f5f9;
  color: #334155;
}

.status-chip.status-in_progress {
  background: #dbeafe;
  color: #1d4ed8;
}

.status-chip.status-finalized {
  background: #dcfce7;
  color: #166534;
}

.team-row {
  display: grid;
  grid-template-columns: 1fr auto;
  gap: 0.5rem;
  border: 1px solid #e2e8f0;
  border-radius: 8px;
  padding: 0.38rem 0.48rem;
}

.team-row.winner {
  border-color: #86efac;
  background: #f0fdf4;
}

.team-name {
  font-size: 0.9rem;
}

.score {
  font-size: 1rem;
}

.dispute-chip {
  border-radius: 999px;
  background: #ffedd5;
  color: #9a3412;
  font-size: 0.76rem;
  font-weight: 700;
  padding: 0.18rem 0.5rem;
  justify-self: start;
}

.open-match-btn {
  margin-top: 0.25rem;
  text-decoration: none;
  text-align: center;
  border-radius: 9px;
  padding: 0.45rem 0.6rem;
  background: #eff6ff;
  color: #1d4ed8;
  font-weight: 700;
  border: 1px solid #bfdbfe;
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
  border-color: #fed7aa;
  background: #fff7ed;
}

.state-error {
  border-color: #fecaca;
  background: #fff1f2;
  color: #991b1b;
}

.state-error button {
  margin-top: 0.55rem;
  border: 1px solid #ef4444;
  color: #b91c1c;
  background: #fff;
  border-radius: 9px;
  padding: 0.45rem 0.7rem;
  cursor: pointer;
}

.private-row {
  margin-top: 0.45rem;
  display: flex;
  justify-content: center;
  gap: 0.45rem;
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

.msg {
  margin-top: 0.4rem;
}

.msg.error {
  color: #b91c1c;
}

.empty-inline {
  color: #64748b;
}

.hint {
  margin-top: 0.25rem;
  color: #334155;
}

@media (max-width: 700px) {
  .page-header {
    align-items: stretch;
    flex-direction: column;
  }

  .header-actions,
  .private-row {
    display: grid;
    grid-template-columns: 1fr;
  }

  .round-column {
    min-width: 260px;
    width: 260px;
  }
}
</style>