<script setup>
import { ref, computed, watch, onBeforeUnmount } from 'vue';
import { router, Link } from '@inertiajs/vue3';
import axios from 'axios';
const props=defineProps({enabled:Boolean,confirmed:Boolean});
const enabled=ref(props.enabled),confirmed=ref(props.confirmed),qr=ref(''),code=ref(''),recovery=ref([]),busy=ref(false),error=ref(''),message=ref(''),passwordNeeded=ref(false),password=ref('');
let retry=null;
watch(()=>[props.enabled,props.confirmed],([a,b])=>{enabled.value=a;confirmed.value=b;});
const status=computed(()=>confirmed.value?'2FA confirmado':enabled.value?'2FA pendiente de confirmar':'2FA no configurado');
const errorText=e=>Object.values(e.response?.data?.errors||{}).flat().join(' ') || e.response?.data?.message || 'No se pudo completar la configuración.';
function clearSecrets(){qr.value='';code.value='';recovery.value=[];password.value='';}
async function refresh(){await new Promise(resolve=>router.reload({only:['auth','twoFactorEnabled','twoFactorConfirmed'],onFinish:resolve}));}
async function run(work){
 if(busy.value)return;busy.value=true;error.value='';message.value='';
 try{await work();retry=null;}catch(e){
  if(e.response?.status===423){passwordNeeded.value=true;retry=work;error.value='Confirma tu contraseña para continuar.';}
  else{error.value=errorText(e);}
 }finally{busy.value=false;}
}
async function loadQr(){
 const r=await axios.get(route('two-factor.qr-code'));if(!r.data.svg)throw new Error('QR unavailable');qr.value=r.data.svg;
}
function configure(){run(async()=>{
 if(!enabled.value){await axios.post(route('two-factor.enable'));enabled.value=true;confirmed.value=false;await refresh();}
 await loadQr();
});}
function confirm(){run(async()=>{
 await axios.post(route('two-factor.confirm'),{code:code.value.trim()});
 confirmed.value=true;clearSecrets();await refresh();message.value='Segundo factor confirmado. Ya puedes volver a Administrar acceso.';
});}
function showRecovery(){run(async()=>{const r=await axios.get(route('two-factor.recovery-codes'));recovery.value=r.data;});}
function disable(){
 if(!window.confirm('Desactivar 2FA bloqueará las modificaciones administrativas hasta que vuelvas a configurarlo y confirmarlo.'))return;
 run(async()=>{await axios.delete(route('two-factor.disable'));enabled.value=false;confirmed.value=false;clearSecrets();await refresh();message.value='Segundo factor desactivado.';});
}
async function confirmPassword(){
 if(busy.value)return;busy.value=true;error.value='';
 try{await axios.post(route('password.confirm.store'),{password:password.value});password.value='';passwordNeeded.value=false;}
 catch(e){error.value=errorText(e);return;}finally{busy.value=false;password.value='';}
 const pending=retry;retry=null;if(pending)await run(pending);
}
onBeforeUnmount(()=>{clearSecrets();retry=null;});
</script>
<template>
 <section class="overflow-hidden rounded-2xl border border-blue-200 bg-white shadow-sm">
  <div class="flex flex-wrap items-center justify-between gap-4 bg-blue-900 p-6 text-white"><div><p class="text-xs uppercase tracking-wider text-blue-200">Módulo 1.2 · Fortify 2FA</p><h2 class="mt-2 text-2xl font-bold">{{status}}</h2><p class="mt-2 text-sm text-blue-100">Confirma un código de tu aplicación autenticadora para habilitar cambios administrativos.</p></div>
   <div class="flex flex-wrap gap-2"><button v-if="!confirmed" :disabled="busy" @click="configure" class="rounded-lg bg-white px-4 py-2 text-sm font-semibold text-blue-900 disabled:opacity-50">{{enabled?'Completar configuración':'Configurar 2FA'}}</button><button v-if="confirmed" :disabled="busy" @click="showRecovery" class="rounded-lg border border-blue-300 px-4 py-2 text-sm">Ver códigos de recuperación</button><button v-if="enabled" :disabled="busy" @click="disable" class="rounded-lg border border-blue-300 px-4 py-2 text-sm">Desactivar 2FA</button></div>
  </div>
  <div v-if="error || message || passwordNeeded || qr || recovery.length" class="space-y-4 p-6">
   <p v-if="error" role="alert" class="rounded-lg bg-red-50 p-3 text-red-700">{{error}}</p><p v-if="message" role="status" class="rounded-lg bg-emerald-50 p-3 text-emerald-800">{{message}}</p>
   <form v-if="passwordNeeded" @submit.prevent="confirmPassword" class="max-w-md space-y-3"><label class="block">Confirma tu contraseña<input v-model="password" type="password" autocomplete="current-password" required :disabled="busy" class="mt-1 w-full rounded-lg border-slate-300" /></label><button :disabled="busy" class="rounded-lg bg-blue-900 px-4 py-2 text-white">Confirmar y continuar</button></form>
   <div v-if="qr && !confirmed" class="grid gap-6 sm:grid-cols-2"><div><p class="mb-3 text-sm text-slate-600">Escanea este QR con tu aplicación autenticadora. No lo compartas.</p><div v-html="qr" class="inline-block rounded-xl border bg-white p-3" /></div><form @submit.prevent="confirm" class="max-w-md space-y-3"><label class="block">Código de seis dígitos<input v-model="code" type="text" inputmode="numeric" autocomplete="one-time-code" pattern="[0-9]{6}" maxlength="6" required :disabled="busy" class="mt-1 w-full rounded-lg border-slate-300" /></label><button :disabled="busy" class="rounded-lg bg-blue-900 px-4 py-2 text-white">Confirmar segundo factor</button><p class="text-xs text-slate-500">Usa el código vigente. Si cambia antes de enviarlo, escribe el nuevo.</p></form></div>
   <div v-if="recovery.length" class="rounded-xl bg-slate-50 p-4"><p class="mb-3 text-sm">Guarda estos códigos en un lugar privado. Cada uno sirve una sola vez si pierdes acceso a tu aplicación.</p><ul class="grid gap-2 font-mono text-sm sm:grid-cols-2"><li v-for="value in recovery" :key="value">{{value}}</li></ul><button @click="recovery=[]" class="mt-4 text-sm text-blue-900">Ocultar códigos</button></div>
   <Link v-if="confirmed && $page.props.campusAuthorization?.canManage" :href="route('campus.authorization.index')" class="inline-block font-semibold text-blue-900">Volver a Administrar acceso →</Link>
  </div>
 </section>
</template>
