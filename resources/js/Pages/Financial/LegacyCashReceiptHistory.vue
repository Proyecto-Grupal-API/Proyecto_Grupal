<script setup>
import{ref,onMounted}from'vue';import axios from'axios';import{Head,Link}from'@inertiajs/vue3';import AuthenticatedLayout from'@/Layouts/AuthenticatedLayout.vue';
const filters=ref({transaction_id:'',wallet_id:'',reviewed_by:''}),rows=ref([]),meta=ref({current_page:1,last_page:1,total:0}),busy=ref(false),error=ref('');
const date=v=>new Date(v).toLocaleString('es-MX');
async function load(page=1){if(busy.value)return;busy.value=true;error.value='';rows.value=[];try{const{data}=await axios.get(route('financial.receipts.admin.history.records'),{params:{...filters.value,page,per_page:20}});rows.value=data.data;meta.value=data.meta;}catch(e){error.value=Object.values(e.response?.data?.errors||{}).flat().join(' ')||e.response?.data?.message||'No se pudo cargar el historial.';}finally{busy.value=false;}}
onMounted(()=>load());
</script>
<template><Head title="Historial de revisiones"/><AuthenticatedLayout><template #header><h2 class="text-2xl font-bold text-[#00338D]">Historial de revisiones · 2.9</h2></template>
<div class="bg-[#F5F8FC] min-h-screen p-6"><div class="max-w-6xl mx-auto"><Link :href="route('financial.receipts.admin.index')">Volver a consulta administrativa</Link>
<p class="my-4 text-slate-600">Revisiones registradas de operaciones de demostración. No constituyen comprobantes de recepción física de efectivo.</p>
<form @submit.prevent="load(1)" class="bg-white border rounded-xl p-5 grid gap-3 sm:grid-cols-3"><label>ID de transacción<input v-model="filters.transaction_id" maxlength="36" class="block w-full border rounded p-2"/></label><label>ID de wallet<input v-model="filters.wallet_id" maxlength="36" class="block w-full border rounded p-2"/></label><label>ID de cuenta revisora<input v-model="filters.reviewed_by" maxlength="24" class="block w-full border rounded p-2"/></label><button :disabled="busy" class="bg-blue-800 text-white rounded px-4 py-2">Filtrar</button></form>
<p v-if="error" role="alert" class="text-red-700 mt-4">{{error}}</p>
<section class="bg-white border rounded-xl p-5 mt-4"><p v-if="busy">Cargando…</p><p v-else-if="!rows.length&&!error">No hay revisiones para estos filtros.</p>
<article v-for="row in rows" :key="row.id" class="border-b py-4"><div class="flex flex-wrap justify-between gap-3"><h3 class="font-bold">Recarga de demostración revisada</h3><Link :href="route('financial.receipts.admin.history.show',{reviewId:row.id})" class="text-blue-800">Ver auditoría</Link></div><p>{{date(row.reviewed_at)}}</p><p class="text-sm break-all">Transacción: {{row.transaction_id}}</p><p class="text-sm break-all">Wallet: {{row.wallet_id}}</p><p class="text-sm break-all">Cuenta revisora: {{row.reviewed_by}}</p><p class="whitespace-pre-wrap mt-2">{{row.reason}}</p></article>
<nav v-if="!error" class="flex flex-wrap gap-4 mt-5" aria-label="Paginación"><button :disabled="busy||meta.current_page<=1" @click="load(meta.current_page-1)">Anterior</button><span>Página {{meta.current_page}} de {{meta.last_page}} · {{meta.total}} revisiones</span><button :disabled="busy||meta.current_page>=meta.last_page" @click="load(meta.current_page+1)">Siguiente</button></nav>
</section></div></div></AuthenticatedLayout></template>
