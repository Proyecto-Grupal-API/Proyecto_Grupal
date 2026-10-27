<script setup>
import PlatformLayout from '@/Layouts/PlatformLayout.vue'
defineOptions({ layout: PlatformLayout })
import { ref, onMounted } from 'vue'
import StatusBadge from '@/Platform/components/ui/StatusBadge.vue'
import EmptyState from '@/Platform/components/ui/EmptyState.vue'
import { campaignsInitial } from '@/Platform/initialState.js'
import { api } from '@/Platform/services/api.js'

const campaigns = ref([...campaignsInitial])
const isLoading = ref(true)

async function loadCampaigns() {
  try {
    isLoading.value = true
    const res = await api.campaigns.list()
    const list = Array.isArray(res) ? res : (res.data || [])
    if (list.length > 0) {
      campaigns.value = list.map(c => ({
        id: c._id || c.id,
        title: c.name,
        multiplier: c.multiplier ? `${c.multiplier}x` : '1.5x',
        status: (c.status === 'active' || c.status === 'Activa') ? 'Activa' : 'Borrador',
        description: c.description || 'Campaña especial para la comunidad universitaria.',
        period: c.start_date ? `${c.start_date.slice(0, 10)} al ${c.end_date?.slice(0, 10) || 'permanente'}` : 'Vigente',
        scope: c.type === 'academic' ? 'Académica' : 'Campus Global',
      }))
    }
  } catch (err) {
    console.warn('No se pudo actualizar la información de Recompensas.', err)
  } finally {
    isLoading.value = false
  }
}

onMounted(() => {
  loadCampaigns()
})
</script>

<template>
  <div class="page">
    <div class="page-header">
      <h1 class="page-title">Campañas activas</h1>
      <div class="page-subtitle">Panel del alumno</div>
      <p class="page-desc">Participa y gana puntos extra en campañas universitarias.</p>
    </div>

    <EmptyState v-if="!isLoading && !campaigns.length" title="Sin campañas activas" message="Pronto habrá nuevas campañas disponibles." icon="📢" />
    <div v-else class="campaigns-list">
      <div v-for="c in campaigns" :key="c.id" class="campaign-card card">
        <div class="camp-badge">
          <span class="multiplier-chip">{{ c.multiplier }}</span>
        </div>
        <div class="camp-info">
          <div class="camp-header">
            <h3 class="camp-title">{{ c.title }}</h3>
            <StatusBadge :status="c.status" />
          </div>
          <p class="camp-desc">{{ c.description }}</p>
          <div class="camp-meta">
            <span class="meta-item">📅 {{ c.period }}</span>
            <span class="meta-item">🌐 {{ c.scope }}</span>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>

<style scoped>
.page { display: flex; flex-direction: column; gap: 20px; }
.campaigns-list { display: flex; flex-direction: column; gap: 12px; }

.campaign-card {
  display: flex; align-items: center; gap: 20px; padding: 18px 20px;
}
.multiplier-chip {
  display: inline-flex; align-items: center; justify-content: center;
  width: 56px; height: 56px; border-radius: 50%;
  background: var(--cd-primary-light); color: var(--cd-primary);
  font-size: 16px; font-weight: 700; flex-shrink: 0;
}
.camp-info { flex: 1; }
.camp-header { display: flex; align-items: center; gap: 10px; margin-bottom: 4px; }
.camp-title { font-size: 15px; font-weight: 700; color: var(--cd-text-primary); margin: 0; }
.camp-desc { font-size: 13px; color: var(--cd-text-secondary); margin-bottom: 8px; }
.camp-meta { display: flex; gap: 16px; }
.meta-item { font-size: 12px; color: var(--cd-text-muted); }
</style>
