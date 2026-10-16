<script setup>
import { nextTick, onMounted, ref, watch } from "vue";
import axios from "axios";

const props = defineProps({ organizacionId: String, miembros: { type: Array, default: () => [] } });
const datos = ref(null), detalle = ref(null), estado = ref("activa"), historial = ref(false);
const cargando = ref(false), ocupado = ref(false), error = ref(""), aviso = ref("");
const editor = ref(null), form = ref({}), clave = ref(crypto.randomUUID());
const participante = ref({ usuario_id: "", funcion: "integrante" }), claveParticipante = ref(crypto.randomUUID());
const accion = ref(null), motivo = ref(""), nombreCampo = ref(null), motivoCampo = ref(null);
const opciones = () => ({ headers: { "X-Organization-Id": props.organizacionId } });
const nombre = (id) => props.miembros.find(m => m.usuario_id === id)?.nombre || "Integrante no disponible";
watch(form, () => { clave.value = crypto.randomUUID(); }, { deep: true });
watch(participante, () => { claveParticipante.value = crypto.randomUUID(); }, { deep: true });
function fallar(e) { error.value = Object.values(e.response?.data?.errors ?? {}).flat().join(" ") || e.response?.data?.message || "No se pudo conectar. Intenta nuevamente."; }
async function cargar(page = 1) {
    cargando.value = true;
    try { datos.value = (await axios.get("/api/organizaciones/comisiones", { ...opciones(), params: { estado: estado.value, page } })).data; }
    catch (e) { fallar(e); }
    finally { cargando.value = false; }
}
async function ver(c, page = 1) {
    try { detalle.value = (await axios.get(`/api/organizaciones/comisiones/${c.id}`, { ...opciones(), params: { historial: historial.value ? 1 : 0, page } })).data; }
    catch (e) { fallar(e); }
}
function abrir(c = null) {
    editor.value = c || "nueva";
    form.value = c ? { nombre: c.nombre, proposito: c.proposito, fecha_inicio: c.fecha_inicio, fecha_fin: c.fecha_fin || "", version: c.version } : { nombre: "", proposito: "", fecha_inicio: "", fecha_fin: "" };
    error.value = "";
    nextTick(() => nombreCampo.value?.focus());
}
async function ejecutar(fn) {
    if (ocupado.value) return;
    ocupado.value = true; error.value = ""; aviso.value = "";
    try { await fn(); }
    catch (e) { fallar(e); }
    finally { ocupado.value = false; }
}
function guardar() {
    ejecutar(async () => {
        const body = { ...form.value, fecha_fin: form.value.fecha_fin || null, clave_solicitud: clave.value };
        const res = editor.value === "nueva" ? await axios.post("/api/organizaciones/comisiones", body, opciones()) : await axios.put(`/api/organizaciones/comisiones/${editor.value.id}`, body, opciones());
        editor.value = null; aviso.value = res.data.message;
        await cargar(datos.value?.page || 1);
        await ver(res.data.comision);
    });
}
function asignar() {
    ejecutar(async () => {
        const res = await axios.post(`/api/organizaciones/comisiones/${detalle.value.comision.id}/integrantes`, { ...participante.value, clave_solicitud: claveParticipante.value }, opciones());
        participante.value = { usuario_id: "", funcion: "integrante" };
        aviso.value = res.data.message;
        await ver(detalle.value.comision);
    });
}
function preparar(tipo, registro = null) {
    accion.value = { tipo, registro }; motivo.value = "";
    nextTick(() => motivoCampo.value?.focus());
}
function confirmar() {
    ejecutar(async () => {
        const c = detalle.value.comision;
        const url = accion.value.tipo === "cierre" ? `/api/organizaciones/comisiones/${c.id}/cerrar` : `/api/organizaciones/comisiones/${c.id}/integrantes/${accion.value.registro.id}/retirar`;
        const res = await axios.post(url, { motivo: motivo.value, version: c.version }, opciones());
        aviso.value = res.data.message; accion.value = null;
        if (res.data.comision?.estado === "cerrada") historial.value = true;
        await ver(c);
        await cargar(datos.value?.page || 1);
    });
}
async function filtrar() { detalle.value = null; editor.value = null; accion.value = null; error.value = ""; await cargar(); }
onMounted(() => cargar());
</script>

<template>
    <section class="rounded-xl border bg-white p-5 space-y-5" aria-labelledby="comisiones-titulo" :aria-busy="cargando || ocupado">
        <div class="flex flex-wrap justify-between gap-3 items-start">
            <div><h2 id="comisiones-titulo" class="text-lg font-bold text-gray-900">Comisiones del Consejo</h2><p class="text-sm text-gray-600 mt-1">Propósito, periodo de trabajo, coordinación e integrantes.</p></div>
            <button v-if="datos?.puede_editar" type="button" :disabled="ocupado" class="rounded-lg bg-blue-900 text-white px-4 py-2 disabled:opacity-50" @click="abrir()">Nueva comisión</button>
        </div>
        <p v-if="error" role="alert" class="rounded-lg bg-red-50 text-red-800 p-3">{{ error }}</p>
        <p v-if="aviso" role="status" class="rounded-lg bg-green-50 text-green-800 p-3">{{ aviso }}</p>
        <div class="flex flex-wrap gap-3 items-center">
            <label for="comisiones-estado" class="text-sm font-medium">Mostrar</label><select id="comisiones-estado" v-model="estado" :disabled="ocupado || cargando" class="rounded-lg border-gray-300" @change="filtrar"><option value="activa">Comisiones abiertas</option><option value="cerrada">Comisiones cerradas</option></select>
            <button type="button" :disabled="ocupado || cargando" class="text-blue-900 font-semibold disabled:opacity-50" @click="cargar(datos?.page || 1); detalle && ver(detalle.comision, detalle.page)">Actualizar</button>
        </div>
        <form v-if="editor" class="rounded-lg border p-4 space-y-4" @submit.prevent="guardar">
            <h3 class="font-semibold">{{ editor === "nueva" ? "Crear comisión" : "Editar comisión" }}</h3>
            <fieldset :disabled="ocupado" class="space-y-4">
                <div><label for="comision-nombre" class="block text-sm font-medium mb-1">Nombre de la comisión</label><input id="comision-nombre" ref="nombreCampo" v-model="form.nombre" required minlength="3" maxlength="150" class="w-full rounded-lg border-gray-300" /></div>
                <div><label for="comision-proposito" class="block text-sm font-medium mb-1">Propósito</label><textarea id="comision-proposito" v-model="form.proposito" required minlength="10" maxlength="2000" rows="3" class="w-full rounded-lg border-gray-300"></textarea></div>
                <div class="grid sm:grid-cols-2 gap-4"><div><label for="comision-inicio" class="block text-sm font-medium mb-1">Inicio del periodo</label><input id="comision-inicio" v-model="form.fecha_inicio" required type="date" class="w-full min-w-0 rounded-lg border-gray-300" /></div><div><label for="comision-fin" class="block text-sm font-medium mb-1">Último día del periodo (opcional)</label><input id="comision-fin" v-model="form.fecha_fin" type="date" :min="form.fecha_inicio" class="w-full min-w-0 rounded-lg border-gray-300" /></div></div>
                <p class="text-xs text-gray-600">Fechas de Ciudad de México. La participación queda vigente dentro de este periodo.</p>
                <div class="flex gap-3"><button class="rounded-lg bg-blue-900 text-white px-4 py-2">{{ ocupado ? "Guardando…" : "Guardar comisión" }}</button><button type="button" class="text-gray-700" @click="editor = null">Cancelar</button></div>
            </fieldset>
        </form>
        <p v-if="cargando" role="status" class="text-sm text-gray-600">Cargando comisiones…</p>
        <div v-if="datos" class="space-y-3">
            <p v-if="!datos.comisiones.length && !cargando" class="text-sm text-gray-600 py-3">No hay comisiones en esta sección.</p>
            <article v-for="c in datos.comisiones" :key="c.id" class="rounded-lg border p-4 space-y-2 break-words">
                <div class="flex justify-between flex-wrap gap-2"><h3 class="font-semibold">{{ c.nombre }}</h3><span class="text-sm">{{ c.situacion }}</span></div>
                <p class="text-sm whitespace-pre-line">{{ c.proposito }}</p><p class="text-xs text-gray-600">{{ c.fecha_inicio }} → {{ c.fecha_fin || "Sin fecha de fin" }}</p>
                <div class="flex flex-wrap gap-4"><button type="button" :disabled="ocupado" class="text-sm font-semibold text-blue-900" @click="historial = c.estado === 'cerrada'; accion = null; ver(c)">Ver integrantes e historial</button><button v-if="datos.puede_editar && c.estado === 'activa'" type="button" :disabled="ocupado" class="text-sm font-semibold text-blue-900" @click="abrir(c)">Editar comisión</button></div>
            </article>
            <nav v-if="datos.last_page > 1" class="flex gap-4 text-sm" aria-label="Páginas de comisiones"><button :disabled="cargando || ocupado || datos.page === 1" class="text-blue-900 disabled:opacity-50" @click="cargar(datos.page - 1)">Anterior</button><span>{{ datos.page }} de {{ datos.last_page }}</span><button :disabled="cargando || ocupado || datos.page === datos.last_page" class="text-blue-900 disabled:opacity-50" @click="cargar(datos.page + 1)">Siguiente</button></nav>
        </div>
        <div v-if="detalle" class="rounded-lg border p-4 space-y-4" aria-labelledby="comision-detalle-titulo">
            <div class="flex flex-wrap justify-between gap-2"><h3 id="comision-detalle-titulo" class="font-semibold">Integrantes · {{ detalle.comision.nombre }}</h3><button type="button" :disabled="ocupado" class="text-sm text-gray-700" @click="detalle = null; accion = null">Cerrar detalle</button></div>
            <p v-if="detalle.comision.motivo_cierre" class="text-sm text-gray-600">Cerrada: {{ detalle.comision.motivo_cierre }}</p>
            <label class="flex gap-2 items-center text-sm"><input v-model="historial" type="checkbox" :disabled="ocupado" class="rounded border-gray-300" @change="ver(detalle.comision)" />Incluir participaciones retiradas</label>
            <ol class="space-y-3"><li v-for="a in detalle.integrantes" :key="a.id" class="rounded-lg bg-gray-50 p-3 text-sm space-y-1"><p class="font-semibold">{{ nombre(a.usuario_id) }} · {{ a.funcion === "coordinacion" ? "Coordinación" : "Integrante" }}</p><p class="text-gray-600">{{ a.retirado_en ? "Participación retirada" : a.vigente ? "Participación vigente" : "Fuera de vigencia" }}</p><p v-if="a.motivo_retiro" class="whitespace-pre-line">{{ a.motivo_retiro }}</p><button v-if="datos.puede_editar && !a.retirado_en" type="button" :disabled="ocupado" class="text-red-700 font-semibold" @click="preparar('retiro', a)">Retirar participación</button></li></ol>
            <p v-if="!detalle.integrantes.length" class="text-sm text-gray-600">No hay participaciones que mostrar.</p>
            <nav v-if="detalle.last_page > 1" class="flex gap-4 text-sm" aria-label="Páginas de integrantes de comisión"><button :disabled="ocupado || detalle.page === 1" class="text-blue-900 disabled:opacity-50" @click="ver(detalle.comision, detalle.page - 1)">Anterior</button><span>{{ detalle.page }} de {{ detalle.last_page }}</span><button :disabled="ocupado || detalle.page === detalle.last_page" class="text-blue-900 disabled:opacity-50" @click="ver(detalle.comision, detalle.page + 1)">Siguiente</button></nav>
            <form v-if="datos.puede_editar && detalle.comision.situacion === 'vigente'" class="space-y-3 border-t pt-4" @submit.prevent="asignar"><h4 class="font-semibold text-sm">Añadir integrante a la comisión</h4><fieldset :disabled="ocupado" class="space-y-3"><label for="comision-integrante" class="block text-sm font-medium">Integrante del Consejo</label><select id="comision-integrante" v-model="participante.usuario_id" required class="w-full rounded-lg border-gray-300"><option value="" disabled>Selecciona un integrante activo</option><option v-for="m in miembros" :key="m.id" :value="m.usuario_id">{{ m.nombre }} · {{ m.matricula }}</option></select><label for="comision-funcion" class="block text-sm font-medium">Función en la comisión</label><select id="comision-funcion" v-model="participante.funcion" class="w-full rounded-lg border-gray-300"><option value="integrante">Integrante</option><option value="coordinacion">Coordinación</option></select><p class="text-xs text-gray-600">Cada comisión admite una coordinación. Participar o coordinar no concede permisos adicionales en Campus Digital.</p><button class="rounded-lg bg-blue-900 text-white px-4 py-2">Guardar participación</button></fieldset></form>
            <button v-if="datos.puede_editar && detalle.comision.estado === 'activa' && !accion" type="button" :disabled="ocupado" class="font-semibold text-sm text-red-700" @click="preparar('cierre')">Cerrar comisión</button>
            <form v-if="accion" class="space-y-3 border-t pt-4" @submit.prevent="confirmar"><label for="comision-motivo" class="block text-sm font-medium">{{ accion.tipo === "cierre" ? "Motivo de cierre de la comisión" : "Motivo del retiro de participación" }}</label><textarea id="comision-motivo" ref="motivoCampo" v-model="motivo" required minlength="10" maxlength="1000" rows="2" :disabled="ocupado" class="w-full rounded-lg border-gray-300"></textarea><div class="flex gap-3"><button :disabled="ocupado" class="rounded-lg bg-red-700 text-white px-4 py-2 disabled:opacity-50">{{ accion.tipo === "cierre" ? "Confirmar cierre de comisión" : "Confirmar retiro" }}</button><button type="button" :disabled="ocupado" class="text-gray-700" @click="accion = null">Cancelar</button></div></form>
        </div>
    </section>
</template>
