<script setup>
import { ref, computed } from 'vue'
import { useForm, router } from '@inertiajs/vue3'
import Equipo4Layout from '../../Layouts/Equipo4Layout.vue'
import Team4Module from '../../Components/Team4Module.vue'

const props = defineProps({
  alerts: { type: Array, default: () => [] },
  rules: { type: Array, default: () => [] },
  productsList: { type: Array, default: () => [] },
  locationsList: { type: Array, default: () => [] },
  showHistory: { type: Boolean, default: false },
  kpis: { type: Array, default: () => [] },
  filters: { type: Object, default: () => ({}) }
})

const activeTab = ref('alerts')

const alertColumns = ['SKU', 'Producto', 'Ubicación', 'Actual', 'Reorden', 'Prioridad', 'Estado']
const ruleColumns = ['SKU', 'Producto', 'Ubicación', 'Mín (auto)', 'Máx (auto)', 'Punto reorden', 'Activa']

const priorityLabels = { CRITICAL: 'Crítica', HIGH: 'Alta', MEDIUM: 'Media', LOW: 'Baja' }
const statusLabels = { ACTIVE: 'Activa', RESOLVED: 'Resuelta', DISMISSED: 'Descartada' }

const formattedAlerts = computed(() => {
  return props.alerts.map(a => ({
    ...a,
    'SKU': a.product_sku,
    'Producto': a.product_name,
    'Ubicación': a.location_name,
    'Actual': a.current_qty,
    'Reorden': a.threshold,
    'Prioridad': priorityLabels[a.priority] || a.priority,
    'Estado': statusLabels[a.status] || a.status
  }))
})

const activeAlertsCount = computed(() => props.alerts.filter(a => a.status === 'ACTIVE').length)

const formattedRules = computed(() => {
  return props.rules.map(r => ({
    ...r,
    'SKU': r.product_sku,
    'Producto': r.product_name,
    'Ubicación': r.location_name,
    'Mín (auto)': r.min_qty,
    'Máx (auto)': r.max_qty,
    'Punto reorden': r.reorder_point,
    'Activa': r.active ? 'Sí' : 'No'
  }))
})

const showRuleModal = ref(false)
const selectedRule = ref(null)
const ruleForm = useForm({ reorder_point: 0, active: true })

const openEditRule = (row) => {
  selectedRule.value = row
  ruleForm.clearErrors()
  ruleForm.reorder_point = row.reorder_point
  ruleForm.active = row.active
  showRuleModal.value = true
}

const submitRule = () => {
  ruleForm.put(`/equipo4/reglas-reorden/${selectedRule.value._id}`, {
    preserveScroll: true,
    onSuccess: () => { showRuleModal.value = false; ruleForm.reset() }
  })
}

const resetRule = (row) => {
  if (!confirm(`¿Recalcular el punto de reorden de ${row.product_sku}?`)) return
  router.post(`/equipo4/reglas-reorden/${row._id}/reset`, {}, { preserveScroll: true })
}

const syncAllRules = () => {
  if (!confirm('¿Sincronizar las reglas de reorden? Se crearán las que falten y se actualizarán las existentes (sin tocar el punto de reorden ajustado).')) return
  router.post('/equipo4/reglas-reorden/sync-all', {}, { preserveScroll: true })
}

const showDiscardModal = ref(false)
const selectedAlert = ref(null)
const discardForm = useForm({ reason: '' })

const openDiscardModal = (row) => {
  selectedAlert.value = row
  discardForm.reset()
  discardForm.clearErrors()
  showDiscardModal.value = true
}

const submitDiscard = () => {
  discardForm.post(`/equipo4/alertas/${selectedAlert.value._id}/discard`, {
    preserveScroll: true,
    onSuccess: () => { showDiscardModal.value = false; discardForm.reset(); selectedAlert.value = null }
  })
}

const recalculate = () => {
  if (!confirm('¿Recalcular las alertas?')) return
  router.post('/equipo4/alertas/generate', {}, { preserveScroll: true })
}

const toggleHistory = () => {
  router.get('/equipo4/alertas', { history: props.showHistory ? undefined : '1' }, { preserveScroll: false })
}
</script>

<template>
  <Equipo4Layout>
    <div class="mb-6 flex gap-6 border-b border-slate-200">
      <button
        @click="activeTab = 'alerts'"
        :class="activeTab === 'alerts' ? 'pb-3 text-sm font-semibold text-[#00338D] border-b-2 border-[#00338D] -mb-px' : 'pb-3 text-sm font-medium text-slate-500 hover:text-[#0284C7] transition'"
      >
        Alertas ({{ activeAlertsCount }})
      </button>
      <button
        @click="activeTab = 'rules'"
        :class="activeTab === 'rules' ? 'pb-3 text-sm font-semibold text-[#00338D] border-b-2 border-[#00338D] -mb-px' : 'pb-3 text-sm font-medium text-slate-500 hover:text-[#0284C7] transition'"
      >
        Reglas de reorden ({{ formattedRules.length }})
      </button>
    </div>

    <div v-if="activeTab === 'alerts'">
      <div class="mb-4 flex justify-end">
        <button @click="toggleHistory" class="text-xs font-semibold text-[#0284C7] hover:underline">
          {{ showHistory ? '← Ver solo activas' : 'Ver historial →' }}
        </button>
      </div>
      <Team4Module
        title="Alertas de reabastecimiento"
        :subtitle="showHistory ? 'Historial de alertas resueltas y descartadas' : 'Productos con stock bajo. Se resuelven automáticamente al reponer stock.'"
        :columns="alertColumns"
        :rows="formattedAlerts"
        :kpis="kpis"
        :pagination="{ total: 0, current_page: 1, last_page: 1, per_page: 25, from: 0, to: 0 }"
        :filters="filters"
        search-route="/equipo4/alertas"
      >
        <template #toolbar>
          <button v-if="!showHistory" @click="recalculate" class="inline-flex items-center px-3 py-2 text-xs font-semibold rounded-lg bg-[#00338D] text-white hover:bg-[#0284C7] transition">Recalcular ahora</button>
        </template>
        <template #actions="{ row }">
          <button v-if="row.status === 'ACTIVE'" @click="openDiscardModal(row)" class="px-3 py-1 text-xs font-medium rounded border border-slate-300 bg-white text-slate-700 hover:bg-slate-100 transition">Descartar</button>
          <span v-else-if="row.status === 'RESOLVED'" class="text-xs text-emerald-600 italic">Resuelta auto</span>
          <span v-else class="text-xs text-slate-400 italic" :title="row.resolution_reason">Descartada</span>
        </template>
      </Team4Module>
    </div>

    <div v-if="activeTab === 'rules'">
      <Team4Module
        title="Reglas de reorden"
        subtitle="Se crean automáticamente con los min/max del producto. Solo el punto de reorden es editable."
        :columns="ruleColumns"
        :rows="formattedRules"
        :pagination="{ total: 0, current_page: 1, last_page: 1, per_page: 25, from: 0, to: 0 }"
        :filters="{}"
        search-route="/equipo4/alertas"
      >
        <template #toolbar>
          <button @click="syncAllRules" class="inline-flex items-center px-3 py-2 text-xs font-semibold rounded-lg border border-[#0284C7] text-[#0284C7] bg-white hover:bg-slate-50 transition">Sincronizar reglas</button>
        </template>
        <template #actions="{ row }">
          <div class="flex items-center justify-end gap-2">
            <button @click="openEditRule(row)" class="px-3 py-1 text-xs font-medium rounded border border-slate-300 bg-white text-slate-700 hover:bg-slate-100 transition">Editar punto</button>
            <button @click="resetRule(row)" class="px-3 py-1 text-xs font-medium rounded border border-[#0284C7] text-[#0284C7] bg-white hover:bg-slate-50 transition">Recalcular</button>
          </div>
        </template>
      </Team4Module>
    </div>

    <div v-if="showRuleModal" class="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/50 backdrop-blur-sm p-4 overflow-y-auto">
      <div class="bg-white rounded-2xl shadow-xl border border-slate-200 w-full max-w-lg p-6 space-y-4 my-8">
        <div class="flex justify-between items-center border-b border-slate-100 pb-3">
          <h2 class="text-lg font-bold text-[#00338D]">Ajustar Regla</h2>
          <button @click="showRuleModal = false" class="text-slate-400 hover:text-slate-600 font-bold">&times;</button>
        </div>
        <div v-if="Object.keys(ruleForm.errors).length > 0" class="rounded-lg bg-rose-50 border border-rose-200 p-3">
          <ul class="text-xs text-rose-700 list-disc pl-4 space-y-0.5">
            <li v-for="(err, field) in ruleForm.errors" :key="field">{{ err }}</li>
          </ul>
        </div>
        <div class="bg-slate-50 rounded-lg p-3 text-sm text-slate-600 space-y-1">
          <p><strong>{{ selectedRule?.product_sku }}</strong> — {{ selectedRule?.product_name }}</p>
          <p class="text-xs">Ubicación: {{ selectedRule?.location_name }}</p>
          <p class="text-xs">Mín: <strong>{{ selectedRule?.min_qty }}</strong> · Máx: <strong>{{ selectedRule?.max_qty }}</strong></p>
        </div>
        <form @submit.prevent="submitRule" class="space-y-4">
          <div>
            <label class="block text-xs font-semibold uppercase text-slate-600 mb-1">Punto de reorden *</label>
            <input v-model.number="ruleForm.reorder_point" type="number" min="0" class="w-full px-3 py-2 text-sm rounded-lg border border-slate-300 focus:border-[#0284C7]" />
          </div>
          <div class="flex items-center gap-2">
            <input id="rule-active" v-model="ruleForm.active" type="checkbox" class="rounded border-slate-300 text-[#00338D]" />
            <label for="rule-active" class="text-sm text-slate-700">Regla activa</label>
          </div>
          <div class="flex justify-end gap-2 pt-3 border-t border-slate-100">
            <button type="button" @click="showRuleModal = false" class="px-4 py-2 text-sm font-medium rounded-lg border border-slate-300 bg-white text-slate-700 hover:bg-slate-50 transition">Cancelar</button>
            <button type="submit" :disabled="ruleForm.processing" class="px-4 py-2 text-sm font-semibold rounded-lg bg-[#00338D] text-white hover:bg-[#0284C7] transition disabled:opacity-50">
              {{ ruleForm.processing ? 'Guardando...' : 'Guardar' }}
            </button>
          </div>
        </form>
      </div>
    </div>

    <div v-if="showDiscardModal" class="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/50 backdrop-blur-sm p-4 overflow-y-auto">
      <div class="bg-white rounded-2xl shadow-xl border border-slate-200 w-full max-w-md p-6 space-y-4 my-8">
        <div class="flex justify-between items-center border-b border-slate-100 pb-3">
          <h2 class="text-lg font-bold text-[#00338D]">Descartar alerta</h2>
          <button @click="showDiscardModal = false" class="text-slate-400 hover:text-slate-600 font-bold">&times;</button>
        </div>
        <div v-if="Object.keys(discardForm.errors).length > 0" class="rounded-lg bg-rose-50 border border-rose-200 p-3">
          <ul class="text-xs text-rose-700 list-disc pl-4 space-y-0.5">
            <li v-for="(err, field) in discardForm.errors" :key="field">{{ err }}</li>
          </ul>
        </div>
        <div class="text-sm text-slate-600 space-y-1">
          <p><strong>{{ selectedAlert?.SKU }}</strong> — {{ selectedAlert?.Producto }}</p>
        </div>
        <form @submit.prevent="submitDiscard" class="space-y-4">
          <div>
            <label class="block text-xs font-semibold uppercase text-slate-600 mb-1">Motivo del descarte *</label>
            <textarea v-model="discardForm.reason" rows="3" class="w-full px-3 py-2 text-sm rounded-lg border border-slate-300 focus:border-[#0284C7]"></textarea>
            <p v-if="discardForm.errors.reason" class="mt-1 text-xs text-rose-600">{{ discardForm.errors.reason }}</p>
          </div>
          <div class="flex justify-end gap-2 pt-3 border-t border-slate-100">
            <button type="button" @click="showDiscardModal = false" class="px-4 py-2 text-sm font-medium rounded-lg border border-slate-300 bg-white text-slate-700 hover:bg-slate-50 transition">Cancelar</button>
            <button type="submit" :disabled="discardForm.processing" class="px-4 py-2 text-sm font-semibold rounded-lg bg-rose-600 text-white hover:bg-rose-700 transition disabled:opacity-50">
              {{ discardForm.processing ? 'Descartando...' : 'Descartar alerta' }}
            </button>
          </div>
        </form>
      </div>
    </div>
  </Equipo4Layout>
</template>
