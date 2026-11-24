<script setup>
import PlatformLayout from '@/Layouts/PlatformLayout.vue'
defineOptions({ layout: PlatformLayout })
import { ref, computed, onMounted } from 'vue'
import StatusBadge from '@/Platform/components/ui/StatusBadge.vue'
import { useToast } from '@/Platform/composables/useToast.js'
import { useRole } from '@/Platform/composables/useRole.js'
import { api } from '@/Platform/services/api.js'

const { success, error } = useToast()
const { hasPermission } = useRole()
const canManage = computed(() => hasPermission('rules.manage'))
// Mensaje claro del servidor (validación 422 o regla de negocio) en lugar del texto técnico de axios.
const apiMessage = (err, fallback) => Object.values(err.response?.data?.errors || {}).flat().join(' ') || err.response?.data?.message || err.response?.data?.error || fallback

const rules = ref([])
const businesses = ref([])
const isLoading = ref(true)
const showModal = ref(false)
const isSubmitting = ref(false)
const editingId = ref(null)

const blank = () => ({
  business_id: '', name: '', type: 'amount_spent', points_per_unit: 1, unit_amount: 10, fixed_points: 50,
  multiplier: 1, max_points_per_operation: '', daily_cap: '', active_from: new Date().toISOString().slice(0, 10), active_to: '', status: 'active',
})
const form = ref(blank())

const typeLabels = { amount_spent: 'Puntos por importe', fixed: 'Puntos fijos por operación', event: 'Puntos por evento o acción' }
const businessName = (id) => businesses.value.find(b => b.business_id === id)?.business_name || id

function describe(r) {
  if (r.type === 'amount_spent') return `${r.points_per_unit} pt por cada $${r.unit_amount}`
  return `${r.fixed_points ?? 0} pts fijos`
}

async function load() {
  try {
    isLoading.value = true
    const [rulesRes, businessesRes] = await Promise.all([api.rules.list({ per_page: 100 }), api.businesses.list({ per_page: 100 })])
    const list = Array.isArray(rulesRes) ? rulesRes : (rulesRes.data || [])
    businesses.value = Array.isArray(businessesRes) ? businessesRes : (businessesRes.data || [])
    rules.value = list.map(r => ({ ...r, id: r._id || r.id }))
  } catch (err) {
    console.warn('No se pudo actualizar la información de Recompensas.', err)
  } finally {
    isLoading.value = false
  }
}

function openCreate() {
  editingId.value = null
  form.value = blank()
  if (businesses.value.length) form.value.business_id = businesses.value[0].business_id
  showModal.value = true
}

function openEdit(r) {
  editingId.value = r.id
  form.value = {
    business_id: r.business_id, name: r.name, type: r.type || 'amount_spent', points_per_unit: r.points_per_unit ?? 1, unit_amount: r.unit_amount ?? 10,
    fixed_points: r.fixed_points ?? 50, multiplier: r.multiplier ?? 1, max_points_per_operation: r.max_points_per_operation ?? '', daily_cap: r.daily_cap ?? '',
    active_from: String(r.active_from || '').slice(0, 10), active_to: String(r.active_to || '').slice(0, 10), status: r.status || 'active',
  }
  showModal.value = true
}

function payload() {
  const f = form.value
  const data = {
    name: f.name.trim(), type: f.type, multiplier: Number(f.multiplier) || 1, status: f.status,
    max_points_per_operation: f.max_points_per_operation === '' ? null : Number(f.max_points_per_operation),
    daily_cap: f.daily_cap === '' ? null : Number(f.daily_cap),
    active_from: f.active_from || null, active_to: f.active_to || null,
  }
  if (f.type === 'amount_spent') { data.points_per_unit = Number(f.points_per_unit); data.unit_amount = Number(f.unit_amount); data.fixed_points = null }
  else data.fixed_points = Number(f.fixed_points)
  if (!editingId.value) data.business_id = f.business_id
  return data
}

async function submit() {
  if (!form.value.name.trim() || (!editingId.value && !form.value.business_id)) return
  try {
    isSubmitting.value = true
    if (editingId.value) {
      await api.rules.update(editingId.value, payload())
      success(`Regla "${form.value.name}" actualizada.`)
    } else {
      await api.rules.create(payload())
      success(`Regla "${form.value.name}" creada exitosamente.`)
    }
    showModal.value = false
    await load()
  } catch (err) {
    error(apiMessage(err, 'No se pudo guardar la regla.'))
  } finally {
    isSubmitting.value = false
  }
}

async function toggle(r) {
  try {
    await api.rules.toggleStatus(r.id)
    success(`Estado de la regla "${r.name}" actualizado.`)
    await load()
  } catch (err) {
    error(apiMessage(err, 'No se pudo cambiar el estado.'))
  }
}

onMounted(load)
</script>

<template>
  <div class="page">
    <div class="phrow">
      <div class="ph">
        <h1 class="page-title">7.3 Reglas de acumulación</h1>
        <div class="page-subtitle">Panel de administración</div>
        <p class="page-desc">Cada negocio adherido define cómo otorga puntos: por importe o fijos, con multiplicador, tope por operación, tope diario y vigencia.</p>
      </div>
      <button v-if="canManage" class="btn btn-primary" @click="openCreate">＋ Nueva regla</button>
    </div>

    <div v-if="!isLoading && !rules.length" class="card" style="padding:24px;color:var(--cd-text-muted)">No hay reglas registradas.</div>
    <div class="rules-grid">
      <div v-for="r in rules" :key="r.id" class="rule-card card" :data-rule="r.name">
        <div class="rc-header">
          <div class="rc-value">{{ describe(r) }}</div>
          <StatusBadge :status="r.status === 'active' ? 'Activa' : 'Inactiva'" />
        </div>
        <div class="rc-name">{{ r.name }}</div>
        <div class="rc-desc">{{ typeLabels[r.type] || r.type }} · multiplicador ×{{ r.multiplier ?? 1 }}</div>
        <div class="rc-meta">
          <span>🏪 {{ businessName(r.business_id) }}</span>
          <span v-if="r.max_points_per_operation">📦 Máx. {{ r.max_points_per_operation }} pts/op</span>
          <span v-if="r.daily_cap">📅 Máx. {{ r.daily_cap }} pts/día</span>
        </div>
        <div v-if="canManage" style="display:flex;gap:8px;margin-top:8px">
          <button class="btn btn-ghost btn-sm" style="flex:1;justify-content:center" @click="openEdit(r)">Editar</button>
          <button class="btn btn-ghost btn-sm" style="flex:1;justify-content:center" :style="{ color: r.status === 'active' ? '#dc2626' : '#16a34a' }" @click="toggle(r)">
            {{ r.status === 'active' ? 'Desactivar regla' : 'Activar regla' }}
          </button>
        </div>
      </div>
    </div>

    <div v-if="showModal" class="modal-backdrop" @click.self="showModal = false">
      <div class="modal card" role="dialog" aria-labelledby="rule-title">
        <div class="modal-header">
          <h3 id="rule-title" class="modal-title">{{ editingId ? 'Editar regla de acumulación' : 'Nueva regla de acumulación' }}</h3>
          <button class="modal-close" aria-label="Cerrar" @click="showModal = false">✕</button>
        </div>
        <form class="modal-body" @submit.prevent="submit">
          <div class="form-group">
            <label class="cd-label" for="rule-business">Negocio adherido</label>
            <select id="rule-business" v-model="form.business_id" required class="cd-input" :disabled="!!editingId">
              <option value="" disabled>Selecciona un negocio</option>
              <option v-for="b in businesses" :key="b.business_id" :value="b.business_id">{{ b.business_name }} ({{ b.business_id }})</option>
            </select>
            <span v-if="!businesses.length" class="form-hint">Primero adhiere un negocio en «Negocios».</span>
          </div>
          <div class="form-group">
            <label class="cd-label" for="rule-name">Nombre de la regla</label>
            <input id="rule-name" v-model="form.name" required maxlength="255" class="cd-input" placeholder="Ej: 1 punto por cada $2 en souvenirs" />
          </div>
          <div class="form-row">
            <div class="form-group">
              <label class="cd-label" for="rule-type">Tipo de cálculo</label>
              <select id="rule-type" v-model="form.type" class="cd-input">
                <option v-for="(label, key) in typeLabels" :key="key" :value="key">{{ label }}</option>
              </select>
            </div>
            <div class="form-group">
              <label class="cd-label" for="rule-multiplier">Multiplicador</label>
              <input id="rule-multiplier" v-model.number="form.multiplier" type="number" step="0.1" min="0.1" class="cd-input" />
            </div>
          </div>
          <div v-if="form.type === 'amount_spent'" class="form-row">
            <div class="form-group">
              <label class="cd-label" for="rule-points">Puntos otorgados</label>
              <input id="rule-points" v-model.number="form.points_per_unit" type="number" min="0" class="cd-input" />
            </div>
            <div class="form-group">
              <label class="cd-label" for="rule-unit">Por cada importe de ($)</label>
              <input id="rule-unit" v-model.number="form.unit_amount" type="number" min="0.01" step="0.01" class="cd-input" />
            </div>
          </div>
          <div v-else class="form-group">
            <label class="cd-label" for="rule-fixed">Puntos fijos</label>
            <input id="rule-fixed" v-model.number="form.fixed_points" type="number" min="0" class="cd-input" />
          </div>
          <div class="form-row">
            <div class="form-group">
              <label class="cd-label" for="rule-max-op">Tope por operación (pts)</label>
              <input id="rule-max-op" v-model="form.max_points_per_operation" type="number" min="1" class="cd-input" placeholder="Sin tope" />
            </div>
            <div class="form-group">
              <label class="cd-label" for="rule-daily">Tope diario por alumno (pts)</label>
              <input id="rule-daily" v-model="form.daily_cap" type="number" min="1" class="cd-input" placeholder="Sin tope" />
            </div>
          </div>
          <div class="form-row">
            <div class="form-group">
              <label class="cd-label" for="rule-from">Vigente desde</label>
              <input id="rule-from" v-model="form.active_from" type="date" class="cd-input" />
            </div>
            <div class="form-group">
              <label class="cd-label" for="rule-to">Vigente hasta</label>
              <input id="rule-to" v-model="form.active_to" type="date" class="cd-input" />
            </div>
          </div>
          <div class="modal-actions">
            <button type="button" class="btn btn-secondary" @click="showModal = false">Cancelar</button>
            <button type="submit" class="btn btn-primary" :disabled="isSubmitting">
              {{ isSubmitting ? 'Guardando...' : (editingId ? 'Guardar cambios' : 'Crear Regla') }}
            </button>
          </div>
        </form>
      </div>
    </div>
  </div>
</template>

<style scoped>
.page{display:flex;flex-direction:column;gap:20px;}
.phrow{display:flex;align-items:flex-end;justify-content:space-between;gap:12px;flex-wrap:wrap;}
.ph{display:flex;flex-direction:column;gap:2px;}
.rules-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(260px,1fr));gap:16px;}
.rule-card{padding:18px;display:flex;flex-direction:column;gap:6px;}
.rc-header{display:flex;align-items:center;justify-content:space-between;margin-bottom:4px;gap:8px;}
.rc-value{font-size:17px;font-weight:800;color:var(--cd-primary);}
.rc-name{font-size:14px;font-weight:700;color:var(--cd-text-primary);}
.rc-desc{font-size:12px;color:var(--cd-text-secondary);}
.rc-meta{display:flex;gap:12px;flex-wrap:wrap;font-size:11px;color:var(--cd-text-muted);}
.modal-backdrop { position: fixed; inset: 0; background: rgba(0,0,0,0.45); display: flex; align-items: center; justify-content: center; z-index: 999; }
.modal { width: 100%; max-width: 540px; max-height: 92vh; overflow-y: auto; padding: 24px; display: flex; flex-direction: column; gap: 16px; }
.modal-header { display: flex; justify-content: space-between; align-items: center; }
.modal-title { font-size: 16px; font-weight: 700; margin: 0; }
.modal-close { background: none; border: none; font-size: 18px; cursor: pointer; color: var(--cd-text-muted); }
.modal-body { display: flex; flex-direction: column; gap: 14px; }
.form-group { display: flex; flex-direction: column; gap: 6px; }
.form-row { display: grid; grid-template-columns: repeat(2, 1fr); gap: 10px; }
.cd-label { font-size: 12px; font-weight: 600; color: var(--cd-text-secondary); }
.modal-actions { display: flex; justify-content: flex-end; gap: 10px; margin-top: 8px; }
.form-hint { font-size: 11px; color: var(--cd-text-muted); }
</style>
