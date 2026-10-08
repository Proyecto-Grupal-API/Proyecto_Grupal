<script setup>
import ApplicationLogo from '@/Components/ApplicationLogo.vue';
import { Link } from '@inertiajs/vue3';
</script>

<template>
    <div class="flex min-h-screen items-stretch bg-[#F5F8FC]">
        <div class="hidden w-5/12 flex-col justify-between bg-[#00338D] p-10 text-white lg:flex">
            <div class="max-w-sm">
                <p class="text-xs font-bold uppercase tracking-[0.3em] text-blue-200">Campus Digital</p>
                <h1 class="mt-20 text-5xl font-bold leading-tight">Tu campus,<br />en una sola cuenta.</h1>
                <p class="mt-6 text-lg leading-8 text-blue-100">Identidad estudiantil, servicios y beneficios siempre contigo.</p>
            </div>
            <p class="text-sm text-blue-200">Plataforma estudiantil · 2026</p>
        </div>

        <div class="flex w-full flex-col items-center justify-center px-5 py-10 lg:w-7/12">
            <div class="mb-8 h-20 w-36 sm:h-24 sm:w-44">
            <Link href="/">
                <ApplicationLogo />
            </Link>
            </div>

        <div class="w-full max-w-md overflow-hidden rounded-2xl border border-slate-200 bg-white px-7 py-8 shadow-xl shadow-[#00338D]/5 sm:px-9">
            <slot />
        </div>
        </div>
    </div>
</template>
