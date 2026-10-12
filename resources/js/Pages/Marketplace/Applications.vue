<script setup>
import CampusLayout from '../../Layouts/CampusLayout.vue';
import { Link, useForm } from '@inertiajs/vue3';
const props = defineProps({ applications: Object, isAdmin: Boolean });
const form = useForm({ name: '', type: 'student', description: '' });
const review = useForm({ decision: 'approved', observations: '' });
function submitReview(id, decision) { review.decision = decision; review.post(`/tienda/solicitudes/${id}/resolver`, { preserveScroll: true }); }
</script>
<template>
  <CampusLayout title="Solicitudes de negocio">
    <div class="max-w-[1180px] mx-auto space-y-5">
      <div class="flex items-center justify-between"><div><p class="text-xs font-semibold text-campus-blue">3.1 · 3.2</p><h1 class="text-2xl font-bold text-slate-800">Registro y autorización</h1><p class="text-sm text-slate-500 mt-1">Las decisiones quedan registradas con responsable y fecha.</p></div><Link href="/tienda/negocios" class="text-sm text-campus-blue">← Negocios</Link></div>
      <form v-if="!isAdmin" @submit.prevent="form.post('/tienda/solicitudes')" class="bg-white rounded-xl border border-campus-border p-5 space-y-3">
        <h2 class="font-bold text-slate-800">Registrar un negocio</h2>
        <div class="grid md:grid-cols-2 gap-3"><input v-model="form.name" required maxlength="120" placeholder="Nombre del negocio" class="rounded-lg border p-3 text-sm"/><select v-model="form.type" class="rounded-lg border p-3 text-sm"><option value="student">Estudiantil</option><option value="association">Asociación</option><option value="souvenirs">Institucional</option><option value="service">Servicios</option><option value="other">Otro</option></select></div>
        <textarea v-model="form.description" required maxlength="1500" placeholder="Describe el negocio y lo que ofrecerá" class="w-full rounded-lg border p-3 text-sm"></textarea>
        <button :disabled="form.processing" class="rounded-lg bg-campus-blue px-4 py-2.5 text-sm font-semibold text-white">Enviar a revisión</button>
        <p v-if="form.errors.name || form.errors.description" class="text-sm text-red-600">{{ form.errors.name || form.errors.description }}</p>
      </form>
      <div class="bg-white rounded-xl border border-campus-border overflow-hidden">
        <div class="p-4 border-b font-bold text-slate-800">Solicitudes registradas</div>
        <div v-for="a in applications.data" :key="a.id" class="p-4 border-b last:border-0">
          <div class="flex flex-col md:flex-row md:justify-between gap-3"><div><h3 class="font-semibold text-slate-800">{{ a.business_name }}</h3><p class="text-sm text-slate-500">{{ a.description }}</p><p class="text-xs text-slate-400 mt-1">Tipo: {{ a.type }} · Enviada: {{ a.submitted_at || '—' }}</p><p v-if="a.observations" class="text-sm mt-2 text-slate-600">Observaciones: {{ a.observations }}</p></div><span class="text-xs rounded-full px-3 py-1 h-fit bg-amber-50 text-amber-700">{{ a.status }}</span></div>
          <div v-if="isAdmin && ['pending','observed'].includes(a.status)" class="mt-3 space-y-2">
            <textarea v-model="review.observations" placeholder="Observaciones de revisión (opcional)" class="w-full rounded-lg border p-2 text-sm"></textarea>
            <div class="flex flex-wrap gap-2"><button @click="submitReview(a.id,'approved')" class="rounded bg-green-700 text-white px-3 py-2 text-xs">Aprobar y crear negocio</button><button @click="submitReview(a.id,'observed')" class="rounded bg-amber-500 text-white px-3 py-2 text-xs">Solicitar ajustes</button><button @click="submitReview(a.id,'rejected')" class="rounded bg-red-600 text-white px-3 py-2 text-xs">Rechazar</button></div>
          </div>
        </div>
        <div v-if="!applications.data?.length" class="p-10 text-center text-sm text-slate-400">No hay solicitudes.</div>
      </div>
    </div>
  </CampusLayout>
</template>
