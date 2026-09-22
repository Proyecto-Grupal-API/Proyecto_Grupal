<script setup>
import { ref, computed } from 'vue'
import { useForm } from '@inertiajs/vue3'
import Equipo4Layout from '../../Layouts/Equipo4Layout.vue'
import Team4Module from '../../Components/Team4Module.vue'

const props = defineProps({
  products: { type: Array, default: () => [] },
  productsList: { type: Array, default: () => [] },
  kpis: { type: Array, default: () => [] },
  pagination: { type: Object, default: () => ({}) },
  filters: { type: Object, default: () => ({}) }
})

const columns = ['SKU', 'Producto', 'Último costo', 'Costo promedio', 'Precio sugerido', 'Margen', 'Acciones']

const formattedProducts = computed(() => {
  return props.products.map(p => ({
    ...p,
    'SKU': p.sku,
    'Producto': p.name,
    'Último costo': p.last_cost !== null ? '$' + Number(p.last_cost).toFixed(2) : '—',
    'Costo promedio': p.average_cost !== null ? '$' + Number(p.average_cost).toFixed(2) : '—',
    'Precio sugerido': p.suggested_price !== null && p.suggested_price > 0 ? '$' + Number(p.suggested_price).toFixed(2) : '—',
    'Margen': p.suggested_margin ? p.suggested_margin + '%' : '—'
  }))
})

const showCostModal = ref(false)
const showHistoryModal = ref(false)
const selectedProduct = ref(null)
const historyItems = ref([])
const loadingHistory = ref(false)

const costForm = useForm({
  product_id: '',
  cost: 0,
  source: 'MANUAL',
  reference: '',
  notes: '',
  suggested_price: null
})

const openCostModal = (row) => {
  selectedProduct.value = row
  costForm.reset()
  costForm.clearErrors()
  costForm.product_id = row._id
  showCostModal.value = true
}

const submitCost = () => {
  costForm.post('/equipo4/costos', {
    preserveScroll: true,
    onSuccess: () => { showCostModal.value = false; costForm.reset() }
  })
}

const openHistory = async (row) => {
  selectedProduct.value = row
  historyItems.value = []
  loadingHistory.value = true
  showHistoryModal.value = true

  try {
    const response = await fetch(`/equipo4/costos/${row._id}/history`, { headers: { 'Accept': 'application/json' } })
    const data = await response.json()
    historyItems.value = data.history
  } catch (e) { console.error(e) } finally { loadingHistory.value = false }
}
</script>

<template>
  <Equipo4Layout>
    <Team4Module
      title="Costos y precios de referencia"
      subtitle="Historial de costos, costo promedio y márgenes sugeridos"
      :columns="columns"
      :rows="formattedProducts"
      :kpis="kpis"
      :pagination="pagination"
      :filters="filters"
      search-route="/equipo4/costos"
    >
      <template #actions="{ row }">
        <div class="flex items-center justify-end gap-2">
          <button @click="openCostModal(row)" class="px-3 py-1 text-xs font-medium rounded bg-[#00338D] text-white hover:bg-[#0284C7] transition">+ Costo</button>
          <button @click="openHistory(row)" class="px-3 py-1 text-xs font-medium rounded border border-slate-300 bg-white text-slate-700 hover:bg-slate-100 transition">Historial</button>
        </div>
      </template>
    </Team4Module>

    <div v-if="showCostModal" class="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/50 backdrop-blur-sm p-4 overflow-y-auto">
      <div class="bg-white rounded-2xl shadow-xl border border-slate-200 w-full max-w-lg p-6 space-y-4 my-8">
        <div class="flex justify-between items-center border-b border-slate-100 pb-3">
          <h2 class="text-lg font-bold text-[#00338D]">Registrar costo</h2>
          <button @click="showCostModal = false" class="text-slate-400 hover:text-slate-600 font-bold">&times;</button>
        </div>
        <div v-if="Object.keys(costForm.errors).length > 0" class="rounded-lg bg-rose-50 border border-rose-200 p-3">
          <ul class="text-xs text-rose-700 list-disc pl-4 space-y-0.5">
            <li v-for="(err, field) in costForm.errors" :key="field">{{ err }}</li>
          </ul>
        </div>
        <div class="bg-slate-50 rounded-lg p-3 text-sm text-slate-600">
          <p><strong>{{ selectedProduct?.SKU }}</strong> — {{ selectedProduct?.Producto }}</p>
        </div>
        <form @submit.prevent="submitCost" class="space-y-4">
          <div class="grid grid-cols-2 gap-4">
            <div>
              <label class="block text-xs font-semibold uppercase text-slate-600 mb-1">Costo unitario *</label>
              <input v-model.number="costForm.cost" type="number" min="0" step="0.01" class="w-full px-3 py-2 text-sm rounded-lg border border-slate-300" />
            </div>
            <div>
              <label class="block text-xs font-semibold uppercase text-slate-600 mb-1">Precio venta sugerido</label>
              <input v-model.number="costForm.suggested_price" type="number" min="0" step="0.01" class="w-full px-3 py-2 text-sm rounded-lg border border-slate-300" placeholder="Opcional" />
            </div>
          </div>
          <div>
            <label class="block text-xs font-semibold uppercase text-slate-600 mb-1">Fuente</label>
            <select v-model="costForm.source" class="w-full px-3 py-2 text-sm rounded-lg border border-slate-300 bg-white">
              <option value="MANUAL">Manual</option>
              <option value="PURCHASE_ORDER">Orden de compra</option>
              <option value="RECEIPT">Recepción</option>
            </select>
          </div>
          <div>
            <label class="block text-xs font-semibold uppercase text-slate-600 mb-1">Referencia (opcional)</label>
            <input v-model="costForm.reference" type="text" class="w-full px-3 py-2 text-sm rounded-lg border border-slate-300" />
          </div>
          <div>
            <label class="block text-xs font-semibold uppercase text-slate-600 mb-1">Notas</label>
            <textarea v-model="costForm.notes" rows="2" class="w-full px-3 py-2 text-sm rounded-lg border border-slate-300"></textarea>
          </div>
          <div class="flex justify-end gap-2 pt-3 border-t border-slate-100">
            <button type="button" @click="showCostModal = false" class="px-4 py-2 text-sm font-medium rounded-lg border border-slate-300 bg-white text-slate-700 hover:bg-slate-50 transition">Cancelar</button>
            <button type="submit" :disabled="costForm.processing" class="px-4 py-2 text-sm font-semibold rounded-lg bg-[#00338D] text-white hover:bg-[#0284C7] transition disabled:opacity-50">
              {{ costForm.processing ? 'Guardando...' : 'Registrar costo' }}
            </button>
          </div>
        </form>
      </div>
    </div>

    <div v-if="showHistoryModal" class="fixed inset-0 z-50 flex items-start justify-center bg-slate-900/50 backdrop-blur-sm p-4 overflow-y-auto">
      <div class="bg-white rounded-2xl shadow-xl border border-slate-200 w-full max-w-2xl p-6 space-y-4 my-8">
        <div class="flex justify-between items-center border-b border-slate-100 pb-3">
          <h2 class="text-lg font-bold text-[#00338D]">Historial — {{ selectedProduct?.SKU }}</h2>
          <button @click="showHistoryModal = false" class="text-slate-400 hover:text-slate-600 font-bold">&times;</button>
        </div>
        <div v-if="loadingHistory" class="text-center py-8 text-slate-500">Cargando...</div>
        <div v-else class="rounded-lg border border-slate-200 overflow-hidden max-h-96 overflow-y-auto">
          <table class="w-full text-sm">
            <thead class="bg-slate-50 text-xs uppercase text-slate-600 sticky top-0">
              <tr>
                <th class="px-3 py-2 text-left">Fecha</th>
                <th class="px-3 py-2 text-right">Costo</th>
                <th class="px-3 py-2 text-left">Fuente</th>
                <th class="px-3 py-2 text-left">Referencia</th>
                <th class="px-3 py-2 text-left">Notas</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
              <tr v-for="h in historyItems" :key="h._id">
                <td class="px-3 py-2 text-xs">{{ h.effective_at }}</td>
                <td class="px-3 py-2 text-right font-semibold">${{ Number(h.cost).toFixed(2) }}</td>
                <td class="px-3 py-2 text-xs">{{ h.source }}</td>
                <td class="px-3 py-2 text-xs">{{ h.reference || '—' }}</td>
                <td class="px-3 py-2 text-xs text-slate-500">{{ h.notes || '—' }}</td>
              </tr>
              <tr v-if="historyItems.length === 0">
                <td colspan="5" class="px-3 py-4 text-center text-xs text-slate-500">Sin costos registrados.</td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>
    </div>
  </Equipo4Layout>
</template>
