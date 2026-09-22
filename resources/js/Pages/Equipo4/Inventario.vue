<script setup>
import { ref, computed } from 'vue'
import { useForm } from '@inertiajs/vue3'
import Equipo4Layout from '../../Layouts/Equipo4Layout.vue'
import Team4Module from '../../Components/Team4Module.vue'

const props = defineProps({
  inventory: { type: Array, default: () => [] },
  productsList: { type: Array, default: () => [] },
  kpis: { type: Array, default: () => [] },
  pagination: { type: Object, default: () => ({}) },
  filters: { type: Object, default: () => ({}) }
})

const columns = ['SKU', 'Producto', 'Almacén', 'Ubicación', 'Existencia', 'Reservado', 'Disponible', 'Estado']

const formattedInventory = computed(() => {
  return props.inventory.map(i => ({
    ...i,
    SKU: i.sku,
    Producto: i.product_name,
    'Almacén': i.warehouse_name,
    'Ubicación': i.location_name,
    'Existencia': i.on_hand,
    'Reservado': i.reserved,
    'Disponible': i.available,
    'Estado': i.status
  }))
})

const showModal = ref(false)
const selectedItem = ref(null)

const form = useForm({
  inventory_id: '',
  quantity: 0,
  reason: ''
})

const openAdjustModal = (row) => {
  selectedItem.value = row
  form.reset()
  form.clearErrors()
  form.inventory_id = row._id
  form.quantity = 0
  showModal.value = true
}

const submitForm = () => {
  form.post('/equipo4/inventario/adjust', {
    preserveScroll: true,
    onSuccess: () => { showModal.value = false; form.reset() }
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
        <button @click="openAdjustModal(row)" class="px-3 py-1 text-xs font-medium rounded border border-slate-300 bg-white text-slate-700 hover:bg-slate-100 transition">
          Ajustar
        </button>
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
  </Equipo4Layout>
</template>
