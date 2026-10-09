<script setup>
import { Head, Link } from '@inertiajs/vue3';
const props = defineProps({ receipt: { type: Object, required: true } });
const labels = { CASH_IN: 'Entrada de efectivo', CASH_OUT: 'Salida de efectivo', ADJUSTMENT: 'Ajuste de efectivo', TOPUP: 'Recarga en efectivo', WITHDRAWAL: 'Retiro en efectivo' };
const formatMoney = () => {
    try { return new Intl.NumberFormat('es-MX', { style: 'currency', currency: props.receipt.currency }).format(props.receipt.amount_cents / 100); }
    catch { return `${(props.receipt.amount_cents / 100).toFixed(2)} ${props.receipt.currency}`; }
};
const issued = () => new Intl.DateTimeFormat('es-MX', { dateStyle: 'long', timeStyle: 'short', timeZone: 'America/Mexico_City' }).format(new Date(props.receipt.issued_at));
const print = () => window.print();
</script>

<template>
    <Head :title="`Comprobante ${receipt.folio}`" />
    <main class="min-h-screen bg-[#F5F8FC] px-4 py-8 text-slate-800">
        <div class="mx-auto max-w-3xl">
            <nav class="screen-only mb-5 flex flex-wrap items-center justify-between gap-3">
                <Link :href="route('financial.cash.index')" class="font-semibold text-[#00338D]">Volver a Caja y turnos</Link>
                <button type="button" class="rounded-xl bg-[#00338D] px-5 py-3 font-semibold text-white" @click="print">Imprimir o guardar PDF</button>
            </nav>
            <article class="receipt rounded-2xl border border-slate-200 bg-white p-6 shadow-sm sm:p-10">
                <header class="border-b border-slate-200 pb-6"><p class="text-xs font-bold uppercase tracking-widest text-sky-600">Campus Digital · Finanzas</p><h1 class="mt-2 text-3xl font-bold text-[#00338D]">Comprobante de caja</h1><p class="mt-4 break-all font-semibold">{{ receipt.folio }}</p><p class="mt-2 text-sm text-slate-500">{{ issued() }} · Hora de Ciudad de México</p></header>
                <section class="border-b border-slate-200 py-6"><h2 class="text-lg font-semibold">{{ labels[receipt.movement_type] || receipt.movement_type }}</h2><p class="mt-3 text-4xl font-bold text-[#00338D]">{{ formatMoney() }}</p><p class="mt-2 text-sm text-slate-500">Moneda: {{ receipt.currency }}</p></section>
                <dl class="grid gap-5 py-6 sm:grid-cols-2">
                    <div><dt>Asociación</dt><dd>{{ receipt.association_id }}</dd></div><div><dt>Caja</dt><dd>{{ receipt.cash_register_name }}</dd></div>
                    <div><dt>Responsable</dt><dd>{{ receipt.actor_id }}</dd></div><div><dt>Turno</dt><dd>{{ receipt.cash_shift_id }}</dd></div>
                    <div v-if="receipt.wallet_id"><dt>Wallet</dt><dd>{{ receipt.wallet_id }}</dd></div><div><dt>Movimiento de caja</dt><dd>{{ receipt.movement_id }}</dd></div>
                    <div v-if="receipt.reference_id"><dt>Operación relacionada</dt><dd>{{ receipt.reference_type }} · {{ receipt.reference_id }}</dd></div>
                    <div v-if="receipt.financial_transaction_id"><dt>Transacción financiera</dt><dd>{{ receipt.financial_transaction_id }}</dd></div>
                    <div class="sm:col-span-2"><dt>Motivo</dt><dd class="whitespace-pre-wrap">{{ receipt.reason }}</dd></div>
                </dl>
                <footer class="border-t border-slate-200 pt-4 text-xs text-slate-500">Este comprobante conserva los datos registrados al confirmar el movimiento. Su consulta e impresión no ejecutan otra operación.</footer>
            </article>
        </div>
    </main>
</template>

<style scoped>
dt { color: #64748b; font-size: .8rem; }dd { margin-top: .4rem; overflow-wrap: anywhere; font-size: .875rem; }
@media print {
    .screen-only { display: none !important; }
    main { padding: 0; background: white; min-height: auto; }
    .receipt { border: none; border-radius: 0; box-shadow: none; padding: 0; }
    article { break-inside: avoid; }
}
</style>
