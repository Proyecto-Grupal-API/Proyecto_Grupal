<script setup>
import { ref, computed } from 'vue'
import { useForm, router } from '@inertiajs/vue3'
import Equipo4Layout from '../../Layouts/Equipo4Layout.vue'
import Team4Module from '../../Components/Team4Module.vue'

const props = defineProps({
  products: { type: Array, default: () => [] },
  categories: { type: Array, default: () => [] },
  kpis: { type: Array, default: () => [] },
  pagination: { type: Object, default: () => ({}) },
  filters: { type: Object, default: () => ({}) }
})

const columns = ['SKU', 'Producto', 'Categoría', 'Stock mín.', 'Stock máx.', 'Activo']

const showModal = ref(false)
const showDeleteModal = ref(false)
const isEditing = ref(false)
const selectedProduct = ref(null)

const form = useForm({
  sku: '',
  name: '',
  description: '',
  category: '',
  stock_min: 0,
  stock_max: 0,
  active: true
})

function categoryLabel(slug) {
  const found = props.categories.find(c => c.slug === slug)
  return found ? found.name : slug
}

const formattedProducts = computed(() => {
  return props.products.map(p => ({
    ...p,
    SKU: p.sku,
    Producto: p.name,
    'Categoría': categoryLabel(p.category),
    'Stock mín.': p.stock_min,
    'Stock máx.': p.stock_max,
    Activo: p.active ? 'Sí' : 'No'
  }))
})

const openCreateModal = () => {
  isEditing.value = false
  selectedProduct.value = null
  form.reset()
  form.clearErrors()
  form.category = props.categories[0]?.slug || ''
  showModal.value = true
}

const openEditModal = (row) => {
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
  selectedProduct.value = row
  showDeleteModal.value = true
}

const submitForm = () => {
  const options = {
    preserveScroll: true,
    onSuccess: () => {
      showModal.value = false
      form.reset()
    },
    onError: (errors) => {
      console.error('Errores de validación:', errors)
    },
  }

  if (isEditing.value) {
    form.put(`/equipo4/productos/${selectedProduct.value._id}`, options)
  } else {
    form.post('/equipo4/productos', options)
  }
}

const deleteProduct = () => {
  if (!selectedProduct.value) return
  router.delete(`/equipo4/productos/${selectedProduct.value._id}`, {
    onSuccess: () => {
      showDeleteModal.value = false
      selectedProduct.value = null
    }
  })
}
</script>

<template>
  <Equipo4Layout>
    <Team4Module
      title="Gestión de Productos"
      subtitle="Catálogo oficial de productos y souvenirs"
      :columns="columns"
      :rows="formattedProducts"
      :kpis="kpis"
      :pagination="pagination"
      :filters="filters"
      search-route="/equipo4/productos"
    >
      <template #toolbar>
        <button
          @click="openCreateModal"
          class="inline-flex items-center px-4 py-2 text-sm font-semibold rounded-lg bg-[#00338D] text-white hover:bg-[#0284C7] transition"
        >
          + Nuevo Producto
        </button>
      </template>

      <template #actions="{ row }">
        <div class="flex items-center justify-end gap-2">
          <button
            @click="openEditModal(row)"
            class="px-3 py-1 text-xs font-medium rounded border border-slate-300 bg-white text-slate-700 hover:bg-slate-100 transition"
          >
            Editar
          </button>
          <button
            @click="confirmDelete(row)"
            class="px-3 py-1 text-xs font-medium rounded bg-rose-600 text-white hover:bg-rose-700 transition"
          >
            Eliminar
          </button>
        </div>
      </template>
    </Team4Module>

    <div v-if="showModal" class="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/50 backdrop-blur-sm p-4 overflow-y-auto">
      <div class="bg-white rounded-2xl shadow-xl border border-slate-200 w-full max-w-lg p-6 space-y-4">
        <div class="flex justify-between items-center border-b border-slate-100 pb-3">
          <h2 class="text-lg font-bold text-[#00338D]">
            {{ isEditing ? 'Editar Producto' : 'Nuevo Producto' }}
          </h2>
          <button @click="showModal = false" class="text-slate-400 hover:text-slate-600 font-bold">&times;</button>
        </div>

        <div v-if="Object.keys(form.errors).length > 0" class="rounded-lg bg-rose-50 border border-rose-200 p-3">
          <p class="text-xs font-bold text-rose-800 mb-1">Errores de validación:</p>
          <ul class="text-xs text-rose-700 list-disc pl-4 space-y-0.5">
            <li v-for="(err, field) in form.errors" :key="field">{{ err }}</li>
          </ul>
        </div>

        <form @submit.prevent="submitForm" class="space-y-4">
          <div>
            <label class="block text-xs font-semibold uppercase text-slate-600 mb-1">SKU</label>
            <input v-model="form.sku" type="text" placeholder="Ej. PROD-001" class="w-full px-3 py-2 text-sm rounded-lg border border-slate-300 focus:border-[#0284C7] focus:ring-[#0284C7]" />
            <p v-if="form.errors.sku" class="mt-1 text-xs text-rose-600">{{ form.errors.sku }}</p>
          </div>

          <div>
            <label class="block text-xs font-semibold uppercase text-slate-600 mb-1">Nombre</label>
            <input v-model="form.name" type="text" placeholder="Nombre del producto" class="w-full px-3 py-2 text-sm rounded-lg border border-slate-300 focus:border-[#0284C7] focus:ring-[#0284C7]" />
            <p v-if="form.errors.name" class="mt-1 text-xs text-rose-600">{{ form.errors.name }}</p>
          </div>

          <div>
            <label class="block text-xs font-semibold uppercase text-slate-600 mb-1">Categoría</label>
            <select v-model="form.category" class="w-full px-3 py-2 text-sm rounded-lg border border-slate-300 focus:border-[#0284C7] focus:ring-[#0284C7] bg-white">
              <option value="" disabled>Selecciona una categoría</option>
              <option v-for="cat in categories" :key="cat.slug" :value="cat.slug">{{ cat.name }}</option>
            </select>
            <p v-if="form.errors.category" class="mt-1 text-xs text-rose-600">{{ form.errors.category }}</p>
          </div>

          <div class="grid grid-cols-2 gap-4">
            <div>
              <label class="block text-xs font-semibold uppercase text-slate-600 mb-1">Stock Mínimo</label>
              <input v-model.number="form.stock_min" type="number" min="0" class="w-full px-3 py-2 text-sm rounded-lg border border-slate-300 focus:border-[#0284C7] focus:ring-[#0284C7]" />
              <p v-if="form.errors.stock_min" class="mt-1 text-xs text-rose-600">{{ form.errors.stock_min }}</p>
            </div>
            <div>
              <label class="block text-xs font-semibold uppercase text-slate-600 mb-1">Stock Máximo</label>
              <input v-model.number="form.stock_max" type="number" min="0" class="w-full px-3 py-2 text-sm rounded-lg border border-slate-300 focus:border-[#0284C7] focus:ring-[#0284C7]" />
              <p v-if="form.errors.stock_max" class="mt-1 text-xs text-rose-600">{{ form.errors.stock_max }}</p>
            </div>
          </div>

          <div>
            <label class="block text-xs font-semibold uppercase text-slate-600 mb-1">Descripción</label>
            <textarea v-model="form.description" rows="3" placeholder="Descripción opcional" class="w-full px-3 py-2 text-sm rounded-lg border border-slate-300 focus:border-[#0284C7] focus:ring-[#0284C7]"></textarea>
            <p v-if="form.errors.description" class="mt-1 text-xs text-rose-600">{{ form.errors.description }}</p>
          </div>

          <div class="flex items-center gap-2">
            <input id="active" v-model="form.active" type="checkbox" class="rounded border-slate-300 text-[#00338D] focus:ring-[#0284C7]" />
            <label for="active" class="text-sm text-slate-700">Producto Activo</label>
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
        <h3 class="text-lg font-bold text-slate-900">¿Eliminar producto?</h3>
        <p class="text-sm text-slate-600">
          Esta acción eliminará permanentemente el producto <strong>{{ selectedProduct?.name }}</strong> (SKU: {{ selectedProduct?.sku }}).
        </p>
        <div class="flex justify-end gap-2 pt-2">
          <button @click="showDeleteModal = false" class="px-4 py-2 text-sm font-medium rounded-lg border border-slate-300 bg-white text-slate-700 hover:bg-slate-50 transition">Cancelar</button>
          <button @click="deleteProduct" class="px-4 py-2 text-sm font-semibold rounded-lg bg-rose-600 text-white hover:bg-rose-700 transition">Eliminar</button>
        </div>
      </div>
    </div>
  </Equipo4Layout>
</template>
