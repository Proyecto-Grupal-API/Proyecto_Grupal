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
