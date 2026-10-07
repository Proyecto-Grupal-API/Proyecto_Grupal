<script setup>
import GuestLayout from '@/Layouts/GuestLayout.vue';
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import TextInput from '@/Components/TextInput.vue';
import { Head, router, useForm } from '@inertiajs/vue3';

const form = useForm({
    current_password: '',
    password: '',
    password_confirmation: '',
});

const submit = () => {
    form.put(route('password.initial.update'), {
        onFinish: () => form.reset(),
    });
};
</script>

<template>
    <GuestLayout>
        <Head title="Establecer contraseña" />

        <p class="mb-4 text-sm text-gray-600">
            Debes establecer una contraseña definitiva antes de continuar.
        </p>

        <form @submit.prevent="submit" class="space-y-4">
            <div>
                <InputLabel for="current_password" value="Contraseña temporal" />
                <TextInput id="current_password" v-model="form.current_password" type="password" autocomplete="current-password" class="mt-1 block w-full" required autofocus />
                <InputError :message="form.errors.current_password" class="mt-2" />
            </div>
            <div>
                <InputLabel for="password" value="Contraseña nueva" />
                <TextInput id="password" v-model="form.password" type="password" autocomplete="new-password" class="mt-1 block w-full" required />
                <InputError :message="form.errors.password" class="mt-2" />
            </div>
            <div>
                <InputLabel for="password_confirmation" value="Confirmar contraseña nueva" />
                <TextInput id="password_confirmation" v-model="form.password_confirmation" type="password" autocomplete="new-password" class="mt-1 block w-full" required />
                <InputError :message="form.errors.password_confirmation" class="mt-2" />
            </div>
            <div class="flex items-center justify-between">
                <button type="button" class="text-sm text-gray-600 underline" @click="router.post(route('logout'))">Cerrar sesión</button>
                <PrimaryButton :disabled="form.processing">Guardar contraseña</PrimaryButton>
            </div>
        </form>
    </GuestLayout>
</template>
