<script setup>
import { onMounted, ref } from "vue";
import axios from "axios";

// Incidencia E6 (20-nov-2026): las altas y bajas de integrantes ya se auditaban, pero ningún historial las mostraba.
const props = defineProps({ organizacionId: String });
const datos = ref(null), cargando = ref(false), error = ref("");
const operaciones = { incorporacion: "Incorporación", eliminacion: "Baja" };
const estados = { activo: "Activo", inactivo: "Inactivo" };
async function cargar(page = 1) {
    cargando.value = true;
    error.value = "";
    try {
        datos.value = (await axios.get("/api/v1/comunidad/portal/organizaciones/miembros/historial", { headers: { "X-Organization-Id": props.organizacionId }, params: { page } })).data;
    } catch (e) {
        error.value = e.response?.status === 401 || e.response?.status === 419 ? "Tu sesión terminó. Recarga la página e inicia sesión nuevamente."
            : (e.response?.status < 500 && e.response?.data?.message) || "No se pudo cargar el historial. Intenta nuevamente.";
    } finally { cargando.value = false; }
}
const fecha = f => (f ? new Date(f).toLocaleString("es-MX", { dateStyle: "medium", timeStyle: "short" }) : "");
onMounted(() => cargar());
</script>

<template>
    <section class="rounded-lg border bg-white p-4 space-y-3 break-words" aria-labelledby="historial-integrantes-titulo" data-historial-integrantes>
        <div class="flex flex-wrap justify-between gap-2 items-start">
            <div><h2 id="historial-integrantes-titulo" class="text-lg font-bold text-gray-900">Historial de integrantes</h2><p class="text-sm text-gray-600 mt-1">Altas y bajas confirmadas, con quién las hizo y cuándo.</p></div>
            <button type="button" :disabled="cargando" class="text-blue-900 font-semibold disabled:opacity-50" @click="cargar(datos?.page || 1)">Actualizar historial</button>
        </div>
        <p v-if="error" class="text-sm text-red-700" role="alert">{{ error }}</p>
        <p v-if="datos && !datos.movimientos.length" class="text-sm text-gray-600">Todavía no hay altas ni bajas registradas.</p>
        <ol v-if="datos?.movimientos.length" class="space-y-2">
            <li v-for="m in datos.movimientos" :key="m.id" class="rounded-lg bg-gray-50 p-3 text-sm space-y-1" :data-movimiento-integrante="m.operacion">
                <p class="font-semibold">{{ operaciones[m.operacion] }} · {{ m.integrante.nombre }}<span v-if="m.integrante.matricula" class="font-normal text-gray-600"> ({{ m.integrante.matricula }})</span></p>
                <p class="text-xs text-gray-600">{{ fecha(m.fecha) }} · por {{ m.realizado_por.nombre }} · {{ m.organizacion_nombre }}</p>
                <p class="text-xs text-gray-700">Estado: {{ m.estado_anterior ? estados[m.estado_anterior] || m.estado_anterior : "Sin registro previo" }} → {{ estados[m.estado_posterior] || m.estado_posterior }}</p>
            </li>
        </ol>
        <nav v-if="datos?.last_page > 1" class="flex gap-4 text-sm" aria-label="Páginas del historial de integrantes">
            <button :disabled="cargando || datos.page === 1" class="text-blue-900 disabled:opacity-50" @click="cargar(datos.page - 1)">Anterior</button>
            <span>{{ datos.page }} de {{ datos.last_page }}</span>
            <button :disabled="cargando || datos.page === datos.last_page" class="text-blue-900 disabled:opacity-50" @click="cargar(datos.page + 1)">Siguiente</button>
        </nav>
    </section>
</template>
