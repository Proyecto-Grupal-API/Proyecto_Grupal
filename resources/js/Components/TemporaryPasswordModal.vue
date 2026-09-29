<script setup>
import Modal from '@/Components/Modal.vue';
import { nextTick, ref, watch } from 'vue';

const props = defineProps({
    show: { type: Boolean, default: false },
    password: { type: String, required: true },
    mode: { type: String, default: 'created' },
    studentLabel: { type: String, default: '' },
});

const emit = defineEmits(['close']);
const copyFeedback = ref('');
const copyButton = ref(null);

watch(() => props.show, async (show) => {
    copyFeedback.value = '';
    if (show) {
        await nextTick();
        copyButton.value?.focus();
    }
});

async function copyPassword() {
    try {
        if (!navigator.clipboard?.writeText) throw new Error('Clipboard unavailable');
        await navigator.clipboard.writeText(props.password);
        copyFeedback.value = 'Contraseña copiada.';
    } catch {
        copyFeedback.value = 'No fue posible copiar automáticamente. Selecciona la contraseña y cópiala manualmente.';
    }
}
</script>

<template>
    <Modal :show="show" :closeable="false" max-width="lg" aria-labelledby="temporary-password-title">
        <div class="p-6 sm:p-8">
            <h3 id="temporary-password-title" class="text-xl font-semibold text-[#00338D]">
                {{ mode === 'reissued' ? 'Contraseña temporal reemitida' : 'Cuenta creada correctamente' }}
            </h3>
            <p v-if="studentLabel" class="mt-2 text-sm text-slate-600">Estudiante: {{ studentLabel }}</p>
            <p class="mt-4 text-sm text-slate-700">Esta contraseña sólo se mostrará una vez. Entrégala al estudiante por un medio seguro. En su primer acceso deberá cambiarla.</p>
            <p class="mt-2 text-sm text-slate-600">Si se pierde, deberá reemitirse una nueva.</p>
            <div class="mt-5 rounded-lg border border-slate-200 bg-slate-50 p-4">
                <p class="text-sm font-semibold text-slate-700">Contraseña temporal</p>
                <code class="mt-2 block select-all break-all font-mono text-sm text-slate-900">{{ password }}</code>
            </div>
            <p v-if="copyFeedback" role="status" aria-live="polite" class="mt-3 text-sm text-slate-700">{{ copyFeedback }}</p>
            <div class="mt-6 flex flex-wrap justify-end gap-3">
                <button ref="copyButton" type="button" class="rounded-lg border border-[#00338D] px-4 py-2 text-sm font-semibold text-[#00338D]" @click="copyPassword">Copiar contraseña</button>
                <button type="button" class="rounded-lg bg-[#00338D] px-4 py-2 text-sm font-semibold text-white" @click="emit('close')">Cerrar</button>
            </div>
        </div>
    </Modal>
</template>
