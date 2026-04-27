<template>
  <section class="auth-wrapper">
    <article class="auth-card">
      <h1>Iniciar sesión</h1>

      <template v-if="step === 'credentials'">
        <p class="subtitle">Accede para crear torneos o administrar competiciones.</p>

        <form @submit.prevent="handleLogin">
          <div class="input-group">
            <label for="email">Email</label>
            <input id="email" v-model="email" type="email" placeholder="tu@email.com" required />
          </div>

          <div class="input-group">
            <label for="password">Contraseña</label>
            <input id="password" v-model="password" type="password" placeholder="••••••••" required />
          </div>

          <p v-if="errorMessage" class="msg error">{{ errorMessage }}</p>

          <button type="submit" class="submit-btn" :disabled="loading">
            {{ loading ? 'Entrando...' : 'Entrar' }}
          </button>
        </form>

        <div class="divider">o continúa con</div>

        <div class="google-block">
          <div ref="googleButtonRef" class="google-button-slot"></div>
          <p v-if="googleError" class="msg error">{{ googleError }}</p>
        </div>
      </template>

      <template v-else>
        <p class="subtitle">
          Te enviamos un código de 6 dígitos a
          <strong>{{ pending2fa?.emailHint || 'tu correo' }}</strong>.
        </p>

        <form @submit.prevent="handleVerify2fa">
          <div class="input-group">
            <label for="otp">Código de verificación</label>
            <input
              id="otp"
              v-model="otpCode"
              type="text"
              inputmode="numeric"
              maxlength="6"
              autocomplete="one-time-code"
              placeholder="123456"
              required
            />
          </div>

          <p v-if="errorMessage" class="msg error">{{ errorMessage }}</p>

          <button type="submit" class="submit-btn" :disabled="loading">
            {{ loading ? 'Verificando...' : 'Verificar y entrar' }}
          </button>
        </form>

        <button type="button" class="back-btn" @click="goBackToCredentials">
          Volver al login
        </button>
      </template>

      <p class="switch-page">
        ¿No tienes cuenta? <router-link to="/register">Regístrate</router-link>
      </p>
    </article>
  </section>
</template>

<script setup>
import { ref, onMounted } from 'vue'
import { useRouter, useRoute } from 'vue-router'
import { useAuthStore } from '../stores/auth'
import { storeToRefs } from 'pinia'

const email = ref('')
const password = ref('')
const otpCode = ref('')
const loading = ref(false)
const errorMessage = ref('')
const googleError = ref('')
const step = ref('credentials')

const googleButtonRef = ref(null)
const GOOGLE_CLIENT_ID = import.meta.env.VITE_GOOGLE_CLIENT_ID || ''

const authStore = useAuthStore()
const { pending2fa } = storeToRefs(authStore)
const router = useRouter()
const route = useRoute()

function processAuthResult(result) {
  if (result.success && result.requires2fa) {
    step.value = 'otp'
    errorMessage.value = ''
    return
  }

  if (result.success) {
    router.push(resolvePostLoginPath())
    return
  }

  errorMessage.value = result.message || 'No se pudo completar la autenticación.'
}

async function handleLogin() {
  loading.value = true
  errorMessage.value = ''

  const result = await authStore.login(email.value, password.value)
  processAuthResult(result)

  loading.value = false
}

async function handleVerify2fa() {
  loading.value = true
  errorMessage.value = ''

  const result = await authStore.verify2fa(otpCode.value)
  processAuthResult(result)

  loading.value = false
}

function goBackToCredentials() {
  step.value = 'credentials'
  otpCode.value = ''
  errorMessage.value = ''
  authStore.clearPending2fa()
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

  const result = await authStore.loginWithGoogle(response.credential)
  processAuthResult(result)

  loading.value = false
}

async function initGoogleButton() {
  if (!GOOGLE_CLIENT_ID) {
    googleError.value = 'Login con Google no configurado. Falta VITE_GOOGLE_CLIENT_ID.'
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
    text: 'signin_with',
    shape: 'pill',
    width: 360,
  })
}

function resolvePostLoginPath() {
  const redirect = route.query.redirect
  if (typeof redirect === 'string' && redirect.startsWith('/')) {
    return redirect
  }
  return '/tournaments'
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

.back-btn {
  width: 100%;
  margin-top: 0.65rem;
  border: 1px solid var(--border);
  border-radius: 10px;
  padding: 0.62rem 0.9rem;
  font-weight: 600;
  background: #f8fafc;
  cursor: pointer;
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