import { computed } from 'vue'
import { usePage } from '@inertiajs/vue3'
export function useRole() {
  const actor = computed(() => usePage().props.platformActor || {})
  const currentRole = computed(() => actor.value.role)
  const user = computed(() => ({...actor.value, initials: (actor.value.name || '').split(' ').map(x=>x[0]).slice(0,2).join(''), roleLabel: {student:'Alumno',professor:'Profesor',admin:'Administración'}[actor.value.role]}))
  return {user,currentUser:actor,currentRole,permissions:computed(()=>actor.value.permissions || []),
    isStudent:computed(()=>currentRole.value==='student'),isProfessor:computed(()=>currentRole.value==='professor'),
    isAdmin:computed(()=>currentRole.value==='admin'),isPlatformAdmin:computed(()=>false),
    hasPermission: (p)=>(actor.value.permissions || []).includes(p)}
}
