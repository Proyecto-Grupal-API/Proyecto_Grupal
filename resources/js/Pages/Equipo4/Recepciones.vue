<script setup>
import { ref, computed } from 'vue'
import { useForm, router } from '@inertiajs/vue3'
import Equipo4Layout from '../../Layouts/Equipo4Layout.vue'
import Team4Module from '../../Components/Team4Module.vue'

const props = defineProps({
  receipts: { type: Array, default: () => [] },
  receivableOrders: { type: Array, default: () => [] },
  kpis: { type: Array, default: () => [] },
  pagination: { type: Object, default: () => ({}) },
  filters: { type: Object, default: () => ({}) }
})

const columns = ['Folio', 'OC Relacionada', 'Recibido por', 'Estado', 'Fecha recepción']

const formattedReceipts = computed(() => {
  return props.receipts.map(r => ({
    ...r,
    'Folio': r.folio,
    'OC Relacionada': r.purchase_order_folio,
    'Recibido por': r.received_by,
    'Estado': r.status,
    'Fecha recepción': r.received_at ? new Date(r.received_at).toLocaleString('es-MX', { dateStyle: 'short', timeStyle: 'short' }) : '—'
  }))
})

const showModal = ref(false)
const loadingDetails = ref(false)
const selectedOrderId = ref('')
const orderDetails = ref(null)
const receiptQuantities = ref({})

const form = useForm({
  purchase_order_id: '',
  notes: '',
  items: []
})

const openCreateModal = async () => {
  form.reset()
  form.clearErrors()
  selectedOrderId.value = ''
  orderDetails.value = null
  receiptQuantities.value = {}
  showModal.value = true
}

const loadOrderDetails = async () => {
  if (!selectedOrderId.value) return
  loadingDetails.value = true
  orderDetails.value = null

  try {
    const response = await fetch(`/equipo4/compras/${selectedOrderId.value}/details`, {
      headers: { 'Accept': 'application/json' }
    })
    const data = await response.json()

    if (!data.items || data.items.length === 0) {
      alert('Esta OC no tiene líneas de productos.')
      loadingDetails.value = false
      return
    }

    orderDetails.value = data
    const quantities = {}
    data.items.forEach(item => { quantities[item.purchase_order_item_id] = item.quantity })
    receiptQuantities.value = quantities
  } catch (e) {
    console.error(e)
    alert('Error al cargar la OC')
  } finally {
    loadingDetails.value = false
  }
}

const submitReceipt = () => {
  form.clearErrors()
  if (!orderDetails.value) { alert('Primero selecciona una OC'); return }

  form.purchase_order_id = selectedOrderId.value
  form.items = orderDetails.value.items
    .map(item => ({
      purchase_order_item_id: item.purchase_order_item_id,
      quantity: Number(receiptQuantities.value[item.purchase_order_item_id]) || 0
    }))
    .filter(i => i.quantity > 0)

  if (form.items.length === 0) {
    alert('Debes ingresar al menos una cantidad mayor a 0')
    return
  }

  form.post('/equipo4/recepciones', {
    preserveScroll: true,
    onSuccess: () => {
      showModal.value = false
      form.reset()
      orderDetails.value = null
      selectedOrderId.value = ''
      receiptQuantities.value = {}
    },
    onError: (errors) => { console.error('Errores:', errors) },
  })
}
</script>

<template>
  <Equipo4Layout>
    <Team4Module
      title="Recepciones"
      subtitle="Recepción de mercancía de órdenes de compra autorizadas"
      :columns="columns"
      :rows="formattedReceipts"
      :kpis="kpis"
      :pagination="pagination"
      :filters="filters"
      search-route="/equipo4/recepciones"
    >
      <template #toolbar>
        <button @click="openCreateModal" class="inline-flex items-center px-4 py-2 text-sm font-semibold rounded-lg bg-[#00338D] text-white hover:bg-[#0284C7] transition">
          + Nueva Recepción
        </button>
      </template>
    </Team4Module>

    <div v-if="showModal" class="fixed inset-0 z-50 flex items-start justify-center bg-slate-900/50 backdrop-blur-sm p-4 overflow-y-auto">
      <div class="bg-white rounded-2xl shadow-xl border border-slate-200 w-full max-w-3xl p-6 space-y-4 my-8">
        <div class="flex justify-between items-center border-b border-slate-100 pb-3">
          <h2 class="text-lg font-bold text-[#00338D]">Registrar Recepción</h2>
          <button @click="showModal = false" class="text-slate-400 hover:text-slate-600 font-bold">&times;</button>
        </div>

        <div v-if="Object.keys(form.errors).length > 0" class="rounded-lg bg-rose-50 border border-rose-200 p-3">
          <ul class="text-xs text-rose-700 list-disc pl-4 space-y-0.5">
            <li v-for="(err, field) in form.errors" :key="field">{{ err }}</li>
          </ul>
        </div>

        <div>
          <label class="block text-xs font-semibold uppercase text-slate-600 mb-1">Orden de Compra *</label>
          <select v-model="selectedOrderId" @change="loadOrderDetails" class="w-full px-3 py-2 text-sm rounded-lg border border-slate-300 focus:border-[#0284C7] bg-white">
            <option value="" disabled>Selecciona una OC autorizada</option>
            <option v-for="o in receivableOrders" :key="o._id" :value="o._id">{{ o.folio }}</option>
          </select>
          <p v-if="receivableOrders.length === 0" class="mt-1 text-xs text-amber-600">
            No hay OCs autorizadas pendientes.
          </p>
        </div>

        <div v-if="loadingDetails" class="text-center py-8 text-slate-500">Cargando líneas...</div>

        <div v-else-if="orderDetails" class="space-y-4">
          <div class="rounded-lg border border-slate-200 overflow-hidden">
            <div class="bg-slate-50 px-4 py-2 text-xs font-bold uppercase text-slate-600">OC {{ orderDetails.folio }}</div>
            <table class="w-full text-sm">
              <thead class="bg-slate-50 text-xs uppercase text-slate-600 border-b border-slate-200">
                <tr>
                  <th class="px-3 py-2 text-left">Producto</th>
                  <th class="px-3 py-2 text-left w-24">Pedido</th>
                  <th class="px-3 py-2 text-left w-32">A recibir</th>
                  <th class="px-3 py-2 text-left w-32">Costo unitario</th>
                </tr>
              </thead>
              <tbody class="divide-y divide-slate-100">
                <tr v-for="item in orderDetails.items" :key="item.purchase_order_item_id">
                  <td class="px-3 py-2">
                    <div class="font-medium text-slate-700">{{ item.product_sku }}</div>
                    <div class="text-xs text-slate-500">{{ item.product_name }}</div>
                  </td>
                  <td class="px-3 py-2 text-slate-700">{{ item.quantity }}</td>
                  <td class="px-3 py-2">
                    <input v-model.number="receiptQuantities[item.purchase_order_item_id]" type="number" min="0" :max="item.quantity" class="w-full px-2 py-1 text-xs rounded border border-slate-300" />
                  </td>
                  <td class="px-3 py-2 text-slate-700">${{ item.unit_cost.toFixed(2) }}</td>
                </tr>
              </tbody>
            </table>
          </div>

          <div>
            <label class="block text-xs font-semibold uppercase text-slate-600 mb-1">Notas</label>
            <textarea v-model="form.notes" rows="2" class="w-full px-3 py-2 text-sm rounded-lg border border-slate-300 focus:border-[#0284C7]"></textarea>
          </div>

          <div class="bg-amber-50 border border-amber-200 rounded-lg p-3 text-xs text-amber-800">
            Al confirmar, el stock se incrementará automáticamente y se registrará el costo de cada producto.
          </div>
        </div>

        <div class="flex justify-end gap-2 pt-3 border-t border-slate-100">
          <button type="button" @click="showModal = false" class="px-4 py-2 text-sm font-medium rounded-lg border border-slate-300 bg-white text-slate-700 hover:bg-slate-50 transition">Cancelar</button>
          <button type="button" @click="submitReceipt" :disabled="!orderDetails || form.processing" class="px-4 py-2 text-sm font-semibold rounded-lg bg-[#00338D] text-white hover:bg-[#0284C7] transition disabled:opacity-50">
            {{ form.processing ? 'Procesando...' : 'Confirmar recepción' }}
          </button>
        </div>
      </div>
    </div>
  </Equipo4Layout>
</template>
