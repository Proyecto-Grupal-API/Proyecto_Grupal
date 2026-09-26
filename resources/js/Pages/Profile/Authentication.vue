<script setup>
import { Head } from '@inertiajs/vue3';
import Modulo6Layout from '@/Layouts/Modulo6Layout.vue';
import TwoFactorAuthenticationForm from '@/Components/TwoFactorAuthenticationForm.vue';
import UpdatePasswordForm from './Partials/UpdatePasswordForm.vue';
defineProps({ twoFactorEnabled: Boolean, twoFactorConfigurationPending: Boolean });
</script>
<template>
    <Head title="Autenticación" />
    <Modulo6Layout headerTitle="Autenticación">
        <div class="campus-page space-y-6">
            <header><h1 class="text-2xl font-bold">Autenticación</h1><p class="mt-2 text-sm text-gray-600">Administra tu contraseña y la verificación en dos pasos.</p></header>
            <section class="campus-profile-card"><TwoFactorAuthenticationForm :initially-enabled="twoFactorEnabled" :initially-pending="twoFactorConfigurationPending" /></section>
            <section class="campus-profile-card"><UpdatePasswordForm class="max-w-xl" /></section>
        </div>
    </Modulo6Layout>
</template>
