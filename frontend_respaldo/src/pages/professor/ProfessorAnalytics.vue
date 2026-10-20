<script setup>
import { ref, onMounted } from 'vue'
import { professorDashboardMock } from '../../mocks/rewards.js'
import { api } from '../../services/api.js'

const data = ref({ ...professorDashboardMock })
const topStudents = ref([
  { rank: 1, name: 'Ana García', group: '3°A', points: 580, redemptions: 4 },
  { rank: 2, name: 'Laura Martínez', group: '1°B', points: 520, redemptions: 3 },
  { rank: 3, name: 'Carlos Rodríguez', group: '5°B', points: 480, redemptions: 2 },
  { rank: 4, name: 'Juan Pérez', group: '1°A', points: 420, redemptions: 3 },
])
const isLoading = ref(true)

async function loadAnalytics() {
  try {
    isLoading.value = true
    const res = await api.analytics.getProfessorAnalytics()
    if (res && res.metrics) {
      if (res.group_participation) {
        data.value.groupParticipation = res.group_participation
      }
      if (res.top_students && res.top_students.length) {
        topStudents.value = res.top_students.map((s, idx) => ({
          rank: idx + 1,
          name: s.name,
          group: s.department || 'Campus',
          points: s.balance || 0,
          redemptions: s.lifetime_redeemed ? Math.floor(s.lifetime_redeemed / 200) : 1,
        }))
      }
    }
  } catch (err) {
    console.warn('Usando analítica local:', err)
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
    <div class="page-header">
      <h1 class="page-title">Analítica docente</h1>
      <div class="page-subtitle">Panel del profesor</div>
      <p class="page-desc">Visualiza la participación académica, canjes entregados y el desempeño por grupos.</p>
    </div>

    <div class="grid-2">
      <div class="card">
        <div class="ch"><span class="ci">📊</span><span class="ct">Participación por grupo</span></div>
        <div class="bar-chart">
          <div v-for="g in data.groupParticipation" :key="g.group" class="bc">
            <div class="bpct">{{ g.pct }}%</div>
            <div class="btrack"><div class="bfill" :style="{height:g.pct+'%'}"></div></div>
            <div class="blabel">{{ g.group }}</div>
          </div>
        </div>
      </div>

      <div class="card summary-card">
        <div class="ch"><span class="ci">📈</span><span class="ct">Resumen docente</span></div>
        <div class="sum-list">
          <div class="sum-item"><span class="sl">Grupos con >80% participación</span><span class="sv green">3 / 5</span></div>
          <div class="sum-item"><span class="sl">Canjes validados este periodo</span><span class="sv blue">24</span></div>
          <div class="sum-item"><span class="sl">Puntos acumulados en aula</span><span class="sv blue">4,800</span></div>
          <div class="sum-item"><span class="sl">Alumnos activos participando</span><span class="sv">142</span></div>
        </div>
      </div>
    </div>

    <div class="card">
      <div class="ch"><span class="ci">🏆</span><span class="ct">Alumnos más activos</span></div>
      <div class="table-wrapper">
        <table class="cd-table">
          <thead>
            <tr><th>#</th><th>Alumno</th><th>Grupo</th><th>Puntos</th><th>Canjes</th></tr>
          </thead>
          <tbody>
            <tr v-for="s in topStudents" :key="s.rank">
              <td>{{ s.rank }}</td>
              <td style="font-weight:600">{{ s.name }}</td>
              <td>{{ s.group }}</td>
              <td style="color:var(--cd-primary);font-weight:600">{{ s.points }} pts</td>
              <td>{{ s.redemptions }}</td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</template>

<style scoped>
.page{display:flex;flex-direction:column;gap:20px;}
.grid-2{display:grid;grid-template-columns:1fr 1fr;gap:16px;}
.ch{display:flex;align-items:center;gap:8px;padding:16px 18px 12px;border-bottom:1px solid var(--cd-border-light);}
.ci{font-size:16px;} .ct{font-size:14px;font-weight:700;color:var(--cd-text-primary);}
.bar-chart{display:flex;align-items:flex-end;gap:12px;padding:16px 18px 12px;height:180px;}
.bc{display:flex;flex-direction:column;align-items:center;flex:1;}
.bpct{font-size:11px;font-weight:600;color:var(--cd-text-secondary);margin-bottom:4px;}
.btrack{width:100%;flex:1;background:#f0f3f9;border-radius:4px 4px 0 0;display:flex;align-items:flex-end;overflow:hidden;}
.bfill{width:100%;background:var(--cd-primary);border-radius:4px 4px 0 0;}
.blabel{font-size:11px;color:var(--cd-text-muted);margin-top:6px;}
.sum-list{padding:12px 18px;display:flex;flex-direction:column;gap:12px;}
.sum-item{display:flex;justify-content:space-between;align-items:center;font-size:13px;color:var(--cd-text-secondary);border-bottom:1px solid var(--cd-border-light);padding-bottom:8px;}
.sum-item:last-child{border-bottom:none;}
.sv{font-weight:700;}
.green{color:#16a34a;}
.blue{color:var(--cd-primary);}
@media(max-width:800px){.grid-2{grid-template-columns:1fr;}}
</style>
