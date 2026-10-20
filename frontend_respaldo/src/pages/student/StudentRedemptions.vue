<script setup>
import { ref, onMounted } from 'vue'
import StatusBadge from '../../components/ui/StatusBadge.vue'
import EmptyState from '../../components/ui/EmptyState.vue'
import ConfirmModal from '../../components/ui/ConfirmModal.vue'
import { useToast } from '../../composables/useToast.js'
import { redemptionsMock } from '../../mocks/rewards.js'
import { api } from '../../services/api.js'

const { success, error } = useToast()
const myRedemptions = ref([...redemptionsMock.slice(0, 4)])
const isLoading = ref(true)
const selectedToCancel = ref(null)
const showCancelModal = ref(false)

function formatStatus(status) {
  const map = {
    confirmed: 'Validado',
    reserved: 'Pendiente',
    delivered: 'Entregado',
    cancelled: 'Cancelado',
    reversed: 'Revertido',
  }
  return map[status] || status
}

async function loadRedemptions() {
  try {
    isLoading.value = true
    const res = await api.redemptions.list()
    const list = Array.isArray(res) ? res : (res.data || [])
    if (list.length > 0) {
      myRedemptions.value = list.map(r => ({
        id: r._id || r.id,
        _id: r._id || r.id,
        reward: r.reward?.name || 'Recompensa Campus',
        code: r.redemption_code || 'CJ-0000',
        points: r.points_spent || 0,
        date: r.requested_at ? r.requested_at.slice(0, 10) : (r.created_at ? r.created_at.slice(0, 10) : 'Hoy'),
        status: formatStatus(r.status),
        rawStatus: r.status,
      }))
    }
  } catch (err) {
    console.warn('Usando canjes locales de respaldo:', err)
  } finally {
    isLoading.value = false
  }
}

function openCancel(r) {
  selectedToCancel.value = r
  showCancelModal.value = true
}

async function confirmCancel() {
  if (!selectedToCancel.value) return

  try {
    await api.redemptions.cancel(selectedToCancel.value._id || selectedToCancel.value.id, 'Cancelado por el estudiante')
    success(`Canje ${selectedToCancel.value.code} cancelado. Los puntos se reembolsaron a tu cuenta.`)
    showCancelModal.value = false
    selectedToCancel.value = null
    await loadRedemptions()
  } catch (err) {
    console.error('Error al cancelar canje:', err)
    error(err.message || 'No se pudo cancelar el canje.')
    showCancelModal.value = false
  }
}

onMounted(() => {
  loadRedemptions()
})
</script>

<template>
  <div class="page">
    <div class="page-header">
      <h1 class="page-title">Mis canjes</h1>
      <div class="page-subtitle">Panel del alumno</div>
      <p class="page-desc">Consulta el estado de tus solicitudes de canje y administra tus beneficios.</p>
    </div>

    <div class="card">
      <EmptyState v-if="!isLoading && !myRedemptions.length" title="Sin canjes" message="Aún no has realizado ningún canje. ¡Explora el catálogo!" icon="🎟" />
      <div v-else class="table-wrapper">
        <table class="cd-table">
          <thead>
            <tr>
              <th>Recompensa</th>
              <th>Código</th>
              <th>Puntos</th>
              <th>Fecha</th>
              <th>Estado</th>
              <th>Acción</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="r in myRedemptions" :key="r.id">
              <td style="font-weight:600">{{ r.reward }}</td>
              <td><code style="font-size:12px;background:#f0f3f9;padding:2px 6px;border-radius:4px">{{ r.code }}</code></td>
              <td style="color:var(--cd-primary);font-weight:600">{{ r.points.toLocaleString() }} pts</td>
              <td style="color:var(--cd-text-muted);font-size:12px">{{ r.date }}</td>
              <td><StatusBadge :status="r.status" /></td>
              <td>
                <button
                  v-if="r.rawStatus === 'confirmed' || r.rawStatus === 'reserved'"
                  class="btn btn-ghost btn-sm"
                  style="color:#dc2626"
                  @click="openCancel(r)"
                >
                  Cancelar
                </button>
                <span v-else style="color:var(--cd-text-muted);font-size:12px">—</span>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>

    <ConfirmModal
      :show="showCancelModal"
      :danger="true"
      title="Cancelar canje"
      :message="`¿Deseas cancelar el canje '${selectedToCancel?.code}'? Se reembolsarán ${selectedToCancel?.points} puntos a tu cuenta.`"
      confirm-label="Sí, cancelar canje"
      @confirm="confirmCancel"
      @cancel="showCancelModal = false"
    />
  </div>
</template>

<style scoped>
.page { display: flex; flex-direction: column; gap: 20px; }
</style>
