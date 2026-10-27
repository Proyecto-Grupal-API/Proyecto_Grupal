<script setup>
import PlatformLayout from '@/Layouts/PlatformLayout.vue'
defineOptions({ layout: PlatformLayout })
import { ref, onMounted } from 'vue'
import StatusBadge from '@/Platform/components/ui/StatusBadge.vue'
import { useToast } from '@/Platform/composables/useToast.js'
import { integrationInitial } from '@/Platform/initialState.js'
import { api } from '@/Platform/services/api.js'

const { success, error } = useToast()
const services = ref([...integrationInitial])
const webhooks = ref([])
const isRetrying = ref(false)
const isLoading = ref(true)

async function loadIntegrations() {
  try {
    isLoading.value = true
    const [logsRes, webhooksRes] = await Promise.allSettled([
      api.integrations.getLogs(),
      api.integrations.getWebhooks(),
    ])

    if (webhooksRes.status === 'fulfilled' && webhooksRes.value) {
      const list = Array.isArray(webhooksRes.value) ? webhooksRes.value : (webhooksRes.value.data || [])
      webhooks.value = list
    }
  } catch (err) {
    console.warn('No se pudo actualizar la información de Recompensas.', err)
  } finally {
    isLoading.value = false
  }
}

async function handleRetry(wh) {
  try {
    isRetrying.value = true
    await api.integrations.retryWebhook(wh._id || wh.id)
    success(`Webhook "${wh.event || 'reintento'}" reenviado exitosamente a la cola de procesamiento.`)
    await loadIntegrations()
  } catch (err) {
    console.error('Error al reintentar webhook:', err)
    error(err.message || 'Error al reintentar envío de webhook.')
  } finally {
    isRetrying.value = false
  }
}

onMounted(() => {
  loadIntegrations()
})
</script>

<template>
  <div class="page">
    <div class="ph">
      <h1 class="page-title">7.10 Integración y observabilidad</h1>
      <div class="page-subtitle">Panel de administración</div>
      <p class="page-desc">Monitoreo de endpoints REST, eventos del Equipo 3 (Marketplace / Ventas), colas de webhooks y salud de infraestructura.</p>
    </div>

    <div class="summary-row">
      <div class="card sm-card ok">
        <div class="sml">Servicios Operativos</div>
        <div class="smv">{{ services.filter(i => i.status === 'OK').length }}</div>
      </div>
      <div class="card sm-card warn">
        <div class="sml">En Observación</div>
        <div class="smv">{{ services.filter(i => i.status === 'Advertencia').length }}</div>
      </div>
      <div class="card sm-card err">
        <div class="sml">Webhooks Pendientes</div>
        <div class="smv">{{ webhooks.filter(w => w.status === 'failed' || w.status === 'pending').length }}</div>
      </div>
    </div>

    <div class="card">
      <div class="card-header">
        <h3 class="card-title">Contratos de API y Estado de Servicios</h3>
      </div>
      <div class="table-wrapper">
        <table class="cd-table">
          <thead>
            <tr>
              <th>Servicio / Contrato</th>
              <th>Tipo</th>
              <th>Endpoint</th>
              <th>Estado</th>
              <th>Última Revisión</th>
              <th>Disponibilidad</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="i in services" :key="i.id">
              <td style="font-weight:600">{{ i.name }}</td>
              <td><span class="type-badge">{{ i.type }}</span></td>
              <td><code style="font-size:11px;background:#f0f3f9;padding:2px 6px;border-radius:4px">{{ i.endpoint }}</code></td>
              <td><StatusBadge :status="i.status" /></td>
              <td style="color:var(--cd-text-muted);font-size:12px;white-space:nowrap">{{ i.lastCheck }}</td>
              <td><span :style="{ color: parseFloat(i.uptime) > 95 ? '#16a34a' : (parseFloat(i.uptime) > 90 ? '#d97706' : '#dc2626'), fontWeight: 700 }">{{ i.uptime }}</span></td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>

    <!-- Webhooks / Eventos Externos -->
    <div class="card">
      <div class="card-header">
        <h3 class="card-title">Bandeja de Eventos y Webhooks Asíncronos</h3>
      </div>
      <div class="table-wrapper">
        <table class="cd-table">
          <thead>
            <tr>
              <th>ID Evento</th>
              <th>Origen</th>
              <th>Evento / Clave</th>
              <th>Intentos</th>
              <th>Estado</th>
              <th>Acción</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="w in webhooks" :key="w._id || w.id">
              <td><code style="font-size:11px">{{ (w._id || w.id).slice(-8) }}</code></td>
              <td><span class="type-badge">{{ w.source_domain || 'team-3' }}</span></td>
              <td><strong>{{ w.event_type || 'purchase.completed' }}</strong></td>
              <td>{{ w.attempts || 1 }} / 5</td>
              <td><StatusBadge :status="w.status === 'success' ? 'Completado' : (w.status === 'failed' ? 'Cancelado' : 'Pendiente')" /></td>
              <td>
                <button
                  v-if="w.status === 'failed' || w.status === 'pending'"
                  class="btn btn-secondary btn-sm"
                  :disabled="isRetrying"
                  @click="handleRetry(w)"
                >
                  Reintentar
                </button>
                <span v-else style="color:var(--cd-text-muted);font-size:12px">Sincronizado</span>
              </td>
            </tr>
            <tr v-if="!webhooks.length">
              <td colspan="6" style="text-align:center;color:var(--cd-text-muted);padding:20px">
                No hay webhooks registrados. Esto no acredita la conexión operativa con otros módulos.
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
.ph{display:flex;flex-direction:column;gap:2px;}
.summary-row{display:grid;grid-template-columns:repeat(3,1fr);gap:16px;}
.sm-card{padding:16px 20px;text-align:center;border-top:3px solid;}
.ok{border-color:#16a34a;} .warn{border-color:#d97706;} .err{border-color:#dc2626;}
.sml{font-size:12px;color:var(--cd-text-secondary);margin-bottom:4px;}
.smv{font-size:24px;font-weight:800;color:var(--cd-text-primary);}
.type-badge{padding:2px 8px;background:#f0f3f9;border-radius:12px;font-size:11px;color:var(--cd-text-secondary);font-weight:600;}
</style>
