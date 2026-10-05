<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { reactive, ref } from 'vue';

const props = defineProps({
    wallet: {
        type: Object,
        default: null,
    },
    history: {
        type: Array,
        default: () => [],
    },
});

const amounts = reactive({});
const reasons = reactive({});
const submitting = ref(null);
const errors = ref({});

const formatMoney = (cents, currency = 'MXN') =>
    new Intl.NumberFormat('es-MX', {
        style: 'currency',
        currency,
    }).format(cents / 100);

const formatDate = (date) => {
    if (!date) return '';

    return new Intl.DateTimeFormat('es-MX', {
        dateStyle: 'medium',
        timeStyle: 'short',
    }).format(new Date(date));
};

const refundable = (entry) =>
    entry.amount_cents < 0 &&
    ['PAGO', 'RETIRO', 'TRANSFERENCIA_SALIDA'].includes(entry.movement_type);

const defaultAmount = (entry) => Math.abs(entry.amount_cents);

const submitRefund = (entry) => {
    const amount = Number(amounts[entry.transaction_id] ?? defaultAmount(entry));
    if (!Number.isInteger(amount) || amount <= 0) {
        errors.value = {
            amount_cents: 'El monto debe ser un entero mayor que cero.',
        };
        return;
    }

    submitting.value = entry.transaction_id;
    errors.value = {};

    router.post(
        route('financial.refunds.request', entry.transaction_id),
        {
            amount_cents: amount,
            reason: reasons[entry.transaction_id] ?? '',
            idempotency_key: crypto.randomUUID(),
        },
        {
            preserveScroll: true,
            onError: (serverErrors) => {
                errors.value = serverErrors;
            },
            onFinish: () => {
                submitting.value = null;
            },
        }
    );
};
</script>

<template>
    <Head title="Retenciones y devoluciones" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                <div>
                    <p class="text-xs font-bold uppercase tracking-[0.22em] text-[#0284C7]">
                        Módulo 2 · 2.7
                    </p>
                    <h2 class="mt-1 text-2xl font-bold tracking-tight text-[#00338D]">
                        Retenciones, devoluciones y reversos
                    </h2>
                </div>
                <Link
                    :href="route('financial.dashboard')"
                    class="rounded-xl border border-slate-200 bg-white px-4 py-2 text-sm font-bold text-[#00338D] shadow-sm"
                >
                    Volver a finanzas
                </Link>
            </div>
        </template>

        <div class="min-h-[calc(100vh-9rem)] bg-[#F5F8FC] px-4 py-8 sm:px-6 lg:px-8">
            <div class="mx-auto max-w-6xl">
                <section class="rounded-2xl bg-[#00338D] p-7 text-white shadow-xl sm:p-10">
                    <p class="text-sm text-blue-100">Submódulo 2.7</p>
                    <h1 class="mt-2 text-3xl font-bold">Correcciones financieras</h1>
                    <p class="mt-4 max-w-3xl leading-7 text-blue-100">
                        Las devoluciones se registran como solicitudes pendientes. Las retenciones y los reversos
                        requieren autorización de los procesos financieros correspondientes.
                    </p>
                </section>

                <div v-if="wallet" class="mt-6 grid gap-5 md:grid-cols-2">
                    <section class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
                        <p class="text-xs font-bold uppercase tracking-[0.18em] text-[#0284C7]">
                            Disponible
                        </p>
                        <p class="mt-3 text-3xl font-bold text-[#00338D]">
                            {{ formatMoney(wallet.available_balance_cents, wallet.currency) }}
                        </p>
                    </section>
                    <section class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
                        <p class="text-xs font-bold uppercase tracking-[0.18em] text-[#0284C7]">
                            Retenido
                        </p>
                        <p class="mt-3 text-3xl font-bold text-[#00338D]">
                            {{ formatMoney(wallet.held_balance_cents, wallet.currency) }}
                        </p>
                    </section>
                </div>

                <section v-if="wallet" class="mt-6 rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
                    <div>
                        <p class="text-xs font-bold uppercase tracking-[0.18em] text-[#0284C7]">
                            Devoluciones
                        </p>
                        <h3 class="mt-1 text-xl font-bold text-[#00338D]">
                            Solicita una devolución de una operación elegible
                        </h3>
                        <p class="mt-2 text-sm text-slate-500">
                            El monto puede ser parcial cuando el servicio financiero lo permita. La solicitud no mueve saldo por sí sola.
                        </p>
                    </div>

                    <p v-if="errors.amount_cents" class="mt-4 rounded-xl bg-red-50 p-3 text-sm text-red-700">
                        {{ errors.amount_cents }}
                    </p>

                    <div class="mt-6 divide-y divide-slate-100">
                        <div
                            v-for="entry in history.filter(refundable)"
                            :key="entry.id"
                            class="py-5 first:pt-0 last:pb-0"
                        >
                            <div class="grid gap-4 lg:grid-cols-[1fr_180px] lg:items-end">
                                <div>
                                    <p class="font-bold text-slate-800">
                                        {{ entry.movement_type }} · {{ formatMoney(Math.abs(entry.amount_cents), wallet.currency) }}
                                    </p>
                                    <p class="mt-1 text-sm text-slate-500">
                                        {{ formatDate(entry.created_at) }} · {{ entry.transaction_status ?? 'COMPLETADA' }}
                                    </p>
                                </div>

                                <div class="rounded-xl bg-slate-50 p-3 text-sm text-slate-600">
                                    <span class="font-semibold">Movimiento:</span>
                                    {{ entry.transaction_id }}
                                </div>
                            </div>

                            <div class="mt-4 grid gap-3 md:grid-cols-[180px_1fr_auto]">
                                <label class="text-sm text-slate-600">
                                    Monto (centavos)
                                    <input
                                        v-model.number="amounts[entry.transaction_id]"
                                        :placeholder="defaultAmount(entry)"
                                        min="1"
                                        step="1"
                                        type="number"
                                        class="mt-1 w-full rounded-xl border-slate-300"
                                    />
                                </label>

                                <label class="text-sm text-slate-600">
                                    Motivo
                                    <input
                                        v-model="reasons[entry.transaction_id]"
                                        type="text"
                                        maxlength="500"
                                        placeholder="Ej. Compra cancelada"
                                        class="mt-1 w-full rounded-xl border-slate-300"
                                    />
                                </label>

                                <button
                                    type="button"
                                    class="rounded-xl bg-[#00338D] px-5 py-3 text-sm font-bold text-white disabled:cursor-not-allowed disabled:opacity-50"
                                    :disabled="submitting === entry.transaction_id"
                                    @click="submitRefund(entry)"
                                >
                                    {{ submitting === entry.transaction_id ? 'Enviando…' : 'Solicitar devolución' }}
                                </button>
                            </div>
                        </div>
                    </div>

                    <div v-if="!history.filter(refundable).length" class="mt-5 rounded-xl border border-dashed border-slate-200 p-6 text-center">
                        <p class="text-sm text-slate-500">
                            No hay operaciones recientes que puedan solicitarse para devolución.
                        </p>
                    </div>
                </section>

                <section v-else class="mt-6 rounded-2xl border border-slate-200 bg-white p-8 text-center shadow-sm">
                    <p class="text-sm text-slate-500">No encontramos una wallet financiera asociada con tu cuenta.</p>
                </section>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
