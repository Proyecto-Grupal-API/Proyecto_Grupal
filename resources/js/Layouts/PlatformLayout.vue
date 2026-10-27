<script setup>
import { computed, ref, onMounted, onBeforeUnmount } from 'vue'
import { Link, usePage } from '@inertiajs/vue3'
import Modulo6Layout from './Modulo6Layout.vue'
import ToastContainer from '@/Platform/components/ui/ToastContainer.vue'
import '@/Platform/platform.css'
const page=usePage(), error=ref('')
const links=computed(()=>page.props.platformActor?.role==='admin' ? [
 ['Resumen','/recompensas/admin'],['Ledger','/recompensas/admin/ledger'],['Negocios','/recompensas/admin/negocios'],['Reglas','/recompensas/admin/reglas'],['Catálogo','/recompensas/admin/catalogo'],['Canjes','/recompensas/admin/canjes'],['Gobierno','/recompensas/admin/gobierno'],['Administración','/recompensas/admin/administracion'],['Auditoría','/recompensas/admin/auditoria'],['Analítica','/recompensas/admin/analitica'],['Integraciones','/recompensas/admin/integracion'],['Configuración','/recompensas/admin/configuracion']
] : page.props.platformActor?.role==='professor' ? [['Resumen','/recompensas/profesor'],['Campañas','/recompensas/profesor/campanas'],['Validar canjes','/recompensas/profesor/validar'],['Analítica','/recompensas/profesor/analitica'],['Historial','/recompensas/profesor/historial']] : [['Resumen','/recompensas/alumno'],['Campañas','/recompensas/alumno/campanas'],['Catálogo','/recompensas/alumno/catalogo'],['Mis canjes','/recompensas/alumno/canjes'],['Mi actividad','/recompensas/alumno/actividad']])
const receiveError=(event)=>{error.value=event.detail}
onMounted(()=>window.addEventListener('platform-api-error',receiveError))
onBeforeUnmount(()=>window.removeEventListener('platform-api-error',receiveError))
</script>
<template><Modulo6Layout headerTitle="Recompensas y plataforma"><div class="platform-module">
<nav class="platform-nav" aria-label="Recompensas"><Link v-for="[label,url] in links" :key="url" :href="url" :aria-current="page.url.split('?')[0]===url?'page':undefined">{{label}}</Link></nav>
<div v-if="error" role="alert" class="platform-error">{{error}} <button @click="error=''" aria-label="Cerrar aviso">×</button></div>
<slot/><ToastContainer/>
</div></Modulo6Layout></template>
<style scoped>.platform-module{padding:24px;min-height:calc(100svh - 72px)}@media(max-width:640px){.platform-module{padding:16px}}.platform-nav{display:flex;gap:.5rem;flex-wrap:wrap;margin-bottom:1.5rem}.platform-nav a{padding:.5rem .8rem;border:1px solid #cbd5e1;border-radius:.5rem;background:white}.platform-nav [aria-current]{background:#153873;color:white}.platform-error{padding:1rem;margin-bottom:1rem;border:1px solid #ef4444;background:#fff1f2;color:#991b1b;display:flex;justify-content:space-between}</style>
