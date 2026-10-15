<script setup>
import CampusLayout from '../../Layouts/MarketplaceLayout.vue';
import { useForm, router } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
const props=defineProps({ products:Object, managedBusinesses:Array, inventoryCatalogs:Object });
const editing=ref(null);
const defaults=()=>({business_id:props.managedBusinesses?.[0]?.id || '',name:'',kind:'product',category:'General',description:'',price:'',currency:'MXN',image:'',active:true,available:true,inventory_required:true,inventory_product_id:'',inventory_location_id:''});
const form=useForm(defaults());
const inventory=computed(()=>props.inventoryCatalogs?.[form.business_id] || {products:[],locations:[]});
const canEdit=p=>props.managedBusinesses?.some(b=>b.id===p.business_id);
watch(()=>form.kind,kind=>{form.inventory_required=kind==='product';});
watch(()=>form.business_id,()=>{if (!editing.value) {form.inventory_product_id='';form.inventory_location_id='';}});
function edit(p){editing.value=p.id;Object.assign(form,defaults(),Object.fromEntries(Object.keys(defaults()).map(k=>[k,p[k] ?? defaults()[k]])));}
function reset(){editing.value=null;form.defaults(defaults());form.reset();form.clearErrors();}
function save(){const options={preserveScroll:true,onSuccess:reset};editing.value ? form.put(`/tienda/productos/${editing.value}`,options) : form.post('/tienda/productos',options);}
</script>
<template><CampusLayout title="Productos y servicios"><div class="mx-auto max-w-[1180px] space-y-5">
  <div><p class="text-xs text-campus-blue">CATÁLOGO COMERCIAL</p><h1 class="text-2xl font-bold">Productos y servicios</h1><p class="text-sm text-slate-500">Los productos físicos se vinculan a existencias de Inventarios; los servicios no reservan stock.</p></div>
  <form v-if="managedBusinesses?.length" @submit.prevent="save" class="space-y-4 rounded-xl border bg-white p-5">
    <h2 class="font-bold">{{ editing ? 'Editar artículo' : 'Crear artículo' }}</h2>
    <div class="grid gap-3 md:grid-cols-3"><label class="text-sm">Negocio<select v-model="form.business_id" :disabled="!!editing" required class="mt-1 w-full rounded-lg border"><option v-for="b in managedBusinesses" :key="b.id" :value="b.id">{{ b.name }}</option></select></label><label class="text-sm">Nombre<input v-model="form.name" required maxlength="150" class="mt-1 w-full rounded-lg border"/></label><label class="text-sm">Tipo<select v-model="form.kind" class="mt-1 w-full rounded-lg border"><option value="product">Producto físico</option><option value="service">Servicio</option></select></label></div>
    <div class="grid gap-3 md:grid-cols-3"><label class="text-sm">Categoría<input v-model="form.category" required class="mt-1 w-full rounded-lg border"/></label><label class="text-sm">Precio MXN<input v-model="form.price" type="number" min="0.01" max="99999999.99" step="0.01" required class="mt-1 w-full rounded-lg border"/></label><label class="text-sm">Imagen (URL opcional)<input v-model="form.image" type="url" class="mt-1 w-full rounded-lg border"/></label></div>
    <label class="block text-sm">Descripción<textarea v-model="form.description" maxlength="2000" class="mt-1 w-full rounded-lg border"></textarea></label>
    <div v-if="form.inventory_required" class="grid gap-3 rounded-lg bg-slate-50 p-3 md:grid-cols-2"><label class="text-sm">Producto en Inventarios<select v-model="form.inventory_product_id" required class="mt-1 w-full rounded-lg border"><option value="">Selecciona un producto</option><option v-for="p in inventory.products" :key="p.id" :value="p.id">{{ p.name }}</option></select></label><label class="text-sm">Ubicación de salida<select v-model="form.inventory_location_id" required class="mt-1 w-full rounded-lg border"><option value="">Selecciona una ubicación</option><option v-for="l in inventory.locations" :key="l.id" :value="l.id">{{ l.name || l.code || l.id }}</option></select></label><p v-if="inventory.unavailable" class="text-sm text-amber-800">Inventarios no está disponible. Reintenta antes de publicar.</p></div>
    <div class="flex flex-wrap gap-4 text-sm"><label><input v-model="form.active" type="checkbox"/> Activo</label><label><input v-model="form.available" type="checkbox"/> Disponible para pedidos</label></div>
    <div class="flex gap-3"><button :disabled="form.processing" class="rounded-lg bg-campus-blue px-4 py-2 text-white">Guardar</button><button v-if="editing" type="button" @click="reset" class="rounded-lg border px-4 py-2">Cancelar edición</button></div>
  </form>
  <div class="overflow-x-auto rounded-xl border bg-white"><table class="w-full text-sm"><thead class="bg-slate-50"><tr><th class="p-3 text-left">Artículo</th><th class="p-3 text-left">Precio</th><th class="p-3 text-left">Existencias disponibles</th><th class="p-3 text-left">Estado</th><th class="p-3 text-left">Acciones</th></tr></thead><tbody><tr v-for="p in products.data" :key="p.id" class="border-t"><td class="p-3">{{ p.name }}<p class="text-xs text-slate-500">{{ p.category }} · {{ p.kind==='product' ? 'Producto' : 'Servicio' }}</p></td><td class="p-3">${{ Number(p.price).toFixed(2) }} MXN</td><td class="p-3">{{ !p.inventory_required ? 'No aplica' : p.stock_snapshot ?? 'Sin confirmar' }}</td><td class="p-3">{{ p.active && p.available ? 'Disponible' : 'Inactivo' }}</td><td class="p-3"><div v-if="canEdit(p)" class="flex gap-3"><button @click="edit(p)" class="text-campus-blue">Editar</button><button v-if="p.active" @click="router.delete(`/tienda/productos/${p.id}`,{preserveScroll:true})" class="text-red-700">Desactivar</button></div></td></tr><tr v-if="!products.data?.length"><td colspan="5" class="p-8 text-center text-slate-500">No hay artículos visibles.</td></tr></tbody></table></div>
</div></CampusLayout></template>
