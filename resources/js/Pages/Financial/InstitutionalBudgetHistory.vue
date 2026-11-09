<script setup>
import { ref } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { formatInstitutionalMoney as money } from '@/Support/institutionalMoney.js';
const props = defineProps({ history: Object, filters: Object, actions: Array });
const action = ref(props.filters.action || '');
const labels = { APPROVE: 'Aprobación', ADJUST: 'Ajuste del importe aprobado', SUSPEND: 'Suspensión', RESUME: 'Reanudación', ISSUE_BECA: 'Emisión de BECA', CONSUME_BECA: 'Compra con BECA', CANCEL_BECA: 'Cancelación de BECA', EXPIRE_BECA: 'Vencimiento de BECA', REFUND_BECA: 'Devolución al bono', SETTLE_BECA: 'Liquidación al negocio', REFUND_CLOSED_BECA: 'Devolución al presupuesto', UNKNOWN: 'Operación no reconocida' };
const amounts = { approved_amount_cents: 'Aprobado', committed_amount_cents: 'Comprometido', consumed_amount_cents: 'Consumido neto', available_amount_cents: 'Disponible' };
const refs = { bonus_id: 'Bono', commitment_id: 'Compromiso', purchase_payment_id: 'Compra', obligation_id: 'Obligación', refund_request_id: 'Devolución', settlement_id: 'Liquidación' };
const visit = before => router.get(route('financial.institutional.history', { budgetId: props.history.budget_id }), { ...(action.value ? { action: action.value } : {}), per_page: props.filters.per_page, ...(before ? { before } : {}) });
const date = value => value ? new Date(value).toLocaleString('es-MX') : 'Sin fecha';
const state = value => value === 'ACTIVE' ? 'Activo' : value === 'SUSPENDED' ? 'Suspendido' : 'No registrado';
</script>
<template>
    <Head title="Historial presupuestario" />
    <AuthenticatedLayout>
        <template #header><h2 class="text-2xl font-bold text-[#00338D]">Historial presupuestario</h2></template>
        <main class="min-h-screen bg-[#F5F8FC] p-5"><div class="mx-auto max-w-6xl space-y-5">
            <Link :href="route('financial.institutional.index', { budget: history.budget_id })" class="font-semibold text-blue-800">Volver al presupuesto</Link>
            <div class="rounded-xl border bg-white p-5"><h3 class="text-lg font-bold">{{ history.purpose }}</h3><p class="mt-2 break-all font-mono text-xs">{{ history.budget_id }}</p><p class="mt-3 text-sm text-slate-600">Operaciones registradas, de la más reciente a la más antigua. Consulta los importes antes y después de cada operación. La conciliación del presupuesto se consulta por separado.</p></div>
            <form @submit.prevent="visit(null)" class="flex flex-wrap items-end gap-3 rounded-xl border bg-white p-5"><label class="text-sm font-semibold">Tipo de operación<select v-model="action" class="mt-1 block rounded border-slate-300"><option value="">Todas</option><option v-for="code in actions" :key="code" :value="code">{{ labels[code] }}</option></select></label><button class="rounded bg-[#00338D] px-4 py-2 text-white">Consultar</button><button type="button" @click="action = ''; visit(null)" class="rounded border px-4 py-2">Ver desde el inicio</button></form>
            <p v-if="!history.items.length" class="rounded-xl border bg-white p-5">No hay operaciones para esta consulta.</p>
            <article v-for="op in history.items" :key="op.operation_id" class="rounded-xl border bg-white p-5">
                <h3 class="text-lg font-bold text-[#00338D]">{{ labels[op.action] }}</h3><p class="mt-1 text-sm text-slate-600">{{ date(op.created_at) }}</p><p class="mt-2 break-all font-mono text-xs">Referencia de operación: {{ op.operation_id }}</p><p class="mt-3 whitespace-pre-wrap break-words">{{ op.reason }}</p>
                <p v-if="!op.recorded_fields_valid" class="mt-3 rounded bg-amber-50 p-3 text-amber-900">Hay campos que requieren revisión. No se han corregido ni sustituido por cero.</p>
                <div class="mt-4 overflow-x-auto"><table class="w-full text-left text-sm"><caption class="mb-2 text-left font-semibold text-slate-700">Saldos del presupuesto</caption><thead><tr class="border-b"><th class="p-2">Concepto</th><th class="p-2">Antes</th><th class="p-2">Después</th></tr></thead><tbody><tr v-for="(title, key) in amounts" :key="key" class="border-b"><th class="p-2 font-medium">{{ title }}</th><td class="p-2">{{ op.before[key] === null ? 'No registrado' : money(op.before[key], history.currency) }}</td><td class="p-2">{{ op.after[key] === null ? 'No registrado' : money(op.after[key], history.currency) }}</td></tr><tr><th class="p-2 font-medium">Versión</th><td class="p-2">{{ op.before.version ?? 'No registrada' }}</td><td class="p-2">{{ op.after.version ?? 'No registrada' }}</td></tr><tr><th class="p-2 font-medium">Estado</th><td class="p-2">{{ state(op.before.status) }}</td><td class="p-2">{{ state(op.after.status) }}</td></tr></tbody></table></div>
                <section v-if="op.settlement" class="mt-5 rounded-xl border border-sky-200 bg-sky-50 p-4">
                    <h4 class="font-bold text-[#00338D]">Liquidación de esta obligación</h4>
                    <p class="mt-1 text-sm text-slate-700">Estos importes corresponden a la obligación indicada; no son saldos generales del presupuesto.</p>
                    <p class="mt-2 break-all font-mono text-xs">Obligación: {{ op.settlement.obligation_id ?? 'No registrada' }}</p>
                    <p class="mt-3 font-semibold">Importe registrado en esta liquidación: {{ op.settlement.paid_amount_cents === null ? 'No registrado' : money(op.settlement.paid_amount_cents, history.currency) }}</p>
                    <div class="mt-3 overflow-x-auto"><table class="w-full text-left text-sm"><thead><tr class="border-b border-sky-200"><th class="p-2">Concepto de la obligación</th><th class="p-2">Antes</th><th class="p-2">Después</th></tr></thead><tbody>
                        <tr v-for="(title, key) in { settled_amount_cents: 'Liquidado acumulado', unpaid_amount_cents: 'Pendiente de pago' }" :key="key" class="border-b border-sky-200"><th class="p-2 font-medium">{{ title }}</th><td class="p-2">{{ op.settlement.before[key] === null ? 'No registrado' : money(op.settlement.before[key], history.currency) }}</td><td class="p-2">{{ op.settlement.after[key] === null ? 'No registrado' : money(op.settlement.after[key], history.currency) }}</td></tr>
                    </tbody></table></div>
                </section>
                <details v-if="Object.keys(op.references).length" class="mt-4"><summary class="cursor-pointer font-semibold text-blue-800">Referencias relacionadas</summary><dl class="mt-2 space-y-2"><div v-for="(id, key) in op.references" :key="key"><dt class="text-sm font-medium">{{ refs[key] }}</dt><dd class="break-all font-mono text-xs">{{ id }}</dd></div></dl></details>
            </article>
            <button v-if="history.has_more" @click="visit(history.next_cursor)" class="rounded bg-[#00338D] px-4 py-2 text-white">Ver operaciones anteriores</button>
        </div></main>
    </AuthenticatedLayout>
</template>
