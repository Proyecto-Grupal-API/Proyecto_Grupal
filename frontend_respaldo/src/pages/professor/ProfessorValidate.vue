<script setup>
import { ref, onMounted } from 'vue'
import StatusBadge from '../../components/ui/StatusBadge.vue'
import ConfirmModal from '../../components/ui/ConfirmModal.vue'
import { useToast } from '../../composables/useToast.js'
import { redemptionsMock } from '../../mocks/rewards.js'
import { api } from '../../services/api.js'

const { success, error } = useToast()
const redemptions = ref([...redemptionsMock])
const selected = ref(null)
const showModal = ref(false)
const searchCode = ref('')
const isSearching = ref(false)
const searchResult = ref(null)
const deliveryNote = ref('Entrega presencial en cubículo')

async function loadRedemptions() {
  try {
    const res = await api.redemptions.list()
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
        status: (r.status === 'delivered') ? 'Entregado' : (r.status === 'confirmed' ? 'Validado' : 'Pendiente'),
        rawStatus: r.status,
      }))
    }
  } catch (err) {
    console.warn('Usando canjes locales en validador:', err)
  }
}

async function handleSearchCode() {
  if (!searchCode.value.trim()) return
  try {
    isSearching.value = true
    searchResult.value = null
    const res = await api.redemptions.validateCode(searchCode.value.trim())
    if (res && res.success) {
      searchResult.value = res
      success('Código verificado con éxito.')
    }
  } catch (err) {
    console.error('Error al validar código:', err)
    error(err.message || 'Código de canje no encontrado o inválido.')
    searchResult.value = null
  } finally {
    isSearching.value = false
  }
}

function openValidate(r) {
  selected.value = r
  showModal.value = true
}

async function confirmValidate() {
  if (!selected.value) return
  try {
    const targetId = selected.value._id || selected.value.id
    await api.redemptions.deliver(targetId, { note: deliveryNote.value })
    success(`Canje de ${selected.value.student || 'estudiante'} entregado exitosamente.`)
    showModal.value = false
    selected.value = null
    searchResult.value = null
    searchCode.value = ''
    await loadRedemptions()
  } catch (err) {
    console.error('Error al entregar:', err)
    error(err.message || 'Error al procesar la entrega.')
    showModal.value = false
  }
}

const colors = ['#2878ED','#16a34a','#d97706','#7c3aed']
function ac(idx) { return colors[idx % colors.length] }

onMounted(() => {
  loadRedemptions()
})
</script>

<template>
  <div class="page">
    <div class="page-header">
      <h1 class="page-title">Validar y entregar canjes</h1>
      <div class="page-subtitle">Panel del profesor</div>
      <p class="page-desc">Ingresa el código proporcionado por el estudiante para validar y registrar la entrega de su recompensa física.</p>
    </div>

    <!-- Buscador directo de código -->
    <div class="card validate-box">
      <h3 class="vb-title">🔍 Búsqueda rápida de código de canje</h3>
      <div class="vb-row">
        <input
          v-model="searchCode"
          class="cd-input"
          placeholder="Ej: CJ-ABCD-1234"
          style="max-width: 380px; text-transform: uppercase;"
          @keyup.enter="handleSearchCode"
        />
        <button class="btn btn-primary" :disabled="isSearching || !searchCode.trim()" @click="handleSearchCode">
          {{ isSearching ? 'Buscando...' : 'Buscar y Validar' }}
        </button>
      </div>

      <!-- Resultado de búsqueda -->
      <div v-if="searchResult" class="result-box card">
        <div class="rb-header">
          <div>
            <div class="rb-code">{{ searchResult.redemption.redemption_code }}</div>
            <div class="rb-status">Estado: <strong>{{ searchResult.redemption.status }}</strong></div>
          </div>
          <button
            v-if="searchResult.can_deliver"
            class="btn btn-primary"
            @click="openValidate({
              _id: searchResult.redemption._id,
              student: searchResult.student?.name || 'Estudiante',
              reward: searchResult.redemption.reward?.name || 'Recompensa',
              points: searchResult.redemption.points_spent,
            })"
          >
            ✓ Entregar recompensa
          </button>
          <span v-else class="status-warning">Este canje ya fue entregado o cancelado.</span>
        </div>

        <div class="rb-grid">
          <div>
            <span class="lbl">Estudiante:</span>
            <strong>{{ searchResult.student?.name || 'N/A' }}</strong> ({{ searchResult.student?.email || 'N/A' }})
          </div>
          <div>
            <span class="lbl">Recompensa:</span>
            <strong>{{ searchResult.redemption.reward?.name || 'N/A' }}</strong>
          </div>
          <div>
            <span class="lbl">Puntos deducidos:</span>
            <strong>{{ searchResult.redemption.points_spent }} pts</strong>
          </div>
        </div>
      </div>
    </div>

    <!-- Tabla general -->
    <div class="card">
      <div class="card-header">
        <h3 class="card-title">Historial de canjes recientes</h3>
      </div>
      <div class="table-wrapper">
        <table class="cd-table">
          <thead>
            <tr>
              <th>Alumno</th>
              <th>Recompensa</th>
              <th>Código</th>
              <th>Puntos</th>
              <th>Fecha</th>
              <th>Estado</th>
              <th>Acción</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="(r,i) in redemptions" :key="r.id">
              <td>
                <div class="sc">
                  <div class="av" :style="{background:ac(i)}">{{r.initials}}</div>
                  {{r.student}}
                </div>
              </td>
              <td>{{r.reward}}</td>
              <td><code class="ct">{{r.code}}</code></td>
              <td style="color:var(--cd-primary);font-weight:600">{{r.points.toLocaleString()}} pts</td>
              <td style="color:var(--cd-text-muted);font-size:12px">{{r.date}}</td>
              <td><StatusBadge :status="r.status" /></td>
              <td>
                <button
                  class="btn btn-primary btn-sm"
                  :disabled="r.rawStatus==='delivered'||r.rawStatus==='cancelled'||r.status==='Entregado'||r.status==='Cancelado'"
                  @click="openValidate(r)"
                >
                  {{ (r.rawStatus === 'delivered' || r.status === 'Entregado') ? 'Entregado' : 'Validar' }}
                </button>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>

    <ConfirmModal
      :show="showModal"
      title="Confirmar entrega de recompensa"
      :message="`¿Confirmas la entrega de '${selected?.reward}' al estudiante ${selected?.student}?`"
      confirm-label="Confirmar y entregar"
      @confirm="confirmValidate"
      @cancel="showModal=false"
    />
  </div>
</template>

<style scoped>
.page { display:flex; flex-direction:column; gap:20px; }
.validate-box { padding: 20px; display: flex; flex-direction: column; gap: 14px; }
.vb-title { font-size: 15px; font-weight: 700; color: var(--cd-text-primary); margin: 0; }
.vb-row { display: flex; gap: 10px; align-items: center; }
.result-box { padding: 16px 20px; background: #f8fafc; border: 1px solid var(--cd-border); display: flex; flex-direction: column; gap: 12px; }
.rb-header { display: flex; justify-content: space-between; align-items: center; }
.rb-code { font-size: 18px; font-weight: 800; color: var(--cd-primary); }
.rb-status { font-size: 13px; color: var(--cd-text-secondary); }
.status-warning { color: #d97706; font-size: 13px; font-weight: 600; }
.rb-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 12px; font-size: 13px; }
.lbl { color: var(--cd-text-muted); margin-right: 4px; }
.sc { display:flex; align-items:center; gap:8px; }
.av { width:28px; height:28px; border-radius:50%; color:#fff; display:flex; align-items:center; justify-content:center; font-size:11px; font-weight:600; flex-shrink:0; }
.ct { font-size:11px; background:#f0f3f9; padding:2px 6px; border-radius:4px; font-weight: 600; }
</style>
