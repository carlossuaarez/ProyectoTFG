import { defineStore } from 'pinia'
import { ref } from 'vue'
import api from '../services/api'

export const useTournamentStore = defineStore('tournaments', () => {
  const tournaments = ref([])
  const loading = ref(false)
  const error = ref('')

  async function fetchTournaments() {
    loading.value = true
    error.value = ''
    try {
      const res = await api.get('/tournaments')
      tournaments.value = Array.isArray(res.data) ? res.data : []
    } catch (err) {
      error.value = err.response?.data?.error || 'No se pudieron cargar los torneos'
    } finally {
      loading.value = false
    }
  }

  return { tournaments, loading, error, fetchTournaments }
})