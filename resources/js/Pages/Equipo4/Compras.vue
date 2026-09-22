<script setup>
import { ref, computed } from 'vue'
import { useForm, router } from '@inertiajs/vue3'
import Equipo4Layout from '../../Layouts/Equipo4Layout.vue'
import Team4Module from '../../Components/Team4Module.vue'

const props = defineProps({
  orders: { type: Array, default: () => [] },
  suppliersList: { type: Array, default: () => [] },
  productsList: { type: Array, default: () => [] },
  showHistory: { type: Boolean, default: false },
  kpis: { type: Array, default: () => [] },
  pagination: { type: Object, default: () => ({}) },
  filters: { type: Object, default: () => ({}) }
})

const columns = ['Folio', 'Proveedor', 'Estado', 'Total estimado', 'Artículos', 'Entrega esperada']

const statusLabels = {
  BORRADOR: 'Borrador',
  SOLICITADA: 'Solicitada',
  AUTORIZADA: 'Autorizada',
  RECIBIDA_TOTAL: 'Recibida',
  CANCELADA: 'Cancelada'
}

const formattedOrders = computed(() => {
  return props.orders.map(o => ({
    ...o,
    'Folio': o.folio,
    'Proveedor': o.supplier_name,
    'Estado': o.status,
    'Total estimado': '$' + Number(o.total_estimated).toLocaleString('es-MX', { minimumFractionDigits: 2, maximumFractionDigits: 2 }),
    'Artículos': o.items_count + (o.items_count === 1 ? ' artículo' : ' artículos'),
    'Entrega esperada': o.expected_at || '—'
  }))
})

const showModal = ref(false)
const showDeleteModal = ref(false)
const isEditing = ref(false)
const selectedOrder = ref(null)
const loadingDetails = ref(false)

const form = useForm({
  supplier_id: '',
  expected_at: '',
  notes: '',
  status: 'SOLICITADA',
  items: []
})

function addItem() { form.items.push({ product_id: '', quantity: 1, unit_cost: 0 }) }
function removeItem(index) { form.items.splice(index, 1) }
function itemSubtotal(item) { return (item.quantity || 0) * (item.unit_cost || 0) }

const totalEstimate = computed(() => form.items.reduce((sum, i) => sum + itemSubtotal(i), 0))

const openCreateModal = () => {
  isEditing.value = false
  selectedOrder.value = null
  form.reset()
  form.clearErrors()
  form.status = 'SOLICITADA'
  form.items = [{ product_id: '', quantity: 1, unit_cost: 0 }]
  showModal.value = true
}

const openEditModal = async (row) => {
  isEditing.value = true
  selectedOrder.value = row
  form.clearErrors()
  loadingDetails.value = true
  showModal.value = true

  try {
    const response = await fetch(`/equipo4/compras/${row._id}/details`, { headers: { 'Accept': 'application/json' } })
    const data = await response.json()
    form.supplier_id = data.supplier_id
    form.expected_at = data.expected_at
    form.notes = data.notes || ''
    form.status = data.status
    form.items = data.items.map(i => ({ product_id: i.product_id, quantity: i.quantity, unit_cost: i.unit_cost }))
  } catch (e) { console.error(e) } finally { loadingDetails.value = false }
}

const confirmDelete = (row) => { selectedOrder.value = row; showDeleteModal.value = true }

const submitForm = () => {
  const options = {
    preserveScroll: true,
    onSuccess: () => { showModal.value = false; form.reset() },
    onError: (errors) => { console.error('Errores de validación:', errors) },
  }
  if (isEditing.value) {
    form.put(`/equipo4/compras/${selectedOrder.value._id}`, options)
  } else {
    form.post('/equipo4/compras', options)
  }
}

const deleteOrder = () => {
  if (!selectedOrder.value) return
  router.delete(`/equipo4/compras/${selectedOrder.value._id}`, { onSuccess: () => { showDeleteModal.value = false; selectedOrder.value = null } })
}

const authorizeOrder = (row) => {
  if (!confirm(`¿Autorizar la OC ${row.folio}?`)) return
  router.post(`/equipo4/compras/${row._id}/status`, { action: 'autorizar' }, { preserveScroll: true })
}

const cancelOrder = (row) => {
  if (!confirm(`¿Cancelar la OC ${row.folio}?`)) return
  router.post(`/equipo4/compras/${row._id}/status`, { action: 'cancelar' }, { preserveScroll: true })
}

const toggleHistory = () => {
  router.get('/equipo4/compras', { history: props.showHistory ? undefined : '1' }, { preserveScroll: false })
}
</script>

<template>
  <Equipo4Layout>
    <div class="mb-4 flex justify-end">
      <button @click="toggleHistory" class="text-xs font-semibold text-[#0284C7] hover:underline">
        {{ showHistory ? '← Ver OC activas' : 'Ver historial de OC completadas →' }}
      </button>
    </div>

    <Team4Module
      title="Órdenes de Compra"
      :subtitle="showHistory ? 'Historial de OCs completadas y canceladas' : 'OCs activas por autorizar, editar o recibir'"
      :columns="columns"
      :rows="formattedOrders"
      :kpis="kpis"
      :pagination="pagination"
      :filters="filters"
      search-route="/equipo4/compras"
    >
      <template #toolbar>
        <button v-if="!showHistory" @click="openCreateModal" class="inline-flex items-center px-4 py-2 text-sm font-semibold rounded-lg bg-[#00338D] text-white hover:bg-[#0284C7] transition">
          + Nueva OC
        </button>
      </template>

      <template #actions="{ row }">
        <div class="flex items-center justify-end gap-1">
          <template v-if="row.status === 'BORRADOR'">
            <button @click="openEditModal(row)" class="px-2 py-1 text-xs font-medium rounded border border-slate-300 bg-white text-slate-700 hover:bg-slate-100 transition">Editar</button>
            <button @click="authorizeOrder(row)" class="px-2 py-1 text-xs font-medium rounded bg-[#10B981] text-white hover:bg-emerald-700 transition">Autorizar</button>
            <button @click="cancelOrder(row)" class="px-2 py-1 text-xs font-medium rounded bg-amber-600 text-white hover:bg-amber-700 transition">Cancelar</button>
            <button @click="confirmDelete(row)" class="px-2 py-1 text-xs font-medium rounded bg-rose-600 text-white hover:bg-rose-700 transition">Eliminar</button>
          </template>
          <template v-else-if="row.status === 'SOLICITADA'">
            <button @click="openEditModal(row)" class="px-2 py-1 text-xs font-medium rounded border border-slate-300 bg-white text-slate-700 hover:bg-slate-100 transition">Editar</button>
            <button @click="authorizeOrder(row)" class="px-2 py-1 text-xs font-medium rounded bg-[#10B981] text-white hover:bg-emerald-700 transition">Autorizar</button>
            <button @click="cancelOrder(row)" class="px-2 py-1 text-xs font-medium rounded bg-amber-600 text-white hover:bg-amber-700 transition">Cancelar</button>
          </template>
          <template v-else-if="row.status === 'AUTORIZADA'">
            <a href="/equipo4/recepciones" class="px-2 py-1 text-xs font-medium rounded bg-[#00338D] text-white hover:bg-[#0284C7] transition">Recibir</a>
            <button @click="cancelOrder(row)" class="px-2 py-1 text-xs font-medium rounded bg-amber-600 text-white hover:bg-amber-700 transition">Cancelar</button>
          </template>
          <template v-else>
            <span class="text-xs text-slate-400 italic">Sin acciones</span>
          </template>
        </div>
      </template>
    </Team4Module>

    <div v-if="showModal" class="fixed inset-0 z-50 flex items-start justify-center bg-slate-900/50 backdrop-blur-sm p-4 overflow-y-auto">
      <div class="bg-white rounded-2xl shadow-xl border border-slate-200 w-full max-w-4xl p-6 space-y-4 my-8">
        <div class="flex justify-between items-center border-b border-slate-100 pb-3">
          <h2 class="text-lg font-bold text-[#00338D]">{{ isEditing ? 'Editar OC ' + selectedOrder?.folio : 'Nueva Orden de Compra' }}</h2>
          <button @click="showModal = false" class="text-slate-400 hover:text-slate-600 font-bold">&times;</button>
        </div>

        <div v-if="Object.keys(form.errors).length > 0" class="rounded-lg bg-rose-50 border border-rose-200 p-3">
          <p class="text-xs font-bold text-rose-800 mb-1">Errores de validación:</p>
          <ul class="text-xs text-rose-700 list-disc pl-4 space-y-0.5">
            <li v-for="(err, field) in form.errors" :key="field">{{ err }}</li>
          </ul>
        </div>

        <div v-if="loadingDetails" class="text-center py-8 text-slate-500">Cargando...</div>

        <form v-else @submit.prevent="submitForm" class="space-y-4">
          <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
            <div>
              <label class="block text-xs font-semibold uppercase text-slate-600 mb-1">Proveedor *</label>
              <select v-model="form.supplier_id" class="w-full px-3 py-2 text-sm rounded-lg border border-slate-300 focus:border-[#0284C7] bg-white">
                <option value="" disabled>Selecciona un proveedor</option>
                <option v-for="s in suppliersList" :key="s._id" :value="s._id">{{ s.code }} - {{ s.legal_name }}</option>
              </select>
              <p v-if="form.errors.supplier_id" class="mt-1 text-xs text-rose-600">{{ form.errors.supplier_id }}</p>
            </div>
            <div>
              <label class="block text-xs font-semibold uppercase text-slate-600 mb-1">Entrega esperada *</label>
              <input v-model="form.expected_at" type="date" class="w-full px-3 py-2 text-sm rounded-lg border border-slate-300 focus:border-[#0284C7]" />
              <p v-if="form.errors.expected_at" class="mt-1 text-xs text-rose-600">{{ form.errors.expected_at }}</p>
            </div>
            <div>
              <label class="block text-xs font-semibold uppercase text-slate-600 mb-1">Estado *</label>
              <select v-model="form.status" class="w-full px-3 py-2 text-sm rounded-lg border border-slate-300 focus:border-[#0284C7] bg-white">
                <option value="BORRADOR">Borrador</option>
                <option value="SOLICITADA">Solicitada</option>
                <option v-if="isEditing" value="AUTORIZADA">Autorizada</option>
              </select>
            </div>
          </div>

          <div>
            <label class="block text-xs font-semibold uppercase text-slate-600 mb-1">Notas</label>
            <textarea v-model="form.notes" rows="2" class="w-full px-3 py-2 text-sm rounded-lg border border-slate-300 focus:border-[#0284C7]"></textarea>
          </div>

          <div class="border-t border-slate-100 pt-4">
            <div class="flex justify-between items-center mb-2">
              <h3 class="text-sm font-bold text-[#00338D] uppercase tracking-wide">Líneas de la OC</h3>
              <button type="button" @click="addItem" class="text-xs font-semibold text-[#0284C7] hover:underline">+ Añadir línea</button>
            </div>

            <div class="overflow-x-auto rounded-lg border border-slate-200">
              <table class="w-full text-sm">
                <thead class="bg-slate-50 text-xs uppercase text-slate-600">
                  <tr>
                    <th class="px-3 py-2 text-left">Producto</th>
                    <th class="px-3 py-2 text-left w-24">Cantidad</th>
                    <th class="px-3 py-2 text-left w-32">Costo unit.</th>
                    <th class="px-3 py-2 text-left w-32">Subtotal</th>
                    <th class="px-3 py-2 w-12"></th>
                  </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                  <tr v-for="(item, index) in form.items" :key="index">
                    <td class="px-3 py-2">
                      <select v-model="item.product_id" class="w-full px-2 py-1 text-xs rounded border border-slate-300 focus:border-[#0284C7] bg-white">
                        <option value="" disabled>Producto</option>
                        <option v-for="p in productsList" :key="p._id" :value="p._id">{{ p.sku }} - {{ p.name }}</option>
                      </select>
                    </td>
                    <td class="px-3 py-2"><input v-model.number="item.quantity" type="number" min="1" class="w-full px-2 py-1 text-xs rounded border border-slate-300" /></td>
                    <td class="px-3 py-2"><input v-model.number="item.unit_cost" type="number" min="0" step="0.01" class="w-full px-2 py-1 text-xs rounded border border-slate-300" /></td>
                    <td class="px-3 py-2 text-xs font-semibold text-slate-700">${{ itemSubtotal(item).toFixed(2) }}</td>
                    <td class="px-3 py-2 text-center"><button type="button" @click="removeItem(index)" class="text-rose-600 hover:text-rose-800 text-lg font-bold leading-none">&times;</button></td>
                  </tr>
                  <tr v-if="form.items.length === 0"><td colspan="5" class="px-3 py-4 text-center text-xs text-slate-500">Sin líneas.</td></tr>
                </tbody>
                <tfoot class="bg-slate-50 border-t border-slate-200">
                  <tr>
                    <td colspan="3" class="px-3 py-2 text-right text-xs font-bold text-slate-700 uppercase">Total estimado:</td>
                    <td class="px-3 py-2 text-sm font-bold text-[#00338D]">${{ totalEstimate.toFixed(2) }}</td>
                    <td></td>
                  </tr>
                </tfoot>
              </table>
            </div>
            <p v-if="form.errors.items" class="mt-1 text-xs text-rose-600">{{ form.errors.items }}</p>
          </div>

          <div class="flex justify-end gap-2 pt-3 border-t border-slate-100">
            <button type="button" @click="showModal = false" class="px-4 py-2 text-sm font-medium rounded-lg border border-slate-300 bg-white text-slate-700 hover:bg-slate-50 transition">Cancelar</button>
            <button type="submit" :disabled="form.processing" class="px-4 py-2 text-sm font-semibold rounded-lg bg-[#00338D] text-white hover:bg-[#0284C7] transition disabled:opacity-50">
              {{ form.processing ? 'Guardando...' : (isEditing ? 'Actualizar' : 'Crear OC') }}
            </button>
          </div>
        </form>
      </div>
    </div>

    <div v-if="showDeleteModal" class="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/50 backdrop-blur-sm p-4">
      <div class="bg-white rounded-2xl shadow-xl border border-slate-200 w-full max-w-md p-6 space-y-4">
        <h3 class="text-lg font-bold text-slate-900">¿Eliminar Orden de Compra?</h3>
        <p class="text-sm text-slate-600">Vas a eliminar la OC <strong>{{ selectedOrder?.folio }}</strong>.</p>
        <div class="flex justify-end gap-2 pt-2">
          <button @click="showDeleteModal = false" class="px-4 py-2 text-sm font-medium rounded-lg border border-slate-300 bg-white text-slate-700 hover:bg-slate-50 transition">Cancelar</button>
          <button @click="deleteOrder" class="px-4 py-2 text-sm font-semibold rounded-lg bg-rose-600 text-white hover:bg-rose-700 transition">Eliminar</button>
        </div>
      </div>
    </div>
  </Equipo4Layout>
</template>
