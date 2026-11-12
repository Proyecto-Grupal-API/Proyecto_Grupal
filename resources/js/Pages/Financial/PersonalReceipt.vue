<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link } from '@inertiajs/vue3';
import { formatInstitutionalMoney } from '@/Support/institutionalMoney.js';
defineProps({ receipt: Object });
const money = (n, currency) => n === null || n === undefined ? 'Sin registro histórico'
    : formatInstitutionalMoney(typeof n === 'number' && Number.isSafeInteger(n) ? String(n) : n, currency);
const date = value => value ? new Date(value).toLocaleString('es-MX') : '';
const print = () => window.print();
</script>
<template><Head title="Comprobante" /><AuthenticatedLayout>
<template #header><h2 class="text-2xl font-bold text-[#00338D]">Comprobante · 2.9</h2></template>
<div class="receipt-area bg-[#F5F8FC] p-6 min-h-screen"><div class="max-w-4xl mx-auto">
<div class="receipt-actions flex justify-between mb-5"><Link v-if="receipt.reference_type === 'LIBRARY_FINE'" :href="route('module5.library.fines.index')">Volver a mis multas</Link><Link v-else-if="receipt.reference_type === 'LOCKER_RENTAL'" :href="route('module5.lockers.requests.index')">Volver a mis lockers</Link><Link v-else-if="receipt.reference_type === 'PRINT_ORDER'" :href="route('module5.services.index')">Volver a mis solicitudes</Link><Link v-else-if="receipt.reference_type === 'COMMUNITY_EVENT'" :href="route('modulo6.boletos')">Volver a mis boletos</Link><Link v-else :href="route('financial.receipts.index')">Volver al historial</Link><Link v-if="receipt.source === 'PURCHASE'" :href="route('marketplace.orders')">Ir a mis pedidos</Link><a :href="route('financial.receipts.pdf', {source: receipt.source.toLowerCase(), operationId: receipt.id})" class="bg-blue-800 text-white rounded-lg px-4 py-2">Descargar PDF</a><button @click="print" class="bg-blue-800 text-white rounded-lg px-4 py-2">Imprimir / guardar PDF</button></div>
<article class="receipt-paper bg-white border rounded-xl p-7">
<h1 class="text-2xl font-bold text-[#00338D]">Campus Digital · Comprobante de operación</h1>
<p class="font-mono text-sm break-all mt-4">{{ receipt.id }}</p><p>{{ date(receipt.created_at) }} · {{ receipt.status }} · {{ receipt.currency }}</p>
<p v-if="receipt.historical_notice" class="bg-amber-50 p-3 mt-3">{{receipt.historical_notice.message}}</p>
<p class="mt-2 text-sm">Este documento muestra tu participación en la operación; los saldos de otras wallets no se incluyen.</p>
<div v-if="receipt.purchase" class="mt-6 border-t pt-4"><h2 class="font-bold">Desglose del pago</h2>
<p>Total: {{ money(receipt.purchase.total_amount_cents, receipt.currency) }}</p>
<p>Wallet: {{ money(receipt.purchase.wallet_amount_cents, receipt.currency) }}</p>
<p>Bonos: {{ money(receipt.purchase.bonus_amount_cents, receipt.currency) }}</p>
<p v-for="b in receipt.purchase.bonuses" :key="b.bonus_id" class="text-sm break-all">Bono {{ b.bonus_id }} · {{ money(b.amount_cents, receipt.currency) }}</p>
<section v-if="receipt.purchase.institutional" class="mt-4 rounded-lg border border-blue-200 bg-blue-50 p-4">
<h3 class="font-bold text-[#00338D]">Compra cubierta con BECA institucional</h3>
<p class="text-sm mt-1">Esta compra se cubrió con tu BECA. No se descontó dinero de tu wallet.</p>
<p class="mt-3">Devoluciones completadas: {{ money(receipt.purchase.institutional.refunded_amount_cents, receipt.currency) }}</p>
<p>Importe neto de la compra: {{ money(receipt.purchase.institutional.net_amount_cents, receipt.currency) }}</p>
<p v-if="receipt.purchase.institutional.restored_bonus_amount_cents > 0" class="text-sm mt-2">Reintegrado al bono: {{ money(receipt.purchase.institutional.restored_bonus_amount_cents, receipt.currency) }}.</p>
<p v-if="receipt.purchase.institutional.released_budget_amount_cents > 0" class="text-sm mt-2">Devuelto al presupuesto institucional: {{ money(receipt.purchase.institutional.released_budget_amount_cents, receipt.currency) }}. Ese importe no se agregó a tu wallet ni reactivó la BECA cerrada.</p>
<p class="text-xs text-slate-600 mt-3">Devoluciones registradas al {{ date(receipt.purchase.institutional.observed_at) }}. El total original del comprobante se conserva.</p>
</section>
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
