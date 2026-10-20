<script setup>
import { ref, onMounted } from 'vue'
import { useRouter } from 'vue-router'
import StatCard from '../../components/ui/StatCard.vue'
import StatusBadge from '../../components/ui/StatusBadge.vue'
import { studentDashboardMock } from '../../mocks/rewards.js'
import { api } from '../../services/api.js'

const router = useRouter()
const data = ref({ ...studentDashboardMock })
const isLoading = ref(true)

async function loadDashboard() {
  try {
    isLoading.value = true
    const stats = await api.dashboard.getStats()
    if (stats) {
      data.value.balance = stats.balance ?? data.value.balance
      data.value.activeRedemptions = stats.active_redemptions ?? data.value.activeRedemptions
      data.value.availableCampaigns = stats.available_campaigns ?? data.value.availableCampaigns
      if (stats.featured_campaign) {
        data.value.featuredCampaign = {
          title: stats.featured_campaign.name || data.value.featuredCampaign.title,
          description: stats.featured_campaign.description || data.value.featuredCampaign.description,
          period: stats.featured_campaign.start_date ? `${stats.featured_campaign.start_date.slice(0, 10)} al ${stats.featured_campaign.end_date?.slice(0, 10) || 'permanente'}` : data.value.featuredCampaign.period,
        }
      }
      if (stats.recent_activity && stats.recent_activity.length) {
        data.value.recentActivity = stats.recent_activity.map(a => ({
          date: (a.created_at || '').slice(0, 10),
          concept: a.description || a.source_domain || 'Movimiento de puntos',
          points: a.amount || 0,
          status: a.amount > 0 ? 'Completado' : 'Canjeado',
        }))
      }
    }
  } catch (err) {
    console.warn('Usando datos de respaldo para el dashboard del estudiante:', err)
  } finally {
    isLoading.value = false
  }
}

onMounted(() => {
  loadDashboard()
})
</script>

<template>
  <div class="page">
    <!-- Header -->
    <div class="page-header">
      <div>
        <h1 class="page-title">Recompensas</h1>
        <div class="page-subtitle">Panel del alumno</div>
        <p class="page-desc">Acumula puntos, participa en campañas y canjea recompensas.</p>
      </div>
    </div>

    <!-- KPIs -->
    <div class="stats-row">
      <StatCard
        title="Mis puntos"
        :value="`${data.balance.toLocaleString()} pts`"
        icon="⭐"
        icon-bg="#FEF9C3"
        icon-color="#D97706"
        :link="true"
        @click="router.push('/recompensas/alumno')"
      />
      <StatCard
        title="Canjes activos"
        :value="data.activeRedemptions"
        icon="🎟"
        icon-bg="#F0F9FF"
        icon-color="#2878ED"
        :link="true"
        @click="router.push('/recompensas/alumno/canjes')"
      />
      <StatCard
        title="Campañas disponibles"
        :value="data.availableCampaigns"
        icon="📢"
        icon-bg="#F0FDF4"
        icon-color="#16A34A"
        :link="true"
        @click="router.push('/recompensas/alumno/campanas')"
      />
    </div>

    <!-- Campaña destacada -->
    <div class="campaign-banner card">
      <div class="campaign-visual">
        <span class="campaign-emoji">📣</span>
      </div>
      <div class="campaign-info">
        <h2 class="campaign-title">{{ data.featuredCampaign.title }}</h2>
        <p class="campaign-desc">{{ data.featuredCampaign.description }}</p>
        <div class="campaign-period">
          <span class="period-icon">📅</span>
          {{ data.featuredCampaign.period }}
        </div>
      </div>
      <button class="btn btn-primary campaign-cta" @click="router.push('/recompensas/alumno/campanas')">
        Ver campaña
      </button>
    </div>

    <!-- Dos columnas -->
    <div class="two-col">
      <!-- Recompensas destacadas -->
      <div class="card rewards-card">
        <div class="card-header">
          <h3 class="card-title">Recompensas destacadas</h3>
          <button class="link-btn" @click="router.push('/recompensas/alumno/catalogo')">
            Ver catálogo completo →
          </button>
        </div>
        <div class="rewards-grid">
          <div v-for="reward in data.featuredRewards" :key="reward.id" class="reward-item">
            <div class="reward-image">{{ reward.image }}</div>
            <div class="reward-name">{{ reward.name }}</div>
            <div class="reward-points">{{ reward.points.toLocaleString() }} puntos</div>
            <button
              class="btn btn-secondary btn-sm reward-btn"
              @click="reward.points <= data.balance
                ? router.push('/recompensas/alumno/catalogo')
                : null"
            >
              {{ reward.points <= data.balance ? 'Canjear' : 'Ver' }}
            </button>
          </div>
        </div>
      </div>

      <!-- Actividad reciente -->
      <div class="card activity-card">
        <div class="card-header">
          <h3 class="card-title">Actividad reciente</h3>
          <button class="link-btn" @click="router.push('/recompensas/alumno/actividad')">
            Ver toda mi actividad →
          </button>
        </div>
        <div class="table-wrapper">
          <table class="cd-table">
            <thead>
              <tr>
                <th>Fecha</th>
                <th>Concepto</th>
                <th>Puntos</th>
                <th>Estado</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="item in data.recentActivity" :key="item.date + item.concept">
                <td class="date-cell">{{ item.date }}</td>
                <td>{{ item.concept }}</td>
                <td>
                  <span :class="item.points > 0 ? 'pts-positive' : 'pts-negative'">
                    {{ item.points > 0 ? '+' : '' }}{{ item.points.toLocaleString() }}
                  </span>
                </td>
                <td><StatusBadge :status="item.status" /></td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>
    </div>
  </div>
</template>

<style scoped>
.page { display: flex; flex-direction: column; gap: 20px; }

.page-header { margin-bottom: 4px; }

.stats-row {
  display: grid;
  grid-template-columns: repeat(3, 1fr);
  gap: 16px;
}

/* Campaña */
.campaign-banner {
  display: flex;
  align-items: center;
  gap: 20px;
  padding: 20px 24px;
  background: linear-gradient(135deg, #f0f8ff 0%, #e8f4fd 100%);
  border: 1px solid #c3ddf9;
}

.campaign-visual {
  width: 80px;
  height: 80px;
  background: #dceeff;
  border-radius: 12px;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 36px;
  flex-shrink: 0;
}

.campaign-info { flex: 1; }
.campaign-title { font-size: 17px; font-weight: 700; color: var(--cd-text-primary); margin-bottom: 4px; }
.campaign-desc { font-size: 13px; color: var(--cd-text-secondary); margin-bottom: 8px; }
.campaign-period { font-size: 12px; color: var(--cd-text-muted); display: flex; align-items: center; gap: 5px; }
.period-icon { font-size: 12px; }
.campaign-cta { flex-shrink: 0; }

/* Two columns */
.two-col { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; }

/* Card shared */
.card-header {
  display: flex; align-items: center; justify-content: space-between;
  padding: 16px 18px 12px;
  border-bottom: 1px solid var(--cd-border-light);
}
.card-title { font-size: 14px; font-weight: 700; color: var(--cd-text-primary); margin: 0; }
.link-btn {
  font-size: 12px; color: var(--cd-primary); background: none; border: none;
  cursor: pointer; font-family: var(--cd-font); font-weight: 500;
  transition: color 0.15s;
}
.link-btn:hover { color: var(--cd-primary-dark); }

/* Rewards grid */
.rewards-card { overflow: hidden; }
.rewards-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 0; }
.reward-item {
  padding: 16px 12px;
  display: flex;
  flex-direction: column;
  align-items: center;
  text-align: center;
  border-right: 1px solid var(--cd-border-light);
  gap: 6px;
}
.reward-item:last-child { border-right: none; }
.reward-image { font-size: 36px; }
.reward-name { font-size: 13px; font-weight: 600; color: var(--cd-text-primary); line-height: 1.3; }
.reward-points { font-size: 12px; font-weight: 600; color: var(--cd-primary); }
.reward-btn { width: 100%; justify-content: center; margin-top: 4px; }

/* Activity */
.activity-card { overflow: hidden; }
.date-cell { color: var(--cd-text-muted); font-size: 12px; white-space: nowrap; }
.pts-positive { color: #16a34a; font-weight: 600; font-size: 13px; }
.pts-negative { color: #dc2626; font-weight: 600; font-size: 13px; }

/* Responsive */
@media (max-width: 1100px) {
  .two-col { grid-template-columns: 1fr; }
}
@media (max-width: 768px) {
  .stats-row { grid-template-columns: 1fr; }
  .campaign-banner { flex-direction: column; text-align: center; }
}
</style>
