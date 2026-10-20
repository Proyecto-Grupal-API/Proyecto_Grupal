<script setup>
import { ref, onMounted } from 'vue'
import StatusBadge from '../../components/ui/StatusBadge.vue'
import { auditMock } from '../../mocks/rewards.js'
import { api } from '../../services/api.js'

const historyLogs = ref([...auditMock])
const isLoading = ref(true)

async function loadHistory() {
  try {
    isLoading.value = true
    const res = await api.audit.getLogs()
    const list = Array.isArray(res) ? res : (res.data || [])
    if (list.length > 0) {
      historyLogs.value = list.map(a => ({
        id: a._id || a.id,
        user: a.actor_role === 'professor' ? 'Docente (Tú)' : (a.actor_role === 'student' ? 'Estudiante' : 'Admin'),
        initials: a.actor_role === 'professor' ? 'ER' : (a.actor_role === 'student' ? 'AL' : 'AD'),
        action: a.action,
        entity: a.entity_type,
        date: a.created_at ? a.created_at.slice(0, 19).replace('T', ' ') : 'Reciente',
      }))
    }
  } catch (err) {
    console.warn('Usando historial de auditoría de respaldo:', err)
  } finally {
    isLoading.value = false
  }
}

onMounted(() => {
  loadHistory()
})
</script>

<template>
  <div class="page">
    <div class="page-header">
      <h1 class="page-title">Historial y reportes</h1>
      <div class="page-subtitle">Panel del profesor</div>
      <p class="page-desc">Consulta el registro de entregas, validaciones y actividades docentes.</p>
    </div>
    <div class="card">
      <div class="table-wrapper">
        <table class="cd-table">
          <thead><tr><th>Actor</th><th>Acción</th><th>Entidad</th><th>Fecha y Hora</th></tr></thead>
          <tbody>
            <tr v-for="a in historyLogs" :key="a.id">
              <td><div style="display:flex;align-items:center;gap:8px"><div class="av" :style="{background:`#16a34a`}">{{a.initials}}</div>{{a.user}}</div></td>
              <td style="font-weight:600">{{a.action}}</td>
              <td style="color:var(--cd-text-muted);font-size:12px">{{a.entity}}</td>
              <td style="color:var(--cd-text-muted);font-size:12px;white-space:nowrap">{{a.date}}</td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</template>

<style scoped>
.page{display:flex;flex-direction:column;gap:20px;}
.av{width:28px;height:28px;border-radius:50%;color:#fff;display:flex;align-items:center;justify-content:center;font-size:11px;font-weight:600;flex-shrink:0;}
</style>
