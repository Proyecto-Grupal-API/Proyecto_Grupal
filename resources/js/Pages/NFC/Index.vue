<script setup>
import { ref } from 'vue'
import { Link, router, usePage } from '@inertiajs/vue3'

const props = defineProps({
    cards: {
        type: Array,
        default: () => [],
    },
    canManage: {
        type: Boolean,
        default: false,
    },
    availableTransitions: {
        type: Object,
        default: () => ({}),
    },
})

const page = usePage()

// Tarjeta seleccionada para cambiar de estado
const selectedCard = ref(null)

// Nuevo estado seleccionado
const selectedStatus = ref('')
const reportingLost = ref(false)
const processing = ref(false)

// Motivo del cambio
const reason = ref('')

// Mostrar/ocultar modal
const showModal = ref(false)

// Errores de validación
const errorMessage = ref('')

// Estados disponibles
const statusLabels = {
    active: 'Activa',
    blocked: 'Bloqueada',
    suspended: 'Suspendida',
    replaced: 'Reemplazada',
}

// Abrir modal para cambiar estado
const openStatusModal = (card, status, lost = false) => {
    selectedCard.value = card
    selectedStatus.value = status
    reportingLost.value = lost
    reason.value = ''
    errorMessage.value = ''
    showModal.value = true
}

// Cerrar modal
const closeModal = () => {
    showModal.value = false
    selectedCard.value = null
    selectedStatus.value = ''
    reportingLost.value = false
    reason.value = ''
    errorMessage.value = ''
}

// Cambiar estado
const updateStatus = () => {
    if (processing.value) return

    if (!reason.value.trim()) {
        errorMessage.value = 'Debes indicar el motivo del cambio.'
        return
    }

    if (!selectedCard.value) {
        return
    }

    processing.value = true
    router.patch(
        route(reportingLost.value ? 'nfc-cards.report-lost' : 'nfc-cards.update-status', selectedCard.value.id),
        reportingLost.value
            ? { reason: reason.value }
            : { status: selectedStatus.value, reason: reason.value },
        {
            preserveScroll: true,
            onFinish: () => { processing.value = false },
            onSuccess: () => {
                closeModal()
            },
            onError: (errors) => {
                errorMessage.value =
                    errors.reason ||
                    errors.status ||
                    'No se pudo actualizar el estado.'
            },
        }
    )
}
</script>

<template>
    <div class="min-h-screen bg-gray-100 py-10">
        <div class="mx-auto max-w-7xl px-6">

            <!-- Encabezado -->
            <div class="mb-6 flex items-center justify-between">
                <div>
                    <h1 class="text-3xl font-bold text-gray-900">
                        Tarjetas NFC
                    </h1>

                    <p class="mt-2 text-gray-600">
                        Administración y ciclo de vida de credenciales NFC.
                    </p>
                </div>

                <Link
                    v-if="canManage"
                    :href="route('nfc-cards.create')"
                    class="rounded-md bg-indigo-600 px-4 py-2 text-sm font-medium text-white shadow hover:bg-indigo-700"
                >
                    + Registrar tarjeta
                </Link>
            </div>

            <!-- Mensaje de éxito -->
            <div
                v-if="page.props.flash?.success"
                class="mb-6 rounded-md bg-green-100 px-4 py-3 text-sm text-green-800"
            >
                {{ page.props.flash.success }}
            </div>

            <!-- Tabla -->
            <div class="overflow-hidden rounded-lg bg-white shadow">

                <!-- Sin tarjetas -->
                <div
                    v-if="cards.length === 0"
                    class="p-8 text-center"
                >
                    <p class="text-gray-500">
                        No hay tarjetas NFC registradas.
                    </p>

                    <Link
                        v-if="canManage"
                        :href="route('nfc-cards.create')"
                        class="mt-4 inline-block text-indigo-600 hover:text-indigo-800"
                    >
                        Registrar la primera tarjeta
                    </Link>
                </div>

                <!-- Con tarjetas -->
                <div v-else class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200">

                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">
                                    ID
                                </th>

                                <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">
                                    Estudiante
                                </th>

                                <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">
                                    UID
                                </th>

                                <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">
                                    Estado
                                </th>

                                <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">
                                    Registrada
                                </th>

                                <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">
                                    Acciones
                                </th>
                            </tr>
                        </thead>

                        <tbody class="divide-y divide-gray-200 bg-white">

                            <tr
                                v-for="card in cards"
                                :key="card.id"
                            >
                                <!-- ID -->
                                <td class="whitespace-nowrap px-6 py-4 text-sm text-gray-900">
                                    {{ card.id }}
                                </td>

                                <!-- Estudiante -->
                                <td class="whitespace-nowrap px-6 py-4">
                                    <div class="text-sm font-medium text-gray-900">
                                        {{ card.user?.name }}
                                    </div>

                                    <div class="text-sm text-gray-500">
                                        {{ card.user?.email }}
                                    </div>
                                </td>

                                <!-- UID -->
                                <td class="whitespace-nowrap px-6 py-4">
                                    <span class="font-mono text-sm text-gray-700">
                                        {{ card.uid }}
                                    </span>
                                </td>

                                <!-- Estado -->
                                <td class="whitespace-nowrap px-6 py-4">
                                    <span
                                        class="inline-flex rounded-full px-3 py-1 text-xs font-semibold"
                                        :class="{
                                            'bg-green-100 text-green-800':
                                                card.status === 'active',

                                            'bg-red-100 text-red-800':
                                                card.status === 'blocked',

                                            'bg-yellow-100 text-yellow-800':
                                                card.status === 'suspended',

                                            'bg-gray-100 text-gray-800':
                                                card.status === 'replaced',
                                        }"
                                    >
                                        {{ statusLabels[card.status] }}
                                    </span>
                                </td>

                                <!-- Fecha -->
                                <td class="whitespace-nowrap px-6 py-4 text-sm text-gray-500">
                                    {{ card.registered_at }}
                                </td>

                                <!-- Acciones -->
                                <td class="px-6 py-4">
                                    <div class="flex flex-wrap gap-2">

                                        <template v-if="canManage">
                                            <button
                                                v-if="availableTransitions[card.id]?.includes('active')"
                                                type="button"
                                                @click="openStatusModal(card, 'active')"
                                                class="rounded-md bg-green-100 px-3 py-2 text-xs font-medium text-green-700 hover:bg-green-200"
                                            >
                                                Reactivar
                                            </button>
                                            <button
                                                v-if="availableTransitions[card.id]?.includes('blocked')"
                                                type="button"
                                                @click="openStatusModal(card, 'blocked')"
                                                class="rounded-md bg-red-100 px-3 py-2 text-xs font-medium text-red-700 hover:bg-red-200"
                                            >
                                                Bloquear
                                            </button>
                                            <button
                                                v-if="availableTransitions[card.id]?.includes('suspended')"
                                                type="button"
                                                @click="openStatusModal(card, 'suspended')"
                                                class="rounded-md bg-yellow-100 px-3 py-2 text-xs font-medium text-yellow-700 hover:bg-yellow-200"
                                            >
                                                Suspender
                                            </button>
                                            <button
                                                v-if="availableTransitions[card.id]?.includes('blocked')"
                                                type="button"
                                                @click="openStatusModal(card, 'blocked', true)"
                                                class="rounded-md bg-orange-100 px-3 py-2 text-xs font-medium text-orange-700 hover:bg-orange-200"
                                            >
                                                Reportar pérdida
                                            </button>
                                        </template>

                                        <!-- Ver historial -->
                                        <Link
                                            :href="route('nfc-cards.history', card.id)"
                                            class="rounded-md bg-indigo-100 px-3 py-2 text-xs font-medium text-indigo-700 hover:bg-indigo-200"
                                        >
                                            Historial
                                        </Link>

                                    </div>
                                </td>
                            </tr>

                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <!-- MODAL PARA CAMBIAR ESTADO -->
    <div
        v-if="showModal"
        class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 px-4"
    >
        <div class="w-full max-w-md rounded-lg bg-white p-6 shadow-xl">

            <h2 class="text-xl font-bold text-gray-900">
                {{ reportingLost ? 'Reportar pérdida de tarjeta' : 'Cambiar estado de tarjeta' }}
            </h2>

            <p class="mt-2 text-sm text-gray-600">
                Tarjeta:
                <span class="font-mono font-semibold">
                    {{ selectedCard?.uid }}
                </span>
            </p>

            <div class="mt-4 rounded-md bg-gray-50 p-3">
                <p class="text-sm text-gray-600">
                    Estado actual:
                    <span class="font-semibold">
                        {{ statusLabels[selectedCard?.status] }}
                    </span>
                </p>

                <p class="mt-1 text-sm text-gray-600">
                    Nuevo estado:
                    <span class="font-semibold">
                        {{ statusLabels[selectedStatus] }}
                    </span>
                </p>
            </div>

            <!-- Motivo -->
            <div class="mt-5">
                <label
                    for="reason"
                    class="block text-sm font-medium text-gray-700"
                >
                    Motivo del cambio *
                </label>

                <textarea
                    id="reason"
                    v-model="reason"
                    rows="4"
                    maxlength="500"
                    placeholder="Escribe el motivo del cambio de estado..."
                    class="mt-2 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                ></textarea>

                <p class="mt-1 text-xs text-gray-500">
                    {{ reason.length }}/500 caracteres
                </p>
            </div>

            <!-- Error -->
            <div
                v-if="errorMessage"
                class="mt-3 rounded-md bg-red-100 px-3 py-2 text-sm text-red-700"
            >
                {{ errorMessage }}
            </div>

            <!-- Botones -->
            <div class="mt-6 flex justify-end gap-3">

                <button
                    type="button"
                    :disabled="processing"
                    @click="closeModal"
                    class="rounded-md bg-gray-100 px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-200"
                >
                    Cancelar
                </button>

                <button
                    type="button"
                    :disabled="processing"
                    @click="updateStatus"
                    class="rounded-md bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-700"
                >
                    {{ processing ? 'Guardando…' : reportingLost ? 'Confirmar pérdida' : 'Confirmar cambio' }}
                </button>

            </div>
        </div>
    </div>
</template>
