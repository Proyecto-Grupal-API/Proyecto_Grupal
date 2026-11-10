<script setup>
import { Head, Link, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import Modulo6Layout from '@/Layouts/Modulo6Layout.vue';
defineProps({ title: String, subtitle: String });
const page = usePage();
const listado = computed(() => {
    const [ruta, consulta] = page.url.split('?');
    const params = new URLSearchParams(consulta);
    if (!params.has('registro')) return null;
    params.delete('registro');
    return params.size ? `${ruta}?${params}` : ruta;
});
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
            <div v-if="listado" class="flex flex-wrap items-center justify-between gap-3 rounded-lg border bg-white p-3 text-sm">
                <span>Vista del registro del aviso</span>
                <Link :href="listado" class="font-semibold text-blue-800 underline">Ver todos mis registros</Link>
            </div>
            <slot />
        </div>
    </Modulo6Layout>
</template>
