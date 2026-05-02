<template>
  <section class="invite-page">
    <article class="invite-card">
      <h1>Unirse a equipo por invitación</h1>
      <p>Pega o revisa tu código de invitación y solicita acceso al equipo.</p>

      <div class="input-group">
        <label for="code">Código</label>
        <input id="code" v-model.trim="code" maxlength="20" />
      </div>

      <p v-if="error" class="msg error">{{ error }}</p>
      <p v-if="success" class="msg success">{{ success }}</p>

      <button type="button" class="submit-btn" :disabled="loading" @click="acceptInvite">
        {{ loading ? 'Procesando...' : 'Aceptar invitación' }}
      </button>

      <div v-if="result" class="links-box">
        <router-link :to="`/tournaments/${result.tournament_id}/teams`">Ir a equipos del torneo</router-link>
        <router-link :to="`/tournaments/${result.tournament_id}`">Ir al detalle del torneo</router-link>
      </div>
    </article>
  </section>
</template>

<script setup>
import { ref, onMounted } from 'vue'
import { useRoute } from 'vue-router'
import api from '../services/api'

const route = useRoute()

const code = ref('')
const loading = ref(false)
const error = ref('')
const success = ref('')
const result = ref(null)

function sanitizeCode(value) {
  return String(value || '').trim().replace(/[^a-zA-Z0-9]/g, '').toUpperCase()
}

async function acceptInvite() {
  error.value = ''
  success.value = ''
  result.value = null

  const clean = sanitizeCode(code.value)
  if (!clean || clean.length < 6) {
    error.value = 'Código no válido.'
    return
  }

  loading.value = true
  try {
    const res = await api.post(`/team-invites/${clean}/accept`)
    success.value = res.data?.message || 'Solicitud enviada.'
    result.value = {
      team_id: Number(res.data?.team_id || 0),
      tournament_id: Number(res.data?.tournament_id || 0)
    }
  } catch (err) {
    error.value = err.response?.data?.error || 'No se pudo aceptar la invitación.'
  } finally {
    loading.value = false
  }
}

onMounted(() => {
  const codeFromUrl = String(route.params.code || '')
  code.value = sanitizeCode(codeFromUrl)
})
</script>

<style scoped>
.invite-page {
  display: flex;
  justify-content: center;
}

.invite-card {
  width: min(620px, 100%);
  background: var(--surface);
  border: 1px solid var(--border);
  border-radius: var(--radius);
  box-shadow: var(--shadow-sm);
  padding: 1rem;
}

.invite-card h1 {
  margin-bottom: 0.2rem;
}

.invite-card p {
  color: var(--muted);
  margin-bottom: 0.7rem;
}

.input-group {
  display: grid;
  gap: 0.35rem;
  margin-bottom: 0.75rem;
}

.input-group label {
  font-weight: 700;
}

.input-group input {
  border: 1px solid var(--border);
  border-radius: 10px;
  padding: 0.62rem 0.72rem;
  text-transform: uppercase;
}

.submit-btn {
  border: none;
  border-radius: 10px;
  padding: 0.65rem 0.82rem;
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
  margin-bottom: 0.6rem;
  font-weight: 600;
}

.msg.error {
  color: #b91c1c;
}

.msg.success {
  color: #166534;
}

.links-box {
  margin-top: 0.8rem;
  display: grid;
  gap: 0.4rem;
}

.links-box a {
  text-decoration: none;
  color: #0284c7;
  font-weight: 700;
}
</style>