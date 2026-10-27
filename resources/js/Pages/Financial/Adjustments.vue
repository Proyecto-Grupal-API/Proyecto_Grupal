<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { computed, reactive, ref } from 'vue';
import axios from 'axios';

const props = defineProps({
    wallet: { type: Object, default: null }, canRequest: Boolean,
    permissions: { type: Object, default: () => ({}) },
    holds: { type: Array, default: () => [] }, refunds: { type: Array, default: () => [] },
    purchaseRefunds: { type: Array, default: () => [] }, purchases: { type: Array, default: () => [] },
    transactions: { type: Array, default: () => [] }, policies: { type: Array, default: () => [] },
});
const tabs = [['holds', 'Retenciones'], ['refunds', 'Devoluciones'], ['transactions', 'Operaciones y reversos'], ['policies', 'Plazos de retención']];
const tab = ref('holds');
const dialog = ref(null);
const busy = ref(false);
const notice = ref('');
const error = ref('');
const history = ref(null);
const historyPage = ref(1);
const historyTotal = ref(0);
const lookupWallet = ref('');
const form = reactive({ kind: '', title: '', id: '', action: '', key: '', reason: '', amount: '',
    selectedId: '', walletAmount: '0', bonusAmounts: {}, operationType: '', maxSeconds: '', active: true, version: 1, receipt: '' });
const can = (action) => Boolean(props.permissions[action]);
const money = (cents) => new Intl.NumberFormat('es-MX', { style: 'currency', currency: props.wallet?.currency || 'MXN' }).format((cents || 0) / 100);
const date = (value) => value ? new Intl.DateTimeFormat('es-MX', { dateStyle: 'medium', timeStyle: 'short' }).format(new Date(value)) : '—';
const short = (id) => id ? id.slice(0, 8) : '—';
const refundable = computed(() => props.transactions.filter((row) => row.status === 'COMPLETADA' && row.amount_cents < 0
    && ['PAGO', 'RETIRO', 'TRANSFERENCIA_SALIDA'].includes(row.movement_type)
    && row.reference_type !== 'PURCHASE' && !row.reference_type?.startsWith('WALLET_HOLD_')));
const reversible = (row) => row.status === 'COMPLETADA' && ['PAGO', 'TRANSFERENCIA_SALIDA', 'TRANSFERENCIA_ENTRADA'].includes(row.movement_type)
    && row.reference_type !== 'PURCHASE' && !row.reference_type?.startsWith('WALLET_HOLD_');
const selectedPurchase = computed(() => props.purchases.find((row) => row.id === form.selectedId));
const cents = (input) => {
    const text = String(input).trim().replace(',', '.');
    if (!/^\d+(\.\d{1,2})?$/.test(text)) throw new Error('Ingresa un importe con hasta dos decimales.');
    const [whole, fraction = ''] = text.split('.');
    const value = Number(whole) * 100 + Number(fraction.padEnd(2, '0'));
    if (!Number.isSafeInteger(value)) throw new Error('El importe está fuera del rango permitido.');
    return value;
};
function open(kind, title, row = {}, action = '') {
    error.value = ''; notice.value = '';
    Object.assign(form, { kind, title, id: row.id || '', action, key: crypto.randomUUID(), reason: '', amount: '', selectedId: '',
        walletAmount: '0', bonusAmounts: {}, operationType: row.operation_type || '', maxSeconds: row.max_duration_seconds || '',
        active: row.active ?? true, version: row.version || 1, receipt: '' });
    dialog.value.showModal();
}
function close() { if (!busy.value) dialog.value.close(); }
async function submit() {
    if (busy.value) return;
    error.value = '';
    let url; let method = 'post'; let payload = { reason: form.reason };
    try {
        if (!form.reason.trim()) throw new Error('Escribe el motivo de la operación.');
        switch (form.kind) {
        case 'request-refund':
            url = route('financial.adjustments.refunds.store');
            payload = { ...payload, original_transaction_id: form.selectedId, amount_cents: cents(form.amount) }; break;
        case 'request-purchase':
            url = route('financial.adjustments.purchase-refunds.store');
            payload = { ...payload, purchase_payment_id: form.selectedId, wallet_amount_cents: cents(form.walletAmount),
                bonus_refunds: Object.entries(form.bonusAmounts).filter(([, amount]) => String(amount).trim() && cents(amount) > 0)
                    .map(([bonus_id, amount]) => ({ bonus_id, amount_cents: cents(amount) })) }; break;
        case 'refund':
            url = route('financial.adjustments.refunds.action', { refundId: form.id, action: form.action });
            if (form.action === 'recover') payload.recovery_reference = form.receipt; break;
        case 'purchase-refund': url = route('financial.adjustments.purchase-refunds.action', { refundId: form.id, action: form.action }); break;
        case 'hold': url = route('financial.adjustments.holds.action', { holdId: form.id, action: form.action }); break;
        case 'reverse': url = route('financial.adjustments.reverse', { transactionId: form.id }); break;
        case 'policy':
            payload = { ...payload, max_duration_seconds: Number(form.maxSeconds), active: form.active };
            if (form.id) { url = route('financial.adjustments.policies.update', { policyId: form.id }); method = 'patch'; payload.expected_version = form.version; }
            else { url = route('financial.adjustments.policies.store'); payload.operation_type = form.operationType; }
            break;
        }
        busy.value = true;
        await axios({ method, url, data: payload, headers: { 'Idempotency-Key': form.key } });
        dialog.value.close(); notice.value = 'Operación registrada correctamente.';
        router.reload({ preserveScroll: true });
    } catch (exception) {
        const validation = exception.response?.data?.errors;
        error.value = validation ? Object.values(validation).flat().join(' ') : exception.response?.data?.message
            || (exception.response ? 'No se pudo realizar la operación.' : exception.message || 'No pudimos confirmar el resultado. Puedes reintentar esta misma solicitud.');
    } finally { busy.value = false; }
}
async function loadHistory(policy, page = 1) {
    if (busy.value) return;
    busy.value = true; error.value = '';
    try {
        const response = await axios.get(route('financial.adjustments.policies.history', { policyId: policy.id }), { params: { page } });
        history.value = { policy, rows: response.data.data }; historyPage.value = page;
        historyTotal.value = response.data.meta.pagination.total;
    } catch (exception) { error.value = exception.response?.data?.message || 'No se pudo consultar el historial.'; }
    finally { busy.value = false; }
}
function selectWallet() {
    router.get(route('financial.adjustments.index'), { wallet_id: lookupWallet.value }, { preserveScroll: true });
}
</script>

<template>
    <Head title="Retenciones y devoluciones" />
    <AuthenticatedLayout>
        <template #header>
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div><p class="text-xs font-bold uppercase tracking-widest text-sky-600">Finanzas · 2.7</p><h1 class="mt-1 text-2xl font-bold text-[#00338D]">Retenciones y devoluciones</h1></div>
                <Link :href="route('financial.dashboard')" class="text-sm font-semibold text-blue-700 hover:underline">Volver a Finanzas</Link>
            </div>
        </template>
        <main class="min-h-screen bg-[#F5F8FC] px-4 py-7 sm:px-6">
            <div class="mx-auto max-w-7xl space-y-6">
                <p v-if="notice" role="status" class="rounded-xl border border-emerald-200 bg-emerald-50 p-4 text-emerald-800">{{ notice }}</p>
                <p v-if="error && !dialog?.open" role="alert" class="rounded-xl border border-red-200 bg-red-50 p-4 text-red-800">{{ error }}</p>
                <section class="rounded-2xl bg-[#00338D] p-6 text-white">
                    <h2 class="text-lg font-bold">{{ wallet ? 'Saldo de la wallet' : 'Sin wallet asociada' }}</h2>
                    <div v-if="wallet" class="mt-4 grid gap-4 sm:grid-cols-3">
                        <div><p class="text-sm text-blue-100">Disponible</p><p class="mt-1 text-2xl font-bold">{{ money(wallet.available_balance_cents) }}</p></div>
                        <div><p class="text-sm text-blue-100">Retenido</p><p class="mt-1 text-2xl font-bold">{{ money(wallet.held_balance_cents) }}</p></div>
                        <div><p class="text-sm text-blue-100">Wallet</p><p class="mt-1 break-all font-mono text-sm">{{ wallet.id }}</p></div>
                    </div>
                    <p v-else class="mt-2 text-blue-100">Aún no hay una wallet disponible para consultar movimientos.</p>
                </section>
                <form v-if="can('adjustments.view')" @submit.prevent="selectWallet" class="flex flex-wrap gap-3 rounded-xl border bg-white p-4">
                    <label class="grow text-sm">Consultar otra wallet<input v-model="lookupWallet" required placeholder="Identificador de wallet" class="mt-1 block w-full rounded-lg border-slate-300" /></label>
                    <button class="self-end rounded-lg bg-blue-700 px-4 py-2 text-white">Consultar</button>
                </form>
                <nav aria-label="Secciones de ajustes" class="flex flex-wrap gap-2">
                    <button v-for="[key, label] in tabs" :key="key" @click="tab = key; history = null" :aria-pressed="tab === key" :class="tab === key ? 'bg-blue-800 text-white' : 'bg-white text-slate-700 hover:bg-blue-50'" class="rounded-xl border px-4 py-3 text-sm font-semibold">{{ label }}</button>
                </nav>
                <section class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm sm:p-6">
                    <p class="mb-4 text-xs text-slate-500">Se muestran hasta 50 registros recientes por listado. Los importes se presentan en {{ wallet?.currency || 'MXN' }}.</p>
                    <template v-if="tab === 'holds'">
                        <h2 class="text-lg font-bold text-[#00338D]">Retenciones</h2><p class="mt-1 text-sm text-slate-500">El saldo reservado se libera al cancelar o vencer; al capturar se utiliza una sola vez.</p>
                        <p v-if="!holds.length" class="py-10 text-center text-slate-500">No hay retenciones registradas.</p>
                        <article v-for="row in holds" :key="row.id" class="mt-4 rounded-xl border p-4">
                            <div class="flex flex-wrap justify-between gap-3"><div><p class="font-semibold">{{ row.operation_type }} · {{ money(row.amount_cents) }}</p><p class="mt-1 text-sm text-slate-500">{{ row.status }} · Vence {{ date(row.expires_at) }}</p></div><p class="font-mono text-xs text-slate-500">{{ short(row.id) }}</p></div>
                            <p class="mt-2 text-sm">{{ row.reason }}</p>
                            <div v-if="row.status === 'ACTIVA'" class="mt-3 flex flex-wrap gap-2">
                                <button :disabled="!can('hold.release')" @click="open('hold', 'Liberar retención', row, 'release')" class="action">Liberar</button>
                                <button :disabled="!can('hold.capture') || new Date(row.expires_at) <= new Date()" @click="open('hold', 'Capturar retención', row, 'capture')" class="action">Capturar</button>
                            </div>
                        </article>
                    </template>
                    <template v-if="tab === 'refunds'">
                        <div class="flex flex-wrap items-center justify-between gap-3"><h2 class="text-lg font-bold text-[#00338D]">Solicitudes de devolución</h2><div class="flex flex-wrap gap-2">
                            <button :disabled="!canRequest || !refundable.length" @click="open('request-refund', 'Solicitar devolución')" class="action">Solicitar devolución</button>
                            <button :disabled="!canRequest || !purchases.length" @click="open('request-purchase', 'Devolver compra con bonos')" class="action">Compra con bonos</button>
                        </div></div>
                        <p class="mt-2 text-sm text-slate-500">Solicitar una devolución no modifica los saldos. La solicitud debe revisarse antes de ejecutarse.</p>
                        <p v-if="!refunds.length && !purchaseRefunds.length" class="py-10 text-center text-slate-500">No hay solicitudes registradas.</p>
                        <article v-for="row in [...refunds.map(r => ({ ...r, kind: 'refund' })), ...purchaseRefunds.map(r => ({ ...r, kind: 'purchase-refund' }))]" :key="row.id" class="mt-4 rounded-xl border p-4">
                            <div class="flex flex-wrap justify-between gap-2"><h3 class="font-semibold">{{ row.kind === 'refund' ? 'Devolución' : 'Compra con bonos' }} · {{ money(row.amount_cents ?? row.total_amount_cents) }}</h3><span class="text-sm font-semibold text-blue-800">{{ row.status }}</span></div>
                            <p v-if="row.kind === 'purchase-refund'" class="mt-1 text-sm text-slate-500">Wallet: {{ money(row.wallet_amount_cents) }} · Bonos: {{ money(row.bonus_amount_cents) }}</p>
                            <p class="mt-2 text-sm">{{ row.reason }}</p><p v-if="row.review_reason" class="mt-1 text-sm text-slate-500">Revisión: {{ row.review_reason }}</p>
                            <div class="mt-3 flex flex-wrap gap-2">
                                <template v-if="row.status === 'PENDIENTE'"><button :disabled="!can('refund.review')" @click="open(row.kind, 'Aprobar devolución', row, 'approve')" class="action">Aprobar</button><button :disabled="!can('refund.review')" @click="open(row.kind, 'Rechazar devolución', row, 'reject')" class="action">Rechazar</button></template>
                                <template v-if="row.status === 'APROBADA'"><button v-if="row.kind === 'refund' && row.is_withdrawal" :disabled="!can('refund.recover')" @click="open(row.kind, 'Confirmar recuperación de un retiro', row, 'recover')" class="action">Registrar recuperación de retiro</button><button :disabled="!can('refund.execute')" @click="open(row.kind, 'Completar devolución', row, 'complete')" class="action">Completar</button></template>
                            </div>
                        </article>
                    </template>
                    <template v-if="tab === 'transactions'">
                        <h2 class="text-lg font-bold text-[#00338D]">Operaciones y reversos</h2><p class="mt-1 text-sm text-slate-500">Un reverso compensa una operación completada y conserva su historial. Requiere autorización administrativa.</p>
                        <p v-if="!transactions.length" class="py-10 text-center text-slate-500">No hay movimientos registrados.</p>
                        <article v-for="row in transactions" :key="row.id" class="mt-4 flex flex-wrap items-center justify-between gap-3 rounded-xl border p-4">
                            <div><p class="font-semibold">{{ row.movement_type }} · {{ money(row.amount_cents) }}</p><p class="mt-1 text-xs text-slate-500">{{ short(row.id) }} · {{ row.status }} · {{ date(row.created_at) }}</p></div>
                            <button :disabled="!can('transaction.reverse') || !reversible(row)" @click="open('reverse', 'Confirmar reverso de operación', row)" class="action">Reversar</button>
                        </article>
                    </template>
                    <template v-if="tab === 'policies'">
                        <div class="flex flex-wrap items-center justify-between gap-3"><h2 class="text-lg font-bold text-[#00338D]">Plazos por operación</h2><button :disabled="!can('policy.manage')" @click="open('policy', 'Crear política de retención')" class="action">Nueva política</button></div>
                        <p class="mt-2 text-sm text-slate-500">Los cambios aplican a futuras retenciones. Las existentes conservan su fecha de vencimiento.</p>
                        <p v-if="!can('policy.view')" class="my-6 rounded-xl bg-slate-50 p-4 text-sm text-slate-600">La consulta y administración de políticas requieren permisos específicos.</p>
                        <p v-else-if="!policies.length" class="py-10 text-center text-slate-500">No hay políticas configuradas.</p>
                        <article v-for="row in policies" :key="row.id" class="mt-4 rounded-xl border p-4"><p class="font-semibold">{{ row.operation_type }} · {{ row.max_duration_seconds }} segundos</p><p class="mt-1 text-sm text-slate-500">{{ row.active ? 'Activa' : 'Deshabilitada' }} · Versión {{ row.version }}</p><div class="mt-3 flex gap-2"><button :disabled="!can('policy.manage')" @click="open('policy', 'Editar política', row)" class="action">Editar</button><button :disabled="!can('policy.view') || busy" @click="loadHistory(row)" class="action">Historial</button></div></article>
                        <section v-if="history" class="mt-5 rounded-xl bg-slate-50 p-4"><h3 class="font-bold">Historial · {{ history.policy.operation_type }}</h3><article v-for="row in history.rows" :key="row.id" class="mt-3 border-t pt-3 text-sm"><p class="font-semibold">Versión {{ row.version }} · {{ row.change_type }}</p><p>{{ row.reason }}</p><p class="mt-1 text-xs text-slate-500">{{ row.actor_id }} · {{ date(row.created_at) }}</p><p class="mt-1">Plazo: {{ row.before?.max_duration_seconds ?? 'Sin política anterior' }} → {{ row.after.max_duration_seconds }} segundos</p><p>Estado: {{ row.before ? (row.before.active ? 'Activa' : 'Deshabilitada') : 'Sin política anterior' }} → {{ row.after.active ? 'Activa' : 'Deshabilitada' }}</p></article><div class="mt-4 flex gap-3"><button :disabled="historyPage === 1 || busy" @click="loadHistory(history.policy, historyPage - 1)" class="action">Anterior</button><button :disabled="historyPage * 20 >= historyTotal || busy" @click="loadHistory(history.policy, historyPage + 1)" class="action">Siguiente</button></div></section>
                    </template>
                    <p class="mt-5 border-t pt-4 text-xs text-slate-500">Las acciones administrativas solo están disponibles para usuarios autorizados.</p>
                </section>
            </div>
        </main>
        <dialog ref="dialog" @cancel="busy && $event.preventDefault()" class="w-[min(94vw,36rem)] rounded-2xl p-0 shadow-xl backdrop:bg-slate-900/50">
            <form @submit.prevent="submit" class="space-y-4 p-6">
                <h2 class="text-xl font-bold text-[#00338D]">{{ form.title }}</h2>
                <p v-if="error" role="alert" class="rounded-lg bg-red-50 p-3 text-sm text-red-800">{{ error }}</p>
                <template v-if="form.kind === 'request-refund'"><label class="field">Operación original<select v-model="form.selectedId" required><option value="">Selecciona una operación</option><option v-for="row in refundable" :key="row.id" :value="row.id">{{ row.movement_type }} · {{ money(-row.amount_cents) }} · {{ short(row.id) }}</option></select></label><label class="field">Importe a devolver<input v-model="form.amount" inputmode="decimal" placeholder="0.00" required /></label></template>
                <template v-if="form.kind === 'request-purchase'"><label class="field">Compra original<select v-model="form.selectedId" @change="form.bonusAmounts = {}; form.walletAmount = '0'" required><option value="">Selecciona una compra</option><option v-for="row in purchases" :key="row.id" :value="row.id">{{ short(row.id) }} · {{ money(row.total_amount_cents) }}</option></select></label><template v-if="selectedPurchase"><label class="field">Parte a devolver a la wallet (pagado {{ money(selectedPurchase.wallet_amount_cents) }})<input v-model="form.walletAmount" inputmode="decimal" required /></label><label v-for="line in selectedPurchase.bonuses" :key="line.bonus_id" class="field">Bono {{ short(line.bonus_id) }} (pagado {{ money(line.amount_cents) }})<input v-model="form.bonusAmounts[line.bonus_id]" inputmode="decimal" placeholder="0.00" /></label><p class="text-xs text-slate-500">Cada parte vuelve a su fuente original. Deja en cero las partes que no quieras devolver.</p></template></template>
                <template v-if="form.kind === 'policy'"><label class="field">Tipo de operación<input v-model="form.operationType" :disabled="Boolean(form.id)" required placeholder="COMPRA_PENDIENTE" /></label><label class="field">Duración máxima (segundos)<input v-model="form.maxSeconds" type="number" min="1" max="2147483647" step="1" required /></label><label class="flex items-center gap-2 text-sm"><input v-model="form.active" type="checkbox" class="rounded border-slate-300" />Habilitar nuevas retenciones de esta operación</label></template>
                <label v-if="form.action === 'recover'" class="field">Referencia de recuperación externa<input v-model="form.receipt" required maxlength="255" /></label>
                <p v-if="form.kind === 'reverse'" class="rounded-lg bg-amber-50 p-3 text-sm text-amber-900">Se registrarán movimientos compensatorios. Las compras con bonos, retiros y retenciones requieren sus flujos específicos.</p>
                <label class="field">Motivo<textarea v-model="form.reason" required maxlength="1000" rows="3"></textarea></label>
                <div class="flex justify-end gap-3"><button type="button" :disabled="busy" @click="close" class="action">Cancelar</button><button :disabled="busy" class="rounded-lg bg-blue-800 px-4 py-2 text-sm font-semibold text-white disabled:opacity-50">{{ busy ? 'Procesando…' : 'Confirmar' }}</button></div>
            </form>
        </dialog>
    </AuthenticatedLayout>
</template>

<style scoped>
.action { @apply rounded-lg border border-blue-200 px-3 py-2 text-sm font-semibold text-blue-800 hover:bg-blue-50 disabled:cursor-not-allowed disabled:border-slate-200 disabled:bg-slate-50 disabled:text-slate-400; }
.field { @apply block text-sm font-medium text-slate-700; }
.field input, .field select, .field textarea { @apply mt-1 block w-full rounded-lg border-slate-300 text-sm focus:border-blue-500 focus:ring-blue-500; }
</style>
