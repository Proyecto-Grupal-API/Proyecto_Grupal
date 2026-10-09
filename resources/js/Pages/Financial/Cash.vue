<script setup>
import { computed, reactive, ref } from 'vue';
import { Head, Link } from '@inertiajs/vue3';
import axios from 'axios';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';

const associationInput = ref('');
const association = ref('');
const permissions = ref({ read: false, operate: false, adjust: false, close: false });
const registers = ref([]);
const shifts = ref([]);
const movements = ref([]);
const registerId = ref('');
const shiftId = ref('');
const summary = ref(null);
const pages = reactive({ registers: null, shifts: null, movements: null });
const busy = ref(false);
const error = ref('');
const success = ref('');
const pending = ref(null);
const action = ref('open');
const form = reactive({ amount: '', wallet: '', reason: '', type: 'CASH_IN' });
const register = computed(() => registers.value.find(item => item.id === registerId.value));
const currency = computed(() => summary.value?.currency || register.value?.currency || 'MXN');
const base = computed(() => `/finanzas/caja/asociaciones/${encodeURIComponent(association.value)}`);
const actorOwnsShift = computed(() => summary.value?.is_operator === true);
const titles = { open: 'Abrir turno', topups: 'Recarga en efectivo', withdrawals: 'Retiro en efectivo',
    movements: 'Entrada o salida de efectivo', adjustments: 'Ajuste de efectivo', close: 'Cerrar turno y realizar arqueo' };
const actionPermission = computed(() => action.value === 'adjustments' ? permissions.value.adjust
    : action.value === 'close' ? permissions.value.close : permissions.value.operate);
const canSubmit = computed(() => actionPermission.value && (action.value === 'open' ? !!registerId.value
    : !!summary.value && summary.value.status === 'OPEN' && (['adjustments', 'close'].includes(action.value) || actorOwnsShift.value)));
const money = value => new Intl.NumberFormat('es-MX', { style: 'currency', currency: currency.value }).format((value || 0) / 100);
const date = value => value ? new Intl.DateTimeFormat('es-MX', { dateStyle: 'short', timeStyle: 'short' }).format(new Date(value)) : '—';
const statusLabel = value => ({ OPEN: 'Abierto', CLOSED: 'Cerrado', ACTIVE: 'Activa', INACTIVE: 'Inactiva' }[value] || value);
const typeLabel = value => ({ CASH_IN: 'Entrada', CASH_OUT: 'Salida', TOPUP: 'Recarga', WITHDRAWAL: 'Retiro', ADJUSTMENT: 'Ajuste' }[value] || value);

function explain(exception) {
    const response = exception.response;
    if (!response) return 'No pudimos confirmar la respuesta. Reintenta la misma solicitud para comprobar su resultado.';
    if (response.status === 401 || response.status === 419) return 'Tu sesión venció. Inicia sesión nuevamente antes de continuar.';
    if (response.status === 403) return response.data?.message || 'No tienes permiso para esta acción en la asociación.';
    if (response.status === 404) return 'El registro no existe o no pertenece a esta asociación.';
    const messages = Object.values(response.data?.errors || {}).flat();
    return messages.length ? messages.join(' ') : response.data?.message || 'No fue posible completar la solicitud.';
}
async function run(task) {
    if (busy.value) return;
    busy.value = true; error.value = '';
    try { await task(); } catch (exception) { error.value = explain(exception); }
    finally { busy.value = false; }
}
async function getList(kind, url, page = 1) {
    const { data } = await axios.get(url, { params: { page, per_page: 10 } });
    ({ registers, shifts, movements })[kind].value = data.data;
    pages[kind] = data.meta.pagination;
}
async function connect() {
    if (pending.value) return;
    await run(async () => {
        association.value = ''; registers.value = []; shifts.value = []; movements.value = [];
        registerId.value = ''; shiftId.value = ''; summary.value = null; success.value = '';
        permissions.value = { read: false, operate: false, adjust: false, close: false };
        Object.keys(pages).forEach(key => { pages[key] = null; });
        const { data } = await axios.get('/finanzas/caja/context', { params: { association_id: associationInput.value.trim() } });
        association.value = data.data.association_id; permissions.value = data.data.permissions;
        if (permissions.value.read) await getList('registers', `${base.value}/registers`);
    });
}
async function chooseRegister() {
    if (pending.value) return;
    await run(async () => {
        shifts.value = []; movements.value = []; shiftId.value = ''; summary.value = null;
        pages.shifts = null; pages.movements = null; success.value = '';
        if (registerId.value) await getList('shifts', `${base.value}/registers/${registerId.value}/shifts`);
    });
}
async function snapshot() {
    if (!shiftId.value) return;
    const { data } = await axios.get(`${base.value}/shifts/${shiftId.value}`);
    summary.value = data.data;
    await getList('movements', `${base.value}/shifts/${shiftId.value}/movements`);
}
async function chooseShift() { await run(async () => { summary.value = null; movements.value = []; await snapshot(); }); }
async function paginate(kind, page) {
    if (pending.value) return;
    await run(async () => {
        if (kind === 'registers') {
            registerId.value = ''; shiftId.value = ''; summary.value = null; shifts.value = []; movements.value = [];
            await getList(kind, `${base.value}/registers`, page);
        } else if (kind === 'shifts') {
            shiftId.value = ''; summary.value = null; movements.value = [];
            await getList(kind, `${base.value}/registers/${registerId.value}/shifts`, page);
        } else await getList(kind, `${base.value}/shifts/${shiftId.value}/movements`, page);
    });
}
function cents() {
    const value = form.amount.trim();
    const valid = action.value === 'adjustments' ? /^-?\d{1,12}(?:\.\d{1,2})?$/ : /^\d{1,12}(?:\.\d{1,2})?$/;
    if (!valid.test(value)) throw new Error('Escribe el importe con un máximo de dos decimales, por ejemplo 100.50.');
    const negative = value.startsWith('-');
    const [whole, fraction = ''] = value.replace('-', '').split('.');
    const amount = Number(whole) * 100 + Number(fraction.padEnd(2, '0'));
    if (!Number.isSafeInteger(amount) || (!['open', 'close'].includes(action.value) && amount === 0)) throw new Error('El importe debe ser válido y distinto de cero.');
    return negative ? -amount : amount;
}
async function submit() {
    if (busy.value || (!pending.value && !canSubmit.value)) return;
    if (!pending.value) {
        try {
            const amount = cents();
            if (action.value !== 'open' && !form.reason.trim()) throw new Error('Escribe el motivo de la operación.');
            const payload = action.value === 'open' ? { opening_amount_cents: amount }
                : action.value === 'close' ? { counted_amount_cents: amount, reason: form.reason.trim() }
                : { amount_cents: amount, reason: form.reason.trim() };
            if (['topups', 'withdrawals'].includes(action.value)) {
                if (!form.wallet.trim()) throw new Error('Escribe el identificador de la wallet.');
                payload.wallet_id = form.wallet.trim();
            }
            if (action.value === 'movements') payload.type = form.type;
            if (!window.confirm(`${titles[action.value]} por ${money(amount)}. ¿Confirmas que el importe corresponde al efectivo físico?`)) return;
            const url = action.value === 'open' ? `${base.value}/registers/${registerId.value}/shifts`
                : `${base.value}/shifts/${shiftId.value}/${action.value}`;
            pending.value = { url, payload, action: action.value, key: crypto.randomUUID() };
        } catch (exception) { error.value = exception.message; return; }
    }
    await run(async () => {
        const request = pending.value;
        let result;
        try {
            result = await axios.post(request.url, request.payload, { headers: { 'Idempotency-Key': request.key } });
        } catch (exception) {
            // Validation and access failures did not execute the service. Business/concurrency
            // conflicts and unknown responses preserve the original request for safe retry.
            if ([401, 403, 404, 419, 422].includes(exception.response?.status)) pending.value = null;
            throw exception;
        }
        pending.value = null; success.value = `${titles[request.action]}: operación confirmada.`;
        form.amount = ''; form.reason = ''; form.wallet = '';
        if (request.action === 'open') shiftId.value = result.data.data.id;
        try {
            await getList('shifts', `${base.value}/registers/${registerId.value}/shifts`);
            await snapshot();
        } catch (exception) {
            summary.value = null;
            error.value = `La operación fue confirmada, pero no pudimos actualizar el resumen. ${explain(exception)}`;
        }
    });
}
function abandon() {
    if (window.confirm('Antes de crear otra solicitud, revisa el historial para comprobar si la anterior se completó. Descartar este reintento no cancela una operación. ¿Deseas continuar?')) pending.value = null;
}
</script>

<template>
    <Head title="Caja y turnos" />
    <AuthenticatedLayout>
        <template #header>
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div><p class="text-xs font-bold uppercase tracking-widest text-sky-600">Finanzas · 2.8</p><h2 class="mt-1 text-2xl font-bold text-[#00338D]">Caja y turnos</h2></div>
                <Link :href="route('financial.dashboard')" class="text-sm font-semibold text-[#00338D]">Volver a Finanzas</Link>
            </div>
        </template>
        <main class="min-h-screen bg-[#F5F8FC] px-4 py-8 sm:px-6">
            <div class="mx-auto max-w-7xl space-y-5">
                <section class="rounded-2xl bg-[#00338D] p-6 text-white">
                    <h1 class="text-2xl font-bold">Control de efectivo</h1><p class="mt-2 text-blue-100">Administra el turno y compara el efectivo contado con las operaciones registradas.</p>
                </section>
                <div v-if="error" role="alert" class="rounded-xl border border-red-200 bg-red-50 p-4 text-red-800">{{ error }}</div>
                <div v-if="success" role="status" class="rounded-xl border border-emerald-200 bg-emerald-50 p-4 text-emerald-800">{{ success }}</div>
                <section class="panel">
                    <h2>Asociación y caja</h2>
                    <form class="mt-4 flex flex-wrap items-end gap-3" @submit.prevent="connect">
                        <label class="flex-1">Identificador de asociación<input v-model="associationInput" required maxlength="255" :disabled="busy || !!pending" placeholder="Identificador proporcionado por tu asociación" /></label>
                        <button class="primary" :disabled="busy || !!pending">Consultar cajas</button>
                    </form>
                    <p v-if="!association" class="note mt-3">Selecciona tu asociación para consultar los permisos y las cajas disponibles.</p>
                    <p v-else-if="!permissions.read" class="mt-4 rounded-xl bg-blue-50 p-4 text-sm text-[#00338D]">No tienes autorización para consultar las cajas de esta asociación. Solicita al administrador que revise tu acceso.</p>
                    <template v-else>
                        <label class="mt-4">Caja<select v-model="registerId" :disabled="busy || !!pending" @change="chooseRegister"><option value="">Seleccionar caja</option><option v-for="item in registers" :key="item.id" :value="item.id">{{ item.name }} · {{ item.currency }} · {{ statusLabel(item.status) }}</option></select></label>
                        <p v-if="!registers.length" class="note mt-3">No hay cajas registradas en esta asociación.</p>
                        <div v-if="pages.registers?.last_page > 1" class="pagination"><button :disabled="busy || !!pending || pages.registers.current_page === 1" @click="paginate('registers', pages.registers.current_page - 1)">Anterior</button><span>Página {{ pages.registers.current_page }} de {{ pages.registers.last_page }}</span><button :disabled="busy || !!pending || pages.registers.current_page === pages.registers.last_page" @click="paginate('registers', pages.registers.current_page + 1)">Siguiente</button></div>
                    </template>
                </section>
                <section v-if="registerId && permissions.read" class="panel">
                    <h2>Turnos de {{ register?.name }}</h2>
                    <label class="mt-4">Turno<select v-model="shiftId" :disabled="busy || !!pending" @change="chooseShift"><option value="">Seleccionar turno</option><option v-for="item in shifts" :key="item.id" :value="item.id">{{ date(item.opened_at) }} · {{ statusLabel(item.status) }} · {{ item.agent_id }}</option></select></label>
                    <p v-if="!shifts.length" class="note mt-3">Esta caja todavía no tiene turnos.</p>
                    <div v-if="pages.shifts?.last_page > 1" class="pagination"><button :disabled="busy || !!pending || pages.shifts.current_page === 1" @click="paginate('shifts', pages.shifts.current_page - 1)">Anterior</button><span>Página {{ pages.shifts.current_page }} de {{ pages.shifts.last_page }}</span><button :disabled="busy || !!pending || pages.shifts.current_page === pages.shifts.last_page" @click="paginate('shifts', pages.shifts.current_page + 1)">Siguiente</button></div>
                    <button v-if="shiftId" class="secondary mt-3" :disabled="busy" @click="chooseShift">Actualizar resumen e historial</button>
                </section>
                <section v-if="summary" class="panel">
                    <div class="flex flex-wrap justify-between gap-3"><h2>Resumen del turno</h2><span class="rounded-full bg-blue-50 px-3 py-1 text-sm text-[#00338D]">{{ statusLabel(summary.status) }}</span></div>
                    <div class="mt-5 grid gap-4 sm:grid-cols-3"><div><p class="note">Fondo inicial</p><strong>{{ money(summary.opening_amount_cents) }}</strong></div><div><p class="note">Entradas</p><strong>{{ money(summary.cash_in_cents) }}</strong></div><div><p class="note">Salidas</p><strong>{{ money(summary.cash_out_cents) }}</strong></div><div><p class="note">Ajustes</p><strong>{{ money(summary.adjustment_cents) }}</strong></div><div><p class="note">Efectivo esperado</p><strong class="text-2xl text-[#00338D]">{{ money(summary.expected_amount_cents) }}</strong></div><div v-if="summary.status === 'CLOSED'"><p class="note">Efectivo contado / diferencia</p><strong>{{ money(summary.counted_amount_cents) }} / {{ money(summary.difference_cents) }}</strong></div></div>
                    <p class="note mt-4 break-all">Responsable: {{ summary.agent_id }} · Turno: {{ summary.id }}</p>
                    <p v-if="summary.status === 'CLOSED'" class="note mt-2">Cerrado por {{ summary.closed_by }} el {{ date(summary.closed_at) }}. Motivo: {{ summary.closing_reason }}</p>
                </section>
                <section v-if="registerId && permissions.read" class="panel">
                    <h2>Registrar operación</h2>
                    <label class="mt-4">Operación<select v-model="action" :disabled="busy || !!pending"><option value="open">Abrir turno</option><option value="topups">Recarga en efectivo</option><option value="withdrawals">Retiro en efectivo</option><option value="movements">Entrada o salida física</option><option value="adjustments">Ajuste de efectivo</option><option value="close">Cierre y arqueo</option></select></label>
                    <p v-if="!actionPermission" class="note mt-3">Esta acción requiere un permiso que tu cuenta no tiene.</p>
                    <p v-else-if="action !== 'open' && !summary" class="note mt-3">Selecciona un turno antes de continuar.</p>
                    <p v-else-if="action !== 'open' && summary?.status === 'CLOSED'" class="note mt-3">El turno está cerrado. Puedes consultar su historial.</p>
                    <p v-else-if="action !== 'open' && !['adjustments', 'close'].includes(action) && !actorOwnsShift" class="note mt-3">Solo el operador responsable puede registrar operaciones ordinarias en este turno.</p>
                    <form class="mt-4 space-y-4" @submit.prevent="submit">
                        <fieldset class="grid gap-4 sm:grid-cols-2" :disabled="busy || !!pending || !canSubmit">
                            <label v-if="action === 'movements'">Tipo<select v-model="form.type"><option value="CASH_IN">Entrada de efectivo</option><option value="CASH_OUT">Salida de efectivo</option></select></label>
                            <label> {{ action === 'open' ? 'Fondo inicial' : action === 'close' ? 'Efectivo contado' : 'Importe' }} ({{ currency }})<input v-model="form.amount" required inputmode="decimal" placeholder="100.00" /></label>
                            <label v-if="['topups', 'withdrawals'].includes(action)">Identificador de wallet<input v-model="form.wallet" required maxlength="36" placeholder="UUID de la wallet" /></label>
                            <label v-if="action !== 'open'" class="sm:col-span-2">Motivo<textarea v-model="form.reason" required maxlength="1000" rows="2" /></label>
                        </fieldset>
                        <p class="note">{{ action === 'adjustments' ? 'Un ajuste puede ser positivo o negativo y queda registrado con responsable y motivo.' : action === 'close' ? 'El cierre conserva el arqueo y no permite movimientos nuevos. Una diferencia no se corrige automáticamente.' : 'Confirma el efectivo físico antes de registrar la operación.' }}</p>
                        <div v-if="pending" class="rounded-xl bg-amber-50 p-4 text-sm text-amber-900">La solicitud conserva su importe y su clave para evitar duplicados. Puedes actualizar el historial y reintentar. Descartarla no revierte una operación.</div>
                        <div class="flex flex-wrap gap-3"><button class="primary" :disabled="busy || (!pending && !canSubmit)">{{ busy ? 'Procesando…' : pending ? 'Reintentar la misma solicitud' : titles[action] }}</button><button v-if="pending" type="button" class="secondary" :disabled="busy" @click="abandon">Descartar reintento</button></div>
                    </form>
                </section>
                <section v-if="summary" class="panel">
                    <h2>Historial de efectivo</h2>
                    <div class="mt-4 overflow-x-auto"><table class="w-full text-left text-sm"><thead><tr><th>Fecha</th><th>Movimiento</th><th>Importe</th><th>Responsable y motivo</th></tr></thead><tbody><tr v-for="item in movements" :key="item.id"><td>{{ date(item.created_at) }}</td><td>{{ typeLabel(item.type) }}<p class="note break-all">{{ item.reference_type }} {{ item.reference_id }}</p></td><td class="whitespace-nowrap">{{ money(item.amount_cents) }}</td><td class="break-all">{{ item.actor_id }}<p class="note">{{ item.reason }}</p></td></tr></tbody></table></div>
                    <p v-if="!movements.length" class="note mt-4">No hay movimientos registrados en este turno.</p>
                    <div v-if="pages.movements?.last_page > 1" class="pagination"><button :disabled="busy || !!pending || pages.movements.current_page === 1" @click="paginate('movements', pages.movements.current_page - 1)">Anterior</button><span>Página {{ pages.movements.current_page }} de {{ pages.movements.last_page }}</span><button :disabled="busy || !!pending || pages.movements.current_page === pages.movements.last_page" @click="paginate('movements', pages.movements.current_page + 1)">Siguiente</button></div>
                </section>
            </div>
        </main>
    </AuthenticatedLayout>
</template>

<style scoped>
.panel { border: 1px solid #e2e8f0; border-radius: 1rem; background: white; padding: 1.5rem; }
h2 { font-size: 1.125rem; font-weight: 700; color: #00338d; }
label { display: block; font-size: .875rem; font-weight: 600; color: #334155; }
input, select, textarea { display: block; margin-top: .4rem; width: 100%; border: 1px solid #cbd5e1; border-radius: .65rem; padding: .65rem; font-weight: 400; }
.primary, .secondary, .pagination button { border-radius: .65rem; padding: .7rem 1rem; font-size: .875rem; font-weight: 600; }
.primary { background: #00338d; color: white; }.secondary, .pagination button { border: 1px solid #cbd5e1; color: #00338d; background: white; }
button:disabled, fieldset:disabled, input:disabled, select:disabled { opacity: .5; cursor: not-allowed; }
.note { color: #64748b; font-size: .8rem; font-weight: 400; }
th, td { padding: .85rem .5rem; border-bottom: 1px solid #e2e8f0; vertical-align: top; }
.pagination { margin-top: 1rem; display: flex; align-items: center; gap: .75rem; font-size: .8rem; }
</style>
