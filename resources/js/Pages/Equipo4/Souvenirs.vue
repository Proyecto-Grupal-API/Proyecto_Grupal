<script setup>
import { ref, computed } from 'vue'
import { useForm, router } from '@inertiajs/vue3'
import Equipo4Layout from '../../Layouts/Equipo4Layout.vue'
import Team4Module from '../../Components/Team4Module.vue'
import { usePermissions } from '@/Composables/usePermissions'

const props = defineProps({
  products: { type: Array, default: () => [] },
  categories: { type: Array, default: () => [] },
  kpis: { type: Array, default: () => [] },
  pagination: { type: Object, default: () => ({}) },
  filters: { type: Object, default: () => ({}) }
})

const { can } = usePermissions()
const canCreate = computed(() => can('souvenirs.create'))
const canUpdate = computed(() => can('souvenirs.update'))
const canDelete = computed(() => can('souvenirs.delete'))
const hasAnyAction = computed(() => canUpdate.value || canDelete.value)

const columns = ['SKU', 'Producto', 'Categoría', 'Stock mín.', 'Stock máx.', 'Activo']

const formattedProducts = computed(() => {
  return props.products.map(p => ({
    ...p,
    _id: String(p._id || ''),
    SKU: p.sku,
    Producto: p.name,
    'Categoría': p.category,
    'Stock mín.': p.stock_min,
    'Stock máx.': p.stock_max,
    Activo: p.active ? 'Sí' : 'No'
  }))
})

const showModal = ref(false)
const showDeleteModal = ref(false)
const isEditing = ref(false)
const selectedProduct = ref(null)

const form = useForm({
  sku: '', name: '', description: '', category: 'souvenirs',
  stock_min: 0, stock_max: 0, active: true
})

const openCreateModal = () => {
  isEditing.value = false
  selectedProduct.value = null
  form.reset()
  form.clearErrors()
  form.category = 'souvenirs'
  showModal.value = true
}

const openEditModal = (row) => {
  if (!row._id) return
  isEditing.value = true
  selectedProduct.value = row
  form.clearErrors()
  form.sku = row.sku
  form.name = row.name
  form.description = row.description || ''
  form.category = row.category
  form.stock_min = row.stock_min
  form.stock_max = row.stock_max
  form.active = row.active
  showModal.value = true
}

const confirmDelete = (row) => {
  if (!row._id) return
  selectedProduct.value = row
  showDeleteModal.value = true
}

const submitForm = () => {
  if (isEditing.value) {
    if (!selectedProduct.value || !selectedProduct.value._id) return
    form.put(`/equipo4/souvenirs/${selectedProduct.value._id}`, {
      preserveScroll: true,
      onSuccess: () => { showModal.value = false; form.reset() }
    })
  } else {
    form.post('/equipo4/souvenirs', {
      preserveScroll: true,
      onSuccess: () => { showModal.value = false; form.reset() }
    })
  }
}

const deleteProduct = () => {
  if (!selectedProduct.value || !selectedProduct.value._id) return
  router.delete(`/equipo4/souvenirs/${selectedProduct.value._id}`, {
    preserveScroll: true,
    onSuccess: () => { showDeleteModal.value = false; selectedProduct.value = null }
  })
}
</script>

<template>
  <Equipo4Layout>
    <Team4Module
      title="Souvenirs"
      subtitle="Productos promocionales del campus"
      :columns="columns"
      :rows="formattedProducts"
      :kpis="kpis"
      :pagination="pagination"
      :filters="filters"
      search-route="/equipo4/souvenirs"
    >
      <template #toolbar>
        <button v-if="canCreate" @click="openCreateModal" class="inline-flex items-center px-4 py-2 text-sm font-semibold rounded-lg bg-[#00338D] text-white hover:bg-[#0284C7] transition">
          + Nuevo Souvenir
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
      <div class="bg-white rounded-2xl shadow-xl border border-slate-200 w-full max-w-lg p-6 space-y-4">
        <div class="flex justify-between items-center border-b border-slate-100 pb-3">
          <h2 class="text-lg font-bold text-[#00338D]">{{ isEditing ? 'Editar Souvenir' : 'Nuevo Souvenir' }}</h2>
          <button @click="showModal = false" class="text-slate-400 hover:text-slate-600 font-bold">&times;</button>
        </div>
        <div v-if="Object.keys(form.errors).length > 0" class="rounded-lg bg-rose-50 border border-rose-200 p-3">
          <ul class="text-xs text-rose-700 list-disc pl-4 space-y-0.5">
            <li v-for="(err, field) in form.errors" :key="field">{{ err }}</li>
          </ul>
        </div>
        <form @submit.prevent="submitForm" class="space-y-4">
          <div>
            <label class="block text-xs font-semibold uppercase text-slate-600 mb-1">SKU</label>
            <input v-model="form.sku" type="text" class="w-full px-3 py-2 text-sm rounded-lg border border-slate-300 focus:border-[#0284C7]" />
            <p v-if="form.errors.sku" class="mt-1 text-xs text-rose-600">{{ form.errors.sku }}</p>
          </div>
          <div>
            <label class="block text-xs font-semibold uppercase text-slate-600 mb-1">Nombre</label>
            <input v-model="form.name" type="text" class="w-full px-3 py-2 text-sm rounded-lg border border-slate-300 focus:border-[#0284C7]" />
            <p v-if="form.errors.name" class="mt-1 text-xs text-rose-600">{{ form.errors.name }}</p>
          </div>
          <div class="grid grid-cols-2 gap-4">
            <div>
              <label class="block text-xs font-semibold uppercase text-slate-600 mb-1">Stock Mínimo</label>
              <input v-model.number="form.stock_min" type="number" min="0" class="w-full px-3 py-2 text-sm rounded-lg border border-slate-300" />
            </div>
            <div>
              <label class="block text-xs font-semibold uppercase text-slate-600 mb-1">Stock Máximo</label>
              <input v-model.number="form.stock_max" type="number" min="0" class="w-full px-3 py-2 text-sm rounded-lg border border-slate-300" />
            </div>
          </div>
          <div>
            <label class="block text-xs font-semibold uppercase text-slate-600 mb-1">Descripción</label>
            <textarea v-model="form.description" rows="3" class="w-full px-3 py-2 text-sm rounded-lg border border-slate-300"></textarea>
          </div>
          <div class="flex items-center gap-2">
            <input id="active" v-model="form.active" type="checkbox" class="rounded border-slate-300 text-[#00338D]" />
            <label for="active" class="text-sm text-slate-700">Activo</label>
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
        <h3 class="text-lg font-bold text-slate-900">¿Eliminar souvenir?</h3>
        <p class="text-sm text-slate-600">Vas a eliminar <strong>{{ selectedProduct?.name }}</strong>.</p>
        <div class="flex justify-end gap-2 pt-2">
          <button @click="showDeleteModal = false" class="px-4 py-2 text-sm font-medium rounded-lg border border-slate-300 bg-white text-slate-700 hover:bg-slate-50 transition">Cancelar</button>
          <button @click="deleteProduct" class="px-4 py-2 text-sm font-semibold rounded-lg bg-rose-600 text-white hover:bg-rose-700 transition">Eliminar</button>
        </div>
      </div>
    </div>
  </Equipo4Layout>
</template>
