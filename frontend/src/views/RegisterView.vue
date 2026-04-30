<template>
  <section class="auth-wrapper">
    <article class="auth-card">
      <h1>Crear cuenta</h1>
      <p class="subtitle">Regístrate para gestionar torneos y participar con tu equipo.</p>

      <form @submit.prevent="handleRegister">
        <div class="input-group">
          <label for="fullName">Nombre y apellidos reales</label>
          <input
            id="fullName"
            v-model.trim="fullName"
            placeholder="Ej: Alejandro García López"
            minlength="5"
            maxlength="100"
            required
          />
          <small class="help">Este dato solo será visible para ti.</small>
        </div>

        <div class="input-group">
          <label for="username">Nombre de usuario (único)</label>
          <input
            id="username"
            v-model.trim="username"
            placeholder="Ej: alextourney"
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

      <div class="divider">o regístrate con</div>

      <div class="google-block">
        <div ref="googleButtonRef" class="google-button-slot"></div>
        <p v-if="googleError" class="msg error">{{ googleError }}</p>
      </div>

      <p class="switch-page">
        ¿Ya tienes cuenta? <router-link to="/login">Inicia sesión</router-link>
      </p>
    </article>
  </section>
</template>

<script setup>
import { ref, onMounted } from 'vue'
import { useRouter, useRoute } from 'vue-router'
import { useAuthStore } from '../stores/auth'

const router = useRouter()
const route = useRoute()
const authStore = useAuthStore()

const fullName = ref('')
const username = ref('')
const email = ref('')
const password = ref('')
const confirmPassword = ref('')

const loading = ref(false)
const errorMessage = ref('')
const successMessage = ref('')
const googleError = ref('')

const googleButtonRef = ref(null)
const GOOGLE_CLIENT_ID = import.meta.env.VITE_GOOGLE_CLIENT_ID || ''

function validateUsername(value) {
  return /^[a-zA-Z0-9_]{3,30}$/.test(value)
}

function validatePassword(value) {
  // mínimo 8, al menos una minúscula, una mayúscula y un número
  return /^(?=.*[a-z])(?=.*[A-Z])(?=.*\d).{8,}$/.test(value)
}

function validateRealFullName(value) {
  const normalized = value.trim().replace(/\s+/g, ' ')
  if (normalized.length < 5 || normalized.length > 100) return false
  const parts = normalized.split(' ').filter(Boolean)
  if (parts.length < 2) return false
  return /^[A-Za-zÀ-ÿ\u00f1\u00d1' -]+$/.test(normalized)
}

function resolvePostRegisterPath() {
  const redirect = route.query.redirect
  if (typeof redirect === 'string' && redirect.startsWith('/')) {
    return redirect
  }
  return '/tournaments'
}

function processAuthResult(result) {
  if (!result?.success) {
    errorMessage.value = result?.message || 'No se pudo completar la autenticación.'
    return
  }

  if (result.requires2fa) {
    successMessage.value = 'Cuenta creada. Verifica el código 2FA para completar el acceso.'
    router.push({
      path: '/login',
      query: typeof route.query.redirect === 'string' ? { redirect: route.query.redirect } : {},
    })
    return
  }

  router.push(resolvePostRegisterPath())
}

async function handleRegister() {
  loading.value = true
  errorMessage.value = ''
  successMessage.value = ''

  const cleanFullName = fullName.value.trim().replace(/\s+/g, ' ')
  const cleanUsername = username.value.trim()
  const cleanEmail = email.value.trim()

  if (!validateRealFullName(cleanFullName)) {
    errorMessage.value = 'Debes indicar nombre y apellidos reales (mínimo 2 palabras).'
    loading.value = false
    return
  }

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
    const result = await authStore.register(cleanUsername, cleanFullName, cleanEmail, password.value)
    processAuthResult(result)
  } finally {
    loading.value = false
  }
}

function waitForGoogleSdk() {
  return new Promise((resolve) => {
    let tries = 0
    const interval = setInterval(() => {
      if (window.google?.accounts?.id) {
        clearInterval(interval)
        resolve(true)
        return
      }

      tries += 1
      if (tries >= 30) {
        clearInterval(interval)
        resolve(false)
      }
    }, 200)
  })
}

async function handleGoogleCredential(response) {
  if (!response?.credential) {
    errorMessage.value = 'No se recibió token de Google.'
    return
  }

  loading.value = true
  errorMessage.value = ''
  successMessage.value = ''

  try {
    const result = await authStore.registerWithGoogle(response.credential)
    processAuthResult(result)
  } finally {
    loading.value = false
  }
}

async function initGoogleButton() {
  if (!GOOGLE_CLIENT_ID) {
    googleError.value = 'Registro con Google no configurado. Falta VITE_GOOGLE_CLIENT_ID.'
    return
  }

  if (!googleButtonRef.value) return

  const sdkLoaded = await waitForGoogleSdk()
  if (!sdkLoaded) {
    googleError.value = 'No se pudo cargar Google Sign-In.'
    return
  }

  window.google.accounts.id.initialize({
    client_id: GOOGLE_CLIENT_ID,
    callback: handleGoogleCredential,
  })

  googleButtonRef.value.innerHTML = ''
  window.google.accounts.id.renderButton(googleButtonRef.value, {
    theme: 'outline',
    size: 'large',
    text: 'signup_with',
    shape: 'pill',
    width: 360,
  })
}

onMounted(() => {
  initGoogleButton()
})
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

.divider {
  margin: 0.85rem 0 0.7rem;
  text-align: center;
  color: #64748b;
  font-size: 0.9rem;
}

.google-button-slot {
  display: flex;
  justify-content: center;
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