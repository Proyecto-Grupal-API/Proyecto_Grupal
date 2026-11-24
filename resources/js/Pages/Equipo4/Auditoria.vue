<script setup>
import { reactive, ref } from 'vue'
import { router } from '@inertiajs/vue3'
import Equipo4Layout from '../../Layouts/Equipo4Layout.vue'

const props = defineProps({
  events: { type: Array, default: () => [] },
  families: { type: Object, default: () => ({}) },
  kpis: { type: Array, default: () => [] },
  pagination: { type: Object, default: () => ({ current_page: 1, last_page: 1, total: 0, from: 0, to: 0 }) },
  filters: { type: Object, default: () => ({}) },
})

const form = reactive({
  event: props.filters.event || '',
  q: props.filters.q || '',
  from: props.filters.from || '',
  to: props.filters.to || '',
})
const expanded = ref(null)

const actionLabels = {
  created: 'Alta', updated: 'Cambio', deleted: 'Baja', authorized: 'Autorización', cancelled: 'Cancelación',
  closed: 'Cierre', dismissed: 'Descarte', released: 'Liberación', transferred: 'Transferencia',
}

function query(page = 1) {
  const params = { page }
  for (const key of ['event', 'q', 'from', 'to']) if (form[key]) params[key] = form[key]
  return params
}
function apply() { router.get('/equipo4/auditoria', query(1), { preserveState: true, preserveScroll: true, replace: true }) }
function clear() { form.event = ''; form.q = ''; form.from = ''; form.to = ''; apply() }
function goTo(page) {
  if (page < 1 || page > props.pagination.last_page) return
  router.get('/equipo4/auditoria', query(page), { preserveState: true, preserveScroll: true, replace: true })
}
function when(value) {
  return value ? new Date(value).toLocaleString('es-MX', { dateStyle: 'short', timeStyle: 'medium' }) : '—'
}
</script>

<template>
  <Equipo4Layout>
    <section class="space-y-6">
      <div class="rounded-2xl bg-white border border-slate-200/70 px-6 py-5 flex items-center gap-4">
        <span class="grid place-items-center w-12 h-12 rounded-full bg-[#1B3A8F] text-white text-base font-bold shrink-0">A</span>
        <div class="min-w-0">
          <h1 class="text-2xl font-semibold text-slate-800 tracking-tight">Auditoría</h1>
          <p class="mt-0.5 text-sm text-slate-500">Quién hizo qué y cuándo en el inventario de este negocio. Sólo lectura.</p>
        </div>
      </div>

      <div v-if="kpis.length" class="grid grid-cols-2 md:grid-cols-3 gap-4">
        <div v-for="(k, i) in kpis" :key="i" class="rounded-2xl bg-white border border-slate-200/70 p-5">
          <span class="text-xs text-slate-400">{{ k.label }}</span>
          <strong class="mt-2 block text-3xl font-semibold" :class="k.color === 'success' ? 'text-emerald-600' : 'text-slate-800'">{{ k.value }}</strong>
        </div>
      </div>

      <form class="rounded-2xl bg-white border border-slate-200/70 px-6 py-4 grid gap-3 md:grid-cols-5 items-end" @submit.prevent="apply">
        <div>
          <label for="audit-family" class="block text-xs font-semibold uppercase text-slate-600 mb-1">Tipo de registro</label>
          <select id="audit-family" v-model="form.event" class="w-full px-3 py-2 text-sm rounded-lg border border-slate-300 bg-white">
            <option value="">Todos</option>
            <option v-for="(label, key) in families" :key="key" :value="key">{{ label }}</option>
          </select>
        </div>
        <div>
          <label for="audit-q" class="block text-xs font-semibold uppercase text-slate-600 mb-1">Folio, SKU o persona</label>
          <input id="audit-q" v-model="form.q" type="search" maxlength="80" class="w-full px-3 py-2 text-sm rounded-lg border border-slate-300" />
        </div>
        <div>
          <label for="audit-from" class="block text-xs font-semibold uppercase text-slate-600 mb-1">Desde</label>
          <input id="audit-from" v-model="form.from" type="date" class="w-full px-3 py-2 text-sm rounded-lg border border-slate-300" />
        </div>
        <div>
          <label for="audit-to" class="block text-xs font-semibold uppercase text-slate-600 mb-1">Hasta</label>
          <input id="audit-to" v-model="form.to" type="date" class="w-full px-3 py-2 text-sm rounded-lg border border-slate-300" />
        </div>
        <div class="flex gap-2">
          <button type="submit" class="px-4 py-2 text-sm font-semibold rounded-lg bg-[#1B3A8F] text-white hover:bg-[#2F6BE8] transition">Filtrar</button>
          <button type="button" class="px-4 py-2 text-sm font-medium rounded-lg border border-slate-300 bg-white text-slate-700 hover:bg-slate-50 transition" @click="clear">Limpiar</button>
        </div>
      </form>

      <div class="rounded-2xl bg-white border border-slate-200/70 overflow-hidden">
        <div class="px-6 py-4 flex items-center justify-between">
          <h2 class="text-lg text-slate-800">Registros</h2>
          <span class="inline-flex items-center px-2.5 py-1 rounded-md bg-emerald-50 text-emerald-700 text-[11px] font-bold uppercase">
            {{ pagination.total }} {{ pagination.total === 1 ? 'registro' : 'registros' }}
          </span>
        </div>
        <div class="overflow-x-auto">
          <table class="w-full text-sm text-left">
            <thead class="text-xs uppercase text-slate-500 bg-slate-50 border-y border-slate-200">
              <tr>
                <th class="px-4 py-3 font-semibold">Fecha</th>
                <th class="px-4 py-3 font-semibold">Registro</th>
                <th class="px-4 py-3 font-semibold">Acción</th>
                <th class="px-4 py-3 font-semibold">Referencia</th>
                <th class="px-4 py-3 font-semibold">Persona</th>
                <th class="px-4 py-3 font-semibold text-right">Detalle</th>
              </tr>
            </thead>
            <tbody>
              <template v-for="e in events" :key="e._id">
                <tr class="border-b border-slate-100 hover:bg-slate-50/70 transition" :data-event="e.event">
                  <td class="px-4 py-3 text-slate-700 whitespace-nowrap">{{ when(e.created_at) }}</td>
                  <td class="px-4 py-3 text-slate-700">{{ e.family }}</td>
                  <td class="px-4 py-3 text-slate-700">{{ actionLabels[e.action] || e.action || e.event }}</td>
                  <td class="px-4 py-3 text-slate-700 font-mono text-xs">{{ e.entity_label || e.entity_id || '—' }}</td>
                  <td class="px-4 py-3 text-slate-700">{{ e.actor_name || (e.actor_id === 'SYSTEM' ? 'Sistema' : e.actor_id) || '—' }}</td>
                  <td class="px-4 py-3 text-right">
                    <button type="button" class="px-3 py-1 text-xs font-medium rounded border border-slate-300 bg-white text-slate-700 hover:bg-slate-100 transition"
                      :aria-expanded="expanded === e._id" @click="expanded = expanded === e._id ? null : e._id">
                      {{ expanded === e._id ? 'Ocultar' : 'Ver cambios' }}
                    </button>
                  </td>
                </tr>
                <tr v-if="expanded === e._id" class="bg-slate-50/60 border-b border-slate-100">
                  <td colspan="6" class="px-6 py-3">
                    <p v-if="!e.changes.length" class="text-xs text-slate-500">Este registro no guarda detalle de cambios.</p>
                    <table v-else class="w-full text-xs">
                      <thead class="text-slate-500"><tr><th class="py-1 text-left font-semibold">Campo</th><th class="py-1 text-left font-semibold">Antes</th><th class="py-1 text-left font-semibold">Después</th></tr></thead>
                      <tbody>
                        <tr v-for="c in e.changes" :key="c.field">
                          <td class="py-1 pr-3 font-mono text-slate-600">{{ c.field }}</td>
                          <td class="py-1 pr-3 text-slate-600 break-all">{{ c.before ?? '—' }}</td>
                          <td class="py-1 text-slate-800 break-all">{{ c.after ?? '—' }}</td>
                        </tr>
                      </tbody>
                    </table>
                    <p class="mt-2 text-[11px] text-slate-400">Evento {{ e.event }} · Origen {{ e.ip || 'interno' }} · Correlación {{ e.correlation_id || '—' }}</p>
                  </td>
                </tr>
              </template>
              <tr v-if="!events.length">
                <td colspan="6" class="px-4 py-8 text-center text-slate-500">Sin registros para este filtro.</td>
              </tr>
            </tbody>
          </table>
        </div>
        <div v-if="pagination.total > 0" class="border-t border-slate-200 px-6 py-4 flex flex-col sm:flex-row items-center justify-between gap-3">
          <p class="text-xs text-slate-500">Mostrando <strong>{{ pagination.from }}</strong> a <strong>{{ pagination.to }}</strong> de <strong>{{ pagination.total }}</strong> registros</p>
          <div class="flex items-center gap-1">
            <button type="button" :disabled="pagination.current_page === 1" class="px-3 py-1 text-xs font-medium rounded border border-slate-300 bg-white text-slate-700 hover:bg-slate-100 disabled:opacity-40 disabled:cursor-not-allowed transition" @click="goTo(pagination.current_page - 1)">← Anterior</button>
            <span class="px-2 text-xs text-slate-600">Página {{ pagination.current_page }} de {{ pagination.last_page }}</span>
            <button type="button" :disabled="pagination.current_page === pagination.last_page" class="px-3 py-1 text-xs font-medium rounded border border-slate-300 bg-white text-slate-700 hover:bg-slate-100 disabled:opacity-40 disabled:cursor-not-allowed transition" @click="goTo(pagination.current_page + 1)">Siguiente →</button>
          </div>
        </div>
      </div>
    </section>
  </Equipo4Layout>
</template>
