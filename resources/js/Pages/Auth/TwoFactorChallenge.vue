<script setup>
import GuestLayout from '@/Layouts/GuestLayout.vue';
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import TextInput from '@/Components/TextInput.vue';
import { Head, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';

const usingRecoveryCode = ref(false);

const form = useForm({
    code: '',
    recovery_code: '',
});

const submit = () => {
    if (usingRecoveryCode.value) {
        form.code = '';
    } else {
        form.recovery_code = '';
        form.code = form.code.replace(/\s/g, '');
    }

    form.post(route('two-factor.login.store'), {
        onFinish: () => form.reset('code', 'recovery_code'),
    });
};

const toggleMode = () => {
    usingRecoveryCode.value = !usingRecoveryCode.value;
    form.clearErrors();
    form.reset('code', 'recovery_code');
};
</script>

<template>
    <GuestLayout>
        <Head title="Verificación en dos pasos" />

        <div class="mb-6">
            <h1 class="text-xl font-semibold text-gray-900">Verificación en dos pasos</h1>
            <p class="mt-2 text-sm text-gray-600">
            <template v-if="!usingRecoveryCode">
                Abre tu aplicación de autenticación e introduce el código de 6 dígitos.
            </template>
            <template v-else>
                Introduce uno de los códigos de recuperación que guardaste al activar 2FA.
            </template>
            </p>
        </div>

        <form @submit.prevent="submit">
            <div v-if="!usingRecoveryCode">
                <InputLabel for="code" value="Código de autenticación" />
                <TextInput
                    id="code"
                    type="text"
                    inputmode="numeric"
                    v-model="form.code"
                    autofocus
                    autocomplete="one-time-code"
                    maxlength="6"
                    required
                    class="mt-1 block w-full tracking-[0.35em]"
                    placeholder="000000"
                />
                <InputError class="mt-2" :message="form.errors.code" />
            </div>

            <div v-else>
                <InputLabel for="recovery_code" value="Código de recuperación" />
                <TextInput
                    id="recovery_code"
                    type="text"
                    v-model="form.recovery_code"
                    autocomplete="one-time-code"
                    autofocus
                    required
                    class="mt-1 block w-full"
                />
                <InputError class="mt-2" :message="form.errors.recovery_code" />
            </div>

            <div class="mt-6 flex items-center justify-between gap-4">
                <button
                    type="button"
                    class="text-sm font-medium text-blue-700 underline hover:text-blue-900"
                    @click="toggleMode"
                >
                    {{ usingRecoveryCode ? 'Usar código de autenticación' : 'Usar código de recuperación' }}
                </button>

                <PrimaryButton :disabled="form.processing">
                    Verificar
                </PrimaryButton>
            </div>
        </form>
    </GuestLayout>
</template>
