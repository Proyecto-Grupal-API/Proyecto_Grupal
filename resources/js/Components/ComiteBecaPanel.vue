<script setup>
import { ref, watch, nextTick } from 'vue';
import axios from 'axios';
import Modal from '@/Components/Modal.vue';
const props = defineProps({ show: Boolean, convocatoria: Object });
const emit = defineEmits(['close', 'saved']);
const cargando = ref(false), ocupado = ref(false), error = ref(''), miembros = ref([]), datos = ref(null), responsable = ref(null);
const form = ref({ version: 0, responsable_id: '', revisor_ids: [], motivo: '' });
let ciclo = 0;
const mensaje = e => Object.values(e.response?.data?.errors ?? {}).flat().join(' ') || e.response?.data?.message || 'No se pudo consultar el comité.';
const nombre = id => miembros.value.find(m => m.usuario_id === id)?.nombre || 'Integrante anterior';
watch(() => props.show, async show => {
    const actual = ++ciclo;
    if (!show || !props.convocatoria) return;
    cargando.value = true; error.value = ''; datos.value = null;
    try {
        const [comite, org] = await Promise.all([axios.get(`/api/becas/${props.convocatoria.id}/comite`), axios.get('/api/organizaciones')]);
        if (actual !== ciclo) return;
        if (org.data.organizacion?.id !== props.convocatoria.organizacion_id) throw new Error('contexto');
        datos.value = comite.data; miembros.value = org.data.miembros;
        form.value = { version: comite.data.version, responsable_id: comite.data.comite?.responsable_id ?? '', revisor_ids: [...(comite.data.comite?.revisor_ids ?? [])], motivo: '' };
        await nextTick(); responsable.value?.focus();
    } catch (e) { if (actual === ciclo) error.value = mensaje(e); }
    finally { if (actual === ciclo) cargando.value = false; }
});
watch(() => form.value.responsable_id, id => { form.value.revisor_ids = form.value.revisor_ids.filter(x => x !== id); });
async function guardar() {
    if (ocupado.value) return;
    ocupado.value = true; error.value = '';
    try {
        await axios.put(`/api/becas/${props.convocatoria.id}/comite`, form.value, { headers: { 'X-Organization-Id': props.convocatoria.organizacion_id } });
        emit('saved');
    } catch (e) { error.value = mensaje(e); }
    finally { ocupado.value = false; }
}
</script>
<template>
    <Modal :show="show" :closeable="!ocupado" @close="emit('close')" aria-labelledby="titulo-comite">
        <section class="p-5 sm:p-6 space-y-4">
            <h2 id="titulo-comite" class="text-xl font-semibold text-blue-950">Comité de becas</h2>
            <p class="text-sm text-gray-600">{{ convocatoria?.titulo }}. Los revisores recomiendan y el responsable emite la resolución final.</p>
            <p v-if="cargando" role="status">Cargando integrantes y comité…</p>
            <p v-if="error" role="alert" class="rounded-lg bg-red-50 text-red-800 p-3">{{ error }}</p>
            <template v-if="datos">
                <p v-if="datos.comite" class="text-sm">Comité vigente: versión {{ datos.version }} · Responsable: {{ nombre(datos.comite.responsable_id) }}.</p>
                <p v-if="datos.comite?.retirados_ids?.length" class="rounded-lg bg-amber-50 text-amber-900 p-3 text-sm">Hay integrantes dados de baja. Configura una nueva versión para continuar con los dictámenes.</p>
                <form v-if="datos.puede_configurar" @submit.prevent="guardar" class="space-y-4">
                    <div><label for="comite-responsable" class="block text-sm font-medium mb-1">Responsable del dictamen</label>
                        <select id="comite-responsable" ref="responsable" v-model="form.responsable_id" required :disabled="ocupado" class="w-full rounded-lg border-gray-300">
                            <option value="" disabled>Selecciona un integrante</option>
                            <option v-for="m in miembros" :key="m.usuario_id" :value="m.usuario_id">{{ m.nombre }} · {{ m.matricula || 'Sin matrícula' }}</option>
                        </select>
                    </div>
                    <fieldset class="space-y-2"><legend class="text-sm font-medium mb-2">Revisores (de 1 a 10)</legend>
                        <label v-for="m in miembros" :key="m.usuario_id" class="flex items-start gap-3 rounded-lg border p-3 text-sm">
                            <input type="checkbox" v-model="form.revisor_ids" :value="m.usuario_id" :disabled="ocupado || m.usuario_id === form.responsable_id" class="mt-1 rounded border-gray-300 text-blue-800" />
                            <span>{{ m.nombre }} · {{ m.matricula || 'Sin matrícula' }}<span v-if="m.usuario_id === form.responsable_id" class="block text-gray-500">Responsable del dictamen</span></span>
                        </label>
                    </fieldset>
                    <p class="text-xs text-gray-600">Todas las revisiones independientes deben estar registradas antes de resolver. Nadie revisa ni dictamina su propia solicitud.</p>
                    <div><label for="comite-motivo" class="block text-sm font-medium mb-1">Motivo de la designación o cambio</label><textarea id="comite-motivo" v-model="form.motivo" required minlength="10" maxlength="2000" rows="3" :disabled="ocupado" class="w-full rounded-lg border-gray-300"></textarea></div>
                    <p v-if="datos.version" class="text-sm text-amber-900 bg-amber-50 rounded-lg p-3">Guardar crea una nueva versión y requiere nuevas recomendaciones para las solicitudes pendientes. Los dictámenes previos se conservan.</p>
                    <button type="submit" :disabled="ocupado || !form.revisor_ids.length || form.revisor_ids.length > 10" class="rounded-lg bg-blue-900 text-white px-4 py-2 disabled:opacity-50">{{ ocupado ? 'Guardando…' : 'Guardar comité' }}</button>
                </form>
                <details v-if="datos.historial?.length" class="border-t pt-3 text-sm"><summary class="cursor-pointer font-medium">Historial del comité</summary><ol class="mt-3 space-y-3"><li v-for="h in datos.historial" :key="h.id" class="border rounded-lg p-3"><p>Versión {{ h.despues?.version }} · {{ h.accion === 'beca_comite_baja' ? 'Baja de integrante' : 'Designación' }}</p><p class="whitespace-pre-line">{{ h.accion === 'beca_comite_baja' ? 'Retiro de acceso por baja de integrante.' : h.despues?.motivo }}</p></li></ol></details>
            </template>
            <div class="flex justify-end border-t pt-4"><button type="button" @click="emit('close')" :disabled="ocupado" class="rounded-lg border px-4 py-2 text-blue-900">Cerrar</button></div>
        </section>
    </Modal>
</template>
