<script setup>
import { Head, usePage } from '@inertiajs/vue3';
import Modulo6Layout from './Modulo6Layout.vue';
defineProps({ title: String });
const page=usePage();
</script>
<template>
  <Modulo6Layout :header-title="title"><Head :title="title"/>
    <div class="p-4 md:p-6">
      <div v-if="Object.keys(page.props.errors || {}).length" role="alert" class="mb-4 rounded-lg border border-red-200 bg-red-50 p-4 text-sm text-red-800"><p v-for="(error,key) in page.props.errors" :key="key">{{ error }}</p></div>
      <div v-if="page.props.marketplacePayment" class="mb-5 rounded-lg border border-amber-200 bg-amber-50 p-3 text-sm text-amber-900">
        <template v-if="page.props.marketplacePayment.walletAvailable">Puedes pagar con wallet en los negocios habilitados. Confirma el importe desde Mis pedidos.</template>
        <template v-else>El pago con wallet no está habilitado para tu cuenta. Registrar un pedido solo crea una reserva.</template>
        La reserva de inventario dura hasta {{ page.props.marketplacePayment.holdMinutes }} minutos.
      </div>
      <slot/>
    </div>
  </Modulo6Layout>
</template>
