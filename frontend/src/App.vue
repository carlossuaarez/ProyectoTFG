<template>
  <div id="app">
    <NavBar />
    <main class="main-content">
      <router-view />
    </main>
    <footer class="footer">
      <p>© 2026 TourneyHub - Torneos deportivos y e-sports</p>
    </footer>
  </div>
</template>

<script setup>
import NavBar from './components/NavBar.vue'
</script>

<style>
* {
  margin: 0;
  padding: 0;
  box-sizing: border-box;
}
body {
  font-family: 'Inter', sans-serif;
  background: #f0f2f5;
  color: #1a1a2e;
}
#app {
  display: flex;
  flex-direction: column;
  min-height: 100vh;
}
.main-content {
  flex: 1;
  width: 100%;
  max-width: 1200px;
  margin: 0 auto;
  padding: 2rem 1rem;
}
.footer {
  background: #1a1a2e;
  color: #ccc;
  text-align: center;
  padding: 1rem;
  font-size: 0.85rem;
  margin-top: auto;
}
</style>