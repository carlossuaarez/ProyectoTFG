<template>
  <section class="form-page">
    <article class="form-card">
      <header class="form-header">
        <h1>Crear nuevo torneo</h1>
        <p>Configura la competición y publica las inscripciones.</p>
      </header>

      <form @submit.prevent="createTournament">
        <fieldset>
          <legend>Datos básicos</legend>

          <div class="input-group">
            <label for="name">Nombre del torneo</label>
            <input id="name" v-model="name" placeholder="Ej: Copa Primavera 2026" required />
          </div>

          <div class="input-group">
            <label for="game">Juego / Deporte</label>
            <input id="game" v-model="game" placeholder="Ej: Fútbol 7, Valorant" required />
          </div>

          <div class="input-group">
            <label for="type">Tipo</label>
            <select id="type" v-model="type" required>
              <option value="">Selecciona tipo</option>
              <option value="sports">Deportes</option>
              <option value="esports">e-Sports</option>
            </select>
          </div>
        </fieldset>

        <fieldset>
          <legend>Configuración</legend>

          <div class="input-row">
            <div class="input-group">
              <label for="maxTeams">Nº máximo de equipos</label>
              <input id="maxTeams" v-model.number="max_teams" type="number" min="2" required />
            </div>

            <div class="input-group">
              <label for="format">Formato</label>
              <select id="format" v-model="format" required>
                <option value="single_elim">Eliminatoria simple</option>
                <option value="league">Liga</option>
              </select>
            </div>
          </div>

          <div class="input-group">
            <label for="startDate">Fecha de inicio</label>
            <input id="startDate" v-model="start_date" type="date" required />
          </div>
        </fieldset>

        <fieldset>
          <legend>Opcional</legend>
          <div class="input-group">
            <label for="prize">Premio</label>
            <input id="prize" v-model="prize" placeholder="Ej: 500€, trofeo + medallas..." />
          </div>
        </fieldset>

        <p v-if="errorMessage" class="msg error">{{ errorMessage }}</p>
        <p v-if="successMessage" class="msg success">{{ successMessage }}</p>

        <button type="submit" class="submit-btn" :disabled="loading">
          {{ loading ? 'Creando torneo...' : 'Crear torneo' }}
        </button>
      </form>
    </article>
  </section>
</template>

<script setup>
import { ref } from 'vue'
import { useRouter } from 'vue-router'
import api from '../services/api'

const router = useRouter()

const name = ref('')
const game = ref('')
const type = ref('')
const max_teams = ref(8)
const format = ref('single_elim')
const start_date = ref('')
const prize = ref('')

const loading = ref(false)
const errorMessage = ref('')
const successMessage = ref('')

async function createTournament() {
  loading.value = true
  errorMessage.value = ''
  successMessage.value = ''

  try {
    await api.post('/tournaments', {
      name: name.value.trim(),
      game: game.value.trim(),
      type: type.value,
      max_teams: Number(max_teams.value),
      format: format.value,
      start_date: start_date.value,
      prize: prize.value.trim()
    })

    successMessage.value = 'Torneo creado correctamente.'
    setTimeout(() => {
      router.push('/tournaments')
    }, 700)
  } catch (err) {
    errorMessage.value = err.response?.data?.error || 'Error al crear el torneo.'
  } finally {
    loading.value = false
  }
}
</script>

<style scoped>
.form-page {
  display: flex;
  justify-content: center;
}

.form-card {
  width: min(780px, 100%);
  background: var(--surface);
  border: 1px solid var(--border);
  border-radius: var(--radius);
  box-shadow: var(--shadow-md);
  padding: 1rem;
}

.form-header {
  margin-bottom: 0.85rem;
}

.form-header h1 {
  font-size: clamp(1.35rem, 2.3vw, 1.9rem);
  margin-bottom: 0.2rem;
}

.form-header p {
  color: var(--muted);
}

fieldset {
  border: 1px solid var(--border);
  border-radius: 12px;
  padding: 0.8rem;
  margin-bottom: 0.85rem;
}

legend {
  font-weight: 700;
  color: #334155;
  padding: 0 0.25rem;
}

.input-group {
  display: grid;
  gap: 0.35rem;
  margin-bottom: 0.75rem;
}

.input-group:last-child {
  margin-bottom: 0;
}

.input-row {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 0.75rem;
}

label {
  font-weight: 700;
  font-size: 0.9rem;
}

input,
select {
  border: 1px solid var(--border);
  border-radius: 10px;
  padding: 0.62rem 0.72rem;
  background: #fff;
}

input:focus,
select:focus {
  outline: 2px solid rgba(6, 182, 212, 0.25);
  border-color: #06b6d4;
}

.submit-btn {
  width: 100%;
  border: none;
  border-radius: 10px;
  padding: 0.75rem 1rem;
  font-weight: 700;
  color: #fff;
  background: linear-gradient(135deg, #0ea5e9, #06b6d4);
  cursor: pointer;
}

.submit-btn:disabled {
  opacity: 0.7;
  cursor: not-allowed;
}

.msg {
  margin: 0.3rem 0 0.75rem;
  font-weight: 600;
}

.msg.error {
  color: #b91c1c;
}

.msg.success {
  color: #166534;
}

@media (max-width: 650px) {
  .input-row {
    grid-template-columns: 1fr;
  }
}
</style>