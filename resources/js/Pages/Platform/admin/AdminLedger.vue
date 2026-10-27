<script setup>
import PlatformLayout from '@/Layouts/PlatformLayout.vue'
defineOptions({ layout: PlatformLayout })
import { ref, onMounted } from 'vue'
import StatusBadge from '@/Platform/components/ui/StatusBadge.vue'
import { useToast } from '@/Platform/composables/useToast.js'
import { ledgerInitial } from '@/Platform/initialState.js'
import { api } from '@/Platform/services/api.js'

const { success, error } = useToast()
const search = ref('')
const selectedType = ref('all')
const ledgerList = ref([...ledgerInitial])
const isLoading = ref(true)

async function loadLedger() {
  try {
    isLoading.value = true
    const params = {}
    if (selectedType.value !== 'all') params.type = selectedType.value
    if (search.value.trim()) params.search = search.value.trim()

    const res = await api.points.getLedger(params)
    const list = Array.isArray(res) ? res : (res.data || [])
    if (list.length > 0) {
      ledgerList.value = list.map(l => ({
        id: l._id || l.id,
        _id: l._id || l.id,
        student: l.student?.name || l.student_id || 'Estudiante',
        sid: l.student?.student_code || l.student_id?.slice(-8) || 'MAT-000',
        studentId: l.student_id,
        movement: l.type,
        points: Math.abs(l.amount || 0),
        reference: l.source_reference || l.idempotency_key?.slice(0, 10) || 'REF-TX',
        date: l.created_at ? l.created_at.slice(0, 19).replace('T', ' ') : 'Reciente',
        status: l.type === 'reverse' ? 'Revertido' : (l.type === 'redeem' ? 'Canjeado' : 'Completado'),
      }))
    }
  } catch (err) {
    console.warn('No se pudo actualizar la información de Recompensas.', err)
  } finally {
    isLoading.value = false
  }
}

async function handleReconcile(studentId) {
  if (!studentId) return
  try {
    const res = await api.points.reconcile(studentId)
    success(`Cuenta reconciliada exitosamente. Saldo auditado: ${res.balance} pts.`)
    await loadLedger()
  } catch (err) {
    console.error('Error al reconciliar:', err)
    error(err.message || 'Error al reconciliar saldo.')
  }
}

function mvtLabel(t) { return { earn: 'Ganancia', redeem: 'Canje', reverse: 'Reverso' }[t] || t }
function ptsColor(m) { return { earn: '#16a34a', redeem: '#dc2626', reverse: '#d97706' }[m] || '#2878ED' }
function ptsSign(m) { return { earn: '+', redeem: '-', reverse: '±' }[m] || '' }

onMounted(() => {
  loadLedger()
})
</script>

<template>
  <div class="page">
    <div class="phrow">
      <div class="ph">
        <h1 class="page-title">7.1 Ledger global de puntos</h1>
        <div class="page-subtitle">Panel de administración</div>
        <p class="page-desc">Libro mayor inmutable y de solo anexión (append-only) que registra cada emisión, canje y reverso de puntos en el campus.</p>
      </div>
    </div>

    <div class="filters-bar">
      <input
        v-model="search"
        class="cd-input"
        placeholder="Buscar por estudiante, matrícula o referencia…"
        style="max-width:360px"
        @keyup.enter="loadLedger"
      />
      <select v-model="selectedType" class="cd-input" style="max-width:160px" @change="loadLedger">
        <option value="all">Todos los tipos</option>
        <option value="earn">Ganancia (earn)</option>
        <option value="redeem">Canje (redeem)</option>
        <option value="reverse">Reverso (reverse)</option>
      </select>
      <button class="btn btn-secondary" @click="loadLedger">Filtrar</button>
    </div>

    <div class="card">
      <div class="table-wrapper">
        <table class="cd-table">
          <thead>
            <tr>
              <th>Estudiante</th>
              <th>Matrícula / ID</th>
              <th>Movimiento</th>
              <th>Puntos</th>
              <th>Referencia / Clave</th>
              <th>Fecha y Hora</th>
              <th>Estado</th>
              <th>Acción</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="l in ledgerList" :key="l.id">
              <td style="font-weight:600">{{ l.student }}</td>
              <td style="color:var(--cd-text-muted);font-size:12px">{{ l.sid }}</td>
              <td><span class="mvt-badge" :class="l.movement">{{ mvtLabel(l.movement) }}</span></td>
              <td><span :style="{ color: ptsColor(l.movement), fontWeight: 700 }">{{ ptsSign(l.movement) }}{{ l.points }} pts</span></td>
              <td><code style="font-size:11px;background:#f0f3f9;padding:2px 6px;border-radius:4px">{{ l.reference }}</code></td>
              <td style="color:var(--cd-text-muted);font-size:12px;white-space:nowrap">{{ l.date }}</td>
              <td><StatusBadge :status="l.status" /></td>
              <td>
                <button
                  v-if="l.studentId"
                  class="btn btn-ghost btn-sm"
                  title="Auditar y conciliar balance recalculando desde la suma de ledger"
                  @click="handleReconcile(l.studentId)"
                >
                  Conciliar
                </button>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</template>

<style scoped>
.page{display:flex;flex-direction:column;gap:20px;}
.phrow{display:flex;align-items:flex-end;justify-content:space-between;}
.ph{display:flex;flex-direction:column;gap:2px;}
.filters-bar{display:flex;gap:10px;flex-wrap:wrap;align-items:center;}
.mvt-badge{padding:2px 10px;border-radius:12px;font-size:12px;font-weight:600;}
.earn{background:#f0fdf4;color:#16a34a;}
.redeem{background:#fef2f2;color:#dc2626;}
.reverse{background:#fffbeb;color:#d97706;}
</style>
