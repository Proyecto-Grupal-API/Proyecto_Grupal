<script setup>
import PlatformLayout from '@/Layouts/PlatformLayout.vue'
defineOptions({ layout: PlatformLayout })
import { ref, onMounted } from 'vue'
import StatusBadge from '@/Platform/components/ui/StatusBadge.vue'
import ConfirmModal from '@/Platform/components/ui/ConfirmModal.vue'
import { useToast } from '@/Platform/composables/useToast.js'
import { useRole } from '@/Platform/composables/useRole.js'
import { settingsInitial } from '@/Platform/initialState.js'
import { api } from '@/Platform/services/api.js'

const { success, error } = useToast()
const { isPlatformAdmin } = useRole()
const settingsList = ref([...settingsInitial])
const showEditModal = ref(false)
const selectedSetting = ref(null)
const editValue = ref('')
const editReason = ref('Actualización de parámetro por política de seguridad')
const isSubmitting = ref(false)
const isLoading = ref(true)

async function loadSettings() {
  try {
    isLoading.value = true
    const res = await api.settings.list()
    if (res && res.settings) {
      const entries = Object.entries(res.settings)
      if (entries.length > 0) {
        settingsList.value = entries.map(([k, v], idx) => ({
          id: idx + 1,
          key: k,
          config: formatKeyLabel(k),
          value: typeof v === 'object' ? JSON.stringify(v) : String(v),
          version: `v${res.active_version || '2.4.1'}`,
          lastModified: res.updated_at ? res.updated_at.slice(0, 10) : '2026-10-14',
          status: 'Activo',
        }))
      }
    }
  } catch (err) {
    console.warn('No se pudo actualizar la información de Recompensas.', err)
  } finally {
    isLoading.value = false
  }
}

function formatKeyLabel(k) {
  const map = {
    points_expiry_days: 'Días de expiración de puntos',
    daily_cap_points: 'Tope diario global por alumno',
    rate_limit_per_hour: 'Límite de transacciones por hora (antifraude)',
    max_redemption_per_day: 'Canjes máximos diarios por alumno',
    min_transfer_amount: 'Monto mínimo para transferencias',
    points_ratio_mxn: 'Ratio puntos por peso mexicano ($)',
  }
  return map[k] || k.replace(/_/g, ' ')
}

function openEdit(s) {
  selectedSetting.value = s
  editValue.value = s.value
  showEditModal.value = true
}

async function confirmEdit() {
  if (!selectedSetting.value) return

  try {
    isSubmitting.value = true
    await api.settings.update(selectedSetting.value.key || selectedSetting.value.config, {
      value: editValue.value,
      change_reason: editReason.value,
    })

    success(`Configuración "${selectedSetting.value.config}" actualizada con éxito.`)
    showEditModal.value = false
    selectedSetting.value = null
    await loadSettings()
  } catch (err) {
    console.error('Error al actualizar configuración:', err)
    error(err.message || 'No se pudo actualizar la configuración. Verifica tus permisos.')
    showEditModal.value = false
  } finally {
    isSubmitting.value = false
  }
}

onMounted(() => {
  loadSettings()
})
</script>

<template>
  <div class="page">
    <div class="ph">
      <h1 class="page-title">7.11 Configuraciones sensibles y gobernanza</h1>
      <div class="page-subtitle">Panel de administración</div>
      <p class="page-desc">Parámetros críticos de emisión, límites de seguridad, periodos de caducidad y versiones del motor contable.</p>
    </div>

    <div class="warn-banner">
      ⚠ <strong>Advertencia de gobernanza:</strong> Las modificaciones en esta sección impactan el cálculo contable de todos los usuarios en tiempo real. Se requiere rol de administración y cada cambio queda auditado con justificación obligatoria.
    </div>

    <div class="card">
      <div class="table-wrapper">
        <table class="cd-table">
          <thead>
            <tr>
              <th>Parámetro de Configuración</th>
              <th>Valor Activo</th>
              <th>Versión</th>
              <th>Última Modificación</th>
              <th>Estado</th>
              <th>Acción</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="s in settingsList" :key="s.id">
              <td style="font-weight:600">{{ s.config }}</td>
              <td><code style="font-size:12px;background:#f0f3f9;padding:3px 8px;border-radius:4px;font-weight:700">{{ s.value }}</code></td>
              <td style="color:var(--cd-text-muted);font-size:12px">{{ s.version }}</td>
              <td style="color:var(--cd-text-muted);font-size:12px;white-space:nowrap">{{ s.lastModified }}</td>
              <td><StatusBadge :status="s.status" /></td>
              <td>
                <button class="btn btn-ghost btn-sm" @click="openEdit(s)">
                  Modificar
                </button>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>

    <!-- Modal Editar Configuración -->
    <div v-if="showEditModal" class="modal-backdrop" @click.self="showEditModal = false">
      <div class="modal card">
        <div class="modal-header">
          <h3 class="modal-title">Modificar Configuración Sensible</h3>
          <button class="modal-close" @click="showEditModal = false">✕</button>
        </div>
        <form @submit.prevent="confirmEdit" class="modal-body">
          <div class="form-group">
            <label class="cd-label">Parámetro</label>
            <input :value="selectedSetting?.config" disabled class="cd-input" style="background:#f1f5f9" />
          </div>

          <div class="form-group">
            <label class="cd-label">Nuevo valor del parámetro</label>
            <input v-model="editValue" required class="cd-input" />
          </div>

          <div class="form-group">
            <label class="cd-label">Justificación del cambio (requerida para auditoría)</label>
            <textarea v-model="editReason" required rows="3" class="cd-input" placeholder="Explica la razón del cambio en la política..."></textarea>
          </div>

          <div class="modal-actions">
            <button type="button" class="btn btn-secondary" @click="showEditModal = false">Cancelar</button>
            <button type="submit" class="btn btn-primary" :disabled="isSubmitting">
              {{ isSubmitting ? 'Guardando...' : 'Confirmar y Aplicar' }}
            </button>
          </div>
        </form>
      </div>
    </div>
  </div>
</template>

<style scoped>
.page{display:flex;flex-direction:column;gap:20px;}
.ph{display:flex;flex-direction:column;gap:2px;}
.warn-banner{padding:14px 18px;background:#fffbeb;border:1px solid #fde68a;border-radius:8px;font-size:13px;color:#92400e;line-height:1.5;}

.modal-backdrop { position: fixed; inset: 0; background: rgba(0,0,0,0.45); display: flex; align-items: center; justify-content: center; z-index: 999; }
.modal { width: 100%; max-width: 500px; padding: 24px; display: flex; flex-direction: column; gap: 16px; }
.modal-header { display: flex; justify-content: space-between; align-items: center; }
.modal-title { font-size: 16px; font-weight: 700; margin: 0; }
.modal-close { background: none; border: none; font-size: 18px; cursor: pointer; color: var(--cd-text-muted); }
.modal-body { display: flex; flex-direction: column; gap: 14px; }
.form-group { display: flex; flex-direction: column; gap: 6px; }
.cd-label { font-size: 12px; font-weight: 600; color: var(--cd-text-secondary); }
.modal-actions { display: flex; justify-content: flex-end; gap: 10px; margin-top: 8px; }
</style>
