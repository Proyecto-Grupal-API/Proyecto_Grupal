<script setup>
import PlatformLayout from '@/Layouts/PlatformLayout.vue'
defineOptions({ layout: PlatformLayout })
import { ref, computed, onMounted } from 'vue'
import StatusBadge from '@/Platform/components/ui/StatusBadge.vue'
import { useToast } from '@/Platform/composables/useToast.js'
import { useRole } from '@/Platform/composables/useRole.js'
import { api } from '@/Platform/services/api.js'

const { success, error } = useToast()
const { hasPermission, currentUser } = useRole()
const canPropose = computed(() => hasPermission('settings.update'))
const canApprove = computed(() => hasPermission('settings.approve'))
const apiMessage = (err, fallback) => Object.values(err.response?.data?.errors || {}).flat().join(' ') || err.response?.data?.message || err.response?.data?.error || fallback

const settings = ref([])
const versions = ref([])
const isLoading = ref(true)
const isSubmitting = ref(false)
const showModal = ref(false)
const form = ref({ config_key: '', value: '' })
const showRejectModal = ref(false)
const rejecting = ref(null)
const rejectReason = ref('')

const statusLabels = { draft: 'Borrador', pending_approval: 'Pendiente', approved: 'Aprobada', rejected: 'Rechazada', active: 'Activa', superseded: 'Sustituida' }
const text = v => (v !== null && typeof v === 'object') ? JSON.stringify(v) : String(v ?? '')
const mine = v => String(v.created_by) === String(currentUser.value.id)

async function load() {
  try {
    isLoading.value = true
    const [settingsRes, versionsRes] = await Promise.all([api.settings.list(), api.settings.getVersions()])
    const list = Array.isArray(settingsRes) ? settingsRes : (settingsRes.data || [])
    settings.value = list.map(s => ({ ...s, id: s._id || s.id }))
    const v = Array.isArray(versionsRes) ? versionsRes : (versionsRes.data || [])
    versions.value = v.map(x => ({ ...x, id: x._id || x.id }))
  } catch (err) {
    console.warn('No se pudo actualizar la información de Recompensas.', err)
  } finally {
    isLoading.value = false
  }
}

function openPropose(setting = null) {
  form.value = setting ? { config_key: setting.key, value: text(setting.value) } : { config_key: '', value: '' }
  showModal.value = true
}

// El valor se guarda como número si lo es; si no, como texto.
const parsed = raw => (raw.trim() !== '' && !Number.isNaN(Number(raw))) ? Number(raw) : raw.trim()

async function submitVersion() {
  if (!form.value.config_key.trim() || !String(form.value.value).trim()) return
  try {
    isSubmitting.value = true
    const res = await api.settings.createVersion({ config_key: form.value.config_key.trim(), value: parsed(String(form.value.value)) })
    success(`Versión ${res.version?.version ?? ''} de "${form.value.config_key.trim()}" guardada como borrador.`)
    showModal.value = false
    await load()
  } catch (err) {
    error(apiMessage(err, 'No se pudo guardar la versión.'))
  } finally {
    isSubmitting.value = false
  }
}

const done = { pending_approval: 'enviada a aprobación', approved: 'aprobada', active: 'activada', rejected: 'rechazada' }
async function transition(v, target, reason = null) {
  try {
    isSubmitting.value = true
    await api.settings.transitionVersion(v.id, target, reason)
    success(`Versión ${v.version} de "${v.config_key}" ${done[target]}.`)
    await load()
  } catch (err) {
    error(apiMessage(err, 'No se pudo cambiar el estado de la versión.'))
  } finally {
    isSubmitting.value = false
  }
}

function openReject(v) { rejecting.value = v; rejectReason.value = ''; showRejectModal.value = true }
async function confirmReject() {
  if (!rejecting.value || !rejectReason.value.trim()) return
  await transition(rejecting.value, 'rejected', rejectReason.value.trim())
  showRejectModal.value = false
}

onMounted(load)
</script>

<template>
  <div class="page">
    <div class="phrow">
      <div class="ph">
        <h1 class="page-title">7.11 Configuraciones sensibles y gobernanza</h1>
        <div class="page-subtitle">Panel de administración</div>
        <p class="page-desc">Los parámetros del programa cambian por versiones: una persona redacta y envía, otra con autoridad de aprobación aprueba y activa. Todo queda en la auditoría.</p>
      </div>
      <button v-if="canPropose" class="btn btn-primary" @click="openPropose()">＋ Nueva versión de parámetro</button>
    </div>

    <div class="card" data-seccion="parametros">
      <div class="card-header"><h3 class="card-title">Parámetros vigentes</h3></div>
      <div class="table-wrapper">
        <table class="cd-table">
          <thead><tr><th>Parámetro</th><th>Valor activo</th><th>Última modificación</th><th v-if="canPropose">Acción</th></tr></thead>
          <tbody>
            <tr v-for="s in settings" :key="s.id">
              <td style="font-weight:600">{{ s.key }}</td>
              <td><code style="font-size:12px;background:#f0f3f9;padding:3px 8px;border-radius:4px;font-weight:700">{{ text(s.value) }}</code></td>
              <td style="color:var(--cd-text-muted);font-size:12px;white-space:nowrap">{{ String(s.updated_at || '').slice(0, 10) }}</td>
              <td v-if="canPropose"><button class="btn btn-ghost btn-sm" @click="openPropose(s)">Proponer cambio</button></td>
            </tr>
            <tr v-if="!settings.length"><td :colspan="canPropose ? 4 : 3" style="text-align:center;color:var(--cd-text-muted);padding:24px">Aún no hay parámetros activos. Un parámetro aparece aquí cuando se activa su primera versión aprobada.</td></tr>
          </tbody>
        </table>
      </div>
    </div>

    <div class="card" data-seccion="versiones">
      <div class="card-header"><h3 class="card-title">Versiones y aprobaciones</h3></div>
      <div class="table-wrapper">
        <table class="cd-table">
          <thead><tr><th>Parámetro</th><th>Versión</th><th>Valor propuesto</th><th>Estado</th><th>Aprobó</th><th>Acción</th></tr></thead>
          <tbody>
            <tr v-for="v in versions" :key="v.id">
              <td style="font-weight:600">{{ v.config_key }}</td>
              <td>v{{ v.version }}</td>
              <td><code style="font-size:12px">{{ text(v.value) }}</code></td>
              <td><StatusBadge :status="statusLabels[v.status] || v.status" /></td>
              <td style="font-size:12px;color:var(--cd-text-muted)">{{ v.approved_by?.name || (v.status === 'rejected' ? (v.rejected_reason || '') : '—') }}</td>
              <td>
                <button v-if="v.status === 'draft' && canPropose" class="btn btn-ghost btn-sm" :disabled="isSubmitting" @click="transition(v, 'pending_approval')">Enviar a aprobación</button>
                <template v-else-if="v.status === 'pending_approval' && canApprove">
                  <button v-if="!mine(v)" class="btn btn-ghost btn-sm" style="color:#16a34a" :disabled="isSubmitting" @click="transition(v, 'approved')">Aprobar</button>
                  <button class="btn btn-ghost btn-sm" style="color:#dc2626" :disabled="isSubmitting" @click="openReject(v)">Rechazar</button>
                  <span v-if="mine(v)" style="font-size:11px;color:var(--cd-text-muted)">La aprueba otra persona</span>
                </template>
                <button v-else-if="v.status === 'approved' && canApprove && !mine(v)" class="btn btn-ghost btn-sm" :disabled="isSubmitting" @click="transition(v, 'active')">Activar</button>
                <span v-else-if="['pending_approval', 'approved'].includes(v.status)" style="font-size:11px;color:var(--cd-text-muted)">Espera a quien aprueba</span>
                <span v-else style="font-size:11px;color:var(--cd-text-muted)">—</span>
              </td>
            </tr>
            <tr v-if="!versions.length"><td colspan="6" style="text-align:center;color:var(--cd-text-muted);padding:24px">No hay versiones registradas.</td></tr>
          </tbody>
        </table>
      </div>
    </div>

    <div v-if="showModal" class="modal-backdrop" @click.self="showModal = false">
      <div class="modal card" role="dialog" aria-labelledby="version-title">
        <div class="modal-header">
          <h3 id="version-title" class="modal-title">Nueva versión de parámetro</h3>
          <button class="modal-close" aria-label="Cerrar" @click="showModal = false">✕</button>
        </div>
        <form class="modal-body" @submit.prevent="submitVersion">
          <div class="form-group">
            <label class="cd-label" for="version-key">Clave del parámetro</label>
            <input id="version-key" v-model="form.config_key" required maxlength="100" class="cd-input" placeholder="Ej: points.daily_cap" />
          </div>
          <div class="form-group">
            <label class="cd-label" for="version-value">Valor propuesto</label>
            <input id="version-value" v-model="form.value" required class="cd-input" placeholder="Ej: 500" />
            <span class="form-hint">Se guarda como borrador. No entra en vigor hasta que otra persona lo apruebe y lo active.</span>
          </div>
          <div class="modal-actions">
            <button type="button" class="btn btn-secondary" @click="showModal = false">Cancelar</button>
            <button type="submit" class="btn btn-primary" :disabled="isSubmitting">{{ isSubmitting ? 'Guardando...' : 'Guardar borrador' }}</button>
          </div>
        </form>
      </div>
    </div>

    <div v-if="showRejectModal" class="modal-backdrop" @click.self="showRejectModal = false">
      <div class="modal card" role="dialog" aria-labelledby="reject-title">
        <div class="modal-header">
          <h3 id="reject-title" class="modal-title">Rechazar versión</h3>
          <button class="modal-close" aria-label="Cerrar" @click="showRejectModal = false">✕</button>
        </div>
        <form class="modal-body" @submit.prevent="confirmReject">
          <div class="form-group">
            <label class="cd-label" for="reject-reason">Motivo del rechazo</label>
            <textarea id="reject-reason" v-model="rejectReason" rows="3" required maxlength="500" class="cd-input"></textarea>
          </div>
          <div class="modal-actions">
            <button type="button" class="btn btn-secondary" @click="showRejectModal = false">Cancelar</button>
            <button type="submit" class="btn btn-primary" :disabled="isSubmitting">Rechazar versión</button>
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
