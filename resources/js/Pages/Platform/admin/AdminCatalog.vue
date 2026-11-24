<script setup>
import PlatformLayout from '@/Layouts/PlatformLayout.vue'
defineOptions({ layout: PlatformLayout })
import { ref, onMounted } from 'vue'
import StatusBadge from '@/Platform/components/ui/StatusBadge.vue'
import ConfirmModal from '@/Platform/components/ui/ConfirmModal.vue'
import { useToast } from '@/Platform/composables/useToast.js'
import { rewardsInitial } from '@/Platform/initialState.js'
import { api } from '@/Platform/services/api.js'

const { success, error } = useToast()
// Mensaje claro del backend (errores de validación 422 o mensaje de negocio) en lugar del texto técnico de axios.
const apiMessage = (err, fallback) => Object.values(err.response?.data?.errors || {}).flat().join(' ') || err.response?.data?.message || err.response?.data?.error || fallback
const rewards = ref([...rewardsInitial])
const showModal = ref(false)
const showDeleteModal = ref(false)
const selectedToDelete = ref(null)
const editingId = ref(null)
const isSubmitting = ref(false)
const isLoading = ref(true)

const form = ref({
  name: '',
  description: '',
  points_required: 200,
  stock: 25,
  valid_to: new Date(Date.now() + 180 * 24 * 3600 * 1000).toISOString().slice(0, 10),
  status: 'active',
})

async function loadRewards() {
  try {
    isLoading.value = true
    const res = await api.rewards.list()
    const list = Array.isArray(res) ? res : (res.data || [])
    // La tabla siempre refleja el servidor (también cuando queda vacía tras eliminar la última).
    {
      rewards.value = list.map(r => ({
        id: r._id || r.id,
        _id: r._id || r.id,
        name: r.name,
        description: r.description || '',
        business: r.business_id || 'Programa (sin negocio)',
        points: r.points_required ?? r.points,
        rawStock: r.stock,
        limitPerUser: r.limit_per_user ?? null,
        rawValidTo: (r.valid_to || r.valid_until) ? String(r.valid_to || r.valid_until).slice(0, 10) : '',
        stock: (r.stock !== null && r.stock !== undefined) ? (r.stock - (r.reserved_stock || 0)) : 'Sin límite',
        validUntil: (r.valid_to || r.valid_until) ? String(r.valid_to || r.valid_until).slice(0, 10) : 'Sin límite',
        status: (r.status === 'active' || r.status === 'Activo') ? 'Activo' : 'Inactivo',
        image: r.image || '🎁',
      }))
    }
  } catch (err) {
    console.warn('No se pudo actualizar la información de Recompensas.', err)
  } finally {
    isLoading.value = false
  }
}

// Límite de canjes por alumno (7.4-e). Vacío = sin límite.
function limitValue() {
  const n = parseInt(form.value.limit_per_user)
  return Number.isInteger(n) && n > 0 ? n : null
}

function openCreate() {
  editingId.value = null
  form.value = { name: '', description: '', points_required: 200, stock: 25, limit_per_user: null, valid_to: new Date(Date.now() + 180 * 24 * 3600 * 1000).toISOString().slice(0, 10), status: 'active' }
  showModal.value = true
}

function openEdit(r) {
  editingId.value = r._id
  form.value = { name: r.name, description: r.description, points_required: r.points, stock: r.rawStock ?? 0, limit_per_user: r.limitPerUser, valid_to: r.rawValidTo, status: r.status === 'Activo' ? 'active' : 'inactive' }
  showModal.value = true
}

async function handleCreateReward() {
  if (!form.value.name.trim()) return

  try {
    isSubmitting.value = true
    if (editingId.value) {
      await api.rewards.update(editingId.value, {
        name: form.value.name,
        description: form.value.description,
        points_required: parseInt(form.value.points_required),
        stock: parseInt(form.value.stock),
        limit_per_user: limitValue(),
        valid_to: form.value.valid_to || null,
        status: form.value.status,
      })
      success(`Recompensa "${form.value.name}" actualizada.`)
      showModal.value = false
      await loadRewards()
      return
    }
    await api.rewards.create({
      name: form.value.name,
      description: form.value.description,
      points_required: parseInt(form.value.points_required),
      stock: parseInt(form.value.stock),
      limit_per_user: limitValue(),
      valid_to: form.value.valid_to,
      status: 'active',
    })

    success(`Recompensa "${form.value.name}" creada exitosamente.`)
    showModal.value = false
    form.value.name = ''
    form.value.description = ''
    await loadRewards()
  } catch (err) {
    console.error('Error al crear recompensa:', err)
    error(apiMessage(err, 'No se pudo crear la recompensa.'))
  } finally {
    isSubmitting.value = false
  }
}

function openDelete(r) {
  selectedToDelete.value = r
  showDeleteModal.value = true
}

async function confirmDelete() {
  if (!selectedToDelete.value) return
  try {
    await api.rewards.delete(selectedToDelete.value._id || selectedToDelete.value.id)
    success(`Recompensa "${selectedToDelete.value.name}" eliminada.`)
    showDeleteModal.value = false
    selectedToDelete.value = null
    await loadRewards()
  } catch (err) {
    console.error('Error al eliminar:', err)
    error(apiMessage(err, 'No se pudo eliminar la recompensa.'))
    showDeleteModal.value = false
  }
}

onMounted(() => {
  loadRewards()
})
</script>

<template>
  <div class="page">
    <div class="phrow">
      <div class="ph">
        <h1 class="page-title">7.4 Catálogo de recompensas</h1>
        <div class="page-subtitle">Panel de administración</div>
        <p class="page-desc">Administra recompensas físicas y digitales, define costos en puntos y controla el inventario de stock.</p>
      </div>
      <button class="btn btn-primary" @click="openCreate">＋ Nueva recompensa</button>
    </div>

    <div class="card">
      <div class="table-wrapper">
        <table class="cd-table">
          <thead>
            <tr>
              <th>Recompensa</th>
              <th>Emisor / Proveedor</th>
              <th>Costo en Puntos</th>
              <th>Stock Disponible</th>
              <th>Vigencia</th>
              <th>Estado</th>
              <th>Acción</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="r in rewards" :key="r.id">
              <td>
                <div style="display:flex;align-items:center;gap:10px">
                  <span style="font-size:22px">{{ r.image }}</span>
                  <span style="font-weight:600">{{ r.name }}</span>
                </div>
                <div v-if="r.limitPerUser" class="limit-note" data-limite-por-alumno>Máximo {{ r.limitPerUser }} por alumno</div>
              </td>
              <td style="color:var(--cd-text-muted);font-size:12px">{{ r.business }}</td>
              <td style="color:var(--cd-primary);font-weight:700">{{ r.points.toLocaleString() }} pts</td>
              <td><strong>{{ r.stock }}</strong><template v-if="typeof r.stock === 'number'"> unidades</template></td>
              <td style="color:var(--cd-text-muted);font-size:12px">{{ r.validUntil }}</td>
              <td><StatusBadge :status="r.status" /></td>
              <td style="white-space:nowrap">
                <button class="btn btn-ghost btn-sm" @click="openEdit(r)">Editar</button>
                <button class="btn btn-ghost btn-sm" style="color:#dc2626" @click="openDelete(r)">Eliminar</button>
              </td>
            </tr>
            <tr v-if="!isLoading && !rewards.length"><td colspan="7" style="text-align:center;color:var(--cd-text-muted);padding:24px">No hay recompensas registradas.</td></tr>
          </tbody>
        </table>
      </div>
    </div>

    <!-- Modal Nueva Recompensa -->
    <div v-if="showModal" class="modal-backdrop" @click.self="showModal = false">
      <div class="modal card">
        <div class="modal-header">
          <h3 class="modal-title">{{ editingId ? 'Editar Recompensa' : 'Nueva Recompensa' }}</h3>
          <button class="modal-close" @click="showModal = false">✕</button>
        </div>
        <form @submit.prevent="handleCreateReward" class="modal-body">
          <div class="form-group">
            <label class="cd-label">Nombre del artículo / beneficio</label>
            <input v-model="form.name" required class="cd-input" placeholder="Ej: Termo Metálico Campus 500ml" />
          </div>

          <div class="form-row">
            <div class="form-group">
              <label class="cd-label">Puntos requeridos</label>
              <input v-model.number="form.points_required" type="number" min="1" required class="cd-input" />
            </div>
            <div class="form-group">
              <label class="cd-label">{{ editingId ? 'Stock total' : 'Stock inicial' }}</label>
              <input v-model.number="form.stock" type="number" min="0" required class="cd-input" />
            </div>
          </div>

          <div class="form-group">
            <label class="cd-label" for="reward-limit-per-user">Máximo de canjes por alumno (opcional)</label>
            <input id="reward-limit-per-user" v-model.number="form.limit_per_user" type="number" min="1" class="cd-input" placeholder="Sin límite" />
            <small style="color:var(--cd-text-muted);font-size:12px">Los canjes cancelados no cuentan.</small>
          </div>

          <div class="form-group">
            <label class="cd-label">Fecha de vigencia límite</label>
            <input v-model="form.valid_to" type="date" required class="cd-input" />
          </div>

          <div class="form-group">
            <label class="cd-label">Descripción del producto</label>
            <textarea v-model="form.description" rows="2" class="cd-input" placeholder="Detalles de entrega y canje..."></textarea>
          </div>

          <div class="modal-actions">
            <button type="button" class="btn btn-secondary" @click="showModal = false">Cancelar</button>
            <button type="submit" class="btn btn-primary" :disabled="isSubmitting">
              {{ isSubmitting ? 'Guardando...' : (editingId ? 'Guardar cambios' : 'Crear Recompensa') }}
            </button>
          </div>
        </form>
      </div>
    </div>

    <ConfirmModal
      :show="showDeleteModal"
      :danger="true"
      title="Eliminar recompensa"
      :message="`¿Estás seguro de que deseas eliminar permanentemente '${selectedToDelete?.name}'?`"
      confirm-label="Sí, eliminar"
      @confirm="confirmDelete"
      @cancel="showDeleteModal = false"
    />
  </div>
</template>

<style scoped>
.limit-note{font-size:11px;color:var(--cd-text-muted);margin:2px 0 0 32px;}
.page{display:flex;flex-direction:column;gap:20px;}
.phrow{display:flex;align-items:flex-end;justify-content:space-between;}
.ph{display:flex;flex-direction:column;gap:2px;}

.modal-backdrop { position: fixed; inset: 0; background: rgba(0,0,0,0.45); display: flex; align-items: center; justify-content: center; z-index: 999; }
.modal { width: 100%; max-width: 500px; padding: 24px; display: flex; flex-direction: column; gap: 16px; }
.modal-header { display: flex; justify-content: space-between; align-items: center; }
.modal-title { font-size: 16px; font-weight: 700; margin: 0; }
.modal-close { background: none; border: none; font-size: 18px; cursor: pointer; color: var(--cd-text-muted); }
.modal-body { display: flex; flex-direction: column; gap: 14px; }
.form-group { display: flex; flex-direction: column; gap: 6px; }
.form-row { display: grid; grid-template-columns: repeat(2, 1fr); gap: 10px; }
.cd-label { font-size: 12px; font-weight: 600; color: var(--cd-text-secondary); }
.modal-actions { display: flex; justify-content: flex-end; gap: 10px; margin-top: 8px; }
</style>
