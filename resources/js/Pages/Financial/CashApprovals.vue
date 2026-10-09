<script setup>
import { computed, reactive, ref } from 'vue';
import { Head, Link } from '@inertiajs/vue3';
import axios from 'axios';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
const input = ref('');
const association = ref('');
const permissions = ref({});
const busy = ref(false);
const error = ref('');
const success = ref('');
const lists = reactive({ policies: [], history: [], requests: [], administrative: [] });
const pages = reactive({});
const pending = ref(null);
const reviewReasons = reactive({});
const form = reactive({ operation: 'WITHDRAWAL', currency: 'MXN', enabled: true, threshold_cents: 1, version: 0, reason: '' });
const base = computed(() => `/finanzas/caja/asociaciones/${encodeURIComponent(association.value)}`);
const urls = { policies: 'approval-policies', history: 'approval-policies/history', requests: 'approval-requests', administrative: 'administrative-approval-requests' };
const operation = value => ({ TOPUP: 'Recarga', WITHDRAWAL: 'Retiro', ADJUSTMENT: 'Ajuste de efectivo', WITHDRAWAL_RECOVERY: 'Recuperación de retiro' })[value] || value;
const date = value => new Date(value).toLocaleString('es-MX');
async function run(task) {
    if (busy.value) return;
    busy.value = true; error.value = ''; success.value = '';
    try { await task(); } catch (e) {
        error.value = Object.values(e.response?.data?.errors || {}).flat().join(' ') || e.response?.data?.message || 'No se pudo completar la solicitud. Conserva los datos para reintentar.';
    } finally { busy.value = false; }
}
async function load(kind, page = 1) {
    const { data } = await axios.get(`${base.value}/${urls[kind]}`, { params: { page, per_page: 10 } });
    lists[kind] = data.data; pages[kind] = data.meta.pagination;
}
function selectPolicy(item = null) {
    if (pending.value) return;
    const found = item || lists.policies.find(p => p.operation === form.operation && p.currency === form.currency);
    if (found) Object.assign(form, found, { reason: '' });
    else Object.assign(form, { enabled: true, threshold_cents: 1, version: 0, reason: '' });
}
async function connect() {
    if (pending.value) return;
    await run(async () => {
        association.value = ''; permissions.value = {}; lists.policies = []; lists.history = []; lists.requests = []; lists.administrative = []; Object.keys(pages).forEach(k => delete pages[k]);
        const { data } = await axios.get('/finanzas/caja/context', { params: { association_id: input.value.trim() } });
        association.value = data.data.association_id; permissions.value = data.data.permissions;
        if (permissions.value.approval_read) { await load('policies'); await load('requests'); await load('administrative'); await load('history'); selectPolicy(); }
    });
}
async function save() {
    if (!permissions.value.approval_manage) return;
    if (!pending.value) {
        if (!Number.isSafeInteger(Number(form.threshold_cents)) || Number(form.threshold_cents) < 1 || !form.reason.trim()) { error.value = 'Indica un umbral entero en centavos y el motivo.'; return; }
        if (!window.confirm(form.enabled ? '¿Guardar esta regla para las nuevas solicitudes de caja?' : '¿Desactivar la segunda autorización para nuevas solicitudes de esta operación y moneda?')) return;
        pending.value = { url: `${base.value}/approval-policies`, key: crypto.randomUUID(), body: { ...form, threshold_cents: Number(form.threshold_cents), version: Number(form.version) } };
    }
    await run(async () => {
        const request = pending.value;
        const { data } = await axios.post(request.url, request.body, { headers: { 'Idempotency-Key': request.key } });
        pending.value = null; Object.assign(form, data.data, { reason: '' }); success.value = 'Política guardada. Se aplica a solicitudes nuevas.';
        if (permissions.value.approval_read) { await load('policies'); await load('history'); }
    });
}
function discard() {
    if (window.confirm('Descartar el reintento no revierte un cambio que el servidor ya haya guardado. Consulta la política antes de volver a editar.')) pending.value = null;
}
async function review(item, decision, kind = 'requests') {
    const reason = (reviewReasons[item.id] || '').trim();
    if (!reason) { error.value = 'Escribe el motivo de la decisión.'; return; }
    if (!window.confirm(`${decision === 'approve' ? 'Autorizar' : 'Rechazar'} ${operation(item.operation)} por ${(item.amount_cents / 100).toFixed(2)} ${item.currency}?`)) return;
    await run(async () => {
        await axios.post(`${base.value}/${kind === 'administrative' ? 'administrative-approval-requests' : 'approval-requests'}/${item.id}/${decision}`, { reason });
        success.value = decision === 'approve' ? kind === 'administrative' ? 'Autorizada. El solicitante todavía debe ejecutar el registro de efectivo.' : 'Autorizada. El operador todavía debe ejecutar la operación con la confirmación del titular vigente.' : 'Solicitud rechazada sin mover dinero.';
        await load(kind, pages[kind]?.current_page || 1);
    });
}
</script>
<template>
    <Head title="Segunda autorización de caja" />
    <AuthenticatedLayout>
        <template #header><h1 class="text-xl font-semibold text-[#00338D]">Segunda autorización de caja</h1></template>
        <main class="mx-auto max-w-7xl space-y-6 px-6 py-8 text-[#00338D]">
            <Link :href="route('financial.cash.index')" class="font-semibold">← Volver a Caja</Link>
            <p>Configura por asociación, operación y moneda qué importes requieren un supervisor. En recargas y retiros, el titular confirma por separado. En ajustes y recuperaciones, la autorización administrativa no registra ni devuelve dinero por sí sola.</p>
            <form class="panel flex flex-wrap gap-3" @submit.prevent="connect"><label class="flex-1">Asociación<input v-model="input" required maxlength="255" :disabled="busy || !!pending" /></label><button :disabled="busy || !!pending">Consultar</button></form>
            <p v-if="error" role="alert" class="rounded-xl bg-red-50 p-4 text-red-800">{{ error }}</p>
            <p v-if="success" role="status" class="rounded-xl bg-green-50 p-4 text-green-800">{{ success }}</p>
            <p v-if="association && !permissions.approval_read" class="panel">No tienes permiso para consultar las políticas ni las solicitudes de esta asociación.</p>
            <section v-if="association && permissions.approval_manage" class="panel space-y-4">
                <h2 class="font-semibold">Configurar regla</h2>
                <p class="text-sm">Sin una regla registrada no se exige supervisor. Umbral inclusivo: 10000 centavos exige autorización desde $100.00. En ajustes se compara el valor absoluto del importe, conservando su signo. Las solicitudes existentes conservan su versión.</p>
                <form class="space-y-4" @submit.prevent="save">
                    <fieldset :disabled="busy || !!pending" class="grid gap-4 sm:grid-cols-3">
                        <label>Operación<select v-model="form.operation" @change="selectPolicy()"><option value="WITHDRAWAL">Retiro</option><option value="TOPUP">Recarga</option><option value="ADJUSTMENT">Ajuste de efectivo</option><option value="WITHDRAWAL_RECOVERY">Recuperación de retiro</option></select></label>
                        <label>Moneda<input v-model="form.currency" required maxlength="3" pattern="[A-Z]{3}" @change="selectPolicy()" /></label>
                        <label>Umbral en centavos<input v-model="form.threshold_cents" type="number" min="1" step="1" required /></label>
                        <label class="flex items-center gap-2"><input v-model="form.enabled" type="checkbox" /> Exigir supervisor</label>
                        <label class="sm:col-span-2">Motivo del cambio<input v-model="form.reason" required maxlength="1000" /></label>
                    </fieldset>
                    <p>Versión consultada: {{ form.version }}. Se rechazará el cambio si otra persona actualizó la regla.</p>
                    <button :disabled="busy">{{ pending ? 'Reintentar el mismo cambio' : 'Guardar regla' }}</button>
                    <button v-if="pending" type="button" :disabled="busy" class="ml-3" @click="discard">Descartar reintento</button>
                </form>
            </section>
            <template v-if="association && permissions.approval_read">
                <section class="panel space-y-3"><h2 class="font-semibold">Políticas registradas</h2>
                    <p v-if="!lists.policies.length">No hay reglas registradas para esta asociación.</p>
                    <div v-for="item in lists.policies" :key="item.operation + item.currency" class="row"><span>{{ operation(item.operation) }} · {{ item.currency }} · {{ item.enabled ? 'Activa desde ' + item.threshold_cents + ' centavos' : 'Desactivada' }} · Versión {{ item.version }}</span><button v-if="permissions.approval_manage" :disabled="busy || !!pending" @click="selectPolicy(item)">Editar</button></div>
                </section>
                <section class="panel space-y-4"><div class="flex justify-between"><h2 class="font-semibold">Solicitudes pendientes de supervisor</h2><button :disabled="busy" @click="run(() => load('requests'))">Actualizar</button></div>
                    <p v-if="!lists.requests.length">No hay solicitudes vigentes pendientes.</p>
                    <article v-for="item in lists.requests" :key="item.id" class="space-y-2 rounded-xl border p-4">
                        <h3 class="font-semibold">{{ operation(item.operation) }} · {{ (item.amount_cents / 100).toFixed(2) }} {{ item.currency }}</h3>
                        <p class="break-all text-sm">Wallet: {{ item.wallet_id }} · Solicitante: {{ item.operator_id }} · Titular: {{ item.student_id }}</p>
                        <p>{{ item.reason }}</p><p class="text-sm">Vence: {{ date(item.expires_at) }} · Política versión {{ item.policy?.version }} · Titular: {{ item.student_status === 'CONFIRMED' ? 'Confirmado' : 'Pendiente' }}</p>
                        <template v-if="permissions.approval_review && item.can_review"><label>Motivo de la decisión<input v-model="reviewReasons[item.id]" maxlength="1000" :disabled="busy" /></label><button :disabled="busy" @click="review(item, 'approve')">Autorizar</button><button :disabled="busy" class="ml-3" @click="review(item, 'reject')">Rechazar</button></template><p v-else-if="permissions.approval_review" class="text-sm">Esta solicitud requiere la revisión de otro supervisor.</p>
                    </article>
                </section>
                <section class="panel space-y-4"><div class="flex justify-between"><h2 class="font-semibold">Ajustes y recuperaciones pendientes</h2><button :disabled="busy" @click="run(() => load('administrative'))">Actualizar</button></div>
                    <p v-if="!lists.administrative.length">No hay solicitudes administrativas vigentes pendientes.</p>
                    <article v-for="item in lists.administrative" :key="item.id" class="space-y-2 rounded-xl border p-4">
                        <h3 class="font-semibold">{{ operation(item.operation) }} · {{ (item.amount_cents / 100).toFixed(2) }} {{ item.currency }}</h3>
                        <p class="break-all text-sm">Solicitante: {{ item.operator_id }}<span v-if="item.refund_request_id"> · Devolución: {{ item.refund_request_id }}</span></p>
                        <p>{{ item.reason }}</p><p class="text-sm">Vence: {{ date(item.expires_at) }} · Política versión {{ item.policy?.version }}</p>
                        <template v-if="permissions.approval_review && item.can_review"><label>Motivo de la decisión<input v-model="reviewReasons[item.id]" maxlength="1000" :disabled="busy" /></label><button :disabled="busy" @click="review(item, 'approve', 'administrative')">Autorizar</button><button :disabled="busy" class="ml-3" @click="review(item, 'reject', 'administrative')">Rechazar</button></template><p v-else-if="permissions.approval_review" class="text-sm">Esta solicitud requiere la revisión de otro supervisor.</p>
                    </article>
                </section>
                <section class="panel space-y-3"><h2 class="font-semibold">Historial de políticas</h2><div v-for="(item, index) in lists.history" :key="index" class="row"><div><p>{{ operation(item.after.operation) }} · {{ item.after.currency }} · Versión {{ item.after.version }} · {{ item.after.enabled ? 'Activa' : 'Desactivada' }} · Umbral {{ item.after.threshold_cents }} centavos</p><p class="text-sm">{{ item.actor_id }} · {{ date(item.created_at) }} · {{ item.reason }}</p></div></div><p v-if="!lists.history.length">No hay cambios registrados.</p></section>
                <div v-for="kind in ['policies', 'requests', 'administrative', 'history']" :key="kind" class="flex items-center gap-3 text-sm"><template v-if="pages[kind]?.last_page > 1"><span>{{ ({ policies: 'Políticas', requests: 'Solicitudes', administrative: 'Ajustes y recuperaciones', history: 'Historial' })[kind] }} · Página {{ pages[kind].current_page }} de {{ pages[kind].last_page }}</span><button :disabled="busy || pages[kind].current_page <= 1" @click="run(() => load(kind, pages[kind].current_page - 1))">Anterior</button><button :disabled="busy || pages[kind].current_page >= pages[kind].last_page" @click="run(() => load(kind, pages[kind].current_page + 1))">Siguiente</button></template></div>
            </template>
        </main>
    </AuthenticatedLayout>
</template>
<style scoped>
.panel { border: 1px solid #e0e7ef; border-radius: 16px; background: white; padding: 24px; }
label { display: block; font-size: 14px; } input:not([type=checkbox]), select { display: block; margin-top: 6px; width: 100%; border: 1px solid #ccd5e0; border-radius: 8px; padding: 8px; }
button { background: #00338d; color: white; border-radius: 8px; padding: 8px 16px; } button:disabled { opacity: .45; cursor: not-allowed; }.row { display: flex; flex-wrap: wrap; gap: 12px; justify-content: space-between; border-top: 1px solid #e0e7ef; padding-top: 12px; }
</style>
