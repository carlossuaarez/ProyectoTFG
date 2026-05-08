import axios from 'axios'
import { useUiStore } from '../stores/ui'

export const API_BASE_URL = import.meta.env.VITE_API_BASE_URL || 'http://localhost:8080/api'

const api = axios.create({
  baseURL: API_BASE_URL,
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
    try { ui = useUiStore() } catch { ui = null }

    const status = error.response?.status
    const data = error.response?.data
    const message = data?.error || error.message || 'Error desconocido'
    const silent = error.config?.silent === true

    if (status === 401) {
      localStorage.removeItem('token')
      const currentPath = window.location.pathname
      if (currentPath !== '/login') {
        const redirect = encodeURIComponent(`${window.location.pathname}${window.location.search}`)
        window.location.assign(`/login?redirect=${redirect}`)
      }
    } else if (silent) {
      // La vista quiere manejarlo a mano (formularios con feedback inline).
    } else if (!status) {
      ui?.toastError('Error de red. Comprueba tu conexión.')
    } else if (status === 429) {
      const retry = data?.retry_after_seconds
      ui?.toastError(retry ? `${message} (espera ${retry}s)` : message)
    } else if (status >= 500) {
      ui?.toastError('Error interno del servidor. Inténtalo de nuevo.')
    } else if ([403, 404, 409, 422].includes(status)) {
      ui?.toastError(message)
    } else if (status >= 400 && status < 500 && status !== 400) {
      ui?.toastError(message)
    }

    return Promise.reject(error)
  }
)

export default api