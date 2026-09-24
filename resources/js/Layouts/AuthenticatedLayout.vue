<script setup>
import Modulo6Layout from '@/Layouts/Modulo6Layout.vue';
</script>
<template>
    <Modulo6Layout headerTitle="Identidad y seguridad">
        <div class="campus-page">
            <header v-if="$slots.header" class="mb-6"><slot name="header" /></header>
            <slot />
        </div>
    </Modulo6Layout>
</template>
