<template>
  <section class="page">
    <header class="page-header">
      <div>
        <h1>Centro de partido</h1>
        <p v-if="tournamentName">
          {{ tournamentName }} · {{ match?.phase_label || '-' }} · Partido #{{ match?.id || '-' }}
        </p>
      </div>
      <div class="header-actions">
        <router-link
          v-if="match?.tournament_id"
          class="back-link"
          :to="`/tournaments/${match.tournament_id}/matches`"
        >
          Volver al bracket
        </router-link>
      </div>
    </header>

    <section v-if="requiresAccessCode && !match" class="state-box state-private">
      <h2>Torneo privado</h2>
      <p>Introduce el código para abrir el centro de partido.</p>
      <div class="private-row">
        <input v-model.trim="accessCodeInput" placeholder="Código de acceso" autocomplete="one-time-code" />
        <button type="button" @click="unlockPrivateTournament">Acceder</button>
      </div>
      <p v-if="error" class="msg error">{{ error }}</p>
    </section>

    <section v-else-if="loading" class="state-box">Cargando partido...</section>

    <section v-else-if="error" class="state-box state-error">
      <p>{{ error }}</p>
      <button type="button" @click="fetchMatchCenter">Reintentar</button>
    </section>

    <template v-else-if="match">
      <article class="scoreboard-card">
        <div class="match-meta-top">
          <span class="status-chip" :class="match.status">{{ statusLabel(match.status) }}</span>
          <div class="meta-right">
            <span>{{ dateLabel(match.scheduled_at) }}</span>
            <span v-if="match.location_name">{{ match.location_name }}</span>
          </div>
        </div>

        <div class="scoreboard">
          <section class="side" :style="{ '--side-color': normalizeTeamColor(match.team_a_color_hex, '#0EA5E9') }">
            <div class="logo-wrap">
              <img
                :src="resolveTeamLogo(match.team_a_logo_url)"
                alt="Equipo A"
                @error="onTeamALogoError"
              />
            </div>
            <h3>{{ match.team_a_name || 'Por definir' }}</h3>
          </section>

          <section class="center-score">
            <strong class="score">{{ match.score_a }} - {{ match.score_b }}</strong>
            <p class="winner" v-if="match.winner_team_name">
              Ganador provisional: <strong>{{ match.winner_team_name }}</strong>
            </p>
            <p v-else class="winner muted">Aún sin ganador</p>
          </section>

          <section class="side" :style="{ '--side-color': normalizeTeamColor(match.team_b_color_hex, '#64748B') }">
            <div class="logo-wrap">
              <img
                :src="resolveTeamLogo(match.team_b_logo_url)"
                alt="Equipo B"
                @error="onTeamBLogoError"
              />
            </div>
            <h3>{{ match.team_b_name || 'Por definir' }}</h3>
          </section>
        </div>

        <div class="confirm-grid">
          <div class="confirm-card" :class="{ ok: match.captain_a_confirmed }">
            <span>Confirmación Capitán A</span>
            <strong>{{ match.captain_a_confirmed ? 'Confirmado' : 'Pendiente' }}</strong>
          </div>
          <div class="confirm-card" :class="{ ok: match.captain_b_confirmed }">
            <span>Confirmación Capitán B</span>
            <strong>{{ match.captain_b_confirmed ? 'Confirmado' : 'Pendiente' }}</strong>
          </div>
        </div>
      </article>

      <article class="panel-grid">
        <section class="panel">
          <h2>Gestión de resultado</h2>

          <form class="score-form" @submit.prevent="submitScore">
            <div class="score-inputs">
              <div class="input-group">
                <label>{{ match.team_a_name || 'Equipo A' }}</label>
                <input v-model.number="scoreForm.score_a" type="number" min="0" max="99" required />
              </div>
              <div class="input-group">
                <label>{{ match.team_b_name || 'Equipo B' }}</label>
                <input v-model.number="scoreForm.score_b" type="number" min="0" max="99" required />
              </div>
            </div>

            <button type="submit" class="action-btn" :disabled="scoreLoading || !permissions.can_report_score">
              {{ scoreLoading ? 'Guardando...' : 'Guardar marcador' }}
            </button>
          </form>

          <div class="actions-row">
            <button
              type="button"
              class="action-btn secondary"
              :disabled="confirmLoading || !canConfirm"
              @click="confirmResult"
            >
              {{ confirmLoading ? 'Confirmando...' : 'Confirmar resultado' }}
            </button>

            <button
              type="button"
              class="action-btn ghost"
              :disabled="statusLoading || !permissions.can_manage"
              @click="setMatchStatus('in_progress')"
            >
              Marcar en juego
            </button>

            <button
              type="button"
              class="action-btn ghost"
              :disabled="statusLoading || !permissions.can_manage"
              @click="setMatchStatus('finalized')"
            >
              Marcar finalizado
            </button>
          </div>

          <p v-if="actionMessage" class="msg success">{{ actionMessage }}</p>
          <p v-if="actionError" class="msg error">{{ actionError }}</p>
        </section>

        <section class="panel">
          <h2>Reclamación / disputa</h2>

          <form class="dispute-form" @submit.prevent="openDispute">
            <div class="input-group">
              <label for="dispute-reason">Motivo</label>
              <textarea
                id="dispute-reason"
                v-model.trim="disputeReason"
                rows="4"
                minlength="10"
                maxlength="500"
                placeholder="Describe claramente la incidencia del partido..."
              />
            </div>

            <button type="submit" class="action-btn danger" :disabled="disputeLoading || !permissions.can_dispute">
              {{ disputeLoading ? 'Enviando...' : 'Abrir disputa' }}
            </button>
          </form>

          <ul v-if="disputes.length > 0" class="disputes-list">
            <li v-for="d in disputes" :key="d.id">
              <div class="dispute-top">
                <strong>#{{ d.id }} · {{ disputeStatusLabel(d.status) }}</strong>
                <small>{{ dateLabel(d.created_at, true) }}</small>
              </div>
              <p>{{ d.reason }}</p>
              <small class="muted">Abierta por @{{ d.created_by_username || 'usuario' }}</small>

              <div v-if="permissions.can_manage" class="dispute-admin">
                <select :value="d.status" @change="updateDisputeStatus(d, $event.target.value)">
                  <option value="open">Abierta</option>
                  <option value="reviewing">En revisión</option>
                  <option value="resolved">Resuelta</option>
                  <option value="rejected">Rechazada</option>
                </select>
              </div>
            </li>
          </ul>
          <p v-else class="muted">No hay disputas registradas para este partido.</p>
        </section>
      </article>

      <article class="timeline-card">
        <h2>Timeline del partido</h2>
        <ul v-if="timeline.length > 0" class="timeline-list">
          <li v-for="item in timeline" :key="item.id">
            <div class="dot"></div>
            <div class="content">
              <div class="line-top">
                <strong>{{ eventLabel(item.event_type) }}</strong>
                <small>{{ dateLabel(item.created_at, true) }}</small>
              </div>
              <p class="muted">
                {{ item.actor_username ? `@${item.actor_username}` : 'Sistema' }}
              </p>
            </div>
          </li>
        </ul>
        <p v-else class="muted">Todavía no hay eventos en el timeline.</p>
      </article>

      <article class="timeline-card">
        <h2>Timeline de fases</h2>
        <div v-if="phaseTimeline.length === 0" class="muted">Aún no hay fases generadas.</div>
        <ul v-else class="phase-list">
          <li v-for="phase in phaseTimeline" :key="phase.round_number">
            <div class="phase-head">
              <strong>{{ phase.phase_label }}</strong>
              <small>Ronda {{ phase.round_number }}</small>
            </div>
            <div class="phase-track">
              <span>Pendientes: {{ phase.pending_count }}</span>
              <span>En juego: {{ phase.in_progress_count }}</span>
              <span>Finalizados: {{ phase.finalized_count }}</span>
            </div>
          </li>
        </ul>
      </article>
    </template>
  </section>
</template>

<script setup>
import { ref, reactive, computed, onMounted } from 'vue'
import { useRoute } from 'vue-router'
import api from '../services/api'

const route = useRoute()
const ACCESS_CODE_STORAGE_KEY = 'tourneyhub_private_codes'
const API_BASE = import.meta.env.VITE_API_BASE_URL || 'http://localhost:8080/api'
const FALLBACK_LOGO = '/favicon.svg'

const loading = ref(true)
const error = ref('')
const requiresAccessCode = ref(false)
const accessCodeInput = ref('')
const inlineAccessCode = ref('')

const match = ref(null)
const tournament = ref(null)
const permissions = reactive({
  can_manage: false,
  can_report_score: false,
  can_confirm_as_team_a: false,
  can_confirm_as_team_b: false,
  can_dispute: false
})
const timeline = ref([])
const phaseTimeline = ref([])
const disputes = ref([])

const scoreForm = reactive({
  score_a: 0,
  score_b: 0
})

const scoreLoading = ref(false)
const confirmLoading = ref(false)
const statusLoading = ref(false)
const disputeLoading = ref(false)

const actionError = ref('')
const actionMessage = ref('')
const disputeReason = ref('')

const teamALogoBroken = ref(false)
const teamBLogoBroken = ref(false)

const tournamentName = computed(() => String(tournament.value?.name || ''))
const canConfirm = computed(() => permissions.can_confirm_as_team_a || permissions.can_confirm_as_team_b)

function sanitizeCode(value) {
  return String(value || '').trim().replace(/[^a-zA-Z0-9]/g, '').toUpperCase()
}

function readCodeMap() {
  try {
    const raw = sessionStorage.getItem(ACCESS_CODE_STORAGE_KEY)
    return raw ? JSON.parse(raw) : {}
  } catch {
    return {}
  }
}

function saveAccessCodeForTournament(tournamentId, code) {
  if (!tournamentId) return
  const map = readCodeMap()
  map[String(tournamentId)] = code
  sessionStorage.setItem(ACCESS_CODE_STORAGE_KEY, JSON.stringify(map))
}

function getAccessCodeForTournament(tournamentId) {
  if (!tournamentId) return ''
  const map = readCodeMap()
  return String(map[String(tournamentId)] || '')
}

function getBackendOrigin() {
  try {
    return new URL(API_BASE).origin
  } catch {
    return ''
  }
}

function resolveTeamLogo(url) {
  const value = String(url || '').trim()
  if (!value) return FALLBACK_LOGO
  if (value.startsWith('/uploads/')) {
    const origin = getBackendOrigin()
    return origin ? `${origin}${value}` : value
  }
  return value
}

function normalizeTeamColor(color, fallback) {
  const value = String(color || '').trim().toUpperCase()
  return /^#[0-9A-F]{6}$/.test(value) ? value : fallback
}

function statusLabel(status) {
  if (status === 'finalized') return 'Finalizado'
  if (status === 'in_progress') return 'En juego'
  return 'Pendiente'
}

function disputeStatusLabel(status) {
  if (status === 'reviewing') return 'En revisión'
  if (status === 'resolved') return 'Resuelta'
  if (status === 'rejected') return 'Rechazada'
  return 'Abierta'
}

function eventLabel(type) {
  const map = {
    created: 'Partido creado',
    status_change: 'Cambio de estado',
    score_submitted: 'Marcador reportado',
    captain_confirmed: 'Confirmación de capitán',
    finalized: 'Partido finalizado',
    dispute_opened: 'Disputa abierta',
    dispute_updated: 'Disputa actualizada'
  }
  return map[type] || type
}

function dateLabel(value, withTime = false) {
  if (!value) return '-'
  const d = new Date(value)
  if (Number.isNaN(d.getTime())) return '-'
  const date = d.toLocaleDateString('es-ES')
  if (!withTime) return date
  return `${date} ${d.toLocaleTimeString('es-ES', { hour: '2-digit', minute: '2-digit' })}`
}

function getTournamentAccessCode() {
  const tournamentId = tournament.value?.id || match.value?.tournament_id
  const fromMap = getAccessCodeForTournament(tournamentId)
  if (fromMap) return fromMap
  return inlineAccessCode.value
}

async function fetchMatchCenter() {
  loading.value = true
  error.value = ''
  actionError.value = ''
  actionMessage.value = ''

  try {
    const code = getTournamentAccessCode()
    const config = code ? { headers: { 'X-Tournament-Code': code } } : undefined
    const res = await api.get(`/matches/${route.params.id}`, config)

    match.value = res.data?.match || null
    tournament.value = res.data?.tournament || null
    timeline.value = Array.isArray(res.data?.timeline) ? res.data.timeline : []
    phaseTimeline.value = Array.isArray(res.data?.phase_timeline) ? res.data.phase_timeline : []
    disputes.value = Array.isArray(res.data?.disputes) ? res.data.disputes : []

    const p = res.data?.permissions || {}
    permissions.can_manage = Boolean(p.can_manage)
    permissions.can_report_score = Boolean(p.can_report_score)
    permissions.can_confirm_as_team_a = Boolean(p.can_confirm_as_team_a)
    permissions.can_confirm_as_team_b = Boolean(p.can_confirm_as_team_b)
    permissions.can_dispute = Boolean(p.can_dispute)

    scoreForm.score_a = Number(match.value?.score_a || 0)
    scoreForm.score_b = Number(match.value?.score_b || 0)

    requiresAccessCode.value = false

    const tournamentId = match.value?.tournament_id
    if (tournamentId && code) {
      saveAccessCodeForTournament(tournamentId, code)
      inlineAccessCode.value = code
    }
  } catch (err) {
    match.value = null
    tournament.value = null
    timeline.value = []
    phaseTimeline.value = []
    disputes.value = []

    if (err.response?.status === 403 && err.response?.data?.requires_access_code) {
      requiresAccessCode.value = true
      error.value = err.response?.data?.error || 'Torneo privado: introduce código.'
    } else {
      error.value = err.response?.data?.error || 'No se pudo cargar el centro de partido.'
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

  const tournamentId = tournament.value?.id || match.value?.tournament_id || route.query.tournament_id
  if (tournamentId) {
    saveAccessCodeForTournament(tournamentId, code)
  }
  inlineAccessCode.value = code
  accessCodeInput.value = code
  fetchMatchCenter()
}

async function submitScore() {
  if (!permissions.can_report_score) return
  actionError.value = ''
  actionMessage.value = ''
  scoreLoading.value = true

  try {
    const payload = {
      score_a: Number(scoreForm.score_a),
      score_b: Number(scoreForm.score_b)
    }
    const res = await api.patch(`/matches/${route.params.id}/score`, payload)
    actionMessage.value = res.data?.message || 'Marcador guardado.'
    await fetchMatchCenter()
  } catch (err) {
    actionError.value = err.response?.data?.error || 'No se pudo guardar el marcador.'
  } finally {
    scoreLoading.value = false
  }
}

async function confirmResult() {
  if (!canConfirm.value) return
  actionError.value = ''
  actionMessage.value = ''
  confirmLoading.value = true

  try {
    const res = await api.patch(`/matches/${route.params.id}/confirm`)
    actionMessage.value = res.data?.message || 'Confirmación registrada.'
    await fetchMatchCenter()
  } catch (err) {
    actionError.value = err.response?.data?.error || 'No se pudo confirmar el resultado.'
  } finally {
    confirmLoading.value = false
  }
}

async function setMatchStatus(status) {
  if (!permissions.can_manage) return
  actionError.value = ''
  actionMessage.value = ''
  statusLoading.value = true

  try {
    const res = await api.patch(`/matches/${route.params.id}/status`, { status })
    actionMessage.value = res.data?.message || 'Estado actualizado.'
    await fetchMatchCenter()
  } catch (err) {
    actionError.value = err.response?.data?.error || 'No se pudo actualizar el estado.'
  } finally {
    statusLoading.value = false
  }
}

async function openDispute() {
  if (!permissions.can_dispute) return
  actionError.value = ''
  actionMessage.value = ''

  const reason = disputeReason.value.trim()
  if (reason.length < 10) {
    actionError.value = 'El motivo de disputa debe tener al menos 10 caracteres.'
    return
  }

  disputeLoading.value = true
  try {
    const res = await api.post(`/matches/${route.params.id}/disputes`, { reason })
    actionMessage.value = res.data?.message || 'Disputa enviada.'
    disputeReason.value = ''
    await fetchMatchCenter()
  } catch (err) {
    actionError.value = err.response?.data?.error || 'No se pudo abrir la disputa.'
  } finally {
    disputeLoading.value = false
  }
}

async function updateDisputeStatus(dispute, status) {
  if (!permissions.can_manage) return
  try {
    await api.patch(`/matches/${route.params.id}/disputes/${dispute.id}`, {
      status,
      resolution_note: dispute.resolution_note || ''
    })
    await fetchMatchCenter()
  } catch (err) {
    actionError.value = err.response?.data?.error || 'No se pudo actualizar la disputa.'
  }
}

function onTeamALogoError(event) {
  teamALogoBroken.value = true
  event.target.src = FALLBACK_LOGO
}

function onTeamBLogoError(event) {
  teamBLogoBroken.value = true
  event.target.src = FALLBACK_LOGO
}

onMounted(() => {
  const codeFromQuery = sanitizeCode(route.query.code)
  if (codeFromQuery) {
    inlineAccessCode.value = codeFromQuery
    const tournamentFromQuery = Number(route.query.tournament_id || 0)
    if (tournamentFromQuery > 0) {
      saveAccessCodeForTournament(tournamentFromQuery, codeFromQuery)
    }
  }
  fetchMatchCenter()
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
  background: #fff;
  color: #334155;
  font-weight: 700;
  padding: 0.5rem 0.72rem;
}

.scoreboard-card,
.timeline-card,
.panel,
.state-box {
  background: var(--surface);
  border: 1px solid var(--border);
  border-radius: var(--radius);
  box-shadow: var(--shadow-sm);
  padding: 0.95rem;
  margin-bottom: 0.9rem;
}

.match-meta-top {
  display: flex;
  justify-content: space-between;
  align-items: center;
  gap: 0.7rem;
  margin-bottom: 0.8rem;
}

.meta-right {
  display: flex;
  gap: 0.7rem;
  color: #64748b;
  font-size: 0.85rem;
  flex-wrap: wrap;
}

.status-chip {
  border-radius: 999px;
  padding: 0.25rem 0.65rem;
  font-size: 0.8rem;
  font-weight: 700;
}

.status-chip.pending {
  background: #e2e8f0;
  color: #334155;
}

.status-chip.in_progress {
  background: #ffedd5;
  color: #9a3412;
}

.status-chip.finalized {
  background: #dcfce7;
  color: #166534;
}

.scoreboard {
  display: grid;
  grid-template-columns: 1fr auto 1fr;
  gap: 0.85rem;
  align-items: center;
}

.side {
  border: 1px solid var(--border);
  border-top: 4px solid var(--side-color);
  border-radius: 12px;
  background: #f8fafc;
  padding: 0.7rem;
  text-align: center;
}

.logo-wrap {
  width: 64px;
  height: 64px;
  margin: 0 auto 0.5rem;
  border-radius: 12px;
  border: 1px solid var(--border);
  background: #fff;
  overflow: hidden;
}

.logo-wrap img {
  width: 100%;
  height: 100%;
  object-fit: cover;
}

.side h3 {
  font-size: 1rem;
}

.center-score {
  text-align: center;
  padding: 0 0.7rem;
}

.score {
  display: block;
  font-size: clamp(2rem, 5vw, 3rem);
  line-height: 1;
}

.winner {
  margin-top: 0.35rem;
  font-size: 0.9rem;
}

.confirm-grid {
  margin-top: 0.9rem;
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 0.65rem;
}

.confirm-card {
  border: 1px solid #cbd5e1;
  border-radius: 10px;
  padding: 0.55rem 0.65rem;
  background: #f8fafc;
}

.confirm-card.ok {
  border-color: #86efac;
  background: #f0fdf4;
}

.confirm-card span {
  display: block;
  font-size: 0.8rem;
  color: #64748b;
}

.panel-grid {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 0.9rem;
}

.panel h2,
.timeline-card h2 {
  margin-bottom: 0.65rem;
  font-size: 1.05rem;
}

.score-form,
.dispute-form {
  display: grid;
  gap: 0.65rem;
}

.score-inputs {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 0.65rem;
}

.input-group {
  display: grid;
  gap: 0.32rem;
}

.input-group label {
  font-size: 0.84rem;
  color: #475569;
  font-weight: 700;
}

.input-group input,
.input-group textarea,
.dispute-admin select {
  border: 1px solid var(--border);
  border-radius: 10px;
  padding: 0.55rem 0.65rem;
  background: #fff;
}

.action-btn {
  border: none;
  border-radius: 10px;
  padding: 0.58rem 0.8rem;
  font-weight: 700;
  color: #fff;
  background: linear-gradient(135deg, #0ea5e9, #06b6d4);
  cursor: pointer;
}

.action-btn:disabled {
  opacity: 0.65;
  cursor: not-allowed;
}

.action-btn.secondary {
  background: linear-gradient(135deg, #0f766e, #14b8a6);
}

.action-btn.ghost {
  background: #fff;
  color: #334155;
  border: 1px solid #cbd5e1;
}

.action-btn.danger {
  background: linear-gradient(135deg, #dc2626, #f97316);
}

.actions-row {
  margin-top: 0.75rem;
  display: flex;
  flex-wrap: wrap;
  gap: 0.55rem;
}

.msg {
  margin-top: 0.45rem;
  font-size: 0.88rem;
  font-weight: 600;
}

.msg.error {
  color: #b91c1c;
}

.msg.success {
  color: #166534;
}

.disputes-list {
  list-style: none;
  display: grid;
  gap: 0.55rem;
}

.disputes-list li {
  border: 1px solid var(--border);
  border-radius: 10px;
  background: #f8fafc;
  padding: 0.58rem 0.65rem;
}

.dispute-top {
  display: flex;
  justify-content: space-between;
  gap: 0.5rem;
  margin-bottom: 0.3rem;
}

.dispute-admin {
  margin-top: 0.45rem;
}

.timeline-list {
  list-style: none;
  display: grid;
  gap: 0.55rem;
}

.timeline-list li {
  display: grid;
  grid-template-columns: 14px 1fr;
  gap: 0.5rem;
}

.dot {
  width: 10px;
  height: 10px;
  margin-top: 0.42rem;
  border-radius: 999px;
  background: #0ea5e9;
}

.line-top {
  display: flex;
  justify-content: space-between;
  gap: 0.4rem;
  align-items: baseline;
}

.phase-list {
  list-style: none;
  display: grid;
  gap: 0.55rem;
}

.phase-list li {
  border: 1px solid var(--border);
  border-radius: 10px;
  padding: 0.52rem 0.62rem;
  background: #f8fafc;
}

.phase-head {
  display: flex;
  justify-content: space-between;
  margin-bottom: 0.35rem;
}

.phase-track {
  display: flex;
  gap: 0.8rem;
  flex-wrap: wrap;
  font-size: 0.84rem;
  color: #475569;
}

.muted {
  color: #64748b;
}

.state-box {
  text-align: center;
  color: var(--muted);
}

.state-private {
  border-color: #fed7aa;
  background: #fff7ed;
}

.private-row {
  margin-top: 0.55rem;
  display: flex;
  gap: 0.5rem;
  justify-content: center;
}

.private-row input {
  width: min(300px, 100%);
  border: 1px solid var(--border);
  border-radius: 10px;
  padding: 0.56rem 0.65rem;
}

.private-row button {
  border: none;
  border-radius: 10px;
  background: #0ea5e9;
  color: #fff;
  font-weight: 700;
  padding: 0.56rem 0.8rem;
  cursor: pointer;
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
  padding: 0.45rem 0.75rem;
  cursor: pointer;
}

@media (max-width: 980px) {
  .panel-grid {
    grid-template-columns: 1fr;
  }
}

@media (max-width: 720px) {
  .scoreboard {
    grid-template-columns: 1fr;
  }

  .score-inputs {
    grid-template-columns: 1fr;
  }

  .confirm-grid {
    grid-template-columns: 1fr;
  }

  .line-top,
  .phase-head {
    flex-direction: column;
    align-items: flex-start;
    gap: 0.2rem;
  }

  .private-row {
    flex-direction: column;
  }
}
</style>