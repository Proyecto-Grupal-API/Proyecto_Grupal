<script setup>
import { ref } from 'vue'
import { useRouter } from 'vue-router'
import StatCard from '../../components/ui/StatCard.vue'
import StatusBadge from '../../components/ui/StatusBadge.vue'
import ConfirmModal from '../../components/ui/ConfirmModal.vue'
import { useToast } from '../../composables/useToast.js'
import { professorDashboardMock } from '../../mocks/rewards.js'

import { onMounted } from 'vue'
import { api } from '../../services/api.js'

const router = useRouter()
const { success, error } = useToast()
const data = ref({ ...professorDashboardMock })

const redemptions = ref([...data.value.pendingRedemptions])
const selectedRd = ref(null)
const showModal = ref(false)
const isLoading = ref(true)

async function loadDashboard() {
  try {
    isLoading.value = true
    const stats = await api.dashboard.getStats()
    if (stats) {
      data.value.balance = stats.balance ?? data.value.balance
      data.value.pendingValidations = stats.pending_validations ?? data.value.pendingValidations
      data.value.activeCampaigns = stats.active_campaigns ?? data.value.activeCampaigns
      if (stats.pending_redemptions && stats.pending_redemptions.length) {
        redemptions.value = stats.pending_redemptions.map(r => ({
          id: r._id || r.id,
          _id: r._id || r.id,
          student: r.student?.name || 'Estudiante',
          initials: r.student?.name ? r.student.name.split(' ').map(n=>n[0]).join('').slice(0,2) : 'AL',
          reward: r.reward?.name || 'Recompensa',
          code: r.redemption_code,
          points: r.points_spent,
          date: r.requested_at ? r.requested_at.slice(0, 10) : 'Hoy',
          status: 'Pendiente',
        }))
      }
    }
  } catch (err) {
    console.warn('Usando dashboard local de profesor:', err)
  } finally {
    isLoading.value = false
  }
}

function openValidate(rd) {
  selectedRd.value = rd
  showModal.value = true
}

async function confirmValidate() {
  if (!selectedRd.value) return
  try {
    await api.redemptions.deliver(selectedRd.value._id || selectedRd.value.id, {
      note: 'Validado y entregado desde el panel docente',
    })
    redemptions.value = redemptions.value.map(r =>
      r.id === selectedRd.value.id ? { ...r, status: 'Entregado' } : r
    )
    showModal.value = false
    success(`Canje de ${selectedRd.value.student} entregado y registrado exitosamente.`)
    selectedRd.value = null
  } catch (err) {
    console.error('Error al entregar canje:', err)
    error(err.message || 'No se pudo validar el canje.')
    showModal.value = false
  }
}

const colors = ['#2878ED','#16a34a','#d97706','#7c3aed']
function avatarColor(idx) { return colors[idx % colors.length] }

onMounted(() => {
  loadDashboard()
})
</script>

<template>
  <div class="page">
    <!-- Header -->
    <div class="page-header">
      <h1 class="page-title">Recompensas</h1>
      <div class="page-subtitle">Panel del profesor</div>
      <p class="page-desc">Reconoce el esfuerzo de tus estudiantes y fomenta su participación académica.</p>
    </div>

    <!-- KPIs -->
    <div class="stats-row">
      <StatCard title="Saldo de puntos" :value="`${data.balance.toLocaleString()} pts`"
        icon="💰" icon-bg="#F0FDF4" icon-color="#16a34a" :link="true"
        @click="router.push('/recompensas/profesor')" />
      <StatCard title="Validaciones pendientes" :value="`${data.pendingValidations} canjes`"
        icon="⏰" icon-bg="#FFFBEB" icon-color="#D97706" :link="true"
        @click="router.push('/recompensas/profesor/validar')" />
      <StatCard title="Campañas activas" :value="`${data.activeCampaigns} campañas`"
        icon="📢" icon-bg="#EFF6FF" icon-color="#2878ED" :link="true"
        @click="router.push('/recompensas/profesor/campanas')" />
    </div>

    <!-- Campaña destacada -->
    <div class="campaign-hero card">
      <div class="ch-content">
        <div class="ch-badge">{{ data.featuredCampaign.multiplier }}</div>
        <div>
          <h2 class="ch-title">{{ data.featuredCampaign.title }}</h2>
          <p class="ch-desc">{{ data.featuredCampaign.description }}</p>
          <span class="ch-period">{{ data.featuredCampaign.period }}</span>
        </div>
      </div>
      <div class="ch-visual">🎓</div>
    </div>

    <!-- Canjes por validar -->
    <div class="card">
      <div class="card-header-row">
        <div class="card-title-wrap">
          <span class="card-icon">🎟</span>
          <span class="card-title">Canjes por validar</span>
        </div>
        <button class="link-btn" @click="router.push('/recompensas/profesor/validar')">Ver todos ›</button>
      </div>
      <div class="table-wrapper">
        <table class="cd-table">
          <thead>
            <tr><th>Alumno</th><th>Recompensa</th><th>Código / Folio</th><th>Fecha</th><th>Estado</th><th>Acción</th></tr>
          </thead>
          <tbody>
            <tr v-for="(rd, idx) in redemptions.slice(0,4)" :key="rd.id">
              <td>
                <div class="student-cell">
                  <div class="av" :style="{background: avatarColor(idx)}">{{ rd.initials }}</div>
                  {{ rd.student }}
                </div>
              </td>
              <td>{{ rd.reward }}</td>
              <td><code class="code-tag">{{ rd.code }}</code></td>
              <td style="color:var(--cd-text-muted);font-size:12px">{{ rd.date }}</td>
              <td><StatusBadge :status="rd.status" /></td>
              <td>
                <button class="btn btn-primary btn-sm"
                  :disabled="rd.status === 'Validado'"
                  @click="openValidate(rd)">
                  {{ rd.status === 'Validado' ? 'Validado' : 'Validar' }}
                </button>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>

    <!-- Gráfico + Acciones rápidas -->
    <div class="bottom-row">
      <!-- Participación por grupo -->
      <div class="card chart-card">
        <div class="card-header-row">
          <div class="card-title-wrap">
            <span class="card-icon">📊</span>
            <span class="card-title">Participación por grupo</span>
          </div>
        </div>
        <div class="bar-chart">
          <div v-for="g in data.groupParticipation" :key="g.group" class="bar-col">
            <div class="bar-pct">{{ g.pct }}%</div>
            <div class="bar-track">
              <div class="bar-fill" :style="{ height: g.pct + '%' }"></div>
            </div>
            <div class="bar-label">{{ g.group }}</div>
          </div>
        </div>
      </div>

      <!-- Acciones rápidas -->
      <div class="card actions-card">
        <div class="card-header-row">
          <div class="card-title-wrap">
            <span class="card-icon">⚡</span>
            <span class="card-title">Acciones rápidas</span>
          </div>
        </div>
        <div class="actions-list">
          <button class="action-item" @click="router.push('/recompensas/profesor/validar')">
            <div class="ai-icon-wrap" style="background:#EFF6FF">✅</div>
            <div class="ai-text">
              <div class="ai-title">Validar canje</div>
              <div class="ai-desc">Revisa y confirma canjes de tus estudiantes</div>
            </div>
            <span class="ai-arrow">›</span>
          </button>
          <button class="action-item" @click="router.push('/recompensas/profesor/analitica')">
            <div class="ai-icon-wrap" style="background:#F0FDF4">📊</div>
            <div class="ai-text">
              <div class="ai-title">Ver analítica</div>
              <div class="ai-desc">Conoce la participación por grupo</div>
            </div>
            <span class="ai-arrow">›</span>
          </button>
        </div>
      </div>
    </div>

    <ConfirmModal
      :show="showModal"
      title="Validar canje"
      :message="`¿Deseas validar el canje de ${selectedRd?.student} — ${selectedRd?.reward}?`"
      confirm-label="Validar"
      @confirm="confirmValidate"
      @cancel="showModal = false"
    />
  </div>
</template>

<style scoped>
.page { display: flex; flex-direction: column; gap: 20px; }
.stats-row { display: grid; grid-template-columns: repeat(3, 1fr); gap: 16px; }

/* Campaign hero */
.campaign-hero {
  background: linear-gradient(135deg, #1d4ed8, #2878ED);
  color: #fff;
  padding: 24px 28px;
  display: flex; align-items: center; justify-content: space-between;
  border-color: #1d4ed8;
}
.ch-content { display: flex; align-items: center; gap: 20px; }
.ch-badge {
  width: 60px; height: 60px; border-radius: 50%;
  background: #F59E0B; color: #fff;
  display: flex; align-items: center; justify-content: center;
  font-size: 20px; font-weight: 800; flex-shrink: 0;
}
.ch-title { font-size: 18px; font-weight: 700; margin-bottom: 4px; }
.ch-desc { font-size: 13px; opacity: 0.85; margin-bottom: 8px; }
.ch-period {
  display: inline-block; padding: 3px 12px; border-radius: 20px;
  background: rgba(255,255,255,0.2); font-size: 12px;
}
.ch-visual { font-size: 60px; opacity: 0.3; }

/* Card rows */
.card-header-row {
  display: flex; align-items: center; justify-content: space-between;
  padding: 16px 18px 12px; border-bottom: 1px solid var(--cd-border-light);
}
.card-title-wrap { display: flex; align-items: center; gap: 8px; }
.card-icon { font-size: 16px; }
.card-title { font-size: 14px; font-weight: 700; color: var(--cd-text-primary); }
.link-btn {
  font-size: 12px; color: var(--cd-primary); background: none; border: none;
  cursor: pointer; font-family: var(--cd-font); font-weight: 500;
}

/* Table */
.student-cell { display: flex; align-items: center; gap: 8px; font-size: 13px; }
.av {
  width: 28px; height: 28px; border-radius: 50%; color: #fff;
  display: flex; align-items: center; justify-content: center;
  font-size: 11px; font-weight: 600; flex-shrink: 0;
}
.code-tag { font-size: 11px; background: #f0f3f9; padding: 2px 6px; border-radius: 4px; }

/* Bottom row */
.bottom-row { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; }

/* Bar chart */
.chart-card { overflow: hidden; }
.bar-chart {
  display: flex; align-items: flex-end; gap: 12px;
  padding: 16px 18px 12px; height: 160px;
}
.bar-col { display: flex; flex-direction: column; align-items: center; flex: 1; }
.bar-pct { font-size: 11px; font-weight: 600; color: var(--cd-text-secondary); margin-bottom: 4px; }
.bar-track {
  width: 100%; flex: 1; background: #f0f3f9; border-radius: 4px 4px 0 0;
  display: flex; align-items: flex-end; overflow: hidden;
}
.bar-fill {
  width: 100%; background: var(--cd-primary); border-radius: 4px 4px 0 0;
  transition: height 0.3s;
}
.bar-label { font-size: 11px; color: var(--cd-text-muted); margin-top: 6px; }

/* Actions */
.actions-card { overflow: hidden; }
.actions-list { padding: 8px 0; }
.action-item {
  display: flex; align-items: center; gap: 14px; padding: 14px 18px;
  border: none; background: transparent; width: 100%; cursor: pointer;
  text-align: left; font-family: var(--cd-font); border-bottom: 1px solid var(--cd-border-light);
  transition: background 0.15s;
}
.action-item:last-child { border-bottom: none; }
.action-item:hover { background: #f8fafd; }
.ai-icon-wrap {
  width: 38px; height: 38px; border-radius: 10px;
  display: flex; align-items: center; justify-content: center;
  font-size: 18px; flex-shrink: 0;
}
.ai-text { flex: 1; }
.ai-title { font-size: 13px; font-weight: 600; color: var(--cd-text-primary); }
.ai-desc { font-size: 12px; color: var(--cd-text-muted); }
.ai-arrow { font-size: 18px; color: var(--cd-text-muted); }

@media (max-width: 1100px) {
  .bottom-row { grid-template-columns: 1fr; }
}
@media (max-width: 768px) {
  .stats-row { grid-template-columns: 1fr; }
}
</style>
