<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import TwoFactorSetup from '@/Components/TwoFactorSetup.vue';
import { Head } from '@inertiajs/vue3';
defineProps({ userRoles: {type:Array,default:()=>[]}, twoFactorEnabled: Boolean, twoFactorConfirmed: Boolean });
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

                <TwoFactorSetup :enabled="twoFactorEnabled" :confirmed="twoFactorConfirmed" />

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
                                    Consulta de roles
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
                                            Sin roles registrados.
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <section class="lg:col-span-5 bg-white rounded-2xl border border-slate-200 p-6 shadow-sm space-y-3">
                        <h3 class="text-lg font-bold text-gray-900">Asignación de roles</h3>
                        <p class="text-sm text-gray-600">
                            Los roles deben ser asignados mediante un proceso autorizado de la institución.
                            Desde esta pantalla puedes consultar tus roles registrados.
                        </p>
                    </section>

                </div>

            </div>
        </div>
    </AuthenticatedLayout>
</template>