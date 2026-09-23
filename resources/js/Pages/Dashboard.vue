<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head } from '@inertiajs/vue3';
</script>

<template>
    <Head title="Inicio" />

    <AuthenticatedLayout>
        <template #header>
            <div>
                <p class="text-xs font-bold uppercase tracking-[0.22em] text-[#0284C7]">Campus Virtual</p>
                <h2 class="mt-1 text-2xl font-bold tracking-tight text-[#00338D]">Inicio</h2>
            </div>
        </template>

        <div class="min-h-[calc(100vh-9rem)] bg-[#F5F8FC] px-4 py-8 sm:px-6 lg:px-8">
            <div class="mx-auto max-w-7xl">
                <section class="rounded-2xl bg-[#00338D] p-7 text-white shadow-xl shadow-[#00338D]/10 sm:p-10">
                    <p class="text-sm text-blue-100">Bienvenido de nuevo</p>
                    <h1 class="mt-2 text-3xl font-bold sm:text-4xl">Tu experiencia universitaria, en un solo lugar.</h1>
                    <p class="mt-4 max-w-2xl leading-7 text-blue-100">Consulta tu condición estudiantil y administra tus preferencias de comunicación.</p>
                </section>
                <div class="mt-6 grid gap-5 md:grid-cols-2">
                    <a :href="route('student-services.index')" class="group rounded-2xl border border-slate-200 bg-white p-6 shadow-sm transition hover:-translate-y-1 hover:border-[#0284C7]">
                        <span class="text-xs font-bold uppercase tracking-[0.18em] text-[#0284C7]">Módulo 1.8</span>
                        <h2 class="mt-3 text-xl font-bold text-[#00338D]">Condición estudiantil</h2>
                        <p class="mt-2 text-sm leading-6 text-slate-500">Revisa tu estado académico, matrícula y restricciones.</p>
                        <span class="mt-6 inline-block text-sm font-bold text-[#00338D] group-hover:text-[#0284C7]">Consultar estado →</span>
                    </a>
                    <a :href="route('student-services.index')" class="group rounded-2xl border border-slate-200 bg-white p-6 shadow-sm transition hover:-translate-y-1 hover:border-[#10B981]">
                        <span class="text-xs font-bold uppercase tracking-[0.18em] text-[#10B981]">Módulo 1.9</span>
                        <h2 class="mt-3 text-xl font-bold text-[#00338D]">Privacidad y preferencias</h2>
                        <p class="mt-2 text-sm leading-6 text-slate-500">Administra tus consentimientos y canales de comunicación.</p>
                        <span class="mt-6 inline-block text-sm font-bold text-[#00338D] group-hover:text-[#10B981]">Gestionar preferencias →</span>
                    </a>
                    <a :href="route('identity.qr.index')" class="group rounded-2xl border border-slate-200 bg-white p-6 shadow-sm transition hover:-translate-y-1 hover:border-[#00338D]">
                        <span class="text-xs font-bold uppercase tracking-[0.18em] text-[#00338D]">Módulo 1.6</span>
                        <h2 class="mt-3 text-xl font-bold text-[#00338D]">Identidad QR</h2>
                        <p class="mt-2 text-sm leading-6 text-slate-500">Tu QR de identificación y tu código dinámico para validar operaciones sensibles.</p>
                        <span class="mt-6 inline-block text-sm font-bold text-[#00338D] group-hover:text-[#0284C7]">Ver mi QR →</span>
                    </a>
                    <a :href="route('security.devices.index')" class="group rounded-2xl border border-slate-200 bg-white p-6 shadow-sm transition hover:-translate-y-1 hover:border-[#0284C7]">
                        <span class="text-xs font-bold uppercase tracking-[0.18em] text-[#0284C7]">Módulo 1.7</span>
                        <h2 class="mt-3 text-xl font-bold text-[#00338D]">Dispositivos y sesiones</h2>
                        <p class="mt-2 text-sm leading-6 text-slate-500">Revisa desde dónde ha ingresado tu cuenta y cierra sesiones remotas.</p>
                        <span class="mt-6 inline-block text-sm font-bold text-[#00338D] group-hover:text-[#0284C7]">Administrar accesos →</span>
                    </a>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
