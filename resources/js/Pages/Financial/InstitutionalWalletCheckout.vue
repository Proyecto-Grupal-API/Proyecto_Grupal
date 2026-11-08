<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { computed } from 'vue';

const props = defineProps({ paymentRequest: Object, reviewToken: String, passwordRecent: Boolean, eventCheckout: Boolean, recipientName: { type: String, default: 'Servicios al Estudiante' } });
const form = useForm({ consent: false, review_token: props.reviewToken });
const amount = computed(() => {
    const cents = BigInt(props.paymentRequest.amount_cents);
    return '$' + new Intl.NumberFormat('es-MX').format(cents / 100n) + '.' + String(cents % 100n).padStart(2, '0') + ' MXN';
});
const pay = () => {
    form.review_token = props.reviewToken;
    form.post(props.paymentRequest.confirmation_path + '/confirmar');
};
</script>

<template>
    <Head :title="eventCheckout ? 'Pagar reserva de evento' : 'Pagar multa de biblioteca'" />
    <AuthenticatedLayout>
        <template #header>
            <h2 class="text-2xl font-bold text-[#00338D]">{{ eventCheckout ? 'Reserva de evento' : 'Multa de biblioteca' }}</h2>
        </template>
        <main class="mx-auto max-w-xl p-6">
            <section class="space-y-4 rounded-xl border bg-white p-6">
                <Link v-if="eventCheckout" :href="route('modulo6.boletos')" class="text-blue-800 underline">Volver a mis boletos</Link>
                <h3 class="text-xl font-semibold">{{ recipientName }}</h3>
                <p class="text-sm text-slate-600">Referencia {{ paymentRequest.source_reference }}</p>
                <p class="text-3xl font-bold text-[#00338D]">{{ amount }}</p>
                <template v-if="paymentRequest.status === 'CONFIRMED'">
                    <p class="font-semibold text-green-800">El pago está confirmado.</p>
                    <Link :href="route('financial.receipts.show', { source: 'transaction', operationId: paymentRequest.payment_id })" class="text-blue-800 underline">Ver comprobante</Link>
                </template>
                <p v-else-if="paymentRequest.status === 'CANCELLED'">La solicitud fue cancelada.</p>
                <p v-else-if="paymentRequest.status === 'EXPIRED'">La reserva venció.</p>
                <template v-else>
                    <p v-if="!passwordRecent" class="rounded-lg bg-amber-50 p-3">
                        <Link :href="route('password.confirm')" class="font-semibold text-blue-800 underline">Confirma tu contraseña</Link>
                        para autorizar el pago. Después volverás a este detalle.
                    </p>
                    <form class="space-y-4" @submit.prevent="pay">
                        <label class="flex items-start gap-3">
                            <input v-model="form.consent" type="checkbox" class="mt-1 rounded" />
                            <span>Autorizo pagar {{ amount }} a {{ recipientName }} con el saldo de mi wallet.</span>
                        </label>
                        <p v-for="(error, key) in form.errors" :key="key" class="text-red-700" role="alert">{{ error }}</p>
                        <button type="submit" :disabled="!form.consent || !passwordRecent || form.processing" class="rounded-lg bg-[#00338D] px-5 py-3 font-semibold text-white disabled:opacity-50">
                            {{ form.processing ? 'Procesando…' : 'Confirmar y pagar' }}
                        </button>
                    </form>
                </template>
            </section>
        </main>
    </AuthenticatedLayout>
</template>
