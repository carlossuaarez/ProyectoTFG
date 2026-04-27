<template>
  <div class="form-container">
    <h2>Iniciar sesión</h2>
    <form @submit.prevent="handleLogin">
      <input v-model="email" type="email" placeholder="Email" required />
      <input v-model="password" type="password" placeholder="Contraseña" required />
      <button type="submit">Entrar</button>
    </form>
    <p>¿No tienes cuenta? <router-link to="/register">Regístrate</router-link></p>
  </div>
</template>

<script setup>
import { ref } from 'vue'
import { useAuthStore } from '../stores/auth'
import { useRouter } from 'vue-router'

const email = ref('')
const password = ref('')
const authStore = useAuthStore()
const router = useRouter()

async function handleLogin() {
  const success = await authStore.login(email.value, password.value)
  if (success) {
    router.push('/tournaments')
  }
}
</script>

<style scoped>
.auth-wrapper {
  display: flex;
  justify-content: center;
  align-items: center;
  min-height: 60vh;
}
.auth-card {
  background: white;
  padding: 2.5rem;
  border-radius: 16px;
  box-shadow: 0 8px 30px rgba(0,0,0,0.12);
  width: 100%;
  max-width: 420px;
}
.auth-card h2 {
  text-align: center;
  margin-bottom: 1.5rem;
  color: #1a1a2e;
}
.input-group {
  margin-bottom: 1.2rem;
}
.input-group label {
  display: block;
  margin-bottom: 0.3rem;
  font-weight: 600;
  color: #333;
}
.input-group input {
  width: 100%;
  padding: 0.7rem 1rem;
  border: 2px solid #e0e0e0;
  border-radius: 8px;
  font-size: 1rem;
  transition: border-color 0.2s;
}
.input-group input:focus {
  outline: none;
  border-color: #f0c040;
}
.submit-btn {
  width: 100%;
  padding: 0.8rem;
  background: linear-gradient(135deg, #302b63, #24243e);
  color: white;
  border: none;
  border-radius: 8px;
  font-size: 1.1rem;
  font-weight: 600;
  cursor: pointer;
  margin-top: 0.5rem;
  transition: opacity 0.2s;
}
.submit-btn:hover {
  opacity: 0.9;
}
.switch-page {
  text-align: center;
  margin-top: 1.2rem;
  color: #666;
}
.switch-page a {
  color: #e94560;
  text-decoration: none;
  font-weight: 600;
}
</style>