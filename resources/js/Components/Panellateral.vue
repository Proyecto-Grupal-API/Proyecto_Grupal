<script setup>
defineProps({
    show: Boolean,
    titulo: String,
});
defineEmits(["close"]);
</script>

<template>
    <div>
        <!-- Fondo oscuro semitransparente -->
        <transition
            enter-active-class="transition-opacity ease-linear duration-300"
            enter-from-class="opacity-0"
            enter-to-class="opacity-100"
            leave-active-class="transition-opacity ease-linear duration-300"
            leave-from-class="opacity-100"
            leave-to-class="opacity-0"
        >
            <div
                v-if="show"
                class="fixed inset-0 bg-gray-900 bg-opacity-50 z-40"
                @click="$emit('close')"
            ></div>
        </transition>

        <!-- Panel que se desliza -->
        <div
            v-if="show"
            role="dialog"
            aria-modal="true"
            :aria-label="titulo"
            @keydown.esc="$emit('close')"
            :class="show ? 'translate-x-0' : 'translate-x-full'"
            class="fixed inset-y-0 right-0 z-50 w-full max-w-md bg-white shadow-2xl transform transition-transform duration-300 ease-in-out flex flex-col"
        >
            <!-- Encabezado del Panel -->
            <div
                class="campus-panel-header px-6 py-4 text-white flex justify-between items-center shadow-md"
            >
                <h2 class="text-lg font-bold tracking-wide">{{ titulo }}</h2>
                <button
                    aria-label="Cerrar panel"
                    @click="$emit('close')"
                    class="text-white hover:text-gray-300 text-3xl leading-none"
                >
                    &times;
                </button>
            </div>

            <!-- Cuerpo del Panel (Formulario) -->
            <div class="flex-1 overflow-y-auto p-6 bg-gray-50">
                <slot />
            </div>

            <!-- Pie del Panel (Botones) -->
            <div
                class="px-6 py-4 border-t border-gray-200 bg-white flex justify-end space-x-3 shadow-inner"
            >
                <button
                    @click="$emit('close')"
                    class="px-4 py-2 bg-white border border-gray-300 rounded-lg text-sm font-semibold text-gray-700 hover:bg-gray-50 transition"
                >
                    Cancelar
                </button>
                <slot name="footer" />
            </div>
        </div>
    </div>
</template>
