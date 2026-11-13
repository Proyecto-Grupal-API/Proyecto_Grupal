<script setup>
import PlatformLayout from '@/Layouts/PlatformLayout.vue'
defineOptions({ layout: PlatformLayout })
import { ref, onMounted } from 'vue'
import { adminDashboardInitial } from '@/Platform/initialState.js'
import { api } from '@/Platform/services/api.js'
import axios from 'axios'

const data = ref({ ...adminDashboardInitial })
const topRewards = ref([])
const isLoading = ref(true)
const community = ref(null)
const communityLoading = ref(true)
const communityError = ref('')
const communityDays = ref(30)
const communityOrganization = ref('')
const communitySource = ref('eventos')
const communityMetricLabels = {
  eventos_iniciados: ['Eventos publicados iniciados', 'eventos'],
  eventos_cancelados: ['Eventos cancelados', 'eventos'],
  reservas_confirmadas: ['Reservas confirmadas', 'eventos'],
  reservas_espera: ['Reservas en espera', 'eventos'],
  reservas_pago_pendiente: ['Reservas con pago pendiente', 'eventos'],
  asistencias: ['Asistencias registradas', 'eventos'],
  eventos_cobros_confirmados: ['Cobros E2 confirmados', 'eventos'],
  eventos_devoluciones_confirmadas: ['Devoluciones E2 verificables', 'eventos'],
  eventos_cobrado_bruto_centavos: ['Cobrado bruto E2', 'eventos'],
  eventos_devuelto_centavos: ['Devuelto E2 de los cobros seleccionados', 'eventos'],
  eventos_cobrado_neto_centavos: ['Cobrado neto de devoluciones verificables (no saldo)', 'eventos'],
  encuestas_cerradas: ['Encuestas cerradas del reporte', 'participacion'],
  encuestas_participaciones: ['Participaciones en encuestas cerradas', 'participacion'],
  votaciones_cerradas: ['Votaciones cerradas del reporte', 'participacion'],
  votaciones_participaciones: ['Participaciones en votaciones cerradas', 'participacion'],
  solicitudes_enviadas: ['Solicitudes de beca enviadas', 'becas'],
  solicitudes_pendiente: ['Solicitudes pendientes', 'becas'],
  solicitudes_en_revision: ['Solicitudes en revisión', 'becas'],
  solicitudes_aprobada: ['Solicitudes aprobadas', 'becas'],
  solicitudes_rechazada: ['Solicitudes rechazadas', 'becas'],
  solicitudes_retirada: ['Solicitudes retiradas', 'becas'],
  solicitudes_cancelada: ['Solicitudes canceladas', 'becas'],
  aprobaciones_periodo: ['Apoyos aprobados en el periodo', 'becas'],
  personas_aprobadas: ['Personas distintas con apoyo aprobado', 'becas'],
  asignaciones_pendientes: ['Asignaciones pendientes de integración', 'becas'],
  monto_pendiente_centavos: ['Monto aprobado pendiente de integración (no entregado)', 'becas'],
  apoyos_entregados: ['Apoyos con entrega confirmada', 'becas'],
  apoyos_entregados_locker: ['Lockers entregados', 'becas'],
  apoyos_entregados_impresiones: ['Apoyos de impresiones entregados', 'becas'],
  apoyos_entregados_bono: ['Bonos BECA emitidos', 'becas'],
  mensajes_entregados: ['Mensajes entregados', 'comunicacion'],
  mensajes_leidos: ['Entregas leídas al menos una vez', 'comunicacion'],
}

function communityMetrics(report) {
  return Object.entries(report.metrics)
    .filter(([key]) => communityMetricLabels[key]?.[1] === communitySource.value)
    .map(([key, value]) => ({ key, label: communityMetricLabels[key][0], value }))
}

function communityMetricValue(key, value) {
  return community.value.metric_units[key] === 'centavos_mxn'
    ? (value / 100).toLocaleString('es-MX', { style: 'currency', currency: 'MXN' }) + ' MXN'
    : value.toLocaleString('es-MX')
}

function communityDate(value) {
  return new Date(value).toLocaleString('es-MX', { timeZone: 'America/Mexico_City' })
}

async function loadCommunity(page = 1) {
  communityLoading.value = true
  communityError.value = ''
  community.value = null
  try {
    // Same Axios instance/session as the platform helper; no service token in the browser.
    const params = { days: communityDays.value, page, per_page: 10 }
    if (communityOrganization.value.trim()) params.organization_id = communityOrganization.value.trim()
    const { data: result } = await axios.get('/api/v1/recompensas/analytics/community', {
      params, headers: { Accept: 'application/json' },
    })
    if (typeof result?.linked !== 'boolean' || !Array.isArray(result.reports?.data)
      || !Number.isInteger(result.reports.current_page) || !Number.isInteger(result.reports.last_page)
      || (result.linked && !['report_count', 'closed_surveys', 'closed_elections']
        .every(key => Number.isInteger(result.stats?.[key]) && result.stats[key] >= 0))) {
      throw new Error('Respuesta de Comunidad incompleta')
    }
    community.value = result
  } catch (error) {
    const status = error.response?.status
    communityError.value = status === 422 ? 'Revisa la organización (24 caracteres hexadecimales) y los días (1 a 366).'
      : status === 403 ? 'Tu sesión no tiene permiso para consultar esta analítica.'
      : status === 401 ? 'La sesión terminó. Inicia sesión para consultar Comunidad.'
      : 'No se pudieron consultar los agregados de Comunidad. Vuelve a intentarlo.'
  } finally {
    communityLoading.value = false
  }
}

function maxVal() { return Math.max(1, ...data.value.chartData.issued, ...data.value.chartData.redeemed) }
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
  loadCommunity()
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
          <div class="y-axis"><span v-for="fraction in [1, 0.8, 0.6, 0.4, 0.2, 0]" :key="fraction">{{ (maxVal() * fraction).toLocaleString('es-MX', { maximumFractionDigits: 1 }) }}</span></div>
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
        <p class="source-list">El desglose por fuente todavía no está disponible.</p>
      </div>
    </div>

    <section class="card community-section" aria-labelledby="community-title" :aria-busy="communityLoading">
      <div class="ch"><h2 id="community-title" class="ct">Comunidad · Agregados publicados</h2></div>
      <div class="community-body">
        <p class="community-note">Reportes vigentes recibidos de Comunidad. Cada reporte conserva su propio periodo; sus métricas no se suman porque pueden solaparse.</p>
        <form class="community-filters" @submit.prevent="loadCommunity(1)">
          <label>Organización (ID opcional)
            <input v-model="communityOrganization" class="cd-input" pattern="[a-fA-F0-9]{24}" maxlength="24"
              placeholder="Todas las organizaciones" :disabled="communityLoading" />
          </label>
          <label>Últimos días
            <input v-model.number="communityDays" class="cd-input" type="number" min="1" max="366" required :disabled="communityLoading" />
          </label>
          <button class="btn btn-primary" type="submit" :disabled="communityLoading">Consultar Comunidad</button>
        </form>
        <p v-if="communityLoading" role="status" class="community-note">Cargando agregados de Comunidad…</p>
        <p v-else-if="communityError" role="alert" class="community-error">{{ communityError }}</p>
        <p v-else-if="!community?.linked" role="status" class="community-note">Sin datos vinculados de Comunidad para esta organización. Los indicadores aparecerán cuando se reciban eventos válidos.</p>
        <template v-else>
          <dl class="community-counts">
            <div><dt class="kl">Reportes vigentes en la ventana</dt><dd class="kv">{{ community.stats.report_count.toLocaleString('es-MX') }}</dd></div>
            <div><dt class="kl">Encuestas cerradas</dt><dd class="kv">{{ community.stats.closed_surveys.toLocaleString('es-MX') }}</dd></div>
            <div><dt class="kl">Votaciones cerradas</dt><dd class="kv">{{ community.stats.closed_elections.toLocaleString('es-MX') }}</dd></div>
          </dl>
          <p class="community-note">Ventana: {{ communityDate(community.window.since) }} a {{ communityDate(community.window.until) }} (Ciudad de México), por fecha del evento. Se aplica el estado vigente al consultar, incluidos retiros posteriores a la publicación. Los cierres se cuentan por proceso y revisión, separados de los reportes.</p>
          <label class="community-source">Indicadores del reporte
            <select v-model="communitySource" class="cd-input">
              <option value="eventos">Eventos y asistencias</option>
              <option value="participacion">Participación en procesos cerrados</option>
              <option value="becas">Becas y apoyos</option>
              <option value="comunicacion">Comunicación</option>
            </select>
          </label>
          <p v-if="!community.reports.data.length" role="status" class="community-note">No hay reportes vigentes publicados en esta ventana.</p>
          <ul v-else class="community-reports">
            <li v-for="report in community.reports.data" :key="report.organization_id + ':' + report.report_id" class="community-report">
              <h3>Reporte <code>{{ report.report_id }}</code></h3>
              <p class="community-note">Organización <code>{{ report.organization_id }}</code> · Periodo {{ report.period.start }} a {{ report.period.end }}</p>
              <p class="community-note">Generado: {{ communityDate(report.generated_at) }} (Ciudad de México)</p>
              <dl v-if="communityMetrics(report).length" class="community-metrics">
                <div v-for="metric in communityMetrics(report)" :key="metric.key">
                  <dt>{{ metric.label }}</dt><dd>{{ communityMetricValue(metric.key, metric.value) }}</dd>
                </div>
              </dl>
              <p v-else class="community-note">Este reporte no incluye indicadores de la fuente seleccionada.</p>
            </li>
          </ul>
          <nav v-if="community.reports.last_page > 1" class="community-pagination" aria-label="Paginación de reportes de Comunidad">
            <button class="btn btn-ghost" type="button" :disabled="communityLoading || community.reports.current_page <= 1" @click="loadCommunity(community.reports.current_page - 1)">Anterior</button>
            <span>Página {{ community.reports.current_page }} de {{ community.reports.last_page }}</span>
            <button class="btn btn-ghost" type="button" :disabled="communityLoading || community.reports.current_page >= community.reports.last_page" @click="loadCommunity(community.reports.current_page + 1)">Siguiente</button>
          </nav>
        </template>
      </div>
    </section>

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
.community-section h2,.community-report h3,.community-counts,.community-counts dd,.community-metrics dd{margin:0;}
.community-body{padding:16px 20px;display:flex;flex-direction:column;gap:16px;}
.community-note{margin:0;font-size:13px;line-height:1.6;color:var(--cd-text-secondary);overflow-wrap:anywhere;}
.community-filters{display:flex;align-items:flex-end;flex-wrap:wrap;gap:12px;}
.community-filters label,.community-source{display:flex;flex-direction:column;gap:6px;font-size:12px;color:var(--cd-text-secondary);}
.community-filters label:first-child{flex:1;min-width:220px;}
.community-filters input[type=number]{max-width:100px;}
.community-source{max-width:360px;}
.community-counts{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:16px;}
.community-counts dd,.community-metrics dd{font-variant-numeric:tabular-nums;}
.community-error{margin:0;color:var(--cd-danger);font-size:13px;}
.community-reports{margin:0;padding:0;list-style:none;}
.community-report{padding:16px 0;border-top:1px solid var(--cd-border-light);}
.community-report h3{font-size:14px;color:var(--cd-text-primary);overflow-wrap:anywhere;}
.community-report code{font-size:12px;}
.community-metrics{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px 20px;margin:12px 0 0;}
.community-metrics dt{font-size:12px;color:var(--cd-text-secondary);}
.community-metrics dd{margin-top:4px;font-size:16px;font-weight:700;color:var(--cd-text-primary);}
.community-pagination{display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px;font-size:12px;}
.community-body button:disabled{opacity:.6;cursor:not-allowed;}
@media(max-width:600px){.community-counts,.community-metrics{grid-template-columns:1fr;}.community-filters label:first-child{min-width:0;flex-basis:100%;}}
@media(max-width:900px){.grid-2,.kpis{grid-template-columns:1fr;}}
</style>
