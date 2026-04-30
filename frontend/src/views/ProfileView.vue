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
          <div ref="avatarEditorRef" class="avatar-wrapper">
            <img
              :src="avatarPreview"
              alt="Foto de perfil"
              class="avatar"
              @error="onAvatarError"
            />

            <button
              type="button"
              class="avatar-overlay-btn"
              @click.stop="togglePhotoEditor"
            >
              Cambiar foto
            </button>

            <div v-if="photoEditorOpen" class="avatar-editor" @click.stop>
              <div class="editor-tabs">
                <button
                  type="button"
                  class="editor-tab"
                  :class="{ active: photoMode === 'file' }"
                  @click="photoMode = 'file'"
                >
                  Dispositivo
                </button>
                <button
                  type="button"
                  class="editor-tab"
                  :class="{ active: photoMode === 'url' }"
                  @click="photoMode = 'url'"
                >
                  URL
                </button>
              </div>

              <div v-if="photoMode === 'file'" class="editor-panel">
                <input
                  ref="avatarFileInputRef"
                  type="file"
                  accept="image/png,image/jpeg,image/webp"
                  @change="handleAvatarFileChange"
                />
                <small class="help">PNG/JPG/WEBP, máximo 2 MB.</small>
                <small v-if="selectedFileName" class="help">Archivo: {{ selectedFileName }}</small>
              </div>

              <div v-else class="editor-panel">
                <input
                  v-model.trim="photoUrlDraft"
                  type="text"
                  class="editor-input"
                  placeholder="https://... o /uploads/avatars/..."
                />
                <div class="editor-actions">
                  <button type="button" class="mini-btn" @click="applyPhotoUrl">
                    Aplicar URL
                  </button>
                </div>
              </div>

              <small v-if="photoEditorError || fileError" class="msg error file-error">
                {{ photoEditorError || fileError }}
              </small>
            </div>
          </div>

          <div class="avatar-meta">
            <strong>{{ userHandle }}</strong>
            <small>{{ form.email || '-' }}</small>
          </div>
        </div>

        <form @submit.prevent="handleSave">
          <div class="input-group">
            <label for="fullName">Nombre y apellidos reales</label>
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
import { ref, reactive, computed, onMounted, onBeforeUnmount } from 'vue'
import { useAuthStore } from '../stores/auth'

const authStore = useAuthStore()

const loading = ref(true)
const saving = ref(false)
const errorMessage = ref('')
const successMessage = ref('')
const avatarBroken = ref(false)

const localAvatarPreview = ref('')
const avatarFileBase64 = ref('')
const selectedFileName = ref('')
const fileError = ref('')

const photoEditorOpen = ref(false)
const photoMode = ref('file') // ahora por defecto abre en Dispositivo
const photoUrlDraft = ref('')
const photoEditorError = ref('')
const avatarEditorRef = ref(null)
const avatarFileInputRef = ref(null)

const form = reactive({
  full_name: '',
  username: '',
  email: '',
  avatar_url: '',
})

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

function isValidAvatarReference(value) {
  if (value === '') return true
  if (value.startsWith('/uploads/')) return true
  return /^https?:\/\/[^\s]+$/i.test(value)
}

const userHandle = computed(() => {
  const clean = String(form.username || '').trim().replace(/^@+/, '')
  return clean ? `@${clean}` : '@'
})

const avatarPreview = computed(() => {
  if (localAvatarPreview.value) return localAvatarPreview.value

  const resolved = resolveAvatarUrl(form.avatar_url)
  if (avatarBroken.value || !resolved) return fallbackAvatar

  return resolved
})

function onAvatarError() {
  avatarBroken.value = true
}

function clearLocalAvatarSelection() {
  localAvatarPreview.value = ''
  avatarFileBase64.value = ''
  selectedFileName.value = ''
  fileError.value = ''
  if (avatarFileInputRef.value) {
    avatarFileInputRef.value.value = ''
  }
}

function togglePhotoEditor() {
  photoEditorOpen.value = !photoEditorOpen.value
  photoEditorError.value = ''
  if (photoEditorOpen.value) {
    photoUrlDraft.value = form.avatar_url || ''
  }
}

function closePhotoEditor() {
  photoEditorOpen.value = false
  photoEditorError.value = ''
}

function applyPhotoUrl() {
  const value = String(photoUrlDraft.value || '').trim()

  if (!isValidAvatarReference(value)) {
    photoEditorError.value = 'Introduce una URL válida (http/https) o una ruta /uploads/...'
    return
  }

  form.avatar_url = value
  avatarBroken.value = false
  photoEditorError.value = ''

  // Si aplicamos URL, se descarta selección local de archivo
  clearLocalAvatarSelection()
  closePhotoEditor()
}

function handleAvatarFileChange(event) {
  const file = event?.target?.files?.[0]
  fileError.value = ''
  photoEditorError.value = ''

  if (!file) {
    clearLocalAvatarSelection()
    return
  }

  const allowedTypes = ['image/png', 'image/jpeg', 'image/webp']
  if (!allowedTypes.includes(file.type)) {
    fileError.value = 'Formato no permitido. Usa PNG, JPG o WEBP.'
    clearLocalAvatarSelection()
    return
  }

  const maxBytes = 2 * 1024 * 1024
  if (file.size > maxBytes) {
    fileError.value = 'La imagen supera 2 MB.'
    clearLocalAvatarSelection()
    return
  }

  const reader = new FileReader()
  reader.onload = () => {
    const result = String(reader.result || '')
    if (!result.startsWith('data:image/')) {
      fileError.value = 'No se pudo leer la imagen seleccionada.'
      clearLocalAvatarSelection()
      return
    }

    avatarFileBase64.value = result
    localAvatarPreview.value = result
    selectedFileName.value = file.name
    avatarBroken.value = false
    closePhotoEditor()
  }
  reader.onerror = () => {
    fileError.value = 'No se pudo leer la imagen seleccionada.'
    clearLocalAvatarSelection()
  }
  reader.readAsDataURL(file)
}

function fillFormFromUser(user) {
  form.full_name = user?.full_name || ''
  form.username = user?.username || ''
  form.email = user?.email || ''
  form.avatar_url = user?.avatar_url || ''

  photoUrlDraft.value = form.avatar_url || ''
  avatarBroken.value = false
  clearLocalAvatarSelection()
}

function handleOutsideClick(event) {
  if (!photoEditorOpen.value) return
  if (!avatarEditorRef.value) return
  if (!avatarEditorRef.value.contains(event.target)) {
    closePhotoEditor()
  }
}

onMounted(async () => {
  document.addEventListener('click', handleOutsideClick)

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

onBeforeUnmount(() => {
  document.removeEventListener('click', handleOutsideClick)
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
    avatar_file_base64: avatarFileBase64.value,
  }

  const result = await authStore.updateMe(payload)
  if (!result.success) {
    errorMessage.value = result.message
    saving.value = false
    return
  }

  fillFormFromUser(result.user)
  successMessage.value = result.message || 'Perfil actualizado correctamente'
  clearLocalAvatarSelection()
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

.avatar-wrapper {
  position: relative;
  width: 68px;
  height: 68px;
  flex-shrink: 0;
}

.avatar {
  width: 68px;
  height: 68px;
  border-radius: 999px;
  object-fit: cover;
  border: 1px solid var(--border);
  background: #fff;
}

.avatar-overlay-btn {
  position: absolute;
  inset: 0;
  border: none;
  border-radius: 999px;
  background: rgba(15, 23, 42, 0.62);
  color: #fff;
  font-size: 0.72rem;
  font-weight: 700;
  opacity: 0;
  cursor: pointer;
  transition: opacity 0.15s ease;
}

.avatar-wrapper:hover .avatar-overlay-btn,
.avatar-wrapper:focus-within .avatar-overlay-btn {
  opacity: 1;
}

.avatar-editor {
  position: absolute;
  top: calc(100% + 8px);
  left: 0;
  width: 320px;
  max-width: min(320px, 86vw);
  background: #fff;
  border: 1px solid var(--border);
  border-radius: 12px;
  box-shadow: var(--shadow-md);
  padding: 0.65rem;
  z-index: 15;
  overflow: hidden;
}

.editor-tabs {
  display: flex;
  gap: 0.45rem;
  margin-bottom: 0.55rem;
}

.editor-tab {
  border: 1px solid var(--border);
  background: #f8fafc;
  border-radius: 8px;
  padding: 0.35rem 0.55rem;
  font-size: 0.82rem;
  font-weight: 700;
  cursor: pointer;
}

.editor-tab.active {
  border-color: #06b6d4;
  background: rgba(6, 182, 212, 0.1);
  color: #0f172a;
}

.editor-panel {
  display: grid;
  gap: 0.45rem;
  min-width: 0;
}

/* Ajuste clave para que "Ningún archivo seleccionado" no se salga */
.editor-panel input[type='file'] {
  width: 100%;
  max-width: 100%;
  min-width: 0;
  box-sizing: border-box;
  font-size: 0.8rem;
  border: 1px solid var(--border);
  border-radius: 8px;
  padding: 0.42rem 0.5rem;
  background: #fff;
  overflow: hidden;
}

.editor-input {
  border: 1px solid var(--border);
  border-radius: 8px;
  padding: 0.5rem 0.6rem;
  font-size: 0.88rem;
}

.editor-input:focus {
  outline: 2px solid rgba(6, 182, 212, 0.22);
  border-color: #06b6d4;
}

.editor-actions {
  display: flex;
  justify-content: flex-end;
}

.mini-btn {
  border: none;
  border-radius: 8px;
  padding: 0.45rem 0.65rem;
  font-weight: 700;
  font-size: 0.82rem;
  cursor: pointer;
  color: #fff;
  background: linear-gradient(135deg, #0ea5e9, #06b6d4);
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

.file-error {
  margin-top: 0.2rem;
}

.msg.success {
  color: #166534;
}

@media (max-width: 768px) {
  .avatar-overlay-btn {
    opacity: 1;
    font-size: 0.68rem;
  }

  .avatar-editor {
    width: min(320px, 86vw);
  }
}
</style>