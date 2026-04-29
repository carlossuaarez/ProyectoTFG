<template>
  <section class="admin-page">
    <header class="admin-head">
      <h1>Panel de Administración</h1>
      <p>Gestiona torneos, revisa su estado y elimina eventos cuando sea necesario.</p>
    </header>

    <div class="filters-panel">
      <div class="field">
        <label for="search">Buscar</label>
        <input id="search" v-model.trim="searchTerm" placeholder="Nombre, disciplina, lugar..." />
      </div>

      <div class="field">
        <label for="typeFilter">Categoría</label>
        <select id="typeFilter" v-model="typeFilter">
          <option value="all">Todas</option>
          <option value="sports">Deporte</option>
          <option value="esports">e-Sports</option>
        </select>
      </div>

      <div class="field">
        <label for="visibilityFilter">Visibilidad</label>
        <select id="visibilityFilter" v-model="visibilityFilter">
          <option value="all">Todas</option>
          <option value="public">Público</option>
          <option value="private">Privado</option>
        </select>
      </div>

      <div class="stats-box">
        <strong>{{ filteredTournaments.length }}</strong>
        <span>resultados</span>
      </div>
    </div>

    <div v-if="loading" class="state-box">Cargando torneos...</div>

    <div v-else-if="error" class="state-box state-error">
      <p>{{ error }}</p>
      <button type="button" @click="fetchAdminTournaments">Reintentar</button>
    </div>

    <div v-else class="table-wrapper">
      <table v-if="filteredTournaments.length > 0">
        <thead>
          <tr>
            <th>Nombre</th>
            <th>Creador</th>
            <th>Categoría</th>
            <th>Disciplina</th>
            <th>Inicio</th>
            <th>Ubicación</th>
            <th>Visibilidad</th>
            <th>Equipos máx.</th>
            <th>Acción</th>
          </tr>
        </thead>

        <tbody>
          <tr v-for="t in filteredTournaments" :key="t.id">
            <td>
              <div class="name-cell">
                <strong>{{ t.name }}</strong>
                <small>{{ shortDescription(t.description) }}</small>
              </div>
            </td>

            <td>
              <span class="creator-cell">{{ creatorLabel(t) }}</span>
            </td>

            <td>
              <span class="badge type" :class="t.type">
                {{ t.type === 'esports' ? 'e-Sports' : 'Deporte' }}
              </span>
            </td>

            <td>{{ t.game || '-' }}</td>
            <td>{{ formatDateTime(t.start_date, t.start_time) }}</td>

            <td>
              <div class="location-cell">
                <span>{{ Number(t.is_online) === 1 ? 'Online' : (t.location_name || 'Pendiente') }}</span>
                <small v-if="Number(t.is_online) !== 1 && t.location_address">{{ t.location_address }}</small>
              </div>
            </td>

            <td>
              <span class="badge visibility" :class="t.visibility">
                {{ t.visibility === 'private' ? 'Privado' : 'Público' }}
              </span>
              <small v-if="t.visibility === 'private' && t.access_code_last4" class="code-last4">
                ****{{ t.access_code_last4 }}
              </small>
            </td>

            <td>
              <span v-if="Number(t.is_full) === 1" class="full-badge">COMPLETO</span>
              <span v-else>{{ Number(t.teams_count || 0) }} / {{ t.max_teams }}</span>
            </td>

            <td>
              <div class="actions-cell">
                <button class="view-btn" type="button" @click="viewTournament(t)">Ver</button>
                <button class="delete-btn" type="button" @click="deleteTournament(t.id)">Eliminar</button>
              </div>
            </td>
          </tr>
        </tbody>
      </table>

      <div v-else class="empty">
        <p>No hay torneos que coincidan con los filtros.</p>
      </div>
    </div>
  </section>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue'
import { useRouter } from 'vue-router'
import api from '../services/api'

const router = useRouter()

const tournaments = ref([])
const loading = ref(true)
const error = ref('')

const searchTerm = ref('')
const typeFilter = ref('all')
const visibilityFilter = ref('all')

async function fetchAdminTournaments() {
  loading.value = true
  error.value = ''
  try {
    const res = await api.get('/admin/tournaments')
    tournaments.value = Array.isArray(res.data) ? res.data : []
  } catch (e) {
    error.value = e.response?.data?.error || 'No se pudo cargar el panel de administración.'
  } finally {
    loading.value = false
  }
}

onMounted(fetchAdminTournaments)

const filteredTournaments = computed(() => {
  const q = searchTerm.value.toLowerCase()

  return tournaments.value
    .map((t) => ({ ...t, visibility: t.visibility || 'public' }))
    .filter((t) => (typeFilter.value === 'all' ? true : t.type === typeFilter.value))
    .filter((t) => (visibilityFilter.value === 'all' ? true : t.visibility === visibilityFilter.value))
    .filter((t) => {
      if (!q) return true
      const haystack = [t.name, t.game, t.description, t.location_name, t.location_address, t.created_by_username]
        .map((v) => String(v || '').toLowerCase())
        .join(' ')
      return haystack.includes(q)
    })
    .sort((a, b) => {
      const da = `${a.start_date || ''} ${String(a.start_time || '00:00:00').slice(0, 8)}`
      const db = `${b.start_date || ''} ${String(b.start_time || '00:00:00').slice(0, 8)}`
      return new Date(da) - new Date(db)
    })
})

function shortDescription(value) {
  const text = String(value || '').trim()
  if (!text) return 'Sin descripción'
  if (text.length <= 70) return text
  return text.slice(0, 70) + '...'
}

function formatDateTime(date, time) {
  if (!date) return '-'
  const parsed = new Date(date)
  if (Number.isNaN(parsed.getTime())) return '-'
  const datePart = parsed.toLocaleDateString('es-ES')
  const hhmm = String(time || '00:00:00').slice(0, 5)
  return `${datePart} · ${hhmm}`
}

function creatorLabel(tournament) {
  const username = String(tournament?.created_by_username || '').trim()
  if (username) return `@${username}`

  const id = Number(tournament?.created_by || 0)
  return id > 0 ? `Usuario #${id}` : 'Desconocido'
}

function viewTournament(tournament) {
  const isPrivate = (tournament.visibility || 'public') === 'private'

  if (isPrivate) {
    router.push({
      path: `/tournaments/${tournament.id}`,
      query: { admin_preview: '1' },
    })
    return
  }

  router.push(`/tournaments/${tournament.id}`)
}

async function deleteTournament(id) {
  const ok = window.confirm('¿Seguro que quieres eliminar este torneo? Esta acción no se puede deshacer.')
  if (!ok) return

  try {
    await api.delete(`/admin/tournaments/${id}`)
    tournaments.value = tournaments.value.filter((t) => t.id !== id)
  } catch (e) {
    window.alert(e.response?.data?.error || 'No se pudo eliminar el torneo.')
  }
}
</script>

<style scoped>
.admin-head { margin-bottom: 0.9rem; }
.admin-head h1 { margin-bottom: 0.2rem; }
.admin-head p { color: #64748b; }

.filters-panel {
  background: #fff;
  border: 1px solid #e2e8f0;
  border-radius: 14px;
  padding: 0.8rem;
  margin-bottom: 0.9rem;
  display: grid;
  grid-template-columns: 1fr 180px 180px 120px;
  gap: 0.7rem;
  align-items: end;
}
.field { display: grid; gap: 0.35rem; }
.field label { font-size: 0.82rem; color: #64748b; font-weight: 700; }
.field input, .field select {
  border: 1px solid #dbe1ea;
  border-radius: 10px;
  padding: 0.58rem 0.68rem;
  background: #fff;
}

.stats-box {
  border: 1px solid #dbe1ea;
  border-radius: 10px;
  background: #f8fafc;
  text-align: center;
  padding: 0.5rem;
  display: grid;
}
.stats-box strong { font-size: 1.1rem; }
.stats-box span { font-size: 0.82rem; color: #64748b; }

.table-wrapper {
  background: #fff;
  border: 1px solid #e2e8f0;
  border-radius: 14px;
  overflow: auto;
}
table { width: 100%; border-collapse: collapse; min-width: 1240px; }
thead th {
  text-align: left;
  background: #f8fafc;
  padding: 0.75rem;
  border-bottom: 1px solid #e2e8f0;
  font-size: 0.88rem;
  white-space: nowrap;
}

tbody td {
  padding: 0.75rem;
  border-bottom: 1px solid #eef2f7;
  vertical-align: top;
}

.name-cell { display: grid; gap: 0.2rem; }
.name-cell small { color: #64748b; font-size: 0.82rem; }

.creator-cell {
  font-weight: 700;
  color: #0f172a;
  font-size: 0.9rem;
}

.location-cell { display: grid; gap: 0.2rem; }
.location-cell small { color: #64748b; font-size: 0.8rem; }

.badge {
  border-radius: 999px;
  padding: 0.25rem 0.55rem;
  font-size: 0.78rem;
  font-weight: 700;
  display: inline-block;
}
.badge.type.sports { background: #dcfce7; color: #166534; }
.badge.type.esports { background: #dbeafe; color: #1d4ed8; }
.badge.visibility.public { background: #e2e8f0; color: #334155; }
.badge.visibility.private { background: #fee2e2; color: #991b1b; }

.code-last4 {
  display: block;
  margin-top: 0.2rem;
  color: #7f1d1d;
  font-size: 0.78rem;
}

.full-badge {
  display: inline-block;
  border: 1px solid #bbf7d0;
  background: #dcfce7;
  color: #166534;
  border-radius: 999px;
  padding: 0.18rem 0.55rem;
  font-size: 0.78rem;
  font-weight: 800;
}

.actions-cell { display: flex; gap: 0.45rem; align-items: center; }

.view-btn {
  border: 1px solid #bae6fd;
  background: #eff6ff;
  color: #1d4ed8;
  padding: 0.35rem 0.58rem;
  border-radius: 8px;
  font-weight: 700;
  font-size: 0.86rem;
  cursor: pointer;
}

.delete-btn {
  border: 1px solid #fecaca;
  background: #fff1f2;
  color: #b91c1c;
  padding: 0.35rem 0.58rem;
  border-radius: 8px;
  font-weight: 700;
  cursor: pointer;
  font-size: 0.86rem;
}

.state-box {
  border: 1px dashed #cbd5e1;
  border-radius: 14px;
  padding: 1rem;
  text-align: center;
  color: #64748b;
  background: #fff;
}

.state-error {
  border-color: #fecaca;
  color: #991b1b;
  background: #fff1f2;
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
.empty { text-align: center; padding: 1.2rem; color: #64748b; }

@media (max-width: 900px) {
  .filters-panel { grid-template-columns: 1fr; }
  .stats-box {
    text-align: left;
    display: flex;
    gap: 0.5rem;
    align-items: baseline;
    justify-content: flex-start;
  }
}
</style>