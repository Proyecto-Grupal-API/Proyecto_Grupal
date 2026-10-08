<script setup>
import { useForm } from '@inertiajs/vue3';

const form = useForm({
  email: 'admin@campus.local',
  password: 'admin12345',
});

const submit = () => form.post('/login');
</script>

<template>
  <div class="min-h-screen bg-campus-navy flex items-center justify-center p-6">
    <div class="w-full max-w-md bg-white rounded-2xl shadow-2xl p-8">
      <div class="flex items-center gap-3 mb-8">
        <div class="h-12 w-12 rounded-xl bg-blue-600 text-white flex items-center justify-center font-bold text-xl">C</div>
        <div><div class="font-bold text-lg text-campus-navy">Campus Digital</div><div class="text-xs text-slate-400">Equipo 3 · Marketplace</div></div>
      </div>
      <h1 class="text-2xl font-bold text-slate-800">Acceso administrativo</h1>
      <p class="mt-1 text-sm text-slate-500">Usuario admin con acceso completo al módulo Tienda.</p>
      <form class="mt-6 space-y-4" @submit.prevent="submit">
        <div>
          <label class="text-xs font-semibold text-slate-600">Correo</label>
          <input v-model="form.email" type="email" class="mt-1 w-full rounded-lg border border-slate-200 px-3 py-2.5 outline-none focus:ring-2 focus:ring-blue-200"/>
          <div v-if="form.errors.email" class="mt-1 text-xs text-red-500">{{ form.errors.email }}</div>
        </div>
        <div>
          <label class="text-xs font-semibold text-slate-600">Contraseña</label>
          <input v-model="form.password" type="password" class="mt-1 w-full rounded-lg border border-slate-200 px-3 py-2.5 outline-none focus:ring-2 focus:ring-blue-200"/>
        </div>
        <button :disabled="form.processing" class="w-full rounded-lg bg-campus-blue px-4 py-3 text-sm font-bold text-white hover:bg-blue-700 disabled:opacity-50">
          {{ form.processing ? 'Ingresando…' : 'Ingresar' }}
        </button>
      </form>
      <div class="mt-5 rounded-lg bg-slate-50 p-3 text-xs text-slate-500">
        Demo: <strong>admin@campus.local</strong> / <strong>admin12345</strong>
      </div>
    </div>
  </div>
</template>
