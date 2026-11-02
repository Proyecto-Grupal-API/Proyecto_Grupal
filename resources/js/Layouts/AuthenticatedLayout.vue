<script setup>
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { usePage } from '@inertiajs/vue3';
import AppSidebar from '@/Components/Campus/AppSidebar.vue';
import AppTopbar from '@/Components/Campus/AppTopbar.vue';

const page = usePage();
const sidebarOpen = ref(false);
const flash = computed(() => page.props.flash ?? {});
const dismissedFlash = ref(false);
watch(flash, () => { dismissedFlash.value = false; });

// Preserve the existing remote-session revocation notice and heartbeat.
const sessionRevokedOverlay = ref(false);
let heartbeatTimer = null;

async function checkHeartbeat() {
    try {
        const response = await window.axios.get(route('security.heartbeat'), {
            headers: { Accept: 'application/json' },
            validateStatus: () => true,
        });
        const valid = response.headers['content-type']?.includes('application/json') && response.data?.ok === true;
        if (!valid) {
            sessionRevokedOverlay.value = true;
            clearInterval(heartbeatTimer);
        }
    } catch {
        // A network failure is not evidence of revocation; retry on the next beat.
    }
}

function acknowledgeRevoked() {
    window.location.href = route('login');
}

onMounted(() => { heartbeatTimer = setInterval(checkHeartbeat, 8000); });
onBeforeUnmount(() => { clearInterval(heartbeatTimer); });
</script>

<template>
    <div class="min-h-screen bg-[#f4f7fb] lg:flex">
        <AppSidebar :open="sidebarOpen" @close="sidebarOpen = false" />

        <div class="flex min-h-screen min-w-0 flex-1 flex-col">
            <AppTopbar :user="page.props.auth.user" @toggle-sidebar="sidebarOpen = true" />

            <div v-if="!dismissedFlash && (flash.success || flash.error || flash.status)" role="status" class="border-b px-4 py-3 text-sm sm:px-6" :class="flash.error ? 'border-rose-200 bg-rose-50 text-rose-700' : 'border-emerald-200 bg-emerald-50 text-emerald-700'">
                <div class="flex items-center justify-between gap-4">
                    <span>{{ flash.error ?? flash.success ?? flash.status }}</span>
                    <button type="button" class="rounded text-xs font-semibold underline focus-visible:outline focus-visible:outline-2" @click="dismissedFlash = true">Cerrar</button>
                </div>
            </div>

            <header v-if="$slots.header" class="border-b border-slate-200 bg-white px-4 py-5 shadow-sm sm:px-6">
                <slot name="header" />
            </header>

            <main id="main-content" class="min-w-0 flex-1">
                <slot />
            </main>
        </div>

        <div v-if="sessionRevokedOverlay" class="fixed inset-0 z-[60] flex items-center justify-center bg-slate-900/50 px-4" role="alertdialog" aria-modal="true" aria-labelledby="revoked-title">
            <div class="w-full max-w-sm rounded-2xl border border-slate-200 bg-white p-6 text-center shadow-xl">
                <div class="mx-auto mb-3 flex h-12 w-12 items-center justify-center rounded-full bg-rose-50 text-rose-700" aria-hidden="true">!</div>
                <h3 id="revoked-title" class="mb-1 font-bold text-slate-800">Tu sesión fue cerrada</h3>
                <p class="mb-4 text-sm text-slate-500">Se revocó esta sesión desde otro dispositivo o pestaña. Continúa para volver a entrar.</p>
                <button type="button" class="w-full rounded-lg bg-[#00338D] py-2 text-sm font-semibold text-white transition hover:bg-[#0284C7] focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-blue-600" @click="acknowledgeRevoked">Entendido, continuar</button>
            </div>
        </div>
    </div>
</template>
