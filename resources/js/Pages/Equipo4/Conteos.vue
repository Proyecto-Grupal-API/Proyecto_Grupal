<script setup>
import { ref, computed } from 'vue'
import { useForm, router } from '@inertiajs/vue3'
import Equipo4Layout from '../../Layouts/Equipo4Layout.vue'
import Team4Module from '../../Components/Team4Module.vue'

const props = defineProps({
  counts: { type: Array, default: () => [] },
  warehousesList: { type: Array, default: () => [] },
  kpis: { type: Array, default: () => [] },
  pagination: { type: Object, default: () => ({}) },
  filters: { type: Object, default: () => ({}) }
})

const columns = ['Folio', 'Almacén', 'Estado', 'Responsable', 'Diferencias', 'Notas']

const statusLabels = { DRAFT: 'Borrador', CLOSED: 'Cerrado' }

const formattedCounts = computed(() => {
  return props.counts.map(c => ({
    ...c,
    'Folio': c.folio,
    'Almacén': c.warehouse_name,
    'Estado': statusLabels[c.status] || c.status,
    'Responsable': c.started_by,
    'Diferencias': c.differences_count,
    'Notas': c.notes || '—'
  }))
})

const showCreateModal = ref(false)
const showCaptureModal = ref(false)
const showDeleteModal = ref(false)
const selectedCount = ref(null)
const captureItems = ref([])
const loadingCapture = ref(false)

const createForm = useForm({ warehouse_id: '', notes: '' })

const openCreateModal = () => {
  createForm.reset()
  createForm.clearErrors()
  if (props.warehousesList.length > 0) createForm.warehouse_id = props.warehousesList[0]._id
  showCreateModal.value = true
}

const submitCreate = () => {
  createForm.post('/equipo4/conteos', {
    preserveScroll: true,
    onSuccess: () => { showCreateModal.value = false; createForm.reset() }
  })
}

const openCaptureModal = async (row) => {
  selectedCount.value = row
  captureItems.value = []
  loadingCapture.value = true
  showCaptureModal.value = true

  try {
    const response = await fetch(`/equipo4/conteos/${row._id}/details`, { headers: { 'Accept': 'application/json' } })
    const data = await response.json()
    captureItems.value = data.items.map(i => ({ ...i, counted_qty_input: i.counted_qty || 0 }))
  } catch (e) { console.error(e) } finally { loadingCapture.value = false }
}

const saveCapture = () => {
  if (!selectedCount.value) return
  const items = captureItems.value.map(i => ({ item_id: i._id, counted_qty: Number(i.counted_qty_input) || 0 }))
  router.post(`/equipo4/conteos/${selectedCount.value._id}/capture`, { items }, {
    preserveScroll: true,
    onSuccess: () => { showCaptureModal.value = false; selectedCount.value = null }
  })
}

const closeCount = () => {
  if (!selectedCount.value) return
  const alerts = captureItems.value.filter(i => hasAlert(i) !== null).length
  let msg = `¿Cerrar el conteo ${selectedCount.value.folio} y aplicar los ajustes?`
  if (alerts > 0) msg = `⚠️ Hay ${alerts} diferencia(s) sospechosa(s). ¿Seguro que quieres cerrar?`
  if (!confirm(msg)) return
  router.post(`/equipo4/conteos/${selectedCount.value._id}/close`, {}, {
    preserveScroll: true,
    onSuccess: () => { showCaptureModal.value = false; selectedCount.value = null }
  })
}

const confirmDelete = (row) => { selectedCount.value = row; showDeleteModal.value = true }
const deleteCount = () => {
  if (!selectedCount.value) return
  router.delete(`/equipo4/conteos/${selectedCount.value._id}`, {
    preserveScroll: true,
    onSuccess: () => { showDeleteModal.value = false; selectedCount.value = null }
  })
}

const calculatedDifference = (item) => (Number(item.counted_qty_input) || 0) - item.expected_qty

const hasAlert = (item) => {
  const counted = Number(item.counted_qty_input) || 0
  const expected = item.expected_qty || 0
  const stockMax = item.stock_max || 0
  const diff = counted - expected
  if (Math.abs(diff) > 500) return 'critical'
  if (expected > 0 && counted > expected * 10) return 'critical'
  if (stockMax > 0 && counted > stockMax) return 'warning'
  return null
}

const alertClass = (item) => {
  const alert = hasAlert(item)
  if (alert === 'critical') return 'bg-rose-50'
  if (alert === 'warning') return 'bg-amber-50'
  return ''
}

const alertText = (item) => {
  const alert = hasAlert(item)
  if (alert === 'critical') return '⚠️ Diferencia sospechosa'
  if (alert === 'warning') return '⚠️ Excede stock máx'
  return ''
}
</script>

<template>
  <Equipo4Layout>
    <Team4Module
      title="Conteos y ajustes"
      subtitle="Inventario físico, conteo cíclico y aplicación de diferencias"
      :columns="columns"
      :rows="formattedCounts"
      :kpis="kpis"
      :pagination="pagination"
      :filters="filters"
      search-route="/equipo4/conteos"
    >
      <template #toolbar>
        <button @click="openCreateModal" class="inline-flex items-center px-4 py-2 text-sm font-semibold rounded-lg bg-[#00338D] text-white hover:bg-[#0284C7] transition">
          + Nuevo Conteo
        </button>
      </template>

      <template #actions="{ row }">
        <div class="flex items-center justify-end gap-2">
          <template v-if="row.status === 'DRAFT'">
            <button @click="openCaptureModal(row)" class="px-3 py-1 text-xs font-medium rounded border border-slate-300 bg-white text-slate-700 hover:bg-slate-100 transition">Capturar</button>
            <button @click="confirmDelete(row)" class="px-3 py-1 text-xs font-medium rounded bg-rose-600 text-white hover:bg-rose-700 transition">Eliminar</button>
          </template>
          <template v-else>
            <span class="text-xs text-slate-400 italic">Cerrado</span>
          </template>
        </div>
      </template>
    </Team4Module>

    <div v-if="showCreateModal" class="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/50 backdrop-blur-sm p-4">
      <div class="bg-white rounded-2xl shadow-xl border border-slate-200 w-full max-w-md p-6 space-y-4">
        <div class="flex justify-between items-center border-b border-slate-100 pb-3">
          <h2 class="text-lg font-bold text-[#00338D]">Nuevo Conteo</h2>
          <button @click="showCreateModal = false" class="text-slate-400 hover:text-slate-600 font-bold">&times;</button>
        </div>
        <div v-if="Object.keys(createForm.errors).length > 0" class="rounded-lg bg-rose-50 border border-rose-200 p-3">
          <ul class="text-xs text-rose-700 list-disc pl-4 space-y-0.5">
            <li v-for="(err, field) in createForm.errors" :key="field">{{ err }}</li>
          </ul>
        </div>
        <form @submit.prevent="submitCreate" class="space-y-4">
          <div>
            <label class="block text-xs font-semibold uppercase text-slate-600 mb-1">Almacén *</label>
            <select v-model="createForm.warehouse_id" class="w-full px-3 py-2 text-sm rounded-lg border border-slate-300 focus:border-[#0284C7] bg-white">
              <option value="" disabled>Selecciona un almacén</option>
              <option v-for="w in warehousesList" :key="w._id" :value="w._id">{{ w.code }} - {{ w.name }}</option>
            </select>
          </div>
          <div>
            <label class="block text-xs font-semibold uppercase text-slate-600 mb-1">Notas</label>
            <textarea v-model="createForm.notes" rows="2" class="w-full px-3 py-2 text-sm rounded-lg border border-slate-300 focus:border-[#0284C7]"></textarea>
          </div>
          <div class="flex justify-end gap-2 pt-3 border-t border-slate-100">
            <button type="button" @click="showCreateModal = false" class="px-4 py-2 text-sm font-medium rounded-lg border border-slate-300 bg-white text-slate-700 hover:bg-slate-50 transition">Cancelar</button>
            <button type="submit" :disabled="createForm.processing" class="px-4 py-2 text-sm font-semibold rounded-lg bg-[#00338D] text-white hover:bg-[#0284C7] transition disabled:opacity-50">
              {{ createForm.processing ? 'Creando...' : 'Crear conteo' }}
            </button>
          </div>
        </form>
      </div>
    </div>

    <div v-if="showCaptureModal" class="fixed inset-0 z-50 flex items-start justify-center bg-slate-900/50 backdrop-blur-sm p-4 overflow-y-auto">
      <div class="bg-white rounded-2xl shadow-xl border border-slate-200 w-full max-w-4xl p-6 space-y-4 my-8">
        <div class="flex justify-between items-center border-b border-slate-100 pb-3">
          <h2 class="text-lg font-bold text-[#00338D]">Capturar Conteo {{ selectedCount?.folio }}</h2>
          <button @click="showCaptureModal = false" class="text-slate-400 hover:text-slate-600 font-bold">&times;</button>
        </div>
        <div v-if="loadingCapture" class="text-center py-8 text-slate-500">Cargando items...</div>
        <div v-else class="space-y-4">
          <div class="rounded-lg border border-slate-200 overflow-hidden">
            <div class="overflow-x-auto max-h-96">
              <table class="w-full text-sm">
                <thead class="bg-slate-50 text-xs uppercase text-slate-600 sticky top-0">
                  <tr>
                    <th class="px-3 py-2 text-left">SKU</th>
                    <th class="px-3 py-2 text-left">Producto</th>
                    <th class="px-3 py-2 text-right w-24">Esperado</th>
                    <th class="px-3 py-2 text-right w-24">Stock máx</th>
                    <th class="px-3 py-2 text-right w-32">Contado</th>
                    <th class="px-3 py-2 text-right w-24">Diferencia</th>
                    <th class="px-3 py-2 text-left w-40">Alerta</th>
                  </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                  <tr v-for="item in captureItems" :key="item._id" :class="alertClass(item)">
                    <td class="px-3 py-2 font-mono text-xs">{{ item.product_sku }}</td>
                    <td class="px-3 py-2">{{ item.product_name }}</td>
                    <td class="px-3 py-2 text-right text-slate-600">{{ item.expected_qty }}</td>
                    <td class="px-3 py-2 text-right text-slate-400 text-xs">{{ item.stock_max }}</td>
                    <td class="px-3 py-2">
                      <input v-model.number="item.counted_qty_input" type="number" min="0" class="w-full px-2 py-1 text-xs rounded border border-slate-300 text-right" />
                    </td>
                    <td class="px-3 py-2 text-right font-semibold" :class="calculatedDifference(item) === 0 ? 'text-emerald-600' : 'text-rose-600'">
                      {{ calculatedDifference(item) > 0 ? '+' : '' }}{{ calculatedDifference(item) }}
                    </td>
                    <td class="px-3 py-2 text-xs">
                      <span v-if="alertText(item)" :class="hasAlert(item) === 'critical' ? 'text-rose-700 font-bold' : 'text-amber-700 font-semibold'">
                        {{ alertText(item) }}
                      </span>
                    </td>
                  </tr>
                  <tr v-if="captureItems.length === 0"><td colspan="7" class="px-3 py-4 text-center text-xs text-slate-500">Sin items.</td></tr>
                </tbody>
              </table>
            </div>
          </div>
          <div class="flex justify-end gap-2 pt-3 border-t border-slate-100">
            <button type="button" @click="showCaptureModal = false" class="px-4 py-2 text-sm font-medium rounded-lg border border-slate-300 bg-white text-slate-700 hover:bg-slate-50 transition">Cancelar</button>
            <button type="button" @click="saveCapture" class="px-4 py-2 text-sm font-semibold rounded-lg border border-[#0284C7] text-[#0284C7] bg-white hover:bg-slate-50 transition">Guardar captura</button>
            <button type="button" @click="closeCount" class="px-4 py-2 text-sm font-semibold rounded-lg bg-[#00338D] text-white hover:bg-[#0284C7] transition">Cerrar y aplicar</button>
          </div>
        </div>
      </div>
    </div>

    <div v-if="showDeleteModal" class="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/50 backdrop-blur-sm p-4">
      <div class="bg-white rounded-2xl shadow-xl border border-slate-200 w-full max-w-md p-6 space-y-4">
        <h3 class="text-lg font-bold text-slate-900">¿Eliminar conteo?</h3>
        <p class="text-sm text-slate-600">Vas a eliminar el conteo <strong>{{ selectedCount?.folio }}</strong>.</p>
        <div class="flex justify-end gap-2 pt-2">
          <button @click="showDeleteModal = false" class="px-4 py-2 text-sm font-medium rounded-lg border border-slate-300 bg-white text-slate-700 hover:bg-slate-50 transition">Cancelar</button>
          <button @click="deleteCount" class="px-4 py-2 text-sm font-semibold rounded-lg bg-rose-600 text-white hover:bg-rose-700 transition">Eliminar</button>
        </div>
      </div>
    </div>
  </Equipo4Layout>
</template>
