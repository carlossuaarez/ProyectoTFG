<template>
  <teleport to="body">
    <div class="toast-host" aria-live="polite" aria-atomic="true">
      <article
        v-for="t in ui.toasts"
        :key="t.id"
        class="toast"
        :class="`toast-${t.type}`"
      >
        <header>
          <strong>{{ t.title || labelByType(t.type) }}</strong>
          <button type="button" aria-label="Cerrar" @click="ui.removeToast(t.id)">✕</button>
        </header>
        <p>{{ t.message }}</p>
      </article>
    </div>
  </teleport>
</template>

<script setup>
import { useUiStore } from '../stores/ui'

const ui = useUiStore()

function labelByType(type) {
  if (type === 'success') return 'Éxito'
  if (type === 'error') return 'Error'
  return 'Info'
}
</script>

<style scoped>
.toast-host {
  position: fixed;
  right: 1rem;
  bottom: 1rem;
  display: grid;
  gap: 0.55rem;
  z-index: 9999;
  width: min(360px, calc(100vw - 2rem));
}

.toast {
  border-radius: 12px;
  border: 1px solid #dbe1ea;
  background: #ffffff;
  box-shadow: 0 12px 30px rgba(15, 23, 42, 0.16);
  padding: 0.6rem 0.72rem;
}

.toast header {
  display: flex;
  justify-content: space-between;
  gap: 0.65rem;
  margin-bottom: 0.2rem;
}

.toast header strong {
  font-size: 0.9rem;
}

.toast header button {
  border: none;
  background: transparent;
  color: #64748b;
  font-weight: 700;
  cursor: pointer;
}

.toast p {
  color: #334155;
  font-size: 0.88rem;
}

.toast-success {
  border-color: #86efac;
  background: #f0fdf4;
}

.toast-error {
  border-color: #fecaca;
  background: #fff1f2;
}

.toast-info {
  border-color: #bfdbfe;
  background: #eff6ff;
}
</style>