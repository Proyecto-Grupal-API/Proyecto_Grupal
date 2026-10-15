<script setup>
import { ref, computed } from 'vue'
import { Head, useForm, router } from '@inertiajs/vue3'
import Equipo4Layout from '../../Layouts/Equipo4Layout.vue'

const props = defineProps({
  categories: { type: Array, default: () => [] },
})

const q = ref('')
const open = ref(false)
const editing = ref(null)

const form = useForm({ name: '', description: '', active: true })

const filtered = computed(() =>
  props.categories.filter(c => c.name.toLowerCase().includes(q.value.toLowerCase()))
)

function openCreate() {
  editing.value = null
  form.reset()
  form.active = true
  open.value = true
}

function openEdit(row) {
  editing.value = row
  form.name = row.name
  form.description = row.description
  form.active = row.active
  open.value = true
}

function closeModal() {
  open.value = false
  form.reset()
  editing.value = null
}

function save() {
  if (editing.value) {
    form.put('/equipo4/categorias/' + editing.value.id, {
      preserveScroll: true,
      onSuccess: () => closeModal(),
    })
  } else {
    form.post('/equipo4/categorias', {
      preserveScroll: true,
      onSuccess: () => closeModal(),
    })
  }
}

function destroy(row) {
  if (!confirm('¿Eliminar categoría "' + row.name + '"?')) return
  router.delete('/equipo4/categorias/' + row.id, { preserveScroll: true })
}
</script>

<template>
  <Head title="Categorías - Campus Digital" />

  <Equipo4Layout>
    <div class="max-w-6xl mx-auto px-4 pt-8 pb-16">
      <!-- Hero -->
      <div class="rounded-2xl bg-white border border-slate-200/70 px-6 py-5 flex items-center gap-4">
        <span class="grid place-items-center w-12 h-12 rounded-full bg-[#1B3A8F] text-white text-base font-bold shrink-0">C</span>
        <div>
          <h1 class="text-2xl font-semibold text-slate-800 tracking-tight">Categorías de productos</h1>
          <p class="mt-0.5 text-sm text-slate-500">Clasificación oficial para productos y souvenirs</p>
        </div>
      </div>

      <!-- Toolbar -->
      <div class="flex justify-end gap-2 mt-6">
        <input v-model="q" type="text" placeholder="Buscar..."
               class="border border-slate-300 rounded-lg px-3 py-2 text-sm w-64 bg-white" />
        <button @click="openCreate"
                class="bg-[#1B3A8F] text-white text-sm font-semibold px-4 py-2 rounded-lg hover:bg-[#2F6BE8]">
          + Nueva Categoría
        </button>
      </div>

      <!-- Tabla -->
      <div class="bg-white rounded-2xl border border-slate-200/70 mt-4 overflow-hidden">
        <div class="px-6 py-3 border-b">
          <h3 class="text-xs font-bold text-[#0B3B8C] tracking-widest uppercase">Información</h3>
        </div>
        <table class="w-full text-sm">
          <thead class="text-xs uppercase text-slate-500 bg-slate-50">
            <tr>
              <th class="text-left px-6 py-3">Nombre</th>
              <th class="text-left px-6 py-3">Slug</th>
              <th class="text-left px-6 py-3">Descripción</th>
              <th class="text-left px-6 py-3">Activa</th>
              <th class="text-right px-6 py-3">Acciones</th>
            </tr>
          </thead>
          <tbody>
            <tr v-if="filtered.length === 0">
              <td colspan="5" class="text-center py-10 text-slate-400">Sin registros para mostrar</td>
            </tr>
            <tr v-for="c in filtered" :key="c.id" class="border-t hover:bg-slate-50">
              <td class="px-6 py-4 font-medium">{{ c.name }}</td>
              <td class="px-6 py-4 text-slate-500">{{ c.slug }}</td>
              <td class="px-6 py-4 text-slate-500">{{ c.description || '—' }}</td>
              <td class="px-6 py-4">
                <span :class="c.active ? 'text-emerald-600' : 'text-slate-400'">
                  {{ c.active ? 'Sí' : 'No' }}
                </span>
              </td>
              <td class="px-6 py-4 text-right space-x-2">
                <button @click="openEdit(c)" class="text-[#0B3B8C] font-semibold">Editar</button>
                <button @click="destroy(c)" class="text-red-600 font-semibold">Eliminar</button>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>

    <!-- Modal -->
    <div v-if="open" class="fixed inset-0 bg-black/40 flex items-center justify-center z-50">
      <div class="bg-white rounded-xl shadow-2xl w-full max-w-md mx-4 p-6">
        <div class="flex items-center justify-between mb-4">
          <h3 class="text-lg font-bold text-[#0B3B8C]">
            {{ editing ? 'Editar Categoría' : 'Nueva Categoría' }}
          </h3>
          <button @click="closeModal" class="text-slate-400 text-xl">&times;</button>
        </div>

        <div v-if="Object.keys(form.errors).length"
             class="bg-red-50 text-red-700 text-sm rounded-lg px-3 py-2 mb-4">
          <div v-for="(err, field) in form.errors" :key="field">{{ err }}</div>
        </div>

        <div class="space-y-4">
          <div>
            <label class="block text-xs font-semibold text-slate-600 mb-1">NOMBRE *</label>
            <input v-model="form.name" type="text"
                   class="w-full border border-slate-300 rounded-lg px-3 py-2" />
          </div>
          <div>
            <label class="block text-xs font-semibold text-slate-600 mb-1">DESCRIPCIÓN</label>
            <textarea v-model="form.description" rows="3"
                      class="w-full border border-slate-300 rounded-lg px-3 py-2"></textarea>
          </div>
          <label class="flex items-center gap-2 text-sm">
            <input v-model="form.active" type="checkbox" />
            Activa
          </label>
        </div>

        <div class="flex justify-end gap-2 mt-6">
          <button @click="closeModal"
                  class="px-4 py-2 border border-slate-300 rounded-lg text-sm">Cancelar</button>
          <button @click="save" :disabled="form.processing"
                  class="px-4 py-2 bg-[#0B3B8C] text-white rounded-lg text-sm font-semibold disabled:opacity-50">
            Guardar
          </button>
        </div>
      </div>
    </div>
  </Equipo4Layout>
</template>