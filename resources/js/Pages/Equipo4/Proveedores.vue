<script setup>
import { ref, computed } from 'vue'
import { useForm, router } from '@inertiajs/vue3'
import Equipo4Layout from '../../Layouts/Equipo4Layout.vue'
import Team4Module from '../../Components/Team4Module.vue'
import { usePermissions } from '@/Composables/usePermissions'

const props = defineProps({
  suppliers: { type: Array, default: () => [] },
  kpis: { type: Array, default: () => [] },
  pagination: { type: Object, default: () => ({}) },
  filters: { type: Object, default: () => ({}) }
})

const { can } = usePermissions()
const canCreate = computed(() => can('proveedores.create'))
const canUpdate = computed(() => can('proveedores.update'))
const canDelete = computed(() => can('proveedores.delete'))
const hasAnyAction = computed(() => canUpdate.value || canDelete.value)

const columns = computed(() => {
  const base = ['Código', 'Razón social', 'Contacto', 'Email', 'Teléfono', 'Términos', 'Estado']
  return hasAnyAction.value ? [...base, 'Acciones'] : base
})

const showModal = ref(false)
const showDeleteModal = ref(false)
const isEditing = ref(false)
const selectedSupplier = ref(null)

const form = useForm({
  code: '', legal_name: '', trade_name: '', tax_id: '',
  contact_name: '', contact_email: '', contact_phone: '',
  payment_terms: 'CONTADO', notes: '', status: 'ACTIVO'
})

const paymentTermsLabels = { CONTADO: 'Contado', '15_DIAS': '15 días', '30_DIAS': '30 días', '60_DIAS': '60 días' }
const statusLabels = { ACTIVO: 'Activo', INACTIVO: 'Inactivo', SUSPENDIDO: 'Suspendido' }

const formattedSuppliers = computed(() => {
  return props.suppliers.map(s => ({
    ...s,
    _id: String(s._id || ''),
    'Código': s.code,
    'Razón social': s.legal_name,
    'Contacto': s.contact_name,
    'Email': s.contact_email || '—',
    'Teléfono': s.contact_phone || '—',
    'Términos': paymentTermsLabels[s.payment_terms] || s.payment_terms,
    'Estado': statusLabels[s.status] || s.status
  }))
})

const openCreateModal = () => {
  isEditing.value = false
  selectedSupplier.value = null
  form.reset()
  form.clearErrors()
  showModal.value = true
}

const openEditModal = (row) => {
  if (!row._id) return
  isEditing.value = true
  selectedSupplier.value = row
  form.clearErrors()
  form.code = row.code
  form.legal_name = row.legal_name
  form.trade_name = row.trade_name || ''
  form.tax_id = row.tax_id || ''
  form.contact_name = row.contact_name
  form.contact_email = row.contact_email || ''
  form.contact_phone = row.contact_phone || ''
  form.payment_terms = row.payment_terms
  form.notes = row.notes || ''
  form.status = row.status
  showModal.value = true
}

const confirmDelete = (row) => {
  if (!row._id) return
  selectedSupplier.value = row
  showDeleteModal.value = true
}

const submitForm = () => {
  if (isEditing.value) {
    form.put(`/equipo4/proveedores/${selectedSupplier.value._id}`, {
      onSuccess: () => { showModal.value = false; form.reset() }, onError: (errs) => { console.error("[MODAL ERROR]", errs); window.scrollTo({top:0,behavior:"smooth"}) }
    })
  } else {
    form.post('/equipo4/proveedores', {
      onSuccess: () => { showModal.value = false; form.reset() }, onError: (errs) => { console.error("[MODAL ERROR]", errs); window.scrollTo({top:0,behavior:"smooth"}) }
    })
  }
}

const deleteSupplier = () => {
  if (!selectedSupplier.value) return
  router.delete(`/equipo4/proveedores/${selectedSupplier.value._id}`, {
    onSuccess: () => { showDeleteModal.value = false; selectedSupplier.value = null }, onError: (errs) => { console.error("[MODAL ERROR]", errs); window.scrollTo({top:0,behavior:"smooth"}) }
  })
}
</script>

<template>
  <Equipo4Layout>
    <Team4Module
      title="Proveedores"
      subtitle="Catálogo de proveedores y condiciones comerciales"
      :columns="columns"
      :rows="formattedSuppliers"
      :kpis="kpis"
      :pagination="pagination"
      :filters="filters"
      search-route="/equipo4/proveedores"
    >
      <template #toolbar>
        <button v-if="canCreate" @click="openCreateModal" class="inline-flex items-center px-4 py-2 text-sm font-semibold rounded-lg bg-[#00338D] text-white hover:bg-[#0284C7] transition">
          + Nuevo Proveedor
        </button>
      </template>
      <template #actions="{ row }">
        <div v-if="hasAnyAction" class="flex items-center justify-end gap-2">
          <button v-if="canUpdate" @click="openEditModal(row)" class="px-3 py-1 text-xs font-medium rounded border border-slate-300 bg-white text-slate-700 hover:bg-slate-100 transition">Editar</button>
          <button v-if="canDelete" @click="confirmDelete(row)" class="px-3 py-1 text-xs font-medium rounded bg-rose-600 text-white hover:bg-rose-700 transition">Eliminar</button>
        </div>
        <span v-else class="text-xs text-slate-400 italic">Solo lectura</span>
      </template>
    </Team4Module>

    <div v-if="showModal" class="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/50 backdrop-blur-sm p-4 overflow-y-auto">
      <div class="bg-white rounded-2xl shadow-xl border border-slate-200 w-full max-w-2xl p-6 space-y-4">
        <div class="flex justify-between items-center border-b border-slate-100 pb-3">
          <h2 class="text-lg font-bold text-[#00338D]">{{ isEditing ? 'Editar Proveedor' : 'Nuevo Proveedor' }}</h2>
          <button @click="showModal = false" class="text-slate-400 hover:text-slate-600 font-bold">&times;</button>
        </div>
        <div v-if="Object.keys(form.errors).length > 0" class="rounded-lg bg-rose-50 border border-rose-200 p-3">
          <ul class="text-xs text-rose-700 list-disc pl-4 space-y-0.5">
            <li v-for="(err, field) in form.errors" :key="field">{{ err }}</li>
          </ul>
        </div>
        <form @submit.prevent="submitForm" class="space-y-4">
          <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
            <div>
              <label class="block text-xs font-semibold uppercase text-slate-600 mb-1">Código *</label>
              <input v-model="form.code" type="text" class="w-full px-3 py-2 text-sm rounded-lg border border-slate-300" />
            </div>
            <div>
              <label class="block text-xs font-semibold uppercase text-slate-600 mb-1">Estado *</label>
              <select v-model="form.status" class="w-full px-3 py-2 text-sm rounded-lg border border-slate-300 bg-white">
                <option value="ACTIVO">Activo</option>
                <option value="INACTIVO">Inactivo</option>
                <option value="SUSPENDIDO">Suspendido</option>
              </select>
            </div>
          </div>
          <div>
            <label class="block text-xs font-semibold uppercase text-slate-600 mb-1">Razón social *</label>
            <input v-model="form.legal_name" type="text" class="w-full px-3 py-2 text-sm rounded-lg border border-slate-300" />
          </div>
          <div class="grid grid-cols-2 gap-4">
            <div>
              <label class="block text-xs font-semibold uppercase text-slate-600 mb-1">Nombre comercial</label>
              <input v-model="form.trade_name" type="text" class="w-full px-3 py-2 text-sm rounded-lg border border-slate-300" />
            </div>
            <div>
              <label class="block text-xs font-semibold uppercase text-slate-600 mb-1">RFC</label>
              <input v-model="form.tax_id" type="text" class="w-full px-3 py-2 text-sm rounded-lg border border-slate-300" />
            </div>
          </div>
          <div class="grid grid-cols-3 gap-4">
            <div>
              <label class="block text-xs font-semibold uppercase text-slate-600 mb-1">Contacto *</label>
              <input v-model="form.contact_name" type="text" class="w-full px-3 py-2 text-sm rounded-lg border border-slate-300" />
            </div>
            <div>
              <label class="block text-xs font-semibold uppercase text-slate-600 mb-1">Email</label>
              <input v-model="form.contact_email" type="email" class="w-full px-3 py-2 text-sm rounded-lg border border-slate-300" />
            </div>
            <div>
              <label class="block text-xs font-semibold uppercase text-slate-600 mb-1">Teléfono</label>
              <input v-model="form.contact_phone" type="text" class="w-full px-3 py-2 text-sm rounded-lg border border-slate-300" />
            </div>
          </div>
          <div>
            <label class="block text-xs font-semibold uppercase text-slate-600 mb-1">Términos de pago *</label>
            <select v-model="form.payment_terms" class="w-full px-3 py-2 text-sm rounded-lg border border-slate-300 bg-white">
              <option value="CONTADO">Contado</option>
              <option value="15_DIAS">15 días</option>
              <option value="30_DIAS">30 días</option>
              <option value="60_DIAS">60 días</option>
            </select>
          </div>
          <div>
            <label class="block text-xs font-semibold uppercase text-slate-600 mb-1">Notas</label>
            <textarea v-model="form.notes" rows="2" class="w-full px-3 py-2 text-sm rounded-lg border border-slate-300"></textarea>
          </div>
          <div class="flex justify-end gap-2 pt-3 border-t border-slate-100">
            <button type="button" @click="showModal = false" class="px-4 py-2 text-sm font-medium rounded-lg border border-slate-300 bg-white text-slate-700 hover:bg-slate-50 transition">Cancelar</button>
            <button type="submit" :disabled="form.processing" class="px-4 py-2 text-sm font-semibold rounded-lg bg-[#00338D] text-white hover:bg-[#0284C7] transition disabled:opacity-50">
              {{ form.processing ? 'Guardando...' : (isEditing ? 'Actualizar' : 'Guardar') }}
            </button>
          </div>
        </form>
      </div>
    </div>

    <div v-if="showDeleteModal" class="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/50 backdrop-blur-sm p-4">
      <div class="bg-white rounded-2xl shadow-xl border border-slate-200 w-full max-w-md p-6 space-y-4">
        <h3 class="text-lg font-bold text-slate-900">¿Eliminar proveedor?</h3>
        <p class="text-sm text-slate-600">Vas a eliminar <strong>{{ selectedSupplier?.legal_name }}</strong>.</p>
        <div class="flex justify-end gap-2 pt-2">
          <button @click="showDeleteModal = false" class="px-4 py-2 text-sm font-medium rounded-lg border border-slate-300 bg-white text-slate-700 hover:bg-slate-50 transition">Cancelar</button>
          <button @click="deleteSupplier" class="px-4 py-2 text-sm font-semibold rounded-lg bg-rose-600 text-white hover:bg-rose-700 transition">Eliminar</button>
        </div>
      </div>
    </div>
  </Equipo4Layout>
</template>
