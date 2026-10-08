<script setup>
import CampusLayout from '../../Layouts/CampusLayout.vue';
import { Link } from '@inertiajs/vue3';
defineProps({ businesses: Object });
</script>

<template>
  <CampusLayout title="Negocios">
    <div class="max-w-[1180px] mx-auto">
      <div class="flex justify-between items-end mb-5">
        <div><div class="text-xs text-campus-blue font-semibold">3.1 · 3.2</div><h1 class="text-2xl font-bold text-slate-800">Negocios virtuales</h1><p class="text-sm text-slate-500 mt-1">Registro, clasificación, autorización, visibilidad y personal.</p></div>
        <Link href="/tienda" class="text-sm text-campus-blue font-semibold">← Volver a Tienda</Link>
      </div>
      <div class="grid md:grid-cols-2 xl:grid-cols-3 gap-4">
        <div v-for="b in businesses.data" :key="b.id" class="bg-white border border-campus-border rounded-xl p-5 shadow-card">
          <div class="flex justify-between"><div class="h-11 w-11 rounded-lg bg-blue-50 text-campus-blue flex items-center justify-center font-bold">CD</div><span class="text-[10px] px-2 py-1 rounded-full" :class="b.status==='approved' ? 'bg-green-50 text-green-700':'bg-amber-50 text-amber-700'">{{ b.status }}</span></div>
          <h3 class="font-bold text-slate-800 mt-4">{{ b.name }}</h3>
          <p class="text-xs text-slate-500 mt-1">{{ b.description }}</p>
          <div class="mt-4 text-xs text-slate-500">Tipo: <b>{{ b.type }}</b></div>
          <div class="mt-1 text-xs text-slate-500">Visibilidad: <b>{{ b.visibility }}</b></div>
        </div>
      </div>
    </div>
  </CampusLayout>
</template>
