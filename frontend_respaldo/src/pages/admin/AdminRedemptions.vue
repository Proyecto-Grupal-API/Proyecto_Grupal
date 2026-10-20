<script setup>
import { ref, onMounted } from 'vue'
import StatusBadge from '../../components/ui/StatusBadge.vue'
import ConfirmModal from '../../components/ui/ConfirmModal.vue'
import { useToast } from '../../composables/useToast.js'
import { redemptionsMock } from '../../mocks/rewards.js'
import { api } from '../../services/api.js'

const { success, error } = useToast()
const redemptions = ref([...redemptionsMock])
const statusFilter = ref('all')
const searchCode = ref('')
const isLoading = ref(true)

const selectedAction = ref(null) // { item, type: 'deliver'|'cancel' }
const showModal = ref(false)

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
    const params = {}
    if (statusFilter.value !== 'all') params.status = statusFilter.value
    if (searchCode.value.trim()) params.code = searchCode.value.trim()

    const res = await api.redemptions.list(params)
    const list = Array.isArray(res) ? res : (res.data || [])
    if (list.length > 0) {
      redemptions.value = list.map(r => ({
        id: r._id || r.id,
        _id: r._id || r.id,
        student: r.student?.name || 'Estudiante',
        initials: r.student?.name ? r.student.name.split(' ').map(n=>n[0]).join('').slice(0,2) : 'AL',
        reward: r.reward?.name || 'Recompensa',
        code: r.redemption_code,
        points: r.points_spent,
        date: r.requested_at ? r.requested_at.slice(0, 10) : 'Hoy',
        status: formatStatus(r.status),
        rawStatus: r.status,
      }))
    }
  } catch (err) {
    console.warn('Usando canjes locales en admin:', err)
  } finally {
    isLoading.value = false
  }
}

function openAction(item, type) {
  selectedAction.value = { item, type }
  showModal.value = true
}

async function confirmAction() {
  if (!selectedAction.value) return
  const { item, type } = selectedAction.value

  try {
    if (type === 'deliver') {
      await api.redemptions.deliver(item._id || item.id, { note: 'Entregado por el Administrador' })
      success(`Canje ${item.code} marcado como entregado.`)
    } else {
      await api.redemptions.cancel(item._id || item.id, 'Cancelado por el Administrador')
      success(`Canje ${item.code} cancelado y puntos reembolsados.`)
    }
    showModal.value = false
    selectedAction.value = null
    await loadRedemptions()
  } catch (err) {
    console.error(`Error al ${type} canje:`, err)
    error(err.message || `No se pudo procesar la acción.`)
    showModal.value = false
  }
}

onMounted(() => {
  loadRedemptions()
})
</script>

<template>
  <div class="page">
    <div class="phrow">
      <div class="ph">
        <h1 class="page-title">7.5 Canje y entrega</h1>
        <div class="page-subtitle">Panel de administración</div>
        <p class="page-desc">Supervisa la redención de recompensas, valida entregas y gestiona cancelaciones de extremo a extremo.</p>
      </div>
    </div>

    <div class="filters-bar">
      <input
        v-model="searchCode"
        class="cd-input"
        placeholder="Buscar por código (ej: CJ-ABCD-1234)…"
        style="max-width:320px; text-transform:uppercase"
        @keyup.enter="loadRedemptions"
      />
      <select v-model="statusFilter" class="cd-input" style="max-width:180px" @change="loadRedemptions">
        <option value="all">Todos los estados</option>
        <option value="confirmed">Confirmados / Validados</option>
        <option value="delivered">Entregados</option>
        <option value="cancelled">Cancelados</option>
      </select>
      <button class="btn btn-secondary" @click="loadRedemptions">Filtrar</button>
    </div>

    <div class="card">
      <div class="table-wrapper">
        <table class="cd-table">
          <thead>
            <tr>
              <th>Usuario</th>
              <th>Recompensa</th>
              <th>Código de Canje</th>
              <th>Puntos</th>
              <th>Fecha Solicitud</th>
              <th>Estado</th>
              <th>Acciones</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="r in redemptions" :key="r.id">
              <td>
                <div style="display:flex;align-items:center;gap:8px">
                  <div class="av" style="background:#2878ED">{{ r.initials }}</div>
                  <span style="font-weight:600">{{ r.student }}</span>
                </div>
              </td>
              <td>{{ r.reward }}</td>
              <td><code style="font-size:11px;background:#f0f3f9;padding:2px 6px;border-radius:4px;font-weight:700">{{ r.code }}</code></td>
              <td style="color:var(--cd-primary);font-weight:700">{{ r.points.toLocaleString() }} pts</td>
              <td style="color:var(--cd-text-muted);font-size:12px">{{ r.date }}</td>
              <td><StatusBadge :status="r.status" /></td>
              <td>
                <div style="display:flex;gap:6px">
                  <button
                    v-if="r.rawStatus !== 'delivered' && r.rawStatus !== 'cancelled' && r.status !== 'Entregado' && r.status !== 'Cancelado'"
                    class="btn btn-primary btn-sm"
                    @click="openAction(r, 'deliver')"
                  >
                    Entregar
                  </button>
                  <button
                    v-if="r.rawStatus !== 'delivered' && r.rawStatus !== 'cancelled' && r.status !== 'Entregado' && r.status !== 'Cancelado'"
                    class="btn btn-ghost btn-sm"
                    style="color:#dc2626"
                    @click="openAction(r, 'cancel')"
                  >
                    Cancelar
                  </button>
                  <span v-else style="color:var(--cd-text-muted);font-size:12px">—</span>
                </div>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>

    <ConfirmModal
      :show="showModal"
      :danger="selectedAction?.type === 'cancel'"
      :title="selectedAction?.type === 'deliver' ? 'Confirmar entrega' : 'Cancelar canje'"
      :message="selectedAction?.type === 'deliver'
        ? `¿Confirmas que la recompensa '${selectedAction?.item?.reward}' fue entregada al estudiante?`
        : `¿Deseas cancelar el canje '${selectedAction?.item?.code}'? Se revertirán ${selectedAction?.item?.points} puntos al estudiante.`"
      :confirm-label="selectedAction?.type === 'deliver' ? 'Marcar Entregado' : 'Sí, Cancelar Canje'"
      @confirm="confirmAction"
      @cancel="showModal = false"
    />
  </div>
</template>

<style scoped>
.page{display:flex;flex-direction:column;gap:20px;}
.phrow{display:flex;align-items:flex-end;justify-content:space-between;}
.ph{display:flex;flex-direction:column;gap:2px;}
.filters-bar{display:flex;gap:10px;align-items:center;flex-wrap:wrap;}
.av{width:28px;height:28px;border-radius:50%;color:#fff;display:flex;align-items:center;justify-content:center;font-size:11px;font-weight:600;flex-shrink:0;}
</style>
