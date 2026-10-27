<script setup>
import PlatformLayout from '@/Layouts/PlatformLayout.vue'
defineOptions({ layout: PlatformLayout })
import { ref, computed, onMounted } from 'vue'
import StatusBadge from '@/Platform/components/ui/StatusBadge.vue'
import EmptyState from '@/Platform/components/ui/EmptyState.vue'
import { activityInitial } from '@/Platform/initialState.js'
import { api } from '@/Platform/services/api.js'

const filter = ref('all')
const allActivity = ref([...activityInitial])
const balanceData = ref({
  balance: 0,
  lifetimeEarned: 0,
  lifetimeRedeemed: 0,
})
const isLoading = ref(true)

const filtered = computed(() => {
  if (filter.value === 'all') return allActivity.value
  return allActivity.value.filter(a => a.type === filter.value)
})

function applyFilter(type) {
  filter.value = type
}

function formatPoints(pts) {
  return (pts > 0 ? '+' : '') + pts.toLocaleString()
}

async function loadActivity() {
  try {
    isLoading.value = true
    const [balanceRes, historyRes] = await Promise.allSettled([
      api.points.getMyBalance(),
      api.points.getMyHistory(),
    ])

    if (balanceRes.status === 'fulfilled' && balanceRes.value) {
      balanceData.value.balance = balanceRes.value.balance ?? balanceData.value.balance
      balanceData.value.lifetimeEarned = balanceRes.value.lifetime_earned ?? balanceData.value.lifetimeEarned
      balanceData.value.lifetimeRedeemed = balanceRes.value.lifetime_redeemed ?? balanceData.value.lifetimeRedeemed
    }

    if (historyRes.status === 'fulfilled' && historyRes.value) {
      const list = Array.isArray(historyRes.value) ? historyRes.value : (historyRes.value.data || [])
      if (list.length > 0) {
        allActivity.value = list.map(item => ({
          id: item._id || item.id,
          date: item.created_at ? item.created_at.slice(0, 10) : 'Hoy',
          concept: item.description || (item.type === 'earn' ? 'Puntos acumulados' : 'Canje de recompensa'),
          source: item.source_domain || 'campus',
          points: item.amount || 0,
          type: item.type === 'earn' ? 'earn' : 'redeem',
          status: 'Completado',
        }))
      }
    }
  } catch (err) {
    console.warn('No se pudo actualizar la información de Recompensas.', err)
  } finally {
    isLoading.value = false
  }
}

onMounted(() => {
  loadActivity()
})
</script>

<template>
  <div class="page">
    <div class="page-header">
      <div>
        <h1 class="page-title">Mi actividad</h1>
        <div class="page-subtitle">Panel del alumno</div>
        <p class="page-desc">Historial completo de tus movimientos de puntos y transacciones.</p>
      </div>
    </div>

    <!-- Balance -->
    <div class="balance-strip card">
      <div class="bs-item">
        <div class="bs-label">Saldo actual</div>
        <div class="bs-value blue">{{ balanceData.balance.toLocaleString() }} pts</div>
      </div>
      <div class="bs-divider"></div>
      <div class="bs-item">
        <div class="bs-label">Total ganado</div>
        <div class="bs-value green">+{{ balanceData.lifetimeEarned.toLocaleString() }} pts</div>
      </div>
      <div class="bs-divider"></div>
      <div class="bs-item">
        <div class="bs-label">Total canjeado</div>
        <div class="bs-value red">-{{ balanceData.lifetimeRedeemed.toLocaleString() }} pts</div>
      </div>
    </div>

    <!-- Filtros -->
    <div class="filter-row">
      <button v-for="f in [{k:'all',l:'Todos'},{k:'earn',l:'Ganancias'},{k:'redeem',l:'Canjes'}]"
        :key="f.k" class="filter-btn" :class="{active: filter===f.k}" @click="applyFilter(f.k)">
        {{ f.l }}
      </button>
    </div>

    <!-- Tabla -->
    <div class="card">
      <EmptyState v-if="!isLoading && !filtered.length" title="Sin movimientos" message="No hay movimientos para el filtro seleccionado." icon="🔍" />
      <div v-else class="table-wrapper">
        <table class="cd-table">
          <thead>
            <tr>
              <th>Fecha</th>
              <th>Concepto</th>
              <th>Fuente</th>
              <th>Puntos</th>
              <th>Estado</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="item in filtered" :key="item.id || (item.date + item.concept)">
              <td style="color:var(--cd-text-muted);font-size:12px;white-space:nowrap">{{ item.date }}</td>
              <td style="font-weight:500">{{ item.concept }}</td>
              <td><span class="source-tag">{{ item.source }}</span></td>
              <td>
                <span :class="item.points > 0 ? 'pts-pos' : 'pts-neg'">{{ formatPoints(item.points) }}</span>
              </td>
              <td><StatusBadge :status="item.status" /></td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</template>

<style scoped>
.page { display: flex; flex-direction: column; gap: 20px; }
.page-header { margin-bottom: 4px; }

.balance-strip { display: flex; padding: 20px 24px; gap: 0; }
.bs-item { flex: 1; text-align: center; }
.bs-divider { width: 1px; background: var(--cd-border); }
.bs-label { font-size: 12px; color: var(--cd-text-secondary); margin-bottom: 6px; font-weight: 500; }
.bs-value { font-size: 20px; font-weight: 700; }
.blue { color: var(--cd-primary); }
.green { color: #16a34a; }
.red { color: #dc2626; }

.filter-row { display: flex; gap: 8px; }
.filter-btn {
  padding: 6px 16px; border-radius: 20px; border: 1px solid var(--cd-border);
  background: #fff; color: var(--cd-text-secondary); font-size: 13px;
  font-family: var(--cd-font); cursor: pointer; transition: all 0.15s;
}
.filter-btn.active { background: var(--cd-primary); color: #fff; border-color: var(--cd-primary); }
.filter-btn:hover:not(.active) { background: #f0f3f9; }

.source-tag {
  padding: 2px 8px; background: #f0f3f9; border-radius: 12px;
  font-size: 11px; color: var(--cd-text-secondary);
  text-transform: uppercase;
}
.pts-pos { color: #16a34a; font-weight: 600; }
.pts-neg { color: #dc2626; font-weight: 600; }
</style>
