<script setup>
import { ref, watch, onMounted } from 'vue';
import axios from 'axios';
const props = defineProps({ revision: Object, solicitudId: String, organizacionId: String });
const emit = defineEmits(['saved']);
const opcion = ref('favorable'), motivo = ref(''), ocupado = ref(false), error = ref(''), aviso = ref(''), nombres = ref({});
watch(() => props.revision, r => { opcion.value = r?.recomendacion_propia?.recomendacion ?? 'favorable'; motivo.value = r?.recomendacion_propia?.motivo ?? ''; }, { immediate: true });
onMounted(async () => { try { const { data } = await axios.get('/api/organizaciones'); if (data.organizacion?.id === props.organizacionId) nombres.value = Object.fromEntries(data.miembros.map(m => [m.usuario_id, m.nombre])); } catch { /* Los expedientes siguen siendo legibles si el directorio provisional no responde. */ } });
async function guardar() {
    if (ocupado.value) return;
    ocupado.value = true; error.value = ''; aviso.value = '';
    try {
        await axios.put(`/api/becas/solicitudes/${props.solicitudId}/recomendacion`, { version_comite: props.revision.version, revision: props.revision.recomendacion_propia?.revision ?? 0, recomendacion: opcion.value, motivo: motivo.value }, { headers: { 'X-Organization-Id': props.organizacionId } });
        aviso.value = 'Recomendación guardada.'; emit('saved');
    } catch (e) { error.value = Object.values(e.response?.data?.errors ?? {}).flat().join(' ') || e.response?.data?.message || 'No se pudo guardar la recomendación.'; }
    finally { ocupado.value = false; }
}
</script>
<template>
    <section class="rounded-xl border bg-white p-5 space-y-4" aria-labelledby="revision-comite-titulo">
        <h2 id="revision-comite-titulo" class="text-lg font-semibold">Revisión del comité</h2>
        <p class="text-sm text-gray-600">Versión {{ revision.version }} · {{ revision.funcion === 'responsable' ? 'Responsable del dictamen' : revision.funcion === 'revisor' ? 'Revisor asignado' : 'Supervisión de presidencia' }}.</p>
        <p v-if="revision.dictamen_utilizado" class="text-sm text-gray-600">La resolución registrada utilizó el comité versión {{ revision.dictamen_utilizado.version }}. Sus evidencias se conservan.</p>
        <p v-else-if="!revision.integrantes_vigentes" role="status" class="rounded-lg bg-amber-50 text-amber-900 p-3 text-sm">Presidencia debe reemplazar a los integrantes que dejaron de estar activos antes de resolver.</p>
        <p v-else-if="!revision.puede_resolver" class="text-sm text-amber-900">Recomendaciones pendientes: {{ revision.revisiones_pendientes }}. La resolución requiere al menos una revisión independiente y todas las revisiones que correspondan.</p>
        <p v-else class="text-sm text-green-800">Las recomendaciones independientes están completas.</p>

        <p v-if="aviso" role="status" class="rounded-lg bg-green-50 text-green-800 p-3 text-sm">{{ aviso }}</p>
        <form v-if="revision.puede_recomendar" @submit.prevent="guardar" class="space-y-3">
            <p class="text-sm text-gray-600">Tu recomendación orienta al responsable. Puedes corregirla mientras la solicitud esté pendiente de resolución.</p>
            <label for="comite-recomendacion" class="block text-sm font-medium">Recomendación</label>
            <select id="comite-recomendacion" v-model="opcion" :disabled="ocupado" class="w-full rounded-lg border-gray-300"><option value="favorable">Favorable</option><option value="desfavorable">Desfavorable</option></select>
            <label for="comite-observaciones" class="block text-sm font-medium">Observaciones y evidencia de revisión</label>
            <textarea id="comite-observaciones" v-model="motivo" required minlength="10" maxlength="2000" rows="4" :disabled="ocupado" class="w-full rounded-lg border-gray-300"></textarea>
            <p v-if="error" role="alert" class="rounded-lg bg-red-50 text-red-800 p-3 text-sm">{{ error }}</p>
            <button :disabled="ocupado" class="rounded-lg bg-blue-900 text-white px-4 py-2 disabled:opacity-50">{{ ocupado ? 'Guardando…' : 'Guardar recomendación' }}</button>
        </form>
        <article v-if="revision.funcion === 'revisor' && !revision.puede_recomendar && revision.recomendacion_propia" class="rounded-lg border p-3 text-sm space-y-2">
            <h3 class="font-medium">Tu recomendación registrada · {{ revision.recomendacion_propia.recomendacion === 'favorable' ? 'Favorable' : 'Desfavorable' }}</h3>
            <p class="whitespace-pre-line break-words">{{ revision.recomendacion_propia.motivo }}</p>
        </article>
        <div v-if="revision.recomendaciones?.length" class="space-y-3"><h3 class="font-medium">Recomendaciones registradas</h3><article v-for="r in revision.recomendaciones" :key="r.id" class="rounded-lg border p-3 text-sm space-y-2"><p class="font-medium">{{ nombres[r.usuario_id] || 'Revisor de la convocatoria' }} · {{ r.recomendacion === 'favorable' ? 'Favorable' : 'Desfavorable' }} · Revisión {{ r.revision }}</p><p class="whitespace-pre-line break-words">{{ r.motivo }}</p></article></div>
    </section>
</template>
