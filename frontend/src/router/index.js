import { createRouter, createWebHistory } from 'vue-router'
import HomeView from '../views/HomeView.vue'
import LoginView from '../views/LoginView.vue'
import RegisterView from '../views/RegisterView.vue'
import TournamentsView from '../views/TournamentsView.vue'
import TournamentDetailView from '../views/TournamentDetailView.vue'
import CreateTournamentView from '../views/CreateTournamentView.vue'
import AdminDashboardView from '../views/AdminDashboardView.vue'
import ProfileView from '../views/ProfileView.vue'

function parseJwt(token) {
  try {
    const base64Url = token.split('.')[1]
    const base64 = base64Url.replace(/-/g, '+').replace(/_/g, '/')
    const jsonPayload = decodeURIComponent(
      atob(base64)
        .split('')
        .map((c) => '%' + ('00' + c.charCodeAt(0).toString(16)).slice(-2))
        .join('')
    )
    return JSON.parse(jsonPayload)
  } catch {
    return null
  }
}

function getAuthState() {
  const token = localStorage.getItem('token')
  if (!token) return { isAuthenticated: false, isAdmin: false }

  const payload = parseJwt(token)
  if (!payload?.exp) {
    localStorage.removeItem('token')
    return { isAuthenticated: false, isAdmin: false }
  }

  const isExpired = payload.exp * 1000 <= Date.now()
  if (isExpired) {
    localStorage.removeItem('token')
    return { isAuthenticated: false, isAdmin: false }
  }

  return {
    isAuthenticated: true,
    isAdmin: payload.role === 'admin'
  }
}

const routes = [
  { path: '/', component: HomeView },
  { path: '/login', component: LoginView, meta: { guestOnly: true } },
  { path: '/register', component: RegisterView, meta: { guestOnly: true } },
  { path: '/tournaments', component: TournamentsView },
  { path: '/tournaments/:id', component: TournamentDetailView, props: true },
  { path: '/create-tournament', component: CreateTournamentView, meta: { requiresAuth: true } },
  { path: '/profile', component: ProfileView, meta: { requiresAuth: true } },
  { path: '/admin', component: AdminDashboardView, meta: { requiresAuth: true, requiresAdmin: true } },
  { path: '/:pathMatch(.*)*', redirect: '/' }
]

const router = createRouter({
  history: createWebHistory(),
  routes
})

router.beforeEach((to) => {
  const auth = getAuthState()

  // Evitar que usuarios logueados entren a login/register
  if (to.meta.guestOnly && auth.isAuthenticated) {
    return { path: '/tournaments' }
  }

  // Rutas protegidas
  if (to.meta.requiresAuth && !auth.isAuthenticated) {
    return {
      path: '/login',
      query: { redirect: to.fullPath }
    }
  }

  // Solo admin
  if (to.meta.requiresAdmin && !auth.isAdmin) {
    return { path: '/tournaments' }
  }

  return true
})

export default router