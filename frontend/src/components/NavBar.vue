<template>
  <nav>
    <router-link to="/">Inicio</router-link>
    <router-link to="/tournaments">Torneos</router-link>
    <span v-if="token">
      <router-link to="/create-tournament">Crear torneo</router-link>
      <router-link v-if="isAdmin" to="/admin">Admin</router-link>
      <button @click="logout">Cerrar sesión</button>
    </span>
    <span v-else>
      <router-link to="/login">Login</router-link>
      <router-link to="/register">Registro</router-link>
    </span>
  </nav>
</template>

<script setup>
import { useAuthStore } from '../stores/auth'
import { storeToRefs } from 'pinia'

const authStore = useAuthStore()
const { token, isAdmin } = storeToRefs(authStore)
const { logout } = authStore
</script>

<style scoped>
nav {
  display: flex;
  gap: 1rem;
  padding: 1rem;
  background: #2c3e50;
}
nav a, nav button {
  color: white;
  text-decoration: none;
  background: none;
  border: none;
  cursor: pointer;
  font-size: 1rem;
}
</style>