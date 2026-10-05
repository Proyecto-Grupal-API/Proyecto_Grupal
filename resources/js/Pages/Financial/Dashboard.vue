<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link } from '@inertiajs/vue3';

defineProps({
    wallet: {
        type: Object,
        default: null,
    },

    history: {
        type: Array,
        default: () => [],
    },
});

const formatMoney = (cents, currency = 'MXN') => {
    return new Intl.NumberFormat('es-MX', {
        style: 'currency',
        currency,
    }).format(cents / 100);
};
const formatDate = (date) => {
    if (!date) {
        return '';
    }

    return new Intl.DateTimeFormat('es-MX', {
        dateStyle: 'medium',
        timeStyle: 'short',
    }).format(new Date(date));
};
</script>

<template>
    <Head title="Finanzas" />

    <AuthenticatedLayout>
        <template #header>
            <div>
                <p
                    class="text-xs font-bold uppercase tracking-[0.22em] text-[#0284C7]"
                >
                    Módulo 2
                </p>

                <h2
                    class="mt-1 text-2xl font-bold tracking-tight text-[#00338D]"
                >
                    Finanzas
                </h2>
            </div>
        </template>

        <div
            class="min-h-[calc(100vh-9rem)] bg-[#F5F8FC] px-4 py-8 sm:px-6 lg:px-8"
        >
            <div class="mx-auto max-w-7xl">
                <section
                    class="rounded-2xl bg-[#00338D] p-7 text-white shadow-xl shadow-[#00338D]/10 sm:p-10"
                >
                    <p class="text-sm text-blue-100">
                        Campus Digital
                    </p>

                    <h1 class="mt-2 text-3xl font-bold sm:text-4xl">
                        Mi wallet
                    </h1>

                    <p class="mt-4 max-w-2xl leading-7 text-blue-100">
                        Consulta tu saldo y administra tus operaciones
                        financieras dentro del campus.
                    </p>
                </section>

                <div class="mt-4 flex justify-end">
                    <Link
                        :href="route('financial.adjustments')"
                        class="rounded-xl bg-white px-4 py-2 text-sm font-bold text-[#00338D] shadow-sm ring-1 ring-slate-200"
                    >
                        Retenciones y devoluciones
                    </Link>
                </div>

                <div v-if="wallet" class="mt-6 grid gap-5 md:grid-cols-2">
                    <section
                        class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm"
                    >
                        <p
                            class="text-xs font-bold uppercase tracking-[0.18em] text-[#0284C7]"
                        >
                            Saldo disponible
                        </p>

                        <p
                            class="mt-3 text-4xl font-bold tracking-tight text-[#00338D]"
                        >
                            {{
                                formatMoney(
                                    wallet.available_balance_cents,
                                    wallet.currency
                                )
                            }}
                        </p>

                        <p class="mt-2 text-sm text-slate-500">
                            Disponible para operaciones autorizadas.
                        </p>
                    </section>

                    <section
    class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm md:col-span-2"
>
    <div
        class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between"
    >
        <div>
            <p
                class="text-xs font-bold uppercase tracking-[0.18em] text-[#0284C7]"
            >
                Actividad
            </p>

            <h2 class="mt-1 text-xl font-bold text-[#00338D]">
                Movimientos recientes
            </h2>
        </div>

        <p class="text-sm text-slate-500">
            {{ history.length }} movimiento(s)
        </p>
    </div>

    <div v-if="history.length" class="mt-5 divide-y divide-slate-100">
        <div
            v-for="entry in history"
            :key="entry.id"
            class="flex flex-col gap-3 py-4 sm:flex-row sm:items-center sm:justify-between"
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
                    :class="
                        entry.amount_cents >= 0
                            ? 'text-[#10B981]'
                            : 'text-red-600'
                    "
                >
                    {{
                        entry.amount_cents >= 0
                            ? '+'
                            : ''
                    }}{{
                        formatMoney(
                            entry.amount_cents,
                            wallet.currency
                        )
                    }}
                </p>

                <p class="mt-1 text-sm text-slate-500">
                    Saldo:
                    {{
                        formatMoney(
                            entry.available_balance_after_cents,
                            wallet.currency
                        )
                    }}
                </p>
            </div>
        </div>
    </div>

    <div
        v-else
        class="mt-5 rounded-xl border border-dashed border-slate-200 p-6 text-center"
    >
        <p class="text-sm text-slate-500">
            Aún no hay movimientos registrados en esta wallet.
        </p>
    </div>
</section>

                    <section
                        class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm"
                    >
                        <p
                            class="text-xs font-bold uppercase tracking-[0.18em] text-[#10B981]"
                        >
                            Saldo retenido
                        </p>

                        <p
                            class="mt-3 text-4xl font-bold tracking-tight text-[#00338D]"
                        >
                            {{
                                formatMoney(
                                    wallet.held_balance_cents,
                                    wallet.currency
                                )
                            }}
                        </p>

                        <p class="mt-2 text-sm text-slate-500">
                            Fondos temporalmente no disponibles.
                        </p>
                    </section>

                    <section
                        class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm md:col-span-2"
                    >
                        <div
                            class="grid gap-5 sm:grid-cols-2 lg:grid-cols-3"
                        >
                            <div>
                                <p class="text-sm text-slate-500">
                                    Estado
                                </p>

                                <p class="mt-1 font-bold text-[#00338D]">
                                    {{ wallet.status }}
                                </p>
                            </div>

                            <div>
                                <p class="text-sm text-slate-500">
                                    Moneda
                                </p>

                                <p class="mt-1 font-bold text-[#00338D]">
                                    {{ wallet.currency }}
                                </p>
                            </div>

                            <div>
                                <p class="text-sm text-slate-500">
                                    Tipo
                                </p>

                                <p class="mt-1 font-bold text-[#00338D]">
                                    {{ wallet.type }}
                                </p>
                            </div>
                        </div>
                    </section>
                </div>

                <section
                    v-else
                    class="mt-6 rounded-2xl border border-slate-200 bg-white p-8 text-center shadow-sm"
                >
                    <p
                        class="text-xs font-bold uppercase tracking-[0.18em] text-[#0284C7]"
                    >
                        Wallet
                    </p>

                    <h2 class="mt-3 text-xl font-bold text-[#00338D]">
                        Aún no tienes una wallet disponible
                    </h2>

                    <p class="mx-auto mt-2 max-w-xl text-sm leading-6 text-slate-500">
                        No encontramos una wallet financiera asociada con tu
                        cuenta.
                    </p>
                </section>
            </div>
        </div>
    </AuthenticatedLayout>
</template>