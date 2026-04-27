<template>
  <section class="admin-page">
    <header class="admin-head">
      <h1>Panel de Administración</h1>
      <p>Gestiona torneos publicados y mantén la plataforma ordenada.</p>
    </header>

    <div v-if="loading" class="state-box">Cargando torneos...</div>

    <div v-else-if="error" class="state-box state-error">
      <p>{{ error }}</p>
      <button type="button" @click="fetchAdminTournaments">Reintentar</button>
    </div>

    <div v-else class="table-wrapper">
      <table v-if="tournaments.length > 0">
        <thead>
          <tr>
            <th>Nombre</th>
            <th>Juego/Deporte</th>
            <th>Tipo</th>
            <th>Inicio</th>
            <th>Acción</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="t in tournaments" :key="t.id">
            <td>{{ t.name }}</td>
            <td>{{ t.game }}</td>
            <td>
              <span class="badge" :class="t.type">{{ t.type === 'esports' ? 'e-Sports' : 'Deporte' }}</span>
            </td>
            <td>{{ formatDate(t.start_date) }}</td>
            <td>
              <button class="delete-btn" type="button" @click="deleteTournament(t.id)">
                Eliminar
              </button>
            </td>
          </tr>
        </tbody>
      </table>

      <div v-else class="empty">
        <p>No hay torneos en el panel de administración.</p>
        <router-link to="/create-tournament">Crear un torneo</router-link>
      </div>
    </div>
  </section>
</template>

<script setup>
import { ref, onMounted } from 'vue'
import api from '../services/api'

const tournaments = ref([])
const loading = ref(true)
const error = ref('')

async function fetchAdminTournaments() {
  loading.value = true
  error.value = ''
  try {
    const res = await api.get('/admin/tournaments')
    tournaments.value = res.data
  } catch {
    error.value = 'No se pudo cargar el panel de administración.'
  } finally {
    loading.value = false
  }
}

onMounted(fetchAdminTournaments)

async function deleteTournament(id) {
  const confirmed = window.confirm('¿Seguro que quieres eliminar este torneo? Esta acción no se puede deshacer.')
  if (!confirmed) return

  try {
    await api.delete(`/admin/tournaments/${id}`)
    tournaments.value = tournaments.value.filter((t) => t.id !== id)
  } catch {
    window.alert('No se pudo eliminar el torneo.')
  }
}

function formatDate(date) {
  return new Date(date).toLocaleDateString('es-ES')
}
</script>

<style scoped>
.admin-head {
  margin-bottom: 0.9rem;
}

.admin-head h1 {
  font-size: clamp(1.4rem, 2.4vw, 1.95rem);
  margin-bottom: 0.2rem;
}

.admin-head p {
  color: var(--muted);
}

.table-wrapper {
  background: var(--surface);
  border: 1px solid var(--border);
  border-radius: var(--radius);
  box-shadow: var(--shadow-sm);
  overflow: hidden;
}

table {
  width: 100%;
  border-collapse: collapse;
}

thead th {
  text-align: left;
  background: #f8fafc;
  padding: 0.75rem;
  font-size: 0.88rem;
  border-bottom: 1px solid var(--border);
}

tbody td {
  padding: 0.75rem;
  border-bottom: 1px solid #eef2f7;
  font-size: 0.92rem;
}

tbody tr:hover {
  background: #f8fafc;
}

.badge {
  border-radius: 999px;
  padding: 0.25rem 0.55rem;
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

.delete-btn {
  border: 1px solid #fecaca;
  background: #fff1f2;
  color: #b91c1c;
  padding: 0.35rem 0.58rem;
  border-radius: 8px;
  font-weight: 700;
  cursor: pointer;
}

.state-box {
  border: 1px dashed #cbd5e1;
  border-radius: var(--radius);
  padding: 1rem;
  text-align: center;
  color: var(--muted);
  background: var(--surface);
}

.state-error {
  border-color: #fecaca;
  color: #991b1b;
  background: #fff1f2;
}

.state-error button {
  margin-top: 0.55rem;
  border: 1px solid #ef4444;
  background: #fff;
  color: #b91c1c;
  border-radius: 10px;
  padding: 0.42rem 0.72rem;
  cursor: pointer;
}

.empty {
  text-align: center;
  padding: 1.2rem;
}

.empty a {
  color: #0284c7;
  text-decoration: none;
  font-weight: 700;
}

@media (max-width: 768px) {
  .table-wrapper {
    overflow-x: auto;
  }

  table {
    min-width: 720px;
  }
}
</style>