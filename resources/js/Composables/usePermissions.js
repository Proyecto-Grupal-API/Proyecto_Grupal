import { computed } from 'vue'
import { usePage } from '@inertiajs/vue3'

/**
 * Composable central para verificar permisos del usuario actual.
 *
 * Uso:
 *   import { usePermissions } from '@/Composables/usePermissions'
 *   const { can, hasAnyPermission, role, isAuthorized } = usePermissions()
 *
 *   <button v-if="can('productos.create')">Nuevo Producto</button>
 */
export function usePermissions() {
  const page = usePage()

  const role = computed(() => page.props.inventoryContext?.user?.role ?? '')
  const roleRaw = computed(() => page.props.inventoryContext?.user?.role ?? '')
  const permissions = computed(() => page.props.permissions ?? [])
  const dashboardKpis = computed(() => page.props.dashboardKpis ?? [])
  const isAuthorized = computed(() => Boolean(page.props.inventoryContext?.user))
  const isAdmin = computed(() => role.value === 'admin')

  /**
   * Verifica si el usuario tiene un permiso especifico.
   * El wildcard '*' da acceso a todo.
   */
  function can(permission) {
    if (!permission) return false
    if (permissions.value.includes('*')) return true
    return permissions.value.includes(permission)
  }

  /**
   * Verifica si el usuario tiene AL MENOS UNO de los permisos listados.
   */
  function hasAnyPermission(list) {
    if (!Array.isArray(list) || list.length === 0) return false
    return list.some(p => can(p))
  }

  /**
   * Verifica si el usuario tiene TODOS los permisos listados.
   */
  function hasAllPermissions(list) {
    if (!Array.isArray(list) || list.length === 0) return false
    return list.every(p => can(p))
  }

  /**
   * Verifica si un KPI debe mostrarse en el Dashboard.
   */
  function canSeeKpi(kpiKey) {
    if (dashboardKpis.value.includes('*')) return true
    return dashboardKpis.value.includes(kpiKey)
  }

  return {
    role,
    roleRaw,
    permissions,
    dashboardKpis,
    isAuthorized,
    isAdmin,
    can,
    hasAnyPermission,
    hasAllPermissions,
    canSeeKpi,
  }
}
