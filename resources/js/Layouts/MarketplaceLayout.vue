<script setup>
import { Head, Link, usePage } from '@inertiajs/vue3';
import Modulo6Layout from './Modulo6Layout.vue';
defineProps({ title: String });
const page=usePage();
</script>
<template>
  <Modulo6Layout :header-title="title"><Head :title="title"/>
    <div class="p-4 md:p-6">
      <div v-if="Object.keys(page.props.errors || {}).length" role="alert" class="mb-4 rounded-lg border border-red-200 bg-red-50 p-4 text-sm text-red-800"><p v-for="(error,key) in page.props.errors" :key="key">{{ error }}</p></div>
      <div class="mb-5 rounded-lg border border-amber-200 bg-amber-50 p-3 text-sm text-amber-900">Los pedidos quedan pendientes de pago. La reserva de inventario dura 15 minutos; no se cobran fondos ni se otorgan puntos todavía.</div>
      <slot/>
    </div>
  </Modulo6Layout>
</template>
