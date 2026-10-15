<script setup>
import { Link } from '@inertiajs/vue3';
import CampusLayout from '../../Layouts/MarketplaceLayout.vue';
defineProps({message:String});
</script>
<template><CampusLayout title="Marketplace"><div class="mx-auto max-w-xl rounded-xl border bg-white p-6"><h1 class="text-xl font-bold">No se pudo abrir esta sección</h1><p class="my-4 text-slate-600">{{ message }}</p><Link href="/tienda" class="text-campus-blue">Volver al catálogo</Link></div></CampusLayout></template>
