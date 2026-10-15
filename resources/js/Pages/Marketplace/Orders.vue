<script setup>
import CampusLayout from '../../Layouts/MarketplaceLayout.vue';
import { Link, useForm } from '@inertiajs/vue3';
defineProps({ orders: Object, isAdmin:Boolean });
const cancelForm=useForm({status:'cancelled',reason:''});
const returnForm = useForm({ reason:'', evidence:[] });
const evidenceForm = useForm({ evidence_url:'', reference:'' });
function requestReturn(id) { returnForm.post(`/tienda/pedidos/${id}/postventa`, {preserveScroll:true, onSuccess:()=>returnForm.reset()}); }
function sendEvidence(id) { evidenceForm.post(`/tienda/pedidos/${id}/comprobante`, {preserveScroll:true, onSuccess:()=>evidenceForm.reset()}); }
</script>
<template>
  <CampusLayout title="Mis pedidos">
    <div class="max-w-[1180px] mx-auto space-y-5">
      <div class="flex justify-between items-end"><div><div class="text-xs text-campus-blue font-semibold">3.5 · 3.6 · 3.7 · 3.8 · 3.10</div><h1 class="text-2xl font-bold text-slate-800">Mis pedidos</h1><p class="text-sm text-slate-500 mt-1">Consulta de estados, comprobantes y postventa.</p></div><div class="flex gap-3"><Link href="/tienda/postventa" class="text-sm text-campus-blue">Postventa</Link><Link href="/tienda" class="text-sm text-campus-blue">← Tienda</Link></div></div>
      <div class="bg-white rounded-xl border border-campus-border overflow-hidden shadow-card">
        <table class="w-full text-sm"><thead class="bg-slate-50 text-xs text-slate-500"><tr><th class="text-left p-3">Folio</th><th class="text-left p-3">Total</th><th class="text-left p-3">Pago</th><th class="text-left p-3">Estado</th><th class="text-left p-3">Método</th><th class="text-left p-3">Fecha / acciones</th></tr></thead>
        <tbody><tr v-for="o in orders.data" :key="o.id" class="border-t border-slate-100 align-top"><td class="p-3 font-semibold text-campus-navy">{{ o.folio }}</td><td class="p-3">${{ Number(o.total).toFixed(2) }}</td><td class="p-3">{{ o.payment_status }}<p v-if="o.payment_status==='pending' || o.payment_status==='failed'" class="text-[10px] text-amber-700 mt-1">No confirmado como cobro real</p></td><td class="p-3"><span class="text-[10px] rounded-full px-2 py-1 bg-blue-50 text-blue-700">{{ o.status }}</span></td><td class="p-3">{{ o.payment_method }}</td><td class="p-3 text-xs text-slate-400">{{ o.created_at }}<p v-if="o.hold_expires_at" class="mt-1 text-xs text-amber-800">Reserva hasta {{ new Date(o.hold_expires_at).toLocaleString('es-MX') }}</p><p v-if="['unknown','release_pending'].includes(o.inventory_state)" class="text-xs text-amber-800">Inventario pendiente de confirmación.</p><button v-if="['pending_inventory','awaiting_payment','inventory_review'].includes(o.status)" @click="cancelForm.post(`/tienda/pedidos/${o.id}/estado`,{preserveScroll:true})" :disabled="cancelForm.processing" class="mt-2 rounded border border-red-200 px-2 py-1 text-red-700">Cancelar pedido</button>
            <div v-if="o.status==='awaiting_payment' && ['spei','openpay'].includes(o.payment_method) && ['pending','pending_validation'].includes(o.payment_status)" class="mt-2 min-w-48 space-y-1"><input v-model="evidenceForm.evidence_url" placeholder="URL del comprobante" class="w-full rounded border p-2 text-xs"/><input v-model="evidenceForm.reference" placeholder="Referencia bancaria" class="w-full rounded border p-2 text-xs"/><button @click="sendEvidence(o.id)" class="rounded bg-campus-blue text-white px-2 py-1 text-xs">Enviar comprobante</button></div>
            <div v-if="['confirmed','preparing','ready','delivered'].includes(o.status)" class="mt-2 min-w-48 space-y-1"><input v-model="returnForm.reason" placeholder="Motivo de devolución/postventa" class="w-full rounded border p-2 text-xs"/><button @click="requestReturn(o.id)" class="rounded border px-2 py-1 text-xs">Solicitar postventa</button></div>
          </td></tr><tr v-if="!orders.data?.length"><td colspan="6" class="p-10 text-center text-slate-400">Aún no hay pedidos.</td></tr></tbody></table>
      </div>
    </div>
  </CampusLayout>
</template>
