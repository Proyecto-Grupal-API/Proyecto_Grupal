<script setup>
import { ref, computed } from 'vue'
import { useForm, router } from '@inertiajs/vue3'
import Equipo4Layout from '../../Layouts/Equipo4Layout.vue'
import Team4Module from '../../Components/Team4Module.vue'

const props = defineProps({
  reservations: { type: Array, default: () => [] },
  productsList: { type: Array, default: () => [] },
  locationsList: { type: Array, default: () => [] },
  showHistory: { type: Boolean, default: false },
  kpis: { type: Array, default: () => [] },
  pagination: { type: Object, default: () => ({}) },
  filters: { type: Object, default: () => ({}) }
})

const columns = ['Referencia', 'SKU', 'Producto', 'Ubicación', 'Cantidad', 'Origen', 'Expira', 'Estado']
const statusLabels = { RESERVED: 'Reservada', CONFIRMED: 'Confirmada', RELEASED: 'Liberada', REJECTED: 'Rechazada' }

const formattedReservations = computed(() => {
  return props.reservations.map(r => ({
    ...r,
    'Referencia': r.external_reference || r.reservation_id.slice(0, 12),
    'SKU': r.product_sku,
    'Producto': r.product_name,
    'Ubicación': r.location_name,
    'Cantidad': r.quantity,
    'Origen': r.source,
    'Expira': r.expires_at || '—',
    'Estado': statusLabels[r.status] || r.status
  }))
})

const showCreateModal = ref(false)
const form = useForm({
  product_id: '',
  location_id: '',
  quantity: 1,
  source: 'MANUAL',
  external_reference: '',
  expires_at: ''
})

const defaultExpiresAt = () => {
  const d = new Date()
  d.setHours(d.getHours() + 24)
  return d.toISOString().slice(0, 16)
}

const openCreateModal = () => {
  form.reset()
  form.clearErrors()
  form.quantity = 1
  form.source = 'MANUAL'
  form.expires_at = defaultExpiresAt()
  if (props.locationsList.length > 0) form.location_id = props.locationsList[0]._id
  showCreateModal.value = true
}

const submitForm = () => {
  form.post('/equipo4/reservas', {
    preserveScroll: true,
    onSuccess: () => { showCreateModal.value = false; form.reset() }
  })
}

const releaseReservation = (row) => {
  const id = row.reservation_id || row._id
  if (!id) { alert('Error: la reserva no tiene identificador.'); return }
  if (!confirm(`¿Liberar la reserva ${row.Referencia}?`)) return
  router.post(`/equipo4/reservas/${id}/release`, {}, { preserveScroll: true })
}

const expireOverdue = () => {
  if (!confirm('¿Liberar todas las reservas vencidas?')) return
  router.post('/equipo4/reservas/expire-overdue', {}, { preserveScroll: true })
}

const toggleHistory = () => {
  router.get('/equipo4/reservas', { history: props.showHistory ? undefined : '1' }, { preserveScroll: false })
}
</script>

<template>
  <Equipo4Layout>
    <div class="mb-4 flex justify-end">
      <button @click="toggleHistory" class="text-xs font-semibold text-[#0284C7] hover:underline">
        {{ showHistory ? '← Ver solo activas' : 'Ver historial →' }}
      </button>
    </div>

    <Team4Module
      title="Reservas de stock"
      :subtitle="showHistory ? 'Historial de reservas' : 'Stock apartado para checkout, pedidos o recompensas'"
      :columns="columns"
      :rows="formattedReservations"
      :kpis="kpis"
      :pagination="pagination"
      :filters="filters"
      search-route="/equipo4/reservas"
    >
      <template #toolbar>
        <button v-if="!showHistory" @click="expireOverdue" class="inline-flex items-center px-3 py-2 text-xs font-semibold rounded-lg border border-[#0284C7] text-[#0284C7] bg-white hover:bg-slate-50 transition">Expirar vencidas</button>
        <button v-if="!showHistory" @click="openCreateModal" class="inline-flex items-center px-4 py-2 text-sm font-semibold rounded-lg bg-[#00338D] text-white hover:bg-[#0284C7] transition">+ Nueva Reserva</button>
      </template>
      <template #actions="{ row }">
        <button v-if="row.status === 'RESERVED'" @click="releaseReservation(row)" class="px-3 py-1 text-xs font-medium rounded bg-amber-600 text-white hover:bg-amber-700 transition">Liberar</button>
        <span v-else class="text-xs text-slate-400 italic">{{ statusLabels[row.status] || row.status }}</span>
      </template>
    </Team4Module>

    <div v-if="showCreateModal" class="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/50 backdrop-blur-sm p-4 overflow-y-auto">
      <div class="bg-white rounded-2xl shadow-xl border border-slate-200 w-full max-w-lg p-6 space-y-4 my-8">
        <div class="flex justify-between items-center border-b border-slate-100 pb-3">
          <h2 class="text-lg font-bold text-[#00338D]">Nueva Reserva</h2>
          <button @click="showCreateModal = false" class="text-slate-400 hover:text-slate-600 font-bold">&times;</button>
        </div>
        <div v-if="Object.keys(form.errors).length > 0" class="rounded-lg bg-rose-50 border border-rose-200 p-3">
          <ul class="text-xs text-rose-700 list-disc pl-4 space-y-0.5">
            <li v-for="(err, field) in form.errors" :key="field">{{ err }}</li>
          </ul>
        </div>
        <form @submit.prevent="submitForm" class="space-y-4">
          <div>
            <label class="block text-xs font-semibold uppercase text-slate-600 mb-1">Producto *</label>
            <select v-model="form.product_id" class="w-full px-3 py-2 text-sm rounded-lg border border-slate-300 focus:border-[#0284C7] bg-white">
              <option value="" disabled>Selecciona un producto</option>
              <option v-for="p in productsList" :key="p._id" :value="p._id">{{ p.sku }} - {{ p.name }}</option>
            </select>
          </div>
          <div>
            <label class="block text-xs font-semibold uppercase text-slate-600 mb-1">Ubicación *</label>
            <select v-model="form.location_id" class="w-full px-3 py-2 text-sm rounded-lg border border-slate-300 focus:border-[#0284C7] bg-white">
              <option value="" disabled>Selecciona una ubicación</option>
              <option v-for="l in locationsList" :key="l._id" :value="l._id">{{ l.code }} - {{ l.name }}</option>
            </select>
          </div>
          <div class="grid grid-cols-2 gap-4">
            <div>
              <label class="block text-xs font-semibold uppercase text-slate-600 mb-1">Cantidad *</label>
              <input v-model.number="form.quantity" type="number" min="1" class="w-full px-3 py-2 text-sm rounded-lg border border-slate-300 focus:border-[#0284C7]" />
            </div>
            <div>
              <label class="block text-xs font-semibold uppercase text-slate-600 mb-1">Origen</label>
              <select v-model="form.source" class="w-full px-3 py-2 text-sm rounded-lg border border-slate-300 focus:border-[#0284C7] bg-white">
                <option value="MANUAL">Manual</option>
                <option value="CHECKOUT">Checkout</option>
                <option value="REWARD">Recompensa</option>
                <option value="CANJE">Canje</option>
                <option value="ORDER">Pedido</option>
              </select>
            </div>
          </div>
          <div>
            <label class="block text-xs font-semibold uppercase text-slate-600 mb-1">Referencia (opcional)</label>
            <input v-model="form.external_reference" type="text" class="w-full px-3 py-2 text-sm rounded-lg border border-slate-300 focus:border-[#0284C7]" />
          </div>
          <div>
            <label class="block text-xs font-semibold uppercase text-slate-600 mb-1">Expira el *</label>
            <input v-model="form.expires_at" type="datetime-local" class="w-full px-3 py-2 text-sm rounded-lg border border-slate-300 focus:border-[#0284C7]" />
          </div>
          <div class="flex justify-end gap-2 pt-3 border-t border-slate-100">
            <button type="button" @click="showCreateModal = false" class="px-4 py-2 text-sm font-medium rounded-lg border border-slate-300 bg-white text-slate-700 hover:bg-slate-50 transition">Cancelar</button>
            <button type="submit" :disabled="form.processing" class="px-4 py-2 text-sm font-semibold rounded-lg bg-[#00338D] text-white hover:bg-[#0284C7] transition disabled:opacity-50">
              {{ form.processing ? 'Guardando...' : 'Crear reserva' }}
            </button>
          </div>
        </form>
      </div>
    </div>
  </Equipo4Layout>
</template>
