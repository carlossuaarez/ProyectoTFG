<template>
  <section class="page">
    <header class="page-head">
      <h1>Notificaciones</h1>
      <button type="button" class="mark-btn" :disabled="markingAll" @click="markAll">
        {{ markingAll ? 'Marcando...' : 'Marcar todas como leídas' }}
      </button>
    </header>

    <div v-if="loading" class="state-box">Cargando notificaciones...</div>

    <div v-else-if="error" class="state-box state-error">
      <p>{{ error }}</p>
      <button type="button" @click="load">Reintentar</button>
    </div>

    <ul v-else-if="items.length > 0" class="list">
      <li v-for="n in items" :key="n.id" :class="{ unread: !n.is_read }">
        <div class="text">
          <strong>{{ n.title }}</strong>
          <p>{{ n.body }}</p>
          <small>{{ dateLabel(n.created_at) }}</small>
        </div>
        <div class="actions">
          <router-link v-if="n.link_url" :to="n.link_url" @click="markOne(n.id)">Abrir</router-link>
          <button v-else type="button" @click="markOne(n.id)">Leída</button>
        </div>
      </li>
    </ul>

    <div v-else class="state-box">No tienes notificaciones.</div>
  </section>
</template>

<script setup>
import { ref, onMounted } from 'vue'
import { fetchNotifications, markAllNotificationsRead, markNotificationRead } from '../services/notifications'
import { useUiStore } from '../stores/ui'

const ui = useUiStore()
const loading = ref(true)
const markingAll = ref(false)
const error = ref('')
const items = ref([])

async function load() {
  loading.value = true
  error.value = ''
  const result = await fetchNotifications(100)
  if (!result.success) {
    error.value = result.message
  } else {
    items.value = Array.isArray(result.data?.items) ? result.data.items : []
  }
  loading.value = false
}

async function markOne(id) {
  await markNotificationRead(id)
  items.value = items.value.map((n) => (Number(n.id) === Number(id) ? { ...n, is_read: true } : n))
}

async function markAll() {
  markingAll.value = true
  const result = await markAllNotificationsRead()
  if (!result.success) {
    ui.toastError(result.message)
  } else {
    items.value = items.value.map((n) => ({ ...n, is_read: true }))
  }
  markingAll.value = false
}

function dateLabel(value) {
  const d = new Date(value)
  if (Number.isNaN(d.getTime())) return '-'
  return `${d.toLocaleDateString('es-ES')} ${d.toLocaleTimeString('es-ES', { hour: '2-digit', minute: '2-digit' })}`
}

onMounted(load)
</script>

<style scoped>
.page-head {
  display: flex;
  justify-content: space-between;
  gap: 0.7rem;
  margin-bottom: 0.9rem;
  align-items: center;
}

.mark-btn {
  border: 1px solid #cbd5e1;
  background: #f8fafc;
  color: #334155;
  border-radius: 10px;
  padding: 0.45rem 0.7rem;
  font-weight: 700;
  cursor: pointer;
}

.mark-btn:disabled {
  opacity: 0.6;
  cursor: not-allowed;
}

.list {
  list-style: none;
  display: grid;
  gap: 0.6rem;
}

.list li {
  border: 1px solid #e2e8f0;
  background: #fff;
  border-radius: 12px;
  padding: 0.65rem 0.7rem;
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: 0.7rem;
}

.list li.unread {
  background: #eff6ff;
  border-color: #bfdbfe;
}

.text p {
  color: #334155;
  margin-top: 0.18rem;
}

.text small {
  color: #64748b;
  font-size: 0.78rem;
}

.actions {
  display: inline-flex;
  align-items: center;
  gap: 0.35rem;
}

.actions a,
.actions button {
  border: 1px solid #cbd5e1;
  background: #fff;
  color: #334155;
  border-radius: 8px;
  padding: 0.3rem 0.46rem;
  font-size: 0.78rem;
  font-weight: 700;
  text-decoration: none;
  cursor: pointer;
}

.state-box {
  background: #fff;
  border: 1px dashed #cbd5e1;
  border-radius: 14px;
  padding: 1rem;
  text-align: center;
  color: #64748b;
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
  padding: 0.42rem 0.65rem;
}
</style>