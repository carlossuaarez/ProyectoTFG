<template>
  <header class="navbar">
    <div class="nav-inner">
      <router-link to="/" class="logo">
        <Trophy class="logo-icon" />
        <span>TourneyHub</span>
      </router-link>

      <button
        class="menu-toggle"
        type="button"
        :aria-expanded="isMenuOpen ? 'true' : 'false'"
        aria-label="Abrir menú"
        @click="isMenuOpen = !isMenuOpen"
      >
        <Menu v-if="!isMenuOpen" class="icon-20" />
        <X v-else class="icon-20" />
      </button>

      <nav class="nav-links" :class="{ open: isMenuOpen }">
        <router-link to="/tournaments">Torneos</router-link>

        <template v-if="token">
          <router-link to="/create-tournament" class="pill pill-primary action-link">
            <Plus class="pill-icon" />
            Crear torneo
          </router-link>

          <router-link v-if="isAdmin" to="/admin">Admin</router-link>

          <div ref="userMenuRef" class="user-menu-wrap">
            <button
              class="user-trigger"
              type="button"
              :aria-expanded="isUserMenuOpen ? 'true' : 'false'"
              @click.stop="toggleUserMenu"
            >
              <img
                :src="avatarSrc"
                alt="Foto de perfil"
                class="user-avatar"
                @error="onAvatarError"
              />
              <span class="user-label">{{ userLabel }}</span>
              <ChevronDown class="pill-icon chevron" :class="{ open: isUserMenuOpen }" />
            </button>

            <div v-if="isUserMenuOpen" class="user-dropdown">
              <router-link to="/profile" class="dropdown-item" @click="closeMenus">
                <UserCircle class="pill-icon" />
                Editar perfil
              </router-link>

              <router-link to="/settings" class="dropdown-item" @click="closeMenus">
                <Settings class="pill-icon" />
                Configuración
              </router-link>

              <button class="dropdown-item logout-item" type="button" @click="handleLogout">
                <LogOut class="pill-icon" />
                Cerrar sesión
              </button>
            </div>
          </div>
        </template>

        <template v-else>
          <router-link to="/login">Iniciar sesión</router-link>
          <router-link to="/register" class="pill pill-accent">Registro</router-link>
        </template>
      </nav>
    </div>
  </header>
</template>

<script setup>
import { ref, watch, computed, onMounted, onBeforeUnmount } from 'vue'
import { useRoute } from 'vue-router'
import { useAuthStore } from '../stores/auth'
import { storeToRefs } from 'pinia'
import { Trophy, Menu, X, Plus, LogOut, UserCircle, Settings, ChevronDown } from 'lucide-vue-next'

const authStore = useAuthStore()
const { token, isAdmin, me, payload } = storeToRefs(authStore)
const { logout } = authStore

const route = useRoute()
const isMenuOpen = ref(false)
const isUserMenuOpen = ref(false)
const userMenuRef = ref(null)
const avatarBroken = ref(false)

const fallbackAvatar = '/favicon.svg'
const API_BASE = import.meta.env.VITE_API_BASE_URL || 'http://localhost:8080/api'

function getBackendOrigin() {
  try {
    return new URL(API_BASE).origin
  } catch {
    return ''
  }
}

function resolveAvatarUrl(url) {
  const value = String(url || '').trim()
  if (!value) return ''
  if (value.startsWith('/uploads/')) {
    const origin = getBackendOrigin()
    return origin ? `${origin}${value}` : value
  }
  return value
}

const userLabel = computed(() => {
  const username = String(me.value?.username || payload.value?.username || '').trim()
  return username || 'Usuario'
})

const avatarSrc = computed(() => {
  if (avatarBroken.value) return fallbackAvatar
  const resolved = resolveAvatarUrl(me.value?.avatar_url || '')
  return resolved || fallbackAvatar
})

function onAvatarError() {
  avatarBroken.value = true
}

async function ensureUserLoaded() {
  if (!token.value) return
  if (me.value?.username) return
  await authStore.fetchMe()
}

function toggleUserMenu() {
  isUserMenuOpen.value = !isUserMenuOpen.value
}

function closeMenus() {
  isMenuOpen.value = false
  isUserMenuOpen.value = false
}

function onDocumentClick(event) {
  if (!isUserMenuOpen.value) return
  if (!userMenuRef.value) return
  if (!userMenuRef.value.contains(event.target)) {
    isUserMenuOpen.value = false
  }
}

function handleLogout() {
  closeMenus()
  logout()
}

watch(
  () => route.fullPath,
  () => {
    closeMenus()
  }
)

watch(
  () => token.value,
  async (nextToken) => {
    avatarBroken.value = false
    if (!nextToken) {
      isUserMenuOpen.value = false
      return
    }
    await ensureUserLoaded()
  },
  { immediate: true }
)

onMounted(async () => {
  document.addEventListener('click', onDocumentClick)
  await ensureUserLoaded()
})

onBeforeUnmount(() => {
  document.removeEventListener('click', onDocumentClick)
})
</script>

<style scoped>
.navbar {
  position: sticky;
  top: 0;
  z-index: 100;
  backdrop-filter: blur(10px);
  background: rgba(15, 23, 42, 0.9);
  border-bottom: 1px solid rgba(255, 255, 255, 0.12);
}

.nav-inner {
  width: min(1200px, 100%);
  margin: 0 auto;
  min-height: 72px;
  padding: 0 1rem;
  display: flex;
  align-items: center;
  gap: 1rem;
}

.logo {
  display: inline-flex;
  align-items: center;
  gap: 0.45rem;
  text-decoration: none;
  color: #f8fafc;
  font-size: 1.2rem;
  font-weight: 700;
  margin-right: auto;
}

.logo-icon {
  width: 20px;
  height: 20px;
  filter: drop-shadow(0 0 8px rgba(245, 158, 11, 0.45));
}

.icon-20 {
  width: 20px;
  height: 20px;
}

.menu-toggle {
  display: none;
  border: 1px solid rgba(255, 255, 255, 0.22);
  background: transparent;
  color: #e2e8f0;
  border-radius: 10px;
  width: 40px;
  height: 40px;
  cursor: pointer;
  align-items: center;
  justify-content: center;
}

.nav-links {
  display: flex;
  align-items: center;
  gap: 0.9rem;
}

.nav-links a {
  color: #e2e8f0;
  text-decoration: none;
  font-weight: 500;
  padding: 0.45rem 0.25rem;
  border-bottom: 2px solid transparent;
}

.nav-links a:hover {
  color: #ffffff;
}

.nav-links a.router-link-exact-active {
  color: #f8fafc;
  border-bottom-color: var(--accent);
}

.pill {
  border: none;
  border-radius: 999px;
  padding: 0.5rem 1rem !important;
  font-weight: 700 !important;
  border-bottom: none !important;
  cursor: pointer;
}

.action-link {
  display: inline-flex;
  align-items: center;
  gap: 0.4rem;
}

.pill-icon {
  width: 16px;
  height: 16px;
}

.pill-primary {
  background: linear-gradient(135deg, #0ea5e9, #06b6d4);
  color: #ffffff !important;
}

.pill-accent {
  background: linear-gradient(135deg, #f59e0b, #f97316);
  color: #ffffff !important;
}

.user-menu-wrap {
  position: relative;
}

.user-trigger {
  border: 1px solid rgba(255, 255, 255, 0.2);
  background: rgba(255, 255, 255, 0.08);
  color: #f8fafc;
  border-radius: 999px;
  padding: 0.35rem 0.65rem 0.35rem 0.4rem;
  display: inline-flex;
  align-items: center;
  gap: 0.5rem;
  cursor: pointer;
}

.user-trigger:hover {
  background: rgba(255, 255, 255, 0.14);
}

.user-avatar {
  width: 28px;
  height: 28px;
  border-radius: 999px;
  object-fit: cover;
  border: 1px solid rgba(255, 255, 255, 0.24);
  background: #ffffff;
}

.user-label {
  font-weight: 700;
  font-size: 0.92rem;
}

.chevron {
  transition: transform 0.2s ease;
}

.chevron.open {
  transform: rotate(180deg);
}

.user-dropdown {
  position: absolute;
  right: 0;
  top: calc(100% + 0.45rem);
  min-width: 210px;
  background: #ffffff;
  color: #0f172a;
  border: 1px solid #e2e8f0;
  border-radius: 12px;
  box-shadow: 0 16px 32px rgba(2, 6, 23, 0.2);
  padding: 0.4rem;
  display: grid;
  gap: 0.2rem;
  z-index: 120;
}

.user-dropdown .dropdown-item {
  display: inline-flex;
  align-items: center;
  gap: 0.5rem;
  width: 100%;
  border: none;
  background: transparent;
  color: #0f172a !important;
  text-decoration: none;
  font-weight: 600;
  border-radius: 9px;
  padding: 0.55rem 0.6rem;
  border-bottom: none !important;
  cursor: pointer;
  text-align: left;
}

.user-dropdown .dropdown-item:hover {
  background: #f1f5f9;
}

.user-dropdown .dropdown-item.router-link-exact-active {
  border-bottom-color: transparent !important;
}

.logout-item {
  font: inherit;
}

@media (max-width: 900px) {
  .menu-toggle {
    display: inline-flex;
  }

  .nav-links {
    display: none;
    position: absolute;
    top: 72px;
    left: 0;
    width: 100%;
    padding: 0.9rem 1rem 1rem;
    flex-direction: column;
    align-items: stretch;
    background: #0f172a;
    border-bottom: 1px solid rgba(255, 255, 255, 0.12);
  }

  .nav-links.open {
    display: flex;
  }

  .nav-links a,
  .pill-primary,
  .user-trigger {
    text-align: center;
    justify-content: center;
  }

  .user-menu-wrap {
    width: 100%;
  }

  .user-trigger {
    width: 100%;
    border-radius: 12px;
  }

  .user-dropdown {
    position: static;
    margin-top: 0.45rem;
    width: 100%;
  }
}
</style>