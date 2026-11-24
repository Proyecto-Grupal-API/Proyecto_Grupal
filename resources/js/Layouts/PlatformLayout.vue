<script setup>
import { computed, ref, onMounted, onBeforeUnmount } from 'vue'
import { Link, usePage } from '@inertiajs/vue3'
import Modulo6Layout from './Modulo6Layout.vue'
import ToastContainer from '@/Platform/components/ui/ToastContainer.vue'
import '@/Platform/platform.css'
const page=usePage(), error=ref('')
const actor=computed(()=>page.props.platformActor||{})
// Administración: cada enlace exige el permiso de su pantalla, así que auditoría y cargos parciales sólo ven lo suyo.
const adminLinks=[['Resumen','/recompensas/admin','analytics.view'],['Ledger','/recompensas/admin/ledger','points.view_any'],['Negocios','/recompensas/admin/negocios','businesses.view'],['Reglas','/recompensas/admin/reglas','rules.view'],['Campañas','/recompensas/admin/campanas','rules.view'],['Catálogo','/recompensas/admin/catalogo','rewards.manage'],['Canjes','/recompensas/admin/canjes','redemptions.view_all'],['Gobierno','/recompensas/admin/gobierno','governance.view'],['Administración','/recompensas/admin/administracion','catalogs.view'],['Auditoría','/recompensas/admin/auditoria','audit.view'],['Analítica','/recompensas/admin/analitica','analytics.view'],['Integraciones','/recompensas/admin/integracion','integrations.view'],['Configuración','/recompensas/admin/configuracion','settings.view']]
const links=computed(()=>actor.value.area==='admin' ? adminLinks.filter(([, , permiso])=>(actor.value.permissions||[]).includes(permiso)).map(([label,url])=>[label,url])
 : actor.value.area==='professor' ? [['Resumen','/recompensas/profesor'],['Campañas','/recompensas/profesor/campanas'],['Validar canjes','/recompensas/profesor/validar'],['Analítica','/recompensas/profesor/analitica'],['Historial','/recompensas/profesor/historial']] : [['Resumen','/recompensas/alumno'],['Campañas','/recompensas/alumno/campanas'],['Catálogo','/recompensas/alumno/catalogo'],['Mis canjes','/recompensas/alumno/canjes'],['Mi actividad','/recompensas/alumno/actividad']])
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
