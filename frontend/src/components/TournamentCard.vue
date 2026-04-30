<template>
  <article class="card">
    <div class="card-top">
      <span class="badge" :class="tournament.type">
        {{ tournament.type === 'esports' ? 'e-Sports' : 'Deporte' }}
      </span>
      <span class="visibility" :class="tournament.visibility">
        {{ tournament.visibility === 'private' ? 'Privado' : 'Público' }}
      </span>
    </div>

    <h3>{{ tournament.name }}</h3>
    <p class="game">{{ tournament.game }}</p>
    <p class="creator">Creado por: <strong>{{ creatorLabel }}</strong></p>
    <p class="description">{{ tournament.description || 'Sin descripción' }}</p>

    <p v-if="remainingSlots === 1 && !isFull" class="low-slots-warning">
      Quedan pocas plazas: solo 1 equipo.
    </p>

    <div class="meta-grid">
      <div>
        <span class="label">Inicio</span>
        <strong>{{ formatDateTime(tournament.start_date, tournament.start_time) }}</strong>
      </div>
      <div>
        <span class="label">Equipos</span>
        <strong v-if="isFull" class="full-badge">COMPLETO</strong>
        <strong v-else>{{ teamsCount }} / {{ tournament.max_teams }}</strong>
      </div>
      <div>
        <span class="label">Formato</span>
        <strong>{{ tournament.format === 'single_elim' ? 'Eliminatoria' : 'Liga' }}</strong>
      </div>
      <div>
        <span class="label">Lugar</span>
        <strong>{{ tournament.is_online == 1 ? 'Online' : (tournament.location_name || 'Pendiente') }}</strong>
      </div>
    </div>

    <router-link class="cta" :to="`/tournaments/${tournament.id}`">Ver torneo</router-link>
  </article>
</template>

<script setup>
import { computed } from 'vue'

const props = defineProps({
  tournament: {
    type: Object,
    required: true,
  },
})

const creatorLabel = computed(() => {
  const username = String(props.tournament?.created_by_username || '').trim()
  if (username) return `@${username}`

  const createdBy = Number(props.tournament?.created_by || 0)
  return createdBy > 0 ? `Usuario #${createdBy}` : 'Desconocido'
})

const teamsCount = computed(() => Number(props.tournament?.teams_count || 0))
const maxTeams = computed(() => Number(props.tournament?.max_teams || 0))
const isFull = computed(() => {
  if (Number(props.tournament?.is_full || 0) === 1) return true
  return maxTeams.value > 0 && teamsCount.value >= maxTeams.value
})
const remainingSlots = computed(() => {
  if (maxTeams.value <= 0) return 0
  return Math.max(0, maxTeams.value - teamsCount.value)
})

function formatDateTime(date, time) {
  if (!date) return '-'
  const parsed = new Date(date)
  if (Number.isNaN(parsed.getTime())) return '-'
  const datePart = parsed.toLocaleDateString('es-ES')
  const hhmm = String(time || '00:00:00').slice(0, 5)
  return `${datePart} · ${hhmm}`
}
</script>

<style scoped>
.card {
  background: var(--surface);
  border: 1px solid var(--border);
  border-radius: var(--radius);
  box-shadow: var(--shadow-sm);
  padding: 1rem;
  display: flex;
  flex-direction: column;
  min-height: 330px;
  transition: transform 0.2s ease, box-shadow 0.2s ease;
}

.card:hover {
  transform: translateY(-4px);
  box-shadow: var(--shadow-md);
}

.card-top {
  display: flex;
  justify-content: space-between;
  gap: 0.6rem;
  align-items: center;
  margin-bottom: 0.8rem;
}

.badge,
.visibility {
  border-radius: 999px;
  padding: 0.26rem 0.65rem;
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
  font-size: 1.12rem;
  margin-bottom: 0.25rem;
  line-height: 1.25;
}

.game {
  color: var(--muted);
  margin-bottom: 0.25rem;
}

.creator {
  color: #334155;
  font-size: 0.88rem;
  margin-bottom: 0.45rem;
}

.description {
  color: #334155;
  font-size: 0.92rem;
  margin-bottom: 0.7rem;
}

.low-slots-warning {
  margin-bottom: 0.8rem;
  border: 1px solid #fcd34d;
  background: #fffbeb;
  color: #92400e;
  border-radius: 8px;
  padding: 0.42rem 0.55rem;
  font-size: 0.82rem;
  font-weight: 700;
}

.meta-grid {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 0.65rem;
  margin-bottom: 1rem;
}

.meta-grid > div {
  border: 1px solid var(--border);
  border-radius: 10px;
  background: var(--surface-soft);
  padding: 0.55rem 0.65rem;
}

.label {
  display: block;
  color: var(--muted);
  font-size: 0.78rem;
  margin-bottom: 0.15rem;
}

.full-badge {
  color: #991b1b;
}

.cta {
  margin-top: auto;
  text-decoration: none;
  text-align: center;
  border-radius: 10px;
  padding: 0.65rem;
  font-weight: 700;
  color: #ffffff;
  background: linear-gradient(135deg, #0ea5e9, #06b6d4);
}
</style>