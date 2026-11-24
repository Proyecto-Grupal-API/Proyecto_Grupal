<script setup>
import PlatformLayout from '@/Layouts/PlatformLayout.vue'
defineOptions({ layout: PlatformLayout })
import { ref, computed, onMounted } from 'vue'
import { useRole } from '@/Platform/composables/useRole.js'
import StatusBadge from '@/Platform/components/ui/StatusBadge.vue'
import { useToast } from '@/Platform/composables/useToast.js'
import { ledgerInitial } from '@/Platform/initialState.js'
import { api } from '@/Platform/services/api.js'

const { success, error } = useToast()
const { hasPermission } = useRole()
const canEarn = computed(() => hasPermission('points.earn'))
const canReverse = computed(() => hasPermission('points.reverse'))
const canReconcile = computed(() => hasPermission('points.reconcile'))
const apiMessage = (err, fallback) => Object.values(err.response?.data?.errors || {}).flat().join(' ') || err.response?.data?.message || err.response?.data?.error || fallback
const search = ref('')
const selectedType = ref('all')
const ledgerList = ref([...ledgerInitial])
const isLoading = ref(true)
const isSubmitting = ref(false)
const businesses = ref([])
const showEarnModal = ref(false)
const earnForm = ref({ student_id: '', business_id: '', sale_amount: '', source_reference: '' })
const showReverseModal = ref(false)
const reversing = ref(null)
const reverseReason = ref('')
const exportUrl = computed(() => {
  const params = new URLSearchParams()
  if (selectedType.value !== 'all') params.set('type', selectedType.value)
  if (search.value.trim()) params.set('search', search.value.trim())
  const qs = params.toString()
  return '/api/v1/recompensas/points/ledger/export' + (qs ? `?${qs}` : '')
})

async function openEarn() {
  try {
    const res = await api.businesses.list({ per_page: 100, status: 'active' })
    businesses.value = Array.isArray(res) ? res : (res.data || [])
  } catch { businesses.value = [] }
  earnForm.value = { student_id: '', business_id: businesses.value[0]?.business_id || '', sale_amount: '', source_reference: '' }
  showEarnModal.value = true
}

// Acumulación por compra: aplica la regla activa del negocio, la campaña vigente y los topes.
async function submitEarn() {
  const f = earnForm.value
  if (!f.student_id.trim() || !f.business_id || !f.sale_amount || !f.source_reference.trim()) return
  try {
    isSubmitting.value = true
    const res = await api.points.earn({ student_id: f.student_id.trim(), business_id: f.business_id, sale_amount: Number(f.sale_amount),
      source_domain: 'mostrador', source_reference: f.source_reference.trim(), idempotency_key: `mostrador:${f.business_id}:${f.source_reference.trim()}` })
    success(res.replayed ? 'Esa compra ya había acumulado puntos; no se duplicó.' : `Compra registrada: ${res.ledger?.amount ?? 0} puntos acumulados.`)
    showEarnModal.value = false
    await loadLedger()
  } catch (err) {
    error(apiMessage(err, 'No se pudo registrar la acumulación.'))
  } finally {
    isSubmitting.value = false
  }
}

function openReverse(l) { reversing.value = l; reverseReason.value = ''; showReverseModal.value = true }
async function submitReverse() {
  if (!reversing.value || !reverseReason.value.trim()) return
  try {
    isSubmitting.value = true
    const res = await api.points.reverse({ related_ledger_id: reversing.value._id, reason: reverseReason.value.trim() })
    success(res.shortfall > 0 ? `Movimiento reversado. El alumno ya había usado ${res.shortfall} puntos: quedó saldo pendiente y una alerta para dictamen.` : 'Movimiento reversado.')
    showReverseModal.value = false
    await loadLedger()
  } catch (err) {
    error(apiMessage(err, 'No se pudo reversar el movimiento.'))
  } finally {
    isSubmitting.value = false
  }
}

async function loadLedger() {
  try {
    isLoading.value = true
    const params = {}
    if (selectedType.value !== 'all') params.type = selectedType.value
    if (search.value.trim()) params.search = search.value.trim()

    const res = await api.points.getLedger(params)
    const list = Array.isArray(res) ? res : (res.data || [])
    // Sin coincidencias la tabla debe vaciarse (antes conservaba las filas de la búsqueda anterior).
    {
      ledgerList.value = list.map(l => ({
        id: l._id || l.id,
        _id: l._id || l.id,
        reversible: l.type === 'earn',
        student: l.student?.name || l.student_id || 'Estudiante',
        sid: l.student?.student_code || l.student_id?.slice(-8) || 'MAT-000',
        studentId: l.student_id,
        movement: l.type,
        points: Math.abs(l.amount || 0),
        reference: l.source_reference || l.idempotency_key?.slice(0, 10) || 'REF-TX',
        date: l.created_at ? l.created_at.slice(0, 19).replace('T', ' ') : 'Reciente',
        status: l.type === 'reverse' ? 'Revertido' : (l.type === 'redeem' ? 'Canjeado' : 'Completado'),
      }))
    }
  } catch (err) {
    console.warn('No se pudo actualizar la información de Recompensas.', err)
  } finally {
    isLoading.value = false
  }
}

async function handleReconcile(studentId) {
  if (!studentId) return
  try {
    const res = await api.points.reconcile(studentId)
    success(`Cuenta reconciliada exitosamente. Saldo auditado: ${res.account?.balance ?? res.balance ?? 0} pts.`)
    await loadLedger()
  } catch (err) {
    console.error('Error al reconciliar:', err)
    error(err.message || 'Error al reconciliar saldo.')
  }
}

function mvtLabel(t) { return { earn: 'Ganancia', redeem: 'Canje', reverse: 'Reverso', adjust: 'Ajuste' }[t] || t }
function ptsColor(m) { return { earn: '#16a34a', redeem: '#dc2626', reverse: '#d97706' }[m] || '#2878ED' }
function ptsSign(m) { return { earn: '+', redeem: '-', reverse: '±', adjust: '±' }[m] || '' }

onMounted(() => {
  loadLedger()
})
</script>

<template>
  <div class="page">
    <div class="phrow">
      <div class="ph">
        <h1 class="page-title">7.1 Ledger global de puntos</h1>
        <div class="page-subtitle">Panel de administración</div>
        <p class="page-desc">Libro mayor inmutable y de solo anexión (append-only) que registra cada emisión, canje y reverso de puntos en el campus.</p>
      </div>
      <button v-if="canEarn" class="btn btn-primary" @click="openEarn">＋ Registrar compra</button>
    </div>

    <div class="filters-bar">
      <input
        v-model="search"
        class="cd-input"
        placeholder="Buscar por estudiante, matrícula o referencia…"
        style="max-width:360px"
        @keyup.enter="loadLedger"
      />
      <select v-model="selectedType" class="cd-input" style="max-width:160px" @change="loadLedger">
        <option value="all">Todos los tipos</option>
        <option value="earn">Ganancia (earn)</option>
        <option value="redeem">Canje (redeem)</option>
        <option value="reverse">Reverso (reverse)</option>
        <option value="adjust">Ajuste (adjust)</option>
      </select>
      <button class="btn btn-secondary" @click="loadLedger">Filtrar</button>
      <a :href="exportUrl" class="btn btn-secondary" download>Exportar CSV</a>
    </div>

    <div class="card">
      <div class="table-wrapper">
        <table class="cd-table">
          <thead>
            <tr>
              <th>Estudiante</th>
              <th>Matrícula / ID</th>
              <th>Movimiento</th>
              <th>Puntos</th>
              <th>Referencia / Clave</th>
              <th>Fecha y Hora</th>
              <th>Estado</th>
              <th>Acción</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="l in ledgerList" :key="l.id">
              <td style="font-weight:600">{{ l.student }}</td>
              <td style="color:var(--cd-text-muted);font-size:12px">{{ l.sid }}</td>
              <td><span class="mvt-badge" :class="l.movement">{{ mvtLabel(l.movement) }}</span></td>
              <td><span :style="{ color: ptsColor(l.movement), fontWeight: 700 }">{{ ptsSign(l.movement) }}{{ l.points }} pts</span></td>
              <td><code style="font-size:11px;background:#f0f3f9;padding:2px 6px;border-radius:4px">{{ l.reference }}</code></td>
              <td style="color:var(--cd-text-muted);font-size:12px;white-space:nowrap">{{ l.date }}</td>
              <td><StatusBadge :status="l.status" /></td>
              <td>
                <button
                  v-if="l.studentId && canReconcile"
                  class="btn btn-ghost btn-sm"
                  title="Auditar y conciliar balance recalculando desde la suma de ledger"
                  @click="handleReconcile(l.studentId)"
                >
                  Conciliar
                </button>
                <button v-if="l.reversible && canReverse" class="btn btn-ghost btn-sm" style="color:#d97706" @click="openReverse(l)">Reversar</button>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>

    <div v-if="showEarnModal" class="modal-backdrop" @click.self="showEarnModal = false">
      <div class="modal card" role="dialog" aria-labelledby="earn-title">
        <div class="modal-header">
          <h3 id="earn-title" class="modal-title">Registrar compra para acumular puntos</h3>
          <button class="modal-close" aria-label="Cerrar" @click="showEarnModal = false">✕</button>
        </div>
        <form class="modal-body" @submit.prevent="submitEarn">
          <div class="form-group">
            <label class="cd-label" for="earn-student">Identificador de recompensas del alumno</label>
            <input id="earn-student" v-model="earnForm.student_id" required class="cd-input" placeholder="El alumno lo ve en su resumen de Recompensas" />
          </div>
          <div class="form-group">
            <label class="cd-label" for="earn-business">Negocio adherido</label>
            <select id="earn-business" v-model="earnForm.business_id" required class="cd-input">
              <option value="" disabled>Selecciona un negocio</option>
              <option v-for="b in businesses" :key="b.business_id" :value="b.business_id">{{ b.business_name }} ({{ b.business_id }})</option>
            </select>
          </div>
          <div class="form-row">
            <div class="form-group">
              <label class="cd-label" for="earn-amount">Importe de la compra ($)</label>
              <input id="earn-amount" v-model="earnForm.sale_amount" type="number" min="0" step="0.01" required class="cd-input" />
            </div>
            <div class="form-group">
              <label class="cd-label" for="earn-reference">Folio o referencia de la venta</label>
              <input id="earn-reference" v-model="earnForm.source_reference" required maxlength="100" class="cd-input" placeholder="Ej: TICKET-00123" />
            </div>
          </div>
          <span class="form-hint">Los puntos los calcula la regla activa del negocio, con su multiplicador, la campaña vigente y los topes. El mismo folio no acumula dos veces.</span>
          <div class="modal-actions">
            <button type="button" class="btn btn-secondary" @click="showEarnModal = false">Cancelar</button>
            <button type="submit" class="btn btn-primary" :disabled="isSubmitting">{{ isSubmitting ? 'Registrando...' : 'Acumular puntos' }}</button>
          </div>
        </form>
      </div>
    </div>

    <div v-if="showReverseModal" class="modal-backdrop" @click.self="showReverseModal = false">
      <div class="modal card" role="dialog" aria-labelledby="reverse-title">
        <div class="modal-header">
          <h3 id="reverse-title" class="modal-title">Reversar acumulación</h3>
          <button class="modal-close" aria-label="Cerrar" @click="showReverseModal = false">✕</button>
        </div>
        <form class="modal-body" @submit.prevent="submitReverse">
          <p style="font-size:13px;margin:0">Se revertirán <strong>{{ reversing?.points }} pts</strong> de la referencia <code>{{ reversing?.reference }}</code>. Si el alumno ya los usó, queda saldo pendiente y se abre una alerta.</p>
          <div class="form-group">
            <label class="cd-label" for="reverse-reason">Motivo del reverso</label>
            <textarea id="reverse-reason" v-model="reverseReason" rows="2" required maxlength="500" class="cd-input" placeholder="Ej: devolución de la compra"></textarea>
          </div>
          <div class="modal-actions">
            <button type="button" class="btn btn-secondary" @click="showReverseModal = false">Cancelar</button>
            <button type="submit" class="btn btn-primary" :disabled="isSubmitting">{{ isSubmitting ? 'Reversando...' : 'Confirmar reverso' }}</button>
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
.filters-bar{display:flex;gap:10px;flex-wrap:wrap;align-items:center;}
.mvt-badge{padding:2px 10px;border-radius:12px;font-size:12px;font-weight:600;}
.earn{background:#f0fdf4;color:#16a34a;}
.redeem{background:#fef2f2;color:#dc2626;}
.reverse{background:#fffbeb;color:#d97706;}
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
