<script setup>
import { computed, onMounted, reactive, ref } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import axios from 'axios';
const props = defineProps({ wallet: { type: Object, default: null }, policy: Object, permissions: Object });
const policy = ref({ ...props.policy });
const form = reactive({ identity_method: 'USER_ID', recipient: '', amount: '', kind: 'TRANSFERENCIA', concept: '' });
const busy = ref(true), error = ref(''), notice = ref(''), confirmation = ref(null), result = ref(null), prepareAttempt = ref(null), confirmKey = ref(null);
const pendingRows = ref([]), pendingMeta = ref({ current_page: 1, last_page: 1 });
const rows = ref([]), meta = ref({ current_page: 1, last_page: 1, total: 0 }), audit = ref([]), auditMeta = ref({ current_page: 1, last_page: 1 });
const edits = reactive({ minimum: '', maximum: '', daily: '', monthly: '', confirmation_seconds: '', enabled: true, reason: '', expected_version: 0 });
const frozen = computed(() => prepareAttempt.value !== null);
const money = (cents) => new Intl.NumberFormat('es-MX', { style: 'currency', currency: 'MXN' }).format(cents / 100);
const date = (value) => value ? new Intl.DateTimeFormat('es-MX', { dateStyle: 'medium', timeStyle: 'short', timeZone: policy.value.business_timezone }).format(new Date(value)) : '';
const cents = (value) => {
    const text = String(value).trim();
    if (!/^\d+(\.\d{1,2})?$/.test(text)) throw new Error('Escribe un importe positivo con hasta dos decimales.');
    const [whole, decimal = ''] = text.split('.'); const number = Number(whole) * 100 + Number(decimal.padEnd(2, '0'));
    if (!Number.isSafeInteger(number) || number < 1) throw new Error('El importe no es válido.');
    return number;
};
const message = (e) => e.response?.data?.message || e.message || 'No se recibió respuesta. Reintenta con la misma solicitud.';
function fillPolicy() {
    for (const [target, source] of [['minimum', 'minimum_cents'], ['maximum', 'maximum_cents'], ['daily', 'daily_cents'], ['monthly', 'monthly_cents']]) edits[target] = (policy.value[source] / 100).toFixed(2);
    edits.enabled = policy.value.enabled; edits.confirmation_seconds = policy.value.confirmation_seconds; edits.expected_version = policy.value.version; edits.reason = '';
}
async function records(page = 1) {
    const { data } = await axios.get(route('financial.transfers.records'), { params: { page } }); rows.value = data.data; meta.value = data.meta;
}
async function pendingRecords(page = 1) { const { data } = await axios.get(route('financial.transfers.pending'), { params: { page } }); pendingRows.value = data.data; pendingMeta.value = data.meta; }
async function pendingPage(page) { busy.value = true; error.value = ''; try { await pendingRecords(page); } catch (e) { error.value = message(e); } finally { busy.value = false; } }
async function resume(id) { busy.value = true; error.value = ''; try { const { data } = await axios.get(route('financial.transfers.confirmation', id)); confirmation.value = data.data; prepareAttempt.value = { resumed: true }; confirmKey.value = confirmation.value.retry_key || crypto.randomUUID(); result.value = null; notice.value = 'Revisa los datos de esta preparación antes de confirmarla.'; } catch (e) { error.value = message(e); } finally { busy.value = false; } }
async function loadRecords(page) { busy.value = true; error.value = ''; try { await records(page); } catch (e) { error.value = message(e); } finally { busy.value = false; } }
async function prepare() {
    busy.value = true; error.value = ''; notice.value = '';
    try {
        if (!prepareAttempt.value) {
            prepareAttempt.value = { key: crypto.randomUUID(), payload: { identity_method: form.identity_method, recipient: form.recipient.trim(), amount_cents: cents(form.amount), kind: form.kind, concept: form.concept.trim() || null } };
        }
        const { data } = await axios.post(route('financial.transfers.prepare'), prepareAttempt.value.payload, { headers: { 'Idempotency-Key': prepareAttempt.value.key } });
        confirmation.value = data.data;
        if (!confirmKey.value) confirmKey.value = confirmation.value.retry_key || crypto.randomUUID();
        notice.value = 'Comprueba el nombre, matrícula, monto y concepto antes de confirmar.';
        try { await pendingRecords(); } catch { notice.value += ' Actualiza las preparaciones pendientes cuando haya conexión.'; }
    } catch (e) { error.value = message(e); } finally { busy.value = false; }
}
async function confirm() {
    busy.value = true; error.value = ''; notice.value = '';
    try {
        const { data } = await axios.post(route('financial.transfers.confirm', confirmation.value.id), { confirmed: true }, { headers: { 'Idempotency-Key': confirmKey.value } });
        result.value = data.data; confirmation.value.status = 'COMPLETADA'; notice.value = data.replayed ? 'El envío ya estaba registrado. Se conservó la misma operación.' : 'Envío completado.';
        try { await records(); await pendingRecords(); } catch { notice.value += ' Actualiza el historial para consultar el registro.'; }
        router.reload({ only: ['wallet', 'policy', 'permissions'], onSuccess: (page) => { policy.value = { ...page.props.policy }; fillPolicy(); } });
    } catch (e) { error.value = message(e); } finally { busy.value = false; }
}
async function restart() {
    busy.value = true; error.value = '';
    try {
        if (confirmation.value && confirmation.value.status !== 'COMPLETADA' && confirmation.value.status !== 'CANCELADA') {
            await axios.post(route('financial.transfers.cancel', confirmation.value.id));
        }
        prepareAttempt.value = null; confirmKey.value = null; confirmation.value = null; result.value = null; notice.value = ''; await pendingRecords();
    } catch (e) { error.value = message(e); } finally { busy.value = false; }
}
async function updatePolicy() {
    busy.value = true; error.value = ''; notice.value = '';
    try {
        const seconds = Number(edits.confirmation_seconds);
        if (!Number.isInteger(seconds) || seconds < 1 || seconds > 2147483647) throw new Error('El plazo debe ser un entero positivo de segundos.');
        const payload = { expected_version: edits.expected_version, reason: edits.reason.trim(), enabled: edits.enabled, confirmation_seconds: seconds,
            minimum_cents: cents(edits.minimum), maximum_cents: cents(edits.maximum), daily_cents: cents(edits.daily), monthly_cents: cents(edits.monthly) };
        const { data } = await axios.patch(route('financial.transfers.policy.update'), payload); policy.value = data.data; fillPolicy(); notice.value = 'Política guardada con historial y nueva versión.';
        try { await loadAudit(); } catch { notice.value += ' Actualiza la página para consultar el historial.'; }
    } catch (e) { error.value = message(e); } finally { busy.value = false; }
}
async function loadAudit(page = 1) {
    const { data } = await axios.get(route('financial.transfers.policy.history'), { params: { page } }); audit.value = data.data; auditMeta.value = data.meta;
}
async function auditPage(page) { busy.value = true; error.value = ''; try { await loadAudit(page); } catch (e) { error.value = message(e); } finally { busy.value = false; } }
onMounted(async () => { fillPolicy(); try { await records(); await pendingRecords(); if (props.permissions.manage) await loadAudit(); } catch (e) { error.value = message(e); } finally { busy.value = false; } });
</script>
<template>
    <Head title="Transferencias y regalos" />
    <AuthenticatedLayout>
        <template #header><div class="flex flex-wrap items-center justify-between gap-3"><div><p class="text-xs font-bold uppercase tracking-[0.2em] text-[#0284C7]">Finanzas · 2.6</p><h2 class="mt-1 text-2xl font-bold text-[#00338D]">Transferencias y regalos</h2></div><Link :href="route('financial.dashboard')" class="text-sm font-semibold text-[#00338D]">Volver a Finanzas</Link></div></template>
        <div class="min-h-screen bg-[#F5F8FC] px-4 py-8 sm:px-6"><div class="mx-auto max-w-6xl space-y-6">
            <section class="rounded-2xl bg-[#00338D] p-6 text-white"><h3 class="text-xl font-bold">Envía saldo disponible de tu wallet</h3><p class="mt-2 text-sm text-blue-100">Las transferencias y regalos utilizan dinero de wallet. Los bonos y el saldo retenido conservan sus condiciones.</p><p v-if="wallet" class="mt-4 text-2xl font-bold">Disponible: {{ money(wallet.available_balance_cents) }}</p><p v-else class="mt-4">Se necesita una wallet personal MXN identificada de forma única.</p></section>
            <div v-if="error" role="alert" class="rounded-xl border border-red-200 bg-red-50 p-4 text-red-800">{{ error }}</div>
            <div v-if="notice" role="status" class="rounded-xl border border-green-200 bg-green-50 p-4 text-green-800">{{ notice }}</div>
            <section class="rounded-2xl border border-slate-200 bg-white p-6"><h3 class="text-lg font-bold text-[#00338D]">Límites vigentes</h3><dl class="mt-4 grid gap-4 sm:grid-cols-4"><div><dt class="text-sm text-slate-500">Mínimo</dt><dd class="font-bold">{{ money(policy.minimum_cents) }}</dd></div><div><dt class="text-sm text-slate-500">Máximo por envío</dt><dd class="font-bold">{{ money(policy.maximum_cents) }}</dd></div><div><dt class="text-sm text-slate-500">Diario</dt><dd class="font-bold">{{ money(policy.daily_cents) }}</dd></div><div><dt class="text-sm text-slate-500">Mensual</dt><dd class="font-bold">{{ money(policy.monthly_cents) }}</dd></div></dl><p class="mt-3 text-sm text-slate-500">Transferencias y regalos comparten los acumulados. Calendario: {{ policy.business_timezone }}. Confirmación vigente durante {{ policy.confirmation_seconds }} segundos. Versión {{ policy.version }}.</p></section>
            <section class="rounded-2xl border border-slate-200 bg-white p-6"><h3 class="text-lg font-bold text-[#00338D]">Preparar envío</h3>
                <p v-if="!permissions.send" class="mt-4 rounded-xl bg-blue-50 p-4 text-sm text-[#00338D]">Tu cuenta todavía no tiene habilitado el envío. La autorización debe proceder de la integración de permisos.</p>
                <p v-if="!policy.enabled" class="mt-3 text-sm text-amber-800">La política vigente tiene deshabilitados los nuevos envíos.</p>
                <form v-if="permissions.send && policy.enabled" class="mt-5 space-y-4" @submit.prevent="prepare">
                    <fieldset :disabled="busy || frozen" class="grid gap-4 sm:grid-cols-2">
                        <label class="text-sm">Tipo<select v-model="form.kind" class="mt-1 block w-full rounded-lg border-slate-300"><option value="TRANSFERENCIA">Transferencia</option><option value="REGALO">Regalo</option></select></label>
                        <label class="text-sm">Identificar por<select v-model="form.identity_method" class="mt-1 block w-full rounded-lg border-slate-300"><option value="USER_ID">Identificador de usuario</option><option value="ENROLLMENT">Matrícula exacta</option><option value="QR">Código QR de identidad</option></select></label>
                        <label class="text-sm">Destinatario<input v-model="form.recipient" required maxlength="500" autocomplete="off" class="mt-1 block w-full rounded-lg border-slate-300" /><span class="mt-1 block text-xs text-slate-500">Usuario: identificador de cuenta de 24 caracteres. QR: código generado por Identidad QR, sujeto a disponibilidad del servicio.</span></label>
                        <label class="text-sm">Importe en MXN<input v-model="form.amount" required inputmode="decimal" placeholder="100.00" class="mt-1 block w-full rounded-lg border-slate-300" /></label>
                        <label class="text-sm sm:col-span-2">Concepto o mensaje opcional<input v-model="form.concept" maxlength="255" class="mt-1 block w-full rounded-lg border-slate-300" /></label>
                    </fieldset>
                    <button :disabled="busy || !!confirmation" class="rounded-lg bg-[#00338D] px-5 py-3 font-semibold text-white disabled:opacity-50">{{ frozen ? 'Reintentar preparación' : 'Revisar destinatario y envío' }}</button>
                </form>
                <section v-if="confirmation" class="mt-6 rounded-xl border border-blue-200 bg-blue-50 p-5"><h4 class="font-bold text-[#00338D]">Revisa antes de confirmar</h4><dl class="mt-3 grid gap-3 sm:grid-cols-2"><div><dt class="text-sm text-slate-500">Destinatario</dt><dd class="font-semibold">{{ confirmation.recipient.name }}</dd><dd class="break-all text-xs">{{ confirmation.recipient.user_id }}</dd></div><div><dt class="text-sm text-slate-500">Matrícula</dt><dd>{{ confirmation.recipient.enrollment_number || 'Sin matrícula registrada' }}</dd></div><div><dt class="text-sm text-slate-500">Tipo e importe</dt><dd class="font-bold">{{ confirmation.kind }} · {{ money(confirmation.amount_cents) }}</dd></div><div><dt class="text-sm text-slate-500">Concepto</dt><dd>{{ confirmation.concept || 'Sin concepto' }}</dd></div></dl><p class="mt-3 text-sm">{{ confirmation.status }} · Vigencia: {{ date(confirmation.expires_at) }}</p><p class="mt-2 text-sm text-slate-600">Al confirmar se revisan de nuevo saldo, permisos y límites vigentes. Un envío completado conserva su historial y requiere un proceso autorizado para revertirse.</p><button v-if="confirmation.status === 'PENDIENTE'" :disabled="busy" class="mt-4 rounded-lg bg-[#00338D] px-5 py-3 font-semibold text-white disabled:opacity-50" @click="confirm">{{ confirmKey ? 'Confirmar o reintentar este envío' : 'Confirmar envío' }}</button><p v-if="result" class="mt-3 break-all text-sm">Operación: {{ result.id }}</p></section>
                <button v-if="frozen" :disabled="busy" class="mt-4 rounded-lg border border-slate-300 px-4 py-2 text-sm disabled:opacity-50" @click="restart">{{ confirmation && confirmation.status !== 'COMPLETADA' ? 'Cancelar preparación y empezar otra' : 'Preparar otra solicitud' }}</button>
            </section>
            <section v-if="pendingRows.length" class="rounded-2xl border border-slate-200 bg-white p-6"><h3 class="text-lg font-bold text-[#00338D]">Preparaciones pendientes</h3><p class="mt-2 text-sm text-slate-500">Puedes retomar una solicitud después de recargar la página. Prepararla no reserva ni mueve dinero.</p><article v-for="item in pendingRows" :key="item.id" class="mt-3 flex flex-wrap items-center justify-between gap-3 rounded-xl border p-4"><div><p class="font-semibold">{{ item.recipient.name }} · {{ money(item.amount_cents) }}</p><p class="text-sm">{{ item.kind }} · {{ item.status }} · {{ date(item.expires_at) }}</p></div><button :disabled="busy || !!confirmation" class="rounded-lg border border-[#00338D] px-4 py-2 text-sm text-[#00338D] disabled:opacity-50" @click="resume(item.id)">Revisar solicitud</button></article><div class="mt-4 flex justify-between text-sm"><button :disabled="busy || pendingMeta.current_page <= 1" @click="pendingPage(pendingMeta.current_page - 1)">Anterior</button><span>{{ pendingMeta.current_page }} / {{ pendingMeta.last_page }}</span><button :disabled="busy || pendingMeta.current_page >= pendingMeta.last_page" @click="pendingPage(pendingMeta.current_page + 1)">Siguiente</button></div></section>
            <section class="rounded-2xl border border-slate-200 bg-white p-6"><div class="flex justify-between gap-3"><h3 class="text-lg font-bold text-[#00338D]">Mis envíos y recepciones</h3><button :disabled="busy" class="text-sm text-[#00338D]" @click="loadRecords(meta.current_page)">Actualizar</button></div><p v-if="!rows.length" class="py-8 text-center text-slate-500">Todavía no hay transferencias o regalos registrados.</p><article v-for="row in rows" :key="row.id" class="mt-4 rounded-xl border border-slate-200 p-4"><div class="flex flex-wrap justify-between gap-3"><strong>{{ row.kind }} · {{ row.direction }}</strong><strong :class="row.direction === 'ENTRADA' ? 'text-emerald-600' : 'text-[#00338D]'">{{ row.direction === 'ENTRADA' ? '+' : '-' }}{{ money(row.amount_cents) }}</strong></div><p class="mt-2 text-sm">{{ row.concept || 'Sin concepto' }} · {{ row.status }}</p><p class="mt-1 text-xs text-slate-500">{{ date(row.completed_at) }} · {{ row.id }}</p><p class="mt-1 break-all text-xs text-slate-500">{{ row.direction === 'SALIDA' ? 'Destinatario' : 'Emisor' }}: {{ row.direction === 'SALIDA' ? row.recipient_id : row.sender_id }}</p></article><div class="mt-4 flex items-center justify-between text-sm"><button :disabled="busy || meta.current_page <= 1" @click="loadRecords(meta.current_page - 1)">Anterior</button><span>Página {{ meta.current_page }} de {{ meta.last_page }} · {{ meta.total }} registros</span><button :disabled="busy || meta.current_page >= meta.last_page" @click="loadRecords(meta.current_page + 1)">Siguiente</button></div></section>
            <section v-if="permissions.manage" class="rounded-2xl border border-slate-200 bg-white p-6"><h3 class="text-lg font-bold text-[#00338D]">Administrar política</h3><p class="mt-2 text-sm text-slate-500">Los nuevos límites se validan también al confirmar solicitudes pendientes. El plazo cambiado aplica a nuevas preparaciones.</p><form class="mt-4 grid gap-4 sm:grid-cols-2" @submit.prevent="updatePolicy"><label v-for="[field, label] in [['minimum', 'Mínimo MXN'], ['maximum', 'Máximo por envío MXN'], ['daily', 'Diario MXN'], ['monthly', 'Mensual MXN']]" :key="field" class="text-sm">{{ label }}<input v-model="edits[field]" required :disabled="busy" inputmode="decimal" class="mt-1 block w-full rounded-lg border-slate-300" /></label><label class="text-sm">Vigencia de confirmación en segundos<input v-model="edits.confirmation_seconds" required :disabled="busy" inputmode="numeric" class="mt-1 block w-full rounded-lg border-slate-300" /></label><label class="flex items-center gap-2 text-sm"><input v-model="edits.enabled" :disabled="busy" type="checkbox" />Habilitar nuevos envíos</label><label class="text-sm sm:col-span-2">Motivo del cambio<textarea v-model="edits.reason" required maxlength="1000" :disabled="busy" class="mt-1 block w-full rounded-lg border-slate-300" /></label><button :disabled="busy" class="rounded-lg bg-[#00338D] px-5 py-3 font-semibold text-white disabled:opacity-50">Guardar versión {{ edits.expected_version }}</button><button type="button" :disabled="busy" class="text-sm text-[#00338D]" @click="router.reload()">Consultar versión vigente</button></form><h4 class="mt-6 font-bold text-[#00338D]">Historial de política</h4><article v-for="entry in audit" :key="entry.id" class="mt-3 rounded-xl border p-4"><p class="text-sm font-semibold">Versión {{ entry.version }} · {{ entry.actor_id }}</p><p class="mt-1 text-sm">{{ entry.reason }} · {{ date(entry.created_at) }}</p><details class="mt-2 text-sm"><summary>Ver valores anteriores y nuevos</summary><pre class="mt-2 overflow-auto whitespace-pre-wrap text-xs">{{ JSON.stringify({ anterior: entry.before, nuevo: entry.after }, null, 2) }}</pre></details></article><div class="mt-4 flex justify-between text-sm"><button :disabled="busy || auditMeta.current_page <= 1" @click="auditPage(auditMeta.current_page - 1)">Anterior</button><span>{{ auditMeta.current_page }} / {{ auditMeta.last_page }}</span><button :disabled="busy || auditMeta.current_page >= auditMeta.last_page" @click="auditPage(auditMeta.current_page + 1)">Siguiente</button></div></section>
        </div></div>
    </AuthenticatedLayout>
</template>
