<script setup>
import PlatformLayout from '@/Layouts/PlatformLayout.vue'
defineOptions({ layout: PlatformLayout })
import { ref, computed, onMounted, watch } from 'vue'
import { Link, usePage } from '@inertiajs/vue3'
import StatusBadge from '@/Platform/components/ui/StatusBadge.vue'
import EmptyState from '@/Platform/components/ui/EmptyState.vue'
import { api } from '@/Platform/services/api.js'

const page = usePage()
const movementParam = computed(() => new URL(page.url, 'http://localhost').searchParams.get('movimiento'))
const movementId = computed(() => /^[0-9a-f]{24}$/i.test(movementParam.value || '') ? movementParam.value.toLowerCase() : '')
const filter = ref('all')
const allActivity = ref([])
const balanceData = ref(null)
const balanceError = ref('')
const historyError = ref('')
const isLoading = ref(true)

const filtered = computed(() => {
  if (filter.value === 'all') return allActivity.value
  return allActivity.value.filter(a => a.type === filter.value)
})

function applyFilter(type) {
  filter.value = type
}

function formatPoints(pts) {
  if (pts == null) return '—'
  return (pts > 0 ? '+' : '') + pts.toLocaleString()
}

async function loadActivity() {
  const selected = movementParam.value
  isLoading.value = true
  allActivity.value = []
  balanceData.value = null
  balanceError.value = ''
  historyError.value = ''
  filter.value = 'all'
  if (selected !== null && !movementId.value) {
    historyError.value = 'La referencia del movimiento no es válida.'
    isLoading.value = false
    return
  }
  try {
    const [balanceRes, historyRes] = await Promise.allSettled([
      api.points.getMyBalance(),
      api.points.getMyHistory(1, movementId.value),
    ])
    if (selected !== movementParam.value) return

    if (balanceRes.status === 'fulfilled' && balanceRes.value) {
      balanceData.value = {
        balance: balanceRes.value.balance,
        lifetimeEarned: balanceRes.value.lifetime_earned,
        lifetimeRedeemed: balanceRes.value.lifetime_redeemed,
      }
    } else {
      balanceError.value = 'No se pudo consultar tu saldo. Intenta de nuevo.'
    }

    if (historyRes.status !== 'fulfilled') {
      historyError.value = 'No se pudo consultar tu actividad. Intenta de nuevo.'
      return
    }
    const list = Array.isArray(historyRes.value) ? historyRes.value : historyRes.value?.data
    if (!Array.isArray(list)) throw new Error('Historial no válido')
    allActivity.value = list.map(item => ({
      id: item._id || item.id,
      date: item.created_at ? item.created_at.slice(0, 10) : '—',
      concept: item.description || (item.type === 'earn' ? 'Puntos acumulados' : 'Canje de recompensa'),
      source: item.source_domain || 'campus',
      points: item.amount ?? null,
      type: item.type === 'earn' ? 'earn' : 'redeem',
      status: 'Completado',
    }))
  } catch {
    if (selected === movementParam.value) historyError.value = 'No se pudo consultar tu actividad. Intenta de nuevo.'
  } finally {
    if (selected === movementParam.value) isLoading.value = false
  }
}

watch(movementParam, loadActivity)
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
        <p v-if="movementId" class="page-desc">Movimiento seleccionado: <code>{{ movementId }}</code></p>
      </div>
      <Link v-if="movementParam !== null" href="/recompensas/alumno/actividad" class="btn btn-ghost btn-sm">Ver todos</Link>
    </div>

    <!-- Balance -->
    <div v-if="balanceData" class="balance-strip card">
      <div class="bs-item">
        <div class="bs-label">Saldo actual</div>
        <div class="bs-value blue">{{ balanceData.balance?.toLocaleString() ?? '—' }} pts</div>
      </div>
      <div class="bs-divider"></div>
      <div class="bs-item">
        <div class="bs-label">Total ganado</div>
        <div class="bs-value green">{{ balanceData.lifetimeEarned == null ? '—' : '+' + balanceData.lifetimeEarned.toLocaleString() }} pts</div>
      </div>
      <div class="bs-divider"></div>
      <div class="bs-item">
        <div class="bs-label">Total canjeado</div>
        <div class="bs-value red">{{ balanceData.lifetimeRedeemed == null ? '—' : '-' + balanceData.lifetimeRedeemed.toLocaleString() }} pts</div>
      </div>
    </div>
    <EmptyState v-else-if="balanceError" class="card" title="Saldo no disponible" :message="balanceError" icon="⚠" role="alert" />

    <!-- Filtros -->
    <div class="filter-row">
      <button v-for="f in [{k:'all',l:'Todos'},{k:'earn',l:'Ganancias'},{k:'redeem',l:'Canjes'}]"
        :key="f.k" class="filter-btn" :class="{active: filter===f.k}" @click="applyFilter(f.k)">
        {{ f.l }}
      </button>
    </div>

    <!-- Tabla -->
    <div class="card">
      <EmptyState v-if="isLoading" title="Cargando actividad" message="Consultando tus movimientos." role="status" />
      <EmptyState v-else-if="historyError" title="Actividad no disponible" :message="historyError" icon="⚠" role="alert">
        <button class="btn btn-ghost btn-sm" @click="loadActivity">Reintentar</button>
      </EmptyState>
      <EmptyState v-else-if="!filtered.length" :title="movementId ? 'Movimiento no disponible' : 'Sin movimientos'"
        :message="movementId ? 'No se encontró este movimiento en tu actividad.' : 'No hay movimientos para el filtro seleccionado.'" icon="🔍" />
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
