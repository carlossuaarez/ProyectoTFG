<template>
  <section class="page">
    <header class="page-header">
      <div>
        <h1>Torneos disponibles</h1>
        <p>Busca y filtra competiciones deportivas y de e-sports.</p>
      </div>

      <router-link v-if="token" to="/create-tournament" class="create-btn">
        + Crear torneo
      </router-link>
    </header>

    <div class="filters-panel">
      <div class="field">
        <label for="search">Buscar</label>
        <input id="search" v-model="searchTerm" placeholder="Nombre o juego/deporte" />
      </div>

      <div class="field">
        <label for="type">Tipo</label>
        <select id="type" v-model="filterType">
          <option value="all">Todos</option>
          <option value="sports">Deportes</option>
          <option value="esports">e-Sports</option>
        </select>
      </div>

      <div class="field stats">
        <span class="count">{{ filteredTournaments.length }}</span>
        <small>resultados</small>
      </div>
    </div>

    <div v-if="loading" class="state-box">Cargando torneos...</div>

    <div v-else-if="error" class="state-box state-error">
      <p>{{ error }}</p>
      <button type="button" @click="fetchTournaments">Reintentar</button>
    </div>

    <div v-else-if="filteredTournaments.length === 0" class="state-box">
      <p>No hay torneos con esos filtros.</p>
      <router-link to="/create-tournament">Crear el primero</router-link>
    </div>

    <div v-else class="grid-container">
      <TournamentCard v-for="t in filteredTournaments" :key="t.id" :tournament="t" />
    </div>
  </section>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue'
import { useAuthStore } from '../stores/auth'
import { storeToRefs } from 'pinia'
import api from '../services/api'
import TournamentCard from '../components/TournamentCard.vue'

const tournaments = ref([])
const filterType = ref('all')
const searchTerm = ref('')
const loading = ref(true)
const error = ref('')

const authStore = useAuthStore()
const { token } = storeToRefs(authStore)

function normalize(value) {
  return String(value || '').toLowerCase().trim()
}

async function fetchTournaments() {
  loading.value = true
  error.value = ''
  try {
    const res = await api.get('/tournaments')
    tournaments.value = res.data
  } catch {
    error.value = 'No se pudieron cargar los torneos. Comprueba el servidor API.'
  } finally {
    loading.value = false
  }
}

onMounted(fetchTournaments)

const filteredTournaments = computed(() => {
  const q = normalize(searchTerm.value)

  return tournaments.value
    .filter((t) => (filterType.value === 'all' ? true : t.type === filterType.value))
    .filter((t) => {
      if (!q) return true
      return normalize(t.name).includes(q) || normalize(t.game).includes(q)
    })
    .sort((a, b) => new Date(a.start_date) - new Date(b.start_date))
})
</script>

<style scoped>
.page-header {
  display: flex;
  justify-content: space-between;
  align-items: flex-end;
  gap: 1rem;
  margin-bottom: 1rem;
  flex-wrap: wrap;
}

.page-header h1 {
  font-size: clamp(1.4rem, 2.5vw, 2rem);
  margin-bottom: 0.2rem;
}

.page-header p {
  color: var(--muted);
}

.create-btn {
  text-decoration: none;
  border-radius: 10px;
  padding: 0.65rem 0.9rem;
  font-weight: 700;
  background: linear-gradient(135deg, #0ea5e9, #06b6d4);
  color: #fff;
}

.filters-panel {
  background: var(--surface);
  border: 1px solid var(--border);
  border-radius: var(--radius);
  box-shadow: var(--shadow-sm);
  padding: 0.8rem;
  display: grid;
  grid-template-columns: 1fr 220px 110px;
  gap: 0.7rem;
  margin-bottom: 1rem;
}

.field {
  display: grid;
  gap: 0.35rem;
}

.field label {
  font-size: 0.82rem;
  color: var(--muted);
  font-weight: 700;
}

.field input,
.field select {
  border: 1px solid var(--border);
  border-radius: 10px;
  padding: 0.62rem 0.72rem;
  background: #fff;
  color: var(--text);
}

.stats {
  display: flex;
  align-items: center;
  justify-content: center;
  background: #f8fafc;
  border: 1px solid var(--border);
  border-radius: 10px;
  text-align: center;
}

.count {
  font-size: 1.1rem;
  font-weight: 700;
  margin-right: 0.3rem;
}

.state-box {
  background: var(--surface);
  border: 1px dashed #cbd5e1;
  border-radius: var(--radius);
  padding: 1.2rem;
  text-align: center;
  color: var(--muted);
}

.state-box a {
  color: #0284c7;
  font-weight: 700;
  text-decoration: none;
}

.state-error {
  border: 1px solid #fecaca;
  background: #fff1f2;
  color: #991b1b;
}

.state-error button {
  margin-top: 0.7rem;
  border: 1px solid #ef4444;
  color: #b91c1c;
  background: #fff;
  border-radius: 10px;
  padding: 0.45rem 0.8rem;
  cursor: pointer;
  font-weight: 600;
}

.grid-container {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(270px, 1fr));
  gap: 0.9rem;
}

@media (max-width: 900px) {
  .filters-panel {
    grid-template-columns: 1fr 1fr;
  }

  .stats {
    grid-column: 1 / -1;
    justify-content: flex-start;
    padding: 0.55rem 0.7rem;
  }
}

@media (max-width: 650px) {
  .filters-panel {
    grid-template-columns: 1fr;
  }
}
</style>