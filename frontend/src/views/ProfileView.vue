<template>
  <section class="profile-page">
    <article class="profile-card">
      <header class="profile-header">
        <h1>Mi perfil</h1>
        <p>Actualiza tu información personal.</p>
      </header>

      <div v-if="loading" class="state-box">Cargando perfil...</div>

      <div v-else>
        <div class="avatar-row">
          <img
            :src="avatarPreview"
            alt="Foto de perfil"
            class="avatar"
            @error="onAvatarError"
          />
          <div class="avatar-meta">
            <strong>{{ form.full_name || form.username || 'Usuario' }}</strong>
            <small>{{ form.email || '-' }}</small>
          </div>
        </div>

        <form @submit.prevent="handleSave">
          <div class="input-group">
            <label for="fullName">Nombre</label>
            <input
              id="fullName"
              v-model.trim="form.full_name"
              maxlength="100"
              placeholder="Tu nombre completo"
            />
          </div>

          <div class="input-group">
            <label for="username">Nombre de usuario</label>
            <input
              id="username"
              v-model.trim="form.username"
              minlength="3"
              maxlength="30"
              required
            />
          </div>

          <div class="input-group">
            <label for="email">Correo electrónico</label>
            <input
              id="email"
              v-model.trim="form.email"
              type="email"
              required
            />
          </div>

          <div class="input-group">
            <label for="avatarUrl">Foto de perfil (URL)</label>
            <input
              id="avatarUrl"
              v-model.trim="form.avatar_url"
              type="url"
              placeholder="https://..."
            />
            <small class="help">Puedes pegar una URL de imagen pública.</small>
          </div>

          <p v-if="errorMessage" class="msg error">{{ errorMessage }}</p>
          <p v-if="successMessage" class="msg success">{{ successMessage }}</p>

          <button type="submit" class="save-btn" :disabled="saving">
            {{ saving ? 'Guardando...' : 'Guardar cambios' }}
          </button>
        </form>
      </div>
    </article>
  </section>
</template>

<script setup>
import { ref, reactive, computed, onMounted } from 'vue'
import { useAuthStore } from '../stores/auth'

const authStore = useAuthStore()

const loading = ref(true)
const saving = ref(false)
const errorMessage = ref('')
const successMessage = ref('')
const avatarBroken = ref(false)

const form = reactive({
  full_name: '',
  username: '',
  email: '',
  avatar_url: '',
})

const fallbackAvatar = '/favicon.svg'

const avatarPreview = computed(() => {
  if (avatarBroken.value || !form.avatar_url) {
    return fallbackAvatar
  }
  return form.avatar_url
})

function onAvatarError() {
  avatarBroken.value = true
}

function fillFormFromUser(user) {
  form.full_name = user?.full_name || ''
  form.username = user?.username || ''
  form.email = user?.email || ''
  form.avatar_url = user?.avatar_url || ''
  avatarBroken.value = false
}

onMounted(async () => {
  loading.value = true
  errorMessage.value = ''
  successMessage.value = ''

  const result = await authStore.fetchMe()
  if (!result.success) {
    errorMessage.value = result.message
  } else {
    fillFormFromUser(result.user)
  }

  loading.value = false
})

async function handleSave() {
  saving.value = true
  errorMessage.value = ''
  successMessage.value = ''

  const usernameValid = /^[a-zA-Z0-9_]{3,30}$/.test(form.username)
  if (!usernameValid) {
    errorMessage.value = 'El username debe tener 3-30 caracteres (letras, números o _).'
    saving.value = false
    return
  }

  const payload = {
    full_name: form.full_name,
    username: form.username,
    email: form.email,
    avatar_url: form.avatar_url,
  }

  const result = await authStore.updateMe(payload)
  if (!result.success) {
    errorMessage.value = result.message
    saving.value = false
    return
  }

  fillFormFromUser(result.user)
  successMessage.value = result.message || 'Perfil actualizado correctamente'
  saving.value = false
}
</script>

<style scoped>
.profile-page {
  display: flex;
  justify-content: center;
}

.profile-card {
  width: min(680px, 100%);
  background: var(--surface);
  border: 1px solid var(--border);
  border-radius: var(--radius);
  box-shadow: var(--shadow-md);
  padding: 1rem;
}

.profile-header {
  margin-bottom: 0.85rem;
}

.profile-header h1 {
  margin-bottom: 0.2rem;
}

.profile-header p {
  color: var(--muted);
}

.avatar-row {
  display: flex;
  align-items: center;
  gap: 0.85rem;
  margin-bottom: 1rem;
  padding: 0.75rem;
  border: 1px solid var(--border);
  border-radius: 12px;
  background: var(--surface-soft);
}

.avatar {
  width: 68px;
  height: 68px;
  border-radius: 999px;
  object-fit: cover;
  border: 1px solid var(--border);
  background: #fff;
}

.avatar-meta {
  display: grid;
  gap: 0.12rem;
}

.avatar-meta small {
  color: var(--muted);
}

.input-group {
  display: grid;
  gap: 0.35rem;
  margin-bottom: 0.8rem;
}

label {
  font-weight: 700;
  font-size: 0.9rem;
}

input {
  border: 1px solid var(--border);
  border-radius: 10px;
  padding: 0.62rem 0.72rem;
}

input:focus {
  outline: 2px solid rgba(6, 182, 212, 0.25);
  border-color: #06b6d4;
}

.help {
  color: #64748b;
  font-size: 0.78rem;
}

.save-btn {
  width: 100%;
  border: none;
  border-radius: 10px;
  padding: 0.72rem 0.9rem;
  font-weight: 700;
  cursor: pointer;
  color: #fff;
  background: linear-gradient(135deg, #0ea5e9, #06b6d4);
}

.save-btn:disabled {
  opacity: 0.72;
  cursor: not-allowed;
}

.state-box {
  border: 1px dashed #cbd5e1;
  border-radius: 12px;
  padding: 0.9rem;
  text-align: center;
  color: var(--muted);
}

.msg {
  margin: 0.2rem 0 0.7rem;
  font-weight: 600;
}

.msg.error {
  color: #b91c1c;
}

.msg.success {
  color: #166534;
}
</style>