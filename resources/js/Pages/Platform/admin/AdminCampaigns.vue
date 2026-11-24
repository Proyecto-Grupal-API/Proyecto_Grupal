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
const canManage = computed(() => hasPermission('campaigns.manage'))
const apiMessage = (err, fallback) => Object.values(err.response?.data?.errors || {}).flat().join(' ') || err.response?.data?.message || err.response?.data?.error || fallback

const campaigns = ref([])
const isLoading = ref(true)
const isSubmitting = ref(false)
const showModal = ref(false)
const editingId = ref(null)
const today = () => new Date().toISOString().slice(0, 10)
const blank = () => ({ name: '', description: '', type: 'multiplier', multiplier: 2, starts_at: today(), ends_at: '', status: 'active' })
const form = ref(blank())

const typeLabels = { multiplier: 'Multiplicador de puntos', bonus: 'Bono', academic: 'Académica', special: 'Especial' }
const statusLabels = { active: 'Activa', draft: 'Borrador', inactive: 'Inactiva' }

async function load() {
  try {
    isLoading.value = true
    const res = await api.campaigns.list({ per_page: 100 })
    campaigns.value = (Array.isArray(res) ? res : (res.data || [])).map(c => ({ ...c, id: c._id || c.id }))
  } catch (err) {
    console.warn('No se pudo actualizar la información de Recompensas.', err)
  } finally {
    isLoading.value = false
  }
}

function openCreate() { editingId.value = null; form.value = blank(); showModal.value = true }
function openEdit(c) {
  editingId.value = c.id
  form.value = { name: c.name, description: c.description || '', type: c.type || 'multiplier', multiplier: c.multiplier ?? 1,
    starts_at: String(c.starts_at || '').slice(0, 10), ends_at: String(c.ends_at || '').slice(0, 10), status: c.status || 'active' }
  showModal.value = true
}

async function submit() {
  const f = form.value
  if (!f.name.trim() || !f.starts_at) return
  const data = { name: f.name.trim(), description: f.description.trim() || null, type: f.type, multiplier: Number(f.multiplier), starts_at: f.starts_at, ends_at: f.ends_at || null, status: f.status }
  try {
    isSubmitting.value = true
    if (editingId.value) {
      await api.campaigns.update(editingId.value, data)
      success(`Campaña "${f.name}" actualizada.`)
    } else {
      await api.campaigns.create(data)
      success(`Campaña "${f.name}" creada.`)
    }
    showModal.value = false
    await load()
  } catch (err) {
    error(apiMessage(err, 'No se pudo guardar la campaña.'))
  } finally {
    isSubmitting.value = false
  }
}

async function toggle(c) {
  try {
    await api.campaigns.toggleStatus(c.id)
    success(c.status === 'draft' ? `Campaña "${c.name}" aprobada y activada.` : `Estado de la campaña "${c.name}" actualizado.`)
    await load()
  } catch (err) {
    error(apiMessage(err, 'No se pudo cambiar el estado de la campaña.'))
  }
}

onMounted(load)
</script>

<template>
  <div class="page">
    <div class="phrow">
      <div class="ph">
        <h1 class="page-title">7.3 Campañas de puntos</h1>
        <div class="page-subtitle">Panel de administración</div>
        <p class="page-desc">Campañas globales con multiplicador y vigencia. Las propuestas académicas de los profesores llegan como borrador y se activan aquí.</p>
      </div>
      <button v-if="canManage" class="btn btn-primary" @click="openCreate">＋ Nueva campaña</button>
    </div>

    <div class="card">
      <div class="table-wrapper">
        <table class="cd-table">
          <thead><tr><th>Campaña</th><th>Tipo</th><th>Multiplicador</th><th>Vigencia</th><th>Origen</th><th>Estado</th><th v-if="canManage">Acción</th></tr></thead>
          <tbody>
            <tr v-for="c in campaigns" :key="c.id">
              <td><div style="font-weight:600">{{ c.name }}</div><div style="font-size:11px;color:var(--cd-text-muted)">{{ c.description }}</div></td>
              <td>{{ typeLabels[c.type] || c.type }}</td>
              <td style="font-weight:700;color:var(--cd-primary)">×{{ c.multiplier }}</td>
              <td style="font-size:12px;white-space:nowrap">{{ String(c.starts_at || '').slice(0, 10) }} — {{ c.ends_at ? String(c.ends_at).slice(0, 10) : 'sin fin' }}</td>
              <td style="font-size:12px">{{ c.sponsor_type === 'academic' ? 'Propuesta de profesor' : 'Programa' }}</td>
              <td><StatusBadge :status="statusLabels[c.status] || c.status" /></td>
              <td v-if="canManage" style="white-space:nowrap">
                <button class="btn btn-ghost btn-sm" @click="openEdit(c)">Editar</button>
                <button class="btn btn-ghost btn-sm" :style="{ color: c.status === 'active' ? '#dc2626' : '#16a34a' }" @click="toggle(c)">
                  {{ c.status === 'active' ? 'Desactivar' : (c.status === 'draft' ? 'Aprobar y activar' : 'Activar') }}
                </button>
              </td>
            </tr>
            <tr v-if="!isLoading && !campaigns.length"><td :colspan="canManage ? 7 : 6" style="text-align:center;color:var(--cd-text-muted);padding:24px">No hay campañas registradas.</td></tr>
          </tbody>
        </table>
      </div>
    </div>

    <div v-if="showModal" class="modal-backdrop" @click.self="showModal = false">
      <div class="modal card" role="dialog" aria-labelledby="campaign-title">
        <div class="modal-header">
          <h3 id="campaign-title" class="modal-title">{{ editingId ? 'Editar campaña' : 'Nueva campaña' }}</h3>
          <button class="modal-close" aria-label="Cerrar" @click="showModal = false">✕</button>
        </div>
        <form class="modal-body" @submit.prevent="submit">
          <div class="form-group">
            <label class="cd-label" for="campaign-name">Nombre de la campaña</label>
            <input id="campaign-name" v-model="form.name" required maxlength="255" class="cd-input" placeholder="Ej: Semana de doble punto" />
          </div>
          <div class="form-group">
            <label class="cd-label" for="campaign-description">Descripción</label>
            <textarea id="campaign-description" v-model="form.description" rows="2" maxlength="1000" class="cd-input"></textarea>
          </div>
          <div class="form-row">
            <div class="form-group">
              <label class="cd-label" for="campaign-type">Tipo</label>
              <select id="campaign-type" v-model="form.type" class="cd-input"><option v-for="(label, key) in typeLabels" :key="key" :value="key">{{ label }}</option></select>
            </div>
            <div class="form-group">
              <label class="cd-label" for="campaign-multiplier">Multiplicador (1 a 10)</label>
              <input id="campaign-multiplier" v-model.number="form.multiplier" type="number" min="1" max="10" step="0.1" required class="cd-input" />
            </div>
          </div>
          <div class="form-row">
            <div class="form-group">
              <label class="cd-label" for="campaign-start">Inicia</label>
              <input id="campaign-start" v-model="form.starts_at" type="date" required class="cd-input" />
            </div>
            <div class="form-group">
              <label class="cd-label" for="campaign-end">Termina</label>
              <input id="campaign-end" v-model="form.ends_at" type="date" class="cd-input" />
            </div>
          </div>
          <div class="form-group">
            <label class="cd-label" for="campaign-status">Estado</label>
            <select id="campaign-status" v-model="form.status" class="cd-input"><option value="active">Activa</option><option value="draft">Borrador</option><option value="inactive">Inactiva</option></select>
          </div>
          <div class="modal-actions">
            <button type="button" class="btn btn-secondary" @click="showModal = false">Cancelar</button>
            <button type="submit" class="btn btn-primary" :disabled="isSubmitting">{{ isSubmitting ? 'Guardando...' : (editingId ? 'Guardar cambios' : 'Crear Campaña') }}</button>
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
