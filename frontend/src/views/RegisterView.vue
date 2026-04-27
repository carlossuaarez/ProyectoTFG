<template>
  <section class="auth-wrapper">
    <article class="auth-card">
      <h1>Crear cuenta</h1>
      <p class="subtitle">Regístrate para gestionar torneos y participar con tu equipo.</p>

      <form @submit.prevent="handleRegister">
        <div class="input-group">
          <label for="username">Nombre de usuario</label>
          <input id="username" v-model="username" placeholder="Ej: admin_malaga" required />
        </div>

        <div class="input-group">
          <label for="email">Email</label>
          <input id="email" v-model="email" type="email" placeholder="tu@email.com" required />
        </div>

        <div class="input-group">
          <label for="password">Contraseña</label>
          <input
            id="password"
            v-model="password"
            type="password"
            minlength="6"
            placeholder="Mínimo 6 caracteres"
            required
          />
        </div>

        <p v-if="errorMessage" class="msg error">{{ errorMessage }}</p>

        <button type="submit" class="submit-btn" :disabled="loading">
          {{ loading ? 'Registrando...' : 'Registrarse' }}
        </button>
      </form>

      <p class="switch-page">
        ¿Ya tienes cuenta? <router-link to="/login">Inicia sesión</router-link>
      </p>
    </article>
  </section>
</template>

<script setup>
import { ref } from 'vue'
import { useAuthStore } from '../stores/auth'

const username = ref('')
const email = ref('')
const password = ref('')

const loading = ref(false)
const errorMessage = ref('')

const authStore = useAuthStore()

async function handleRegister() {
  loading.value = true
  errorMessage.value = ''

  if (password.value.length < 6) {
    errorMessage.value = 'La contraseña debe tener al menos 6 caracteres.'
    loading.value = false
    return
  }

  try {
    await authStore.register(username.value, email.value, password.value)
  } catch {
    errorMessage.value = 'No se pudo completar el registro.'
  } finally {
    loading.value = false
  }
}
</script>

<style scoped>
.auth-wrapper {
  min-height: 65vh;
  display: flex;
  justify-content: center;
  align-items: center;
}

.auth-card {
  width: min(430px, 100%);
  background: var(--surface);
  border: 1px solid var(--border);
  border-radius: var(--radius);
  box-shadow: var(--shadow-md);
  padding: 1.1rem;
}

h1 {
  margin-bottom: 0.2rem;
  font-size: 1.65rem;
}

.subtitle {
  color: var(--muted);
  margin-bottom: 0.85rem;
}

.input-group {
  display: grid;
  gap: 0.35rem;
  margin-bottom: 0.75rem;
}

label {
  font-size: 0.9rem;
  font-weight: 700;
}

input {
  border: 1px solid var(--border);
  border-radius: 10px;
  padding: 0.62rem 0.72rem;
}

input:focus {
  outline: 2px solid rgba(6, 182, 212, 0.25);
  border-color: #06b6d4;
}

.submit-btn {
  width: 100%;
  border: none;
  border-radius: 10px;
  padding: 0.72rem 0.9rem;
  font-weight: 700;
  cursor: pointer;
  color: #fff;
  background: linear-gradient(135deg, #0ea5e9, #06b6d4);
}

.submit-btn:disabled {
  opacity: 0.72;
  cursor: not-allowed;
}

.switch-page {
  margin-top: 0.85rem;
  text-align: center;
  color: #475569;
}

.switch-page a {
  color: #0284c7;
  text-decoration: none;
  font-weight: 700;
}

.msg.error {
  color: #b91c1c;
  margin: 0.2rem 0 0.7rem;
}
</style>