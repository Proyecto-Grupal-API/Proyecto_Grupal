<script setup>
import PlatformLayout from '@/Layouts/PlatformLayout.vue'
defineOptions({ layout: PlatformLayout })
import { ref, onMounted } from 'vue'
import { adminDashboardInitial } from '@/Platform/initialState.js'
import { api } from '@/Platform/services/api.js'

const data = ref({ ...adminDashboardInitial })
const topRewards = ref([])
const isLoading = ref(true)

function maxVal() { return Math.max(1, ...data.value.chartData.issued) }
function barH(v) { return Math.round((v / maxVal()) * 100) }

async function loadAnalytics() {
  try {
    isLoading.value = true
    const res = await api.analytics.getOverview()
    if (res && res.summary) {
      data.value.pointsIssuedMonth = res.summary.earned_in_period ?? data.value.pointsIssuedMonth
      data.value.completedRedemptions = res.redemptions_by_status?.delivered ?? data.value.completedRedemptions
      data.value.fraudAlerts = res.open_fraud_flags ?? data.value.fraudAlerts
      data.value.adheredBusinesses = res.active_businesses ?? data.value.adheredBusinesses
    }
    if (res?.activity_trend) { data.value.chartData = {labels:res.activity_trend.map(x=>x.date),issued:res.activity_trend.map(x=>x.earned),redeemed:res.activity_trend.map(x=>x.redeemed)} }
    if (res && res.top_rewards && res.top_rewards.length) {
      topRewards.value = res.top_rewards.map((r, idx) => ({
        rank: idx + 1,
        name: r.name,
        business: '',
        redemptions: r.redemptions_count ?? 0,
        points: `${(r.points_consumed || 0).toLocaleString()} pts`,
      }))
    }
  } catch (err) {
    console.warn('No se pudo actualizar la información de Recompensas.', err)
  } finally {
    isLoading.value = false
  }
}

onMounted(() => {
  loadAnalytics()
})
</script>

<template>
  <div class="page">
    <div class="ph">
      <h1 class="page-title">7.9 Dashboards y analítica global</h1>
      <div class="page-subtitle">Panel de administración</div>
      <p class="page-desc">Métricas avanzadas del programa de recompensas, tendencias de emisión y consumo de beneficios.</p>
    </div>

    <div class="kpis">
      <div class="kpi card"><div class="kl">Puntos emitidos este mes</div><div class="kv blue">{{ data.pointsIssuedMonth.toLocaleString() }}</div></div>
      <div class="kpi card"><div class="kl">Canjes completados</div><div class="kv green">{{ data.completedRedemptions.toLocaleString() }}</div></div>
      <div class="kpi card"><div class="kl">Alertas activas</div><div class="kv red">{{ data.fraudAlerts }}</div></div>
      <div class="kpi card"><div class="kl">Negocios adheridos</div><div class="kv">{{ data.adheredBusinesses }}</div></div>
    </div>

    <div class="grid-2">
      <div class="card">
        <div class="ch">
          <span class="ct">Emisión vs. Canje (Periodo consultado)</span>
          <div class="legend">
            <span class="li"><span class="ld" style="background:#2878ED"></span>Emitidos</span>
            <span class="li"><span class="ld" style="background:#93C5FD"></span>Canjeados</span>
          </div>
        </div>
        <div class="bar-chart">
          <div class="y-axis"><span>250K</span><span>200K</span><span>150K</span><span>100K</span><span>50K</span><span>0</span></div>
          <div class="bars-area">
            <div v-for="(label,i) in data.chartData.labels" :key="label" class="bar-group">
              <div class="bar-pair">
                <div class="bar" style="background:#2878ED;border-radius:3px 3px 0 0" :style="{height:barH(data.chartData.issued[i])+'%'}"></div>
                <div class="bar" style="background:#93C5FD;border-radius:3px 3px 0 0" :style="{height:barH(data.chartData.redeemed[i])+'%'}"></div>
              </div>
              <div class="blabel">{{ label }}</div>
            </div>
          </div>
        </div>
      </div>

      <div class="card">
        <div class="ch"><span class="ct">Participación por fuente de acumulación</span></div>
        <div class="donut-placeholder"><div class="dp-inner"><div class="dp-pct">—</div><div class="dp-label">Sin segmentación</div></div></div>
        <div class="source-list">
          <div class="si"><div class="sbar" style="background:#2878ED;width:60%"></div><span>Compras en Comercios (60%)</span></div>
          <div class="si"><div class="sbar" style="background:#16a34a;width:25%"></div><span>Mérito Académico (25%)</span></div>
          <div class="si"><div class="sbar" style="background:#d97706;width:15%"></div><span>Eventos Universitarios (15%)</span></div>
        </div>
      </div>
    </div>

    <div class="card">
      <div class="ch"><span class="ct">Top recompensas más canjeadas en el campus</span></div>
      <div class="table-wrapper">
        <table class="cd-table">
          <thead>
            <tr><th>#</th><th>Recompensa</th><th>Comercio Asociado</th><th>Canjes Totales</th><th>Puntos Consumidos</th></tr>
          </thead>
          <tbody>
            <tr v-for="r in topRewards" :key="r.rank">
              <td>{{ r.rank }}</td>
              <td style="font-weight:600">{{ r.name }}</td>
              <td style="color:var(--cd-text-secondary)">{{ r.business }}</td>
              <td><strong>{{ r.redemptions }}</strong></td>
              <td style="color:var(--cd-primary);font-weight:700">{{ r.points }}</td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</template>

<style scoped>
.page{display:flex;flex-direction:column;gap:20px;}
.ph{display:flex;flex-direction:column;gap:2px;}
.kpis{display:grid;grid-template-columns:repeat(4,1fr);gap:16px;}
.kpi{padding:18px 20px;}
.kl{font-size:12px;color:var(--cd-text-secondary);margin-bottom:6px;font-weight:500;}
.kv{font-size:24px;font-weight:800;color:var(--cd-text-primary);}
.blue{color:var(--cd-primary);} .green{color:#16a34a;} .red{color:#dc2626;}
.grid-2{display:grid;grid-template-columns:1fr 1fr;gap:16px;}
.ch{display:flex;align-items:center;justify-content:space-between;padding:16px 20px 12px;border-bottom:1px solid var(--cd-border-light);}
.ct{font-size:14px;font-weight:700;color:var(--cd-text-primary);}
.legend{display:flex;gap:14px;}
.li{display:flex;align-items:center;gap:6px;font-size:12px;color:var(--cd-text-secondary);}
.ld{width:10px;height:10px;border-radius:2px;}
.bar-chart{display:flex;padding:16px 20px;height:200px;gap:12px;}
.y-axis{display:flex;flex-direction:column;justify-content:space-between;font-size:10px;color:var(--cd-text-muted);text-align:right;width:30px;}
.bars-area{display:flex;flex:1;justify-content:space-around;align-items:flex-end;}
.bar-group{display:flex;flex-direction:column;align-items:center;gap:6px;}
.bar-pair{display:flex;align-items:flex-end;gap:3px;height:140px;}
.bar{width:14px;}
.blabel{font-size:11px;color:var(--cd-text-muted);}
.donut-placeholder{display:flex;align-items:center;justify-content:center;height:140px;}
.dp-inner{width:90px;height:90px;border-radius:50%;border:8px solid var(--cd-primary);display:flex;flex-direction:column;align-items:center;justify-content:center;}
.dp-pct{font-size:18px;font-weight:800;color:var(--cd-text-primary);}
.dp-label{font-size:10px;color:var(--cd-text-muted);}
.source-list{display:flex;flex-direction:column;gap:8px;padding:0 20px 16px;}
.si{display:flex;align-items:center;gap:10px;font-size:12px;color:var(--cd-text-secondary);}
.sbar{height:6px;border-radius:3px;min-width:30px;}
@media(max-width:900px){.grid-2,.kpis{grid-template-columns:1fr;}}
</style>
