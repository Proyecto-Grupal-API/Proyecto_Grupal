<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link } from '@inertiajs/vue3';
import { computed, onMounted, reactive, ref, watch } from 'vue';
import axios from 'axios';

const props = defineProps({ permissions: Object, catalogs: Object, businessDate: String });
const tabs = { limits: 'Límites', alerts: 'Alertas', reconciliations: 'Conciliación' };
const tab = ref('limits');
const can = action => props.permissions[action] === true;
const viewAction = { limits: 'limits.view', alerts: 'alerts.view', reconciliations: 'reconciliation.view' };
const rows = ref([]), pagination = ref(null), loading = ref(false), busy = ref(false);
const error = ref(''), success = ref(''), detail = ref(null), detailPage = ref(null), detailKind = ref('');
const dialog = ref(null), mode = ref(''), selected = ref(null), key = ref('');
const filters = reactive({ operation: '', active: '', status: '', wallet_id: '', business_date: '', outcome: '', correlation_id: '' });
const form = reactive({});
const visibleFilters = computed(() => tab.value === 'limits' ? ['operation', 'active'] : tab.value === 'alerts'
    ? ['status', 'wallet_id', 'outcome', 'correlation_id'] : ['status', 'business_date']);
const labels = { operation: 'Operación', active: 'Vigencia', status: 'Estado', wallet_id: 'Wallet (UUID)', business_date: 'Fecha contable', outcome: 'Resultado', correlation_id: 'Correlación' };
const money = (cents, currency = 'MXN') => new Intl.NumberFormat('es-MX', { style: 'currency', currency }).format((cents ?? 0) / 100);
const date = value => value ? new Intl.DateTimeFormat('es-MX', { dateStyle: 'medium', timeStyle: 'short' }).format(new Date(value)) : '—';
const routeFor = (suffix, args = {}) => route('financial.controls.' + suffix, args);
const problem = e => {
    const validation = Object.values(e.response?.data?.errors ?? {}).flat();
    error.value = validation.length ? validation.join(' ') : e.response?.data?.message ?? 'No se pudo completar la solicitud. Reintenta.';
};
let requestSequence = 0;
async function load(page = 1) {
    const sequence = ++requestSequence;
    if (!can(viewAction[tab.value])) { rows.value = []; pagination.value = null; return; }
    loading.value = true; error.value = '';
    const params = { page, per_page: 20 };
    for (const name of visibleFilters.value) if (filters[name] !== '') params[name] = filters[name];
    try {
        const response = await axios.get(routeFor(tab.value + '.index'), { params });
        if (sequence === requestSequence) { rows.value = response.data.data; pagination.value = response.data.meta.pagination; }
    } catch (e) { if (sequence === requestSequence) problem(e); }
    finally { if (sequence === requestSequence) loading.value = false; }
}
watch(tab, () => { rows.value = []; detail.value = null; detailPage.value = null; success.value = ''; loading.value = false; for (const name of Object.keys(filters)) filters[name] = ''; load(); });
onMounted(() => load());
function cents(value) {
    if (!/^\d+(\.\d{1,2})?$/.test(String(value))) throw new Error('Escribe un importe con hasta dos decimales.');
    const [whole, decimals = ''] = String(value).split('.');
    const amount = Number(whole) * 100 + Number(decimals.padEnd(2, '0'));
    if (!Number.isSafeInteger(amount)) throw new Error('El importe es demasiado grande.');
    return amount;
}
function open(action, row = null) {
    mode.value = action; selected.value = row; error.value = ''; success.value = ''; key.value = crypto.randomUUID();
    for (const name of Object.keys(form)) delete form[name];
    if (action === 'create' || action === 'edit') Object.assign(form, {
        name: row?.name ?? '', subject_type: row?.subject_type ?? 'GLOBAL', subject_id: row?.subject_id ?? '',
        operation: row?.operation ?? 'RECARGA', period: row?.period ?? 'OPERACION', metric: row?.metric ?? 'MONTO',
        amount: row?.max_amount_cents == null ? '' : (row.max_amount_cents / 100).toFixed(2),
        max_count: row?.max_count ?? '', action: row?.action ?? 'BLOQUEAR', active: row?.active ?? true,
        currency: row?.currency ?? 'MXN', valid_from: row?.valid_from ?? '', valid_until: row?.valid_until ?? '',
        reason: row?.reason ?? '', change_reason: '',
    });
    if (action === 'evaluate') Object.assign(form, { wallet_id: '', operation: 'RECARGA', amount: '' });
    if (action === 'status') Object.assign(form, { status: 'EN_REVISION', note: '' });
    if (action === 'run') Object.assign(form, { business_date: props.businessDate, wallet_ids: '' });
    if (action === 'resolve') Object.assign(form, { note: '' });
    dialog.value.showModal();
}
async function inspect(kind, row, page = 1) {
    if (busy.value || loading.value) return;
    error.value = ''; loading.value = true;
    try {
        let url;
        if (kind === 'history') url = routeFor('limits.history', { limitId: row.id });
        if (kind === 'alert') url = routeFor('alerts.show', { alertId: row.id });
        if (kind === 'differences') url = routeFor('reconciliations.differences', { reconciliationId: row.id });
        const response = await axios.get(url, { params: { page, per_page: 20 } });
        detail.value = { row, data: response.data.data }; detailPage.value = response.data.meta.pagination ?? null; detailKind.value = kind;
    } catch (e) { problem(e); } finally { loading.value = false; }
}
async function submit() {
    if (busy.value) return;
    busy.value = true; error.value = '';
    try {
        let url, payload, method = 'post';
        if (mode.value === 'create' || mode.value === 'edit') {
            payload = { name: form.name, action: form.action, active: form.active, reason: form.reason || null,
                valid_from: form.valid_from || null, valid_until: form.valid_until || null, change_reason: form.change_reason,
                max_amount_cents: form.metric === 'MONTO' ? cents(form.amount) : null,
                max_count: form.metric === 'CONTEO' ? Number(form.max_count) : null };
            if (mode.value === 'create') {
                Object.assign(payload, { subject_type: form.subject_type, subject_id: form.subject_type === 'GLOBAL' ? null : form.subject_id,
                    operation: form.operation, period: form.period, metric: form.metric, currency: form.currency });
                url = routeFor('limits.store');
            } else { method = 'patch'; url = routeFor('limits.update', { limitId: selected.value.id }); payload.expected_revision = selected.value.revision; }
        }
        if (mode.value === 'evaluate') { url = routeFor('limits.evaluate'); payload = { wallet_id: form.wallet_id, operation: form.operation, amount_cents: cents(form.amount) }; }
        if (mode.value === 'status') { url = routeFor('alerts.status', { alertId: selected.value.id }); payload = { status: form.status, note: form.note }; }
        if (mode.value === 'run') {
            url = routeFor('reconciliations.store'); payload = { business_date: form.business_date };
            if (form.wallet_ids.trim()) payload.wallet_ids = form.wallet_ids.split(/[\s,]+/).filter(Boolean);
        }
        if (mode.value === 'resolve') { url = routeFor('reconciliations.resolve', { reconciliationId: detail.value.row.id, differenceId: selected.value.id }); payload = { note: form.note }; }
        const response = await axios({ method, url, data: payload, headers: { 'Idempotency-Key': key.value, Accept: 'application/json' } });
        if (mode.value === 'evaluate') {
            detail.value = { row: { name: 'Evaluación preventiva' }, data: response.data.data }; detailKind.value = 'evaluation'; detailPage.value = null;
        } else {
            success.value = mode.value === 'resolve' ? 'Revisión registrada. No se modificaron saldos.' : 'Operación registrada.';
            if (mode.value === 'run') success.value = `Conciliación ${response.data.data.status}. Caja: ${response.data.data.cash_status}.`;
            detail.value = null; detailPage.value = null;
            await load(pagination.value?.current_page ?? 1);
        }
        dialog.value.close();
    } catch (e) { if (e.response) problem(e); else error.value = e.message; }
    finally { busy.value = false; }
}
const title = computed(() => ({ create: 'Crear límite', edit: 'Editar límite', evaluate: 'Evaluar operación', status: 'Revisar alerta', run: 'Ejecutar conciliación', resolve: 'Resolver diferencia' })[mode.value]);
</script>

<template>
    <Head title="Controles financieros" />
    <AuthenticatedLayout>
        <template #header><div><p class="text-xs font-bold uppercase tracking-widest text-sky-600">Módulo 2 · 2.10</p><h2 class="mt-1 text-2xl font-bold text-[#00338D]">Controles financieros</h2></div></template>
        <main class="min-h-screen bg-[#F5F8FC] px-4 py-8 sm:px-6"><div class="mx-auto max-w-7xl space-y-5">
            <div class="flex flex-wrap items-center justify-between gap-3"><p class="text-slate-600">Límites de operación, seguimiento de alertas y conciliación contable.</p><Link :href="route('financial.dashboard')" class="text-sm font-semibold text-blue-800">Volver a Finanzas</Link></div>
            <p class="rounded-xl border border-blue-200 bg-blue-50 p-4 text-sm text-blue-900">La consulta y las acciones requieren autorización administrativa global. Los permisos se comprueban en el servidor; si todavía no están habilitados, solicita su integración al administrador.</p>
            <p v-if="error" role="alert" class="rounded-xl bg-red-50 p-4 text-red-800">{{ error }}</p>
            <p v-if="success" role="status" class="rounded-xl bg-emerald-50 p-4 text-emerald-800">{{ success }}</p>
            <nav class="flex flex-wrap gap-2" aria-label="Controles financieros"><button v-for="(label, name) in tabs" :key="name" :disabled="busy || loading" @click="tab = name" :aria-pressed="tab === name" class="rounded-xl px-5 py-3 font-semibold" :class="tab === name ? 'bg-[#00338D] text-white' : 'bg-white text-slate-600'">{{ label }}</button></nav>
            <section class="panel">
                <div class="flex flex-wrap justify-between gap-3"><h3 class="text-xl font-bold text-[#00338D]">{{ tabs[tab] }}</h3><div class="flex flex-wrap gap-2">
                    <button v-if="tab === 'limits'" class="btn" :disabled="!can('limits.manage') || busy" @click="open('create')">Crear límite</button>
                    <button v-if="tab === 'limits'" class="btn-secondary" :disabled="!can('limits.evaluate') || busy" @click="open('evaluate')">Evaluar operación</button>
                    <button v-if="tab === 'reconciliations'" class="btn" :disabled="!can('reconciliation.run') || busy" @click="open('run')">Ejecutar conciliación</button>
                </div></div>
                <p v-if="!can(viewAction[tab])" class="mt-6 rounded-xl border border-dashed p-6 text-slate-500">No tienes permiso para consultar estos registros. No se han cargado datos administrativos.</p>
                <template v-else>
                    <form @submit.prevent="load(1)" class="mt-5 flex flex-wrap items-end gap-3">
                        <label v-for="name in visibleFilters" :key="name" class="min-w-40 flex-1 text-sm"><span>{{ labels[name] }}</span>
                            <select v-if="name === 'operation'" v-model="filters[name]" class="field"><option value="">Todas</option><option v-for="value in catalogs.operations" :key="value">{{ value }}</option></select>
                            <select v-else-if="name === 'active'" v-model="filters[name]" class="field"><option value="">Todas</option><option value="1">Activo</option><option value="0">Inactivo</option></select>
                            <select v-else-if="name === 'status'" v-model="filters[name]" class="field"><option value="">Todos</option><option v-for="value in (tab === 'alerts' ? catalogs.alertStatuses : catalogs.reconciliationStatuses)" :key="value">{{ value }}</option></select>
                            <select v-else-if="name === 'outcome'" v-model="filters[name]" class="field"><option value="">Todos</option><option v-for="value in catalogs.outcomes" :key="value">{{ value }}</option></select>
                            <input v-else v-model="filters[name]" :type="name === 'business_date' ? 'date' : 'text'" class="field" />
                        </label><button class="btn-secondary" :disabled="loading || busy">Filtrar</button>
                    </form>
                    <p v-if="loading" class="mt-5 text-slate-500" role="status">Cargando registros…</p>
                    <div v-else-if="rows.length" class="mt-5 divide-y divide-slate-100">
                        <article v-for="row in rows" :key="row.id" class="py-5">
                            <div class="flex flex-wrap justify-between gap-3"><div class="min-w-0 flex-1">
                                <template v-if="tab === 'limits'"><h4 class="font-bold text-slate-800">{{ row.name }}</h4><p class="mt-1 text-sm">{{ row.operation }} · {{ row.period }} · {{ row.subject_type }} {{ row.subject_id }} · {{ row.action }} · {{ row.active ? 'Activo' : 'Inactivo' }}</p><p class="mt-1 font-semibold text-blue-800">{{ row.metric === 'MONTO' ? money(row.max_amount_cents, row.currency) : row.max_count + ' operaciones' }}</p><p class="text-xs text-slate-500">Vigencia: {{ row.valid_from ? date(row.valid_from) : 'Sin inicio definido' }} — {{ row.valid_until ? date(row.valid_until) : 'Sin fin definido' }}</p></template>
                                <template v-if="tab === 'alerts'"><h4 class="font-bold text-slate-800">{{ row.alert_type }} · {{ row.status }}</h4><p class="mt-1 text-sm">{{ row.operation }} · {{ row.amount_cents + ' centavos' }} · {{ row.outcome }}</p><p class="break-all text-xs text-slate-500">Wallet: {{ row.wallet_id }} · Correlación: {{ row.correlation_id || '—' }}</p><p class="text-xs text-slate-500">{{ date(row.detected_at) }}</p></template>
                                <template v-if="tab === 'reconciliations'"><h4 class="font-bold text-slate-800">{{ row.business_date }} · {{ row.status }}</h4><p class="mt-1 text-sm">{{ row.scope }} · {{ row.differences_count }} diferencias · Caja: {{ row.cash_status }}</p><p class="text-xs text-slate-500">Nuevas: {{ row.new_differences_count }} · Recurrentes: {{ row.recurring_differences_count }} · {{ row.executed_by }}</p><p v-if="row.error_message" class="mt-1 text-sm text-red-700">{{ row.error_message }}</p></template>
                                <p class="mt-2 break-all font-mono text-xs text-slate-400">{{ row.id }}</p>
                            </div><div class="flex flex-wrap items-start gap-2">
                                <template v-if="tab === 'limits'"><button class="btn-secondary" :disabled="busy" @click="inspect('history', row)">Historial</button><button class="btn" :disabled="!can('limits.manage') || busy" @click="open('edit', row)">Editar</button></template>
                                <template v-if="tab === 'alerts'"><button class="btn-secondary" :disabled="busy" @click="inspect('alert', row)">Evidencia e historial</button><button class="btn" :disabled="!can('alerts.review') || ['RESUELTA', 'DESCARTADA'].includes(row.status) || busy" @click="open('status', row)">Revisar</button></template>
                                <button v-if="tab === 'reconciliations'" class="btn-secondary" :disabled="busy" @click="inspect('differences', row)">Diferencias</button>
                            </div></div>
                        </article>
                    </div><p v-else class="mt-6 p-6 text-center text-slate-500">No hay registros para los filtros seleccionados.</p>
                    <div v-if="pagination" class="mt-5 flex items-center justify-between gap-3 text-sm"><button class="btn-secondary" :disabled="pagination.current_page <= 1 || loading || busy" @click="load(pagination.current_page - 1)">Anterior</button><span>{{ pagination.total }} registros · Página {{ pagination.current_page }} de {{ pagination.last_page }}</span><button class="btn-secondary" :disabled="pagination.current_page >= pagination.last_page || loading || busy" @click="load(pagination.current_page + 1)">Siguiente</button></div>
                </template>
            </section>
            <section v-if="detail" class="panel">
                <div class="flex justify-between gap-3"><h3 class="text-lg font-bold text-[#00338D]">{{ detailKind === 'history' ? 'Historial del límite' : detailKind === 'alert' ? 'Evidencia de alerta' : detailKind === 'evaluation' ? 'Evaluación preventiva' : 'Diferencias observadas' }}</h3><button class="btn-secondary" @click="detail = null">Cerrar</button></div>
                <template v-if="detailKind === 'history'"><p v-if="!detail.data.length" class="mt-4 text-slate-500">Sin cambios registrados.</p><article v-for="entry in detail.data" :key="entry.id" class="mt-4 rounded-xl border p-4"><p class="font-semibold">{{ entry.change_type }} · {{ entry.actor_id }}</p><p class="text-sm text-slate-500">{{ date(entry.created_at) }} · {{ entry.reason }}</p><div class="mt-3 grid gap-3 md:grid-cols-2"><div><p class="text-xs font-bold">Antes</p><pre class="evidence">{{ JSON.stringify(entry.before, null, 2) }}</pre></div><div><p class="text-xs font-bold">Después</p><pre class="evidence">{{ JSON.stringify(entry.after, null, 2) }}</pre></div></div></article></template>
                <template v-else-if="detailKind === 'differences'"><p class="mt-3 text-sm text-slate-500">Resolver registra una revisión; no mueve dinero ni elimina la evidencia de la conciliación.</p><p v-if="!detail.data.length" class="mt-4 text-slate-500">Esta corrida no observó diferencias.</p><article v-for="entry in detail.data" :key="entry.id" class="mt-4 rounded-xl border p-4"><div class="flex flex-wrap justify-between gap-3"><div><p class="font-semibold">{{ entry.check_code }} · {{ entry.status }}</p><p class="text-sm">Esperado: {{ entry.expected_cents + ' centavos' }} · Actual: {{ entry.actual_cents + ' centavos' }} · Diferencia: {{ entry.difference_cents + ' centavos' }}</p><p class="break-all text-xs text-slate-500">{{ entry.entity_type }}: {{ entry.entity_id }} · Ocurrencias: {{ entry.occurrences }}</p><p v-if="entry.seen_after_resolution" class="text-sm text-amber-700">La diferencia volvió a observarse después de su resolución.</p><p v-if="entry.resolved_by" class="text-sm">{{ entry.resolved_by }} · {{ entry.resolution_note }}</p></div><button class="btn" :disabled="!can('reconciliation.resolve') || entry.status !== 'ABIERTA' || busy" @click="open('resolve', entry)">Resolver</button></div><pre class="evidence">{{ JSON.stringify(entry.details, null, 2) }}</pre></article></template>
                <template v-else-if="detailKind === 'evaluation'"><p class="mt-3 text-sm text-slate-500">Consulta preventiva: no reserva fondos ni garantiza que una operación posterior sea aceptada.</p><p class="mt-4 text-xl font-bold text-blue-900">{{ detail.data.decision }}</p><p class="text-sm">{{ detail.data.operation }} · {{ detail.data.amount_cents + ' centavos' }} · {{ detail.data.limits_evaluated }} límites evaluados</p><article v-for="entry in detail.data.violations" :key="entry.limit_id" class="mt-3 rounded-lg border p-3"><p class="font-semibold">{{ entry.limit_name }} · {{ entry.action }}</p><p class="text-sm">{{ entry.period }} · Observado: {{ entry.metric === 'MONTO' ? entry.observed_value + ' centavos' : entry.observed_value }} · Máximo: {{ entry.metric === 'MONTO' ? entry.threshold_value + ' centavos' : entry.threshold_value }}</p></article></template>
                <template v-else><p class="mt-4 font-semibold">{{ detail.data.status }} · {{ detail.data.outcome }}</p><p class="text-sm">Operación: {{ detail.data.operation }} · {{ detail.data.amount_cents + ' centavos' }}</p><p class="text-sm">Valor observado: {{ detail.data.observed_value }} · Umbral: {{ detail.data.threshold_value }}</p><p class="break-all text-sm">Wallet: {{ detail.data.wallet_id }} · Transacción: {{ detail.data.transaction_id || 'No hubo movimiento' }}</p><p class="break-all text-sm">Correlación: {{ detail.data.correlation_id || '—' }}</p><p class="text-sm">Periodo: {{ date(detail.data.period_start) }} — {{ date(detail.data.period_end) }}</p><h4 class="mt-4 font-semibold">Historial de revisión</h4><article v-for="(entry, index) in detail.data.history" :key="index" class="mt-2 rounded-lg border p-3"><p class="text-sm">{{ entry.from_status || 'Detección' }} → {{ entry.to_status }} · {{ entry.actor_id }}</p><p class="text-sm text-slate-500">{{ date(entry.created_at) }} · {{ entry.note }}</p></article><details class="mt-4"><summary class="cursor-pointer text-sm font-semibold">Ver evidencia adicional</summary><pre class="evidence">{{ JSON.stringify(detail.data.details, null, 2) }}</pre></details></template>
                <div v-if="detailPage" class="mt-4 flex items-center justify-between gap-2 text-sm"><button class="btn-secondary" :disabled="detailPage.current_page <= 1 || loading" @click="inspect(detailKind, detail.row, detailPage.current_page - 1)">Anterior</button><span>Página {{ detailPage.current_page }} de {{ detailPage.last_page }}</span><button class="btn-secondary" :disabled="detailPage.current_page >= detailPage.last_page || loading" @click="inspect(detailKind, detail.row, detailPage.current_page + 1)">Siguiente</button></div>
            </section>
        </div></main>
        <dialog ref="dialog" class="w-full max-w-2xl rounded-2xl p-0 backdrop:bg-slate-900/50" @cancel="busy && $event.preventDefault()">
            <form @submit.prevent="submit" class="space-y-4 p-6"><h3 class="text-xl font-bold text-[#00338D]">{{ title }}</h3><p v-if="error" role="alert" class="rounded-lg bg-red-50 p-3 text-red-800">{{ error }}</p>
                <fieldset :disabled="busy" class="space-y-4">
                    <template v-if="['create', 'edit'].includes(mode)">
                        <label class="block text-sm">Nombre<input v-model="form.name" required maxlength="150" class="field" /></label>
                        <div class="grid gap-3 sm:grid-cols-2"><label class="text-sm">Ámbito<select v-model="form.subject_type" :disabled="mode === 'edit'" class="field"><option v-for="value in catalogs.subjects" :key="value">{{ value }}</option></select></label><label v-if="form.subject_type !== 'GLOBAL'" class="text-sm">Identificador del ámbito<select v-if="form.subject_type === 'WALLET_TYPE'" v-model="form.subject_id" :disabled="mode === 'edit'" required class="field"><option v-for="value in catalogs.walletTypes" :key="value">{{ value }}</option></select><input v-else v-model="form.subject_id" :disabled="mode === 'edit'" required maxlength="255" class="field" /></label></div>
                        <p v-if="form.subject_type === 'ROLE'" class="text-sm text-amber-800">Los límites por rol necesitan el proveedor de roles integrado. Si no está disponible, el backend rechazará las operaciones que dependan de él.</p>
                        <div class="grid gap-3 sm:grid-cols-3"><label class="text-sm">Operación<select v-model="form.operation" :disabled="mode === 'edit'" class="field"><option v-for="value in catalogs.operations" :key="value">{{ value }}</option></select></label><label class="text-sm">Periodo<select v-model="form.period" :disabled="mode === 'edit'" class="field"><option v-for="value in catalogs.periods" :key="value">{{ value }}</option></select></label><label class="text-sm">Métrica<select v-model="form.metric" :disabled="mode === 'edit'" class="field"><option v-for="value in catalogs.metrics" :key="value">{{ value }}</option></select></label></div>
                        <div class="grid gap-3 sm:grid-cols-2"><label v-if="form.metric === 'MONTO'" class="text-sm">Máximo ({{ form.currency }})<input v-model="form.amount" required inputmode="decimal" class="field" /></label><label v-else class="text-sm">Número máximo de operaciones<input v-model="form.max_count" type="number" min="0" step="1" required class="field" /></label><label class="text-sm">Moneda<input v-model="form.currency" :disabled="mode === 'edit'" required minlength="3" maxlength="3" class="field" /></label></div>
                        <label class="block text-sm">Acción<select v-model="form.action" class="field"><option v-for="value in catalogs.actions" :key="value">{{ value }}</option></select></label><label class="flex gap-2 text-sm"><input v-model="form.active" type="checkbox" /> Activo</label>
                        <div class="grid gap-3 sm:grid-cols-2"><label class="text-sm">Válido desde (fecha ISO con zona horaria)<input v-model="form.valid_from" placeholder="2026-10-09T00:00:00-06:00" class="field" /></label><label class="text-sm">Válido hasta (opcional)<input v-model="form.valid_until" placeholder="2026-12-31T23:59:59-06:00" class="field" /></label></div>
                        <label class="block text-sm">Descripción del límite<textarea v-model="form.reason" maxlength="500" class="field" /></label><label class="block text-sm">Motivo de este cambio<textarea v-model="form.change_reason" required maxlength="500" class="field" /></label>
                    </template>
                    <template v-if="mode === 'evaluate'"><label class="block text-sm">Wallet (UUID)<input v-model="form.wallet_id" required class="field" /></label><label class="block text-sm">Operación<select v-model="form.operation" class="field"><option v-for="value in catalogs.operations" :key="value">{{ value }}</option></select></label><label class="block text-sm">Importe en moneda de la wallet<input v-model="form.amount" required inputmode="decimal" class="field" /></label></template>
                    <template v-if="mode === 'status'"><label class="block text-sm">Nuevo estado<select v-model="form.status" class="field"><option v-if="selected.status === 'ABIERTA'">EN_REVISION</option><option>RESUELTA</option><option>DESCARTADA</option></select></label><label class="block text-sm">Motivo de revisión<textarea v-model="form.note" required maxlength="1000" class="field" /></label></template>
                    <template v-if="mode === 'run'"><label class="block text-sm">Fecha contable<input v-model="form.business_date" type="date" required class="field" /></label><label class="block text-sm">Wallets (UUID separados por coma; vacío para toda la institución)<textarea v-model="form.wallet_ids" class="field" /></label><p class="text-sm text-slate-500">La conciliación compara registros y saldos. La integración de caja se reportará según su disponibilidad.</p></template>
                    <label v-if="mode === 'resolve'" class="block text-sm">Motivo de resolución<textarea v-model="form.note" required maxlength="1000" class="field" /></label>
                </fieldset>
                <div class="flex justify-end gap-3"><button type="button" class="btn-secondary" :disabled="busy" @click="dialog.close()">Cancelar</button><button class="btn" :disabled="busy">{{ busy ? 'Procesando…' : 'Confirmar' }}</button></div>
            </form>
        </dialog>
    </AuthenticatedLayout>
</template>

<style scoped>
.panel { @apply rounded-2xl border border-slate-200 bg-white p-6 shadow-sm; }
.btn { @apply rounded-lg bg-blue-900 px-4 py-2 text-sm font-semibold text-white disabled:cursor-not-allowed disabled:opacity-40; }
.btn-secondary { @apply rounded-lg border border-slate-300 px-4 py-2 text-sm font-semibold text-slate-700 disabled:cursor-not-allowed disabled:opacity-40; }
.field { @apply mt-1 block w-full rounded-lg border-slate-300 text-sm; }
.evidence { @apply mt-3 max-h-80 overflow-auto rounded-lg bg-slate-50 p-3 text-xs whitespace-pre-wrap break-words; }
</style>
