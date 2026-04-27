<template>
  <div class="admin-container">
    <h2>Panel de Administración</h2>
    <div class="table-wrapper">
      <table v-if="tournaments.length">
        <thead>
          <tr>
            <th>Nombre</th>
            <th>Juego</th>
            <th>Tipo</th>
            <th>Inicio</th>
            <th>Acción</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="t in tournaments" :key="t.id">
            <td>{{ t.name }}</td>
            <td>{{ t.game }}</td>
            <td><span class="badge" :class="t.type">{{ t.type }}</span></td>
            <td>{{ new Date(t.start_date).toLocaleDateString() }}</td>
            <td><button @click="deleteTournament(t.id)" class="delete-btn">🗑️</button></td>
          </tr>
        </tbody>
      </table>
      <p v-else class="empty">No hay torneos</p>
    </div>
  </div>
</template>

<script setup>
import { ref, onMounted } from 'vue'
import api from '../services/api'

const tournaments = ref([])

onMounted(async () => {
  try {
    const res = await api.get('/admin/tournaments')
    tournaments.value = res.data
  } catch (err) {
    console.error(err)
  }
})

async function deleteTournament(id) {
  if (confirm('¿Eliminar torneo?')) {
    await api.delete(`/admin/tournaments/${id}`)
    tournaments.value = tournaments.value.filter(t => t.id !== id)
  }
}
</script>

<style scoped>
.admin-container {
  max-width: 900px;
  margin: 0 auto;
}
h2 {
  margin-bottom: 1.5rem;
}
.table-wrapper {
  background: white;
  border-radius: 12px;
  box-shadow: 0 4px 12px rgba(0,0,0,0.08);
  overflow-x: auto;
}
table {
  width: 100%;
  border-collapse: collapse;
}
th, td {
  padding: 1rem;
  text-align: left;
  border-bottom: 1px solid #eee;
}
th {
  background: #f8f9fa;
  font-weight: 600;
}
.badge {
  padding: 0.2rem 0.6rem;
  border-radius: 12px;
  font-size: 0.8rem;
}
.badge.sports { background: #e8f5e9; color: #2e7d32; }
.badge.esports { background: #e3f2fd; color: #1565c0; }
.delete-btn {
  background: none;
  border: none;
  font-size: 1.2rem;
  cursor: pointer;
}
</style>