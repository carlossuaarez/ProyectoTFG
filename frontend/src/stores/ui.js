import { defineStore } from 'pinia'
import { ref, computed } from 'vue'

let toastSeq = 1

export const useUiStore = defineStore('ui', () => {
  const toasts = ref([])

  const notificationsOpen = ref(false)
  const notificationsLoading = ref(false)
  const notificationsError = ref('')
  const notifications = ref([])
  const unreadCount = ref(0)

  const hasUnreadNotifications = computed(() => unreadCount.value > 0)

  function pushToast(payload) {
    const id = toastSeq++
    const item = {
      id,
      type: payload?.type || 'info',
      title: String(payload?.title || ''),
      message: String(payload?.message || ''),
      timeoutMs: Number(payload?.timeoutMs || 3400),
    }
    toasts.value.push(item)
    if (item.timeoutMs > 0) {
      window.setTimeout(() => {
        removeToast(id)
      }, item.timeoutMs)
    }
    return id
  }

  function removeToast(id) {
    toasts.value = toasts.value.filter((t) => t.id !== id)
  }

  function toastSuccess(message, title = 'Éxito') {
    pushToast({ type: 'success', title, message })
  }

  function toastError(message, title = 'Error') {
    pushToast({ type: 'error', title, message, timeoutMs: 4600 })
  }

  function toastInfo(message, title = 'Info') {
    pushToast({ type: 'info', title, message })
  }

  function setNotificationsOpen(value) {
    notificationsOpen.value = Boolean(value)
  }

  function toggleNotifications() {
    notificationsOpen.value = !notificationsOpen.value
  }

  function setNotificationsPayload(data) {
    notifications.value = Array.isArray(data?.items) ? data.items : []
    unreadCount.value = Number(data?.unread_count || 0)
  }

  return {
    toasts,
    pushToast,
    removeToast,
    toastSuccess,
    toastError,
    toastInfo,
    notificationsOpen,
    notificationsLoading,
    notificationsError,
    notifications,
    unreadCount,
    hasUnreadNotifications,
    setNotificationsOpen,
    toggleNotifications,
    setNotificationsPayload,
  }
})