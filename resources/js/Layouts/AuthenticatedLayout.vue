<script setup>
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import ApplicationLogo from '@/Components/ApplicationLogo.vue';
import Dropdown from '@/Components/Dropdown.vue';
import DropdownLink from '@/Components/DropdownLink.vue';
import NavLink from '@/Components/NavLink.vue';
import ResponsiveNavLink from '@/Components/ResponsiveNavLink.vue';
import { Link, usePage } from '@inertiajs/vue3';

const showingNavigationDropdown = ref(false);

// --- Banner de mensajes flash (antes se generaban pero nunca se veían) ---
const page = usePage();
const flash = computed(() => page.props.flash ?? {});
const dismissedFlash = ref(false);

// --- Latido: detecta en segundos si esta pestaña fue revocada desde
// otro dispositivo/pestaña, en vez de esperar a que el usuario navegue
// por su cuenta (Módulo 1.7). ---
const sessionRevokedOverlay = ref(false);
let heartbeatTimer = null;

async function checkHeartbeat() {
    try {
        const res = await window.axios.get(route('security.heartbeat'), {
            headers: { Accept: 'application/json' },
            validateStatus: () => true,
        });

        const isJsonOk = res.headers['content-type']?.includes('application/json') && res.data?.ok === true;

        if (!isJsonOk) {
            // La respuesta no fue el JSON esperado: la sesión fue
            // invalidada a mitad de camino (revocada desde otra
            // pestaña) y el navegador terminó siguiendo la redirección
            // hasta el HTML de otra pantalla.
            sessionRevokedOverlay.value = true;
            clearInterval(heartbeatTimer);
        }
    } catch (e) {
        // Error de red: no lo tratamos como revocación, solo se
        // reintenta en el siguiente latido.
    }
}

function acknowledgeRevoked() {
    window.location.href = route('login');
}

onMounted(() => {
    heartbeatTimer = setInterval(checkHeartbeat, 8000);
});

onBeforeUnmount(() => {
    clearInterval(heartbeatTimer);
});
</script>

<template>
    <div>
        <div class="min-h-screen bg-gray-100">
            <nav class="border-b border-gray-100 bg-white">
                <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
                    <div class="flex h-16 justify-between">
                        <div class="flex">
                            <div class="flex shrink-0 items-center">
                                <Link :href="route('dashboard')">
                                    <ApplicationLogo class="h-8 w-28" />
                                </Link>
                            </div>

                            <div class="hidden space-x-8 sm:-my-px sm:ms-10 sm:flex">
                                <NavLink :href="route('dashboard')" :active="route().current('dashboard')">
                                    Inicio
                                </NavLink>
                                <NavLink :href="route('student-services.index')" :active="route().current('student-services.index')">
                                    Mi condición
                                </NavLink>
                                <NavLink :href="route('students.index')" :active="route().current('students.*')">
                                    Estudiantes
                                </NavLink>
                                <NavLink :href="route('roles.index')" :active="route().current('roles.index')">
                                    Roles y Seguridad
                                </NavLink>
                                <NavLink :href="route('security.devices.index')" :active="route().current('security.devices.index')">
                                    Dispositivos
                                </NavLink>
                                <NavLink :href="route('identity.qr.index')" :active="route().current('identity.qr.index')">
                                    Identidad QR
                                </NavLink>
                            </div>
                        </div>

                        <div class="hidden sm:ms-6 sm:flex sm:items-center">
                            <div class="relative ms-3">
                                <Dropdown align="right" width="48">
                                    <template #trigger>
                                        <span class="inline-flex rounded-md">
                                            <button
                                                type="button"
                                                class="inline-flex items-center rounded-md border border-transparent bg-white px-3 py-2 text-sm font-medium leading-4 text-gray-500 transition duration-150 ease-in-out hover:text-gray-700 focus:outline-none"
                                            >
                                                {{ $page.props.auth.user.name }}
                                                <svg class="-me-0.5 ms-2 h-4 w-4" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor">
                                                    <path fill-rule="evenodd" d="M5.293 7.293a1 1 0 011.414 0L10 10.586l3.293-3.293a1 1 0 111.414 1.414l-4 4a1 1 0 01-1.414 0l-4-4a1 1 0 010-1.414z" clip-rule="evenodd" />
                                                </svg>
                                            </button>
                                        </span>
                                    </template>
                                    <template #content>
                                        <DropdownLink :href="route('profile.edit')"> Profile </DropdownLink>
                                        <DropdownLink :href="route('logout')" method="post" as="button"> Log Out </DropdownLink>
                                    </template>
                                </Dropdown>
                            </div>
                        </div>

                        <div class="-me-2 flex items-center sm:hidden">
                            <button
                                @click="showingNavigationDropdown = !showingNavigationDropdown"
                                class="inline-flex items-center justify-center rounded-md p-2 text-gray-400 transition duration-150 ease-in-out hover:bg-gray-100 hover:text-gray-500 focus:bg-gray-100 focus:text-gray-500 focus:outline-none"
                            >
                                <svg class="h-6 w-6" stroke="currentColor" fill="none" viewBox="0 0 24 24">
                                    <path :class="{ hidden: showingNavigationDropdown, 'inline-flex': !showingNavigationDropdown }" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16" />
                                    <path :class="{ hidden: !showingNavigationDropdown, 'inline-flex': showingNavigationDropdown }" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                                </svg>
                            </button>
                        </div>
                    </div>
                </div>

                <div :class="{ block: showingNavigationDropdown, hidden: !showingNavigationDropdown }" class="sm:hidden">
                    <div class="space-y-1 pb-3 pt-2">
                        <ResponsiveNavLink :href="route('dashboard')" :active="route().current('dashboard')">
                            Inicio
                        </ResponsiveNavLink>
                        <ResponsiveNavLink :href="route('student-services.index')" :active="route().current('student-services.index')">
                            Mi condición
                        </ResponsiveNavLink>
                        <ResponsiveNavLink :href="route('students.index')" :active="route().current('students.*')">
                            Estudiantes
                        </ResponsiveNavLink>
                        <ResponsiveNavLink :href="route('roles.index')" :active="route().current('roles.index')">
                            Roles y Seguridad
                        </ResponsiveNavLink>
                        <ResponsiveNavLink :href="route('security.devices.index')" :active="route().current('security.devices.index')">
                            Dispositivos
                        </ResponsiveNavLink>
                        <ResponsiveNavLink :href="route('identity.qr.index')" :active="route().current('identity.qr.index')">
                            Identidad QR
                        </ResponsiveNavLink>
                    </div>

                    <div class="border-t border-gray-200 pb-1 pt-4">
                        <div class="px-4">
                            <div class="text-base font-medium text-gray-800">{{ $page.props.auth.user.name }}</div>
                            <div class="text-sm font-medium text-gray-500">{{ $page.props.auth.user.email }}</div>
                        </div>
                        <div class="mt-3 space-y-1">
                            <ResponsiveNavLink :href="route('profile.edit')"> Profile </ResponsiveNavLink>
                            <ResponsiveNavLink :href="route('logout')" method="post" as="button"> Log Out </ResponsiveNavLink>
                        </div>
                    </div>
                </div>
            </nav>

            <!-- Banner de mensajes flash (éxito / error / estado) -->
            <div
                v-if="!dismissedFlash && (flash.success || flash.error || flash.status)"
                class="border-b px-4 py-3 text-sm sm:px-6 lg:px-8"
                :class="flash.error
                    ? 'border-rose-200 bg-rose-50 text-rose-700'
                    : 'border-emerald-200 bg-emerald-50 text-emerald-700'"
            >
                <div class="mx-auto flex max-w-7xl items-center justify-between gap-4">
                    <span>{{ flash.error ?? flash.success ?? flash.status }}</span>
                    <button @click="dismissedFlash = true" class="text-xs font-semibold opacity-70 hover:opacity-100">
                        Cerrar
                    </button>
                </div>
            </div>

            <header class="bg-white shadow" v-if="$slots.header">
                <div class="mx-auto max-w-7xl px-4 py-6 sm:px-6 lg:px-8">
                    <slot name="header" />
                </div>
            </header>

            <main>
                <slot />
            </main>
        </div>

        <!-- Aviso de sesión revocada desde otro dispositivo (Módulo 1.7) -->
        <div v-if="sessionRevokedOverlay" class="fixed inset-0 z-[60] flex items-center justify-center bg-slate-900/50 px-4">
            <div class="w-full max-w-sm rounded-2xl border border-slate-200 bg-white p-6 text-center shadow-xl">
                <div class="mx-auto mb-3 flex h-12 w-12 items-center justify-center rounded-full bg-rose-50 text-2xl">
                    🔒
                </div>
                <h3 class="mb-1 font-bold text-slate-800">Tu sesión fue cerrada</h3>
                <p class="mb-4 text-sm text-slate-500">
                    Se revocó esta sesión desde otro dispositivo o pestaña. Continúa para volver a entrar.
                </p>
                <button
                    @click="acknowledgeRevoked"
                    class="w-full rounded-lg bg-[#00338D] py-2 text-sm font-semibold text-white transition hover:bg-[#0284C7]"
                >
                    Entendido, continuar
                </button>
            </div>
        </div>
    </div>
</template>