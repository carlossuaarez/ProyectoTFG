import { defineStore } from 'pinia'
import { ref, computed } from 'vue'
import api from '../services/api'
import { useRouter } from 'vue-router'

function parseJwt(token) {
  try {
    const base64Url = token.split('.')[1]
    const base64 = base64Url.replace(/-/g, '+').replace(/_/g, '/')
    const jsonPayload = decodeURIComponent(
      atob(base64)
        .split('')
        .map((c) => '%' + ('00' + c.charCodeAt(0).toString(16)).slice(-2))
        .join('')
    )
    return JSON.parse(jsonPayload)
  } catch {
    return null
  }
}

export const useAuthStore = defineStore('auth', () => {
  const token = ref(localStorage.getItem('token') || null)
  const pending2fa = ref(null)
  const router = useRouter()

  const payload = computed(() => (token.value ? parseJwt(token.value) : null))
  const isAdmin = computed(() => payload.value?.role === 'admin')
  const isAuthenticated = computed(() => Boolean(token.value))

  function setToken(newToken) {
    token.value = newToken
    localStorage.setItem('token', newToken)
  }

  function clearPending2fa() {
    pending2fa.value = null
  }

  function handleAuthResponse(data) {
    if (data?.token) {
      setToken(data.token)
      clearPending2fa()
      return { success: true, requires2fa: false }
    }

    if (data?.requires_2fa && data?.challenge_id) {
      pending2fa.value = {
        challengeId: data.challenge_id,
        emailHint: data.email_hint || '',
      }
      return { success: true, requires2fa: true }
    }

    return { success: false, message: 'Respuesta de autenticación no válida.' }
  }

  async function login(email, password) {
    try {
      const res = await api.post('/login', { email, password })
      return handleAuthResponse(res.data)
    } catch (err) {
      return {
        success: false,
        message: err.response?.data?.error || 'No se pudo iniciar sesión.',
      }
    }
  }

  async function loginWithGoogle(idToken) {
    try {
      const res = await api.post('/auth/google', { id_token: idToken })
      return handleAuthResponse(res.data)
    } catch (err) {
      return {
        success: false,
        message: err.response?.data?.error || 'No se pudo iniciar sesión con Google.',
      }
    }
  }

  async function verify2fa(code) {
    if (!pending2fa.value?.challengeId) {
      return { success: false, message: 'No hay verificación pendiente.' }
    }

    const cleanCode = String(code || '').trim()
    if (!/^\d{6}$/.test(cleanCode)) {
      return { success: false, message: 'El código debe tener 6 dígitos.' }
    }

    try {
      const res = await api.post('/2fa/verify', {
        challenge_id: pending2fa.value.challengeId,
        code: cleanCode,
      })
      return handleAuthResponse(res.data)
    } catch (err) {
      return {
        success: false,
        message: err.response?.data?.error || 'Código inválido.',
      }
    }
  }

  async function register(username, email, password) {
    try {
      await api.post('/register', { username, email, password })
      alert('Registro exitoso, ahora inicia sesión')
      router.push('/login')
    } catch (err) {
      alert('Error: ' + (err.response?.data?.error || 'No se pudo registrar'))
      throw err
    }
  }

  function logout() {
    token.value = null
    clearPending2fa()
    localStorage.removeItem('token')
    router.push('/login')
  }

  return {
    token,
    pending2fa,
    payload,
    isAdmin,
    isAuthenticated,
    login,
    loginWithGoogle,
    verify2fa,
    clearPending2fa,
    register,
    logout,
  }
})