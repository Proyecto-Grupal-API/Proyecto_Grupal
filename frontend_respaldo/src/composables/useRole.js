// composable: useRole.js
// Gestiona el usuario autenticado, roles y permisos conectados al backend Laravel
import { ref, computed } from 'vue'
import { api, getStoredToken, setStoredToken, getStoredUser, setStoredUser } from '../services/api.js'

const roleDefaults = {
  student: {
    id: 'ALU-2024-001',
    name: 'Carlos Mendoza',
    email: 'alumno@campus.edu',
    role: 'student',
    roleLabel: 'Alumno',
    initials: 'CM',
    points: 1250,
  },
  professor: {
    id: 'DOC-2024-089',
    name: 'Dra. Elena Ramos',
    email: 'profesor@campus.edu',
    role: 'professor',
    roleLabel: 'Profesor',
    initials: 'ER',
    points: 0,
  },
  admin: {
    id: 'ADM-2024-001',
    name: 'Roberto Lozano',
    email: 'admin@campus.edu',
    role: 'admin',
    roleLabel: 'Admin Recompensas',
    initials: 'RL',
    points: 0,
  },
  platform_admin: {
    id: 'ADM-SYS-001',
    name: 'Admin Plataforma',
    email: 'platform.admin@campus.edu',
    role: 'platform_admin',
    roleLabel: 'Super Admin',
    initials: 'SA',
    points: 0,
  },
}

const currentRole = ref(localStorage.getItem('campus_role') || 'student')
const currentUser = ref(getStoredUser() || roleDefaults[currentRole.value] || roleDefaults.student)
const isLoadingAuth = ref(false)

export function useRole() {
  const user = computed(() => {
    const u = currentUser.value || roleDefaults[currentRole.value] || roleDefaults.student
    return {
      ...u,
      roleLabel: u.roleLabel || (u.role === 'student' ? 'Alumno' : u.role === 'professor' ? 'Profesor' : 'Admin'),
      initials: u.initials || (u.name ? u.name.split(' ').map(n => n[0]).join('').slice(0, 2).toUpperCase() : 'CD'),
    }
  })

  const isStudent       = computed(() => currentRole.value === 'student')
  const isProfessor     = computed(() => currentRole.value === 'professor')
  const isAdmin         = computed(() => ['admin', 'platform_admin'].includes(currentRole.value))
  const isPlatformAdmin = computed(() => currentRole.value === 'platform_admin')

  const permissions = computed(() => currentUser.value?.permissions || [])

  function hasPermission(perm) {
    if (isPlatformAdmin.value) return true
    return permissions.value.includes(perm)
  }

  async function setRole(role) {
    if (!['student', 'professor', 'admin', 'platform_admin'].includes(role)) return
    currentRole.value = role
    localStorage.setItem('campus_role', role)

    try {
      isLoadingAuth.value = true
      const res = await api.auth.switchDemoUser(role)
      if (res && res.token) {
        setStoredToken(res.token)
        currentUser.value = {
          ...res.user,
          role: role,
        }
        setStoredUser(currentUser.value)
      }
    } catch (err) {
      console.warn('Backend switchDemoUser failed, falling back to local demo state:', err)
      currentUser.value = roleDefaults[role] || roleDefaults.student
      setStoredUser(currentUser.value)
    } finally {
      isLoadingAuth.value = false
    }
  }

  async function initAuth() {
    const token = getStoredToken()
    if (!token) {
      // Auto-iniciar sesión con el rol por defecto
      await setRole(currentRole.value)
    } else {
      try {
        const meRes = await api.auth.me()
        if (meRes && meRes.user) {
          currentUser.value = meRes.user
          currentRole.value = meRes.user.role
          localStorage.setItem('campus_role', meRes.user.role)
          setStoredUser(currentUser.value)
        }
      } catch {
        // Token inválido o expirado, reiniciar rol
        await setRole(currentRole.value)
      }
    }
  }

  return {
    currentRole,
    currentUser,
    user,
    isStudent,
    isProfessor,
    isAdmin,
    isPlatformAdmin,
    permissions,
    hasPermission,
    setRole,
    initAuth,
    isLoadingAuth,
  }
}
