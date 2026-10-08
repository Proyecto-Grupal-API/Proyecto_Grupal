<script setup>
import CampusLayout from '../../Layouts/CampusLayout.vue';
import { Link } from '@inertiajs/vue3';
defineProps({ orders: Object });
</script>

<template>
  <CampusLayout title="Pedidos">
    <div class="max-w-[1180px] mx-auto">
      <div class="flex justify-between items-end mb-5">
        <div><div class="text-xs text-campus-blue font-semibold">3.5 · 3.10</div><h1 class="text-2xl font-bold text-slate-800">Pedidos y postventa</h1><p class="text-sm text-slate-500 mt-1">Estados, cancelaciones y trazabilidad comercial.</p></div>
        <Link href="/tienda" class="text-sm text-campus-blue font-semibold">← Volver a Tienda</Link>
      </div>
      <div class="bg-white rounded-xl border border-campus-border overflow-hidden shadow-card">
        <table class="w-full text-sm">
          <thead class="bg-slate-50 text-xs text-slate-500"><tr><th class="text-left p-3">Folio</th><th class="text-left p-3">Total</th><th class="text-left p-3">Pago</th><th class="text-left p-3">Estado</th><th class="text-left p-3">Método</th><th class="text-left p-3">Fecha</th></tr></thead>
          <tbody>
            <tr v-for="o in orders.data" :key="o.id" class="border-t border-slate-100">
              <td class="p-3 font-semibold text-campus-navy">{{ o.folio }}</td>
              <td class="p-3">${{ Number(o.total).toFixed(2) }}</td>
              <td class="p-3">{{ o.payment_status }}</td>
              <td class="p-3"><span class="text-[10px] rounded-full px-2 py-1 bg-blue-50 text-blue-700">{{ o.status }}</span></td>
              <td class="p-3">{{ o.payment_method }}</td>
              <td class="p-3 text-xs text-slate-400">{{ o.created_at }}</td>
            </tr>
            <tr v-if="!orders.data?.length"><td colspan="6" class="p-10 text-center text-slate-400">Aún no hay pedidos.</td></tr>
          </tbody>
        </table>
      </div>
    </div>
  </CampusLayout>
</template>
