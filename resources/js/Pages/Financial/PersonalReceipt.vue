<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link } from '@inertiajs/vue3';
defineProps({ receipt: Object });
const money = (n, currency) => n === null || n === undefined ? 'Sin registro histórico' : new Intl.NumberFormat('es-MX', { style: 'currency', currency }).format(n / 100);
const date = value => value ? new Date(value).toLocaleString('es-MX') : '';
const print = () => window.print();
</script>
<template><Head title="Comprobante" /><AuthenticatedLayout>
<template #header><h2 class="text-2xl font-bold text-[#00338D]">Comprobante · 2.9</h2></template>
<div class="receipt-area bg-[#F5F8FC] p-6 min-h-screen"><div class="max-w-4xl mx-auto">
<div class="receipt-actions flex justify-between mb-5"><Link :href="route('financial.receipts.index')">Volver al historial</Link><button @click="print" class="bg-blue-800 text-white rounded-lg px-4 py-2">Imprimir / guardar PDF</button></div>
<article class="receipt-paper bg-white border rounded-xl p-7">
<h1 class="text-2xl font-bold text-[#00338D]">Campus Digital · Comprobante de operación</h1>
<p class="font-mono text-sm break-all mt-4">{{ receipt.id }}</p><p>{{ date(receipt.created_at) }} · {{ receipt.status }} · {{ receipt.currency }}</p>
<p class="mt-2 text-sm">Este documento muestra tu participación en la operación; los saldos de otras wallets no se incluyen.</p>
<div v-if="receipt.purchase" class="mt-6 border-t pt-4"><h2 class="font-bold">Desglose del pago</h2>
<p>Total: {{ money(receipt.purchase.total_amount_cents, receipt.currency) }}</p>
<p>Wallet: {{ money(receipt.purchase.wallet_amount_cents, receipt.currency) }}</p>
<p>Bonos: {{ money(receipt.purchase.bonus_amount_cents, receipt.currency) }}</p>
<p v-for="b in receipt.purchase.bonuses" :key="b.bonus_id" class="text-sm break-all">Bono {{ b.bonus_id }} · {{ money(b.amount_cents, receipt.currency) }}</p>
</div>
<div v-for="entry in receipt.entries" :key="entry.id" class="border-t mt-5 pt-4">
<p class="font-bold">{{ entry.movement_type }} · {{ money(entry.amount_cents, receipt.currency) }}</p>
<p>Saldo disponible después del movimiento: {{ money(entry.available_balance_after_cents, receipt.currency) }}</p>
<p v-if="entry.held_balance_after_cents !== null">Saldo retenido: {{ money(entry.held_balance_after_cents, receipt.currency) }}</p>
</div>
<div v-for="cash in receipt.cash_receipts" :key="cash.id" class="border-t mt-5 pt-4"><h2 class="font-bold">Comprobante de caja vinculado</h2><p class="break-all">{{ cash.folio }}</p><p>{{ date(cash.issued_at) }}</p></div>
<p v-if="receipt.original_transaction_id" class="mt-5 text-sm break-all">Operación original: {{ receipt.original_transaction_id }}</p>
<p class="mt-6 text-xs text-slate-500">Consulta de registros financieros existentes. No constituye una factura fiscal ni genera un nuevo movimiento. La fecha de impresión no sustituye la fecha de la operación.</p>
</article></div></div></AuthenticatedLayout></template>
<style>
@media print {
 body { background: white !important; }
 nav, header, .receipt-actions { display: none !important; }
 .receipt-area { min-height: auto !important; padding: 0 !important; background: white !important; }
 .receipt-paper { border: 0 !important; padding: 0 !important; box-shadow: none !important; }
 @page { margin: 15mm; }
}
</style>
