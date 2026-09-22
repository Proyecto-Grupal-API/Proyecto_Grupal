<script setup>
import { ref, computed } from 'vue'
import { useForm } from '@inertiajs/vue3'
import Equipo4Layout from '../../Layouts/Equipo4Layout.vue'
import Team4Module from '../../Components/Team4Module.vue'

const props = defineProps({
  returns: { type: Array, default: () => [] },
  suppliersList: { type: Array, default: () => [] },
  productsList: { type: Array, default: () => [] },
  locationsList: { type: Array, default: () => [] },
  inventoryMap: { type: Object, default: () => ({}) },
  kpis: { type: Array, default: () => [] },
  pagination: { type: Object, default: () => ({}) },
  filters: { type: Object, default: () => ({}) }
})

const columns = ['Folio', 'Tipo', 'Referencia', 'Motivo', 'Resolución', 'Tercero', 'Estado']

const formattedReturns = computed(() => {
  return props.returns.map(r => ({
    ...r,
    'Folio': r.folio,
    'Tipo': r.type,
    'Referencia': r.reference || '—',
    'Motivo': r.reason || '—',
    'Resolución': r.resolution || '—',
    'Tercero': r.third_party || '—',
    'Estado': r.status
  }))
})

function availableLocationsFor(productId) {
  if (!productId || !props.inventoryMap[productId]) return []
  const stockMap = props.inventoryMap[productId]
  return props.locationsList
    .filter(loc => stockMap[loc._id] !== undefined && stockMap[loc._id] > 0)
    .map(loc => ({ ...loc, available: stockMap[loc._id] }))
}

const showSupplierModal = ref(false)
const showCustomerModal = ref(false)

const supplierForm = useForm({
  supplier_id: '',
  product_id: '',
  location_id: '',
  quantity: 1,
  reason: '',
  reference: ''
})

const customerForm = useForm({
  product_id: '',
  location_id: '',
  quantity: 1,
  resolution: 'RESTOCK',
  reason: '',
  reference: ''
})

const supplierAvailableLocations = computed(() => availableLocationsFor(supplierForm.product_id))
const customerAvailableLocations = computed(() => availableLocationsFor(customerForm.product_id))

const openSupplierModal = () => {
  supplierForm.reset()
  supplierForm.clearErrors()
  showSupplierModal.value = true
}

const openCustomerModal = () => {
  customerForm.reset()
  customerForm.clearErrors()
  showCustomerModal.value = true
}

const submitSupplier = () => {
  supplierForm.post('/equipo4/devoluciones/proveedor', {
    preserveScroll: true,
    onSuccess: () => { showSupplierModal.value = false; supplierForm.reset() }
  })
}

const submitCustomer = () => {
  customerForm.post('/equipo4/devoluciones/cliente', {
    preserveScroll: true,
    onSuccess: () => { showCustomerModal.value = false; customerForm.reset() }
  })
}

function onSupplierProductChange() { supplierForm.location_id = '' }
function onCustomerProductChange() { customerForm.location_id = '' }
</script>

<template>
  <Equipo4Layout>
    <Team4Module
      title="Devoluciones"
      subtitle="Devoluciones a proveedores y devoluciones de clientes"
      :columns="columns"
      :rows="formattedReturns"
      :kpis="kpis"
      :pagination="pagination"
      :filters="filters"
      search-route="/equipo4/devoluciones"
    >
      <template #toolbar>
        <button @click="openSupplierModal" class="inline-flex items-center px-3 py-2 text-xs font-semibold rounded-lg bg-[#00338D] text-white hover:bg-[#0284C7] transition">
          + A proveedor
        </button>
        <button @click="openCustomerModal" class="inline-flex items-center px-3 py-2 text-xs font-semibold rounded-lg bg-[#10B981] text-white hover:bg-emerald-700 transition">
          + De cliente
        </button>
      </template>
    </Team4Module>

    <!-- Modal Proveedor -->
    <div v-if="showSupplierModal" class="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/50 backdrop-blur-sm p-4 overflow-y-auto">
      <div class="bg-white rounded-2xl shadow-xl border border-slate-200 w-full max-w-lg p-6 space-y-4 my-8">
        <div class="flex justify-between items-center border-b border-slate-100 pb-3">
          <h2 class="text-lg font-bold text-[#00338D]">Devolución a Proveedor</h2>
          <button @click="showSupplierModal = false" class="text-slate-400 hover:text-slate-600 font-bold">&times;</button>
        </div>

        <div v-if="Object.keys(supplierForm.errors).length > 0" class="rounded-lg bg-rose-50 border border-rose-200 p-3">
          <p class="text-xs font-bold text-rose-800 mb-1">Error:</p>
          <ul class="text-xs text-rose-700 list-disc pl-4 space-y-0.5">
            <li v-for="(err, field) in supplierForm.errors" :key="field">{{ err }}</li>
          </ul>
        </div>

        <form @submit.prevent="submitSupplier" class="space-y-4">
          <div>
            <label class="block text-xs font-semibold uppercase text-slate-600 mb-1">Proveedor</label>
            <select v-model="supplierForm.supplier_id" class="w-full px-3 py-2 text-sm rounded-lg border border-slate-300 focus:border-[#0284C7] bg-white">
              <option value="">Sin especificar</option>
              <option v-for="s in suppliersList" :key="s._id" :value="s._id">{{ s.code }} - {{ s.legal_name }}</option>
            </select>
          </div>

          <div>
            <label class="block text-xs font-semibold uppercase text-slate-600 mb-1">Producto *</label>
            <select v-model="supplierForm.product_id" @change="onSupplierProductChange" class="w-full px-3 py-2 text-sm rounded-lg border border-slate-300 focus:border-[#0284C7] bg-white">
              <option value="" disabled>Selecciona un producto</option>
              <option v-for="p in productsList" :key="p._id" :value="p._id">{{ p.sku }} - {{ p.name }}</option>
            </select>
            <p v-if="supplierForm.errors.product_id" class="mt-1 text-xs text-rose-600">{{ supplierForm.errors.product_id }}</p>
          </div>

          <div>
            <label class="block text-xs font-semibold uppercase text-slate-600 mb-1">Ubicación (solo con stock disponible) *</label>
            <select v-model="supplierForm.location_id" :disabled="!supplierForm.product_id" class="w-full px-3 py-2 text-sm rounded-lg border border-slate-300 focus:border-[#0284C7] bg-white disabled:bg-slate-50 disabled:text-slate-400">
              <option value="" disabled>Selecciona una ubicación</option>
              <option v-for="l in supplierAvailableLocations" :key="l._id" :value="l._id">{{ l.code }} - {{ l.name }} (disp: {{ l.available }})</option>
            </select>
            <p v-if="supplierForm.product_id && supplierAvailableLocations.length === 0" class="mt-1 text-xs text-amber-600">
              Este producto no tiene stock disponible en ninguna ubicación.
            </p>
            <p v-if="supplierForm.errors.location_id" class="mt-1 text-xs text-rose-600">{{ supplierForm.errors.location_id }}</p>
          </div>

          <div>
            <label class="block text-xs font-semibold uppercase text-slate-600 mb-1">Cantidad *</label>
            <input v-model.number="supplierForm.quantity" type="number" min="1" class="w-full px-3 py-2 text-sm rounded-lg border border-slate-300 focus:border-[#0284C7]" />
            <p v-if="supplierForm.errors.quantity" class="mt-1 text-xs text-rose-600">{{ supplierForm.errors.quantity }}</p>
          </div>

          <div>
            <label class="block text-xs font-semibold uppercase text-slate-600 mb-1">Motivo *</label>
            <textarea v-model="supplierForm.reason" rows="2" placeholder="Ej: Defectuoso, no cumple especificación" class="w-full px-3 py-2 text-sm rounded-lg border border-slate-300 focus:border-[#0284C7]"></textarea>
            <p v-if="supplierForm.errors.reason" class="mt-1 text-xs text-rose-600">{{ supplierForm.errors.reason }}</p>
          </div>

          <div>
            <label class="block text-xs font-semibold uppercase text-slate-600 mb-1">Referencia (opcional)</label>
            <input v-model="supplierForm.reference" type="text" placeholder="Ej: OC-00001" class="w-full px-3 py-2 text-sm rounded-lg border border-slate-300 focus:border-[#0284C7]" />
          </div>

          <div class="flex justify-end gap-2 pt-3 border-t border-slate-100">
            <button type="button" @click="showSupplierModal = false" class="px-4 py-2 text-sm font-medium rounded-lg border border-slate-300 bg-white text-slate-700 hover:bg-slate-50 transition">Cancelar</button>
            <button type="submit" :disabled="supplierForm.processing" class="px-4 py-2 text-sm font-semibold rounded-lg bg-[#00338D] text-white hover:bg-[#0284C7] transition disabled:opacity-50">
              {{ supplierForm.processing ? 'Guardando...' : 'Registrar devolución' }}
            </button>
          </div>
        </form>
      </div>
    </div>

    <!-- Modal Cliente -->
    <div v-if="showCustomerModal" class="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/50 backdrop-blur-sm p-4 overflow-y-auto">
      <div class="bg-white rounded-2xl shadow-xl border border-slate-200 w-full max-w-lg p-6 space-y-4 my-8">
        <div class="flex justify-between items-center border-b border-slate-100 pb-3">
          <h2 class="text-lg font-bold text-[#00338D]">Devolución de Cliente</h2>
          <button @click="showCustomerModal = false" class="text-slate-400 hover:text-slate-600 font-bold">&times;</button>
        </div>

        <div v-if="Object.keys(customerForm.errors).length > 0" class="rounded-lg bg-rose-50 border border-rose-200 p-3">
          <p class="text-xs font-bold text-rose-800 mb-1">Error:</p>
          <ul class="text-xs text-rose-700 list-disc pl-4 space-y-0.5">
            <li v-for="(err, field) in customerForm.errors" :key="field">{{ err }}</li>
          </ul>
        </div>

        <form @submit.prevent="submitCustomer" class="space-y-4">
          <div>
            <label class="block text-xs font-semibold uppercase text-slate-600 mb-1">Producto *</label>
            <select v-model="customerForm.product_id" @change="onCustomerProductChange" class="w-full px-3 py-2 text-sm rounded-lg border border-slate-300 focus:border-[#0284C7] bg-white">
              <option value="" disabled>Selecciona un producto</option>
              <option v-for="p in productsList" :key="p._id" :value="p._id">{{ p.sku }} - {{ p.name }}</option>
            </select>
            <p v-if="customerForm.errors.product_id" class="mt-1 text-xs text-rose-600">{{ customerForm.errors.product_id }}</p>
          </div>

          <div>
            <label class="block text-xs font-semibold uppercase text-slate-600 mb-1">
              Ubicación
              <span v-if="customerForm.resolution === 'RESTOCK' || customerForm.resolution === 'QUARANTINE'"> (debe tener stock) *</span>
              <span v-else> (opcional para REPLACE/REFUND)</span>
            </label>
            <select v-model="customerForm.location_id" :disabled="!customerForm.product_id" class="w-full px-3 py-2 text-sm rounded-lg border border-slate-300 focus:border-[#0284C7] bg-white disabled:bg-slate-50 disabled:text-slate-400">
              <option value="" disabled>Selecciona una ubicación</option>
              <option v-for="l in customerAvailableLocations" :key="l._id" :value="l._id">{{ l.code }} - {{ l.name }} (disp: {{ l.available }})</option>
            </select>
            <p v-if="customerForm.product_id && customerAvailableLocations.length === 0" class="mt-1 text-xs text-amber-600">
              Este producto no tiene stock disponible en ninguna ubicación.
            </p>
            <p v-if="customerForm.errors.location_id" class="mt-1 text-xs text-rose-600">{{ customerForm.errors.location_id }}</p>
          </div>

          <div>
            <label class="block text-xs font-semibold uppercase text-slate-600 mb-1">Cantidad *</label>
            <input v-model.number="customerForm.quantity" type="number" min="1" class="w-full px-3 py-2 text-sm rounded-lg border border-slate-300 focus:border-[#0284C7]" />
            <p v-if="customerForm.errors.quantity" class="mt-1 text-xs text-rose-600">{{ customerForm.errors.quantity }}</p>
          </div>

          <div>
            <label class="block text-xs font-semibold uppercase text-slate-600 mb-1">Resolución *</label>
            <select v-model="customerForm.resolution" class="w-full px-3 py-2 text-sm rounded-lg border border-slate-300 focus:border-[#0284C7] bg-white">
              <option value="RESTOCK">Reingreso (vuelve a disponible)</option>
              <option value="QUARANTINE">Cuarentena (no disponible)</option>
              <option value="REPLACE">Reemplazo</option>
              <option value="REFUND">Reembolso</option>
            </select>
          </div>

          <div>
            <label class="block text-xs font-semibold uppercase text-slate-600 mb-1">Motivo *</label>
            <textarea v-model="customerForm.reason" rows="2" placeholder="Ej: Producto dañado, no deseado" class="w-full px-3 py-2 text-sm rounded-lg border border-slate-300 focus:border-[#0284C7]"></textarea>
            <p v-if="customerForm.errors.reason" class="mt-1 text-xs text-rose-600">{{ customerForm.errors.reason }}</p>
          </div>

          <div>
            <label class="block text-xs font-semibold uppercase text-slate-600 mb-1">Referencia de venta (opcional)</label>
            <input v-model="customerForm.reference" type="text" placeholder="Ej: VENTA-00041" class="w-full px-3 py-2 text-sm rounded-lg border border-slate-300 focus:border-[#0284C7]" />
          </div>

          <div class="flex justify-end gap-2 pt-3 border-t border-slate-100">
            <button type="button" @click="showCustomerModal = false" class="px-4 py-2 text-sm font-medium rounded-lg border border-slate-300 bg-white text-slate-700 hover:bg-slate-50 transition">Cancelar</button>
            <button type="submit" :disabled="customerForm.processing" class="px-4 py-2 text-sm font-semibold rounded-lg bg-[#10B981] text-white hover:bg-emerald-700 transition disabled:opacity-50">
              {{ customerForm.processing ? 'Guardando...' : 'Registrar devolución' }}
            </button>
          </div>
        </form>
      </div>
    </div>
  </Equipo4Layout>
</template>
