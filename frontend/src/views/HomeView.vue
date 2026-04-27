<template>
  <section class="hero">
    <div class="hero-content">
      <h1>Organiza y compite en los mejores torneos</h1>
      <p>Deportes y e-sports en una sola plataforma. Crea torneos, inscribe equipos y sigue los resultados en tiempo real.</p>
      <router-link to="/tournaments" class="cta-button">Ver torneos</router-link>
    </div>
    <div class="hero-image">
      <img src="https://i.imgur.com/3QZbQ7R.png" alt="Gaming" style="max-width:100%;" />
    </div>
  </section>
</template>

<script setup>
</script>

<style scoped>
.hero {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 2rem;
  padding: 2rem 0;
}
.hero-content h1 {
  font-size: 2.5rem;
  font-weight: 700;
  color: #1a1a2e;
  margin-bottom: 1rem;
}
.hero-content p {
  font-size: 1.2rem;
  color: #444;
  margin-bottom: 2rem;
}
.cta-button {
  background: linear-gradient(135deg, #f0c040, #e94560);
  color: white;
  padding: 0.8rem 2rem;
  border-radius: 30px;
  text-decoration: none;
  font-weight: 600;
  font-size: 1.1rem;
  display: inline-block;
  transition: transform 0.2s;
}
.cta-button:hover {
  transform: scale(1.05);
}
.hero-image {
  flex: 1;
  text-align: center;
}
@media (max-width: 768px) {
  .hero {
    flex-direction: column;
    text-align: center;
  }
  .hero-content h1 {
    font-size: 2rem;
  }
}
</style>