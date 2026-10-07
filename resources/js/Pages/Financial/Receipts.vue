<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link } from '@inertiajs/vue3';

defineProps({
    wallet: {
        type: Object,
        default: null,
    },
    receipts: {
        type: Array,
        default: () => [],
    },
});

const formatMoney = (cents, currency = 'MXN') =>
    new Intl.NumberFormat('es-MX', {
        style: 'currency',
        currency: currency || 'MXN',
    }).format(cents / 100);

const formatDate = (date) => {
    if (!date) return '';

    return new Intl.DateTimeFormat('es-MX', {
        dateStyle: 'medium',
        timeStyle: 'short',
    }).format(new Date(date));
};
</script>

<template>
    <Head title="Comprobantes financieros" />

    <AuthenticatedLayout>
        <template #header>
            <div>
                <p class="text-xs font-bold uppercase tracking-[0.22em] text-[#0284C7]">
                    Módulo 2.9
                </p>
                <h2 class="mt-1 text-2xl font-bold tracking-tight text-[#00338D]">
                    Tickets y comprobantes
                </h2>
            </div>
        </template>

        <div class="min-h-[calc(100vh-9rem)] bg-[#F5F8FC] px-4 py-8 sm:px-6 lg:px-8">
            <div class="mx-auto max-w-5xl">
                <div class="mb-5">
                    <Link
                        :href="route('financial.dashboard')"
                        class="text-sm font-bold text-[#00338D]"
                    >
                        ← Volver a finanzas
                    </Link>
                </div>

                <section class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
                    <div>
                        <p class="text-xs font-bold uppercase tracking-[0.18em] text-[#0284C7]">
                            Evidencia de operaciones
                        </p>
                        <h1 class="mt-2 text-2xl font-bold text-[#00338D]">
                            Mis comprobantes
                        </h1>
                        <p class="mt-2 text-sm leading-6 text-slate-500">
                            Consulta los movimientos registrados en tu ledger y abre su comprobante.
                        </p>
                    </div>

                    <div v-if="wallet && receipts.length" class="mt-6 divide-y divide-slate-100">
                        <div
                            v-for="receipt in receipts"
                            :key="receipt.transaction_id + '-' + receipt.movement_type"
                            class="flex flex-col gap-3 py-4 sm:flex-row sm:items-center sm:justify-between"
                        >
                            <div>
                                <p class="font-bold text-slate-800">
                                    {{ receipt.movement_type }}
                                </p>
                                <p class="mt-1 text-sm text-slate-500">
                                    {{ formatDate(receipt.created_at) }}
                                </p>
                                <p v-if="receipt.reference_type" class="mt-1 text-xs text-slate-400">
                                    {{ receipt.reference_type }}
                                    <span v-if="receipt.reference_id"> · {{ receipt.reference_id }}</span>
                                </p>
                            </div>

                            <div class="sm:text-right">
                                <p
                                    class="text-lg font-bold"
                                    :class="receipt.amount_cents >= 0 ? 'text-emerald-600' : 'text-red-600'"
                                >
                                    {{ receipt.amount_cents >= 0 ? '+' : '' }}{{
                                        formatMoney(receipt.amount_cents, receipt.currency)
                                    }}
                                </p>

                                <Link
                                    :href="route('financial.receipts.show', receipt.transaction_id)"
                                    class="mt-2 inline-block text-sm font-bold text-[#0284C7]"
                                >
                                    Ver comprobante
                                </Link>
                            </div>
                        </div>
                    </div>

                    <div
                        v-else
                        class="mt-6 rounded-xl border border-dashed border-slate-200 p-6 text-center"
                    >
                        <p class="text-sm text-slate-500">
                            No hay comprobantes disponibles.
                        </p>
                    </div>
                </section>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
