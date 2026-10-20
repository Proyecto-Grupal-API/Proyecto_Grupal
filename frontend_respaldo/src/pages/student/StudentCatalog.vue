<script setup>
import { ref, onMounted } from 'vue'
import ConfirmModal from '../../components/ui/ConfirmModal.vue'
import { useToast } from '../../composables/useToast.js'
import { rewardsMock } from '../../mocks/rewards.js'
import { api } from '../../services/api.js'

const { success, error } = useToast()
const selected = ref(null)
const showModal = ref(false)
const isSubmitting = ref(false)
const balance = ref(1250)
const rewards = ref([...rewardsMock])
const isLoading = ref(true)

function getRewardImage(reward) {
  if (reward.image) return reward.image
  const name = (reward.name || '').toLowerCase()
  if (name.includes('termo') || name.includes('taza') || name.includes('botella')) return '☕'
  if (name.includes('libro') || name.includes('cuaderno') || name.includes('agenda')) return '📚'
  if (name.includes('sudadera') || name.includes('playera') || name.includes('gorra')) return '👕'
  if (name.includes('descuento') || name.includes('cupon') || name.includes('cafeteria')) return '🥪'
  if (name.includes('laptop') || name.includes('mochila') || name.includes('usb')) return '🎒'
  return '🎁'
}

async function loadData() {
  try {
    isLoading.value = true
    const [balanceRes, rewardsRes] = await Promise.allSettled([
      api.points.getMyBalance(),
      api.rewards.list(),
    ])

    if (balanceRes.status === 'fulfilled' && balanceRes.value) {
      balance.value = balanceRes.value.balance ?? balance.value
    }

    if (rewardsRes.status === 'fulfilled' && rewardsRes.value) {
      const list = Array.isArray(rewardsRes.value) ? rewardsRes.value : (rewardsRes.value.data || [])
      if (list.length > 0) {
        rewards.value = list.map(r => ({
          id: r._id || r.id,
          _id: r._id || r.id,
          name: r.name,
          business: r.business_name || (r.business_id ? 'Comercio Asociado' : 'Campus Store'),
          points: r.points_required ?? r.points,
          stock: r.stock !== null ? (r.stock - (r.reserved_stock || 0)) : 99,
          validUntil: r.valid_until ? r.valid_until.slice(0, 10) : 'Sin expiración',
          status: (r.status === 'active' || r.status === 'Activo') ? 'Activo' : 'Agotado',
          image: getRewardImage(r),
        }))
      }
    }
  } catch (err) {
    console.warn('Usando catálogo local:', err)
  } finally {
    isLoading.value = false
  }
}

function openRedeem(r) {
  selected.value = r
  showModal.value = true
}

async function confirmRedeem() {
  if (!selected.value) return

  try {
    isSubmitting.value = true
    const payload = {
      reward_id: selected.value._id || selected.value.id,
    }

    const res = await api.redemptions.create(payload)
    showModal.value = false

    const code = res?.redemption?.redemption_code || 'CJ-EXITOSO'
    success(`¡Canje de "${selected.value.name}" exitoso! Código: ${code}`)

    if (res?.account?.balance !== undefined) {
      balance.value = res.account.balance
    } else {
      balance.value = Math.max(0, balance.value - selected.value.points)
    }

    // Actualizar stock localmente
    selected.value.stock = Math.max(0, selected.value.stock - 1)
    selected.value = null

    // Recargar catálogo en background
    loadData()
  } catch (err) {
    console.error('Error al canjear recompensa:', err)
    error(err.message || 'No se pudo procesar el canje.')
    showModal.value = false
  } finally {
    isSubmitting.value = false
  }
}

onMounted(() => {
  loadData()
})
</script>

<template>
  <div class="page">
    <div class="page-header">
      <h1 class="page-title">Catálogo de recompensas</h1>
      <div class="page-subtitle">Panel del alumno</div>
      <p class="page-desc">Explora y canjea recompensas disponibles con tus puntos.</p>
    </div>

    <div class="balance-banner card">
      <span class="bb-label">Tu saldo disponible</span>
      <span class="bb-value">⭐ {{ balance.toLocaleString() }} pts</span>
    </div>

    <div class="catalog-grid">
      <div v-for="r in rewards.filter(r => r.status === 'Activo' && r.stock > 0)" :key="r.id" class="reward-card card">
        <div class="rc-image">{{ r.image }}</div>
        <div class="rc-info">
          <div class="rc-name">{{ r.name }}</div>
          <div class="rc-business">{{ r.business }}</div>
          <div class="rc-points">{{ r.points.toLocaleString() }} puntos</div>
          <div class="rc-meta">
            <span>📦 Stock: {{ r.stock }}</span>
            <span>📅 {{ r.validUntil }}</span>
          </div>
        </div>
        <button
          class="btn btn-primary btn-sm"
          :disabled="r.points > balance || isSubmitting"
          @click="openRedeem(r)"
        >
          {{ r.points > balance ? 'Puntos insuficientes' : 'Canjear' }}
        </button>
      </div>
    </div>

    <ConfirmModal
      :show="showModal"
      title="Confirmar canje"
      :message="`¿Deseas canjear '${selected?.name}' por ${selected?.points?.toLocaleString()} puntos?`"
      confirm-label="Sí, canjear"
      :disabled="isSubmitting"
      @confirm="confirmRedeem"
      @cancel="showModal = false"
    />
  </div>
</template>

<style scoped>
.page { display: flex; flex-direction: column; gap: 20px; }
.balance-banner {
  display: flex; align-items: center; justify-content: space-between;
  padding: 14px 20px; background: var(--cd-primary-light);
  border-color: #c3ddf9;
}
.bb-label { font-size: 13px; color: var(--cd-text-secondary); font-weight: 500; }
.bb-value { font-size: 18px; font-weight: 700; color: var(--cd-primary); }

.catalog-grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
  gap: 16px;
}
.reward-card {
  display: flex; flex-direction: column; align-items: center;
  padding: 20px 16px; text-align: center; gap: 8px;
}
.rc-image { font-size: 42px; }
.rc-name { font-size: 14px; font-weight: 700; color: var(--cd-text-primary); }
.rc-business { font-size: 12px; color: var(--cd-text-muted); }
.rc-points { font-size: 15px; font-weight: 700; color: var(--cd-primary); }
.rc-meta { display: flex; flex-direction: column; gap: 2px; }
.rc-meta span { font-size: 11px; color: var(--cd-text-muted); }
.rc-info { display: flex; flex-direction: column; gap: 4px; align-items: center; width: 100%; }
</style>
