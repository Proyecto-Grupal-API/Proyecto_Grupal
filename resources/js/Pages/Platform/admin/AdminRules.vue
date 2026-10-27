<script setup>
import PlatformLayout from '@/Layouts/PlatformLayout.vue'
defineOptions({ layout: PlatformLayout })
import { ref, onMounted } from 'vue'
import StatusBadge from '@/Platform/components/ui/StatusBadge.vue'
import { useToast } from '@/Platform/composables/useToast.js'
import { earningRulesInitial } from '@/Platform/initialState.js'
import { api } from '@/Platform/services/api.js'

const { success, error } = useToast()
const showModal = ref(false)
const isSubmitting = ref(false)
const rules = ref([...earningRulesInitial])
const isLoading = ref(true)

const form = ref({
  name: '',
  code: '',
  description: '',
  type: 'multiplier',
  points_multiplier: 1.0,
  fixed_points: null,
  max_points_per_operation: 500,
  daily_cap_points: 1500,
  status: 'active',
})

async function loadRules() {
  try {
    isLoading.value = true
    const res = await api.rules.list()
    const list = Array.isArray(res) ? res : (res.data || [])
    if (list.length > 0) {
      rules.value = list.map(r => ({
        id: r._id || r.id,
        _id: r._id || r.id,
        name: r.name,
        code: r.code,
        value: r.type === 'fixed' ? `${r.fixed_points} pts fijos` : `${r.points_multiplier || 1} pt / $10`,
        status: (r.status === 'active' || r.status === 'Activo') ? 'Activa' : 'Inactiva',
        description: r.description || 'Regla de acumulación configurada en el motor contable.',
        scope: r.business_id ? 'Comercio Específico' : 'Campus Global',
        maxPerOp: r.max_points_per_operation,
        rawStatus: r.status,
      }))
    }
  } catch (err) {
    console.warn('No se pudo actualizar la información de Recompensas.', err)
  } finally {
    isLoading.value = false
  }
}

async function handleCreateRule() {
  if (!form.value.name.trim() || !form.value.code.trim()) return

  try {
    isSubmitting.value = true
    await api.rules.create({
      name: form.value.name,
      code: form.value.code.toUpperCase(),
      description: form.value.description,
      type: form.value.type,
      points_multiplier: form.value.type === 'multiplier' ? parseFloat(form.value.points_multiplier) : null,
      fixed_points: form.value.type === 'fixed' ? parseInt(form.value.fixed_points) : null,
      max_points_per_operation: form.value.max_points_per_operation ? parseInt(form.value.max_points_per_operation) : null,
      daily_cap_points: form.value.daily_cap_points ? parseInt(form.value.daily_cap_points) : null,
      status: 'active',
    })

    success(`Regla "${form.value.name}" creada exitosamente.`)
    showModal.value = false
    form.value.name = ''
    form.value.code = ''
    form.value.description = ''
    await loadRules()
  } catch (err) {
    console.error('Error al crear regla:', err)
    error(err.message || 'Error al guardar la regla.')
  } finally {
    isSubmitting.value = false
  }
}

async function handleToggle(r) {
  try {
    await api.rules.toggleStatus(r._id || r.id)
    success(`Estado de la regla "${r.name}" actualizado.`)
    await loadRules()
  } catch (err) {
    console.error('Error al alternar estado de regla:', err)
    error(err.message || 'No se pudo cambiar el estado.')
  }
}

onMounted(() => {
  loadRules()
})
</script>

<template>
  <div class="page">
    <div class="phrow">
      <div class="ph">
        <h1 class="page-title">7.3 Reglas de acumulación</h1>
        <div class="page-subtitle">Panel de administración</div>
        <p class="page-desc">Define fórmulas, multiplicadores, topes por operación y límites diarios en el motor contable.</p>
      </div>
      <button class="btn btn-primary" @click="showModal = true">＋ Nueva regla</button>
    </div>

    <div class="rules-grid">
      <div v-for="r in rules" :key="r.id" class="rule-card card">
        <div class="rc-header">
          <div class="rc-value">{{ r.value }}</div>
          <StatusBadge :status="r.status" />
        </div>
        <div class="rc-name">{{ r.name }}</div>
        <div class="rc-desc">{{ r.description }}</div>
        <div class="rc-meta">
          <span>🌐 {{ r.scope }}</span>
          <span v-if="r.maxPerOp">📦 Máx. {{ r.maxPerOp }} pts/op</span>
        </div>
        <button
          class="btn btn-ghost btn-sm"
          style="margin-top:8px; width:100%; justify-content:center"
          :style="{ color: r.status === 'Activa' ? '#dc2626' : '#16a34a' }"
          @click="handleToggle(r)"
        >
          {{ r.status === 'Activa' ? 'Desactivar regla' : 'Activar regla' }}
        </button>
      </div>
    </div>

    <!-- Modal Nueva Regla -->
    <div v-if="showModal" class="modal-backdrop" @click.self="showModal = false">
      <div class="modal card">
        <div class="modal-header">
          <h3 class="modal-title">Nueva Regla de Acumulación</h3>
          <button class="modal-close" @click="showModal = false">✕</button>
        </div>
        <form @submit.prevent="handleCreateRule" class="modal-body">
          <div class="form-group">
            <label class="cd-label">Nombre de la regla</label>
            <input v-model="form.name" required class="cd-input" placeholder="Ej: Bonificación Estándar Cafetería" />
          </div>

          <div class="form-row">
            <div class="form-group">
              <label class="cd-label">Código único</label>
              <input v-model="form.code" required class="cd-input" placeholder="Ej: RGL-CAF-01" style="text-transform:uppercase;" />
            </div>
            <div class="form-group">
              <label class="cd-label">Tipo de cálculo</label>
              <select v-model="form.type" class="cd-input">
                <option value="multiplier">Multiplicador por monto</option>
                <option value="fixed">Puntos fijos por evento</option>
              </select>
            </div>
          </div>

          <div v-if="form.type === 'multiplier'" class="form-group">
            <label class="cd-label">Multiplicador (pts por cada $10 gastados)</label>
            <input v-model.number="form.points_multiplier" type="number" step="0.1" min="0.1" class="cd-input" />
          </div>
          <div v-else class="form-group">
            <label class="cd-label">Puntos fijos asignados</label>
            <input v-model.number="form.fixed_points" type="number" min="1" class="cd-input" placeholder="Ej: 50" />
          </div>

          <div class="form-row">
            <div class="form-group">
              <label class="cd-label">Tope por operación (pts)</label>
              <input v-model.number="form.max_points_per_operation" type="number" class="cd-input" placeholder="500" />
            </div>
            <div class="form-group">
              <label class="cd-label">Tope diario total (pts)</label>
              <input v-model.number="form.daily_cap_points" type="number" class="cd-input" placeholder="1500" />
            </div>
          </div>

          <div class="form-group">
            <label class="cd-label">Descripción</label>
            <textarea v-model="form.description" rows="2" class="cd-input" placeholder="Condiciones de aplicación de la regla..."></textarea>
          </div>

          <div class="modal-actions">
            <button type="button" class="btn btn-secondary" @click="showModal = false">Cancelar</button>
            <button type="submit" class="btn btn-primary" :disabled="isSubmitting">
              {{ isSubmitting ? 'Guardando...' : 'Crear Regla' }}
            </button>
          </div>
        </form>
      </div>
    </div>
  </div>
</template>

<style scoped>
.page{display:flex;flex-direction:column;gap:20px;}
.phrow{display:flex;align-items:flex-end;justify-content:space-between;}
.ph{display:flex;flex-direction:column;gap:2px;}
.rules-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(250px,1fr));gap:16px;}
.rule-card{padding:18px;display:flex;flex-direction:column;gap:6px;}
.rc-header{display:flex;align-items:center;justify-content:space-between;margin-bottom:4px;}
.rc-value{font-size:18px;font-weight:800;color:var(--cd-primary);}
.rc-name{font-size:14px;font-weight:700;color:var(--cd-text-primary);}
.rc-desc{font-size:12px;color:var(--cd-text-secondary);min-height:36px;}
.rc-meta{display:flex;gap:12px;font-size:11px;color:var(--cd-text-muted);}

.modal-backdrop { position: fixed; inset: 0; background: rgba(0,0,0,0.45); display: flex; align-items: center; justify-content: center; z-index: 999; }
.modal { width: 100%; max-width: 520px; padding: 24px; display: flex; flex-direction: column; gap: 16px; }
.modal-header { display: flex; justify-content: space-between; align-items: center; }
.modal-title { font-size: 16px; font-weight: 700; margin: 0; }
.modal-close { background: none; border: none; font-size: 18px; cursor: pointer; color: var(--cd-text-muted); }
.modal-body { display: flex; flex-direction: column; gap: 14px; }
.form-group { display: flex; flex-direction: column; gap: 6px; }
.form-row { display: grid; grid-template-columns: repeat(2, 1fr); gap: 10px; }
.cd-label { font-size: 12px; font-weight: 600; color: var(--cd-text-secondary); }
.modal-actions { display: flex; justify-content: flex-end; gap: 10px; margin-top: 8px; }
</style>
