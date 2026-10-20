import { createRouter, createWebHistory } from 'vue-router'

// Layouts redirect
const routes = [
  // Root → redirige a alumno por defecto
  { path: '/', redirect: '/recompensas/alumno' },
  { path: '/recompensas', redirect: '/recompensas/alumno' },

  // ── ALUMNO ──────────────────────────────────────────────────
  {
    path: '/recompensas/alumno',
    component: () => import('../pages/student/StudentDashboard.vue'),
  },
  {
    path: '/recompensas/alumno/campanas',
    component: () => import('../pages/student/StudentCampaigns.vue'),
  },
  {
    path: '/recompensas/alumno/catalogo',
    component: () => import('../pages/student/StudentCatalog.vue'),
  },
  {
    path: '/recompensas/alumno/canjes',
    component: () => import('../pages/student/StudentRedemptions.vue'),
  },
  {
    path: '/recompensas/alumno/actividad',
    component: () => import('../pages/student/StudentActivity.vue'),
  },

  // ── PROFESOR ────────────────────────────────────────────────
  {
    path: '/recompensas/profesor',
    component: () => import('../pages/professor/ProfessorDashboard.vue'),
  },
  {
    path: '/recompensas/profesor/campanas',
    component: () => import('../pages/professor/ProfessorCampaigns.vue'),
  },
  {
    path: '/recompensas/profesor/validar',
    component: () => import('../pages/professor/ProfessorValidate.vue'),
  },
  {
    path: '/recompensas/profesor/analitica',
    component: () => import('../pages/professor/ProfessorAnalytics.vue'),
  },
  {
    path: '/recompensas/profesor/historial',
    component: () => import('../pages/professor/ProfessorHistory.vue'),
  },

  // ── ADMINISTRADOR ───────────────────────────────────────────
  {
    path: '/recompensas/admin',
    component: () => import('../pages/admin/AdminDashboard.vue'),
  },
  {
    path: '/recompensas/admin/ledger',
    component: () => import('../pages/admin/AdminLedger.vue'),
  },
  {
    path: '/recompensas/admin/negocios',
    component: () => import('../pages/admin/AdminBusinesses.vue'),
  },
  {
    path: '/recompensas/admin/reglas',
    component: () => import('../pages/admin/AdminRules.vue'),
  },
  {
    path: '/recompensas/admin/catalogo',
    component: () => import('../pages/admin/AdminCatalog.vue'),
  },
  {
    path: '/recompensas/admin/canjes',
    component: () => import('../pages/admin/AdminRedemptions.vue'),
  },
  {
    path: '/recompensas/admin/gobierno',
    component: () => import('../pages/admin/AdminGovernance.vue'),
  },
  {
    path: '/recompensas/admin/administracion',
    component: () => import('../pages/admin/AdminGeneral.vue'),
  },
  {
    path: '/recompensas/admin/auditoria',
    component: () => import('../pages/admin/AdminAudit.vue'),
  },
  {
    path: '/recompensas/admin/analitica',
    component: () => import('../pages/admin/AdminAnalytics.vue'),
  },
  {
    path: '/recompensas/admin/integracion',
    component: () => import('../pages/admin/AdminObservability.vue'),
  },
  {
    path: '/recompensas/admin/configuracion',
    component: () => import('../pages/admin/AdminSettings.vue'),
  },

  // Catch-all
  { path: '/:pathMatch(.*)*', redirect: '/recompensas/alumno' },
]

const router = createRouter({
  history: createWebHistory(),
  routes,
  scrollBehavior() {
    return { top: 0 }
  },
})

export default router
