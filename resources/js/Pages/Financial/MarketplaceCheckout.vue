<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
const props = defineProps({ paymentRequest: Object, reviewToken: String, passwordRecent: Boolean });
const form = useForm({ consent: false, review_token: props.reviewToken });
const cancellation = useForm({});
const money = cents => {
    const value = BigInt(cents);
    return '$' + new Intl.NumberFormat('es-MX').format(value / 100n) + '.' + String(value % 100n).padStart(2, '0') + ' MXN';
};
const pay = () => {
    form.review_token = props.reviewToken;
    form.post(route('financial.marketplace.confirm', { paymentRequest: props.paymentRequest.payment_request_id }));
};
const cancel = () => cancellation.post(route('financial.marketplace.cancel', { paymentRequest: props.paymentRequest.payment_request_id }));
</script>
<template>
    <Head title="Confirmar compra" />
    <AuthenticatedLayout>
        <template #header><h2 class="text-2xl font-bold text-[#00338D]">Revisa tu compra</h2></template>
        <main class="max-w-2xl mx-auto p-6 space-y-5">
            <Link :href="route('financial.marketplace.index')" class="text-blue-800 underline">Volver a mis compras</Link>
            <section class="rounded-xl border bg-white p-6 space-y-4">
                <p class="text-sm text-slate-500">Solicitud recibida de {{ paymentRequest.client_name }}</p>
                <h3 class="text-xl font-bold">{{ paymentRequest.business_name }}</h3>
                <p>{{ paymentRequest.description || 'Compra en Marketplace' }}</p>
                <p class="text-3xl font-bold text-[#00338D]">{{ money(paymentRequest.amount_cents) }}</p>
                <p class="text-sm break-all">Pedido: {{ paymentRequest.order_id }}</p>
                <p class="text-sm break-all">Negocio: {{ paymentRequest.business_id }}</p>
                <p class="text-sm text-slate-500">Vigencia: {{ new Date(paymentRequest.expires_at).toLocaleString('es-MX') }}</p>
                <ul v-if="paymentRequest.items.length" class="border-t pt-3 space-y-2 text-sm">
                    <li v-for="(item, index) in paymentRequest.items" :key="index" class="break-all">Producto {{ item.product_id }} · Cantidad {{ item.quantity }}</li>
                </ul>
                <p class="text-slate-600">Si autorizas, este importe se descontará de tu wallet. Revisa el negocio y el importe antes de continuar.</p>
                <p v-if="paymentRequest.status === 'PAID'" class="font-semibold text-green-800">Esta compra ya está pagada.</p>
                <Link v-if="paymentRequest.status === 'PAID'" :href="route('financial.receipts.show', { source: 'purchase', operationId: paymentRequest.payment_intent_id })" class="inline-block text-blue-800 underline">Ver comprobante</Link>
                <p v-else-if="paymentRequest.status !== 'PENDING'" class="font-semibold">Esta solicitud está {{ paymentRequest.status === 'EXPIRED' ? 'vencida' : 'cancelada' }}. No se puede pagar.</p>
                <template v-else>
                    <p v-if="!passwordRecent" class="rounded-lg bg-amber-50 p-3">Para autorizar, primero <Link :href="route('password.confirm')" class="font-semibold text-blue-800 underline">confirma tu contraseña</Link>. Después volverás a este detalle.</p>
                    <form @submit.prevent="pay" class="space-y-4">
                        <label class="flex items-start gap-3"><input v-model="form.consent" type="checkbox" class="mt-1 rounded" /><span>Autorizo pagar {{ money(paymentRequest.amount_cents) }} a {{ paymentRequest.business_name }} con mi wallet.</span></label>
                        <p v-for="(error, key) in form.errors" :key="key" class="text-red-700" role="alert">{{ error }}</p>
                        <button type="submit" :disabled="!form.consent || !passwordRecent || form.processing || cancellation.processing" class="rounded-lg bg-[#00338D] text-white px-5 py-3 font-semibold disabled:opacity-50">{{ form.processing ? 'Procesando…' : 'Confirmar y pagar' }}</button>
                    </form>
                    <button type="button" @click="cancel" :disabled="form.processing || cancellation.processing" class="rounded-lg border px-5 py-3 disabled:opacity-50">Cancelar solicitud</button>
                    <p v-for="(error, key) in cancellation.errors" :key="key" class="text-red-700" role="alert">{{ error }}</p>
                </template>
            </section>
        </main>
    </AuthenticatedLayout>
</template>
