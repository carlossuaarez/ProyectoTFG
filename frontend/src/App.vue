<template>
  <div id="app">
    <NavBar />
    <ToastHost />

    <main class="main-content">
      <router-view />
    </main>

    <footer class="footer">
      <div class="footer-inner">
        <p>© 2026 TourneyHub · Plataforma de torneos deportivos y e-sports</p>
        <p class="footer-note">Proyecto DAW · Interfaz responsive para desktop, tablet y móvil</p>
      </div>
    </footer>
  </div>
</template>

<script setup>
import NavBar from './components/NavBar.vue'
import ToastHost from './components/ToastHost.vue'
</script>

<style>
:root {
  --bg: #f4f7fb;
  --surface: #ffffff;
  --surface-soft: #f8fafc;
  --text: #0f172a;
  --muted: #64748b;

  --primary: #0f172a;
  --primary-2: #1e293b;
  --accent: #06b6d4;
  --accent-2: #22c55e;
  --warning: #f59e0b;
  --danger: #ef4444;

  --border: #e2e8f0;
  --radius: 14px;

  --shadow-sm: 0 4px 14px rgba(15, 23, 42, 0.08);
  --shadow-md: 0 12px 30px rgba(15, 23, 42, 0.12);
  --shadow-lg: 0 20px 50px rgba(15, 23, 42, 0.16);
}

* {
  box-sizing: border-box;
  margin: 0;
  padding: 0;
}

html,
body,
#app {
  min-height: 100%;
}

body {
  font-family: Inter, system-ui, -apple-system, Segoe UI, Roboto, sans-serif;
  background: radial-gradient(circle at top right, #e6fbff 0%, var(--bg) 35%);
  color: var(--text);
  line-height: 1.5;
}

a {
  color: inherit;
}

button,
input,
select {
  font: inherit;
}

#app {
  display: flex;
  flex-direction: column;
  min-height: 100vh;
}

.main-content {
  width: min(1200px, 100%);
  margin: 0 auto;
  padding: 2rem 1rem 2.25rem;
  flex: 1;
}

.footer {
  background: var(--primary);
  color: #dbeafe;
  border-top: 1px solid rgba(255, 255, 255, 0.08);
  margin-top: auto;
}

.footer-inner {
  width: min(1200px, 100%);
  margin: 0 auto;
  padding: 1.1rem 1rem;
  display: flex;
  flex-wrap: wrap;
  gap: 0.4rem 1rem;
  justify-content: space-between;
  align-items: center;
  font-size: 0.9rem;
}

.footer-note {
  color: #93c5fd;
}

@media (max-width: 768px) {
  .main-content {
    padding-top: 1.5rem;
  }

  .footer-inner {
    flex-direction: column;
    align-items: flex-start;
  }
}
</style>