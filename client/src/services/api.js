import axios from 'axios'
import { useUiStore } from '../stores/ui'

const api = axios.create({
  baseURL: import.meta.env.VITE_API_BASE_URL || 'http://localhost:8080/api',
  timeout: 10000,
})

api.interceptors.request.use((config) => {
  const token = localStorage.getItem('token')
  if (token) {
    config.headers.Authorization = `Bearer ${token}`
  }
  return config
})

api.interceptors.response.use(
  (response) => response,
  (error) => {
    let ui = null
    try {
      ui = useUiStore()
    } catch {
      ui = null
    }
    if (error.response?.status === 401) {
      // Evita desincronización store/localStorage
      localStorage.removeItem('token')

      // Forzar recarga a login para resetear estado reactivo
      const currentPath = window.location.pathname
      if (currentPath !== '/login') {
        const redirect = encodeURIComponent(`${window.location.pathname}${window.location.search}`)
        window.location.assign(`/login?redirect=${redirect}`)
      }
    } else if (error.response?.status >= 500 && ui) {
      ui.toastError('Error interno del servidor. Inténtalo de nuevo.')
    }

    return Promise.reject(error)
  }
)

export default api