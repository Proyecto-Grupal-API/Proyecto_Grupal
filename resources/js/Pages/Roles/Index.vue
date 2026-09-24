<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import InputLabel from '@/Components/InputLabel.vue';
import TextInput from '@/Components/TextInput.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import TwoFactorAuthenticationForm from '@/Components/TwoFactorAuthenticationForm.vue';
import { Head, useForm } from '@inertiajs/vue3';

defineProps({
    availableRoles: {
        type: Array,
        default: () => []
    },
    userRoles: {
        type: Array,
        default: () => []
    },
    twoFactorEnabled: {
        type: Boolean,
        default: false
    },
    twoFactorConfigurationPending: {
        type: Boolean,
        default: false
    },
    canAssignRoles: {
        type: Boolean,
        default: false
    }
});

const roleForm = useForm({
    role_name: '',
    scope_type: '',
    scope_id: '',
});

const submitRole = () => {
    roleForm.post(route('roles.assign'), {
        onSuccess: () => roleForm.reset(),
    });
};

</script>

<template>
    <Head title="Roles y Seguridad" />

    <AuthenticatedLayout>
        <div class="py-8 bg-slate-50 min-h-screen">
            <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8 space-y-6">

                <!-- Encabezado de sección estilo Campus Digital -->
                <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-2">
                    <div>
                        <span class="text-xs font-semibold tracking-wider text-blue-600 uppercase">
                            IDENTIDAD Y ACCESO (EQUIPO 1)
                        </span>
                        <h1 class="text-2xl font-bold text-gray-900">
                            Roles contextuales y seguridad
                        </h1>
                    </div>
                </div>

                <div class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
                    <TwoFactorAuthenticationForm
                        :initially-enabled="twoFactorEnabled"
                        :initially-pending="twoFactorConfigurationPending"
                    />
                </div>

                <!-- Grid de dos columnas con tarjetas blancas -->
                <div class="grid grid-cols-1 lg:grid-cols-12 gap-6">

                    <!-- Columna Izquierda: Mis Roles Actuales (Módulo 1.3) -->
                    <div class="lg:col-span-7 bg-white rounded-2xl border border-slate-200 p-6 shadow-sm space-y-4">
                        <div>
                            <div class="flex items-center justify-between">
                                <span class="text-xs font-semibold uppercase tracking-wider text-blue-600">
                                    MÓDULO 1.3
                                </span>
                                <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-emerald-50 text-emerald-700 border border-emerald-200">
                                    RBAC Activo
                                </span>
                            </div>
                            <h3 class="text-lg font-bold text-gray-900 mt-1">
                                Mis roles y ámbitos asignados
                            </h3>
                            <p class="text-xs text-gray-500">
                                Listado de permisos contextuales otorgados en la plataforma.
                            </p>
                        </div>

                        <div class="overflow-hidden rounded-xl border border-slate-200">
                            <table class="min-w-full divide-y divide-slate-200 text-sm">
                                <thead class="bg-slate-50 text-xs font-semibold uppercase text-slate-600">
                                    <tr>
                                        <th class="px-4 py-3 text-left">Rol</th>
                                        <th class="px-4 py-3 text-left">Contexto</th>
                                        <th class="px-4 py-3 text-left">Identificador</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-slate-100 bg-white">
                                    <tr v-for="(r, index) in userRoles" :key="index" class="hover:bg-slate-50/70 transition-colors">
                                        <td class="px-4 py-3 font-semibold text-blue-900 uppercase">
                                            {{ r.name }}
                                        </td>
                                        <td class="px-4 py-3 text-gray-600">
                                            <span v-if="!r.scope_type" class="text-xs font-medium px-2 py-0.5 rounded bg-slate-100 text-slate-700">
                                                Global
                                            </span>
                                            <span v-else class="text-xs font-medium px-2 py-0.5 rounded bg-blue-50 text-blue-700 border border-blue-200">
                                                {{ r.scope_type }}
                                            </span>
                                        </td>
                                        <td class="px-4 py-3 text-xs font-mono text-gray-500">
                                            {{ r.scope_id || '—' }}
                                        </td>
                                    </tr>
                                    <tr v-if="!userRoles || userRoles.length === 0">
                                        <td colspan="3" class="px-4 py-8 text-center text-sm text-gray-400">
                                            Sin roles asignados todavía. Asigna uno usando el simulador.
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <!-- Columna Derecha: Asignación de roles (Módulo 1.3) -->
                    <!-- Solo visible/operable para administradores: /roles/assign
                         rechaza (403) cualquier intento que no venga de un admin,
                         así que ni siquiera mostramos el formulario a los demás. -->
                    <div class="lg:col-span-5 bg-white rounded-2xl border border-slate-200 p-6 shadow-sm space-y-4">
                        <div>
                            <span class="text-xs font-semibold uppercase tracking-wider text-blue-600">
                                GESTIÓN DE ROLES
                            </span>
                            <h3 class="text-lg font-bold text-gray-900 mt-1">
                                Asignar nuevo rol
                            </h3>
                            <p class="text-xs text-gray-500">
                                Otorga permisos contextuales vinculados a un negocio o asociación.
                            </p>
                        </div>

                        <p v-if="!canAssignRoles" class="text-sm text-gray-500 bg-slate-50 border border-slate-200 rounded-xl p-4">
                            Solo un administrador puede asignar roles. Si necesitas un rol distinto, contacta a un administrador de la plataforma.
                        </p>

                        <form v-else @submit.prevent="submitRole" class="space-y-4">
                            <div>
                                <InputLabel for="role_name" value="Rol a otorgar" class="text-xs font-semibold uppercase text-slate-600" />
                                <select
                                    id="role_name"
                                    v-model="roleForm.role_name"
                                    class="mt-1 block w-full rounded-xl border-slate-200 text-sm focus:border-blue-500 focus:ring-blue-500 bg-slate-50"
                                    required
                                >
                                    <option value="" disabled>Selecciona un rol</option>
                                    <option v-for="role in availableRoles" :key="role._id || role.id" :value="role.name">
                                        {{ role.display_name }} ({{ role.name }})
                                    </option>
                                </select>
                            </div>

                            <div class="space-y-3">
                                <div>
                                    <InputLabel for="scope_type" value="Ámbito (Contexto)" class="text-xs font-semibold uppercase text-slate-600" />
                                    <select
                                        id="scope_type"
                                        v-model="roleForm.scope_type"
                                        class="mt-1 block w-full rounded-xl border-slate-200 text-sm focus:border-blue-500 focus:ring-blue-500 bg-slate-50"
                                    >
                                        <option value="">Global (Toda la plataforma)</option>
                                        <option value="business">Negocio (Tienda/Comercio)</option>
                                        <option value="association">Asociación Estudiantil</option>
                                        <option value="service">Servicio Universitario</option>
                                    </select>
                                </div>

                                <div>
                                    <InputLabel for="scope_id" value="ID de la entidad (Opcional)" class="text-xs font-semibold uppercase text-slate-600" />
                                    <TextInput
                                        id="scope_id"
                                        type="text"
                                        class="mt-1 block w-full rounded-xl border-slate-200 text-sm bg-slate-50"
                                        v-model="roleForm.scope_id"
                                        placeholder="Ej. NEG-CAFETERIA, ASOC-SISTEMAS"
                                    />
                                </div>
                            </div>

                            <div class="pt-2">
                                <button
                                    type="submit"
                                    :disabled="roleForm.processing"
                                    class="w-full inline-flex justify-center items-center px-4 py-2.5 rounded-xl text-sm font-semibold bg-blue-600 text-white hover:bg-blue-700 active:bg-blue-800 transition-colors shadow-sm disabled:opacity-50"
                                >
                                    Asignar rol
                                </button>
                            </div>
                        </form>
                    </div>

                </div>

            </div>
        </div>
    </AuthenticatedLayout>
</template>
