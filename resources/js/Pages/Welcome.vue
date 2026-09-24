<script setup>
import ApplicationLogo from '@/Components/ApplicationLogo.vue';
import { Head, Link } from '@inertiajs/vue3';

defineProps({
    canLogin: Boolean,
    canRegister: Boolean,
});
</script>

<template>
    <Head title="Inicio" />

    <div class="min-h-screen overflow-hidden bg-[#F5F8FC] text-slate-800">
        <header class="mx-auto flex max-w-7xl items-center justify-between px-5 py-6 sm:px-8">
            <Link href="/" class="h-14 w-32 sm:h-16 sm:w-40">
                <ApplicationLogo class="h-full w-full" />
            </Link>
            <nav class="flex items-center gap-3">
                <Link v-if="canLogin" :href="route('login')" class="rounded-lg px-4 py-2 text-sm font-bold text-[#00338D] transition hover:bg-white">Ingresar</Link>
                <Link v-if="canRegister" :href="route('register')" class="rounded-lg bg-[#00338D] px-4 py-2 text-sm font-bold text-white shadow-lg shadow-[#00338D]/20 transition hover:bg-[#0284C7]">Crear cuenta</Link>
            </nav>
        </header>

        <main class="mx-auto grid max-w-7xl items-center gap-12 px-5 pb-20 pt-10 sm:px-8 lg:grid-cols-[1.1fr_.9fr] lg:pb-28 lg:pt-20">
            <section>
                <p class="text-sm font-bold uppercase tracking-[0.25em] text-[#0284C7]">Plataforma estudiantil</p>
                <h1 class="mt-5 max-w-2xl text-5xl font-extrabold leading-[1.05] tracking-tight text-[#00338D] sm:text-7xl">Todo tu campus, más cerca.</h1>
                <p class="mt-7 max-w-xl text-lg leading-8 text-slate-500">Una identidad digital para acceder a tus servicios, beneficios y experiencias universitarias desde un solo lugar.</p>
                <div class="mt-9 flex flex-wrap gap-4">
                    <Link v-if="canLogin" :href="route('login')" class="rounded-xl bg-[#00338D] px-6 py-3.5 text-sm font-bold text-white shadow-xl shadow-[#00338D]/20 transition hover:-translate-y-0.5 hover:bg-[#0284C7]">Entrar a Campus Virtual</Link>
                    <a href="#servicios" class="rounded-xl border border-slate-200 bg-white px-6 py-3.5 text-sm font-bold text-[#00338D] transition hover:border-[#0284C7]">Conocer servicios</a>
                </div>
            </section>

            <section id="servicios" class="relative rounded-[2rem] bg-[#00338D] p-7 text-white shadow-2xl shadow-[#00338D]/20 sm:p-10">
                <div class="absolute right-8 top-8 h-24 w-24 rounded-full border border-white/20"></div>
                <div class="relative">
                    <div class="flex h-14 w-14 items-center justify-center overflow-hidden rounded-2xl bg-white p-1.5">
                        <img src="/images/logo-mark.svg?v=4" alt="Campus Digital" class="h-full w-full object-contain" />
                    </div>
                    <h2 class="mt-10 text-3xl font-bold">Una cuenta.<br />Más posibilidades.</h2>
                    <div class="mt-8 grid gap-3 text-sm text-blue-100 sm:grid-cols-2">
                        <div class="rounded-xl border border-white/15 bg-white/10 p-4">Identidad estudiantil</div>
                        <div class="rounded-xl border border-white/15 bg-white/10 p-4">Servicios universitarios</div>
                        <div class="rounded-xl border border-white/15 bg-white/10 p-4">Credencial NFC y QR</div>
                        <div class="rounded-xl border border-white/15 bg-white/10 p-4">Beneficios y comunidad</div>
                    </div>
                </div>
            </section>
        </main>
    </div>
</template>
