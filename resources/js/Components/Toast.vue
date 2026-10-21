<template>
  <div v-if="show" class="fixed bottom-4 right-4 z-50 rounded-md bg-slate-900 px-4 py-2 text-sm font-medium text-white shadow-lg transition-all">
    {{ message }}
  </div>
</template>
<script setup>
import { ref, watch } from 'vue';
import { usePage } from '@inertiajs/vue3';
const show = ref(false);
const message = ref('');
const page = usePage();
watch(() => page.props.flash, (flash) => {
    if (flash?.success) { message.value = flash.success; show.value = true; }
    else if (flash?.error) { message.value = flash.error; show.value = true; }
    setTimeout(() => show.value = false, 3000);
}, { deep: true, immediate: true });
</script>