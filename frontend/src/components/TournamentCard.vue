<template>
  <article class="card">
    <div class="card-top">
      <span class="badge" :class="tournament.type">{{ formatType(tournament.type) }}</span>
      <span class="format">{{ formatTournamentFormat(tournament.format) }}</span>
    </div>

    <h3>{{ tournament.name }}</h3>
    <p class="game">{{ tournament.game }}</p>

    <div class="meta-grid">
      <div>
        <span class="label">Equipos</span>
        <strong>{{ tournament.max_teams }}</strong>
      </div>
      <div>
        <span class="label">Inicio</span>
        <strong>{{ formatDate(tournament.start_date) }}</strong>
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

function formatType(type) {
  return type === 'esports' ? '🎮 e-Sports' : '⚽ Deporte'
}

function formatTournamentFormat(format) {
  return format === 'single_elim' ? 'Eliminatoria' : 'Liga'
}

function formatDate(date) {
  return new Date(date).toLocaleDateString('es-ES')
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
  min-height: 250px;
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
.format {
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

.format {
  background: #f1f5f9;
  color: #334155;
}

h3 {
  font-size: 1.12rem;
  margin-bottom: 0.25rem;
  line-height: 1.25;
}

.game {
  color: var(--muted);
  margin-bottom: 0.95rem;
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