<script setup>
import { ref, computed } from 'vue'
import { useForm, router } from '@inertiajs/vue3'
import Equipo4Layout from '../../Layouts/Equipo4Layout.vue'
import Team4Module from '../../Components/Team4Module.vue'

const props = defineProps({
  warehouses: { type: Array, default: () => [] },
  allWarehouses: { type: Array, default: () => [] },
  locations: { type: Array, default: () => [] },
  kpis: { type: Array, default: () => [] },
  pagination: { type: Object, default: () => ({}) },
  filters: { type: Object, default: () => ({}) }
})

const columns = ['Código', 'Almacén', 'Tipo', 'Ubicaciones', 'Estado']

const warehouseTypeLabels = {
  MAIN: 'Principal',
  STORAGE: 'Bodega',
  DISPLAY: 'Mostrador',
  CONSIGNMENT: 'Consignación'
}

const locationTypeLabels = {
  STORAGE: 'Almacenaje',
  DISPLAY: 'Exhibición',
  RECEIVING: 'Recepción',
  SHIPPING: 'Envío'
}

const showModal = ref(false)
const showDeleteModal = ref(false)
const isEditing = ref(false)
const selectedWarehouse = ref(null)

const form = useForm({
  code: '',
  name: '',
  type: 'STORAGE',
  active: true
})

const formattedWarehouses = computed(() => {
  return props.warehouses.map(w => ({
    ...w,
    'Código': w.code,
    'Almacén': w.name,
    'Tipo': warehouseTypeLabels[w.type] || w.type,
    'Ubicaciones': w.locations_count + ' ubicaciones',
    'Estado': w.active ? 'Activo' : 'Inactivo'
  }))
})

const openCreateModal = () => {
  isEditing.value = false
  selectedWarehouse.value = null
  form.reset()
  form.clearErrors()
  showModal.value = true
}

const openEditModal = (row) => {
  isEditing.value = true
  selectedWarehouse.value = row
  form.clearErrors()
  form.code = row.code
  form.name = row.name
  form.type = row.type
  form.active = row.active
  showModal.value = true
}

const confirmDelete = (row) => {
  selectedWarehouse.value = row
  showDeleteModal.value = true
}

const submitForm = () => {
  if (isEditing.value) {
    form.put(`/equipo4/almacenes/${selectedWarehouse.value._id}`, {
      onSuccess: () => { showModal.value = false; form.reset() }
    })
  } else {
    form.post('/equipo4/almacenes', {
      onSuccess: () => { showModal.value = false; form.reset() }
    })
  }
}

const deleteWarehouse = () => {
  if (!selectedWarehouse.value) return
  router.delete(`/equipo4/almacenes/${selectedWarehouse.value._id}`, {
    onSuccess: () => { showDeleteModal.value = false; selectedWarehouse.value = null }
  })
}

// UBICACIONES
const showLocationModal = ref(false)
const showLocationDeleteModal = ref(false)
const isEditingLocation = ref(false)
const selectedLocation = ref(null)

const locationForm = useForm({
  warehouse_id: '',
  code: '',
  name: '',
  type: 'STORAGE',
  capacity: 0,
  active: true
})

const locationColumns = ['Código', 'Ubicación', 'Almacén', 'Tipo', 'Capacidad', 'Estado']

const formattedLocations = computed(() => {
  return props.locations.map(l => {
    const wh = props.allWarehouses.find(w => w._id === l.warehouse_id)
    return {
      ...l,
      'Código': l.code,
      'Ubicación': l.name,
      'Almacén': wh ? wh.name : 'Desconocido',
      'Tipo': locationTypeLabels[l.type] || l.type,
      'Capacidad': l.capacity,
      'Estado': l.active ? 'Activo' : 'Inactivo'
    }
  })
})

const locationPagination = computed(() => ({
  current_page: 1, last_page: 1, per_page: 200, total: props.locations.length, from: 1, to: props.locations.length
}))

const openLocationCreateModal = () => {
  isEditingLocation.value = false
  selectedLocation.value = null
  locationForm.reset()
  locationForm.clearErrors()
  if (props.allWarehouses.length > 0) locationForm.warehouse_id = props.allWarehouses[0]._id
  showLocationModal.value = true
}

const openLocationEditModal = (row) => {
  isEditingLocation.value = true
  selectedLocation.value = row
  locationForm.clearErrors()
  locationForm.warehouse_id = row.warehouse_id
  locationForm.code = row.code
  locationForm.name = row.name
  locationForm.type = row.type
  locationForm.capacity = row.capacity
  locationForm.active = row.active
  showLocationModal.value = true
}

const confirmLocationDelete = (row) => {
  selectedLocation.value = row
  showLocationDeleteModal.value = true
}

const submitLocationForm = () => {
  if (isEditingLocation.value) {
    locationForm.put(`/equipo4/ubicaciones/${selectedLocation.value._id}`, {
      onSuccess: () => { showLocationModal.value = false; locationForm.reset() }
    })
  } else {
    locationForm.post('/equipo4/ubicaciones', {
      onSuccess: () => { showLocationModal.value = false; locationForm.reset() }
    })
  }
}

const deleteLocation = () => {
  if (!selectedLocation.value) return
  router.delete(`/equipo4/ubicaciones/${selectedLocation.value._id}`, {
    onSuccess: () => { showLocationDeleteModal.value = false; selectedLocation.value = null }
  })
}
</script>

<template>
  <Equipo4Layout>
    <Team4Module
      title="Almacenes"
      subtitle="Almacenes, bodegas y mostradores del negocio"
      :columns="columns"
      :rows="formattedWarehouses"
      :kpis="kpis"
      :pagination="pagination"
      :filters="filters"
      search-route="/equipo4/almacenes"
    >
      <template #toolbar>
        <button @click="openCreateModal" class="inline-flex items-center px-4 py-2 text-sm font-semibold rounded-lg bg-[#00338D] text-white hover:bg-[#0284C7] transition">
          + Nuevo Almacén
        </button>
      </template>
      <template #actions="{ row }">
        <div class="flex items-center justify-end gap-2">
          <button @click="openEditModal(row)" class="px-3 py-1 text-xs font-medium rounded border border-slate-300 bg-white text-slate-700 hover:bg-slate-100 transition">Editar</button>
          <button @click="confirmDelete(row)" class="px-3 py-1 text-xs font-medium rounded bg-rose-600 text-white hover:bg-rose-700 transition">Eliminar</button>
        </div>
      </template>
    </Team4Module>

    <div class="mt-8">
      <Team4Module
        title="Ubicaciones"
        subtitle="Zonas físicas dentro de cada almacén"
        :columns="locationColumns"
        :rows="formattedLocations"
        :pagination="locationPagination"
        :filters="{}"
        search-route="/equipo4/almacenes"
      >
        <template #toolbar>
          <button @click="openLocationCreateModal" class="inline-flex items-center px-4 py-2 text-sm font-semibold rounded-lg bg-[#00338D] text-white hover:bg-[#0284C7] transition">
            + Nueva Ubicación
          </button>
        </template>
        <template #actions="{ row }">
          <div class="flex items-center justify-end gap-2">
            <button @click="openLocationEditModal(row)" class="px-3 py-1 text-xs font-medium rounded border border-slate-300 bg-white text-slate-700 hover:bg-slate-100 transition">Editar</button>
            <button @click="confirmLocationDelete(row)" class="px-3 py-1 text-xs font-medium rounded bg-rose-600 text-white hover:bg-rose-700 transition">Eliminar</button>
          </div>
        </template>
      </Team4Module>
    </div>

    <div v-if="showModal" class="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/50 backdrop-blur-sm p-4 overflow-y-auto">
      <div class="bg-white rounded-2xl shadow-xl border border-slate-200 w-full max-w-lg p-6 space-y-4">
        <div class="flex justify-between items-center border-b border-slate-100 pb-3">
          <h2 class="text-lg font-bold text-[#00338D]">{{ isEditing ? 'Editar Almacén' : 'Nuevo Almacén' }}</h2>
          <button @click="showModal = false" class="text-slate-400 hover:text-slate-600 font-bold">&times;</button>
        </div>
        <div v-if="Object.keys(form.errors).length > 0" class="rounded-lg bg-rose-50 border border-rose-200 p-3">
          <ul class="text-xs text-rose-700 list-disc pl-4 space-y-0.5">
            <li v-for="(err, field) in form.errors" :key="field">{{ err }}</li>
          </ul>
        </div>
        <form @submit.prevent="submitForm" class="space-y-4">
          <div>
            <label class="block text-xs font-semibold uppercase text-slate-600 mb-1">Código *</label>
            <input v-model="form.code" type="text" placeholder="ALM-001" class="w-full px-3 py-2 text-sm rounded-lg border border-slate-300 focus:border-[#0284C7]" />
            <p v-if="form.errors.code" class="mt-1 text-xs text-rose-600">{{ form.errors.code }}</p>
          </div>
          <div>
            <label class="block text-xs font-semibold uppercase text-slate-600 mb-1">Nombre *</label>
            <input v-model="form.name" type="text" class="w-full px-3 py-2 text-sm rounded-lg border border-slate-300 focus:border-[#0284C7]" />
            <p v-if="form.errors.name" class="mt-1 text-xs text-rose-600">{{ form.errors.name }}</p>
          </div>
          <div>
            <label class="block text-xs font-semibold uppercase text-slate-600 mb-1">Tipo *</label>
            <select v-model="form.type" class="w-full px-3 py-2 text-sm rounded-lg border border-slate-300 focus:border-[#0284C7] bg-white">
              <option value="MAIN">Principal</option>
              <option value="STORAGE">Bodega</option>
              <option value="DISPLAY">Mostrador</option>
              <option value="CONSIGNMENT">Consignación</option>
            </select>
          </div>
          <div class="flex items-center gap-2">
            <input id="active" v-model="form.active" type="checkbox" class="rounded border-slate-300 text-[#00338D]" />
            <label for="active" class="text-sm text-slate-700">Almacén activo</label>
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
        <h3 class="text-lg font-bold text-slate-900">¿Eliminar almacén?</h3>
        <p class="text-sm text-slate-600">Esta acción eliminará el almacén <strong>{{ selectedWarehouse?.name }}</strong>. Solo se permite si no tiene ubicaciones asociadas.</p>
        <div class="flex justify-end gap-2 pt-2">
          <button @click="showDeleteModal = false" class="px-4 py-2 text-sm font-medium rounded-lg border border-slate-300 bg-white text-slate-700 hover:bg-slate-50 transition">Cancelar</button>
          <button @click="deleteWarehouse" class="px-4 py-2 text-sm font-semibold rounded-lg bg-rose-600 text-white hover:bg-rose-700 transition">Eliminar</button>
        </div>
      </div>
    </div>

    <div v-if="showLocationModal" class="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/50 backdrop-blur-sm p-4 overflow-y-auto">
      <div class="bg-white rounded-2xl shadow-xl border border-slate-200 w-full max-w-lg p-6 space-y-4">
        <div class="flex justify-between items-center border-b border-slate-100 pb-3">
          <h2 class="text-lg font-bold text-[#00338D]">{{ isEditingLocation ? 'Editar Ubicación' : 'Nueva Ubicación' }}</h2>
          <button @click="showLocationModal = false" class="text-slate-400 hover:text-slate-600 font-bold">&times;</button>
        </div>
        <form @submit.prevent="submitLocationForm" class="space-y-4">
          <div>
            <label class="block text-xs font-semibold uppercase text-slate-600 mb-1">Almacén *</label>
            <select v-model="locationForm.warehouse_id" class="w-full px-3 py-2 text-sm rounded-lg border border-slate-300 focus:border-[#0284C7] bg-white">
              <option value="" disabled>Selecciona un almacén</option>
              <option v-for="wh in allWarehouses" :key="wh._id" :value="wh._id">{{ wh.name }} ({{ wh.code }})</option>
            </select>
          </div>
          <div class="grid grid-cols-2 gap-4">
            <div>
              <label class="block text-xs font-semibold uppercase text-slate-600 mb-1">Código *</label>
              <input v-model="locationForm.code" type="text" placeholder="LOC-001" class="w-full px-3 py-2 text-sm rounded-lg border border-slate-300 focus:border-[#0284C7]" />
            </div>
            <div>
              <label class="block text-xs font-semibold uppercase text-slate-600 mb-1">Tipo *</label>
              <select v-model="locationForm.type" class="w-full px-3 py-2 text-sm rounded-lg border border-slate-300 focus:border-[#0284C7] bg-white">
                <option value="STORAGE">Almacenaje</option>
                <option value="DISPLAY">Exhibición</option>
                <option value="RECEIVING">Recepción</option>
                <option value="SHIPPING">Envío</option>
              </select>
            </div>
          </div>
          <div>
            <label class="block text-xs font-semibold uppercase text-slate-600 mb-1">Nombre *</label>
            <input v-model="locationForm.name" type="text" class="w-full px-3 py-2 text-sm rounded-lg border border-slate-300 focus:border-[#0284C7]" />
          </div>
          <div>
            <label class="block text-xs font-semibold uppercase text-slate-600 mb-1">Capacidad *</label>
            <input v-model.number="locationForm.capacity" type="number" min="0" class="w-full px-3 py-2 text-sm rounded-lg border border-slate-300 focus:border-[#0284C7]" />
          </div>
          <div class="flex items-center gap-2">
            <input id="loc-active" v-model="locationForm.active" type="checkbox" class="rounded border-slate-300 text-[#00338D]" />
            <label for="loc-active" class="text-sm text-slate-700">Ubicación activa</label>
          </div>
          <div class="flex justify-end gap-2 pt-3 border-t border-slate-100">
            <button type="button" @click="showLocationModal = false" class="px-4 py-2 text-sm font-medium rounded-lg border border-slate-300 bg-white text-slate-700 hover:bg-slate-50 transition">Cancelar</button>
            <button type="submit" :disabled="locationForm.processing" class="px-4 py-2 text-sm font-semibold rounded-lg bg-[#00338D] text-white hover:bg-[#0284C7] transition disabled:opacity-50">
              {{ locationForm.processing ? 'Guardando...' : (isEditingLocation ? 'Actualizar' : 'Guardar') }}
            </button>
          </div>
        </form>
      </div>
    </div>

    <div v-if="showLocationDeleteModal" class="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/50 backdrop-blur-sm p-4">
      <div class="bg-white rounded-2xl shadow-xl border border-slate-200 w-full max-w-md p-6 space-y-4">
        <h3 class="text-lg font-bold text-slate-900">¿Eliminar ubicación?</h3>
        <p class="text-sm text-slate-600">Vas a eliminar <strong>{{ selectedLocation?.name }}</strong> ({{ selectedLocation?.code }}).</p>
        <div class="flex justify-end gap-2 pt-2">
          <button @click="showLocationDeleteModal = false" class="px-4 py-2 text-sm font-medium rounded-lg border border-slate-300 bg-white text-slate-700 hover:bg-slate-50 transition">Cancelar</button>
          <button @click="deleteLocation" class="px-4 py-2 text-sm font-semibold rounded-lg bg-rose-600 text-white hover:bg-rose-700 transition">Eliminar</button>
        </div>
      </div>
    </div>
  </Equipo4Layout>
</template>
