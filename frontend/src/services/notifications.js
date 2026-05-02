import api from './api'
import { useUiStore } from '../stores/ui'

export async function fetchNotifications(limit = 25) {
  const ui = useUiStore()
  ui.notificationsLoading.value = true
  ui.notificationsError.value = ''
  try {
    const res = await api.get('/notifications', { params: { limit } })
    ui.setNotificationsPayload(res.data || {})
    return { success: true, data: res.data }
  } catch (err) {
    const msg = err.response?.data?.error || 'No se pudieron cargar las notificaciones.'
    ui.notificationsError.value = msg
    return { success: false, message: msg }
  } finally {
    ui.notificationsLoading.value = false
  }
}

export async function markNotificationRead(id) {
  const ui = useUiStore()
  try {
    await api.patch(`/notifications/${id}/read`)
    ui.notifications.value = ui.notifications.value.map((n) => {
      if (Number(n.id) !== Number(id)) return n
      return { ...n, is_read: true }
    })
    const unread = ui.notifications.value.filter((n) => !n.is_read).length
    ui.unreadCount.value = unread
    return { success: true }
  } catch (err) {
    return {
      success: false,
      message: err.response?.data?.error || 'No se pudo marcar como leída.',
    }
  }
}

export async function markAllNotificationsRead() {
  const ui = useUiStore()
  try {
    await api.patch('/notifications/read-all')
    ui.notifications.value = ui.notifications.value.map((n) => ({ ...n, is_read: true }))
    ui.unreadCount.value = 0
    return { success: true }
  } catch (err) {
    return {
      success: false,
      message: err.response?.data?.error || 'No se pudieron marcar como leídas.',
    }
  }
}