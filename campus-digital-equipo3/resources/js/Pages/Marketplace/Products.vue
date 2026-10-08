<script setup>
import CampusLayout from '../../Layouts/CampusLayout.vue';
import { Link } from '@inertiajs/vue3';
defineProps({ products: Object });
</script>

<template>
  <CampusLayout title="Catálogo">
    <div class="max-w-[1180px] mx-auto">
      <div class="flex justify-between items-end mb-5">
        <div><div class="text-xs text-campus-blue font-semibold">3.4 · CATÁLOGO</div><h1 class="text-2xl font-bold text-slate-800">Productos y servicios</h1><p class="text-sm text-slate-500 mt-1">Catálogo común preparado para variantes, imágenes, atributos y disponibilidad.</p></div>
        <Link href="/tienda" class="text-sm text-campus-blue font-semibold">← Volver a Tienda</Link>
      </div>
      <div class="bg-white rounded-xl border border-campus-border overflow-hidden shadow-card">
        <table class="w-full text-sm">
          <thead class="bg-slate-50 text-xs text-slate-500"><tr><th class="text-left p-3">Producto</th><th class="text-left p-3">Categoría</th><th class="text-left p-3">Precio</th><th class="text-left p-3">Stock (referencia)</th><th class="text-left p-3">Estado</th></tr></thead>
          <tbody><tr v-for="p in products.data" :key="p.id" class="border-t border-slate-100"><td class="p-3 font-semibold">{{ p.name }}</td><td class="p-3">{{ p.category }}</td><td class="p-3">${{ Number(p.price).toFixed(2) }}</td><td class="p-3">{{ p.stock_snapshot }}</td><td class="p-3"><span class="text-[10px] rounded-full px-2 py-1 bg-green-50 text-green-700">{{ p.active ? 'Activo':'Inactivo' }}</span></td></tr></tbody>
        </table>
      </div>
    </div>
  </CampusLayout>
</template>
