<script setup>
import { computed } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useRole } from '../../composables/useRole.js'

const route  = useRoute()
const router = useRouter()
const { user, isStudent, isProfessor, isAdmin } = useRole()

// Menús por rol
const studentMenu = [
  { key: 'student-dashboard', label: 'Mis puntos',              icon: '⭐', route: '/recompensas/alumno' },
  { key: 'student-campaigns', label: 'Campañas activas',        icon: '📢', route: '/recompensas/alumno/campanas' },
  { key: 'student-catalog',   label: 'Catálogo de recompensas', icon: '🎁', route: '/recompensas/alumno/catalogo' },
  { key: 'student-redeem',    label: 'Mis canjes',              icon: '🎟', route: '/recompensas/alumno/canjes' },
  { key: 'student-activity',  label: 'Mi actividad',            icon: '🕒', route: '/recompensas/alumno/actividad' },
]

const professorMenu = [
  { key: 'prof-dashboard',   label: 'Mis puntos',          icon: '💰', route: '/recompensas/profesor' },
  { key: 'prof-campaigns',   label: 'Campañas académicas', icon: '📢', route: '/recompensas/profesor/campanas' },
  { key: 'prof-redemptions', label: 'Validar canjes',      icon: '☑',  route: '/recompensas/profesor/validar' },
  { key: 'prof-analytics',   label: 'Analítica docente',   icon: '📊', route: '/recompensas/profesor/analitica' },
  { key: 'prof-history',     label: 'Historial y reportes',icon: '📄', route: '/recompensas/profesor/historial' },
]

const adminMenu = [
  { key: 'admin-dashboard',   label: 'Panel de control',            icon: '📊', route: '/recompensas/admin' },
  { key: 'admin-ledger',      label: '7.1 Ledger global de puntos', icon: '📒', route: '/recompensas/admin/ledger' },
  { key: 'admin-businesses',  label: '7.2 Adhesión de negocios',    icon: '🏢', route: '/recompensas/admin/negocios' },
  { key: 'admin-rules',       label: '7.3 Reglas de acumulación',   icon: '⚙', route: '/recompensas/admin/reglas' },
  { key: 'admin-catalog',     label: '7.4 Catálogo de recompensas', icon: '🎁', route: '/recompensas/admin/catalogo' },
  { key: 'admin-redemptions', label: '7.5 Canje y entrega',         icon: '🎟', route: '/recompensas/admin/canjes' },
  { key: 'admin-governance',  label: '7.6 Gobierno de puntos',      icon: '🛡', route: '/recompensas/admin/gobierno' },
  { key: 'admin-general',     label: '7.7 Administración general',  icon: '🗂', route: '/recompensas/admin/administracion' },
  { key: 'admin-audit',       label: '7.8 Auditoría transversal',   icon: '🔍', route: '/recompensas/admin/auditoria' },
  { key: 'admin-analytics',   label: '7.9 Dashboards y analítica',  icon: '📊', route: '/recompensas/admin/analitica' },
  { key: 'admin-integration', label: '7.10 Integración y obs.',     icon: '🔗', route: '/recompensas/admin/integracion' },
  { key: 'admin-settings',    label: '7.11 Config. sensibles',      icon: '🔒', route: '/recompensas/admin/configuracion' },
]

const menuItems = computed(() => {
  if (isAdmin.value)     return adminMenu
  if (isProfessor.value) return professorMenu
  return studentMenu
})

const sectionLabel = computed(() => {
  if (isAdmin.value)     return 'Vista de administrador'
  if (isProfessor.value) return 'Vista de profesor'
  return 'Vista de alumno'
})

const sectionDesc = computed(() => {
  if (isAdmin.value)     return 'Gestión integral del programa de recompensas.'
  if (isProfessor.value) return 'Incentiva, reconoce y acompaña el crecimiento de tus estudiantes.'
  return 'Explora, gana y canjea recompensas en tu vida universitaria.'
})

const sectionIcon = computed(() => {
  if (isAdmin.value)     return '⚙'
  if (isProfessor.value) return '🎓'
  return '🎓'
})

function isActive(menuRoute) {
  return route.path === menuRoute || (menuRoute !== '/recompensas/alumno' && menuRoute !== '/recompensas/profesor' && menuRoute !== '/recompensas/admin' && route.path.startsWith(menuRoute))
}

function navigate(menuRoute) {
  router.push(menuRoute)
}
</script>

<template>
  <aside class="sidebar">
    <!-- Logo -->
    <div class="sidebar-brand">
      <div class="brand-icon">
        <span>C</span>
      </div>
      <div class="brand-text">
        <div class="brand-name">Campus</div>
        <div class="brand-version">Digital v2.4</div>
      </div>
    </div>

    <!-- Sección -->
    <div class="sidebar-section-label">RECOMPENSAS</div>

    <!-- Menú -->
    <nav class="sidebar-nav">
      <button
        v-for="item in menuItems"
        :key="item.key"
        class="sidebar-item"
        :class="{ 'active': isActive(item.route) }"
        @click="navigate(item.route)"
      >
        <span class="sidebar-icon">{{ item.icon }}</span>
        <span class="sidebar-label">{{ item.label }}</span>
      </button>
    </nav>

    <!-- Footer del sidebar -->
    <div class="sidebar-footer">
      <div class="sidebar-footer-icon">{{ sectionIcon }}</div>
      <div class="sidebar-footer-text">
        <div class="sidebar-footer-title">{{ sectionLabel }}</div>
        <div class="sidebar-footer-desc">{{ sectionDesc }}</div>
      </div>
    </div>
  </aside>
</template>

<style scoped>
.sidebar {
  width: var(--cd-sidebar-width);
  min-height: 100vh;
  background: var(--cd-sidebar-bg);
  display: flex;
  flex-direction: column;
  flex-shrink: 0;
  position: fixed;
  top: 0;
  left: 0;
  bottom: 0;
  z-index: 100;
  overflow-y: auto;
  overflow-x: hidden;
}

/* Brand */
.sidebar-brand {
  display: flex;
  align-items: center;
  gap: 10px;
  padding: 18px 16px 12px;
}

.brand-icon {
  width: 36px;
  height: 36px;
  background: var(--cd-primary);
  border-radius: 8px;
  display: flex;
  align-items: center;
  justify-content: center;
  color: #fff;
  font-size: 18px;
  font-weight: 700;
  flex-shrink: 0;
}

.brand-text { flex: 1; min-width: 0; }
.brand-name { color: #fff; font-size: 15px; font-weight: 700; line-height: 1.2; }
.brand-version { color: var(--cd-sidebar-text); font-size: 11px; }

/* Section label */
.sidebar-section-label {
  padding: 14px 16px 6px;
  font-size: 10px;
  font-weight: 700;
  letter-spacing: 0.08em;
  color: #6b8ab8;
  text-transform: uppercase;
}

/* Nav */
.sidebar-nav {
  display: flex;
  flex-direction: column;
  gap: 2px;
  padding: 0 8px;
  flex: 1;
}

.sidebar-item {
  display: flex;
  align-items: center;
  gap: 10px;
  padding: 9px 12px;
  border-radius: 8px;
  cursor: pointer;
  border: none;
  background: transparent;
  color: var(--cd-sidebar-text);
  font-size: 13px;
  font-family: var(--cd-font);
  text-align: left;
  width: 100%;
  transition: background 0.15s, color 0.15s;
  line-height: 1.3;
}

.sidebar-item:hover:not(.active) {
  background: rgba(255,255,255,0.06);
  color: #d4e3f8;
}

.sidebar-item.active {
  background: var(--cd-primary);
  color: var(--cd-sidebar-text-active);
  font-weight: 600;
}

.sidebar-icon { font-size: 15px; flex-shrink: 0; width: 20px; text-align: center; }
.sidebar-label { flex: 1; line-height: 1.3; }

/* Footer */
.sidebar-footer {
  margin: 16px 8px;
  padding: 14px 12px;
  background: rgba(255,255,255,0.06);
  border-radius: 10px;
  display: flex;
  align-items: flex-start;
  gap: 10px;
}

.sidebar-footer-icon {
  font-size: 20px;
  flex-shrink: 0;
  margin-top: 2px;
}

.sidebar-footer-title {
  font-size: 12px;
  font-weight: 600;
  color: #d4e3f8;
  margin-bottom: 3px;
}

.sidebar-footer-desc {
  font-size: 11px;
  color: #6b8ab8;
  line-height: 1.4;
}
</style>
