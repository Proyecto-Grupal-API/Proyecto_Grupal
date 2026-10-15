<script setup>
import { ref, computed, watch } from 'vue'
import { router } from '@inertiajs/vue3'

const props = defineProps({
  title: { type: String, default: '' },
  subtitle: { type: String, default: '' },
  columns: { type: Array, required: true },
  rows: { type: Array, default: () => [] },
  kpis: { type: Array, default: () => [] },
  pagination: {
    type: Object,
    default: () => ({ current_page: 1, last_page: 1, per_page: 25, total: 0, from: 0, to: 0 })
  },
  filters: { type: Object, default: () => ({ q: '' }) },
  searchRoute: { type: String, required: true }
})

const search = ref(props.filters.q || '')

let debounceTimer = null
watch(search, (value) => {
  clearTimeout(debounceTimer)
  debounceTimer = setTimeout(() => {
    router.get(props.searchRoute, { q: value || undefined, page: 1 }, {
      preserveState: true, preserveScroll: true, replace: true
    })
  }, 300)
})

function goToPage(page) {
  if (page < 1 || page > props.pagination.last_page) return
  router.get(props.searchRoute, { q: search.value || undefined, page: page }, {
    preserveState: true, preserveScroll: true, replace: true
  })
}
function goToPrev() { goToPage(props.pagination.current_page - 1) }
function goToNext() { goToPage(props.pagination.current_page + 1) }

const visiblePages = computed(() => {
  const current = props.pagination.current_page
  const last = props.pagination.last_page
  const delta = 2
  const pages = []
  const start = Math.max(1, current - delta)
  const end = Math.min(last, current + delta)
  for (let i = start; i <= end; i++) pages.push(i)
  return pages
})
</script>

<template>
  <section class="space-y-6">
    <!-- Tarjeta de encabezado + búsqueda y acciones -->
    <div class="rounded-2xl bg-white border border-slate-200/70 px-6 py-5 flex flex-col lg:flex-row lg:items-center lg:justify-between gap-4">
      <div v-if="title" class="flex items-center gap-4 min-w-0">
        <span class="grid place-items-center w-12 h-12 rounded-full bg-[#1B3A8F] text-white text-base font-bold shrink-0">
          {{ title.charAt(0) }}
        </span>
        <div class="min-w-0">
          <h1 class="text-2xl font-semibold text-slate-800 tracking-tight">{{ title }}</h1>
          <p v-if="subtitle" class="mt-0.5 text-sm text-slate-500">{{ subtitle }}</p>
        </div>
      </div>
      <div v-else></div>
      <div class="flex flex-wrap items-center gap-2 lg:ml-auto">
        <input
          v-model="search"
          placeholder="Buscar..."
          class="px-3 py-2 text-sm rounded-lg border border-slate-300 bg-white focus:border-[#2F6BE8] focus:ring-[#2F6BE8]"
        />
        <slot name="toolbar">
          <button class="inline-flex items-center px-4 py-2 text-sm font-semibold rounded-lg bg-[#1B3A8F] text-white hover:bg-[#2F6BE8] transition">Nuevo</button>
        </slot>
      </div>
    </div>

    <!-- KPIs -->
    <div v-if="kpis && kpis.length > 0" class="grid grid-cols-2 md:grid-cols-4 gap-4">
      <div
        v-for="(k, i) in kpis"
        :key="i"
        class="rounded-2xl bg-white border border-slate-200/70 p-5"
      >
        <span class="text-xs text-slate-400">{{ k.label }}</span>
        <strong
          class="mt-2 block text-3xl font-semibold"
          :class="k.color === 'danger' ? 'text-rose-600'
            : k.color === 'warning' ? 'text-amber-600'
            : k.color === 'success' ? 'text-emerald-600'
            : 'text-slate-800'"
        >
          {{ k.value }}
        </strong>
      </div>
    </div>

    <!-- Tabla -->
    <div class="rounded-2xl bg-white border border-slate-200/70 overflow-hidden">
      <div class="px-6 py-4 flex items-center justify-between">
        <h2 class="text-lg text-slate-800">Información</h2>
        <span class="inline-flex items-center px-2.5 py-1 rounded-md bg-emerald-50 text-emerald-700 text-[11px] font-bold uppercase">
          {{ pagination.total }} {{ pagination.total === 1 ? 'registro' : 'registros' }}
        </span>
      </div>
      <div class="overflow-x-auto">
        <table class="w-full text-sm text-left">
          <thead class="text-xs uppercase text-slate-500 bg-slate-50 border-y border-slate-200">
            <tr>
              <th v-for="c in columns" :key="c" class="px-4 py-3 font-semibold">{{ c }}</th>
              <th v-if="$slots.actions" class="px-4 py-3 font-semibold text-right">Acciones</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="(r, i) in rows" :key="r._id || i" class="border-b border-slate-100 last:border-0 hover:bg-slate-50/70 transition">
              <td v-for="c in columns" :key="c" class="px-4 py-3 text-slate-700">{{ r[c] ?? '—' }}</td>
              <td v-if="$slots.actions" class="px-4 py-3 text-right whitespace-nowrap">
                <slot name="actions" :row="r" :index="i" />
              </td>
            </tr>
            <tr v-if="!rows.length">
              <td :colspan="columns.length + ($slots.actions ? 1 : 0)" class="px-4 py-8 text-center text-slate-500">
                {{ search ? 'No se encontraron resultados para "' + search + '"' : 'Sin registros para mostrar.' }}
              </td>
            </tr>
          </tbody>
        </table>
      </div>

      <div v-if="pagination.total > 0" class="border-t border-slate-200 px-6 py-4 flex flex-col sm:flex-row items-center justify-between gap-3">
        <p class="text-xs text-slate-500">
          Mostrando <strong>{{ pagination.from }}</strong> a <strong>{{ pagination.to }}</strong> de <strong>{{ pagination.total }}</strong> registros
        </p>

        <div class="flex items-center gap-1">
          <button @click="goToPrev" :disabled="pagination.current_page === 1"
            class="px-3 py-1 text-xs font-medium rounded border border-slate-300 bg-white text-slate-700 hover:bg-slate-100 disabled:opacity-40 disabled:cursor-not-allowed transition">
            ← Anterior
          </button>

          <button v-for="p in visiblePages" :key="p" @click="goToPage(p)"
            :class="p === pagination.current_page
              ? 'px-3 py-1 text-xs font-semibold rounded bg-[#1B3A8F] text-white'
              : 'px-3 py-1 text-xs font-medium rounded border border-slate-300 bg-white text-slate-700 hover:bg-slate-100 transition'">
            {{ p }}
          </button>

          <button @click="goToNext" :disabled="pagination.current_page === pagination.last_page"
            class="px-3 py-1 text-xs font-medium rounded border border-slate-300 bg-white text-slate-700 hover:bg-slate-100 disabled:opacity-40 disabled:cursor-not-allowed transition">
            Siguiente →
          </button>
        </div>
      </div>
    </div>
  </section>
</template>
