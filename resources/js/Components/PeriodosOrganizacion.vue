<script setup>
import { nextTick, onMounted, ref, watch } from "vue";
import axios from "axios";

const props = defineProps({ organizacionId: String, miembros: { type: Array, default: () => [] } });
const emit = defineEmits(["actualizados"]);
const datos = ref(null), detalle = ref(null), historial = ref(null), situacion = ref("todos");
const cargando = ref(false), ocupado = ref(false), error = ref(""), aviso = ref("");
const editor = ref(null), form = ref({}), clave = ref(crypto.randomUUID()), nombreCampo = ref(null);
const cancelacion = ref(null), motivo = ref(""), motivoCampo = ref(null);
const base = "/api/organizaciones/periodos";
const opciones = () => ({ headers: { "X-Organization-Id": props.organizacionId } });
const nombre = id => props.miembros.find(m => m.usuario_id === id)?.nombre || "Cuenta no disponible";
const cargo = slug => ({ presidencia: "Presidencia", vicepresidencia: "Vicepresidencia", secretaria: "Secretaría", tesoreria: "Tesorería", comunicacion: "Comunicación", responsable_becas: "Responsable de becas" })[slug] || slug;
const movimientos = { cargo_asignado: "Asignación o cambio de cargo", cargo_retirado: "Retiro de cargo", cuenta_baja_responsabilidad: "Baja de la cuenta", presidencia_recuperada: "Recuperación de presidencia" };
const estados = { programado: "Programado", vigente: "Vigente", finalizado: "Finalizado", cancelado: "Cancelado" };
watch(form, () => { clave.value = crypto.randomUUID(); }, { deep: true });
function fallar(e) {
    error.value = e.response?.status === 401 || e.response?.status === 419 ? "Tu sesión terminó. Recarga la página e inicia sesión nuevamente."
        : Object.values(e.response?.data?.errors ?? {}).flat().join(" ") || (e.response?.status < 500 && e.response?.data?.message) || "No se pudo completar la solicitud. Intenta nuevamente.";
}
async function cargar(page = 1) {
    cargando.value = true;
    try { datos.value = (await axios.get(base, { ...opciones(), params: { situacion: situacion.value, page } })).data; }
    catch (e) { fallar(e); }
    finally { cargando.value = false; }
}
async function ver(p, page = 1) {
    cargando.value = true;
    try { detalle.value = (await axios.get(`${base}/${p.id}`, { ...opciones(), params: { page } })).data; }
    catch (e) { fallar(e); }
    finally { cargando.value = false; }
}
async function verHistorial(p, page = 1) {
    cargando.value = true;
    try { historial.value = { ...(await axios.get(`${base}/${p.id}/historial`, { ...opciones(), params: { page } })).data, periodo: p }; }
    catch (e) { fallar(e); }
    finally { cargando.value = false; }
}
function abrir(p = null) {
    editor.value = p || "nuevo"; error.value = ""; cancelacion.value = null;
    form.value = p ? { nombre: p.nombre, descripcion: p.descripcion, fecha_inicio: p.fecha_inicio, fecha_fin: p.fecha_fin, version: p.version }
        : { nombre: "", descripcion: "", fecha_inicio: "", fecha_fin: "" };
    nextTick(() => nombreCampo.value?.focus());
}
async function ejecutar(fn) {
    if (ocupado.value) return;
    ocupado.value = true; error.value = ""; aviso.value = "";
    try { const { data } = await fn(); aviso.value = data.message; emit("actualizados"); if (historial.value?.periodo.id === data.periodo.id) historial.value.periodo = data.periodo; editor.value = null; cancelacion.value = null; await cargar(datos.value?.page || 1); if (detalle.value?.periodo.id === data.periodo.id) await ver(data.periodo); }
    catch (e) { fallar(e); }
    finally { ocupado.value = false; }
}
function guardar() { ejecutar(() => editor.value === "nuevo" ? axios.post(base, { ...form.value, clave_solicitud: clave.value }, opciones()) : axios.put(`${base}/${editor.value.id}`, form.value, opciones())); }
function prepararCancelacion(p) { cancelacion.value = p; editor.value = null; motivo.value = ""; error.value = ""; nextTick(() => motivoCampo.value?.focus()); }
function cancelar() { ejecutar(() => axios.post(`${base}/${cancelacion.value.id}/cancelar`, { version: cancelacion.value.version, motivo: motivo.value }, opciones())); }
function filtrar() { historial.value = null; detalle.value = null; editor.value = null; cancelacion.value = null; error.value = ""; cargar(); }
onMounted(() => cargar());
</script>

<template>
    <section class="rounded-xl border bg-white p-5 space-y-5" aria-labelledby="periodos-titulo" :aria-busy="cargando || ocupado">
        <div class="flex flex-wrap justify-between gap-3 items-start"><div><h2 id="periodos-titulo" class="text-lg font-bold text-gray-900">Periodos de la organización</h2><p class="text-sm text-gray-600 mt-1">Fechas e historial de cada mesa directiva.</p></div><button v-if="datos?.puede_editar" type="button" :disabled="ocupado || cargando" class="rounded-lg bg-blue-900 text-white px-4 py-2 disabled:opacity-50" @click="abrir()">Registrar periodo</button></div>
        <p v-if="error" role="alert" class="rounded-lg bg-red-50 text-red-800 p-3">{{ error }}</p>
        <p v-if="aviso" role="status" class="rounded-lg bg-green-50 text-green-800 p-3">{{ aviso }}</p>
        <div class="flex flex-wrap gap-3 items-center"><label for="periodos-situacion" class="text-sm font-medium">Mostrar periodos</label><select id="periodos-situacion" v-model="situacion" :disabled="ocupado || cargando" class="rounded-lg border-gray-300" @change="filtrar"><option value="todos">Todos los periodos</option><option v-for="(etiqueta, valor) in estados" :key="valor" :value="valor">{{ etiqueta }}</option></select><button type="button" :disabled="ocupado || cargando" class="text-blue-900 font-semibold disabled:opacity-50" @click="error = ''; cargar(datos?.page || 1); detalle && ver(detalle.periodo, detalle.page); historial && verHistorial(historial.periodo, historial.page)">Actualizar periodos</button></div>
        <form v-if="editor" class="rounded-lg border p-4 space-y-4" @submit.prevent="guardar">
            <h3 class="font-semibold">{{ editor === 'nuevo' ? 'Nuevo periodo' : 'Editar periodo' }}</h3>
            <fieldset :disabled="ocupado" class="space-y-4">
                <div><label for="periodo-nombre" class="block text-sm font-medium mb-1">Nombre del periodo</label><input id="periodo-nombre" ref="nombreCampo" v-model="form.nombre" required minlength="3" maxlength="150" class="w-full rounded-lg border-gray-300" /></div>
                <div><label for="periodo-descripcion" class="block text-sm font-medium mb-1">Descripción del periodo (opcional)</label><textarea id="periodo-descripcion" v-model="form.descripcion" maxlength="1000" rows="2" class="w-full rounded-lg border-gray-300"></textarea></div>
                <div class="grid sm:grid-cols-2 gap-4"><div><label for="periodo-inicio" class="block text-sm font-medium mb-1">Primer día del periodo</label><input id="periodo-inicio" v-model="form.fecha_inicio" type="date" required :disabled="editor.en_uso" class="w-full min-w-0 rounded-lg border-gray-300 disabled:bg-gray-100" /></div><div><label for="periodo-fin" class="block text-sm font-medium mb-1">Último día del periodo</label><input id="periodo-fin" v-model="form.fecha_fin" type="date" required :min="form.fecha_inicio" :disabled="editor.en_uso" class="w-full min-w-0 rounded-lg border-gray-300 disabled:bg-gray-100" /></div></div>
                <p class="text-xs text-gray-600">El último día está incluido. Los periodos de la misma organización no se superponen.</p><p v-if="editor.en_uso" class="text-xs text-gray-600">Las fechas se conservan porque el periodo ya tiene cargos o historial vinculado.</p>
                <div class="flex flex-wrap gap-3"><button class="rounded-lg bg-blue-900 text-white px-4 py-2">{{ ocupado ? 'Guardando…' : 'Guardar periodo' }}</button><button type="button" class="text-gray-700" @click="editor = null">Cancelar</button></div>
            </fieldset>
        </form>
        <form v-if="cancelacion" class="rounded-lg border p-4 space-y-3" @submit.prevent="cancelar"><h3 class="font-semibold break-words">Cancelar · {{ cancelacion.nombre }}</h3><p class="text-sm text-gray-600">El registro y el motivo se conservarán. Solo se cancelan periodos que nunca tuvieron cargos vinculados.</p><label for="periodo-motivo" class="block text-sm font-medium">Motivo de cancelación del periodo</label><textarea id="periodo-motivo" ref="motivoCampo" v-model="motivo" :disabled="ocupado" required minlength="10" maxlength="1000" rows="2" class="w-full rounded-lg border-gray-300"></textarea><div class="flex flex-wrap gap-3"><button :disabled="ocupado" class="rounded-lg bg-red-700 text-white px-4 py-2 disabled:opacity-50">Confirmar cancelación del periodo</button><button type="button" :disabled="ocupado" class="text-gray-700" @click="cancelacion = null">Volver</button></div></form>
        <p v-if="cargando" role="status" class="text-sm text-gray-600">Cargando periodos…</p>
        <template v-if="datos">
            <p v-if="!datos.periodos.length && !cargando" class="text-sm text-gray-600 py-3">No hay periodos en esta sección.</p>
            <article v-for="p in datos.periodos" :key="p.id" class="rounded-lg border p-4 space-y-2 break-words">
                <div class="flex flex-wrap justify-between gap-2"><h3 class="font-semibold">{{ p.nombre }}</h3><span class="text-sm text-gray-600">{{ estados[p.situacion] }}</span></div><p class="text-xs text-gray-600">{{ p.fecha_inicio }} → {{ p.fecha_fin }}</p><p v-if="p.descripcion" class="text-sm whitespace-pre-line">{{ p.descripcion }}</p><p v-if="p.motivo_cancelacion" class="text-sm whitespace-pre-line">Cancelación: {{ p.motivo_cancelacion }}</p><p v-if="p.en_uso" class="text-xs text-gray-600">Con cargos o historial vinculado.</p>
                <div class="flex flex-wrap gap-4 text-sm font-semibold"><button type="button" :disabled="ocupado || cargando" class="text-blue-900 disabled:opacity-50" @click="ver(p)">Ver mesa directiva</button><button v-if="p.en_uso" type="button" :disabled="ocupado || cargando" class="text-blue-900 disabled:opacity-50" @click="verHistorial(p)">Ver cambios de cargos</button><template v-if="datos.puede_editar && p.estado === 'registrado'"><button type="button" :disabled="ocupado || cargando" class="text-blue-900 disabled:opacity-50" @click="abrir(p)">Editar periodo</button><button v-if="!p.en_uso" type="button" :disabled="ocupado || cargando" class="text-red-700 disabled:opacity-50" @click="prepararCancelacion(p)">Cancelar periodo</button></template></div>
            </article>
            <nav v-if="datos.last_page > 1" class="flex gap-4 text-sm" aria-label="Páginas de periodos"><button :disabled="ocupado || cargando || datos.page === 1" class="text-blue-900 disabled:opacity-50" @click="cargar(datos.page - 1)">Anterior</button><span>{{ datos.page }} de {{ datos.last_page }}</span><button :disabled="ocupado || cargando || datos.page === datos.last_page" class="text-blue-900 disabled:opacity-50" @click="cargar(datos.page + 1)">Siguiente</button></nav>
        </template>
        <div v-if="detalle" class="rounded-lg border p-4 space-y-3 break-words" aria-labelledby="periodo-mesa-titulo"><div class="flex flex-wrap justify-between gap-2"><h3 id="periodo-mesa-titulo" class="font-semibold">Mesa directiva · {{ detalle.periodo.nombre }}</h3><button type="button" :disabled="ocupado" class="text-sm text-gray-700" @click="detalle = null">Cerrar mesa directiva</button></div><p v-if="!detalle.cargos.length" class="text-sm text-gray-600">No hay cargos vinculados a este periodo.</p><ol class="space-y-3"><li v-for="c in detalle.cargos" :key="c.id" class="rounded-lg bg-gray-50 p-3 space-y-1 text-sm"><p class="font-semibold">{{ cargo(c.slug_rol) }} · {{ nombre(c.usuario_id) }}</p><p class="text-gray-600">{{ c.eliminado_en ? 'Cargo retirado' : c.vigente ? 'Cargo vigente' : 'Fuera de vigencia' }}</p><p class="text-xs text-gray-600">{{ c.fecha_inicio?.slice(0, 10) }} → {{ c.fecha_fin?.slice(0, 10) }}</p></li></ol><nav v-if="detalle.last_page > 1" class="flex gap-4 text-sm" aria-label="Páginas de cargos del periodo"><button :disabled="ocupado || cargando || detalle.page === 1" class="text-blue-900 disabled:opacity-50" @click="ver(detalle.periodo, detalle.page - 1)">Anterior</button><span>{{ detalle.page }} de {{ detalle.last_page }}</span><button :disabled="ocupado || cargando || detalle.page === detalle.last_page" class="text-blue-900 disabled:opacity-50" @click="ver(detalle.periodo, detalle.page + 1)">Siguiente</button></nav></div>
        <div v-if="historial" class="rounded-lg border p-4 space-y-3 break-words" aria-labelledby="periodo-historial-titulo">
            <div class="flex flex-wrap justify-between gap-2"><h3 id="periodo-historial-titulo" class="font-semibold">Cambios de cargos · {{ historial.periodo.nombre }}</h3><button type="button" :disabled="ocupado" class="text-sm text-gray-700" @click="historial = null">Cerrar cambios de cargos</button></div>
            <p v-if="!historial.movimientos.length" class="text-sm text-gray-600">No hay cambios de cargos que mostrar.</p>
            <ol class="space-y-3"><li v-for="m in historial.movimientos" :key="m.id" class="rounded-lg bg-gray-50 p-3 space-y-1 text-sm"><p class="font-semibold">{{ movimientos[m.accion] || m.accion }}</p><p class="text-xs text-gray-600">{{ m.creado_en?.slice(0, 16).replace('T', ' ') }} UTC · {{ m.usuario_id ? nombre(m.usuario_id) : 'Mantenimiento por consola' }}</p><p v-if="m.anterior.usuario_id">Anterior: {{ cargo(m.anterior.slug_rol) }} · {{ nombre(m.anterior.usuario_id) }} · {{ m.anterior.fecha_inicio?.slice(0, 10) }} → {{ m.anterior.fecha_fin?.slice(0, 10) || 'Sin fecha de término' }}</p><p v-if="m.actual.usuario_id">Posterior: {{ cargo(m.actual.slug_rol) }} · {{ nombre(m.actual.usuario_id) }} · {{ m.actual.fecha_inicio?.slice(0, 10) }} → {{ m.actual.fecha_fin?.slice(0, 10) || 'Sin fecha de término' }}{{ m.actual.eliminado_en ? ' · Retirado' : '' }}</p></li></ol>
            <nav v-if="historial.last_page > 1" class="flex gap-4 text-sm" aria-label="Páginas de cambios de cargos"><button :disabled="ocupado || cargando || historial.page === 1" class="text-blue-900 disabled:opacity-50" @click="verHistorial(historial.periodo, historial.page - 1)">Anterior</button><span>{{ historial.page }} de {{ historial.last_page }}</span><button :disabled="ocupado || cargando || historial.page === historial.last_page" class="text-blue-900 disabled:opacity-50" @click="verHistorial(historial.periodo, historial.page + 1)">Siguiente</button></nav>
        </div>
    </section>
</template>
