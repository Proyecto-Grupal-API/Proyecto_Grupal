<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link } from '@inertiajs/vue3';
defineProps({ enabled: Boolean, requests: Object });
const money = cents => {
    const value = BigInt(cents);
    return '$' + new Intl.NumberFormat('es-MX').format(value / 100n) + '.' + String(value % 100n).padStart(2, '0') + ' MXN';
};
const status = value => ({ PENDING: 'Por confirmar', PAID: 'Pagada', CANCELLED: 'Cancelada', EXPIRED: 'Vencida' }[value] || value);
</script>
<template>
    <Head title="Compras por confirmar" />
    <AuthenticatedLayout>
        <template #header><h2 class="text-2xl font-bold text-[#00338D]">Compras por confirmar</h2></template>
        <main class="max-w-4xl mx-auto p-6 space-y-5">
            <Link :href="route('financial.dashboard')" class="text-blue-800 underline">Volver a Finanzas</Link>
            <p class="text-slate-600">Revisa las solicitudes de Marketplace. Crear una solicitud no descuenta dinero: tú decides si autorizas el pago.</p>
            <p v-if="!enabled" class="rounded-xl border bg-white p-6">Las compras desde Marketplace aún no están disponibles.</p>
            <p v-else-if="!requests.data.length" class="rounded-xl border bg-white p-6">No tienes solicitudes de compra.</p>
            <article v-for="row in requests.data" :key="row.payment_request_id" class="rounded-xl border bg-white p-5 space-y-2">
                <p class="font-semibold">{{ row.description || 'Solicitud de compra' }}</p>
                <p class="text-lg font-bold text-[#00338D]">{{ money(row.amount_cents) }} · {{ status(row.status) }}</p>
                <p class="text-sm text-slate-600 break-all">Pedido: {{ row.order_id }}</p>
                <Link :href="route('financial.marketplace.show', { paymentRequest: row.payment_request_id })" class="inline-block text-blue-800 underline">Ver detalle</Link>
            </article>
            <nav v-if="enabled" class="flex flex-wrap gap-3" aria-label="Paginación">
                <template v-for="(link, index) in requests.links" :key="index">
                    <Link v-if="link.url" :href="link.url" :class="link.active ? 'font-bold text-blue-800' : 'text-slate-600'"><span v-html="link.label" /></Link>
                    <span v-else class="text-slate-400" v-html="link.label" />
                </template>
            </nav>
        </main>
    </AuthenticatedLayout>
</template>
