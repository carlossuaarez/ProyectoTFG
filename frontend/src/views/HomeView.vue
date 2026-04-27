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

<script setup></script>

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
}
</style>