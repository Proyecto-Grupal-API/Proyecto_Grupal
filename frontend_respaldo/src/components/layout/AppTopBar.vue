<script setup>
import { computed } from 'vue'
import { useRouter } from 'vue-router'
import { useRole } from '../../composables/useRole.js'

const router = useRouter()
const { user, currentRole, setRole } = useRole()

const navTabs = [
  { label: 'Inicio',        icon: '🏠' },
  { label: 'Mi Perfil',     icon: '👤' },
  { label: 'Cartera',       icon: '💳' },
  { label: 'Tienda',        icon: '🛍' },
  { label: 'Mis Productos', icon: '📦' },
  { label: 'Servicios',     icon: '🎓' },
  { label: 'Comunidad',     icon: '👥' },
  { label: 'Recompensas',   icon: '🏆', active: true },
]

const avatarColor = computed(() => {
  const colors = { student: '#2878ED', professor: '#16a34a', admin: '#7c3aed' }
  return colors[currentRole.value] || '#2878ED'
})

function goToRewards() {
  if (currentRole.value === 'student')        router.push('/recompensas/alumno')
  else if (currentRole.value === 'professor') router.push('/recompensas/profesor')
  else                                        router.push('/recompensas/admin')
}

async function handleRoleChange(newRole) {
  await setRole(newRole)
  goToRewards()
}
</script>

<template>
  <header class="topbar">
    <!-- Nav tabs -->
    <nav class="topbar-nav">
      <template v-for="tab in navTabs" :key="tab.label">
        <button v-if="tab.active" class="nav-tab nav-tab--active" @click="goToRewards">
          <span class="nav-icon">{{ tab.icon }}</span>
          {{ tab.label }}
        </button>
        <a v-else class="nav-tab" href="#">
          <span class="nav-icon">{{ tab.icon }}</span>
          {{ tab.label }}
        </a>
      </template>
    </nav>

    <!-- Derecha -->
    <div class="topbar-right">
      <!-- Selector de rol (solo desarrollo) -->
      <div class="dev-selector" title="Selector de rol (solo desarrollo)">
        <span class="dev-label">ROL:</span>
        <select :value="currentRole" @change="e => handleRoleChange(e.target.value)" class="role-select">
          <option value="student">Alumno</option>
          <option value="professor">Profesor</option>
          <option value="admin">Admin</option>
          <option value="platform_admin">Super Admin</option>
        </select>
      </div>

      <!-- Campana -->
      <button class="topbar-icon-btn" aria-label="Notificaciones">
        <svg width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
          <path d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9"/>
        </svg>
        <span class="notif-dot"></span>
      </button>

      <!-- Avatar + info -->
      <div class="user-info">
        <div class="user-avatar" :style="{ background: avatarColor }">
          {{ user?.initials }}
        </div>
        <div class="user-details">
          <div class="user-name">{{ user?.name }}</div>
          <div class="user-id">{{ user?.id }}</div>
        </div>
        <span v-if="user?.roleLabel" class="role-badge">{{ user.roleLabel }}</span>
      </div>
    </div>
  </header>
</template>

<style scoped>
.topbar {
  height: var(--cd-topbar-height);
  background: var(--cd-topbar-bg);
  border-bottom: 1px solid var(--cd-border);
  display: flex;
  align-items: center;
  padding: 0 16px 0 24px;
  gap: 8px;
  position: fixed;
  top: 0;
  left: var(--cd-sidebar-width);
  right: 0;
  z-index: 90;
  box-shadow: 0 1px 3px rgba(0,0,0,0.04);
}

.topbar-nav {
  display: flex;
  align-items: center;
  gap: 4px;
  flex: 1;
  overflow-x: auto;
  scrollbar-width: none;
}
.topbar-nav::-webkit-scrollbar { display: none; }

.nav-tab {
  display: inline-flex;
  align-items: center;
  gap: 5px;
  padding: 6px 11px;
  border-radius: 20px;
  background: #f0f3f9;
  border: none;
  color: #4a5568;
  font-size: 12.5px;
  font-weight: 500;
  font-family: var(--cd-font);
  cursor: pointer;
  white-space: nowrap;
  text-decoration: none;
  transition: background 0.15s;
  flex-shrink: 0;
}
.nav-tab:hover { background: #e2e8f5; }
.nav-tab--active { background: var(--cd-primary) !important; color: #fff; }

.nav-icon { font-size: 12px; }

.topbar-right {
  display: flex;
  align-items: center;
  gap: 10px;
  flex-shrink: 0;
}

.dev-selector {
  display: flex;
  align-items: center;
  gap: 4px;
  padding: 3px 8px;
  background: #fef9c3;
  border: 1px solid #fde68a;
  border-radius: 6px;
}
.dev-label { font-size: 9px; font-weight: 700; color: #92400e; text-transform: uppercase; }
.role-select {
  padding: 2px 4px;
  border: none;
  border-radius: 4px;
  font-size: 11px;
  font-family: var(--cd-font);
  color: #92400e;
  background: transparent;
  cursor: pointer;
}

.topbar-icon-btn {
  width: 34px;
  height: 34px;
  border-radius: 50%;
  border: 1px solid var(--cd-border);
  background: #f8fafd;
  display: flex;
  align-items: center;
  justify-content: center;
  cursor: pointer;
  color: #6b7a99;
  position: relative;
  transition: background 0.15s;
}
.topbar-icon-btn:hover { background: #e8f0fd; }
.notif-dot {
  position: absolute;
  top: 5px; right: 5px;
  width: 7px; height: 7px;
  background: #ef4444;
  border-radius: 50%;
  border: 1.5px solid #fff;
}

.user-info {
  display: flex;
  align-items: center;
  gap: 8px;
  cursor: pointer;
}
.user-avatar {
  width: 34px; height: 34px;
  border-radius: 50%;
  color: #fff;
  display: flex; align-items: center; justify-content: center;
  font-size: 12px; font-weight: 700;
  flex-shrink: 0;
}
.user-details { line-height: 1.3; }
.user-name { font-size: 12px; font-weight: 700; color: var(--cd-text-primary); }
.user-id   { font-size: 11px; color: var(--cd-text-muted); }
.role-badge {
  padding: 2px 8px;
  background: #f0f3f9;
  border: 1px solid var(--cd-border);
  border-radius: 12px;
  font-size: 11px;
  font-weight: 500;
  color: var(--cd-text-secondary);
}
</style>
