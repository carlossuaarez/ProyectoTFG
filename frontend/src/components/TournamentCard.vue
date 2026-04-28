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
    <p class="description">{{ tournament.description || 'Sin descripción' }}</p>

    <div class="meta-grid">
      <div>
        <span class="label">Inicio</span>
        <strong>{{ formatDateTime(tournament.start_date, tournament.start_time) }}</strong>
      </div>
      <div>
        <span class="label">Equipos</span>
        <strong>{{ tournament.max_teams }}</strong>
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
defineProps({
  tournament: {
    type: Object,
    required: true
  }
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
  min-height: 320px;
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
  margin-bottom: 0.45rem;
}

.description {
  color: #334155;
  font-size: 0.92rem;
  margin-bottom: 0.8rem;
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