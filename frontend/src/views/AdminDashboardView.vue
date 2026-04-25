<template>
  <div>
    <h2>Panel de Administración</h2>
    <table v-if="tournaments.length">
      <thead>
        <tr>
          <th>Nombre</th>
          <th>Juego</th>
          <th>Tipo</th>
          <th>Inicio</th>
          <th>Acciones</th>
        </tr>
      </thead>
      <tbody>
        <tr v-for="t in tournaments" :key="t.id">
          <td>{{ t.name }}</td>
          <td>{{ t.game }}</td>
          <td>{{ t.type }}</td>
          <td>{{ t.start_date }}</td>
          <td><button @click="deleteTournament(t.id)">Eliminar</button></td>
        </tr>
      </tbody>
    </table>
    <p v-else>No hay torneos</p>
  </div>
</template>

<script setup>
import { ref, onMounted } from 'vue'
import api from '../services/api'

const tournaments = ref([])

onMounted(async () => {
  try {
    const res = await api.get('/admin/tournaments')  // necesita token admin
    tournaments.value = res.data
  } catch (err) {
    console.error('Acceso denegado o error', err)
  }
})

async function deleteTournament(id) {
  if (confirm('¿Eliminar torneo?')) {
    try {
      await api.delete(`/admin/tournaments/${id}`)
      tournaments.value = tournaments.value.filter(t => t.id !== id)
    } catch (err) {
      alert('No se pudo eliminar')
    }
  }
}
</script>