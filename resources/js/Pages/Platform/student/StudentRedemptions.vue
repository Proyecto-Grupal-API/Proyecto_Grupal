<script setup>
import PlatformLayout from '@/Layouts/PlatformLayout.vue'
defineOptions({ layout: PlatformLayout })
import { ref, computed, onMounted, watch } from 'vue'
import { Link, usePage } from '@inertiajs/vue3'
import StatusBadge from '@/Platform/components/ui/StatusBadge.vue'
import EmptyState from '@/Platform/components/ui/EmptyState.vue'
import ConfirmModal from '@/Platform/components/ui/ConfirmModal.vue'
import { useToast } from '@/Platform/composables/useToast.js'
import { api } from '@/Platform/services/api.js'

const page = usePage()
const redemptionParam = computed(() => new URL(page.url, 'http://localhost').searchParams.get('canje'))
const redemptionId = computed(() => /^[0-9a-f]{24}$/i.test(redemptionParam.value || '') ? redemptionParam.value.toLowerCase() : '')
const { success, error } = useToast()
const myRedemptions = ref([])
const isLoading = ref(true)
const loadError = ref('')
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
  const selected = redemptionParam.value
  isLoading.value = true
  myRedemptions.value = []
  loadError.value = ''
  if (selected !== null && !redemptionId.value) {
    loadError.value = 'La referencia del canje no es válida.'
    isLoading.value = false
    return
  }
  try {
    const res = redemptionId.value ? await api.redemptions.get(redemptionId.value) : await api.redemptions.list()
    if (selected !== redemptionParam.value) return
    const list = redemptionId.value ? [res] : (Array.isArray(res) ? res : res?.data)
    if (!Array.isArray(list)) throw new Error('Canjes no válidos')
    myRedemptions.value = list.map(r => {
      const id = r?._id || r?.id
      if (typeof id !== 'string' || !/^[0-9a-f]{24}$/i.test(id)
        || (redemptionId.value && id.toLowerCase() !== redemptionId.value)) throw new Error('Referencia de canje no válida')
      return {
        id,
        _id: id,
        reward: r.reward?.name || 'Recompensa Campus',
        code: r.redemption_code || '',
        points: r.points_spent ?? null,
        date: r.requested_at ? r.requested_at.slice(0, 10) : (r.created_at ? r.created_at.slice(0, 10) : '—'),
        status: r.status ? formatStatus(r.status) : 'Estado no disponible',
        rawStatus: r.status,
      }
    })
  } catch (err) {
    if (selected !== redemptionParam.value) return
    loadError.value = [403, 404].includes(err.response?.status)
      ? 'El canje solicitado no está disponible para tu cuenta.'
      : 'No se pudieron consultar tus canjes. Intenta de nuevo.'
  } finally {
    if (selected === redemptionParam.value) isLoading.value = false
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
    success(`Canje ${selectedToCancel.value.code || selectedToCancel.value.id} cancelado.`)
    showCancelModal.value = false
    selectedToCancel.value = null
    await loadRedemptions()
  } catch (err) {
    console.error('Error al cancelar canje:', err)
    error(err.message || 'No se pudo cancelar el canje.')
    showCancelModal.value = false
  }
}

watch(redemptionParam, loadRedemptions)
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
      <p v-if="redemptionId" class="page-desc">Canje seleccionado: <code>{{ redemptionId }}</code></p>
      <Link v-if="redemptionParam !== null" href="/recompensas/alumno/canjes" class="btn btn-ghost btn-sm">Ver todos</Link>
    </div>

    <div class="card">
      <EmptyState v-if="isLoading" title="Cargando canjes" message="Consultando el estado de tus canjes." role="status" />
      <EmptyState v-else-if="loadError" title="Canjes no disponibles" :message="loadError" icon="⚠" role="alert">
        <button class="btn btn-ghost btn-sm" @click="loadRedemptions">Reintentar</button>
      </EmptyState>
      <EmptyState v-else-if="!myRedemptions.length" title="Sin canjes" message="Aún no has realizado ningún canje. ¡Explora el catálogo!" icon="🎟" />
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
              <td><code style="font-size:12px;background:#f0f3f9;padding:2px 6px;border-radius:4px">{{ r.code || '—' }}</code></td>
              <td style="color:var(--cd-primary);font-weight:600">{{ r.points == null ? '—' : r.points.toLocaleString() + ' pts' }}</td>
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
      :message="`¿Deseas cancelar el canje '${selectedToCancel?.code || selectedToCancel?.id}'?`"
      confirm-label="Sí, cancelar canje"
      @confirm="confirmCancel"
      @cancel="showCancelModal = false"
    />
  </div>
</template>

<style scoped>
.page { display: flex; flex-direction: column; gap: 20px; }
</style>
