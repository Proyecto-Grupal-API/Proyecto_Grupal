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
const canManage = computed(() => hasPermission('governance.manage'))
const canAdjust = computed(() => hasPermission('points.adjust'))
const canReview = computed(() => hasPermission('fraud.review'))
const apiMessage = (err, fallback) => Object.values(err.response?.data?.errors || {}).flat().join(' ') || err.response?.data?.message || err.response?.data?.error || fallback

const summary = ref(null)
const policies = ref([])
const fraudFlags = ref([])
const isLoading = ref(true)
const isSubmitting = ref(false)

const showAdjustModal = ref(false)
const adjustForm = ref({ student_id: '', amount: 100, reason: '' })

const showReviewModal = ref(false)
const selectedFlag = ref(null)
const reviewForm = ref({ status: 'resolved', notes: '' })

const showPolicyModal = ref(false)
const editingPolicy = ref(null)
const blankPolicy = () => ({ name: '', type: 'limits', daily_cap_points: '', max_redemptions_per_day: '', expiration_days: '', reversal_grace_period_days: '', status: 'active' })
const policyForm = ref(blankPolicy())
const policyTypes = { limits: 'Límites de emisión y canje', expiration: 'Expiración de puntos', reversal: 'Reversos por devolución' }

const show = (v, unit = '') => (v === null || v === undefined || v === '') ? 'Sin definir' : `${Number(v).toLocaleString('es-MX')}${unit}`
const statusLabel = { open: 'Pendiente', under_review: 'En revisión', resolved: 'Completado', dismissed: 'Cancelado' }

async function load() {
  try {
    isLoading.value = true
    const [summaryRes, policiesRes, fraudRes] = await Promise.allSettled([api.governance.getSummary(), api.governance.getPolicies(), api.governance.getFraudFlags()])
    summary.value = summaryRes.status === 'fulfilled' ? summaryRes.value : null
    const p = policiesRes.status === 'fulfilled' ? policiesRes.value : []
    policies.value = (Array.isArray(p) ? p : (p.data || [])).map(x => ({ ...x, id: x._id || x.id }))
    const f = fraudRes.status === 'fulfilled' ? fraudRes.value : []
    fraudFlags.value = Array.isArray(f) ? f : (f.data || [])
  } finally {
    isLoading.value = false
  }
}

async function handleAdjustment() {
  if (!adjustForm.value.student_id.trim() || !adjustForm.value.reason.trim()) return
  try {
    isSubmitting.value = true
    await api.governance.manualAdjustment({ student_id: adjustForm.value.student_id.trim(), amount: parseInt(adjustForm.value.amount), reason: adjustForm.value.reason.trim() })
    success(`Ajuste de ${adjustForm.value.amount} puntos aplicado exitosamente.`)
    showAdjustModal.value = false
    adjustForm.value = { student_id: '', amount: 100, reason: '' }
    await load()
  } catch (err) {
    error(apiMessage(err, 'No se pudo aplicar el ajuste manual.'))
  } finally {
    isSubmitting.value = false
  }
}

function openReview(flag) {
  selectedFlag.value = flag
  reviewForm.value = { status: 'resolved', notes: '' }
  showReviewModal.value = true
}

async function handleReviewFlag() {
  if (!selectedFlag.value || !reviewForm.value.notes.trim()) return
  try {
    isSubmitting.value = true
    await api.governance.reviewFraudFlag(selectedFlag.value._id || selectedFlag.value.id, { status: reviewForm.value.status, notes: reviewForm.value.notes.trim() })
    success('Dictamen de la alerta guardado.')
    showReviewModal.value = false
    selectedFlag.value = null
    await load()
  } catch (err) {
    error(apiMessage(err, 'No se pudo actualizar la alerta.'))
  } finally {
    isSubmitting.value = false
  }
}

function openPolicy(policy = null) {
  editingPolicy.value = policy
  policyForm.value = policy ? {
    name: policy.name, type: policy.type || 'limits', daily_cap_points: policy.daily_cap_points ?? '', max_redemptions_per_day: policy.max_redemptions_per_day ?? '',
    expiration_days: policy.expiration_days ?? '', reversal_grace_period_days: policy.reversal_grace_period_days ?? '', status: policy.status || 'active',
  } : blankPolicy()
  showPolicyModal.value = true
}

async function submitPolicy() {
  const f = policyForm.value
  if (!f.name.trim()) return
  const num = v => (v === '' || v === null) ? null : Number(v)
  const data = { name: f.name.trim(), daily_cap_points: num(f.daily_cap_points), max_redemptions_per_day: num(f.max_redemptions_per_day),
    expiration_days: num(f.expiration_days), reversal_grace_period_days: num(f.reversal_grace_period_days), status: f.status }
  try {
    isSubmitting.value = true
    if (editingPolicy.value) {
      await api.governance.updatePolicy(editingPolicy.value.id, data)
      success(`Política "${f.name}" actualizada.`)
    } else {
      await api.governance.createPolicy({ ...data, type: f.type })
      success(`Política "${f.name}" creada.`)
    }
    showPolicyModal.value = false
    await load()
  } catch (err) {
    error(apiMessage(err, 'No se pudo guardar la política.'))
  } finally {
    isSubmitting.value = false
  }
}

async function togglePolicy(policy) {
  try {
    await api.governance.updatePolicy(policy.id, { status: policy.status === 'active' ? 'inactive' : 'active' })
    success(`Política "${policy.name}" ${policy.status === 'active' ? 'desactivada' : 'activada'}.`)
    await load()
  } catch (err) {
    error(apiMessage(err, 'No se pudo cambiar el estado de la política.'))
  }
}

onMounted(load)
</script>

<template>
  <div class="page">
    <div class="phrow">
      <div class="ph">
        <h1 class="page-title">7.6 Gobierno de puntos y Antifraude</h1>
        <div class="page-subtitle">Panel de administración</div>
        <p class="page-desc">Políticas de emisión, canje y expiración, alertas antifraude, conciliación del programa y ajustes administrativos.</p>
      </div>
      <div style="display:flex;gap:8px;flex-wrap:wrap">
        <button v-if="canManage" class="btn btn-secondary" @click="openPolicy()">＋ Nueva política</button>
        <button v-if="canAdjust" class="btn btn-primary" @click="showAdjustModal = true">＋ Ajuste manual de puntos</button>
      </div>
    </div>

    <div v-if="summary" class="grid-2" data-seccion="indicadores">
      <div class="card gov-section">
        <div class="gs-title">📋 Límites vigentes</div>
        <div class="gs-list">
          <div class="gs-item"><span>Tope diario general por alumno</span><span class="gsv" data-indicador="tope-diario">{{ show(summary.effective_daily_cap_points, ' pts') }}</span></div>
          <div class="gs-item"><span>Canjes máximos por alumno y día</span><span class="gsv" data-indicador="canjes-dia">{{ show(summary.effective_max_redemptions_per_day) }}</span></div>
          <div class="gs-item"><span>Reglas con tope diario / por operación</span><span class="gsv">{{ summary.rules_with_daily_cap }} / {{ summary.rules_with_operation_cap }}</span></div>
        </div>
      </div>
      <div class="card gov-section">
        <div class="gs-title">📑 Políticas</div>
        <div class="gs-list">
          <div class="gs-item"><span>Políticas registradas</span><span class="gsv" data-indicador="politicas">{{ summary.policies_total }}</span></div>
          <div class="gs-item"><span>Políticas activas</span><span class="gsv" style="color:#16a34a" data-indicador="politicas-activas">{{ summary.policies_active }}</span></div>
        </div>
      </div>
      <div class="card gov-section">
        <div class="gs-title">⚠ Alertas antifraude</div>
        <div class="gs-list">
          <div class="gs-item"><span>Alertas abiertas</span><span class="gsv" style="color:#dc2626" data-indicador="alertas-abiertas">{{ summary.fraud_open }}</span></div>
          <div class="gs-item"><span>Alertas registradas</span><span class="gsv">{{ summary.fraud_total }}</span></div>
        </div>
      </div>
      <div class="card gov-section">
        <div class="gs-title">🔄 Conciliación del programa</div>
        <div class="gs-list">
          <div class="gs-item"><span>Cuentas de puntos</span><span class="gsv">{{ summary.accounts_total }}</span></div>
          <div class="gs-item"><span>Cuentas cuyo saldo no cuadra con el ledger</span><span class="gsv" :style="{ color: summary.accounts_out_of_balance ? '#dc2626' : '#16a34a' }" data-indicador="descuadres">{{ summary.accounts_out_of_balance }}</span></div>
          <div class="gs-item"><span>Saldo pendiente por faltantes en reversos</span><span class="gsv">{{ summary.pending_balance_total }} pts en {{ summary.accounts_with_pending_balance }} cuentas</span></div>
        </div>
      </div>
    </div>

    <div class="card" data-seccion="politicas">
      <div class="card-header"><h3 class="card-title">Políticas de gobierno</h3></div>
      <div class="table-wrapper">
        <table class="cd-table">
          <thead><tr><th>Política</th><th>Tipo</th><th>Tope diario</th><th>Canjes por día</th><th>Expiración</th><th>Gracia de reverso</th><th>Estado</th><th v-if="canManage">Acción</th></tr></thead>
          <tbody>
            <tr v-for="p in policies" :key="p.id">
              <td style="font-weight:600">{{ p.name }}</td>
              <td>{{ policyTypes[p.type] || p.type }}</td>
              <td>{{ show(p.daily_cap_points, ' pts') }}</td>
              <td>{{ show(p.max_redemptions_per_day) }}</td>
              <td>{{ show(p.expiration_days, ' días') }}</td>
              <td>{{ show(p.reversal_grace_period_days, ' días') }}</td>
              <td><StatusBadge :status="p.status === 'active' ? 'Activa' : 'Inactiva'" /></td>
              <td v-if="canManage">
                <button class="btn btn-ghost btn-sm" @click="openPolicy(p)">Editar</button>
                <button class="btn btn-ghost btn-sm" :style="{ color: p.status === 'active' ? '#dc2626' : '#16a34a' }" @click="togglePolicy(p)">{{ p.status === 'active' ? 'Desactivar' : 'Activar' }}</button>
              </td>
            </tr>
            <tr v-if="!policies.length"><td :colspan="canManage ? 8 : 7" style="text-align:center;color:var(--cd-text-muted);padding:24px">No hay políticas registradas.</td></tr>
          </tbody>
        </table>
      </div>
      <p class="form-hint" style="padding:0 16px 14px">El tope diario y el máximo de canjes por día de las políticas activas se aplican en cada acumulación y en cada canje; si varias fijan el mismo límite, rige el más estricto. La expiración y la gracia de reverso quedan registradas como política; el motor aún no las ejecuta de forma automática.</p>
    </div>

    <div class="card" data-seccion="alertas">
      <div class="card-header"><h3 class="card-title">Registro de alertas antifraude</h3></div>
      <div class="table-wrapper">
        <table class="cd-table">
          <thead><tr><th>ID Alerta</th><th>Alumno</th><th>Severidad</th><th>Motivo de detección</th><th>Fecha</th><th>Estado</th><th>Acción</th></tr></thead>
          <tbody>
            <tr v-for="f in fraudFlags" :key="f._id || f.id">
              <td><code style="font-size:11px">{{ String(f._id || f.id).slice(-8) }}</code></td>
              <td><strong>{{ f.student_id }}</strong></td>
              <td><span :style="{ color: f.severity === 'high' ? '#dc2626' : (f.severity === 'medium' ? '#d97706' : '#2878ED'), fontWeight: 700 }">{{ (f.severity || 'medium').toUpperCase() }}</span></td>
              <td>{{ f.reason }}</td>
              <td style="color:var(--cd-text-muted);font-size:12px">{{ String(f.created_at || '').slice(0, 10) }}</td>
              <td><StatusBadge :status="statusLabel[f.status] || f.status" /></td>
              <td>
                <button v-if="canReview && ['open', 'under_review'].includes(f.status)" class="btn btn-ghost btn-sm" @click="openReview(f)">Revisar</button>
                <span v-else style="color:var(--cd-text-muted);font-size:12px">{{ ['open', 'under_review'].includes(f.status) ? 'Pendiente' : 'Atendida' }}</span>
              </td>
            </tr>
            <tr v-if="!fraudFlags.length"><td colspan="7" style="text-align:center;color:var(--cd-text-muted);padding:24px">No hay alertas antifraude registradas.</td></tr>
          </tbody>
        </table>
      </div>
    </div>

    <div v-if="showAdjustModal" class="modal-backdrop" @click.self="showAdjustModal = false">
      <div class="modal card" role="dialog" aria-labelledby="adjust-title">
        <div class="modal-header">
          <h3 id="adjust-title" class="modal-title">Ajuste Manual de Puntos</h3>
          <button class="modal-close" aria-label="Cerrar" @click="showAdjustModal = false">✕</button>
        </div>
        <form class="modal-body" @submit.prevent="handleAdjustment">
          <div class="form-group">
            <label class="cd-label" for="adjust-student">Identificador de recompensas del alumno</label>
            <input id="adjust-student" v-model="adjustForm.student_id" required class="cd-input" placeholder="El alumno lo ve en su resumen de Recompensas" />
            <span class="form-hint">Debe corresponder a un alumno registrado; si no, el ajuste se rechaza.</span>
          </div>
          <div class="form-group">
            <label class="cd-label" for="adjust-amount">Puntos (positivo abona, negativo descuenta)</label>
            <input id="adjust-amount" v-model.number="adjustForm.amount" type="number" required class="cd-input" />
          </div>
          <div class="form-group">
            <label class="cd-label" for="adjust-reason">Razón del ajuste (queda en la auditoría)</label>
            <textarea id="adjust-reason" v-model="adjustForm.reason" rows="2" required maxlength="500" class="cd-input" placeholder="Razón del ajuste manual..."></textarea>
          </div>
          <div class="modal-actions">
            <button type="button" class="btn btn-secondary" @click="showAdjustModal = false">Cancelar</button>
            <button type="submit" class="btn btn-primary" :disabled="isSubmitting">{{ isSubmitting ? 'Aplicando...' : 'Aplicar Ajuste' }}</button>
          </div>
        </form>
      </div>
    </div>

    <div v-if="showReviewModal" class="modal-backdrop" @click.self="showReviewModal = false">
      <div class="modal card" role="dialog" aria-labelledby="review-title">
        <div class="modal-header">
          <h3 id="review-title" class="modal-title">Dictamen de alerta antifraude</h3>
          <button class="modal-close" aria-label="Cerrar" @click="showReviewModal = false">✕</button>
        </div>
        <form class="modal-body" @submit.prevent="handleReviewFlag">
          <p style="font-size:13px;margin:0"><strong>{{ selectedFlag?.reason }}</strong><br />Alumno {{ selectedFlag?.student_id }}</p>
          <div class="form-group">
            <label class="cd-label" for="review-status">Resolución</label>
            <select id="review-status" v-model="reviewForm.status" class="cd-input">
              <option value="under_review">Dejar en revisión</option>
              <option value="resolved">Resuelta (procede)</option>
              <option value="dismissed">Descartada (falso positivo)</option>
            </select>
          </div>
          <div class="form-group">
            <label class="cd-label" for="review-notes">Notas del dictamen</label>
            <textarea id="review-notes" v-model="reviewForm.notes" rows="3" required maxlength="1000" class="cd-input" placeholder="Qué se revisó y qué se decidió..."></textarea>
          </div>
          <div class="modal-actions">
            <button type="button" class="btn btn-secondary" @click="showReviewModal = false">Cancelar</button>
            <button type="submit" class="btn btn-primary" :disabled="isSubmitting">{{ isSubmitting ? 'Guardando...' : 'Guardar Dictamen' }}</button>
          </div>
        </form>
      </div>
    </div>

    <div v-if="showPolicyModal" class="modal-backdrop" @click.self="showPolicyModal = false">
      <div class="modal card" role="dialog" aria-labelledby="policy-title">
        <div class="modal-header">
          <h3 id="policy-title" class="modal-title">{{ editingPolicy ? 'Editar política' : 'Nueva política de gobierno' }}</h3>
          <button class="modal-close" aria-label="Cerrar" @click="showPolicyModal = false">✕</button>
        </div>
        <form class="modal-body" @submit.prevent="submitPolicy">
          <div class="form-group">
            <label class="cd-label" for="policy-name">Nombre de la política</label>
            <input id="policy-name" v-model="policyForm.name" required maxlength="255" class="cd-input" placeholder="Ej: Tope general del semestre" />
          </div>
          <div class="form-row">
            <div class="form-group">
              <label class="cd-label" for="policy-type">Tipo</label>
              <select id="policy-type" v-model="policyForm.type" class="cd-input" :disabled="!!editingPolicy">
                <option v-for="(label, key) in policyTypes" :key="key" :value="key">{{ label }}</option>
              </select>
            </div>
            <div class="form-group">
              <label class="cd-label" for="policy-status">Estado</label>
              <select id="policy-status" v-model="policyForm.status" class="cd-input"><option value="active">Activa</option><option value="inactive">Inactiva</option></select>
            </div>
          </div>
          <div class="form-row">
            <div class="form-group">
              <label class="cd-label" for="policy-daily">Tope diario por alumno (pts)</label>
              <input id="policy-daily" v-model="policyForm.daily_cap_points" type="number" min="1" class="cd-input" placeholder="Sin definir" />
            </div>
            <div class="form-group">
              <label class="cd-label" for="policy-redemptions">Canjes máximos por día</label>
              <input id="policy-redemptions" v-model="policyForm.max_redemptions_per_day" type="number" min="1" class="cd-input" placeholder="Sin definir" />
            </div>
          </div>
          <div class="form-row">
            <div class="form-group">
              <label class="cd-label" for="policy-expiration">Expiración de puntos (días)</label>
              <input id="policy-expiration" v-model="policyForm.expiration_days" type="number" min="1" class="cd-input" placeholder="Sin definir" />
            </div>
            <div class="form-group">
              <label class="cd-label" for="policy-grace">Gracia para reversos (días)</label>
              <input id="policy-grace" v-model="policyForm.reversal_grace_period_days" type="number" min="0" class="cd-input" placeholder="Sin definir" />
            </div>
          </div>
          <div class="modal-actions">
            <button type="button" class="btn btn-secondary" @click="showPolicyModal = false">Cancelar</button>
            <button type="submit" class="btn btn-primary" :disabled="isSubmitting">{{ isSubmitting ? 'Guardando...' : (editingPolicy ? 'Guardar cambios' : 'Crear Política') }}</button>
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
.grid-2{display:grid;grid-template-columns:repeat(auto-fit,minmax(280px,1fr));gap:16px;}
.gov-section{padding:18px;}
.gs-title{font-size:14px;font-weight:700;margin-bottom:10px;}
.gs-list{display:flex;flex-direction:column;gap:8px;}
.gs-item{display:flex;justify-content:space-between;gap:12px;font-size:13px;color:var(--cd-text-secondary);}
.gsv{font-weight:700;color:var(--cd-text-primary);text-align:right;}
.card-header{padding:14px 16px 0;}
.card-title{font-size:15px;font-weight:700;margin:0;}
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
