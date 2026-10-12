<script setup>
import CampusLayout from '../../Layouts/CampusLayout.vue';
import { Link, useForm, router } from '@inertiajs/vue3';
import { ref } from 'vue';

const props = defineProps({ products: Object, managedBusinesses: Array });
const editingId = ref(null);
const editForm = useForm({ name:'', kind:'product', category:'General', description:'', price:'', image:'', active:true, available:true, inventory_required:true, stock_snapshot:0, variants_json:'[]', attributes_json:'{}', tags:[] });
const form = useForm({ business_id: props.managedBusinesses?.[0]?.id || '', name:'', kind:'product', category:'General', description:'', price:'', currency:'MXN', image:'', active:true, available:true, inventory_required:true, stock_snapshot:0, variants:[], attributes:{}, variants_json:'[]', attributes_json:'{}', tags:[] });

function submitCreate() {
  let variants, attributes;
  try { variants = JSON.parse(form.variants_json || '[]'); attributes = JSON.parse(form.attributes_json || '{}'); }
  catch { alert('Variantes debe ser JSON de arreglo y atributos JSON de objeto.'); return; }
  form.transform(data => ({ ...data, variants, attributes })).post('/tienda/productos', {
    preserveScroll: true,
    onSuccess: () => form.reset('name','description','price','image','variants_json','attributes_json')
  });
}
function startEdit(p) {
  editingId.value = p.id;
  editForm.name = p.name; editForm.kind = p.kind || 'product'; editForm.category = p.category || 'General';
  editForm.description = p.description || ''; editForm.price = p.price; editForm.image = p.image || '';
  editForm.active = !!p.active; editForm.available = !!p.available; editForm.inventory_required = !!p.inventory_required;
  editForm.stock_snapshot = p.stock_snapshot || 0; editForm.variants_json = JSON.stringify(p.variants || [], null, 2);
  editForm.attributes_json = JSON.stringify(p.attributes || {}, null, 2);
}
function saveEdit(id) {
  let variants, attributes;
  try { variants = JSON.parse(editForm.variants_json || '[]'); attributes = JSON.parse(editForm.attributes_json || '{}'); }
  catch { alert('Variantes debe ser JSON de arreglo y atributos JSON de objeto.'); return; }
  editForm.transform(data => ({ ...data, variants, attributes })).put(`/tienda/productos/${id}`, {
    preserveScroll: true, onSuccess: () => editingId.value = null
  });
}
function deactivate(id) {
  if (confirm('¿Desactivar este artículo? Se conservará el historial de pedidos.')) router.delete(`/tienda/productos/${id}`, { preserveScroll: true });
}
</script>
<template>
  <CampusLayout title="Catálogo">
    <div class="max-w-[1180px] mx-auto space-y-5">
      <div class="flex justify-between items-end"><div><div class="text-xs text-campus-blue font-semibold">3.4 · CATÁLOGO</div><h1 class="text-2xl font-bold text-slate-800">Productos y servicios</h1><p class="text-sm text-slate-500 mt-1">Alta, edición, variantes, atributos, precio y disponibilidad.</p></div><Link href="/tienda" class="text-sm text-campus-blue">← Volver a Tienda</Link></div>
      <form v-if="managedBusinesses?.length" @submit.prevent="submitCreate()" class="bg-white rounded-xl border border-campus-border p-5 space-y-3">
        <h2 class="font-bold text-slate-800">Crear producto o servicio</h2>
        <div class="grid md:grid-cols-3 gap-3"><select v-model="form.business_id" required class="rounded-lg border p-3 text-sm"><option v-for="b in managedBusinesses" :key="b.id" :value="b.id">{{ b.name }}</option></select><input v-model="form.name" required placeholder="Nombre" class="rounded-lg border p-3 text-sm"/><select v-model="form.kind" class="rounded-lg border p-3 text-sm"><option value="product">Producto</option><option value="service">Servicio</option></select></div>
        <div class="grid md:grid-cols-3 gap-3"><input v-model="form.category" required placeholder="Categoría" class="rounded-lg border p-3 text-sm"/><input v-model="form.price" required type="number" min="0.01" step="0.01" placeholder="Precio MXN" class="rounded-lg border p-3 text-sm"/><input v-model="form.stock_snapshot" type="number" min="0" placeholder="Stock de referencia" class="rounded-lg border p-3 text-sm"/></div>
        <textarea v-model="form.description" placeholder="Descripción" class="w-full rounded-lg border p-3 text-sm"></textarea><input v-model="form.image" placeholder="URL de imagen (opcional)" class="w-full rounded-lg border p-3 text-sm"/>
        <div class="grid md:grid-cols-2 gap-3"><label class="text-xs text-slate-500">Variantes (JSON arreglo)<textarea v-model="form.variants_json" class="mt-1 w-full rounded border p-2 text-xs font-mono" rows="3"></textarea></label><label class="text-xs text-slate-500">Atributos (JSON objeto)<textarea v-model="form.attributes_json" class="mt-1 w-full rounded border p-2 text-xs font-mono" rows="3"></textarea></label></div>
        <div class="flex flex-wrap gap-4 text-xs"><label><input type="checkbox" v-model="form.active"/> Activo</label><label><input type="checkbox" v-model="form.available"/> Disponible</label><label><input type="checkbox" v-model="form.inventory_required"/> Requiere inventario externo</label></div>
        <button :disabled="form.processing" class="rounded-lg bg-campus-blue text-white px-4 py-2 text-sm">Guardar artículo</button>
        <p v-if="form.errors.business_id || form.errors.price" class="text-sm text-red-600">{{ form.errors.business_id || form.errors.price }}</p>
      </form>
      <div class="bg-white rounded-xl border border-campus-border overflow-hidden shadow-card">
        <table class="w-full text-sm"><thead class="bg-slate-50 text-xs text-slate-500"><tr><th class="text-left p-3">Producto</th><th class="text-left p-3">Categoría</th><th class="text-left p-3">Precio</th><th class="text-left p-3">Stock ref.</th><th class="text-left p-3">Estado</th><th class="p-3">Acción</th></tr></thead>
        <tbody><tr v-for="p in products.data" :key="p.id" class="border-t border-slate-100"><td class="p-3 font-semibold">{{ p.name }}<p class="text-xs text-slate-400">{{ p.kind }}</p></td><td class="p-3">{{ p.category }}</td><td class="p-3">${{ Number(p.price).toFixed(2) }} {{ p.currency }}</td><td class="p-3">{{ p.stock_snapshot }}</td><td class="p-3"><span class="text-[10px] rounded-full px-2 py-1" :class="p.active && p.available ? 'bg-green-50 text-green-700':'bg-slate-100 text-slate-500'">{{ p.active && p.available ? 'Activo':'Inactivo' }}</span></td><td class="p-3"><div class="flex flex-col gap-2"><button @click="startEdit(p)" class="text-xs text-campus-blue">Editar</button><button v-if="p.active" @click="deactivate(p.id)" class="text-xs text-red-600">Desactivar</button></div></td></tr><tr v-if="editingId===p.id" class="border-t bg-blue-50/50"><td colspan="6" class="p-4"><form @submit.prevent="saveEdit(p.id)" class="space-y-2"><div class="grid md:grid-cols-3 gap-2"><input v-model="editForm.name" required class="rounded border p-2 text-xs" placeholder="Nombre"/><input v-model="editForm.category" required class="rounded border p-2 text-xs" placeholder="Categoría"/><input v-model="editForm.price" type="number" min="0.01" step="0.01" required class="rounded border p-2 text-xs" placeholder="Precio"/></div><textarea v-model="editForm.description" class="w-full rounded border p-2 text-xs" placeholder="Descripción"></textarea><div class="grid md:grid-cols-2 gap-2"><textarea v-model="editForm.variants_json" rows="3" class="rounded border p-2 text-xs font-mono" placeholder="Variantes JSON"></textarea><textarea v-model="editForm.attributes_json" rows="3" class="rounded border p-2 text-xs font-mono" placeholder="Atributos JSON"></textarea></div><div class="flex gap-3 text-xs"><label><input type="checkbox" v-model="editForm.active"/> Activo</label><label><input type="checkbox" v-model="editForm.available"/> Disponible</label><label><input type="checkbox" v-model="editForm.inventory_required"/> Requiere inventario</label><input v-model="editForm.stock_snapshot" type="number" min="0" class="w-24 rounded border p-1" placeholder="Stock"/></div><div class="flex gap-2"><button class="rounded bg-campus-blue text-white px-3 py-2 text-xs">Guardar cambios</button><button type="button" @click="editingId=null" class="rounded border px-3 py-2 text-xs">Cancelar</button></div></form></td></tr><tr v-if="!products.data?.length"><td colspan="6" class="p-10 text-center text-slate-400">No hay productos.</td></tr></tbody></table>
      </div>
    </div>
  </CampusLayout>
</template>
