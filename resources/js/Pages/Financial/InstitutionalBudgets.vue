<script setup>
import { computed, ref } from 'vue';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { formatInstitutionalMoney as money } from '@/Support/institutionalMoney.js';

const props = defineProps({ report: Object, labels: Array, filters: Object, canListAll: Boolean });
const form = ref({ budget: props.filters.budget || '', business: props.filters.business || '', view: props.filters.view, per_page: props.filters.per_page });
const busy = ref(false);
const page = usePage();
const titles = computed(() => Object.fromEntries((props.labels || []).map(row => [row.budget_id, row])));
const selected = computed(() => props.filters.budget ? props.report.budgets[0] : null);
const params = (extra = {}) => {
    const q = { ...form.value, page: 1, ...extra };
    if (!q.budget) { delete q.budget; delete q.business; q.view = 'businesses'; }
    if (!q.business) delete q.business;
    return q;
};
const visit = extra => router.get(route('financial.institutional.index'), params(extra), {
    preserveState: false, onStart: () => busy.value = true, onFinish: () => busy.value = false,
});
const date = value => {
    if (!value) return 'Sin fecha';
    const parsed = new Date(value);
    return Number.isNaN(parsed.getTime()) ? 'Sin fecha' : parsed.toLocaleString('es-MX');
};
const findings = {
    CONSUMED_VS_OBLIGATIONS: 'El consumo no coincide con las obligaciones.',
    CONSUMED_VS_LEDGER: 'El consumo no coincide con sus movimientos.',
    COMMITTED_VS_BONUS_BALANCES: 'El compromiso no coincide con los saldos de bonos.',
    REFUND_COUNTER_VS_EVIDENCE: 'Las devoluciones requieren revisión.',
    BONUS_BINDING: 'El vínculo de emisión requiere revisión.',
    OBLIGATION_EVIDENCE: 'La evidencia de una obligación requiere revisión.',
    PURCHASE_WITHOUT_OBLIGATION: 'Hay una compra sin obligación registrada.',
    REFUND_EVIDENCE: 'La evidencia de una devolución requiere revisión.',
    LEDGER_RUNNING_BALANCE: 'Hay una diferencia en el saldo por movimiento.',
    BONUS_VS_LEDGER_BALANCE: 'El saldo del bono no coincide con sus movimientos.',
    OBLIGATION_WITHOUT_MOVEMENT: 'Falta un movimiento de consumo correspondiente.',
    REFUND_WITHOUT_MOVEMENT: 'Falta un movimiento de devolución correspondiente.',
    LEDGER_WITHOUT_EVIDENCE: 'Hay un movimiento sin evidencia correspondiente.',
    COMPLETED_REFUND_WITHOUT_EVIDENCE: 'Hay una devolución completada sin su evidencia.',
    BUDGET_CURRENCY: 'La moneda del presupuesto requiere revisión.',
    SETTLEMENT_COUNTER_VS_EVIDENCE: 'El importe liquidado no coincide con sus comprobantes.',
    SETTLEMENT_EVIDENCE: 'Un comprobante de liquidación requiere revisión.',
    SETTLEMENT_WITHOUT_RECORD: 'Hay una auditoría de liquidación sin su registro.',
};
</script>

<template>
    <Head title="Presupuestos institucionales" />
    <AuthenticatedLayout>
        <template #header>
            <p class="text-xs font-bold uppercase tracking-widest text-sky-600">Finanzas · Consulta administrativa</p>
            <h2 class="mt-1 text-2xl font-bold text-[#00338D]">Presupuestos institucionales</h2>
        </template>
        <div class="min-h-screen bg-[#F5F8FC] px-4 py-7 sm:px-6">
            <div class="mx-auto max-w-7xl space-y-5">
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <Link :href="route('financial.dashboard')" class="font-semibold text-blue-800">Volver a Finanzas</Link>
                    <Link v-if="canListAll && filters.budget" :href="route('financial.institutional.index')" class="font-semibold text-blue-800">Todos los presupuestos</Link>
                </div>
                <p class="text-slate-600">Consulta el presupuesto, las compras con BECA y los pagos registrados a los negocios. Esta pantalla no modifica importes ni ejecuta pagos.</p>
                <form @submit.prevent="visit({})" class="grid gap-4 rounded-2xl border bg-white p-5 sm:grid-cols-2 lg:grid-cols-5">
                    <label class="text-sm font-semibold text-slate-700 lg:col-span-2">Presupuesto
                        <input v-model.trim="form.budget" maxlength="36" placeholder="UUID del presupuesto, o elige uno del listado" class="mt-1 block w-full rounded-lg border-slate-300 text-sm" />
                    </label>
                    <label class="text-sm font-semibold text-slate-700">Negocio
                        <input v-model.trim="form.business" :disabled="!form.budget" maxlength="100" placeholder="Todos los negocios" class="mt-1 block w-full rounded-lg border-slate-300 text-sm disabled:bg-slate-100" />
                    </label>
                    <label class="text-sm font-semibold text-slate-700">Vista
                        <select v-model="form.view" :disabled="!form.budget" class="mt-1 block w-full rounded-lg border-slate-300 text-sm">
                            <option value="businesses">Resumen por negocio</option><option value="obligations">Compras y obligaciones</option><option value="settlements">Liquidaciones registradas</option>
                        </select>
                    </label>
                    <p v-if="Object.keys(page.props.errors || {}).length" role="alert" class="text-sm text-red-700 lg:col-span-5">Revisa los filtros. <span v-for="(error, key) in page.props.errors" :key="key" class="block">{{ error }}</span></p>
                    <div class="flex items-end"><button :disabled="busy" class="w-full rounded-lg bg-[#00338D] px-4 py-2 font-semibold text-white disabled:opacity-50">{{ busy ? 'Consultando…' : 'Consultar' }}</button></div>
                </form>
                <div v-if="report.status === 'NO_DATA'" class="rounded-2xl border bg-white p-7 text-slate-600">No hay presupuestos para esta consulta.</div>
                <template v-else>
                    <div :class="report.consistent ? 'border-emerald-200 bg-emerald-50 text-emerald-900' : 'border-amber-200 bg-amber-50 text-amber-900'" class="rounded-xl border px-5 py-4" role="status">
                        <p class="font-semibold">{{ report.consistent ? 'Los registros consultados coinciden.' : 'Se detectaron diferencias que requieren revisión.' }}</p>
                        <p class="mt-1 text-sm">Observado: {{ date(report.observed_at) }}. La conciliación comprende todos los negocios de los presupuestos de esta página.</p>
                    </div>
                    <section v-for="budget in report.budgets" :key="budget.budget_id" class="rounded-2xl border bg-white p-5 sm:p-6">
                        <div class="flex flex-wrap justify-between gap-3">
                            <div><h3 class="text-lg font-bold text-[#00338D]">{{ titles[budget.budget_id]?.purpose || 'Presupuesto institucional' }}</h3>
                                <p class="mt-1 text-sm text-slate-600">{{ titles[budget.budget_id]?.institution_reference }} · {{ budget.currency }} · {{ budget.status === 'ACTIVE' ? 'Activo' : 'Suspendido' }}</p>
                                <p class="mt-1 break-all font-mono text-xs text-slate-500">{{ budget.budget_id }}</p>
                            </div>
                            <Link v-if="!filters.budget" :href="route('financial.institutional.index', { budget: budget.budget_id })" class="self-start rounded-lg border border-blue-800 px-4 py-2 text-sm font-semibold text-blue-800">Ver detalle</Link>
                        </div>
                        <dl class="mt-5 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                            <div v-for="card in [{ label: 'Aprobado', value: budget.approved_amount_cents }, { label: 'Disponible', value: budget.available_amount_cents }, { label: 'Comprometido', value: budget.committed_amount_cents }, { label: 'Consumido neto', value: budget.consumed_amount_cents }]" :key="card.label" class="rounded-xl bg-slate-50 p-4">
                                <dt class="text-sm text-slate-600">{{ card.label }}</dt><dd class="mt-1 break-words text-lg font-bold text-[#00338D]">{{ money(card.value, budget.currency) }}</dd>
                            </div>
                        </dl>
                        <dl class="mt-3 grid gap-3 sm:grid-cols-3">
                            <div class="rounded-xl border p-4"><dt class="text-sm text-slate-600">Compras registradas</dt><dd class="mt-1 break-words font-semibold">{{ money(budget.obligations.purchase_amount_cents, budget.currency) }}</dd></div>
                            <div class="rounded-xl border p-4"><dt class="text-sm text-slate-600">Liquidado al negocio</dt><dd class="mt-1 break-words font-semibold">{{ money(budget.obligations.settled_amount_cents, budget.currency) }}</dd></div>
                            <div class="rounded-xl border p-4"><dt class="text-sm text-slate-600">Pendiente de pago</dt><dd class="mt-1 break-words font-semibold">{{ money(budget.obligations.pending_amount_cents, budget.currency) }}</dd></div>
                        </dl>
                        <div v-if="budget.findings.length" class="mt-5 rounded-xl border border-amber-200 bg-amber-50 p-4">
                            <h4 class="font-semibold text-amber-900">Diferencias encontradas</h4>
                            <ul class="mt-2 space-y-3 text-sm text-amber-950"><li v-for="(finding, i) in budget.findings" :key="i">
                                <p>{{ findings[finding.code] || 'La evidencia requiere revisión.' }} <span v-if="finding.count">Casos: {{ finding.count }}.</span></p>
                                <p v-if="finding.expected_cents !== undefined">Según evidencia: {{ money(finding.expected_cents, budget.currency) }} · Registrado: {{ money(finding.stored_cents, budget.currency) }}</p>
                                <details v-if="finding.sample_ids?.length" class="mt-1"><summary class="cursor-pointer font-semibold">Referencias de ejemplo</summary><ul class="mt-1"><li v-for="id in finding.sample_ids" :key="id" class="break-all font-mono text-xs">{{ id }}</li></ul></details>
                            </li></ul>
                        </div>
                    </section>
                    <section v-if="selected" class="rounded-2xl border bg-white p-5 sm:p-6">
                        <h3 class="text-lg font-bold text-[#00338D]">{{ filters.view === 'settlements' ? 'Liquidaciones registradas' : filters.view === 'obligations' ? 'Compras y obligaciones' : 'Resumen por negocio' }}</h3>
                        <p v-if="filters.business" class="mt-1 text-sm text-slate-600">Negocio: {{ filters.business }}. Los totales del presupuesto conservan todos sus negocios.</p>
                        <p v-if="!report.entries.length" class="mt-4 text-slate-600">No hay registros para estos filtros en esta página.</p>
                        <div v-else class="mt-4 overflow-x-auto">
                            <table class="w-full text-left text-sm">
                                <thead class="border-b bg-slate-50 text-slate-600"><tr>
                                    <th class="p-3">Negocio / referencia</th>
                                    <template v-if="filters.view !== 'settlements'"><th class="p-3">Compra</th><th class="p-3">Devuelto</th><th class="p-3">Liquidado</th><th class="p-3">Pendiente</th></template>
                                    <template v-else><th class="p-3">Importe</th><th class="p-3">Fecha de pago</th></template>
                                </tr></thead>
                                <tbody class="divide-y"><tr v-for="(entry, i) in report.entries" :key="entry.settlement_id || entry.obligation_id || i">
                                    <td class="max-w-xs p-3"><p class="break-all font-semibold">{{ entry.business_id }}</p>
                                        <template v-if="filters.view === 'obligations'"><p class="mt-1 break-all font-mono text-xs">Compra: {{ entry.purchase_payment_id }}</p><p class="mt-1 break-all font-mono text-xs">Obligación: {{ entry.obligation_id }}</p></template>
                                        <template v-if="filters.view === 'settlements'"><p class="mt-1 break-all">{{ entry.payment_provider }} · {{ entry.payment_reference }}</p><p class="mt-1 break-all font-mono text-xs">{{ entry.settlement_id }}</p></template>
                                    </td>
                                    <template v-if="filters.view !== 'settlements'"><td class="whitespace-nowrap p-3">{{ money(entry.purchase_amount_cents, entry.currency) }}</td><td class="whitespace-nowrap p-3">{{ money(entry.refunded_amount_cents, entry.currency) }}</td><td class="whitespace-nowrap p-3">{{ money(entry.settled_amount_cents, entry.currency) }}</td><td class="whitespace-nowrap p-3 font-semibold">{{ money(entry.pending_amount_cents, entry.currency) }}</td></template>
                                    <template v-else><td class="whitespace-nowrap p-3 font-semibold">{{ money(entry.amount_cents, entry.currency) }}</td><td class="whitespace-nowrap p-3">{{ date(entry.paid_at_utc) }}</td></template>
                                </tr></tbody>
                            </table>
                        </div>
                    </section>
                    <nav class="flex flex-wrap items-center justify-between gap-3" aria-label="Paginación">
                        <button v-if="report.page > 1" :disabled="busy" @click="visit({ page: report.page - 1 })" class="rounded-lg border bg-white px-4 py-2 font-semibold text-blue-800">Anterior</button>
                        <span class="text-sm text-slate-600">Página {{ report.page }}</span>
                        <button v-if="report.has_more" :disabled="busy" @click="visit({ page: report.page + 1 })" class="rounded-lg border bg-white px-4 py-2 font-semibold text-blue-800">Siguiente</button>
                    </nav>
                </template>
                <p class="text-sm text-slate-500">Los pagos registrados no liberan presupuesto consumido. Esta consulta no certifica por sí sola el respaldo en efectivo.</p>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
