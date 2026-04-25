import { createRouter, createWebHistory } from 'vue-router'
import HomeView from '../views/HomeView.vue'
import LoginView from '../views/LoginView.vue'
import RegisterView from '../views/RegisterView.vue'
import TournamentsView from '../views/TournamentsView.vue'
import TournamentDetailView from '../views/TournamentDetailView.vue'
import CreateTournamentView from '../views/CreateTournamentView.vue'
import AdminDashboardView from '../views/AdminDashboardView.vue'

const routes = [
  { path: '/', component: HomeView },
  { path: '/login', component: LoginView },
  { path: '/register', component: RegisterView },
  { path: '/tournaments', component: TournamentsView },
  { path: '/tournaments/:id', component: TournamentDetailView, props: true },
  { path: '/create-tournament', component: CreateTournamentView },
  { path: '/admin', component: AdminDashboardView },
]

export default createRouter({
  history: createWebHistory(),
  routes,
})