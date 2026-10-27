<script setup>
import PlatformLayout from '@/Layouts/PlatformLayout.vue'
defineOptions({ layout: PlatformLayout })
import { ref, onMounted } from 'vue'
import StatusBadge from '@/Platform/components/ui/StatusBadge.vue'
import ConfirmModal from '@/Platform/components/ui/ConfirmModal.vue'
import { useToast } from '@/Platform/composables/useToast.js'
import { adminDashboardInitial } from '@/Platform/initialState.js'
import { api } from '@/Platform/services/api.js'

const { success, error } = useToast()
const data = ref({ ...adminDashboardInitial })
const fraudFlags = ref([])
const showAdjustModal = ref(false)
const showReviewModal = ref(false)
const selectedFlag = ref(null)
const isSubmitting = ref(false)
const isLoading = ref(true)

const adjustForm = ref({
  student_id: '',
  amount: 100,
  reason: 'Ajuste administrativo por participación destacada',
})

const reviewForm = ref({
  status: 'resolved',
  note: 'Revisado y confirmado por auditoría administrativa',
})

async function loadGovernance() {
  try {
    isLoading.value = true
    const [policiesRes, fraudRes] = await Promise.allSettled([
      api.governance.getPolicies(),
      api.governance.getFraudFlags(),
    ])

    if (fraudRes.status === 'fulfilled' && fraudRes.value) {
      const list = Array.isArray(fraudRes.value) ? fraudRes.value : (fraudRes.value.data || [])
      fraudFlags.value = list
      data.value.governance.detectedFrauds = list.length
    }
  } catch (err) {
    console.warn('No se pudo actualizar la información de Recompensas.', err)
  } finally {
    isLoading.value = false
  }
}

async function handleAdjustment() {
  if (!adjustForm.value.student_id.trim()) return

  try {
    isSubmitting.value = true
    await api.governance.manualAdjustment({
      student_id: adjustForm.value.student_id.trim(),
      amount: parseInt(adjustForm.value.amount),
      reason: adjustForm.value.reason,
    })

    success(`Ajuste de ${adjustForm.value.amount} puntos aplicado exitosamente.`)
    showAdjustModal.value = false
    adjustForm.value.student_id = ''
  } catch (err) {
    console.error('Error al aplicar ajuste:', err)
    error(err.message || 'No se pudo aplicar el ajuste manual.')
  } finally {
    isSubmitting.value = false
  }
}

function openReview(flag) {
  selectedFlag.value = flag
  showReviewModal.value = true
}

async function handleReviewFlag() {
  if (!selectedFlag.value) return

  try {
    isSubmitting.value = true
    await api.governance.reviewFraudFlag(selectedFlag.value._id || selectedFlag.value.id, {
      status: reviewForm.value.status,
      note: reviewForm.value.note,
    })

    success(`Alerta antifraude marcada como "${reviewForm.value.status}".`)
    showReviewModal.value = false
    selectedFlag.value = null
    await loadGovernance()
  } catch (err) {
    console.error('Error al revisar alerta:', err)
    error(err.message || 'No se pudo actualizar la alerta.')
  } finally {
    isSubmitting.value = false
  }
}

onMounted(() => {
  loadGovernance()
})
</script>

<template>
  <div class="page">
    <div class="phrow">
      <div class="ph">
        <h1 class="page-title">7.6 Gobierno de puntos y Antifraude</h1>
        <div class="page-subtitle">Panel de administración</div>
        <p class="page-desc">Supervisa políticas de emisión, expiración, alertas antifraude y ejecuta ajustes contables directos.</p>
      </div>
      <button class="btn btn-primary" @click="showAdjustModal = true">＋ Ajuste manual de puntos</button>
    </div>

    <div class="grid-2">
      <div class="card gov-section">
        <div class="gs-title">📋 Límites de emisión y topes</div>
        <div class="gs-list">
          <div class="gs-item"><span>Tope diario por estudiante</span><span class="gsv">500 pts</span></div>
          <div class="gs-item"><span>Tope mensual recomendado</span><span class="gsv">5,000 pts</span></div>
          <div class="gs-item"><span>Reglas con límite activo</span><span class="gsv" style="color:var(--cd-primary)">{{ data.governance.emissionLimits }}</span></div>
        </div>
      </div>

      <div class="card gov-section">
        <div class="gs-title">⏰ Política de expiración</div>
        <div class="gs-list">
          <div class="gs-item"><span>Expiración en 30 días</span><span class="gsv" style="color:#d97706">{{ data.governance.expiringPoints }}</span></div>
          <div class="gs-item"><span>Regla de inactividad</span><span class="gsv">12 meses sin tx</span></div>
          <div class="gs-item"><span>Notificación previa al alumno</span><span class="gsv" style="color:#16a34a">Activa (15 días)</span></div>
        </div>
      </div>

      <div class="card gov-section">
        <div class="gs-title">⚠ Alertas antifraude detectadas</div>
        <div class="gs-list">
          <div class="gs-item"><span>Fraudes / flags registrados</span><span class="gsv" style="color:#dc2626">{{ fraudFlags.length || data.governance.detectedFrauds }}</span></div>
          <div class="gs-item"><span>Umbral de ráfaga (rate limit)</span><span class="gsv">5 transacciones / hora</span></div>
          <div class="gs-item"><span>Bloqueo automático de cuenta</span><span class="gsv" style="color:#16a34a">Activo ante faltante</span></div>
        </div>
      </div>

      <div class="card gov-section">
        <div class="gs-title">🔄 Reversos y conciliación contable</div>
        <div class="gs-list">
          <div class="gs-item"><span>Mecanismo ledger</span><span class="gsv">Append-Only estricto</span></div>
          <div class="gs-item"><span>Manejo de saldo faltante</span><span class="gsv">Deuda diferida (pending_balance)</span></div>
          <div class="gs-item"><span>Auditoría de integridad</span><span class="gsv" style="color:#16a34a">100% Verificado</span></div>
        </div>
      </div>
    </div>

    <!-- Tabla de alertas antifraude -->
    <div class="card">
      <div class="card-header">
        <h3 class="card-title">Registro de alertas antifraude y banderas de sospecha</h3>
      </div>
      <div class="table-wrapper">
        <table class="cd-table">
          <thead>
            <tr>
              <th>ID Alerta</th>
              <th>Estudiante / Clave</th>
              <th>Severidad</th>
              <th>Motivo de Detección</th>
              <th>Fecha</th>
              <th>Estado</th>
              <th>Acción</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="f in fraudFlags" :key="f._id || f.id">
              <td><code style="font-size:11px">{{ (f._id || f.id).slice(-8) }}</code></td>
              <td><strong>{{ f.student_id }}</strong></td>
              <td>
                <span :style="{
                  color: f.severity === 'high' ? '#dc2626' : (f.severity === 'medium' ? '#d97706' : '#2878ED'),
                  fontWeight: 700
                }">
                  {{ (f.severity || 'MEDIA').toUpperCase() }}
                </span>
              </td>
              <td>{{ f.reason }}</td>
              <td style="color:var(--cd-text-muted);font-size:12px">{{ f.created_at ? f.created_at.slice(0, 10) : 'Hoy' }}</td>
              <td><StatusBadge :status="f.status === 'open' ? 'Pendiente' : (f.status === 'resolved' ? 'Completado' : 'Cancelado')" /></td>
              <td>
                <button v-if="f.status === 'open'" class="btn btn-ghost btn-sm" @click="openReview(f)">
                  Revisar
                </button>
                <span v-else style="color:var(--cd-text-muted);font-size:12px">Atendida</span>
              </td>
            </tr>
            <tr v-if="!fraudFlags.length">
              <td colspan="7" style="text-align:center;color:var(--cd-text-muted);padding:24px">
                No hay alertas de fraude activas. El sistema opera de forma íntegra.
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>

    <!-- Modal Ajuste Manual de Puntos -->
    <div v-if="showAdjustModal" class="modal-backdrop" @click.self="showAdjustModal = false">
      <div class="modal card">
        <div class="modal-header">
          <h3 class="modal-title">Ajuste Manual de Puntos</h3>
          <button class="modal-close" @click="showAdjustModal = false">✕</button>
        </div>
        <form @submit.prevent="handleAdjustment" class="modal-body">
          <div class="form-group">
            <label class="cd-label">ID o Matrícula del Estudiante</label>
            <input v-model="adjustForm.student_id" required class="cd-input" placeholder="ID estudiante (ej: ALU-2024-001 o Mongo ID)" />
          </div>

          <div class="form-group">
            <label class="cd-label">Cantidad de puntos (positivo para crédito, negativo para débito)</label>
            <input v-model.number="adjustForm.amount" type="number" required class="cd-input" />
          </div>

          <div class="form-group">
            <label class="cd-label">Justificación auditada</label>
            <textarea v-model="adjustForm.reason" required rows="3" class="cd-input" placeholder="Razón del ajuste manual..."></textarea>
          </div>

          <div class="modal-actions">
            <button type="button" class="btn btn-secondary" @click="showAdjustModal = false">Cancelar</button>
            <button type="submit" class="btn btn-primary" :disabled="isSubmitting">
              {{ isSubmitting ? 'Aplicando...' : 'Aplicar Ajuste' }}
            </button>
          </div>
        </form>
      </div>
    </div>

    <!-- Modal Revisar Alerta de Fraude -->
    <div v-if="showReviewModal" class="modal-backdrop" @click.self="showReviewModal = false">
      <div class="modal card">
        <div class="modal-header">
          <h3 class="modal-title">Dictaminar Alerta de Fraude</h3>
          <button class="modal-close" @click="showReviewModal = false">✕</button>
        </div>
        <form @submit.prevent="handleReviewFlag" class="modal-body">
          <div class="form-group">
            <label class="cd-label">Resolución</label>
            <select v-model="reviewForm.status" class="cd-input">
              <option value="resolved">Resolver (Confirmado / Saldo ajustado)</option>
              <option value="dismissed">Desestimar (Falso positivo)</option>
            </select>
          </div>

          <div class="form-group">
            <label class="cd-label">Nota de dictamen / auditoría</label>
            <textarea v-model="reviewForm.note" required rows="3" class="cd-input" placeholder="Dictamen del comité de recompensas..."></textarea>
          </div>

          <div class="modal-actions">
            <button type="button" class="btn btn-secondary" @click="showReviewModal = false">Cancelar</button>
            <button type="submit" class="btn btn-primary" :disabled="isSubmitting">
              {{ isSubmitting ? 'Guardando...' : 'Guardar Dictamen' }}
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
.grid-2{display:grid;grid-template-columns:repeat(2,1fr);gap:16px;}
.gov-section{padding:18px 20px;}
.gs-title{font-size:14px;font-weight:700;color:var(--cd-text-primary);margin-bottom:14px;}
.gs-list{display:flex;flex-direction:column;gap:10px;}
.gs-item{display:flex;justify-content:space-between;align-items:center;font-size:13px;color:var(--cd-text-secondary);border-bottom:1px solid var(--cd-border-light);padding-bottom:8px;}
.gs-item:last-child{border-bottom:none;}
.gsv{font-weight:700;color:var(--cd-text-primary);}

.modal-backdrop { position: fixed; inset: 0; background: rgba(0,0,0,0.45); display: flex; align-items: center; justify-content: center; z-index: 999; }
.modal { width: 100%; max-width: 500px; padding: 24px; display: flex; flex-direction: column; gap: 16px; }
.modal-header { display: flex; justify-content: space-between; align-items: center; }
.modal-title { font-size: 16px; font-weight: 700; margin: 0; }
.modal-close { background: none; border: none; font-size: 18px; cursor: pointer; color: var(--cd-text-muted); }
.modal-body { display: flex; flex-direction: column; gap: 14px; }
.form-group { display: flex; flex-direction: column; gap: 6px; }
.cd-label { font-size: 12px; font-weight: 600; color: var(--cd-text-secondary); }
.modal-actions { display: flex; justify-content: flex-end; gap: 10px; margin-top: 8px; }

@media(max-width:900px){.grid-2{grid-template-columns:1fr;}}
</style>
