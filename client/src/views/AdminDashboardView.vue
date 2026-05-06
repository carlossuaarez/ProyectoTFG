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
            <th>
              <button type="button" class="sort-btn" @click="setSort('name')">
                Nombre <span class="sort-indicator">{{ sortIndicator('name') }}</span>
              </button>
            </th>
            <th>
              <button type="button" class="sort-btn" @click="setSort('creator')">
                Creador <span class="sort-indicator">{{ sortIndicator('creator') }}</span>
              </button>
            </th>
            <th>
              <button type="button" class="sort-btn" @click="setSort('category')">
                Categoría <span class="sort-indicator">{{ sortIndicator('category') }}</span>
              </button>
            </th>
            <th>
              <button type="button" class="sort-btn" @click="setSort('discipline')">
                Disciplina <span class="sort-indicator">{{ sortIndicator('discipline') }}</span>
              </button>
            </th>
            <th>
              <button type="button" class="sort-btn" @click="setSort('start')">
                Inicio <span class="sort-indicator">{{ sortIndicator('start') }}</span>
              </button>
            </th>
            <th>
              <button type="button" class="sort-btn" @click="setSort('location')">
                Ubicación <span class="sort-indicator">{{ sortIndicator('location') }}</span>
              </button>
            </th>
            <th>
              <button type="button" class="sort-btn" @click="setSort('visibility')">
                Visibilidad <span class="sort-indicator">{{ sortIndicator('visibility') }}</span>
              </button>
            </th>
            <th>
              <button type="button" class="sort-btn" @click="setSort('maxTeams')">
                Equipos máx. <span class="sort-indicator">{{ sortIndicator('maxTeams') }}</span>
              </button>
            </th>
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

const sortKey = ref('start')
const sortDirection = ref('asc') // asc | desc

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

function normalizeText(value) {
  return String(value || '').trim().toLocaleLowerCase('es')
}

function startTimestamp(tournament) {
  const datePart = String(tournament?.start_date || '').trim()
  if (!datePart) return 0
  const timePart = String(tournament?.start_time || '00:00:00').slice(0, 8) || '00:00:00'

  let parsed = new Date(`${datePart}T${timePart}`)
  if (Number.isNaN(parsed.getTime())) {
    parsed = new Date(`${datePart} ${timePart}`)
  }
  if (Number.isNaN(parsed.getTime())) return 0
  return parsed.getTime()
}

function locationValue(tournament) {
  if (Number(tournament?.is_online) === 1) return 'online'
  return normalizeText(`${tournament?.location_name || ''} ${tournament?.location_address || ''}`)
}

function sortValue(tournament, key) {
  switch (key) {
    case 'name':
      return normalizeText(tournament?.name)
    case 'creator':
      return normalizeText(tournament?.created_by_username || `usuario #${Number(tournament?.created_by || 0)}`)
    case 'category':
      return normalizeText(tournament?.type === 'esports' ? 'e-sports' : 'deporte')
    case 'discipline':
      return normalizeText(tournament?.game)
    case 'start':
      return startTimestamp(tournament)
    case 'location':
      return locationValue(tournament)
    case 'visibility':
      return normalizeText(tournament?.visibility || 'public')
    case 'maxTeams':
      return Number(tournament?.max_teams || 0)
    default:
      return ''
  }
}

function compareValues(a, b) {
  const aIsNumber = typeof a === 'number'
  const bIsNumber = typeof b === 'number'

  if (aIsNumber && bIsNumber) {
    return a - b
  }

  return String(a).localeCompare(String(b), 'es', { sensitivity: 'base' })
}

function setSort(key) {
  if (sortKey.value === key) {
    sortDirection.value = sortDirection.value === 'asc' ? 'desc' : 'asc'
    return
  }
  sortKey.value = key
  sortDirection.value = 'asc'
}

function sortIndicator(key) {
  if (sortKey.value !== key) return '↕'
  return sortDirection.value === 'asc' ? '↑' : '↓'
}

const filteredTournaments = computed(() => {
  const q = searchTerm.value.toLowerCase()

  const filtered = tournaments.value
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

  const sorted = [...filtered].sort((a, b) => {
    const av = sortValue(a, sortKey.value)
    const bv = sortValue(b, sortKey.value)

    const cmp = compareValues(av, bv)
    if (cmp !== 0) {
      return sortDirection.value === 'asc' ? cmp : -cmp
    }

    return Number(a.id || 0) - Number(b.id || 0)
  })

  return sorted
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

.sort-btn {
  border: none;
  background: transparent;
  padding: 0;
  margin: 0;
  font: inherit;
  color: inherit;
  font-weight: 700;
  cursor: pointer;
  display: inline-flex;
  align-items: center;
  gap: 0.35rem;
}

.sort-btn:hover {
  color: #0f172a;
}

.sort-indicator {
  color: #64748b;
  font-size: 0.78rem;
  line-height: 1;
  min-width: 1rem;
  text-align: center;
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