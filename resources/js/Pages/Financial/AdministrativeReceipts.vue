<script setup>
import {ref} from 'vue';
import axios from 'axios';
import {Head,Link} from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
const form=ref({source:'transaction',operation_id:'',wallet_id:'',scope_type:'',scope_id:''});
const downloadParams=ref({});
const receipt=ref(null),error=ref(''),busy=ref(false),reviewReason=ref(''),reviewMessage=ref('');
const money=n=>new Intl.NumberFormat('es-MX',{style:'currency',currency:receipt.value.currency}).format(n/100);
const lookup=async()=>{busy.value=true;error.value='';receipt.value=null;try{const query={...form.value};const r=await axios.get(route('financial.receipts.admin.show'),{params:query});receipt.value=r.data.data;downloadParams.value=query;}catch(e){error.value=e.response?.data?.message||'No se pudo consultar el comprobante.';}finally{busy.value=false;}};
const classify=async()=>{busy.value=true;error.value='';reviewMessage.value='';receipt.value=null;
try{await axios.post(route('financial.receipts.admin.legacy.review'),{transaction_id:form.value.operation_id,wallet_id:form.value.wallet_id,reason:reviewReason.value});reviewMessage.value='Revisión registrada. Consulta nuevamente la operación.';}catch(e){error.value=e.response?.data?.message||'No se pudo registrar la revisión.';}finally{busy.value=false;}};
</script>
<template><Head title="Consulta administrativa de comprobantes"/><AuthenticatedLayout>
<template #header><h2 class="text-2xl font-bold text-[#00338D]">Consulta administrativa · 2.9</h2></template>
<div class="bg-[#F5F8FC] min-h-screen p-6"><div class="max-w-4xl mx-auto">
<Link :href="route('financial.receipts.index')">Volver a mis comprobantes</Link>
<div class="mt-3"><Link :href="route('financial.receipts.admin.history')" class="text-blue-800">Historial de revisiones históricas</Link></div>
<p class="mt-4 text-slate-600">Requiere permiso de consulta vigente y segundo factor confirmado. Solo se muestra la participación de la wallet seleccionada.</p>
<form @submit.prevent="lookup" class="bg-white border rounded-xl p-6 mt-5 space-y-4">
<label class="block">Tipo de operación<select v-model="form.source" class="block border rounded p-2"><option value="transaction">Transacción</option><option value="purchase">Pago de compra</option></select></label>
<label class="block">ID de operación<input v-model="form.operation_id" required maxlength="36" class="block w-full border rounded p-2"/></label>
<label class="block">ID de wallet<input v-model="form.wallet_id" required maxlength="36" class="block w-full border rounded p-2"/></label>
<label class="block">Contexto<select v-model="form.scope_type" @change="form.scope_id=''" class="block border rounded p-2"><option value="">Sin contexto registrado</option><option value="business">Negocio</option><option value="association">Asociación</option></select></label>
<label v-if="form.scope_type" class="block">ID del contexto<input v-model="form.scope_id" required maxlength="128" class="block w-full border rounded p-2"/></label>
<button :disabled="busy" class="bg-blue-800 text-white rounded px-4 py-2">{{busy?'Consultando…':'Consultar'}}</button><p v-if="error" role="alert" class="text-red-700">{{error}}</p>
</form>
<section class="bg-white border rounded-xl p-6 mt-5"><h3 class="font-bold">Revisión de recarga de demostración</h3><p class="text-sm">Solo una plaza global puede revisar una operación identificada previamente. No crea un folio de caja ni modifica el saldo.</p>
<label class="block mt-3">Motivo<textarea v-model="reviewReason" maxlength="1000" class="block w-full border rounded p-2"/></label>
<button :disabled="busy || form.source !== 'transaction' || !reviewReason.trim() || !form.operation_id || !form.wallet_id" @click="classify" class="border border-blue-800 rounded px-4 py-2 mt-3">Registrar revisión histórica</button><p v-if="reviewMessage" role="status">{{reviewMessage}}</p></section>
<section v-if="receipt" class="bg-white border rounded-xl p-6 mt-5"><a :href="route('financial.receipts.admin.pdf',{_query:downloadParams})" class="inline-block bg-blue-800 text-white rounded px-4 py-2 mb-4">Descargar PDF</a><h3 class="font-bold">Comprobante {{receipt.source}}</h3><p class="font-mono break-all">{{receipt.id}}</p><p v-if="receipt.historical_notice" class="bg-amber-50 p-3 mt-3">{{receipt.historical_notice.message}}</p><p>{{receipt.status}} · {{receipt.currency}}</p>
<div v-if="receipt.purchase"><p>Total: {{money(receipt.purchase.total_amount_cents)}}</p><p>Wallet: {{money(receipt.purchase.wallet_amount_cents)}}</p><p>Bonos: {{money(receipt.purchase.bonus_amount_cents)}}</p></div>
<p v-for="entry in receipt.entries" :key="entry.id">{{entry.movement_type}} · {{money(entry.amount_cents)}}</p>
<p v-for="cash in receipt.cash_receipts" :key="cash.id" class="break-all">Folio de caja: {{cash.folio}}</p>
</section></div></div></AuthenticatedLayout></template>
