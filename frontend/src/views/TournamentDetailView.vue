<template>
  <div v-if="tournament">
    <h2>{{ tournament.name }}</h2>
    <p>Juego: {{ tournament.game }} | Tipo: {{ tournament.type }}</p>
    <p>Formato: {{ tournament.format }} | Plazas: {{ tournament.max_teams }}</p>
    <p>Premio: {{ tournament.prize || 'Sin premio' }}</p>
    <p>Inicio: {{ tournament.start_date }}</p>

    <h3>Equipos inscritos ({{ teams.length }}/{{ tournament.max_teams }})</h3>
    <ul>
      <li v-for="team in teams" :key="team.id">{{ team.name }}</li>
    </ul>

    <div v-if="token && !alreadyJoined">
      <input v-model="teamName" placeholder="Nombre del equipo" />
      <button @click="joinTournament">Inscribir equipo</button>
    </div>
    <p v-else-if="!token">Inicia sesión para inscribirte.</p>
    <p v-else>Ya has inscrito un equipo en este torneo.</p>
  </div>
  <div v-else>Cargando...</div>
</template>

<script setup>
import { ref, onMounted, computed } from 'vue'
import { useRoute } from 'vue-router'
import api from '../services/api'
import { useAuthStore } from '../stores/auth'
import { storeToRefs } from 'pinia'

const route = useRoute()
const tournament = ref(null)
const teams = ref([])
const teamName = ref('')
const authStore = useAuthStore()
const { token } = storeToRefs(authStore)

const alreadyJoined = computed(() => {
  // Muy básico: comprobar si el capitán está en la lista (necesitarías más lógica en producción)
  return false
})

onMounted(async () => {
  try {
    const res = await api.get(`/tournaments/${route.params.id}`)
    tournament.value = res.data
    teams.value = res.data.teams || []
  } catch (err) {
    console.error(err)
  }
})

async function joinTournament() {
  if (!teamName.value) return alert('Escribe un nombre de equipo')
  try {
    await api.post(`/tournaments/${route.params.id}/join`, { team_name: teamName.value })
    alert('Equipo inscrito correctamente')
    const res = await api.get(`/tournaments/${route.params.id}`)
    teams.value = res.data.teams
    teamName.value = ''
  } catch (err) {
    alert(err.response?.data?.error || 'Error al inscribir')
  }
}
</script>