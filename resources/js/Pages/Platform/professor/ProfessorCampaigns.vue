<script setup>
import PlatformLayout from '@/Layouts/PlatformLayout.vue'
defineOptions({ layout: PlatformLayout })
import { ref, onMounted } from 'vue'
import StatusBadge from '@/Platform/components/ui/StatusBadge.vue'
import { useToast } from '@/Platform/composables/useToast.js'
import { campaignsInitial } from '@/Platform/initialState.js'
import { api } from '@/Platform/services/api.js'

const { success, error } = useToast()
const campaigns = ref([...campaignsInitial])
const showModal = ref(false)
const isSubmitting = ref(false)

const form = ref({
  name: '',
  description: '',
  multiplier: 1.5,
  start_date: new Date().toISOString().slice(0, 10),
  end_date: new Date(Date.now() + 30 * 24 * 3600 * 1000).toISOString().slice(0, 10),
})

async function loadCampaigns() {
  try {
    const res = await api.campaigns.list()
    const list = Array.isArray(res) ? res : (res.data || [])
    if (list.length > 0) {
      campaigns.value = list.map(c => ({
        id: c._id || c.id,
        title: c.name,
        multiplier: c.multiplier ? `${c.multiplier}x` : '1.5x',
        status: (c.status === 'active' || c.status === 'Activa') ? 'Activa' : (c.status === 'draft' ? 'Borrador' : c.status),
        description: c.description || 'Iniciativa académica para fomentar el compromiso estudiantil.',
        period: c.start_date ? `${c.start_date.slice(0, 10)} al ${c.end_date?.slice(0, 10) || 'permanente'}` : 'Vigente',
        scope: c.type === 'academic' ? 'Académica' : 'Campus',
      }))
    }
  } catch (err) {
    console.warn('No se pudo actualizar la información de Recompensas.', err)
  }
}

async function submitProposal() {
  if (!form.value.name.trim()) return

  try {
    isSubmitting.value = true
    await api.campaigns.propose({
      name: form.value.name,
      description: form.value.description,
      multiplier: parseFloat(form.value.multiplier),
      start_date: form.value.start_date,
      end_date: form.value.end_date,
    })

    success('¡Propuesta académica enviada exitosamente! Quedará en revisión por el Administrador.')
    showModal.value = false
    form.value.name = ''
    form.value.description = ''
    await loadCampaigns()
  } catch (err) {
    console.error('Error al proponer campaña:', err)
    error(err.message || 'No se pudo enviar la propuesta.')
  } finally {
    isSubmitting.value = false
  }
}

onMounted(() => {
  loadCampaigns()
})
</script>

<template>
  <div class="page">
    <div class="phrow">
      <div class="ph">
        <h1 class="page-title">Campañas académicas</h1>
        <div class="page-subtitle">Panel del profesor</div>
        <p class="page-desc">Incentiva a tus alumnos proponiendo campañas de bonificación de puntos por mérito académico.</p>
      </div>
      <button class="btn btn-primary" @click="showModal = true">
        ＋ Proponer campaña
      </button>
    </div>

    <div class="campaigns-list">
      <div v-for="c in campaigns" :key="c.id" class="campaign-card card">
        <div class="mult-chip">{{ c.multiplier }}</div>
        <div class="camp-info">
          <div class="camp-header">
            <h3 class="camp-title">{{ c.title }}</h3>
            <StatusBadge :status="c.status" />
          </div>
          <p class="camp-desc">{{ c.description }}</p>
          <div class="camp-meta">
            <span>📅 {{ c.period }}</span>
            <span>🌐 {{ c.scope }}</span>
          </div>
        </div>
      </div>
    </div>

    <!-- Modal para proponer campaña -->
    <div v-if="showModal" class="modal-backdrop" @click.self="showModal = false">
      <div class="modal card">
        <div class="modal-header">
          <h3 class="modal-title">Proponer Campaña Académica</h3>
          <button class="modal-close" @click="showModal = false">✕</button>
        </div>
        <form @submit.prevent="submitProposal" class="modal-body">
          <div class="form-group">
            <label class="cd-label">Nombre de la campaña</label>
            <input v-model="form.name" required class="cd-input" placeholder="Ej: Semana de la Investigación" />
          </div>

          <div class="form-group">
            <label class="cd-label">Descripción del objetivo</label>
            <textarea v-model="form.description" rows="3" class="cd-input" placeholder="Detalla los criterios de elegibilidad y mérito..."></textarea>
          </div>

          <div class="form-row">
            <div class="form-group">
              <label class="cd-label">Multiplicador de puntos</label>
              <input v-model.number="form.multiplier" type="number" step="0.1" min="1.1" max="5.0" class="cd-input" />
            </div>
            <div class="form-group">
              <label class="cd-label">Fecha de inicio</label>
              <input v-model="form.start_date" type="date" required class="cd-input" />
            </div>
            <div class="form-group">
              <label class="cd-label">Fecha de fin</label>
              <input v-model="form.end_date" type="date" required class="cd-input" />
            </div>
          </div>

          <div class="modal-actions">
            <button type="button" class="btn btn-secondary" @click="showModal = false">Cancelar</button>
            <button type="submit" class="btn btn-primary" :disabled="isSubmitting">
              {{ isSubmitting ? 'Enviando...' : 'Enviar propuesta' }}
            </button>
          </div>
        </form>
      </div>
    </div>
  </div>
</template>

<style scoped>
.page { display:flex; flex-direction:column; gap:20px; }
.phrow { display:flex; align-items:flex-end; justify-content:space-between; }
.ph { display:flex; flex-direction:column; gap:2px; }
.campaigns-list { display:flex; flex-direction:column; gap:12px; }
.campaign-card { display:flex; align-items:center; gap:20px; padding:18px 20px; }
.mult-chip { min-width:56px; height:56px; border-radius:50%; background:var(--cd-primary-light); color:var(--cd-primary); display:flex; align-items:center; justify-content:center; font-size:14px; font-weight:700; flex-shrink: 0; }
.camp-info { flex:1; }
.camp-header { display:flex; align-items:center; gap:10px; margin-bottom:4px; }
.camp-title { font-size:15px; font-weight:700; color:var(--cd-text-primary); margin:0; }
.camp-desc { font-size:13px; color:var(--cd-text-secondary); margin-bottom:8px; }
.camp-meta { display:flex; gap:16px; font-size:12px; color:var(--cd-text-muted); }

.modal-backdrop { position: fixed; inset: 0; background: rgba(0,0,0,0.45); display: flex; align-items: center; justify-content: center; z-index: 999; }
.modal { width: 100%; max-width: 520px; padding: 24px; display: flex; flex-direction: column; gap: 16px; }
.modal-header { display: flex; justify-content: space-between; align-items: center; }
.modal-title { font-size: 16px; font-weight: 700; margin: 0; }
.modal-close { background: none; border: none; font-size: 18px; cursor: pointer; color: var(--cd-text-muted); }
.modal-body { display: flex; flex-direction: column; gap: 14px; }
.form-group { display: flex; flex-direction: column; gap: 6px; }
.form-row { display: grid; grid-template-columns: repeat(3, 1fr); gap: 10px; }
.cd-label { font-size: 12px; font-weight: 600; color: var(--cd-text-secondary); }
.modal-actions { display: flex; justify-content: flex-end; gap: 10px; margin-top: 8px; }
</style>
