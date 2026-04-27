<template>
  <div class="tournament-card">
    <div class="card-header">
      <span class="type-badge" :class="tournament.type">{{ tournament.type === 'esports' ? '🎮 e-Sports' : '⚽ Deporte' }}</span>
    </div>
    <h3>{{ tournament.name }}</h3>
    <p class="game">{{ tournament.game }}</p>
    <div class="details">
      <span>{{ tournament.format === 'single_elim' ? 'Eliminatoria' : 'Liga' }}</span>
      <span>Máx {{ tournament.max_teams }} equipos</span>
    </div>
    <p class="date">Inicio: {{ new Date(tournament.start_date).toLocaleDateString() }}</p>
    <router-link :to="'/tournaments/' + tournament.id" class="view-btn">Ver torneo</router-link>
  </div>
</template>

<script setup>
defineProps(['tournament'])
</script>

<style scoped>
.tournament-card {
  background: white;
  border-radius: 12px;
  padding: 1.5rem;
  box-shadow: 0 4px 12px rgba(0,0,0,0.08);
  transition: transform 0.2s, box-shadow 0.2s;
  display: flex;
  flex-direction: column;
}
.tournament-card:hover {
  transform: translateY(-5px);
  box-shadow: 0 8px 24px rgba(0,0,0,0.15);
}
.card-header {
  margin-bottom: 0.8rem;
}
.type-badge {
  display: inline-block;
  padding: 0.3rem 0.8rem;
  border-radius: 20px;
  font-size: 0.8rem;
  font-weight: 600;
}
.type-badge.sports {
  background: #e8f5e9;
  color: #2e7d32;
}
.type-badge.esports {
  background: #e3f2fd;
  color: #1565c0;
}
.tournament-card h3 {
  font-size: 1.3rem;
  margin-bottom: 0.5rem;
  color: #1a1a2e;
}
.game {
  color: #666;
  margin-bottom: 1rem;
}
.details {
  display: flex;
  justify-content: space-between;
  color: #777;
  font-size: 0.9rem;
  margin-bottom: 0.8rem;
}
.date {
  color: #444;
  font-size: 0.9rem;
  margin-bottom: 1rem;
}
.view-btn {
  margin-top: auto;
  text-align: center;
  padding: 0.6rem;
  background: #f0c040;
  color: #1a1a2e;
  border-radius: 8px;
  text-decoration: none;
  font-weight: 600;
  transition: background 0.2s;
}
.view-btn:hover {
  background: #e0b030;
}
</style>