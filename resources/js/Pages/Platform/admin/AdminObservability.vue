<script setup>
import PlatformLayout from '@/Layouts/PlatformLayout.vue'
defineOptions({ layout: PlatformLayout })
import { ref, computed, onMounted } from 'vue'
import StatusBadge from '@/Platform/components/ui/StatusBadge.vue'
import { useToast } from '@/Platform/composables/useToast.js'
import { useRole } from '@/Platform/composables/useRole.js'
import { api } from '@/Platform/services/api.js'

const { success, error } = useToast()
const { hasPermission } = useRole()
const canRetry = computed(() => hasPermission('integrations.retry'))
const apiMessage = (err, fallback) => err.response?.data?.error || err.response?.data?.message || fallback

const health = ref(null)
const logs = ref([])
const webhooks = ref([])
const isRetrying = ref(false)
const isLoading = ref(true)

const statusLabels = { processed: 'Completado', ignored: 'Sin efecto', failed: 'En falla' }
const when = v => v ? String(v).slice(0, 19).replace('T', ' ') : '—'
// Estado de cada dominio a partir de datos reales: sin credencial no puede entregar; con credencial y sin eventos aún no ha entregado.
const domainStatus = d => !d.credential ? 'Sin credencial' : (d.failed_webhooks > 0 ? 'Con fallas' : (d.events_total > 0 ? 'Recibiendo' : 'Sin eventos'))

async function load() {
  try {
    isLoading.value = true
    const [healthRes, logsRes, webhooksRes] = await Promise.allSettled([api.integrations.getHealth(), api.integrations.getLogs(), api.integrations.getWebhooks()])
    health.value = healthRes.status === 'fulfilled' ? healthRes.value : null
    const l = logsRes.status === 'fulfilled' ? logsRes.value : []
    logs.value = Array.isArray(l) ? l : (l.data || [])
    const w = webhooksRes.status === 'fulfilled' ? webhooksRes.value : []
    webhooks.value = Array.isArray(w) ? w : (w.data || [])
  } finally {
    isLoading.value = false
  }
}

async function handleRetry(wh) {
  try {
    isRetrying.value = true
    const res = await api.integrations.retryWebhook(wh._id || wh.id)
    success(`Evento reprocesado: ${res.message || 'completado'}`)
  } catch (err) {
    error(`El reintento no prosperó: ${apiMessage(err, 'intenta de nuevo más tarde.')}`)
  } finally {
    isRetrying.value = false
    await load()
  }
}

onMounted(load)
</script>

<template>
  <div class="page">
    <div class="ph">
      <h1 class="page-title">7.10 Integración y observabilidad</h1>
      <div class="page-subtitle">Panel de administración</div>
      <p class="page-desc">Qué módulos entregan eventos a Recompensas, qué llegó, qué efecto tuvo y qué quedó en falla para reintentar.</p>
    </div>

    <div v-if="health" class="summary-row">
      <div class="card sm-card ok">
        <div class="sml">Dominios con credencial</div>
        <div class="smv" data-indicador="dominios">{{ health.domains.filter(d => d.credential).length }} / {{ health.domains.length }}</div>
      </div>
      <div class="card sm-card" :class="health.ingest_enabled ? 'ok' : 'warn'">
        <div class="sml">Recepción de eventos</div>
        <div class="smv" data-indicador="recepcion">{{ health.ingest_enabled ? 'Encendida' : 'Apagada' }}</div>
      </div>
      <div class="card sm-card" :class="health.webhooks.failed ? 'err' : 'ok'">
        <div class="sml">Eventos en falla</div>
        <div class="smv" data-indicador="en-falla">{{ health.webhooks.failed }}</div>
      </div>
      <div class="card sm-card" :class="health.database === 'ok' ? 'ok' : 'err'">
        <div class="sml">Base de Recompensas</div>
        <div class="smv" data-indicador="base">{{ health.database === 'ok' ? 'Responde' : 'Sin respuesta' }}</div>
      </div>
    </div>

    <div class="card" data-seccion="salud">
      <div class="card-header"><h3 class="card-title">Estado de las integraciones por módulo</h3></div>
      <div class="table-wrapper">
        <table class="cd-table">
          <thead><tr><th>Módulo emisor</th><th>Credencial de transporte</th><th>Eventos recibidos</th><th>Último evento</th><th>En falla</th><th>Estado</th></tr></thead>
          <tbody>
            <tr v-for="d in (health?.domains || [])" :key="d.domain">
              <td style="font-weight:600">{{ d.label }}</td>
              <td>{{ d.credential ? 'Configurada' : 'Sin configurar' }}</td>
              <td>{{ d.events_total }}</td>
              <td style="color:var(--cd-text-muted);font-size:12px;white-space:nowrap">{{ when(d.last_event_at) }}</td>
              <td>{{ d.failed_webhooks }}</td>
              <td><StatusBadge :status="domainStatus(d)" /></td>
            </tr>
            <tr v-if="!health"><td colspan="6" style="text-align:center;color:var(--cd-text-muted);padding:20px">No se pudo consultar el estado de las integraciones.</td></tr>
          </tbody>
        </table>
      </div>
    </div>

    <div class="card" data-seccion="eventos">
      <div class="card-header"><h3 class="card-title">Bandeja de eventos comerciales</h3></div>
      <div class="table-wrapper">
        <table class="cd-table">
          <thead><tr><th>Evento</th><th>Origen</th><th>Tipo</th><th>Referencia</th><th>Reintentos</th><th>Estado</th><th>Resultado</th><th>Acción</th></tr></thead>
          <tbody>
            <tr v-for="w in webhooks" :key="w._id || w.id">
              <td><code style="font-size:11px">{{ String(w.event_id || w._id || w.id).slice(-8) }}</code></td>
              <td><span class="type-badge">{{ w.source }}</span></td>
              <td><strong>{{ w.event_type }}</strong></td>
              <td><code style="font-size:11px">{{ w.reference || w.payload?.aggregate_id || '—' }}</code></td>
              <td>{{ w.retry_count || 0 }}</td>
              <td><StatusBadge :status="statusLabels[w.status] || w.status" /></td>
              <td style="font-size:12px">{{ w.detail || '—' }}</td>
              <td>
                <button v-if="w.status === 'failed' && canRetry" class="btn btn-secondary btn-sm" :disabled="isRetrying" @click="handleRetry(w)">Reintentar</button>
                <span v-else style="color:var(--cd-text-muted);font-size:12px">{{ w.status === 'failed' ? 'Pendiente de reintento' : 'Sin pendientes' }}</span>
              </td>
            </tr>
            <tr v-if="!webhooks.length"><td colspan="8" style="text-align:center;color:var(--cd-text-muted);padding:20px">No hay eventos comerciales registrados.</td></tr>
          </tbody>
        </table>
      </div>
    </div>

    <div class="card" data-seccion="registro">
      <div class="card-header"><h3 class="card-title">Registro de integraciones (últimas entregas)</h3></div>
      <div class="table-wrapper">
        <table class="cd-table">
          <thead><tr><th>Fecha</th><th>Módulo</th><th>Sentido</th><th>Recurso</th><th>Evento</th><th>Estado</th></tr></thead>
          <tbody>
            <tr v-for="l in logs" :key="l._id || l.id">
              <td style="color:var(--cd-text-muted);font-size:12px;white-space:nowrap">{{ when(l.created_at) }}</td>
              <td><span class="type-badge">{{ l.domain }}</span></td>
              <td>{{ l.direction === 'inbound' ? 'Entrada' : 'Salida' }}</td>
              <td><code style="font-size:11px;background:#f0f3f9;padding:2px 6px;border-radius:4px">{{ l.endpoint }}</code></td>
              <td style="font-size:12px">{{ l.request_payload?.event_name || '—' }}</td>
              <td><StatusBadge :status="l.status === 'success' ? 'Completado' : 'En falla'" /></td>
            </tr>
            <tr v-if="!logs.length"><td colspan="6" style="text-align:center;color:var(--cd-text-muted);padding:20px">Aún no hay entregas registradas.</td></tr>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</template>

<style scoped>
.page{display:flex;flex-direction:column;gap:20px;}
.ph{display:flex;flex-direction:column;gap:2px;}
.summary-row{display:grid;grid-template-columns:repeat(auto-fit,minmax(190px,1fr));gap:16px;}
.sm-card{padding:16px;border-left:4px solid #cbd5e1;}
.sm-card.ok{border-left-color:#16a34a;}
.sm-card.warn{border-left-color:#d97706;}
.sm-card.err{border-left-color:#dc2626;}
.sml{font-size:12px;color:var(--cd-text-muted);}
.smv{font-size:20px;font-weight:800;margin-top:4px;}
.card-header{padding:14px 16px 0;}
.card-title{font-size:15px;font-weight:700;margin:0;}
.type-badge{padding:2px 8px;border-radius:999px;background:#eef2ff;color:#3730a3;font-size:11px;font-weight:600;}
</style>
