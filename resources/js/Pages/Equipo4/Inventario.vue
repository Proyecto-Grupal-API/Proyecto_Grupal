<script setup>
import { ref, computed } from 'vue'
import { useForm } from '@inertiajs/vue3'
import Equipo4Layout from '../../Layouts/Equipo4Layout.vue'
import Team4Module from '../../Components/Team4Module.vue'
import { usePermissions } from '@/Composables/usePermissions'

const props = defineProps({
  inventory: { type: Array, default: () => [] },
  productsList: { type: Array, default: () => [] },
  locationsList: { type: Array, default: () => [] },
  kpis: { type: Array, default: () => [] },
  pagination: { type: Object, default: () => ({}) },
  filters: { type: Object, default: () => ({}) }
})

const { can } = usePermissions()
const canAdjust = computed(() => can('inventario.adjust'))
const canTransfer = computed(() => can('inventario.transfer'))

const columns = ['SKU', 'Producto', 'Almacén', 'Ubicación', 'Existencia', 'Reservado', 'Disponible', 'Estado']

const formattedInventory = computed(() => {
  return props.inventory.map(i => ({
    ...i,
    _id: String(i._id || ''),
    SKU: i.sku,
    Producto: i.product_name,
    'Almacén': i.warehouse_name,
    'Ubicación': i.location_name,
    'Existencia': i.on_hand,
    'Reservado': i.reserved,
    'Disponible': i.available,
    'Estado': ({ AVAILABLE: 'Disponible', RESERVED: 'Sin disponibilidad (todo reservado)', OUT_OF_STOCK: 'Agotado' })[i.status] || i.status
  }))
})

const showModal = ref(false)
const selectedItem = ref(null)

const form = useForm({ inventory_id: '', quantity: 0, reason: '' })

const openAdjustModal = (row) => {
  if (!canAdjust.value) return
  if (!row._id) {
    alert('ERROR: el registro no tiene _id.')
    return
  }
  selectedItem.value = row
  form.reset()
  form.clearErrors()
  form.inventory_id = row._id
  form.quantity = 0
  showModal.value = true
}

// Transferencia entre ubicaciones del mismo negocio
const showTransferModal = ref(false)
const transferForm = useForm({ inventory_id: '', to_location_id: '', quantity: 1, reason: '' })
const transferDestinations = computed(() => props.locationsList.filter(l => l._id !== selectedItem.value?.location_id))

const openTransferModal = (row) => {
  if (!canTransfer.value || !row._id) return
  selectedItem.value = row
  transferForm.reset()
  transferForm.clearErrors()
  transferForm.inventory_id = row._id
  showTransferModal.value = true
}

const submitTransfer = () => {
  if (!transferForm.inventory_id) return
  transferForm.post('/equipo4/inventario/transfer', {
    preserveScroll: true,
    onSuccess: () => { showTransferModal.value = false; transferForm.reset() },
  })
}

const submitForm = () => {
  if (!form.inventory_id) return
  form.post('/equipo4/inventario/adjust', {
    preserveScroll: true,
    onSuccess: () => { showModal.value = false; form.reset() }, onError: (errs) => { console.error("[MODAL ERROR]", errs); window.scrollTo({top:0,behavior:"smooth"}) },
    onError: (e) => { console.error('Inventario error:', e); alert('No se pudo ajustar: ' + JSON.stringify(e)) }
  })
}
</script>

<template>
  <Equipo4Layout>
    <Team4Module
      title="Inventario"
      subtitle="Existencias por producto, almacén y ubicación"
      :columns="columns"
      :rows="formattedInventory"
      :kpis="kpis"
      :pagination="pagination"
      :filters="filters"
      search-route="/equipo4/inventario"
    >
      <template #actions="{ row }">
        <button v-if="canAdjust" @click="openAdjustModal(row)" class="px-3 py-1 text-xs font-medium rounded border border-slate-300 bg-white text-slate-700 hover:bg-slate-100 transition">
          Ajustar
        </button>
        <button v-if="canTransfer" @click="openTransferModal(row)" :disabled="row.available < 1" class="ml-1 px-3 py-1 text-xs font-medium rounded border border-slate-300 bg-white text-slate-700 hover:bg-slate-100 transition disabled:opacity-40 disabled:cursor-not-allowed">
          Transferir
        </button>
        <span v-if="!canAdjust && !canTransfer" class="text-xs text-slate-400 italic">Solo lectura</span>
      </template>
    </Team4Module>

    <div v-if="showModal" class="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/50 backdrop-blur-sm p-4">
      <div class="bg-white rounded-2xl shadow-xl border border-slate-200 w-full max-w-md p-6 space-y-4">
        <div class="flex justify-between items-center border-b border-slate-100 pb-3">
          <h2 class="text-lg font-bold text-[#00338D]">Ajustar inventario</h2>
          <button @click="showModal = false" class="text-slate-400 hover:text-slate-600 font-bold">&times;</button>
        </div>

        <div v-if="Object.keys(form.errors).length > 0" class="rounded-lg bg-rose-50 border border-rose-200 p-3">
          <ul class="text-xs text-rose-700 list-disc pl-4 space-y-0.5">
            <li v-for="(err, field) in form.errors" :key="field">{{ err }}</li>
          </ul>
        </div>

        <div class="text-sm text-slate-600">
          <p><strong>{{ selectedItem?.sku }}</strong> — {{ selectedItem?.product_name }}</p>
          <p class="text-xs text-slate-500 mt-1">
            Existencia actual: {{ selectedItem?.on_hand }} · Reservado: {{ selectedItem?.reserved }} · Disponible: {{ selectedItem?.available }}
          </p>
        </div>

        <form @submit.prevent="submitForm" class="space-y-4">
          <div>
            <label class="block text-xs font-semibold uppercase text-slate-600 mb-1">Cantidad a ajustar *</label>
            <input v-model.number="form.quantity" type="number" placeholder="Positivo suma, negativo resta" class="w-full px-3 py-2 text-sm rounded-lg border border-slate-300 focus:border-[#0284C7]" />
            <p class="mt-1 text-[10px] text-slate-500">Ej: 5 para sumar, -3 para restar</p>
            <p v-if="form.errors.quantity" class="mt-1 text-xs text-rose-600">{{ form.errors.quantity }}</p>
          </div>

          <div>
            <label class="block text-xs font-semibold uppercase text-slate-600 mb-1">Motivo del ajuste *</label>
            <textarea v-model="form.reason" rows="3" class="w-full px-3 py-2 text-sm rounded-lg border border-slate-300 focus:border-[#0284C7]"></textarea>
            <p v-if="form.errors.reason" class="mt-1 text-xs text-rose-600">{{ form.errors.reason }}</p>
          </div>

          <div class="flex justify-end gap-2 pt-3 border-t border-slate-100">
            <button type="button" @click="showModal = false" class="px-4 py-2 text-sm font-medium rounded-lg border border-slate-300 bg-white text-slate-700 hover:bg-slate-50 transition">Cancelar</button>
            <button type="submit" :disabled="form.processing" class="px-4 py-2 text-sm font-semibold rounded-lg bg-[#00338D] text-white hover:bg-[#0284C7] transition disabled:opacity-50">
              {{ form.processing ? 'Aplicando...' : 'Aplicar ajuste' }}
            </button>
          </div>
        </form>
      </div>
    </div>

    <div v-if="showTransferModal" class="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/50 backdrop-blur-sm p-4">
      <div class="bg-white rounded-2xl shadow-xl border border-slate-200 w-full max-w-md p-6 space-y-4" role="dialog" aria-labelledby="transfer-title">
        <div class="flex justify-between items-center border-b border-slate-100 pb-3">
          <h2 id="transfer-title" class="text-lg font-bold text-[#00338D]">Transferir entre ubicaciones</h2>
          <button @click="showTransferModal = false" class="text-slate-400 hover:text-slate-600 font-bold" aria-label="Cerrar">&times;</button>
        </div>

        <div v-if="Object.keys(transferForm.errors).length > 0" class="rounded-lg bg-rose-50 border border-rose-200 p-3" role="alert">
          <ul class="text-xs text-rose-700 list-disc pl-4 space-y-0.5">
            <li v-for="(err, field) in transferForm.errors" :key="field">{{ err }}</li>
          </ul>
        </div>

        <div class="text-sm text-slate-600">
          <p><strong>{{ selectedItem?.sku }}</strong> — {{ selectedItem?.product_name }}</p>
          <p class="text-xs text-slate-500 mt-1">
            Origen: {{ selectedItem?.warehouse_name }} · {{ selectedItem?.location_name }} · Disponible: {{ selectedItem?.available }}
          </p>
        </div>

        <form @submit.prevent="submitTransfer" class="space-y-4">
          <div>
            <label for="transfer-destination" class="block text-xs font-semibold uppercase text-slate-600 mb-1">Ubicación de destino *</label>
            <select id="transfer-destination" v-model="transferForm.to_location_id" class="w-full px-3 py-2 text-sm rounded-lg border border-slate-300 bg-white">
              <option value="" disabled>Selecciona la ubicación de destino</option>
              <option v-for="l in transferDestinations" :key="l._id" :value="l._id">{{ l.warehouse_name }} · {{ l.name }}</option>
            </select>
          </div>
          <div>
            <label for="transfer-quantity" class="block text-xs font-semibold uppercase text-slate-600 mb-1">Cantidad a transferir *</label>
            <input id="transfer-quantity" v-model.number="transferForm.quantity" type="number" min="1" :max="selectedItem?.available" class="w-full px-3 py-2 text-sm rounded-lg border border-slate-300 focus:border-[#0284C7]" />
          </div>
          <div>
            <label for="transfer-reason" class="block text-xs font-semibold uppercase text-slate-600 mb-1">Motivo de la transferencia *</label>
            <textarea id="transfer-reason" v-model="transferForm.reason" rows="2" class="w-full px-3 py-2 text-sm rounded-lg border border-slate-300 focus:border-[#0284C7]"></textarea>
          </div>
          <div class="flex justify-end gap-2 pt-3 border-t border-slate-100">
            <button type="button" @click="showTransferModal = false" class="px-4 py-2 text-sm font-medium rounded-lg border border-slate-300 bg-white text-slate-700 hover:bg-slate-50 transition">Cancelar</button>
            <button type="submit" :disabled="transferForm.processing" class="px-4 py-2 text-sm font-semibold rounded-lg bg-[#00338D] text-white hover:bg-[#0284C7] transition disabled:opacity-50">
              {{ transferForm.processing ? 'Transfiriendo...' : 'Aplicar transferencia' }}
            </button>
          </div>
        </form>
      </div>
    </div>
  </Equipo4Layout>
</template>
