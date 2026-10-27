<script setup>
import PlatformLayout from '@/Layouts/PlatformLayout.vue'
defineOptions({ layout: PlatformLayout })
import { ref, onMounted } from 'vue'
import StatusBadge from '@/Platform/components/ui/StatusBadge.vue'
import { useToast } from '@/Platform/composables/useToast.js'
import { businessesInitial } from '@/Platform/initialState.js'
import { api } from '@/Platform/services/api.js'

const { success, error } = useToast()
const businesses = ref([...businessesInitial])
const showModal = ref(false)
const isSubmitting = ref(false)
const isLoading = ref(true)

const form = ref({
  name: '',
  code: '',
  category: 'Cafetería',
  contact_name: '',
  contact_email: '',
  status: 'active',
})

async function loadBusinesses() {
  try {
    isLoading.value = true
    const res = await api.businesses.list()
    const list = Array.isArray(res) ? res : (res.data || [])
    if (list.length > 0) {
      businesses.value = list.map(b => ({
        id: b._id || b.id,
        _id: b._id || b.id,
        name: b.name,
        code: b.code,
        responsible: b.contact_name || b.responsible || 'Responsable',
        joinDate: b.created_at ? b.created_at.slice(0, 10) : '2026-01-15',
        participation: b.status === 'active' ? 'Alta' : 'Baja',
        status: (b.status === 'active' || b.status === 'Activo') ? 'Activo' : 'Inactivo',
        rawStatus: b.status,
      }))
    }
  } catch (err) {
    console.warn('No se pudo actualizar la información de Recompensas.', err)
  } finally {
    isLoading.value = false
  }
}

async function handleCreateBusiness() {
  if (!form.value.name.trim() || !form.value.code.trim()) return

  try {
    isSubmitting.value = true
    await api.businesses.create({
      name: form.value.name,
      code: form.value.code.toUpperCase(),
      category: form.value.category,
      contact_name: form.value.contact_name,
      contact_email: form.value.contact_email,
      status: 'active',
    })

    success(`Negocio "${form.value.name}" adherido exitosamente al programa.`)
    showModal.value = false
    form.value.name = ''
    form.value.code = ''
    form.value.contact_name = ''
    form.value.contact_email = ''
    await loadBusinesses()
  } catch (err) {
    console.error('Error al adherir negocio:', err)
    error(err.message || 'Error al guardar el negocio.')
  } finally {
    isSubmitting.value = false
  }
}

async function handleToggle(b) {
  try {
    const res = await api.businesses.toggleStatus(b._id || b.id)
    success(`Estado del negocio "${b.name}" actualizado.`)
    await loadBusinesses()
  } catch (err) {
    console.error('Error al cambiar estado:', err)
    error(err.message || 'No se pudo cambiar el estado.')
  }
}

onMounted(() => {
  loadBusinesses()
})
</script>

<template>
  <div class="page">
    <div class="phrow">
      <div class="ph">
        <h1 class="page-title">7.2 Adhesión de negocios</h1>
        <div class="page-subtitle">Panel de administración</div>
        <p class="page-desc">Gestiona comercios, cafeterías y servicios universitarios afiliados al ecosistema de puntos.</p>
      </div>
      <button class="btn btn-primary" @click="showModal = true">＋ Adherir negocio</button>
    </div>

    <div class="card">
      <div class="table-wrapper">
        <table class="cd-table">
          <thead>
            <tr>
              <th>Negocio</th>
              <th>Código</th>
              <th>Responsable</th>
              <th>Fecha adhesión</th>
              <th>Participación</th>
              <th>Estado</th>
              <th>Acción</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="b in businesses" :key="b.id">
              <td style="font-weight:600">{{ b.name }}</td>
              <td><code style="font-size:11px;background:#f0f3f9;padding:2px 6px;border-radius:4px">{{ b.code || 'COM-01' }}</code></td>
              <td>{{ b.responsible }}</td>
              <td style="color:var(--cd-text-muted);font-size:12px">{{ b.joinDate }}</td>
              <td><span class="part-badge" :class="b.participation.toLowerCase()">{{ b.participation }}</span></td>
              <td><StatusBadge :status="b.status" /></td>
              <td>
                <button
                  class="btn btn-ghost btn-sm"
                  :style="{ color: b.status === 'Activo' ? '#dc2626' : '#16a34a' }"
                  @click="handleToggle(b)"
                >
                  {{ b.status === 'Activo' ? 'Suspender' : 'Activar' }}
                </button>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>

    <!-- Modal Adherir Negocio -->
    <div v-if="showModal" class="modal-backdrop" @click.self="showModal = false">
      <div class="modal card">
        <div class="modal-header">
          <h3 class="modal-title">Adherir Nuevo Negocio</h3>
          <button class="modal-close" @click="showModal = false">✕</button>
        </div>
        <form @submit.prevent="handleCreateBusiness" class="modal-body">
          <div class="form-group">
            <label class="cd-label">Nombre comercial</label>
            <input v-model="form.name" required class="cd-input" placeholder="Ej: Cafetería Central" />
          </div>

          <div class="form-row">
            <div class="form-group">
              <label class="cd-label">Código único</label>
              <input v-model="form.code" required class="cd-input" placeholder="Ej: CAF-CENTRAL" style="text-transform:uppercase;" />
            </div>
            <div class="form-group">
              <label class="cd-label">Categoría</label>
              <select v-model="form.category" class="cd-input">
                <option>Cafetería</option>
                <option>Librería</option>
                <option>Papelería</option>
                <option>Servicios</option>
                <option>Deportes</option>
              </select>
            </div>
          </div>

          <div class="form-row">
            <div class="form-group">
              <label class="cd-label">Contacto / Encargado</label>
              <input v-model="form.contact_name" class="cd-input" placeholder="Nombre completo" />
            </div>
            <div class="form-group">
              <label class="cd-label">Correo electrónico</label>
              <input v-model="form.contact_email" type="email" class="cd-input" placeholder="contacto@negocio.com" />
            </div>
          </div>

          <div class="modal-actions">
            <button type="button" class="btn btn-secondary" @click="showModal = false">Cancelar</button>
            <button type="submit" class="btn btn-primary" :disabled="isSubmitting">
              {{ isSubmitting ? 'Guardando...' : 'Adherir Negocio' }}
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
.part-badge{padding:2px 10px;border-radius:12px;font-size:12px;font-weight:600;}
.alta{background:#f0fdf4;color:#16a34a;}
.media{background:#fffbeb;color:#d97706;}
.baja{background:#fef2f2;color:#dc2626;}

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
