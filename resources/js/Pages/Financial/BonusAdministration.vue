<script setup>
import { ref, onMounted } from 'vue';
import { Head, Link } from '@inertiajs/vue3';
import axios from 'axios';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
const props = defineProps({ permissions: Object, types: Array, userId: String, timezone: String });
const labels = { BECA: 'Beca', CAMPANIA: 'Campaña', BENEFICIO: 'Beneficio', NEGOCIO: 'Negocio', CATEGORIA: 'Categoría', ISSUE: 'Emisión', CANCEL: 'Cancelación' };
const form = ref({ beneficiary_id: '', type: 'BENEFICIO', amount: '', valid_from: '', expires_at: '', combinable: false, allows_partial_use: false, external_reference: '', reason: '', restrictions: [] });
const captureZone = Intl.DateTimeFormat().resolvedOptions().timeZone;
const recipient = ref(null); const issueAttempt = ref(null); const issueBusy = ref(false);
const message = ref(''); const error = ref(''); const records = ref([]); const meta = ref(null); const listBusy = ref(false);
const beneficiaryFilter = ref(''); const selected = ref(null); const cancelReason = ref(''); const cancelAttempt = ref(null); const cancelBusy = ref(false);
const audit = ref([]); const auditMeta = ref(null); const auditBusy = ref(false);
const money = (cents, currency = 'MXN') => new Intl.NumberFormat('es-MX', { style: 'currency', currency }).format(cents / 100);
const date = (value) => value ? new Intl.DateTimeFormat('es-MX', { dateStyle: 'medium', timeStyle: 'short', timeZone: props.timezone }).format(new Date(value)) : '—';
const errorText = (e) => Object.values(e.response?.data?.errors || {}).flat().join(' ') || e.response?.data?.message || 'No pudimos confirmar el resultado. Reintenta con la misma solicitud.';
function cents(value) {
    const match = /^(\d{1,13})(?:\.(\d{1,2}))?$/.exec(value.trim());
    const amount = match ? Number(match[1]) * 100 + Number((match[2] || '').padEnd(2, '0')) : NaN;
    if (!Number.isSafeInteger(amount) || amount <= 0) throw new Error('Introduce un importe mayor que cero, con hasta dos decimales.');
    return amount;
}
async function lookup() {
    error.value = ''; const id = form.value.beneficiary_id.trim().toLowerCase(); recipient.value = null;
    try { const r = await axios.get(route('financial.bonuses.admin.beneficiary', id)); if (form.value.beneficiary_id.trim().toLowerCase() === id) recipient.value = r.data.data; }
    catch (e) { error.value = e.response?.status === 404 ? 'No encontramos una cuenta activa con ese identificador.' : errorText(e); }
}
async function load(page = 1) {
    if (!props.permissions.view) return;
    listBusy.value = true;
    try { const r = await axios.get(route('financial.bonuses.admin.records'), { params: { page, beneficiary_id: beneficiaryFilter.value.trim() || undefined } }); records.value = r.data.data; meta.value = r.data.meta; }
    catch (e) { records.value = []; meta.value = null; error.value = errorText(e); }
    finally { listBusy.value = false; }
}
async function issue() {
    if (issueBusy.value) return; error.value = ''; message.value = '';
    try {
        if (!issueAttempt.value) {
            if (!recipient.value || recipient.value.id !== form.value.beneficiary_id.trim().toLowerCase()) throw new Error('Comprueba la cuenta beneficiaria antes de emitir.');
            if (!form.value.valid_from || !form.value.expires_at) throw new Error('Completa las fechas de vigencia.');
            const payload = { ...form.value, beneficiary_id: recipient.value.id, amount_cents: cents(form.value.amount), valid_from: new Date(form.value.valid_from).toISOString(), expires_at: new Date(form.value.expires_at).toISOString(), restrictions: form.value.restrictions.map(row => ({ ...row })) };
            delete payload.amount; issueAttempt.value = { key: crypto.randomUUID(), payload };
        }
        issueBusy.value = true;
        const r = await axios.post(route('financial.bonuses.admin.issue'), issueAttempt.value.payload, { headers: { 'Idempotency-Key': issueAttempt.value.key } });
        message.value = `Emisión registrada${r.data.replayed ? ' (solicitud ya procesada)' : ''}. Bono: ${r.data.data.bonus_id}.`;
        await load();
    } catch (e) { error.value = e.response || axios.isAxiosError(e) ? errorText(e) : e.message; }
    finally { issueBusy.value = false; }
}
function newIssue() { issueAttempt.value = null; message.value = ''; error.value = ''; }
async function showAudit(bonus, page = 1) {
    if (selected.value?.id !== bonus.id) { cancelAttempt.value = null; cancelReason.value = ''; }
    selected.value = bonus; audit.value = []; auditMeta.value = null; auditBusy.value = true;
    try { const r = await axios.get(route('financial.bonuses.admin.history', bonus.id), { params: { page } }); if (selected.value?.id === bonus.id) { audit.value = r.data.data; auditMeta.value = r.data.meta; } }
    catch (e) { error.value = errorText(e); }
    finally { auditBusy.value = false; }
}
async function cancel() {
    if (cancelBusy.value) return; error.value = ''; message.value = '';
    if (!cancelAttempt.value) {
        if (!cancelReason.value.trim()) { error.value = 'Explica el motivo de la cancelación.'; return; }
        cancelAttempt.value = { key: crypto.randomUUID(), id: selected.value.id, reason: cancelReason.value };
    }
    cancelBusy.value = true;
    try {
        const attempt = cancelAttempt.value;
        const target = { ...selected.value };
        const r = await axios.post(route('financial.bonuses.admin.cancel', attempt.id), { reason: attempt.reason }, { headers: { 'Idempotency-Key': attempt.key } });
        message.value = `Cancelación registrada${r.data.replayed ? ' (solicitud ya procesada)' : ''}.`;
        await load(); await showAudit({ ...target, status: 'CANCELADO', remaining_amount_cents: 0, can_cancel: false });
    } catch (e) { error.value = errorText(e); }
    finally { cancelBusy.value = false; }
}
onMounted(() => load());
</script>

<template>
    <Head title="Administrar bonos" />
    <AuthenticatedLayout>
        <template #header><div class="flex flex-wrap items-center justify-between gap-3"><div><p class="text-xs font-bold uppercase tracking-widest text-sky-600">Finanzas · 2.5</p><h1 class="mt-1 text-2xl font-bold text-[#00338D]">Administrar bonos</h1></div><Link :href="route('financial.bonuses.index')" class="text-sm font-semibold text-[#00338D]">Volver a Mis bonos</Link></div></template>
        <main class="min-h-screen bg-[#F5F8FC] px-4 py-8 sm:px-6"><div class="mx-auto max-w-7xl space-y-6">
            <p v-if="!permissions.view && !permissions.issue && !permissions.cancel" class="rounded-xl border border-blue-200 bg-blue-50 p-4 text-sm text-[#00338D]">La administración requiere autorización. Tu cuenta puede consultar sus propios bonos en Mis bonos.</p>
            <p v-if="error" role="alert" class="rounded-xl bg-red-50 p-4 text-sm text-red-700">{{ error }}</p><p v-if="message" role="status" class="rounded-xl bg-emerald-50 p-4 text-sm text-emerald-800">{{ message }}</p>
            <section class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
                <h2 class="text-xl font-bold text-[#00338D]">Emitir un bono</h2><p class="mt-2 text-sm text-slate-500">Se registra en MXN. El bono conserva su propio saldo y condiciones.</p>
                <p v-if="!permissions.issue" class="mt-4 text-sm text-slate-500">No tienes permiso para emitir bonos.</p>
                <form v-else @submit.prevent="issue" class="mt-5 space-y-4">
                    <fieldset :disabled="issueBusy || !!issueAttempt" class="grid gap-4 sm:grid-cols-2 disabled:opacity-70">
                        <label class="text-sm text-slate-600">Identificador de cuenta beneficiaria<input v-model="form.beneficiary_id" @input="recipient = null" required maxlength="24" class="mt-1 w-full rounded-lg border-slate-300" /><button type="button" @click="lookup" class="mt-2 text-sm font-semibold text-[#00338D]">Comprobar cuenta</button><span v-if="recipient" class="ml-3 font-semibold text-emerald-700">{{ recipient.name }}</span></label>
                        <label class="text-sm text-slate-600">Tipo<select v-model="form.type" class="mt-1 w-full rounded-lg border-slate-300"><option v-for="value in types" :key="value" :value="value">{{ labels[value] }}</option></select></label>
                        <label class="text-sm text-slate-600">Importe en pesos (MXN)<input v-model="form.amount" required inputmode="decimal" placeholder="Ejemplo: 100.00" class="mt-1 w-full rounded-lg border-slate-300" /></label>
                        <label class="text-sm text-slate-600">Referencia externa (opcional)<input v-model="form.external_reference" maxlength="255" class="mt-1 w-full rounded-lg border-slate-300" /></label>
                        <label class="text-sm text-slate-600">Inicio de vigencia<input v-model="form.valid_from" required type="datetime-local" class="mt-1 w-full rounded-lg border-slate-300" /></label><label class="text-sm text-slate-600">Vencimiento<input v-model="form.expires_at" required type="datetime-local" class="mt-1 w-full rounded-lg border-slate-300" /></label>
                        <p class="text-xs text-slate-500 sm:col-span-2">Zona horaria de captura: {{ captureZone }}. Revisa las fechas antes de emitir.</p>
                        <label class="text-sm"><input v-model="form.combinable" type="checkbox" class="mr-2 rounded" />Puede combinarse con otros bonos</label><label class="text-sm"><input v-model="form.allows_partial_use" type="checkbox" class="mr-2 rounded" />Permite uso parcial</label>
                        <label class="text-sm text-slate-600 sm:col-span-2">Motivo de emisión<textarea v-model="form.reason" required maxlength="1000" class="mt-1 w-full rounded-lg border-slate-300" /></label>
                        <div class="sm:col-span-2"><h3 class="font-semibold text-[#00338D]">Restricciones iniciales</h3><p class="mt-1 text-xs text-slate-500">Negocio y Categoría requieren al menos una restricción del mismo tipo. Usa los identificadores registrados en el sistema correspondiente.</p>
                            <div v-for="(restriction, index) in form.restrictions" :key="index" class="mt-3 flex flex-wrap gap-2"><select v-model="restriction.type" class="rounded-lg border-slate-300 text-sm"><option value="NEGOCIO">Negocio</option><option value="CATEGORIA">Categoría</option></select><input v-model="restriction.target_id" required maxlength="255" placeholder="Identificador del destino" class="flex-1 rounded-lg border-slate-300 text-sm" /><button type="button" @click="form.restrictions.splice(index, 1)" class="text-sm text-red-700">Quitar</button></div>
                            <button type="button" :disabled="form.restrictions.length >= 50" @click="form.restrictions.push({ type: 'NEGOCIO', target_id: '' })" class="mt-3 text-sm font-semibold text-[#00338D]">Agregar restricción</button>
                        </div>
                    </fieldset>
                    <p v-if="issueAttempt" class="text-xs text-slate-500">Esta solicitud conserva los datos originales. Reintentar no emite un segundo bono. «Nueva solicitud» inicia una emisión distinta.</p>
                    <div class="flex flex-wrap gap-3"><button :disabled="issueBusy" class="rounded-lg bg-[#00338D] px-5 py-3 text-sm font-bold text-white disabled:opacity-50">{{ issueBusy ? 'Procesando…' : issueAttempt ? 'Reintentar la misma solicitud' : 'Emitir bono' }}</button><button v-if="issueAttempt" type="button" :disabled="issueBusy" @click="newIssue" class="rounded-lg border border-slate-300 px-4 py-2 text-sm">Nueva solicitud</button></div>
                </form>
            </section>
            <section class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm"><h2 class="text-xl font-bold text-[#00338D]">Bonos y auditoría</h2><p v-if="!permissions.view" class="mt-4 text-sm text-slate-500">No tienes permiso para consultar registros administrativos.</p>
                <template v-else><form @submit.prevent="load(1)" class="mt-4 flex flex-wrap gap-3"><label class="text-sm text-slate-600">Filtrar por cuenta beneficiaria<input v-model="beneficiaryFilter" maxlength="24" class="ml-2 rounded-lg border-slate-300 text-sm" /></label><button :disabled="listBusy" class="rounded-lg border border-[#00338D] px-4 py-2 text-sm font-semibold text-[#00338D]">Buscar</button></form><p v-if="listBusy" role="status" class="mt-4 text-sm text-slate-500">Cargando…</p>
                    <div v-for="bonus in records" :key="bonus.id" class="mt-4 flex flex-wrap items-center justify-between gap-3 rounded-xl border border-slate-200 p-4"><div><p class="font-bold text-[#00338D]">{{ labels[bonus.type] }} · {{ money(bonus.remaining_amount_cents, bonus.currency) }}</p><p class="mt-1 break-all text-xs text-slate-500">{{ bonus.status }} · Cuenta {{ bonus.beneficiary_id }}</p><p class="mt-1 text-xs text-slate-500">Vence: {{ date(bonus.expires_at) }}</p></div><button :disabled="cancelBusy || auditBusy" @click="showAudit(bonus)" class="rounded-lg border border-[#00338D] px-4 py-2 text-sm font-semibold text-[#00338D]">Ver auditoría y acciones</button></div>
                    <p v-if="!records.length && !listBusy" class="mt-5 text-sm text-slate-500">No hay bonos en tu ámbito autorizado.</p><nav v-if="meta && meta.last_page > 1" class="mt-5 flex justify-center gap-4"><button :disabled="listBusy || meta.current_page <= 1" @click="load(meta.current_page - 1)" class="text-sm text-[#00338D] disabled:opacity-40">Anterior</button><span class="text-sm">{{ meta.current_page }} / {{ meta.last_page }}</span><button :disabled="listBusy || meta.current_page >= meta.last_page" @click="load(meta.current_page + 1)" class="text-sm text-[#00338D] disabled:opacity-40">Siguiente</button></nav>
                </template>
            </section>
            <section v-if="selected" class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm"><div class="flex justify-between gap-3"><h2 class="font-bold text-[#00338D]">Auditoría del bono</h2><button @click="selected = null" :disabled="cancelBusy" class="text-sm text-slate-500">Cerrar</button></div><p class="mt-2 break-all text-xs text-slate-500">{{ selected.id }}</p><p v-if="auditBusy" role="status" class="mt-4 text-sm text-slate-500">Cargando auditoría…</p>
                <article v-for="entry in audit" :key="entry.id" class="mt-4 rounded-xl border border-slate-200 p-4"><p class="font-semibold">{{ labels[entry.action] }} · {{ date(entry.created_at) }}</p><p class="mt-1 text-sm text-slate-600">{{ entry.reason }}</p><p class="mt-1 break-all text-xs text-slate-500">Responsable: {{ entry.actor_id }}</p><p class="mt-2 text-sm">Saldo anterior: {{ entry.before ? money(entry.before.remaining_amount_cents, entry.before.currency) : 'Bono nuevo' }} · Saldo posterior: {{ money(entry.after.remaining_amount_cents, entry.after.currency) }} · {{ entry.after.status }}</p><details class="mt-3 text-sm"><summary class="cursor-pointer text-[#00338D]">Condiciones registradas</summary><p class="mt-2">Tipo: {{ labels[entry.after.type] }} · Cuenta: {{ entry.after.beneficiary_id }}</p><p>Vigencia: {{ date(entry.after.valid_from) }} — {{ date(entry.after.expires_at) }}</p><p>Combinable: {{ entry.after.combinable ? 'Sí' : 'No' }} · Uso parcial: {{ entry.after.allows_partial_use ? 'Sí' : 'No' }}</p><p v-for="(restriction, i) in entry.after.restrictions" :key="i" class="break-all">{{ labels[restriction.type] }}: {{ restriction.target_id }}</p></details></article>
                <p v-if="!audit.length && !auditBusy" class="mt-4 text-sm text-slate-500">No hay operaciones administrativas de este módulo para el bono. Se conserva su historial financiero previo.</p><nav v-if="auditMeta && auditMeta.last_page > 1" class="mt-5 flex justify-center gap-4"><button :disabled="cancelBusy || auditBusy || auditMeta.current_page <= 1" @click="showAudit(selected, auditMeta.current_page - 1)">Anterior</button><span>{{ auditMeta.current_page }} / {{ auditMeta.last_page }}</span><button :disabled="cancelBusy || auditBusy || auditMeta.current_page >= auditMeta.last_page" @click="showAudit(selected, auditMeta.current_page + 1)">Siguiente</button></nav>
                <form v-if="selected.can_cancel" @submit.prevent="cancel" class="mt-6 border-t border-slate-200 pt-5"><h3 class="font-bold text-red-700">Cancelar el saldo restante</h3><p class="mt-1 text-sm text-slate-500">La cancelación conserva el historial y no acredita la wallet.</p><label class="mt-3 block text-sm">Motivo<textarea v-model="cancelReason" :disabled="cancelBusy || !!cancelAttempt" required maxlength="1000" class="mt-1 w-full rounded-lg border-slate-300" /></label><button :disabled="cancelBusy" class="mt-3 rounded-lg bg-red-700 px-4 py-2 text-sm font-bold text-white disabled:opacity-50">{{ cancelBusy ? 'Procesando…' : cancelAttempt ? 'Reintentar cancelación' : 'Confirmar cancelación' }}</button></form>
            </section>
        </div></main>
    </AuthenticatedLayout>
</template>
