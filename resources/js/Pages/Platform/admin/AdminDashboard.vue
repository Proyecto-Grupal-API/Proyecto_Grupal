<script setup>
import PlatformLayout from '@/Layouts/PlatformLayout.vue'
defineOptions({ layout: PlatformLayout })
import { ref, onMounted } from 'vue'
import { useRouter } from '@/Platform/navigation.js'
import StatCard from '@/Platform/components/ui/StatCard.vue'
import { adminDashboardInitial } from '@/Platform/initialState.js'
import { api } from '@/Platform/services/api.js'

const router = useRouter()
const data = ref({ ...adminDashboardInitial })
const isLoading = ref(true)

// Gráfico de barras simple — sin librería externa
function maxVal() { return Math.max(1, ...data.value.chartData.issued) }
function barH(v) { return Math.round((v / maxVal()) * 100) }
function fmt(n) {
  if (n >= 1000000) return (n/1000000).toFixed(1)+'M'
  if (n >= 1000) return (n/1000).toFixed(0)+'K'
  return n
}

const avatarColorMap = { 'LM': '#16a34a', 'JR': '#2878ED', 'SC': '#d97706', 'AR': '#7c3aed' }
function avatarBg(user) {
  const initials = user.split(' ').map(w => w[0]).slice(0,2).join('')
  return avatarColorMap[initials] || '#2878ED'
}

async function loadDashboard() {
  try {
    isLoading.value = true
    const stats = await api.dashboard.getStats()
    if (stats) {
      data.value.pointsIssuedMonth = stats.points_issued_month ?? data.value.pointsIssuedMonth
      data.value.completedRedemptions = stats.total_redemptions_delivered ?? data.value.completedRedemptions
      data.value.fraudAlerts = stats.open_fraud_flags ?? data.value.fraudAlerts
      data.value.adheredBusinesses = stats.active_businesses ?? 0
      data.value.auditLogs = (stats.recent_audit_logs || []).map(x=>({id:x._id || x.id,user:x.actor_id || 'No registrado',initials:'ID',action:x.action,entity:x.entity_type,date:(x.created_at || '').slice(0,16)}))
    }
  } catch (err) {
    console.warn('No se pudo actualizar la información de Recompensas.', err)
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
    <div class="page-header-row">
      <div>
        <h1 class="page-title">Recompensas</h1>
        <div class="page-subtitle">Panel de administración</div>
        <p class="page-desc">Gestiona, monitorea y haz crecer el programa de recompensas de Campus Digital.</p>
      </div>
      <div class="period-selector">
        <span class="pi">📅</span>
        <span>{{ data.period }}</span>
        <span class="pd">▾</span>
      </div>
    </div>

    <!-- KPIs -->
    <div class="stats-row">
      <StatCard title="Puntos emitidos este mes" :value="`${data.pointsIssuedMonth.toLocaleString()} pts`"
        icon="💎" icon-bg="#EFF6FF" icon-color="#2878ED" :link="true" />
      <StatCard title="Canjes completados" :value="data.completedRedemptions.toLocaleString()"
        icon="🛒" icon-bg="#F0FDF4" icon-color="#16a34a" :link="true"
        @click="router.push('/recompensas/admin/canjes')" />
      <StatCard title="Alertas antifraude" :value="data.fraudAlerts"
        icon="⚠" icon-bg="#FEF2F2" icon-color="#dc2626" :link="true"
        @click="router.push('/recompensas/admin/gobierno')" />
      <StatCard title="Negocios adheridos" :value="data.adheredBusinesses"
        icon="🏢" icon-bg="#F0FDF4" icon-color="#16a34a" :link="true"
        @click="router.push('/recompensas/admin/negocios')" />
    </div>

    <!-- Gráfico + Gov + Acciones -->
    <div class="mid-row">
      <!-- Gráfico principal -->
      <div class="card chart-card">
        <div class="chart-header">
          <span class="ct">Emisión vs. Canje de puntos</span>
          <div class="legend">
            <span class="leg-item"><span class="leg-dot issued"></span>Puntos emitidos</span>
            <span class="leg-item"><span class="leg-dot redeemed"></span>Puntos canjeados</span>
          </div>
        </div>
        <div class="bar-chart">
          <!-- Y axis -->
          <div class="y-axis">
            <span>250K</span><span>200K</span><span>150K</span><span>100K</span><span>50K</span><span>0</span>
          </div>
          <!-- Bars -->
          <div class="bars-area">
            <div v-for="(label, i) in data.chartData.labels" :key="label" class="bar-group">
              <div class="bar-pair">
                <div class="bar issued-bar" :style="{height: barH(data.chartData.issued[i])+'%'}" :title="fmt(data.chartData.issued[i])+'pts'"></div>
                <div class="bar redeemed-bar" :style="{height: barH(data.chartData.redeemed[i])+'%'}" :title="fmt(data.chartData.redeemed[i])+'pts'"></div>
              </div>
              <div class="bar-label">{{ label }}</div>
            </div>
          </div>
        </div>
      </div>

      <!-- Gobierno + Acciones -->
      <div class="right-col">
        <!-- Gobierno y alertas -->
        <div class="card gov-card">
          <div class="card-head"><span class="ct">Gobierno y alertas</span></div>
          <div class="gov-list">
            <div class="gov-item">
              <div class="gi-left"><span class="gi-icon">📋</span><span class="gi-label">Límites de emisión</span></div>
              <span class="gi-badge" style="background:#EFF6FF;color:#2878ED">{{ data.governance.emissionLimits }}</span>
            </div>
            <div class="gov-item">
              <div class="gi-left"><span class="gi-icon">⏰</span><span class="gi-label">Puntos por expirar</span></div>
              <span class="gi-badge" style="background:#FFFBEB;color:#D97706">{{ data.governance.expiringPoints }}</span>
            </div>
            <div class="gov-item">
              <div class="gi-left"><span class="gi-icon">⚠</span><span class="gi-label">Posibles fraudes detectados</span></div>
              <span class="gi-badge" style="background:#FEF2F2;color:#dc2626">{{ data.governance.detectedFrauds }}</span>
            </div>
            <div class="gov-item">
              <div class="gi-left"><span class="gi-icon">👥</span><span class="gi-label">Campañas por aprobar</span></div>
              <span class="gi-badge" style="background:#F5F3FF;color:#7c3aed">{{ data.governance.pendingCampaigns }}</span>
            </div>
          </div>
        </div>

        <!-- Acciones administrativas -->
        <div class="card actions-card">
          <div class="card-head"><span class="ct">Acciones administrativas</span></div>
          <div class="actions-list">
            <button class="action-item" @click="router.push('/recompensas/admin/reglas')">
              <div class="ai-icon" style="background:#EFF6FF">📢</div>
              <div class="ai-text"><div class="ai-t">Crear campaña</div><div class="ai-d">Lanza una nueva campaña de recompensas</div></div>
              <span class="ai-a">›</span>
            </button>
            <button class="action-item" @click="router.push('/recompensas/admin/reglas')">
              <div class="ai-icon" style="background:#F0FDF4">⚙</div>
              <div class="ai-text"><div class="ai-t">Ajustar reglas</div><div class="ai-d">Configura la acumulación de puntos</div></div>
              <span class="ai-a">›</span>
            </button>
            <button class="action-item" @click="router.push('/recompensas/admin/auditoria')">
              <div class="ai-icon" style="background:#F5F3FF">🔍</div>
              <div class="ai-text"><div class="ai-t">Revisar auditoría</div><div class="ai-d">Consulta eventos y trazabilidad</div></div>
              <span class="ai-a">›</span>
            </button>
          </div>
        </div>
      </div>
    </div>

    <!-- Auditoría reciente -->
    <div class="card">
      <div class="card-header-row">
        <div class="cht"><span>📋</span><span class="ct">Últimos eventos de auditoría</span></div>
        <button class="link-btn" @click="router.push('/recompensas/admin/auditoria')">Ver todos ›</button>
      </div>
      <div class="table-wrapper">
        <table class="cd-table">
          <thead><tr><th>Usuario</th><th>Acción</th><th>Entidad</th><th>Fecha</th></tr></thead>
          <tbody>
            <tr v-for="a in data.auditLogs" :key="a.id">
              <td>
                <div class="user-cell">
                  <div class="av" :style="{background: avatarBg(a.user)}">{{ a.initials }}</div>
                  {{ a.user }}
                </div>
              </td>
              <td>{{ a.action }}</td>
              <td style="color:var(--cd-text-muted);font-size:12px">{{ a.entity }}</td>
              <td style="color:var(--cd-text-muted);font-size:12px;white-space:nowrap">{{ a.date }}</td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</template>

<script>
const avatarColors = { 'LM': '#16a34a', 'JR': '#2878ED', 'SC': '#d97706', 'AR': '#7c3aed' }
export default {
  methods: {
    avatarBg(name) {
      const initials = name.split(' ').map(w => w[0]).slice(0,2).join('')
      return avatarColors[initials] || '#2878ED'
    }
  }
}
</script>

<style scoped>
.page { display: flex; flex-direction: column; gap: 20px; }

.page-header-row {
  display: flex; align-items: flex-end; justify-content: space-between;
}

.period-selector {
  display: flex; align-items: center; gap: 6px;
  padding: 8px 14px; border: 1px solid var(--cd-border);
  border-radius: 8px; background: #fff; font-size: 13px;
  font-weight: 500; color: var(--cd-text-primary); cursor: pointer;
}
.pi { font-size: 14px; }
.pd { color: var(--cd-text-muted); }

.stats-row { display: grid; grid-template-columns: repeat(4,1fr); gap: 16px; }

/* Mid row */
.mid-row { display: grid; grid-template-columns: 1fr 380px; gap: 16px; }

/* Chart */
.chart-card { overflow: hidden; }
.chart-header {
  display: flex; align-items: center; justify-content: space-between;
  padding: 16px 18px 12px; border-bottom: 1px solid var(--cd-border-light);
}
.ct { font-size: 14px; font-weight: 700; color: var(--cd-text-primary); }
.legend { display: flex; gap: 16px; }
.leg-item { display: flex; align-items: center; gap: 5px; font-size: 12px; color: var(--cd-text-secondary); }
.leg-dot { width: 10px; height: 10px; border-radius: 50%; }
.leg-dot.issued { background: #2878ED; }
.leg-dot.redeemed { background: #93C5FD; }

.bar-chart { display: flex; padding: 12px 18px 12px 8px; height: 200px; gap: 8px; }
.y-axis {
  display: flex; flex-direction: column; justify-content: space-between;
  font-size: 10px; color: var(--cd-text-muted); padding-bottom: 20px;
  width: 36px; flex-shrink: 0; text-align: right;
}
.bars-area { flex: 1; display: flex; align-items: flex-end; gap: 6px; }
.bar-group { flex: 1; display: flex; flex-direction: column; align-items: center; }
.bar-pair { width: 100%; display: flex; gap: 3px; align-items: flex-end; height: 155px; }
.bar { flex: 1; border-radius: 3px 3px 0 0; min-height: 2px; transition: height 0.4s; }
.issued-bar { background: #2878ED; }
.redeemed-bar { background: #93C5FD; }
.bar-label { font-size: 11px; color: var(--cd-text-muted); margin-top: 4px; }

/* Right col */
.right-col { display: flex; flex-direction: column; gap: 16px; }

/* Gov */
.gov-card { overflow: hidden; }
.card-head { padding: 14px 18px 10px; border-bottom: 1px solid var(--cd-border-light); }
.gov-list { padding: 8px 0; }
.gov-item { display: flex; align-items: center; justify-content: space-between; padding: 10px 18px; border-bottom: 1px solid var(--cd-border-light); }
.gov-item:last-child { border-bottom: none; }
.gi-left { display: flex; align-items: center; gap: 10px; }
.gi-icon { font-size: 16px; }
.gi-label { font-size: 13px; color: var(--cd-text-secondary); }
.gi-badge {
  min-width: 32px; height: 24px; border-radius: 12px;
  display: flex; align-items: center; justify-content: center;
  font-size: 12px; font-weight: 700; padding: 0 8px;
}

/* Actions */
.actions-card { overflow: hidden; }
.actions-list { padding: 4px 0; }
.action-item {
  display: flex; align-items: center; gap: 12px;
  padding: 12px 18px; border: none; background: transparent;
  width: 100%; cursor: pointer; font-family: var(--cd-font);
  text-align: left; border-bottom: 1px solid var(--cd-border-light);
  transition: background 0.15s;
}
.action-item:last-child { border-bottom: none; }
.action-item:hover { background: #f8fafd; }
.ai-icon { width: 34px; height: 34px; border-radius: 8px; display: flex; align-items: center; justify-content: center; font-size: 16px; flex-shrink: 0; }
.ai-text { flex: 1; }
.ai-t { font-size: 13px; font-weight: 600; color: var(--cd-text-primary); }
.ai-d { font-size: 11px; color: var(--cd-text-muted); }
.ai-a { font-size: 18px; color: var(--cd-text-muted); }

/* Audit table */
.card-header-row { display: flex; align-items: center; justify-content: space-between; padding: 16px 18px 12px; border-bottom: 1px solid var(--cd-border-light); }
.cht { display: flex; align-items: center; gap: 8px; }
.link-btn { font-size: 12px; color: var(--cd-primary); background: none; border: none; cursor: pointer; font-family: var(--cd-font); font-weight: 500; }
.user-cell { display: flex; align-items: center; gap: 8px; }
.av { width: 28px; height: 28px; border-radius: 50%; color: #fff; display: flex; align-items: center; justify-content: center; font-size: 11px; font-weight: 600; flex-shrink: 0; }

@media (max-width: 1200px) { .stats-row { grid-template-columns: repeat(2,1fr); } }
@media (max-width: 1100px) { .mid-row { grid-template-columns: 1fr; } }
@media (max-width: 768px)  { .stats-row { grid-template-columns: 1fr; } }
</style>
