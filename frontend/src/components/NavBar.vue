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
          <router-link to="/profile" class="action-link">
            <UserCircle class="pill-icon" />
            Perfil
          </router-link>

          <router-link to="/create-tournament" class="pill pill-primary action-link">
            <Plus class="pill-icon" />
            Crear torneo
          </router-link>

          <router-link v-if="isAdmin" to="/admin">Admin</router-link>

          <button class="pill pill-ghost action-link" type="button" @click="handleLogout">
            <LogOut class="pill-icon" />
            Cerrar sesión
          </button>
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
import { ref, watch } from 'vue'
import { useRoute } from 'vue-router'
import { useAuthStore } from '../stores/auth'
import { storeToRefs } from 'pinia'
import { Trophy, Menu, X, Plus, LogOut, UserCircle } from 'lucide-vue-next'

const authStore = useAuthStore()
const { token, isAdmin } = storeToRefs(authStore)
const { logout } = authStore

const route = useRoute()
const isMenuOpen = ref(false)

watch(
  () => route.fullPath,
  () => {
    isMenuOpen.value = false
  }
)

function handleLogout() {
  isMenuOpen.value = false
  logout()
}
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

.pill-ghost {
  background: rgba(255, 255, 255, 0.08);
  color: #f8fafc;
  border: 1px solid rgba(255, 255, 255, 0.18);
}

.pill-ghost:hover {
  background: rgba(255, 255, 255, 0.14);
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
  .pill-ghost,
  .pill-primary {
    text-align: center;
    justify-content: center;
  }
}
</style>