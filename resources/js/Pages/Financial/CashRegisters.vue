<script setup>
import { ref, reactive } from 'vue';
import { Head, Link } from '@inertiajs/vue3';
import axios from 'axios';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';

const associationInput = ref('');
const association = ref('');
const permissions = ref({ read: false, manage: false });
const registers = ref([]);
const pagination = ref(null);
const history = ref([]);
const historyPagination = ref(null);
const selected = ref(null);
const mode = ref('create');
const busy = ref(false);
const error = ref('');
const success = ref('');
const pending = ref(null);
const form = reactive({ name: '', currency: 'MXN', status: 'ACTIVE', reason: '' });
const root = () => `/finanzas/caja/asociaciones/${encodeURIComponent(association.value)}/registers`;
const status = value => value === 'ACTIVE' ? 'Activa' : 'Inactiva';
const date = value => new Intl.DateTimeFormat('es-MX', { dateStyle: 'short', timeStyle: 'short' }).format(new Date(value));
function explain(exception) {
    if (!exception.response) return 'No pudimos confirmar la respuesta. Reintenta la misma solicitud para evitar duplicados.';
    if ([401, 419].includes(exception.response.status)) return 'Tu sesión venció. Inicia sesión nuevamente.';
    if (exception.response.status === 403) return 'No tienes permiso para esta acción en la asociación.';
    if (exception.response.status === 404) return 'La caja no existe o pertenece a otra asociación.';
    const errors = Object.values(exception.response.data?.errors || {}).flat();
    return errors.length ? errors.join(' ') : exception.response.data?.message || 'No fue posible completar la solicitud.';
}
async function run(task) {
    if (busy.value) return;
    busy.value = true; error.value = '';
    try { await task(); } catch (exception) { error.value = explain(exception); }
    finally { busy.value = false; }
}
async function loadRegisters(page = 1) {
    const { data } = await axios.get(root(), { params: { page, per_page: 10 } });
    registers.value = data.data; pagination.value = data.meta.pagination;
}
async function loadHistory(page = 1) {
    if (!selected.value) return;
    const { data } = await axios.get(`${root()}/${selected.value.id}/history`, { params: { page, per_page: 10 } });
    history.value = data.data; historyPagination.value = data.meta.pagination;
}
function reset() {
    mode.value = 'create'; selected.value = null; history.value = []; historyPagination.value = null;
    form.name = ''; form.currency = 'MXN'; form.status = 'ACTIVE'; form.reason = '';
}
async function connect() {
    if (pending.value) return;
    await run(async () => {
        association.value = ''; permissions.value = { read: false, manage: false };
        registers.value = []; pagination.value = null; reset(); success.value = '';
        const { data } = await axios.get('/finanzas/caja/context', { params: { association_id: associationInput.value.trim() } });
        association.value = data.data.association_id; permissions.value = data.data.permissions;
        if (permissions.value.read) await loadRegisters();
    });
}
async function select(register) {
    if (pending.value) return;
    await run(async () => {
        selected.value = register; mode.value = 'edit'; history.value = []; historyPagination.value = null;
        form.name = register.name; form.currency = register.currency; form.status = register.status; form.reason = '';
        await loadHistory();
    });
}
async function submit() {
    if (busy.value || (!pending.value && (!association.value || !permissions.value.manage))) return;
    if (!pending.value) {
        const updating = mode.value === 'edit';
        if (updating && !selected.value) return;
        if (!window.confirm(updating ? '¿Confirmas el cambio de configuración de esta caja?' : 'La asociación y moneda quedarán fijas. ¿Confirmas crear esta caja?')) return;
        const payload = updating ? { name: form.name.trim(), status: form.status, expected_version: selected.value.version, reason: form.reason.trim() }
            : { name: form.name.trim(), currency: form.currency.toUpperCase(), reason: form.reason.trim() };
        pending.value = { url: updating ? `${root()}/${selected.value.id}` : root(), method: updating ? 'patch' : 'post',
            payload, key: crypto.randomUUID() };
    }
    await run(async () => {
        const request = pending.value;
        let result;
        try { result = await axios({ url: request.url, method: request.method, data: request.payload, headers: { 'Idempotency-Key': request.key } }); }
        catch (exception) {
            if ([401, 403, 404, 419, 422].includes(exception.response?.status)) pending.value = null;
            throw exception;
        }
        pending.value = null;
        success.value = `Cambio confirmado en ${result.data.data.name}. Versión ${result.data.data.version}.`;
        reset();
        if (permissions.value.read) {
            try { await loadRegisters(); }
            catch (exception) { error.value = `El cambio se confirmó, pero no pudimos actualizar el listado. ${explain(exception)}`; }
        }
    });
}
function abandon() {
    if (window.confirm('Descartar este reintento no cancela un cambio ya confirmado. Revisa el listado e historial antes de crear otra solicitud. ¿Deseas continuar?')) pending.value = null;
}
</script>

<template>
    <Head title="Administración de cajas" />
    <AuthenticatedLayout>
        <template #header><div class="flex flex-wrap items-center justify-between gap-3"><div><p class="text-xs font-bold uppercase tracking-widest text-sky-600">Finanzas · 2.8</p><h2 class="mt-1 text-2xl font-bold text-[#00338D]">Administración de cajas</h2></div><Link :href="route('financial.cash.index')" class="text-sm font-semibold text-[#00338D]">Volver a Caja y turnos</Link></div></template>
        <main class="min-h-screen bg-[#F5F8FC] px-4 py-8 sm:px-6"><div class="mx-auto max-w-7xl space-y-5">
            <section class="rounded-2xl bg-[#00338D] p-6 text-white"><h1 class="text-2xl font-bold">Cajas de la asociación</h1><p class="mt-2 text-blue-100">Configura las cajas y consulta quién realizó cada cambio.</p></section>
            <div v-if="error" role="alert" class="rounded-xl border border-red-200 bg-red-50 p-4 text-red-800">{{ error }}</div>
            <div v-if="success" role="status" class="rounded-xl border border-emerald-200 bg-emerald-50 p-4 text-emerald-800">{{ success }}</div>
            <section class="panel"><h2>Asociación</h2><form class="mt-4 flex flex-wrap items-end gap-3" @submit.prevent="connect"><label class="flex-1">Identificador de asociación<input v-model="associationInput" required maxlength="255" :disabled="busy || !!pending" /></label><button class="primary" :disabled="busy || !!pending">Consultar permisos y cajas</button></form><p v-if="association && !permissions.read && !permissions.manage" class="note mt-4">No tienes permiso para consultar o administrar cajas en esta asociación.</p></section>
            <section v-if="association && permissions.read" class="panel"><div class="flex flex-wrap justify-between gap-3"><h2>Cajas registradas</h2><button class="secondary" :disabled="busy || !!pending" @click="run(() => loadRegisters())">Actualizar listado</button></div>
                <div class="mt-4 overflow-x-auto"><table class="w-full text-left text-sm"><thead><tr><th>Caja</th><th>Moneda</th><th>Estado</th><th>Versión</th><th>Consulta</th></tr></thead><tbody><tr v-for="item in registers" :key="item.id"><td>{{ item.name }}<p class="note break-all">{{ item.id }}</p></td><td>{{ item.currency }}</td><td>{{ status(item.status) }}</td><td>{{ item.version }}</td><td><button class="secondary" :disabled="busy || !!pending" @click="select(item)">{{ permissions.manage ? 'Editar e historial' : 'Ver historial' }}</button></td></tr></tbody></table></div>
                <p v-if="!registers.length" class="note mt-4">No hay cajas registradas.</p><div v-if="pagination?.last_page > 1" class="pagination"><button :disabled="busy || !!pending || pagination.current_page === 1" @click="run(() => loadRegisters(pagination.current_page - 1))">Anterior</button><span>Página {{ pagination.current_page }} de {{ pagination.last_page }}</span><button :disabled="busy || !!pending || pagination.current_page === pagination.last_page" @click="run(() => loadRegisters(pagination.current_page + 1))">Siguiente</button></div>
            </section>
            <section v-if="association" class="panel"><div class="flex flex-wrap items-center justify-between gap-3"><h2>{{ mode === 'edit' ? 'Editar caja' : 'Crear caja' }}</h2><button v-if="mode === 'edit' && permissions.manage" class="secondary" :disabled="busy || !!pending" @click="reset">Nueva caja</button></div>
                <p class="note mt-3">La asociación y moneda quedan fijas. Una caja con turno abierto debe cerrarse antes de desactivarla.</p>
                <p v-if="!permissions.manage" class="note mt-3">La administración requiere un permiso específico.</p>
                <form class="mt-4 space-y-4" @submit.prevent="submit"><fieldset class="grid gap-4 sm:grid-cols-2" :disabled="busy || !!pending || !permissions.manage"><label>Nombre de caja<input v-model="form.name" required maxlength="100" /></label><label>Moneda<input v-model="form.currency" required minlength="3" maxlength="3" :readonly="mode === 'edit'" /></label><label v-if="mode === 'edit'">Estado<select v-model="form.status"><option value="ACTIVE">Activa</option><option value="INACTIVE">Inactiva</option></select></label><label class="sm:col-span-2">Motivo del cambio<textarea v-model="form.reason" required maxlength="1000" rows="2" /></label></fieldset>
                    <p v-if="selected" class="note">Asociación: {{ association }} · Versión consultada: {{ selected.version }}</p>
                    <div v-if="pending" class="rounded-xl bg-amber-50 p-4 text-sm text-amber-900">Se conserva la solicitud original para reintentar sin duplicar el cambio.</div>
                    <div class="flex flex-wrap gap-3"><button class="primary" :disabled="busy || (!pending && !permissions.manage)">{{ busy ? 'Procesando…' : pending ? 'Reintentar la misma solicitud' : mode === 'edit' ? 'Guardar cambio' : 'Crear caja' }}</button><button v-if="pending" type="button" class="secondary" :disabled="busy" @click="abandon">Descartar reintento</button></div>
                </form>
            </section>
            <section v-if="selected && permissions.read" class="panel"><div class="flex flex-wrap items-center justify-between gap-3"><h2>Historial de {{ selected.name }}</h2><button class="secondary" :disabled="busy" @click="run(() => loadHistory())">Actualizar historial</button></div><p v-if="!history.length" class="note mt-4">No hay cambios administrativos registrados. Las cajas anteriores a este historial conservan su versión inicial.</p>
                <article v-for="change in history" :key="change.id" class="mt-4 rounded-xl border border-slate-200 p-4"><div class="flex flex-wrap justify-between gap-2"><strong>Versión {{ change.version }} · {{ change.action === 'CREATE' ? 'Alta' : 'Actualización' }}</strong><span class="note">{{ date(change.created_at) }}</span></div><p class="mt-2 text-sm">{{ change.before ? `${change.before.name} (${status(change.before.status)}) → ` : '' }}{{ change.after.name }} ({{ status(change.after.status) }})</p><p class="note mt-2 break-all">Responsable: {{ change.actor_id }}</p><p class="mt-2 text-sm">{{ change.reason }}</p></article>
                <div v-if="historyPagination?.last_page > 1" class="pagination"><button :disabled="busy || historyPagination.current_page === 1" @click="run(() => loadHistory(historyPagination.current_page - 1))">Anterior</button><span>Página {{ historyPagination.current_page }} de {{ historyPagination.last_page }}</span><button :disabled="busy || historyPagination.current_page === historyPagination.last_page" @click="run(() => loadHistory(historyPagination.current_page + 1))">Siguiente</button></div>
            </section>
        </div></main>
    </AuthenticatedLayout>
</template>

<style scoped>
.panel { padding: 1.5rem; background: white; border: 1px solid #e2e8f0; border-radius: 1rem; }
h2 { color: #00338d; font-weight: 700; font-size: 1.125rem; }
label { display: block; font-size: .875rem; font-weight: 600; color: #334155; }
input, select, textarea { display: block; width: 100%; margin-top: .4rem; padding: .65rem; border: 1px solid #cbd5e1; border-radius: .65rem; font-weight: 400; }
.primary, .secondary, .pagination button { padding: .7rem 1rem; border-radius: .65rem; font-size: .875rem; font-weight: 600; }
.primary { background: #00338d; color: white; }.secondary, .pagination button { border: 1px solid #cbd5e1; color: #00338d; background: white; }
button:disabled, fieldset:disabled, input:disabled { opacity: .5; cursor: not-allowed; }
.note { font-size: .8rem; color: #64748b; font-weight: 400; }th, td { padding: .85rem .5rem; border-bottom: 1px solid #e2e8f0; vertical-align: top; }
.pagination { margin-top: 1rem; display: flex; gap: .75rem; align-items: center; font-size: .8rem; }
</style>
