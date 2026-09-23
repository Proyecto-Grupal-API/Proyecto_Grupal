<script setup>
import { Link } from '@inertiajs/vue3'

defineProps({
    card: {
        type: Object,
        required: true,
    },

    events: {
        type: Array,
        default: () => [],
    },
})

const statusLabels = {
    active: 'Activa',
    blocked: 'Bloqueada',
    suspended: 'Suspendida',
    replaced: 'Reemplazada',
}

const eventLabels = {
    registered: 'Registrada',
    active: 'Reactivada',
    reactivated: 'Reactivada',
    blocked: 'Bloqueada',
    lost: 'Bloqueada por pérdida',
    suspended: 'Suspendida',
    replaced: 'Reemplazada',
}

const statusClasses = {
    active: 'bg-green-100 text-green-800',
    blocked: 'bg-red-100 text-red-800',
    suspended: 'bg-yellow-100 text-yellow-800',
    replaced: 'bg-gray-100 text-gray-800',
}

const formatDate = (date) => {
    if (!date) return '—'

    return new Date(date).toLocaleString('es-MX', {
        dateStyle: 'medium',
        timeStyle: 'short',
    })
}
</script>

<template>
    <div class="min-h-screen bg-gray-100 py-10">
        <div class="mx-auto max-w-6xl px-6">

            <!-- Encabezado -->
            <div class="mb-6 flex items-center justify-between">
                <div>
                    <h1 class="text-3xl font-bold text-gray-900">
                        Historial de credencial
                    </h1>

                    <p class="mt-2 text-gray-600">
                        Registro de todos los cambios realizados a la tarjeta NFC.
                    </p>
                </div>

                <Link
                    :href="route('nfc-cards.index')"
                    class="rounded-md bg-gray-200 px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-300"
                >
                    ← Volver a tarjetas
                </Link>
            </div>

            <!-- Información de la tarjeta -->
            <div class="mb-6 rounded-lg bg-white p-6 shadow">
                <h2 class="text-lg font-semibold text-gray-900">
                    Información de la tarjeta
                </h2>

                <div class="mt-4 grid gap-4 md:grid-cols-3">

                    <!-- Estudiante -->
                    <div>
                        <p class="text-sm text-gray-500">
                            Estudiante
                        </p>

                        <p class="mt-1 font-medium text-gray-900">
                            {{ card.user?.name || 'Sin estudiante' }}
                        </p>

                        <p class="text-sm text-gray-500">
                            {{ card.user?.email || '' }}
                        </p>
                    </div>

                    <!-- UID -->
                    <div>
                        <p class="text-sm text-gray-500">
                            UID de tarjeta
                        </p>

                        <p class="mt-1 font-mono font-semibold text-gray-900">
                            {{ card.uid }}
                        </p>
                    </div>

                    <!-- Estado actual -->
                    <div>
                        <p class="text-sm text-gray-500">
                            Estado actual
                        </p>

                        <span
                            class="mt-1 inline-flex rounded-full px-3 py-1 text-xs font-semibold"
                            :class="statusClasses[card.status]"
                        >
                            {{ statusLabels[card.status] || card.status }}
                        </span>
                    </div>

                </div>
            </div>

            <!-- Historial -->
            <div class="overflow-hidden rounded-lg bg-white shadow">

                <div class="border-b border-gray-200 px-6 py-4">
                    <h2 class="text-lg font-semibold text-gray-900">
                        Historial de movimientos
                    </h2>
                </div>

                <!-- Sin eventos -->
                <div
                    v-if="events.length === 0"
                    class="p-8 text-center"
                >
                    <p class="text-gray-500">
                        No hay movimientos registrados para esta tarjeta.
                    </p>
                </div>

                <!-- Eventos -->
                <div v-else class="overflow-x-auto">

                    <table class="min-w-full divide-y divide-gray-200">

                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">
                                    Fecha
                                </th>

                                <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">
                                    Evento
                                </th>

                                <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">
                                    Estado anterior
                                </th>

                                <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">
                                    Nuevo estado
                                </th>

                                <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">
                                    Motivo
                                </th>

                                <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">
                                    Realizado por
                                </th>
                            </tr>
                        </thead>

                        <tbody class="divide-y divide-gray-200 bg-white">

                            <tr
                                v-for="event in events"
                                :key="event.id"
                            >

                                <!-- Fecha -->
                                <td class="whitespace-nowrap px-6 py-4 text-sm text-gray-500">
                                    {{ formatDate(event.created_at) }}
                                </td>

                                <!-- Evento -->
                                <td class="whitespace-nowrap px-6 py-4">
                                    <span
                                        class="font-medium text-gray-900"
                                    >
                                        {{ eventLabels[event.event_type] || event.event_type }}
                                    </span>
                                </td>

                                <!-- Estado anterior -->
                                <td class="whitespace-nowrap px-6 py-4">
                                    <span
                                        v-if="event.previous_status"
                                        class="inline-flex rounded-full px-3 py-1 text-xs font-semibold"
                                        :class="statusClasses[event.previous_status]"
                                    >
                                        {{
                                            statusLabels[event.previous_status]
                                            || event.previous_status
                                        }}
                                    </span>

                                    <span
                                        v-else
                                        class="text-sm text-gray-400"
                                    >
                                        —
                                    </span>
                                </td>

                                <!-- Nuevo estado -->
                                <td class="whitespace-nowrap px-6 py-4">
                                    <span
                                        class="inline-flex rounded-full px-3 py-1 text-xs font-semibold"
                                        :class="statusClasses[event.new_status]"
                                    >
                                        {{
                                            statusLabels[event.new_status]
                                            || event.new_status
                                        }}
                                    </span>
                                </td>

                                <!-- Motivo -->
                                <td class="max-w-xs px-6 py-4 text-sm text-gray-700">
                                    {{ event.reason || '—' }}
                                </td>

                                <!-- Usuario -->
                                <td class="whitespace-nowrap px-6 py-4">
                                    <div class="text-sm font-medium text-gray-900">
                                        {{ event.performedBy?.name || 'Usuario' }}
                                    </div>

                                    <div
                                        v-if="event.performedBy?.email"
                                        class="text-sm text-gray-500"
                                    >
                                        {{ event.performedBy.email }}
                                    </div>
                                </td>

                            </tr>

                        </tbody>
                    </table>

                </div>
            </div>

        </div>
    </div>
</template>
