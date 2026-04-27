<template>
  <header class="navbar">
    <div class="navbar-brand">
      <router-link to="/" class="logo">🏆 TourneyHub</router-link>
    </div>
    <nav class="navbar-links">
      <router-link to="/tournaments">Torneos</router-link>
      <template v-if="token">
        <router-link to="/create-tournament" class="btn-link">Crear torneo</router-link>
        <router-link v-if="isAdmin" to="/admin">Admin</router-link>
        <button @click="logout" class="logout-btn">Cerrar sesión</button>
      </template>
      <template v-else>
        <router-link to="/login">Iniciar sesión</router-link>
        <router-link to="/register" class="btn-link register-btn">Registro</router-link>
      </template>
    </nav>
  </header>
</template>

<script setup>
import { useAuthStore } from '../stores/auth'
import { storeToRefs } from 'pinia'

const authStore = useAuthStore()
const { token, isAdmin } = storeToRefs(authStore)
const { logout } = authStore
</script>

<style scoped>
.navbar {
  background: linear-gradient(135deg, #0f0c29, #302b63, #24243e);
  color: white;
  padding: 0 2rem;
  display: flex;
  justify-content: space-between;
  align-items: center;
  height: 70px;
  box-shadow: 0 2px 10px rgba(0,0,0,0.3);
  position: sticky;
  top: 0;
  z-index: 100;
}
.logo {
  font-size: 1.5rem;
  font-weight: 700;
  color: #f0c040;
  text-decoration: none;
  display: flex;
  align-items: center;
  gap: 0.5rem;
}
.navbar-links {
  display: flex;
  gap: 1.5rem;
  align-items: center;
}
.navbar-links a {
  color: white;
  text-decoration: none;
  font-weight: 500;
  transition: color 0.2s;
  padding: 0.5rem 0;
}
.navbar-links a:hover {
  color: #f0c040;
}
.btn-link {
  background: #f0c040;
  color: #1a1a2e !important;
  padding: 0.5rem 1.2rem !important;
  border-radius: 20px;
  font-weight: 600;
  transition: background 0.2s;
}
.btn-link:hover {
  background: #e0b030 !important;
}
.register-btn {
  background: #e94560;
  color: white !important;
  border-radius: 20px;
}
.register-btn:hover {
  background: #c23150 !important;
}
.logout-btn {
  background: transparent;
  color: white;
  border: 1px solid #555;
  padding: 0.4rem 1rem;
  border-radius: 20px;
  cursor: pointer;
  font-weight: 500;
  transition: all 0.2s;
}
.logout-btn:hover {
  border-color: #e94560;
  color: #e94560;
}
@media (max-width: 600px) {
  .navbar {
    flex-direction: column;
    height: auto;
    padding: 1rem;
  }
  .navbar-links {
    flex-wrap: wrap;
    justify-content: center;
    gap: 0.8rem;
    margin-top: 0.5rem;
  }
}
</style>