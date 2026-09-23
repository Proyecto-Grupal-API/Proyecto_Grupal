<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, router } from '@inertiajs/vue3';
import { ref } from 'vue';

const props = defineProps({
    devices: { type: Array, default: () => [] },
    events: { type: Array, default: () => [] },
    eventsPageSize: { type: Number, default: 20 },
    reauthValidMinutes: { type: Number, default: 5 },
    maxActiveSessions: { type: Number, default: 5 },
});

const events = ref(props.events);
const eventsOffset = ref(props.events.length);
const eventsHasMore = ref(props.events.length >= props.eventsPageSize);
const eventsLoading = ref(false);

async function loadMoreEvents() {
    eventsLoading.value = true;
    try {
        const { data } = await window.axios.get(route('security.events'), {
            params: { offset: eventsOffset.value },
        });
        events.value = [...events.value, ...data.items];
        eventsOffset.value = data.next_offset;
        eventsHasMore.value = data.has_more;
    } finally {
        eventsLoading.value = false;
    }
}

// --- Reautenticación para acciones sensibles (Módulo 1.7) ---
const showReauth = ref(false);
const reauthPassword = ref('');
const reauthError = ref('');
const reauthLoading = ref(false);
let pendingAction = null;

function requestReauth(action) {
    pendingAction = action;
    reauthPassword.value = '';
    reauthError.value = '';
    showReauth.value = true;
}

async function confirmReauth() {
    reauthLoading.value = true;
    reauthError.value = '';
    try {
        await window.axios.post(route('security.reauth'), { password: reauthPassword.value });
        showReauth.value = false;
        const action = pendingAction;
        pendingAction = null;
        if (action) await action();
    } catch (e) {
        reauthError.value = e.response?.data?.message ?? 'No se pudo confirmar tu contraseña.';
    } finally {
        reauthLoading.value = false;
    }
}

async function runSensitive(method, url, payload = {}) {
    try {
        await window.axios({ method, url, data: payload });
        router.reload({ only: ['devices', 'events'] });
    } catch (e) {
        if (e.response?.status === 428) {
            requestReauth(() => runSensitive(method, url, payload));
        }
    }
}

function revoke(sessionId) {
    runSensitive('post', route('security.sessions.revoke', sessionId));
}

function revokeOthers() {
    runSensitive('post', route('security.sessions.revoke-others'));
}

function toggleTrust(device) {
    runSensitive('post', route('security.devices.trust', device.id), { trusted: !device.is_trusted });
}

function removeDevice(device) {
    if (!confirm(`¿Eliminar "${device.device_name}"? Se cerrará cualquier sesión activa en ese dispositivo.`)) {
        return;
    }
    runSensitive('delete', route('security.devices.destroy', device.id));
}

const severityStyles = {
    info: 'bg-emerald-50 text-emerald-700',
    warning: 'bg-amber-50 text-amber-700',
    critical: 'bg-rose-50 text-rose-700',
};

const eventLabels = {
    login_success: 'Inicio de sesión',
    login_failed: 'Intento de inicio fallido',
    new_device: 'Nuevo dispositivo detectado',
    session_revoked: 'Sesión revocada',
    session_forced_logout: 'Sesión cerrada de forma remota',
    session_limit_exceeded: 'Sesión antigua cerrada por límite de sesiones activas',
    device_trusted: 'Dispositivo marcado como confiable',
    device_untrusted: 'Dispositivo marcado como no confiable',
    device_removed: 'Dispositivo eliminado',
    qr_generated: 'QR de identidad generado',
    qr_validated: 'QR validado correctamente',
    qr_validation_failed: 'Intento de validación de QR fallido',
    reauth_success: 'Contraseña confirmada (reautenticación)',
    reauth_failed: 'Intento de reautenticación fallido',
};
</script>

<template>
    <Head title="Dispositivos y sesiones" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
                <div>
                    <p class="text-xs font-bold uppercase tracking-[0.22em] text-[#0284C7]">Módulo 1.7</p>
                    <h2 class="mt-1 text-2xl font-bold tracking-tight text-[#00338D]">Dispositivos y sesiones confiables</h2>
                </div>
                <button
                    @click="revokeOthers"
                    class="rounded-lg border border-slate-200 px-4 py-2 text-xs font-semibold text-slate-600 transition hover:border-[#0284C7] hover:text-[#0284C7]"
                >
                    Cerrar todas las demás sesiones
                </button>
            </div>
        </template>

        <div class="min-h-[calc(100vh-9rem)] bg-[#F5F8FC] px-4 py-8 sm:px-6 lg:px-8">
            <div class="mx-auto max-w-6xl">
                <p class="mb-6 max-w-2xl text-sm text-slate-500">
                    Administra desde dónde ha ingresado tu cuenta y revoca el acceso a cualquier dispositivo que ya no
                    reconozcas. Como máximo se permiten {{ maxActiveSessions }} sesiones activas a la vez; si abres una
                    nueva y ya alcanzaste el límite, la más antigua se cierra automáticamente.
                </p>

                <div class="grid grid-cols-1 gap-6 lg:grid-cols-3">
                    <div class="space-y-4 lg:col-span-2">
                        <div v-for="device in devices" :key="device.id" class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                            <div class="flex items-start justify-between gap-4">
                                <div class="flex items-start gap-3">
                                    <div class="flex h-11 w-11 items-center justify-center rounded-xl bg-[#E0F2FE] text-lg text-[#0284C7]">
                                        🖥
                                    </div>
                                    <div>
                                        <p class="flex items-center gap-2 font-bold text-slate-800">
                                            {{ device.device_name }}
                                            <span v-if="device.is_new" class="rounded-full bg-[#0284C7] px-2 py-0.5 text-[10px] font-bold text-white">Nuevo</span>
                                        </p>
                                        <p class="text-xs text-slate-500">
                                            {{ device.platform }} · {{ device.browser }} · última vez: {{ device.last_seen_at }}
                                        </p>
                                        <p class="text-xs text-slate-500">IP: {{ device.last_ip_address ?? 'N/D' }}</p>
                                    </div>
                                </div>

                                <div class="flex flex-col items-end gap-2">
                                    <span v-if="device.is_trusted" class="rounded-full bg-emerald-50 px-2.5 py-1 text-[11px] font-bold text-emerald-700">Confiable</span>
                                    <span v-else class="rounded-full bg-amber-50 px-2.5 py-1 text-[11px] font-bold text-amber-700">No confiable</span>
                                    <button @click="toggleTrust(device)" class="text-xs font-semibold text-[#0284C7] hover:underline">
                                        {{ device.is_trusted ? 'Quitar confianza' : 'Marcar como confiable' }}
                                    </button>
                                    <button @click="removeDevice(device)" class="text-xs font-semibold text-rose-600 hover:underline">
                                        Eliminar dispositivo
                                    </button>
                                </div>
                            </div>

                            <div class="mt-4 space-y-2 border-t border-slate-100 pt-4">
                                <div
                                    v-for="session in device.sessions"
                                    :key="session.id"
                                    class="flex items-center justify-between text-sm"
                                >
                                    <div>
                                        <p class="font-medium text-slate-700">
                                            {{ session.ip_address ?? 'IP no disponible' }}
                                            <span v-if="session.is_current" class="ml-2 rounded-full bg-emerald-50 px-2 py-0.5 text-[10px] font-bold text-emerald-700">Sesión actual</span>
                                        </p>
                                        <p class="text-xs text-slate-500">
                                            Iniciada {{ session.started_at }} · actividad {{ session.last_activity_at }}
                                        </p>
                                    </div>
                                    <button
                                        v-if="!session.is_current"
                                        @click="revoke(session.id)"
                                        class="rounded-lg border border-rose-200 px-3 py-1.5 text-xs font-semibold text-rose-600 hover:bg-rose-50"
                                    >
                                        Cerrar sesión
                                    </button>
                                </div>
                                <p v-if="device.sessions.length === 0" class="text-xs text-slate-500">
                                    Sin sesiones activas en este dispositivo.
                                </p>
                            </div>
                        </div>

                        <div v-if="devices.length === 0" class="rounded-2xl border border-slate-200 bg-white p-8 text-center text-sm text-slate-500 shadow-sm">
                            Aún no se han registrado dispositivos.
                        </div>
                    </div>

                    <!-- Alertas de acceso -->
                    <div class="h-fit rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
                        <h3 class="mb-1 text-sm font-bold uppercase tracking-wide text-slate-400">Alertas de acceso</h3>
                        <p class="mb-4 text-xs text-slate-500">Bitácora de seguridad</p>

                        <ul class="space-y-3">
                            <li v-for="e in events" :key="e.id" class="border-b border-slate-100 pb-3 text-sm last:border-0 last:pb-0">
                                <div class="flex items-center justify-between">
                                    <p class="font-medium text-slate-700">{{ eventLabels[e.type] ?? e.type }}</p>
                                    <span class="rounded-full px-2 py-0.5 text-[10px] font-bold" :class="severityStyles[e.severity] ?? 'bg-emerald-50 text-emerald-700'">
                                        {{ e.severity }}
                                    </span>
                                </div>
                                <p class="mt-1 text-xs text-slate-500">{{ e.occurred_at }} · {{ e.ip_address ?? 'N/D' }}</p>
                            </li>
                            <li v-if="events.length === 0" class="py-4 text-center text-sm text-slate-500">
                                Sin eventos registrados todavía.
                            </li>
                        </ul>
                        <button
                            v-if="eventsHasMore"
                            @click="loadMoreEvents"
                            class="mt-4 w-full rounded-lg border border-slate-200 py-2 text-xs font-semibold text-slate-600 transition hover:border-[#0284C7] hover:text-[#0284C7] disabled:opacity-50"
                            :disabled="eventsLoading"
                        >
                            {{ eventsLoading ? 'Cargando…' : 'Cargar más' }}
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <!-- Modal de reautenticación -->
        <div v-if="showReauth" class="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/40 px-4">
            <div class="w-full max-w-sm rounded-2xl border border-slate-200 bg-white p-6 shadow-xl">
                <h3 class="mb-1 font-bold text-slate-800">Confirma tu contraseña</h3>
                <p class="mb-4 text-xs text-slate-500">
                    Por seguridad, esta acción requiere que confirmes tu contraseña. La confirmación es válida
                    por {{ reauthValidMinutes }} minutos.
                </p>
                <input
                    v-model="reauthPassword"
                    type="password"
                    class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-[#0284C7] focus:ring-[#0284C7]"
                    placeholder="Tu contraseña"
                    @keyup.enter="confirmReauth"
                    autofocus
                />
                <p v-if="reauthError" class="mt-2 text-xs text-rose-600">{{ reauthError }}</p>
                <div class="mt-4 flex justify-end gap-2">
                    <button @click="showReauth = false" class="rounded-lg border border-slate-200 px-4 py-2 text-xs font-semibold text-slate-600 hover:border-slate-300">
                        Cancelar
                    </button>
                    <button
                        @click="confirmReauth"
                        class="rounded-lg bg-[#00338D] px-4 py-2 text-xs font-semibold text-white transition hover:bg-[#0284C7] disabled:opacity-50"
                        :disabled="reauthLoading || !reauthPassword"
                    >
                        Confirmar
                    </button>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
