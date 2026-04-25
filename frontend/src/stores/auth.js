import { defineStore } from 'pinia';
import { ref } from 'vue';
import api from '../services/api';

export const useAuthStore = defineStore('auth', () => {
  const token = ref(localStorage.getItem('token') || null);
  const user = ref(null);

  async function login(email, password) {
    try {
      const res = await api.post('/login', { email, password });
      token.value = res.data.token;
      localStorage.setItem('token', token.value);
      return true;
    } catch (err) {
      return false;
    }
  }

  function logout() {
    token.value = null;
    user.value = null;
    localStorage.removeItem('token');
  }

  return { token, user, login, logout };
});