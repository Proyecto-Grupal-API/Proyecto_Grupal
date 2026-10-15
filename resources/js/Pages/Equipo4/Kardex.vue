<script setup>
import { ref, computed } from 'vue'
import { useForm, router } from '@inertiajs/vue3'
import Equipo4Layout from '../../Layouts/Equipo4Layout.vue'
import Team4Module from '../../Components/Team4Module.vue'

const props = defineProps({
  movements: { type: Array, default: () => [] },
  kpis: { type: Array, default: () => [] },
  pagination: { type: Object, default: () => ({}) },
  filters: { type: Object, default: () => ({}) }
})

const columns = ['Fecha', 'Tipo', 'SKU', 'Producto', 'Cantidad', 'Motivo', 'Referencia']

const typeLabels = {
  RECEIPT: 'Recepción',
  SALE: 'Venta',
  RETURN_IN: 'Devolución entrante',
  RETURN_OUT: 'Devolución a proveedor',
  ADJUSTMENT: 'Ajuste',
  TRANSFER_IN: 'Transferencia entrada',
  TRANSFER_OUT: 'Transferencia salida',
  QUARANTINE: 'Cuarentena',
  SHRINKAGE: 'Merma'
}

const formattedMovements = computed(() => {
  return props.movements.map(m => ({
    ...m,
    'Fecha': m.created_at,
    'Tipo': typeLabels[m.type] || m.type,
    'SKU': m.product_sku,
    'Producto': m.product_name,
    'Cantidad': m.quantity > 0 ? '+' + m.quantity : m.quantity,
    'Motivo': m.reason,
    'Referencia': m.external_reference || '—'
  }))
})

const filterType = ref(props.filters.type || '')

const applyTypeFilter = () => {
  router.get('/equipo4/kardex', {
    q: props.filters.q || undefined,
    type: filterType.value || undefined,
    page: 1
  }, { preserveState: true, preserveScroll: true, replace: true })
}
</script>

<template>
  <Equipo4Layout>
    <Team4Module
      title="Kardex y movimientos"
      subtitle="Entradas, salidas, ventas, devoluciones, ajustes, transferencias y mermas."
      :columns="columns"
      :rows="formattedMovements"
      :kpis="kpis"
      :pagination="pagination"
      :filters="filters"
      search-route="/equipo4/kardex"
    >
      <template #toolbar>
        <select v-model="filterType" @change="applyTypeFilter" class="px-3 py-2 text-sm rounded-lg border border-slate-300 bg-white focus:border-[#0284C7]">
          <option value="">Todos los tipos</option>
          <option value="RECEIPT">Recepción</option>
          <option value="SALE">Venta</option>
          <option value="RETURN_IN">Devolución entrante</option>
          <option value="RETURN_OUT">Devolución a proveedor</option>
          <option value="ADJUSTMENT">Ajuste</option>
          <option value="TRANSFER_IN">Transferencia entrada</option>
          <option value="TRANSFER_OUT">Transferencia salida</option>
          <option value="QUARANTINE">Cuarentena</option>
          <option value="SHRINKAGE">Merma</option>
        </select>
      </template>
    </Team4Module>
  </Equipo4Layout>
</template>
