<script setup>
import { useForm, Link } from '@inertiajs/vue3'

defineProps({
    users: {
        type: Array,
        default: () => [],
    },
})

const form = useForm({
    user_id: '',
    uid: '',
})

const submit = () => {
    form.post(route('nfc-cards.store'))
}
</script>

<template>
    <div class="min-h-screen bg-gray-100 py-10">
        <div class="mx-auto max-w-3xl px-6">

            <!-- Encabezado -->
            <div class="mb-6">
                <Link
                    :href="route('nfc-cards.index')"
                    class="text-sm text-indigo-600 hover:text-indigo-800"
                >
                    ← Volver a tarjetas NFC
                </Link>

                <h1 class="mt-3 text-3xl font-bold text-gray-900">
                    Registrar tarjeta NFC
                </h1>

                <p class="mt-2 text-gray-600">
                    Asocia una tarjeta NFC con un estudiante.
                </p>
            </div>

            <!-- Formulario -->
            <div class="rounded-lg bg-white p-6 shadow">

                <form @submit.prevent="submit">

                    <!-- Estudiante -->
                    <div>
                        <label
                            for="user_id"
                            class="block text-sm font-medium text-gray-700"
                        >
                            Estudiante
                        </label>

                        <select
                            id="user_id"
                            v-model="form.user_id"
                            class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                        >
                            <option value="" disabled>
                                Selecciona un estudiante
                            </option>

                            <option
                                v-for="user in users"
                                :key="user.id"
                                :value="user.id"
                            >
                                {{ user.name }} — {{ user.email }}
                            </option>
                        </select>

                        <p
                            v-if="form.errors.user_id"
                            class="mt-1 text-sm text-red-600"
                        >
                            {{ form.errors.user_id }}
                        </p>
                    </div>

                    <!-- UID -->
                    <div class="mt-6">
                        <label
                            for="uid"
                            class="block text-sm font-medium text-gray-700"
                        >
                            UID de la tarjeta NFC
                        </label>

                        <input
                            id="uid"
                            v-model="form.uid"
                            type="text"
                            placeholder="Ej. 04A1B2C3D4"
                            class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                        />

                        <p class="mt-1 text-sm text-gray-500">
                            Ingresa el identificador único de la tarjeta.
                        </p>

                        <p
                            v-if="form.errors.uid"
                            class="mt-1 text-sm text-red-600"
                        >
                            {{ form.errors.uid }}
                        </p>
                    </div>

                    <!-- Botones -->
                    <div class="mt-8 flex items-center justify-end gap-3">

                        <Link
                            :href="route('nfc-cards.index')"
                            class="rounded-md bg-gray-200 px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-300"
                        >
                            Cancelar
                        </Link>

                        <button
                            type="submit"
                            :disabled="form.processing"
                            class="rounded-md bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-700 disabled:opacity-50"
                        >
                            {{ form.processing ? 'Registrando...' : 'Registrar tarjeta' }}
                        </button>

                    </div>

                </form>

            </div>
        </div>
    </div>
</template>