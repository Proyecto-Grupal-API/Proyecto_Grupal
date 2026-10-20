<script setup>
import { ref, onMounted } from 'vue'
import EmptyState from '../../components/ui/EmptyState.vue'
import { auditMock } from '../../mocks/rewards.js'
import { api } from '../../services/api.js'

const search = ref('')
const auditList = ref([...auditMock])
const isLoading = ref(true)

async function loadAudit() {
  try {
    isLoading.value = true
    const params = {}
    if (search.value.trim()) params.search = search.value.trim()

    const res = await api.audit.getLogs(params)
    const list = Array.isArray(res) ? res : (res.data || [])
    if (list.length > 0) {
      auditList.value = list.map(a => {
        const role = a.actor_role || 'system'
        const initials = role === 'admin' ? 'AD' : (role === 'student' ? 'AL' : (role === 'professor' ? 'PR' : 'SY'))
        return {
          id: a._id || a.id,
          user: a.actor_id ? `${a.actor_role} (${a.actor_id.slice(-6)})` : (a.actor_role || 'Sistema'),
          initials,
          action: a.action,
          entity: a.entity_type || 'General',
          date: a.created_at ? a.created_at.slice(0, 19).replace('T', ' ') : 'Reciente',
          ip: a.ip_address || '127.0.0.1',
          correlation: a.correlation_id ? a.correlation_id.slice(0, 8) : (a._id ? a._id.slice(0, 8) : 'CORR-01'),
        }
      })
    }
  } catch (err) {
    console.warn('Usando auditoría local:', err)
  } finally {
    isLoading.value = false
  }
}

const colors = { 'LM': '#16a34a', 'JR': '#2878ED', 'SC': '#d97706', 'AR': '#7c3aed', 'AD': '#7c3aed', 'AL': '#2878ED', 'PR': '#16a34a', 'SY': '#64748b' }
function ac(initials) { return colors[initials] || '#2878ED' }

onMounted(() => {
  loadAudit()
})
</script>

<template>
  <div class="page">
    <div class="ph">
      <h1 class="page-title">7.8 Auditoría transversal</h1>
      <div class="page-subtitle">Panel de administración</div>
      <p class="page-desc">Trazabilidad inmutable de todas las operaciones, logins, canjes, emisiones y reversos del sistema.</p>
    </div>

    <div class="search-bar">
      <input
        v-model="search"
        class="cd-input"
        placeholder="Buscar por usuario, acción, entidad o IP…"
        style="max-width:400px"
        @keyup.enter="loadAudit"
      />
      <button class="btn btn-secondary" @click="loadAudit">Buscar</button>
    </div>

    <div class="card">
      <EmptyState v-if="!isLoading && !auditList.length" title="Sin resultados" message="No se encontraron registros de auditoría." icon="🔍" />
      <div v-else class="table-wrapper">
        <table class="cd-table">
          <thead>
            <tr>
              <th>Usuario / Actor</th>
              <th>Acción Registrada</th>
              <th>Entidad Afectada</th>
              <th>Fecha y Hora</th>
              <th>Dirección IP</th>
              <th>ID Correlación</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="a in auditList" :key="a.id">
              <td>
                <div style="display:flex;align-items:center;gap:8px">
                  <div class="av" :style="{ background: ac(a.initials) }">{{ a.initials }}</div>
                  <span style="font-weight:600">{{ a.user }}</span>
                </div>
              </td>
              <td><code style="font-size:12px;color:var(--cd-primary);font-weight:600">{{ a.action }}</code></td>
              <td style="color:var(--cd-text-secondary);font-size:12px">{{ a.entity }}</td>
              <td style="color:var(--cd-text-muted);font-size:12px;white-space:nowrap">{{ a.date }}</td>
              <td><code style="font-size:11px;background:#f0f3f9;padding:2px 6px;border-radius:4px">{{ a.ip }}</code></td>
              <td><code style="font-size:11px;background:#eef2ff;color:#4f46e5;padding:2px 6px;border-radius:4px;font-weight:700">{{ a.correlation }}</code></td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</template>

<style scoped>
.page{display:flex;flex-direction:column;gap:20px;}
.ph{display:flex;flex-direction:column;gap:2px;}
.search-bar{display:flex;gap:10px;align-items:center;}
.av{width:28px;height:28px;border-radius:50%;color:#fff;display:flex;align-items:center;justify-content:center;font-size:11px;font-weight:600;flex-shrink:0;}
</style>
