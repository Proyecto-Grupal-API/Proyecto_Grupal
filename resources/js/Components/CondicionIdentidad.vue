<script setup>
import { ref, onMounted } from 'vue';
import axios from 'axios';
const data = ref(null), error = ref('');
onMounted(async () => {
    try { data.value = (await axios.get('/api/identidad/mi-condicion')).data.data; }
    catch (e) { error.value = e.response?.data?.message || 'No se pudo consultar tu condición académica.'; }
});
</script>
<template>
    <section class="rounded-xl border bg-white p-5">
        <h2 class="font-semibold text-blue-950">Mi condición académica</h2>
        <p v-if="data" class="mt-2">{{ data.name }} · {{ data.enrollment }} · {{ data.status_label }}</p>
        <p v-else class="mt-2 text-sm text-gray-600">{{ error || 'Consultando Identidad…' }}</p>
        <p class="mt-2 text-xs text-gray-500">Información del módulo de Identidad. La condición académica no determina por sí sola la elegibilidad para becas.</p>
    </section>
</template>
