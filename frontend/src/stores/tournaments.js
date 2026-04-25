import { defineStore } from 'pinia'
import { ref } from 'vue'
import api from '../services/api'

export const useTournamentStore = defineStore('tournaments', () => {
  const tournaments = ref([])

  async function fetchTournaments() {
    try {
      const res = await api.get('/tournaments')
      tournaments.value = res.data
    } catch (err) {
      console.error(err)
    }
  }

  return { tournaments, fetchTournaments }
})