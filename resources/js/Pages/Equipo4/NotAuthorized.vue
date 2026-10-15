<script setup>
import { Head, Link, router } from '@inertiajs/vue3';
import { ref } from 'vue';
const props=defineProps({ reason: String, businesses: { type: Array, default: () => [] } });
const business=ref(props.businesses[0]?.id || '');
</script>
<template>
    <Head title="Acceso a Inventarios" />
    <main class="min-h-screen bg-slate-50 flex items-center justify-center p-6">
        <section class="w-full max-w-lg rounded-2xl bg-white p-8 shadow-sm border space-y-5">
            <h1 class="text-2xl font-bold text-blue-900">{{ reason === 'identity_unavailable' ? 'No pudimos verificar tu acceso' : 'Acceso a Inventarios' }}</h1>
            <p class="text-slate-600">{{ reason === 'identity_unavailable' ? 'Intenta nuevamente en unos momentos.' : 'Necesitas una asignación vigente para administrar el inventario del negocio seleccionado.' }}</p>
            <form v-if="businesses.length && reason !== 'identity_unavailable'" @submit.prevent="router.post('/equipo4/context', { business_id: business })" class="space-y-3">
                <label for="inventory-business" class="block font-medium">Negocio</label>
                <select id="inventory-business" v-model="business" class="w-full rounded-lg border-slate-200">
                    <option v-for="item in businesses" :key="item.id" :value="item.id">{{ item.name }}</option>
                </select>
                <button class="rounded-lg bg-blue-800 px-4 py-2 text-white">Abrir inventario</button>
            </form>
            <div class="flex gap-4"><Link href="/dashboard" class="text-blue-700">Volver al inicio</Link><Link href="/equipo4" class="text-blue-700">Reintentar</Link></div>
        </section>
    </main>
</template>
