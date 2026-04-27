<template>
  <section class="auth-wrapper">
    <article class="auth-card">
      <h1>Crear cuenta</h1>
      <p class="subtitle">Regístrate para gestionar torneos y participar con tu equipo.</p>

      <form @submit.prevent="handleRegister">
        <div class="input-group">
          <label for="username">Nombre de usuario</label>
          <input
            id="username"
            v-model.trim="username"
            placeholder="Ej: admin_malaga"
            minlength="3"
            maxlength="30"
            required
          />
          <small class="help">Entre 3 y 30 caracteres. Usa letras, números o guion bajo.</small>
        </div>

        <div class="input-group">
          <label for="email">Email</label>
          <input
            id="email"
            v-model.trim="email"
            type="email"
            placeholder="tu@email.com"
            required
          />
        </div>

        <div class="input-group">
          <label for="password">Contraseña</label>
          <input
            id="password"
            v-model="password"
            type="password"
            minlength="8"
            placeholder="Mínimo 8 caracteres"
            required
          />
          <small class="help">Debe incluir mayúscula, minúscula y número.</small>
        </div>

        <div class="input-group">
          <label for="confirmPassword">Confirmar contraseña</label>
          <input
            id="confirmPassword"
            v-model="confirmPassword"
            type="password"
            minlength="8"
            placeholder="Repite tu contraseña"
            required
          />
        </div>

        <p v-if="errorMessage" class="msg error">{{ errorMessage }}</p>
        <p v-if="successMessage" class="msg success">{{ successMessage }}</p>

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
import { useRouter, useRoute } from 'vue-router'
import api from '../services/api'

const router = useRouter()
const route = useRoute()

const username = ref('')
const email = ref('')
const password = ref('')
const confirmPassword = ref('')

const loading = ref(false)
const errorMessage = ref('')
const successMessage = ref('')

function validateUsername(value) {
  return /^[a-zA-Z0-9_]{3,30}$/.test(value)
}

function validatePassword(value) {
  // mínimo 8, al menos una minúscula, una mayúscula y un número
  return /^(?=.*[a-z])(?=.*[A-Z])(?=.*\d).{8,}$/.test(value)
}

async function handleRegister() {
  loading.value = true
  errorMessage.value = ''
  successMessage.value = ''

  const cleanUsername = username.value.trim()
  const cleanEmail = email.value.trim()

  if (!validateUsername(cleanUsername)) {
    errorMessage.value = 'El nombre de usuario no es válido.'
    loading.value = false
    return
  }

  if (!validatePassword(password.value)) {
    errorMessage.value =
      'La contraseña debe tener mínimo 8 caracteres e incluir mayúscula, minúscula y número.'
    loading.value = false
    return
  }

  if (password.value !== confirmPassword.value) {
    errorMessage.value = 'Las contraseñas no coinciden.'
    loading.value = false
    return
  }

  try {
    await api.post('/register', {
      username: cleanUsername,
      email: cleanEmail,
      password: password.value
    })

    successMessage.value = 'Registro completado. Redirigiendo a inicio de sesión...'

    const redirect = route.query.redirect
    setTimeout(() => {
      router.push({
        path: '/login',
        query: {
          ...(typeof redirect === 'string' ? { redirect } : {}),
          registered: '1'
        }
      })
    }, 900)
  } catch (err) {
    const status = err.response?.status
    if (status === 409) {
      errorMessage.value = 'Ese usuario o email ya existe.'
    } else if (status === 400) {
      errorMessage.value = err.response?.data?.error || 'Datos de registro no válidos.'
    } else {
      errorMessage.value = 'No se pudo completar el registro. Inténtalo de nuevo.'
    }
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
  width: min(460px, 100%);
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

.help {
  color: #64748b;
  font-size: 0.78rem;
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

.msg {
  margin: 0.2rem 0 0.7rem;
  font-weight: 600;
}

.msg.error {
  color: #b91c1c;
}

.msg.success {
  color: #166534;
}
</style>