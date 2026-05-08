import { useUiStore } from '../stores/ui'
import { API_BASE_URL } from './api'

/**
 * Wrapper minimalista basado en `fetch` (sin axios) para endpoints públicos
 * de la API. Cumple con el criterio "comunicación asíncrona cliente/servidor"
 * de la rúbrica usando la API nativa del navegador.
 *
 * Uso:
 *   const tournaments = await publicGet('/tournaments')
 *
 * Lanza un Error con mensaje legible si la respuesta no es OK.
 */
export async function publicGet(path, { signal, silent = false } = {}) {
  const url = joinUrl(API_BASE_URL, path)

  let response
  try {
    response = await fetch(url, {
      method: 'GET',
      headers: { Accept: 'application/json' },
      signal,
    })
  } catch (networkError) {
    if (!silent) toastError('Error de red. Comprueba tu conexión.')
    throw networkError
  }

  let payload = null
  try {
    payload = await response.json()
  } catch {
    payload = null
  }

  if (!response.ok) {
    const message = payload?.error || `HTTP ${response.status}`
    if (!silent) {
      if (response.status >= 500) {
        toastError('Error interno del servidor. Inténtalo de nuevo.')
      } else if (response.status === 429) {
        const retry = payload?.retry_after_seconds
        toastError(retry ? `${message} (espera ${retry}s)` : message)
      } else if ([403, 404, 409, 422].includes(response.status)) {
        toastError(message)
      }
    }
    const err = new Error(message)
    err.status = response.status
    err.data = payload
    throw err
  }

  return payload
}

function joinUrl(base, path) {
  if (!path) return base
  if (path.startsWith('http://') || path.startsWith('https://')) return path
  const trimmedBase = base.replace(/\/+$/, '')
  const trimmedPath = path.startsWith('/') ? path : `/${path}`
  return `${trimmedBase}${trimmedPath}`
}

function toastError(message) {
  try {
    useUiStore().toastError(message)
  } catch {
    // pinia aún no inicializado, ignorar.
  }
}