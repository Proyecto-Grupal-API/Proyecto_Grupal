<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link } from '@inertiajs/vue3';
defineProps({ receipts: Object, hasWallet: Boolean });
const date = value => value ? new Date(value).toLocaleString('es-MX') : '';
</script>
<template>
<Head title="Mis comprobantes" />
<AuthenticatedLayout>
<template #header><h2 class="text-2xl font-bold text-[#00338D]">Mis comprobantes · 2.9</h2></template>
<div class="bg-[#F5F8FC] min-h-screen p-6"><div class="max-w-5xl mx-auto">
<Link :href="route('financial.dashboard')" class="text-blue-800">Volver a Finanzas</Link>
<section class="bg-white rounded-xl border p-6 mt-5">
<p class="mb-5 text-slate-600">Consulta la evidencia de tus operaciones y guarda una copia con la impresión del navegador.</p>
<p v-if="!hasWallet">Aún no tienes una wallet registrada.</p>
<p v-else-if="!receipts.data.length">No hay operaciones registradas.</p>
<div v-for="row in receipts.data" :key="row.source + row.operation_id" class="border-t py-4 flex flex-wrap justify-between gap-3">
<div><p class="font-semibold">{{ row.source === 'PURCHASE' ? 'Pago de compra' : 'Movimiento financiero' }}</p><p class="text-sm text-slate-500">{{ date(row.created_at) }}</p><p class="font-mono text-xs break-all">{{ row.operation_id }}</p></div>
<Link :href="route('financial.receipts.show', { source: row.source.toLowerCase(), operationId: row.operation_id })" class="text-blue-800 font-semibold">Ver comprobante</Link>
</div>
<nav class="mt-5 flex flex-wrap gap-3" aria-label="Paginación"><template v-for="(link, index) in receipts.links" :key="index"><Link v-if="link.url" :href="link.url" :class="link.active ? 'font-bold text-blue-800' : 'text-slate-600'"><span v-html="link.label" /></Link><span v-else class="text-slate-400" v-html="link.label" /></template></nav>
</section></div></div>
</AuthenticatedLayout></template>
