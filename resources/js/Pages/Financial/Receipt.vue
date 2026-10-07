<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link } from '@inertiajs/vue3';

defineProps({
    receipt: {
        type: Object,
        required: true,
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

const printReceipt = () => window.print();
</script>

<template>
    <Head title="Comprobante financiero" />

    <AuthenticatedLayout>
        <template #header>
            <div>
                <p class="text-xs font-bold uppercase tracking-[0.22em] text-[#0284C7]">
                    Módulo 2.9
                </p>
                <h2 class="mt-1 text-2xl font-bold tracking-tight text-[#00338D]">
                    Ticket / comprobante
                </h2>
            </div>
        </template>

        <div class="min-h-[calc(100vh-9rem)] bg-[#F5F8FC] px-4 py-8 sm:px-6 lg:px-8">
            <div class="mx-auto max-w-4xl">
                <div class="mb-5 flex flex-wrap items-center justify-between gap-3 print:hidden">
                    <Link
                        :href="route('financial.receipts.index')"
                        class="text-sm font-bold text-[#00338D]"
                    >
                        ← Volver a comprobantes
                    </Link>

                    <button
                        type="button"
                        class="rounded-xl bg-[#00338D] px-5 py-2.5 text-sm font-bold text-white"
                        @click="printReceipt"
                    >
                        Imprimir / guardar PDF
                    </button>
                </div>

                <section class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm sm:p-8">
                    <div class="border-b border-slate-200 pb-5">
                        <p class="text-xs font-bold uppercase tracking-[0.18em] text-[#0284C7]">
                            CAMPUS DIGITAL
                        </p>
                        <h1 class="mt-2 text-2xl font-bold text-[#00338D]">
                            Comprobante de operación financiera
                        </h1>
                    </div>

                    <div class="mt-6 grid gap-4 sm:grid-cols-2">
                        <div>
                            <p class="text-xs uppercase text-slate-500">Transacción</p>
                            <p class="mt-1 break-all font-mono text-sm text-slate-800">
                                {{ receipt.transaction_id }}
                            </p>
                        </div>

                        <div>
                            <p class="text-xs uppercase text-slate-500">Estado</p>
                            <p class="mt-1 font-bold text-slate-800">
                                {{ receipt.status }}
                            </p>
                        </div>

                        <div>
                            <p class="text-xs uppercase text-slate-500">Fecha</p>
                            <p class="mt-1 text-slate-800">
                                {{ formatDate(receipt.issued_at) }}
                            </p>
                        </div>

                        <div>
                            <p class="text-xs uppercase text-slate-500">Referencia</p>
                            <p class="mt-1 text-slate-800">
                                {{ receipt.reference_type || 'Sin referencia' }}
                                <span v-if="receipt.reference_id">
                                    · {{ receipt.reference_id }}
                                </span>
                            </p>
                        </div>
                    </div>

                    <div class="mt-8">
                        <h2 class="text-lg font-bold text-[#00338D]">
                            Movimientos incluidos
                        </h2>

                        <div class="mt-4 divide-y divide-slate-100 rounded-xl border border-slate-200">
                            <div
                                v-for="entry in receipt.entries"
                                :key="entry.id"
                                class="grid gap-3 p-4 sm:grid-cols-[1fr_auto]"
                            >
                                <div>
                                    <p class="font-bold text-slate-800">
                                        {{ entry.movement_type }}
                                    </p>
                                    <p class="mt-1 text-sm text-slate-500">
                                        {{ formatDate(entry.created_at) }}
                                    </p>
                                </div>

                                <div class="sm:text-right">
                                    <p
                                        class="text-lg font-bold"
                                        :class="entry.amount_cents >= 0 ? 'text-emerald-600' : 'text-red-600'"
                                    >
                                        {{ entry.amount_cents >= 0 ? '+' : '' }}{{
                                            formatMoney(entry.amount_cents, entry.currency)
                                        }}
                                    </p>
                                    <p class="mt-1 text-sm text-slate-500">
                                        Saldo disponible:
                                        {{
                                            formatMoney(
                                                entry.available_balance_after_cents,
                                                entry.currency
                                            )
                                        }}
                                    </p>
                                </div>
                            </div>
                        </div>
                    </div>

                    <p class="mt-6 text-xs leading-5 text-slate-500">
                        Este comprobante se genera a partir de la transacción y de los
                        movimientos registrados en el ledger financiero.
                    </p>
                </section>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
