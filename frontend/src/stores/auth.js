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
        .map(c => '%' + ('00' + c.charCodeAt(0).toString(16)).slice(-2))
        .join('')
    )
    return JSON.parse(jsonPayload)
  } catch {
    return null
  }
}

export const useAuthStore = defineStore('auth', () => {
  const token = ref(localStorage.getItem('token') || null)
  const router = useRouter()

  const payload = computed(() => token.value ? parseJwt(token.value) : null)
  const isAdmin = computed(() => payload.value?.role === 'admin')

  async function login(email, password) {
    try {
      const res = await api.post('/login', { email, password })
      token.value = res.data.token
      localStorage.setItem('token', token.value)
      return true
    } catch (err) {
      alert('Credenciales incorrectas')
      return false
    }
  }

  async function register(username, email, password) {
    try {
      await api.post('/register', { username, email, password })
      alert('Registro exitoso, ahora inicia sesión')
      router.push('/login')
    } catch (err) {
      alert('Error: ' + (err.response?.data?.error || 'No se pudo registrar'))
    }
  }

  function logout() {
    token.value = null
    localStorage.removeItem('token')
    router.push('/login')
  }

  return { token, login, register, logout, payload, isAdmin }
})