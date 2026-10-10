<script setup>
import { ref, computed } from 'vue';
import { Head, Link } from '@inertiajs/vue3';
import axios from 'axios';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
const scopeLabels = { business: 'Negocio', association: 'Asociación', council: 'Consejo', service: 'Servicio' };
const statusLabels = { active: 'Activa', suspended: 'Suspendida', pending: 'Pendiente', revoked: 'Revocada' };
const props = defineProps({ roles: Array, twoFactorReady: Boolean });
const email = ref(''); const target = ref(null); const rows = ref([]); const meta = ref(null);
const roleKey = ref('scholarship_committee'); const scopeId = ref(''); const start = ref(''); const end = ref(''); const reason = ref('');
const busy = ref(false); const error = ref(''); const message = ref('');
const selectedRole = computed(() => props.roles.find(r => r.key === roleKey.value));
const errorText = e => Object.values(e.response?.data?.errors || {}).flat().join(' ') || e.response?.data?.message || 'No se pudo completar la operación. Comprueba el historial antes de reintentar.';
async function lookup() {
    if (busy.value) return; busy.value = true; error.value = ''; target.value = null; rows.value = []; meta.value = null;
    try { const r = await axios.get(route('financial.bonuses.roles.lookup'), { params: { email: email.value.trim() } }); target.value = r.data.data; await load(); }
    catch (e) { error.value = errorText(e); } finally { busy.value = false; }
}
async function load(page = 1) {
    if (!target.value) return;
    const r = await axios.get(route('financial.bonuses.roles.records'), { params: { user_id: target.value.id, page } }); rows.value = r.data.data; meta.value = r.data.meta;
}
async function assign() {
    if (busy.value || !target.value) return; busy.value = true; error.value = ''; message.value = '';
    try {
        const payload = { user_id: target.value.id, role_key: roleKey.value, scope_type: selectedRole.value.scope_type, scope_id: selectedRole.value.scope_type ? scopeId.value.trim() : null,
            starts_at: start.value ? new Date(start.value).toISOString() : null, ends_at: end.value ? new Date(end.value).toISOString() : null, reason: reason.value.trim() };
        await axios.post(route('financial.bonuses.roles.assign'), payload); message.value = 'Rol asignado con responsable, motivo y vigencia.'; await load();
    } catch (e) { error.value = errorText(e); } finally { busy.value = false; }
}
async function revoke(row) {
    if (busy.value) return;
    const text = window.prompt('Motivo obligatorio para revocar esta asignación:'); if (!text?.trim()) return;
    busy.value = true; error.value = ''; message.value = '';
    try { await axios.post(route('financial.bonuses.roles.revoke', row.id), { reason: text.trim() }); message.value = 'Asignación revocada. Su historial se conserva.'; await load(meta.value?.current_page || 1); }
    catch (e) { error.value = errorText(e); } finally { busy.value = false; }
}
const date = value => value ? new Date(value).toLocaleString('es-MX') : 'Sin límite';
</script>
<template>
    <Head title="Roles de bonos" />
    <AuthenticatedLayout>
        <template #header><div class="flex flex-wrap justify-between gap-3"><h1 class="text-2xl font-bold text-[#00338D]">Roles y ámbitos de bonos</h1><Link :href="route('financial.bonuses.admin.index')" class="text-sm text-[#00338D]">Volver a administrar bonos</Link></div></template>
        <main class="min-h-screen bg-[#F5F8FC] px-4 py-8"><div class="mx-auto max-w-5xl space-y-6">
            <p class="text-sm text-slate-600">Las asignaciones se aplican al contexto indicado. Una revocación conserva las operaciones ya registradas.</p>
            <p v-if="!twoFactorReady" class="rounded-xl bg-amber-50 p-4 text-sm text-amber-900">Confirma la autenticación de dos factores en tu cuenta para asignar o revocar roles. Puedes consultar el historial.</p>
            <p v-if="error" role="alert" class="rounded-xl bg-red-50 p-4 text-red-700">{{ error }}</p><p v-if="message" role="status" class="rounded-xl bg-emerald-50 p-4 text-emerald-800">{{ message }}</p>
            <section class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm"><h2 class="text-lg font-bold text-[#00338D]">Seleccionar cuenta</h2><form @submit.prevent="lookup" class="mt-4 flex flex-wrap gap-3"><input v-model="email" :disabled="busy" type="email" required placeholder="Correo de la cuenta" class="min-w-0 flex-1 rounded-lg border-slate-300" /><button :disabled="busy" class="rounded-lg bg-[#00338D] px-4 py-2 text-white">Buscar</button></form><p v-if="target" class="mt-4">{{ target.name }} · {{ target.email }}</p></section>
            <section v-if="target" class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm"><h2 class="text-lg font-bold text-[#00338D]">Asignar rol</h2><form @submit.prevent="assign" class="mt-4"><fieldset :disabled="busy || !twoFactorReady" class="grid gap-4 sm:grid-cols-2 disabled:opacity-50"><label>Rol<select v-model="roleKey" class="mt-1 w-full rounded-lg border-slate-300"><option v-for="role in roles" :key="role.key" :value="role.key">{{ role.label }}</option></select></label><label v-if="selectedRole?.scope_type">Identificador de {{ scopeLabels[selectedRole.scope_type] }}<input v-model="scopeId" required maxlength="100" class="mt-1 w-full rounded-lg border-slate-300" /></label><p v-else class="self-center text-sm">Ámbito global</p><label>Inicio opcional<input v-model="start" type="datetime-local" class="mt-1 w-full rounded-lg border-slate-300" /></label><label>Fin opcional<input v-model="end" type="datetime-local" class="mt-1 w-full rounded-lg border-slate-300" /></label><label class="sm:col-span-2">Motivo<textarea v-model="reason" required maxlength="1000" class="mt-1 w-full rounded-lg border-slate-300" /></label><p v-if="selectedRole?.scope_type === 'business'" class="text-sm text-slate-600 sm:col-span-2">Asignar este rol también registra la membresía en ese negocio si no existe. Una membresía suspendida requiere revisión por separado.</p><button class="rounded-lg bg-[#00338D] px-4 py-2 font-semibold text-white">Asignar con auditoría</button></fieldset></form></section>
            <section v-if="target" class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm"><h2 class="text-lg font-bold text-[#00338D]">Asignaciones e historial</h2><p v-if="!rows.length" class="mt-4 text-slate-500">No hay asignaciones de este catálogo.</p><article v-for="row in rows" :key="row.id" class="mt-4 rounded-xl border border-slate-200 p-4"><div class="flex flex-wrap justify-between gap-3"><p class="font-semibold">{{ roles.find(r => r.key === row.role_key)?.label || row.role_key }} · {{ statusLabels[row.status] || row.status }}</p><button v-if="row.is_current" :disabled="busy || !twoFactorReady" @click="revoke(row)" class="text-sm text-red-700 disabled:opacity-40">Revocar</button></div><p class="mt-2 break-all text-sm">{{ scopeLabels[row.scope_type] || 'Global' }} {{ row.scope_id || '' }} · Generación {{ row.generation }}</p><p class="mt-1 text-sm">Motivo de asignación: {{ row.initial_reason || row.reason }}</p><p v-for="(transition, index) in row.transitions" :key="index" class="mt-1 text-xs text-slate-600">{{ statusLabels[transition.to] }} · {{ transition.reason }} · {{ date(transition.at) }} · Responsable: {{ transition.actor_id }}</p><p class="mt-1 text-xs text-slate-500">Vigencia: {{ date(row.starts_at) }} — {{ date(row.ends_at) }} · Responsable: {{ row.assigned_by }}</p></article><nav v-if="meta?.last_page > 1" class="mt-5 flex justify-center gap-4"><button :disabled="busy || meta.current_page <= 1" @click="load(meta.current_page - 1).catch(e => error = errorText(e))">Anterior</button><span>{{ meta.current_page }} / {{ meta.last_page }}</span><button :disabled="busy || meta.current_page >= meta.last_page" @click="load(meta.current_page + 1).catch(e => error = errorText(e))">Siguiente</button></nav></section>
        </div></main>
    </AuthenticatedLayout>
</template>
