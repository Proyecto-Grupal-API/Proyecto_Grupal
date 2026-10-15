<script setup>
import { usePage, Head } from '@inertiajs/vue3';
import Modulo6Layout from '@/Layouts/Modulo6Layout.vue';
defineProps({ title: String, subtitle: String });
const page = usePage();
</script>
<template>
    <Head :title="title || 'Servicios al estudiante'" />
    <Modulo6Layout :headerTitle="title || 'Servicios al estudiante'">
        <div class="campus-page space-y-5">
            <h1 v-if="title" class="text-2xl font-bold">{{ title }}</h1>
            <p v-if="subtitle" class="text-gray-600">{{ subtitle }}</p>
            <p v-if="page.props.flash?.success" role="status" class="rounded-lg bg-green-50 text-green-800 p-4">{{ page.props.flash.success }}</p>
            <p v-if="Object.keys(page.props.errors || {}).length" role="alert" class="rounded-lg bg-red-50 text-red-800 p-4">{{ Object.values(page.props.errors).flat().join(' ') }}</p>
            <p class="text-sm rounded-lg border bg-blue-50 p-3">Los cobros y depósitos están pendientes de conectar con la cartera. Las becas de impresiones y lockers se asignan como beneficios de servicio.</p>
            <slot />
        </div>
    </Modulo6Layout>
</template>
