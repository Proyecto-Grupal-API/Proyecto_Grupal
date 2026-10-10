<script setup>
import { ref, computed, onMounted, watch } from 'vue';
import { Head, Link } from '@inertiajs/vue3';
import axios from 'axios';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
const props=defineProps({ modules:Object, catalog:Object, twoFactorReady:Boolean });
const state=ref({seats:{},grants:{},events:[],people:{},version:0});
const email=ref(''), target=ref(null), module=ref(2), mode=ref('mixed'), selected=ref([]), scopeMode=ref('all'), scopeType=ref('business'), scopeId=ref(''), contexts=ref([]);
const starts=ref(''), ends=ref(''), reason=ref(''), busy=ref(false), loaded=ref(false), error=ref(''), message=ref(''), tab=ref('modular');
const labels={read:'Consultar',create:'Crear / emitir',approve:'Aprobar',execute:'Ejecutar',cancel:'Cancelar',configure:'Configurar'};
const scopeLabels={business:'Negocio',association:'Asociación',service:'Servicio',council:'Consejo'};
const capabilities=computed(()=>Object.entries(props.catalog).filter(([key,c])=>key!=='campus.roles.manage' && c.module===Number(module.value)));
const groups=computed(()=>{
 const result={}; for(const [key,c] of capabilities.value) { const name=c.submodule ? `${c.module}.${c.submodule}` : `Módulo ${c.module}`; (result[name]??=[]).push({key,...c}); } return result;
});
const selectedCapabilities=computed(()=>mode.value==='full' ? capabilities.value.map(([key])=>key) : selected.value);
const grantRows=computed(()=>Object.entries(state.value.grants||{}));
const person=id=>state.value.people?.[id]?.name || id;
const date=value=>value ? new Date(typeof value==='number'?value*1000:value).toLocaleString('es-MX') : 'Sin límite';
const grantStatus=g=>!g.active?'Revocada':g.ends_at && g.ends_at<=Date.now()/1000?'Vencida':g.starts_at>Date.now()/1000?'Programada':'Activa';
const errorText=e=>Object.values(e.response?.data?.errors||{}).flat().join(' ') || e.response?.data?.message || 'No se pudo completar el cambio. Consulta el historial antes de reintentar.';
async function load() { const r=await axios.get(route('campus.authorization.records')); state.value=r.data.data; loaded.value=true; }
async function lookup() {
 if(busy.value)return; busy.value=true;error.value='';target.value=null;
 try { const r=await axios.get(route('campus.authorization.lookup'),{params:{email:email.value.trim()}});target.value=r.data.data; }
 catch(e){error.value=errorText(e);}finally{busy.value=false;}
}
async function loadContexts() {
 contexts.value=[];scopeId.value='';if(scopeMode.value!=='exact')return;
 try{const r=await axios.get(route('campus.authorization.contexts'),{params:{type:scopeType.value}});contexts.value=r.data.data;}catch(e){error.value=errorText(e);}
}
watch(module,()=>{selected.value=[];});
watch([scopeMode,scopeType],loadContexts);
async function mutate(url,payload,success) {
 if(busy.value || !props.twoFactorReady || !loaded.value)return;
 busy.value=true;error.value='';message.value='';
 try { await axios.post(url,{...payload,version:state.value.version});message.value=success;await load(); }
 catch(e) { error.value=errorText(e); if(e.response?.status===409) { try{await load();}catch{} } }
 finally{busy.value=false;}
}
async function assign() {
 if(!target.value)return;
 const payload={identity:target.value.identity,module:Number(module.value),reason:reason.value.trim()};
 if(tab.value==='global') {
  if(!window.confirm(`Asignar acceso global a ${target.value.email} en la plaza de ${props.modules[module.value]}?`))return;
  await mutate(route('campus.authorization.seats'),payload,'Plaza global asignada y registrada.');
 } else {
  await mutate(route('campus.authorization.grants'),{...payload,capabilities:selectedCapabilities.value,all_contexts:scopeMode.value==='all',scope_type:scopeMode.value==='exact'?scopeType.value:null,scope_id:scopeMode.value==='exact'?scopeId.value:null,starts_at:starts.value?new Date(starts.value).toISOString():null,ends_at:ends.value?new Date(ends.value).toISOString():null},'Asignación modular registrada.');
 }
}
async function revoke(id) {const text=window.prompt('Motivo para revocar esta asignación:');if(text?.trim())await mutate(route('campus.authorization.revoke',id),{reason:text.trim()},'Asignación revocada.');}
async function release(m) {const text=window.prompt('Motivo para liberar esta plaza global. El titular conservará su UUID e historial:');if(text?.trim())await mutate(route('campus.authorization.release',m),{reason:text.trim()},'Plaza liberada. Se conservaron sus asignaciones modulares independientes.');}
onMounted(async()=>{try{await load();}catch(e){error.value=errorText(e);}});
</script>
<template>
 <Head title="Administración de acceso" />
 <AuthenticatedLayout>
  <template #header><div class="flex flex-wrap items-center justify-between gap-3"><h1 class="text-2xl font-bold text-[#00338D]">Administración de acceso del campus</h1><Link :href="route('financial.dashboard')" class="text-sm text-[#00338D]">Volver a Finanzas</Link></div></template>
  <main class="min-h-screen bg-[#F5F8FC] px-4 py-8"><div class="mx-auto max-w-6xl space-y-6">
   <p class="text-sm text-slate-600">Administra las siete plazas globales y los permisos modulares. Estas asignaciones se aplican a las funciones conectadas al nuevo núcleo.</p>
   <p v-if="!twoFactorReady" class="rounded-xl bg-amber-50 p-4 text-amber-900">Puedes consultar. Para realizar cambios activa y confirma la autenticación de dos factores en tu perfil.</p>
   <p v-if="error" role="alert" class="rounded-xl bg-red-50 p-4 text-red-700">{{error}}</p><p v-if="message" role="status" class="rounded-xl bg-emerald-50 p-4 text-emerald-800">{{message}}</p>
   <section class="rounded-2xl border bg-white p-6 shadow-sm"><h2 class="text-lg font-bold text-[#00338D]">Plazas globales</h2><div class="mt-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-3"><article v-for="(name,m) in modules" :key="m" class="rounded-xl border p-4"><h3 class="font-semibold">{{m}} · {{name}}</h3><p class="mt-2 break-all text-sm">{{state.seats[m]?person(state.seats[m]):'Vacante'}}</p><p v-if="state.seats[m]" class="mt-1 break-all text-xs text-slate-500">{{state.seats[m]}}</p><button v-if="state.seats[m]" :disabled="busy || !twoFactorReady" @click="release(m)" class="mt-3 text-sm text-red-700 disabled:opacity-40">Liberar plaza</button></article></div><p class="mt-4 text-xs text-slate-500">Cada titular tiene acceso global en el núcleo. Otro administrador debe liberar tu plaza; no se puede liberar la última.</p></section>
   <section class="rounded-2xl border bg-white p-6 shadow-sm"><h2 class="text-lg font-bold text-[#00338D]">Seleccionar cuenta existente</h2><form @submit.prevent="lookup" class="mt-4 flex flex-wrap gap-3"><label class="flex-1">Correo<input v-model="email" type="email" required :disabled="busy" class="mt-1 w-full rounded-lg border-slate-300" /></label><button :disabled="busy" class="self-end rounded-lg bg-[#00338D] px-4 py-2 text-white">Buscar cuenta</button></form><p v-if="target" class="mt-4">{{target.name}} · {{target.email}}</p></section>
   <section v-if="target" class="rounded-2xl border bg-white p-6 shadow-sm"><div class="flex gap-2"><button @click="tab='modular'" :class="tab==='modular'?'bg-[#00338D] text-white':''" class="rounded-lg border px-4 py-2">Administrador modular</button><button @click="tab='global'" :class="tab==='global'?'bg-[#00338D] text-white':''" class="rounded-lg border px-4 py-2">Plaza global</button></div>
    <form @submit.prevent="assign" class="mt-5"><fieldset :disabled="busy || !twoFactorReady || !loaded" class="space-y-4 disabled:opacity-50">
     <label class="block">Módulo<select v-model="module" class="mt-1 w-full rounded-lg border-slate-300"><option v-for="(name,m) in modules" :key="m" :value="Number(m)">{{m}} · {{name}}</option></select></label>
     <template v-if="tab==='modular'">
      <label class="block">Acceso<select v-model="mode" class="mt-1 w-full rounded-lg border-slate-300"><option value="mixed">Mixto: elegir acciones por submódulo</option><option value="full">Completo: todas las acciones declaradas del módulo</option></select></label>
      <div v-if="mode==='mixed'" class="overflow-x-auto"><table class="w-full text-sm"><thead><tr><th class="p-2 text-left">Submódulo</th><th v-for="label in labels" :key="label" class="p-2">{{label}}</th></tr></thead><tbody><tr v-for="(caps,name) in groups" :key="name" class="border-t"><th class="p-3 text-left">{{name}}</th><td v-for="c in caps" :key="c.key" class="p-3 text-center"><input v-model="selected" type="checkbox" :value="c.key" :aria-label="`${name}: ${labels[c.action]}`" /></td></tr></tbody></table></div>
      <p class="text-xs text-slate-500">{{selectedCapabilities.length}} permisos seleccionados. Aprobar y ejecutar son permisos separados.</p>
      <label class="block">Contexto<select v-model="scopeMode" class="mt-1 w-full rounded-lg border-slate-300"><option value="all">Todos los contextos del módulo</option><option value="exact">Solo un contexto validado</option><option value="none">Sin contexto</option></select></label>
      <div v-if="scopeMode==='exact'" class="grid gap-3 sm:grid-cols-2"><label>Tipo<select v-model="scopeType" class="mt-1 w-full rounded-lg border-slate-300"><option v-for="(label,type) in scopeLabels" :key="type" :value="type">{{label}}</option></select></label><label>Contexto<select v-model="scopeId" required class="mt-1 w-full rounded-lg border-slate-300"><option value="">Selecciona</option><option v-for="c in contexts" :key="c.id" :value="c.id">{{c.label}} · {{c.id}}</option></select></label><p v-if="!contexts.length" class="text-sm text-amber-800 sm:col-span-2">No hay contextos activos registrados para este tipo. Deben provenir del módulo responsable.</p></div>
      <div class="grid gap-3 sm:grid-cols-2"><label>Inicio opcional<input v-model="starts" type="datetime-local" class="mt-1 w-full rounded-lg border-slate-300" /></label><label>Vencimiento opcional<input v-model="ends" type="datetime-local" class="mt-1 w-full rounded-lg border-slate-300" /></label></div>
     </template>
     <p v-else class="rounded-lg bg-amber-50 p-3 text-sm text-amber-900">Esta plaza permite administrar toda la plataforma mediante el núcleo, aunque esté asociada a un módulo. Debe estar vacante.</p>
     <label class="block">Motivo obligatorio<textarea v-model="reason" required maxlength="1000" class="mt-1 w-full rounded-lg border-slate-300" /></label>
     <button :disabled="tab==='modular' && !selectedCapabilities.length" class="rounded-lg bg-[#00338D] px-4 py-2 font-semibold text-white disabled:opacity-40">Asignar y registrar</button>
    </fieldset></form>
   </section>
   <section class="rounded-2xl border bg-white p-6 shadow-sm"><h2 class="text-lg font-bold text-[#00338D]">Administradores modulares</h2><p v-if="!grantRows.length" class="mt-4 text-slate-500">No hay asignaciones.</p><article v-for="[id,g] in grantRows" :key="id" class="mt-4 rounded-xl border p-4"><div class="flex flex-wrap justify-between gap-3"><h3 class="font-semibold">{{person(g.principal)}} · {{modules[g.module]}} · {{grantStatus(g)}}</h3><button v-if="g.active" :disabled="busy || !twoFactorReady" @click="revoke(id)" class="text-sm text-red-700 disabled:opacity-40">Revocar</button></div><p class="mt-2 text-sm">Contexto: {{g.all_contexts?'Todos':g.scope?`${scopeLabels[g.scope.type]} · ${g.scope.id}`:'Sin contexto'}}</p><p class="mt-1 text-sm">Vigencia: {{date(g.starts_at)}} — {{date(g.ends_at)}}</p><div class="mt-3 flex flex-wrap gap-2"><span v-for="c in g.capabilities" :key="c" class="rounded bg-slate-100 px-2 py-1 text-xs">{{c}}</span></div></article></section>
   <section class="rounded-2xl border bg-white p-6 shadow-sm"><h2 class="text-lg font-bold text-[#00338D]">Historial reciente</h2><p class="mt-1 text-xs text-slate-500">Últimos 50 cambios. Versión actual: {{state.version}}</p><details v-for="event in state.events" :key="event.id" class="mt-4 border-t pt-3"><summary class="cursor-pointer text-sm">{{date(event.at)}} · {{event.reason}} · versión {{event.version}}</summary><p class="mt-2 break-all text-xs">Responsable: {{event.actor || 'Inicialización por consola'}}</p><pre class="mt-3 overflow-x-auto rounded-lg bg-slate-50 p-3 text-xs">{{JSON.stringify({before:event.before,after:event.after},null,2)}}</pre></details></section>
  </div></main>
 </AuthenticatedLayout>
</template>
