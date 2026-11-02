<script setup>
import TwoFactorAuthenticationForm from '@/Components/TwoFactorAuthenticationForm.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import { Head, router } from '@inertiajs/vue3';
import { ref } from 'vue';

const props = defineProps({
    twoFactorEnabled: { type: Boolean, default: false },
    twoFactorConfigurationPending: { type: Boolean, default: false },
});

const confirmed = ref(props.twoFactorEnabled);
const logout = () => router.post(route('logout'));
</script>

<template>
    <Head title="Configurar autenticación de dos factores" />
    <main class="mx-auto max-w-2xl px-4 py-12">
        <h1 class="mb-6 text-2xl font-semibold text-gray-900">Configuración de seguridad requerida</h1>
        <div class="rounded-lg bg-white p-6 shadow">
            <TwoFactorAuthenticationForm
                :initially-enabled="twoFactorEnabled"
                :initially-pending="twoFactorConfigurationPending"
                required
                @confirmed="confirmed = true"
            />
            <div class="mt-6 flex gap-4">
                <PrimaryButton v-if="confirmed" type="button" @click="router.visit(route('dashboard'))">
                    Continuar
                </PrimaryButton>
                <button type="button" class="text-sm text-gray-600 underline" @click="logout">Cerrar sesión</button>
            </div>
        </div>
    </main>
</template>
