<template>
  <section class="hero">
    <div class="hero-content">
      <span class="eyebrow">Plataforma para organizadores de eventos</span>
      <h1>Crea torneos profesionales de deportes y e-sports</h1>
      <p>
        Gestiona equipos, plazas e inscripciones desde una única web. Diseñado para academias,
        clubes, asociaciones y organizadores independientes.
      </p>

      <div class="hero-actions">
        <router-link to="/tournaments" class="btn btn-primary">Ver torneos</router-link>
        <router-link to="/create-tournament" class="btn btn-secondary">Crear torneo</router-link>
      </div>

      <section class="private-access-card">
        <h3>Entrar con código privado</h3>
        <p>Si te han compartido un código, introdúcelo para abrir directamente ese torneo.</p>

        <form class="private-access-form" @submit.prevent="resolvePrivateCode">
          <input
            v-model.trim="privateCodeInput"
            type="text"
            maxlength="16"
            autocomplete="one-time-code"
            placeholder="Ej: ENUESMM6"
            :disabled="privateCodeLoading"
          />
          <button type="submit" :disabled="privateCodeLoading">
            {{ privateCodeLoading ? 'Buscando...' : 'Acceder' }}
          </button>
        </form>

        <p v-if="privateCodeError" class="private-msg error">{{ privateCodeError }}</p>
      </section>

      <div class="stats-grid">
        <article>
          <strong>+120</strong>
          <span>Torneos creados (demo)</span>
        </article>
        <article>
          <strong>+800</strong>
          <span>Equipos inscritos (demo)</span>
        </article>
        <article>
          <strong>24/7</strong>
          <span>Acceso web desde cualquier dispositivo</span>
        </article>
      </div>
    </div>

    <div class="hero-panel">
      <h3>¿Para quién está pensado?</h3>
      <ul>
        <li>Clubes deportivos locales</li>
        <li>Academias y escuelas</li>
        <li>Ligas amateur de barrio</li>
        <li>Centros de e-sports y gaming bars</li>
      </ul>
      <p class="panel-note">
        Producto con potencial comercial: puede ofrecerse como servicio para gestión de competiciones.
      </p>
    </div>
  </section>

  <section class="features">
    <h2>Funcionalidades clave</h2>
    <div class="feature-grid">
      <article class="feature-card">
        <h3>Gestión unificada</h3>
        <p>Organiza torneos deportivos y e-sports en una misma plataforma.</p>
      </article>
      <article class="feature-card">
        <h3>Control de plazas</h3>
        <p>Define máximo de equipos y evita sobreinscripciones.</p>
      </article>
      <article class="feature-card">
        <h3>Panel de administración</h3>
        <p>Supervisa torneos, revisa datos y elimina eventos desde un dashboard.</p>
      </article>
    </div>
  </section>
</template>

<script setup>
import { ref } from 'vue'
import { useRouter } from 'vue-router'
import api from '../services/api'

const ACCESS_CODE_STORAGE_KEY = 'tourneyhub_private_codes'

const router = useRouter()
const privateCodeInput = ref('')
const privateCodeLoading = ref(false)
const privateCodeError = ref('')

function sanitizeCode(value) {
  return String(value || '').trim().replace(/[^a-zA-Z0-9]/g, '').toUpperCase()
}

function saveAccessCodeForTournament(tournamentId, code) {
  try {
    const raw = sessionStorage.getItem(ACCESS_CODE_STORAGE_KEY)
    const map = raw ? JSON.parse(raw) : {}
    map[String(tournamentId)] = code
    sessionStorage.setItem(ACCESS_CODE_STORAGE_KEY, JSON.stringify(map))
  } catch {}
}

async function resolvePrivateCode() {
  privateCodeError.value = ''

  const code = sanitizeCode(privateCodeInput.value)
  if (!code || code.length < 6) {
    privateCodeError.value = 'Introduce un código privado válido.'
    return
  }

  privateCodeLoading.value = true
  try {
    const res = await api.post('/tournaments/private/resolve', { access_code: code })
    const tournamentId = Number(res?.data?.tournament_id || 0)

    if (!Number.isInteger(tournamentId) || tournamentId <= 0) {
      privateCodeError.value = 'No se pudo resolver el torneo para ese código.'
      return
    }

    saveAccessCodeForTournament(tournamentId, code)
    await router.push(`/tournaments/${tournamentId}`)
  } catch (err) {
    if (err.response?.status === 404) {
      privateCodeError.value = 'Código incorrecto o torneo no disponible.'
    } else if (err.response?.status === 429) {
      privateCodeError.value = 'Demasiados intentos. Espera un momento.'
    } else {
      privateCodeError.value = err.response?.data?.error || 'No se pudo validar el código privado.'
    }
  } finally {
    privateCodeLoading.value = false
  }
}
</script>

<style scoped>
.hero {
  display: grid;
  grid-template-columns: 2fr 1fr;
  gap: 1.25rem;
  margin-bottom: 2rem;
}

.hero-content,
.hero-panel {
  background: var(--surface);
  border: 1px solid var(--border);
  border-radius: var(--radius);
  box-shadow: var(--shadow-sm);
  padding: 1.4rem;
}

.eyebrow {
  display: inline-block;
  font-size: 0.82rem;
  letter-spacing: 0.05em;
  text-transform: uppercase;
  color: #0369a1;
  font-weight: 700;
  margin-bottom: 0.45rem;
}

.hero-content h1 {
  font-size: clamp(1.8rem, 2.8vw, 2.6rem);
  line-height: 1.15;
  margin-bottom: 0.7rem;
}

.hero-content p {
  color: var(--muted);
  max-width: 65ch;
}

.hero-actions {
  margin-top: 1.2rem;
  display: flex;
  gap: 0.75rem;
  flex-wrap: wrap;
}

.btn {
  text-decoration: none;
  border-radius: 10px;
  padding: 0.75rem 1rem;
  font-weight: 700;
}

.btn-primary {
  background: linear-gradient(135deg, #0ea5e9, #06b6d4);
  color: #fff;
}

.btn-secondary {
  background: #f8fafc;
  color: #0f172a;
  border: 1px solid var(--border);
}

.private-access-card {
  margin-top: 1rem;
  border: 1px solid #dbeafe;
  background: #f8fbff;
  border-radius: 12px;
  padding: 0.85rem;
}

.private-access-card h3 {
  margin: 0 0 0.35rem;
  font-size: 1rem;
}

.private-access-card p {
  margin: 0 0 0.55rem;
  color: #475569;
  max-width: none;
}

.private-access-form {
  display: flex;
  gap: 0.55rem;
  flex-wrap: wrap;
}

.private-access-form input {
  flex: 1;
  min-width: 180px;
  border: 1px solid var(--border);
  border-radius: 10px;
  padding: 0.58rem 0.7rem;
  text-transform: uppercase;
}

.private-access-form button {
  border: none;
  border-radius: 10px;
  background: #0ea5e9;
  color: #fff;
  font-weight: 700;
  padding: 0.58rem 0.9rem;
  cursor: pointer;
}

.private-access-form button:disabled {
  opacity: 0.7;
  cursor: not-allowed;
}

.private-msg {
  margin-top: 0.45rem;
  font-size: 0.88rem;
  font-weight: 600;
}

.private-msg.error {
  color: #b91c1c;
}

.stats-grid {
  margin-top: 1.15rem;
  display: grid;
  grid-template-columns: repeat(3, minmax(110px, 1fr));
  gap: 0.7rem;
}

.stats-grid article {
  background: #f8fafc;
  border: 1px solid var(--border);
  border-radius: 10px;
  padding: 0.7rem;
}

.stats-grid strong {
  display: block;
  font-size: 1.1rem;
}

.stats-grid span {
  color: var(--muted);
  font-size: 0.85rem;
}

.hero-panel h3 {
  margin-bottom: 0.75rem;
}

.hero-panel ul {
  list-style: none;
  display: grid;
  gap: 0.45rem;
  margin-bottom: 0.75rem;
}

.hero-panel li {
  background: #f8fafc;
  border: 1px solid var(--border);
  border-radius: 10px;
  padding: 0.55rem 0.7rem;
  color: #0f172a;
}

.panel-note {
  color: #334155;
  font-size: 0.9rem;
}

.features {
  background: var(--surface);
  border: 1px solid var(--border);
  border-radius: var(--radius);
  box-shadow: var(--shadow-sm);
  padding: 1.25rem;
}

.features h2 {
  margin-bottom: 1rem;
}

.feature-grid {
  display: grid;
  gap: 0.9rem;
  grid-template-columns: repeat(3, minmax(0, 1fr));
}

.feature-card {
  background: var(--surface-soft);
  border: 1px solid var(--border);
  border-radius: 12px;
  padding: 0.9rem;
}

.feature-card h3 {
  margin-bottom: 0.45rem;
  font-size: 1rem;
}

.feature-card p {
  color: var(--muted);
  font-size: 0.92rem;
}

@media (max-width: 1024px) {
  .hero {
    grid-template-columns: 1fr;
  }
}

@media (max-width: 768px) {
  .feature-grid {
    grid-template-columns: 1fr;
  }

  .stats-grid {
    grid-template-columns: 1fr;
  }

  .private-access-form {
    flex-direction: column;
  }
}
</style>