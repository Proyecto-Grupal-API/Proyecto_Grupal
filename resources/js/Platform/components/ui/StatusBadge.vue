<script setup>
defineProps({
  status: { type: String, required: true },
})

const map = {
  'Completado':  { cls: 'success', label: 'Completado' },
  'Entregado':   { cls: 'success', label: 'Entregado' },
  'Validado':    { cls: 'success', label: 'Validado' },
  'Pendiente':   { cls: 'warning', label: 'Pendiente' },
  'Reservado':   { cls: 'info',    label: 'Reservado' },
  'Cancelado':   { cls: 'danger',  label: 'Cancelado' },
  'Revertido':   { cls: 'danger',  label: 'Revertido' },
  'Activo':      { cls: 'success', label: 'Activo' },
  'Activa':      { cls: 'success', label: 'Activa' },
  'Inactivo':    { cls: 'gray',    label: 'Inactivo' },
  'Inactiva':    { cls: 'gray',    label: 'Inactiva' },
  'Suspendido':  { cls: 'danger',  label: 'Suspendido' },
  'Próxima':     { cls: 'purple',  label: 'Próxima' },
  'OK':          { cls: 'success', label: 'OK' },
  'Advertencia': { cls: 'warning', label: 'Advertencia' },
    'Error':       { cls: 'danger',  label: 'Error' },
  'En falla':    { cls: 'danger',  label: 'En falla' },
  'Con fallas':  { cls: 'danger',  label: 'Con fallas' },
  'Rechazada':   { cls: 'danger',  label: 'Rechazada' },
  'Recibiendo':  { cls: 'success', label: 'Recibiendo' },
  'Aprobada':    { cls: 'info',    label: 'Aprobada' },
  'En revisión': { cls: 'warning', label: 'En revisión' },
  'Sin eventos': { cls: 'warning', label: 'Sin eventos' },
}

function getBadge(s) {
  return map[s] || { cls: 'gray', label: s }
}
</script>

<template>
  <span class="badge" :class="`badge-${getBadge(status).cls}`">
    {{ getBadge(status).label }}
  </span>
</template>
