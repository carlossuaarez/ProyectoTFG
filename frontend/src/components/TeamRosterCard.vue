<template>
  <article class="team-card" :style="{ '--team-color': normalizedColor }">
    <header class="team-header">
      <div class="team-identity">
        <img :src="logoSrc" class="team-logo" alt="Logo del equipo" @error="onLogoError" />
        <div>
          <h3>{{ team.name }}</h3>
          <small class="muted">{{ statusLabel }}</small>
        </div>
      </div>

      <span class="status-chip" :class="team.status">{{ statusLabel }}</span>
    </header>

    <div class="occupancy-box">
      <div class="occupancy-meta">
        <span>Plazas ocupadas</span>
        <strong>{{ team.validated_members }} / {{ team.capacity }}</strong>
      </div>
      <div class="bar">
        <div class="bar-fill" :style="{ width: `${occupancyPercent}%` }"></div>
      </div>
    </div>

    <p v-if="team.pending_members > 0" class="pending-note">
      {{ team.pending_members }} jugador(es) pendiente(s) de validación.
    </p>

    <ul class="member-list">
      <li v-for="member in team.members" :key="member.id">
        <img :src="resolveAvatar(member.avatar_url)" class="avatar" alt="avatar" @error="onAvatarError" />
        <div class="member-main">
          <strong>@{{ member.username }}</strong>
          <small class="muted" v-if="member.pending_validation">Pendiente de validar</small>
        </div>

        <span class="role-badge" :class="member.role">{{ roleLabel(member.role) }}</span>

        <button
          v-if="canManage && member.pending_validation"
          class="mini-btn"
          type="button"
          @click="$emit('validate-member', member.id)"
        >
          Validar
        </button>

        <select
          v-if="canChangeRoles && !member.pending_validation && member.role !== 'captain'"
          :value="member.role"
          class="role-select"
          @change="$emit('change-role', { memberId: member.id, role: $event.target.value })"
        >
          <option value="player">Jugador</option>
          <option value="co_captain">Co-capitán</option>
        </select>
      </li>
    </ul>
  </article>
</template>

<script setup>
import { computed, ref } from 'vue'

const props = defineProps({
  team: { type: Object, required: true },
  canManage: { type: Boolean, default: false },
  canChangeRoles: { type: Boolean, default: false }
})

defineEmits(['validate-member', 'change-role'])

const logoBroken = ref(false)
const avatarBroken = ref(false)

const API_BASE = import.meta.env.VITE_API_BASE_URL || 'http://localhost:8080/api'
const fallbackLogo = '/favicon.svg'
const fallbackAvatar = '/favicon.svg'

function getBackendOrigin() {
  try {
    return new URL(API_BASE).origin
  } catch {
    return ''
  }
}

function resolveUrl(url) {
  const value = String(url || '').trim()
  if (!value) return ''
  if (value.startsWith('/uploads/')) {
    const origin = getBackendOrigin()
    return origin ? `${origin}${value}` : value
  }
  return value
}

function resolveAvatar(url) {
  if (avatarBroken.value) return fallbackAvatar
  const resolved = resolveUrl(url)
  return resolved || fallbackAvatar
}

const logoSrc = computed(() => {
  if (logoBroken.value) return fallbackLogo
  const resolved = resolveUrl(props.team.logo_url)
  return resolved || fallbackLogo
})

const normalizedColor = computed(() => {
  const color = String(props.team.color_hex || '#0EA5E9').toUpperCase()
  return /^#[0-9A-F]{6}$/.test(color) ? color : '#0EA5E9'
})

const occupancyPercent = computed(() => {
  const capacity = Math.max(1, Number(props.team.capacity || 1))
  const occupied = Math.max(0, Number(props.team.validated_members || 0))
  return Math.min(100, Math.round((occupied / capacity) * 100))
})

const statusLabel = computed(() => {
  if (props.team.status === 'complete') return 'Completo'
  if (props.team.status === 'pending_validate') return 'Pendiente de validar'
  return 'Incompleto'
})

function roleLabel(role) {
  if (role === 'captain') return 'Capitán'
  if (role === 'co_captain') return 'Co-capitán'
  return 'Jugador'
}

function onLogoError() {
  logoBroken.value = true
}

function onAvatarError() {
  avatarBroken.value = true
}
</script>

<style scoped>
.team-card {
  border: 1px solid var(--border);
  border-left: 6px solid var(--team-color);
  border-radius: 12px;
  background: #fff;
  padding: 0.9rem;
  display: grid;
  gap: 0.7rem;
}

.team-header {
  display: flex;
  justify-content: space-between;
  gap: 0.6rem;
  align-items: center;
}

.team-identity {
  display: flex;
  align-items: center;
  gap: 0.6rem;
}

.team-logo {
  width: 44px;
  height: 44px;
  border-radius: 12px;
  border: 1px solid var(--border);
  object-fit: cover;
  background: #fff;
}

.team-identity h3 {
  margin: 0;
  font-size: 1rem;
}

.muted {
  color: #64748b;
}

.status-chip {
  border-radius: 999px;
  padding: 0.22rem 0.58rem;
  font-size: 0.76rem;
  font-weight: 700;
  white-space: nowrap;
}

.status-chip.complete {
  background: #dcfce7;
  color: #166534;
}

.status-chip.incomplete {
  background: #f1f5f9;
  color: #334155;
}

.status-chip.pending_validate {
  background: #ffedd5;
  color: #9a3412;
}

.occupancy-box {
  display: grid;
  gap: 0.32rem;
}

.occupancy-meta {
  display: flex;
  justify-content: space-between;
  gap: 0.6rem;
  font-size: 0.85rem;
}

.bar {
  width: 100%;
  height: 10px;
  border-radius: 999px;
  background: #e2e8f0;
  overflow: hidden;
}

.bar-fill {
  height: 100%;
  background: var(--team-color);
}

.pending-note {
  border: 1px solid #fed7aa;
  background: #fff7ed;
  color: #9a3412;
  border-radius: 8px;
  padding: 0.42rem 0.55rem;
  font-size: 0.82rem;
  font-weight: 600;
}

.member-list {
  list-style: none;
  display: grid;
  gap: 0.42rem;
}

.member-list li {
  border: 1px solid var(--border);
  border-radius: 10px;
  background: #f8fafc;
  padding: 0.4rem 0.48rem;
  display: grid;
  grid-template-columns: auto 1fr auto auto auto;
  gap: 0.45rem;
  align-items: center;
}

.avatar {
  width: 28px;
  height: 28px;
  border-radius: 999px;
  object-fit: cover;
  border: 1px solid #dbe1ea;
  background: #fff;
}

.member-main {
  display: grid;
  line-height: 1.1;
}

.member-main strong {
  font-size: 0.87rem;
}

.member-main small {
  font-size: 0.74rem;
}

.role-badge {
  border-radius: 999px;
  padding: 0.18rem 0.5rem;
  font-size: 0.75rem;
  font-weight: 700;
  white-space: nowrap;
}

.role-badge.captain {
  background: #dbeafe;
  color: #1d4ed8;
}

.role-badge.co_captain {
  background: #ede9fe;
  color: #6d28d9;
}

.role-badge.player {
  background: #e2e8f0;
  color: #334155;
}

.mini-btn {
  border: 1px solid #93c5fd;
  background: #eff6ff;
  color: #1d4ed8;
  border-radius: 8px;
  padding: 0.24rem 0.45rem;
  font-size: 0.76rem;
  font-weight: 700;
  cursor: pointer;
}

.role-select {
  border: 1px solid #cbd5e1;
  border-radius: 8px;
  padding: 0.2rem 0.35rem;
  font-size: 0.78rem;
  background: #fff;
}

@media (max-width: 620px) {
  .member-list li {
    grid-template-columns: auto 1fr;
    gap: 0.38rem;
  }

  .role-badge,
  .mini-btn,
  .role-select {
    grid-column: 2;
    justify-self: start;
  }
}
</style>