<script setup>
import StudentServicesLayout from '@/Layouts/StudentServicesLayout.vue';
import { router, usePage } from '@inertiajs/vue3';
import { computed, reactive, ref } from 'vue';

const props = defineProps({ report: Object, filters: Object, services: Array });
const form = reactive({ ...props.filters });
const loading = ref(false);
const errors = computed(() => usePage().props.errors || {});
const money = cents => cents === null || cents === undefined ? '—' : new Intl.NumberFormat('es-MX', { style: 'currency', currency: 'MXN' }).format(cents / 100);
const query = () => Object.fromEntries(Object.entries(form).filter(([, v]) => v));
const csvUrl = computed(() => route('module5.reports.csv', query()));
function apply() {
    loading.value = true;
    router.get(route('module5.reports.index'), query(), { preserveState: true, preserveScroll: true, onFinish: () => { loading.value = false; } });
}
const statusLabel = s => ({ active: 'Activo', returned: 'Devuelto', overdue: 'Vencido', pending: 'Pendiente', paid: 'Pagado', waived: 'Condonado', cancelled: 'Cancelado',
    open: 'Abierto', assigned: 'Asignado', in_progress: 'En proceso', resolved: 'Resuelto', closed: 'Cerrado', delivered: 'Entregado', ready: 'Listo', confirmed: 'Confirmado' }[s] || s);
</script>

<template>
    <StudentServicesLayout title="Reporte de Servicios" subtitle="Uso por servicio y estado en el periodo seleccionado">
        <form class="mb-6 grid gap-4 rounded-xl border bg-white p-5 sm:grid-cols-4" @submit.prevent="apply" aria-label="Filtros del reporte">
            <label class="text-sm font-semibold" for="rep-desde">Desde
                <input id="rep-desde" v-model="form.desde" type="date" required class="mt-1 block w-full rounded border-slate-300" :aria-invalid="!!errors.desde" />
            </label>
            <label class="text-sm font-semibold" for="rep-hasta">Hasta
                <input id="rep-hasta" v-model="form.hasta" type="date" required class="mt-1 block w-full rounded border-slate-300" :aria-invalid="!!errors.hasta" />
            </label>
            <label class="text-sm font-semibold" for="rep-servicio">Servicio
                <select id="rep-servicio" v-model="form.servicio" class="mt-1 block w-full rounded border-slate-300">
                    <option value="">Todos</option>
                    <option v-for="s in services" :key="s.clave" :value="s.clave">{{ s.nombre }}</option>
                </select>
            </label>
            <div class="flex items-end gap-3">
                <button class="rounded bg-[#00338D] px-4 py-2 font-semibold text-white disabled:opacity-60" :disabled="loading">{{ loading ? 'Consultando…' : 'Consultar' }}</button>
                <a :href="csvUrl" class="rounded border border-[#00338D] px-4 py-2 font-semibold text-[#00338D]">Exportar CSV</a>
            </div>
            <p v-if="Object.keys(errors).length" role="alert" class="rounded bg-red-50 p-3 text-sm text-red-800 sm:col-span-4">{{ Object.values(errors).join(' ') }}</p>
        </form>

        <p class="mb-4 text-sm text-slate-600" role="status">Periodo {{ report.periodo.desde }} a {{ report.periodo.hasta }} ({{ report.periodo.zona_horaria }}) · {{ report.totales.registros }} registros</p>

        <section class="mb-6 grid gap-4 sm:grid-cols-3 lg:grid-cols-4" aria-label="Indicadores">
            <article v-for="row in report.servicios" :key="row.clave" class="rounded-xl border bg-white p-4">
                <h2 class="text-sm font-semibold text-slate-600">{{ row.servicio }}</h2>
                <p class="mt-1 text-3xl font-bold text-[#00338D]">{{ row.total }}</p>
                <p v-if="row.importe_cents !== null" class="text-sm text-slate-600">Importe: {{ money(row.importe_cents) }}</p>
                <p v-if="row.vencidos !== undefined" class="text-sm text-amber-700">Vencidos: {{ row.vencidos }}</p>
            </article>
        </section>

        <section class="overflow-x-auto rounded-xl border bg-white p-4" aria-label="Detalle por estado">
            <table class="w-full text-left text-sm">
                <thead><tr><th class="py-2">Servicio</th><th>Estado</th><th>Registros</th><th>Importe</th></tr></thead>
                <tbody>
                    <template v-for="row in report.servicios" :key="row.clave">
                        <tr v-if="!Object.keys(row.por_estado).length" class="border-t"><td class="py-2">{{ row.servicio }}</td><td colspan="3" class="text-slate-500">Sin registros en el periodo</td></tr>
                        <tr v-for="(data, status) in row.por_estado" :key="row.clave + status" class="border-t">
                            <td class="py-2">{{ row.servicio }}</td><td>{{ statusLabel(status) }}</td><td>{{ data.registros }}</td><td>{{ money(data.importe_cents) }}</td>
                        </tr>
                    </template>
                </tbody>
            </table>
        </section>
    </StudentServicesLayout>
</template>
