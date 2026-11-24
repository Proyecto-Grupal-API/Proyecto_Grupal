<script setup>
import PlatformLayout from '@/Layouts/PlatformLayout.vue'
defineOptions({ layout: PlatformLayout })
import { ref, computed, onMounted } from 'vue'
import EmptyState from '@/Platform/components/ui/EmptyState.vue'
import { auditInitial } from '@/Platform/initialState.js'
import { api } from '@/Platform/services/api.js'

const search = ref('')
const auditList = ref([...auditInitial])
const isLoading = ref(true)
const total = ref(0)
const exportUrl = computed(() => '/api/v1/recompensas/audit/logs/export' + (search.value.trim() ? `?search=${encodeURIComponent(search.value.trim())}` : ''))

async function loadAudit() {
  try {
    isLoading.value = true
    const params = {}
    if (search.value.trim()) params.search = search.value.trim()

    const res = await api.audit.getLogs(params)
    const list = Array.isArray(res) ? res : (res.data || [])
    total.value = Array.isArray(res) ? list.length : (res.total ?? list.length)
    // Sin coincidencias la tabla debe vaciarse (antes conservaba los registros de la búsqueda anterior).
    {
      auditList.value = list.map(a => {
        const role = a.actor_role || 'not_recorded'
        const initials = role === 'admin' ? 'AD' : (role === 'student' ? 'AL' : (role === 'professor' ? 'PR' : 'SY'))
        return {
          id: a._id || a.id,
          user: a.actor_id ? `${role === 'not_recorded' ? 'Cargo no registrado' : role} (${a.actor_id.slice(-6)})` : (a.actor_role || 'Sistema'),
          initials,
          action: a.action,
          entity: a.entity_type || 'General',
          date: (a.occurred_at || a.created_at || '').slice(0,19).replace('T',' '),
          ip: a.ip_address || '—',
          correlation: a.correlation_id || '—',
        }
      })
    }
  } catch (err) {
    console.warn('No se pudo actualizar la información de Recompensas.', err)
  } finally {
    isLoading.value = false
  }
}

// Detalle de un registro (7.8-b, 7.8-d) y traza de su operación (7.10-f). El servidor ya los ofrecía; la pantalla sólo listaba.
const detail = ref(null)
const trace = ref(null)
const detailLoading = ref(false)
const detailError = ref('')
const traceNote = ref('')

const moment = value => String(value || '').slice(0, 19).replace('T', ' ')
function shown(value) {
  if (value === null || value === undefined || value === '') return '—'
  return typeof value === 'object' ? JSON.stringify(value) : String(value)
}
// Una fila por campo presente antes o después; primero los que cambiaron.
const changes = computed(() => {
  const before = detail.value?.before && typeof detail.value.before === 'object' ? detail.value.before : {}
  const after = detail.value?.after && typeof detail.value.after === 'object' ? detail.value.after : {}
  return [...new Set([...Object.keys(before), ...Object.keys(after)])]
    .map(field => ({ field, before: shown(before[field]), after: shown(after[field]), changed: shown(before[field]) !== shown(after[field]) }))
    .sort((a, b) => Number(b.changed) - Number(a.changed) || a.field.localeCompare(b.field))
})

async function openDetail(row) {
  detailLoading.value = true
  detailError.value = ''
  traceNote.value = ''
  detail.value = null
  trace.value = null
  try {
    const log = await api.audit.getLog(row.id)
    detail.value = log
    if (log.correlation_id) {
      try {
        trace.value = await api.audit.getCorrelation(log.correlation_id)
      } catch (err) {
        traceNote.value = err.response?.status === 404
          ? 'Esta correlación no tiene registro de traza.'
          : 'No se pudo consultar la traza de esta operación.'
      }
    } else {
      traceNote.value = 'Este registro no lleva identificador de correlación.'
    }
  } catch (err) {
    detailError.value = err.response?.status === 403
      ? 'No tienes permiso para ver el detalle de este registro.'
      : 'No se pudo consultar el detalle del registro.'
  } finally {
    detailLoading.value = false
  }
}
function closeDetail() {
  detail.value = null
  trace.value = null
  detailError.value = ''
  traceNote.value = ''
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
      <a :href="exportUrl" class="btn btn-secondary" download>Exportar CSV</a>
      <span style="font-size:12px;color:var(--cd-text-muted)" data-total-auditoria>{{ total }} registro(s)</span>
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
              <th>Detalle</th>
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
              <td><button type="button" class="btn btn-secondary btn-detail" :disabled="detailLoading" :aria-label="`Ver detalle de ${a.action}`" @click="openDetail(a)">Ver detalle</button></td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>

    <p v-if="detailLoading" role="status" class="detail-note">Consultando el detalle del registro…</p>
    <p v-if="detailError" role="alert" class="detail-error">{{ detailError }}</p>

    <section v-if="detail" class="card detail" aria-labelledby="audit-detail-title" data-detalle-auditoria>
      <div class="detail-head">
        <h2 id="audit-detail-title" class="detail-title">Detalle del registro · <code>{{ detail.action }}</code></h2>
        <button type="button" class="btn btn-secondary" @click="closeDetail">Cerrar detalle</button>
      </div>
      <dl class="detail-grid">
        <div><dt>Entidad</dt><dd>{{ detail.entity_type || 'General' }} · <code>{{ detail.entity_id || '—' }}</code></dd></div>
        <div><dt>Actor</dt><dd><code>{{ detail.actor_id || 'Sistema' }}</code> · {{ detail.actor_role || 'cargo no registrado' }}</dd></div>
        <div><dt>Fecha y hora</dt><dd>{{ moment(detail.occurred_at || detail.created_at) }}</dd></div>
        <div><dt>Dominio de origen</dt><dd>{{ detail.source_domain || '—' }}</dd></div>
        <div><dt>Dirección IP</dt><dd><code>{{ detail.ip_address || '—' }}</code></dd></div>
        <div><dt>Dispositivo</dt><dd data-dispositivo>{{ detail.device || 'No registrado' }}</dd></div>
        <div v-if="detail.reason" class="wide"><dt>Motivo</dt><dd>{{ detail.reason }}</dd></div>
      </dl>

      <h3 class="detail-sub">Valores antes y después</h3>
      <p v-if="!changes.length" class="detail-note">Este registro no guardó valores de antes ni de después.</p>
      <div v-else class="table-wrapper">
        <table class="cd-table" data-cambios>
          <thead><tr><th>Campo</th><th>Antes</th><th>Después</th></tr></thead>
          <tbody>
            <tr v-for="c in changes" :key="c.field" :class="{ changed: c.changed }">
              <td><code>{{ c.field }}</code><span v-if="c.changed" class="tag-changed">cambió</span></td>
              <td class="val">{{ c.before }}</td>
              <td class="val">{{ c.after }}</td>
            </tr>
          </tbody>
        </table>
      </div>

      <h3 class="detail-sub">Traza de la operación</h3>
      <p v-if="traceNote" class="detail-note" data-traza-nota>{{ traceNote }}</p>
      <div v-if="trace" data-traza>
        <dl class="detail-grid">
          <div><dt>Correlación</dt><dd><code>{{ trace.correlation?.correlation_id }}</code></dd></div>
          <div><dt>Dominio que la originó</dt><dd>{{ trace.correlation?.origin_domain || '—' }}</dd></div>
          <div class="wide"><dt>Descripción</dt><dd>{{ trace.correlation?.description || '—' }}</dd></div>
          <div v-if="trace.correlation?.related_entities" class="wide"><dt>Entidades relacionadas</dt><dd class="val">{{ shown(trace.correlation.related_entities) }}</dd></div>
        </dl>
        <p class="detail-note">{{ trace.logs.length }} registro(s) de auditoría con esta correlación, en orden:</p>
        <ol class="trace-list">
          <li v-for="l in trace.logs" :key="l._id || l.id">
            <span class="trace-date">{{ moment(l.occurred_at || l.created_at) }}</span>
            <code>{{ l.action }}</code>
            <span>{{ l.entity_type || 'General' }} · {{ l.entity_id || '—' }}</span>
          </li>
        </ol>
      </div>
    </section>
  </div>
</template>

<style scoped>
.page{display:flex;flex-direction:column;gap:20px;}
.ph{display:flex;flex-direction:column;gap:2px;}
.search-bar{display:flex;gap:10px;align-items:center;flex-wrap:wrap;}
.av{width:28px;height:28px;border-radius:50%;color:#fff;display:flex;align-items:center;justify-content:center;font-size:11px;font-weight:600;flex-shrink:0;}
.btn-detail{font-size:12px;padding:4px 10px;white-space:nowrap;}
.detail{padding:20px;display:flex;flex-direction:column;gap:14px;min-width:0;}
.detail-head{display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap;}
.detail-title{font-size:16px;font-weight:700;margin:0;overflow-wrap:anywhere;}
.detail-sub{font-size:14px;font-weight:700;margin:6px 0 0;}
.detail-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:12px;margin:0;}
.detail-grid .wide{grid-column:1/-1;}
.detail-grid dt{font-size:11px;font-weight:700;text-transform:uppercase;color:var(--cd-text-muted);}
.detail-grid dd{margin:2px 0 0;font-size:13px;overflow-wrap:anywhere;}
.detail-note{font-size:13px;color:var(--cd-text-secondary);margin:0;}
.detail-error{font-size:13px;color:#b91c1c;margin:0;}
.val{font-size:12px;overflow-wrap:anywhere;word-break:break-word;max-width:360px;}
.changed td{background:#fffbeb;}
.tag-changed{margin-left:8px;font-size:10px;font-weight:700;color:#92400e;background:#fef3c7;border-radius:4px;padding:1px 6px;}
.trace-list{margin:0;padding-left:20px;display:flex;flex-direction:column;gap:6px;font-size:13px;}
.trace-list li{overflow-wrap:anywhere;}
.trace-list li > *{margin-right:8px;}
.trace-date{color:var(--cd-text-muted);white-space:nowrap;}
</style>
