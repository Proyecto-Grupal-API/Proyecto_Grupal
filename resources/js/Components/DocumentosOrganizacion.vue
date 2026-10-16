<script setup>
import { nextTick, onMounted, ref, watch } from "vue";
import axios from "axios";

const props = defineProps({ organizacionId: String });
const datos = ref(null), estado = ref("activo"), cargando = ref(false), ocupado = ref(false);
const error = ref(""), aviso = ref(""), editor = ref(null), form = ref({}), archivo = ref(null);
const clave = ref(crypto.randomUUID()), historial = ref(null), tituloCampo = ref(null);
const opciones = () => ({ headers: { "X-Organization-Id": props.organizacionId } });
const base = "/api/organizaciones/documentos";
const tamano = bytes => bytes < 1024 ? `${bytes} B` : `${(bytes / 1024).toFixed(0)} KB`;
const hoy = () => new Intl.DateTimeFormat("sv-SE", { timeZone: "America/Mexico_City" }).format(new Date());
watch([form, archivo], () => { clave.value = crypto.randomUUID(); }, { deep: true });
function fallar(e) {
    error.value = e.response?.status === 401 || e.response?.status === 419
        ? "Tu sesión terminó. Recarga la página e inicia sesión nuevamente."
        : Object.values(e.response?.data?.errors ?? {}).flat().join(" ") || (e.response?.status < 500 && e.response?.data?.message) || "No se pudo completar la solicitud. Intenta nuevamente.";
}
async function cargar(page = 1) {
    cargando.value = true;
    try { datos.value = (await axios.get(base, { ...opciones(), params: { estado: estado.value, page } })).data; }
    catch (e) { fallar(e); }
    finally { cargando.value = false; }
}
function abrir(modo, doc = null) {
    editor.value = { modo, doc }; archivo.value = null; error.value = ""; aviso.value = "";
    form.value = doc ? { titulo: doc.titulo, categoria: doc.categoria, descripcion: doc.descripcion, fecha_documento: doc.fecha_documento, visibilidad: doc.visibilidad, version: doc.version, motivo: "" }
        : { titulo: "", categoria: "acta", descripcion: "", fecha_documento: hoy(), visibilidad: "presidencia" };
    nextTick(() => tituloCampo.value?.focus());
}
function seleccionar(e) {
    archivo.value = e.target.files[0] || null;
    if (archivo.value?.size > 5 * 1024 * 1024) { error.value = "El archivo debe pesar como máximo 5 MB."; archivo.value = null; e.target.value = ""; }
    else error.value = "";
}
async function guardar() {
    if (ocupado.value) return;
    ocupado.value = true; error.value = ""; aviso.value = "";
    try {
        const { modo, doc } = editor.value;
        let res;
        if (modo === "editar") res = await axios.put(`${base}/${doc.id}`, form.value, opciones());
        else if (modo === "retirar") res = await axios.post(`${base}/${doc.id}/retirar`, { version: form.value.version, motivo: form.value.motivo }, opciones());
        else {
            if (!archivo.value) { error.value = "Selecciona un archivo PDF, JPG o PNG de hasta 5 MB."; return; }
            const body = new FormData();
            const campos = modo === "version" ? { version: form.value.version, motivo: form.value.motivo } : form.value;
            Object.entries({ ...campos, clave_solicitud: clave.value }).forEach(([k, v]) => body.append(k, v ?? ""));
            body.append("archivo", archivo.value);
            res = await axios.post(modo === "version" ? `${base}/${doc.id}/versiones` : base, body, opciones());
        }
        editor.value = null; archivo.value = null; aviso.value = res.data.message;
        await cargar(datos.value?.page || 1);
        if (historial.value?.doc.id === res.data.documento.id) await verVersiones(res.data.documento);
    } catch (e) { fallar(e); }
    finally { ocupado.value = false; }
}
async function verVersiones(doc, page = 1) {
    cargando.value = true;
    try { historial.value = { ...(await axios.get(`${base}/${doc.id}/versiones`, { ...opciones(), params: { page } })).data, doc }; }
    catch (e) { fallar(e); }
    finally { cargando.value = false; }
}
function filtrar() { editor.value = null; historial.value = null; error.value = ""; cargar(); }
onMounted(() => cargar());
</script>

<template>
    <section class="rounded-xl border bg-white p-5 space-y-5" aria-labelledby="documentos-titulo" :aria-busy="cargando || ocupado">
        <div class="flex flex-wrap justify-between gap-3 items-start">
            <div><h2 id="documentos-titulo" class="text-lg font-bold text-gray-900">Documentos de la organización</h2><p class="text-sm text-gray-600 mt-1">Actas, estatutos, oficios y constancias con historial de versiones.</p></div>
            <button v-if="datos?.puede_editar" type="button" :disabled="ocupado || cargando" class="rounded-lg bg-blue-900 text-white px-4 py-2 disabled:opacity-50" @click="abrir('nuevo')">Añadir documento</button>
        </div>
        <p v-if="error" role="alert" class="rounded-lg bg-red-50 text-red-800 p-3">{{ error }}</p>
        <p v-if="aviso" role="status" class="rounded-lg bg-green-50 text-green-800 p-3">{{ aviso }}</p>
        <div class="flex flex-wrap gap-3 items-center">
            <template v-if="datos?.puede_editar"><label for="documentos-estado" class="text-sm font-medium">Mostrar</label><select id="documentos-estado" v-model="estado" :disabled="ocupado || cargando" class="rounded-lg border-gray-300" @change="filtrar"><option value="activo">Documentos activos</option><option value="retirado">Documentos retirados</option></select></template>
            <button type="button" :disabled="ocupado || cargando" class="text-blue-900 font-semibold disabled:opacity-50" @click="error = ''; cargar(datos?.page || 1); historial && verVersiones(historial.doc, historial.page)">Actualizar documentos</button>
        </div>
        <form v-if="editor" :key="`${editor.modo}-${editor.doc?.id || 'nuevo'}`" class="rounded-lg border p-4 space-y-4" @submit.prevent="guardar">
            <h3 class="font-semibold">{{ { nuevo: 'Nuevo documento', editar: 'Editar datos del documento', version: 'Nueva versión del archivo', retirar: 'Retirar documento' }[editor.modo] }}</h3>
            <fieldset :disabled="ocupado" class="space-y-4">
                <template v-if="['nuevo', 'editar'].includes(editor.modo)">
                    <div><label for="documento-titulo" class="block text-sm font-medium mb-1">Título del documento</label><input id="documento-titulo" ref="tituloCampo" v-model="form.titulo" required minlength="3" maxlength="150" class="w-full rounded-lg border-gray-300" /></div>
                    <div class="grid sm:grid-cols-2 gap-4"><div><label for="documento-categoria" class="block text-sm font-medium mb-1">Tipo de documento</label><select id="documento-categoria" v-model="form.categoria" required class="w-full rounded-lg border-gray-300"><option v-for="(nombre, valor) in datos.categorias" :key="valor" :value="valor">{{ nombre }}</option></select></div><div><label for="documento-fecha" class="block text-sm font-medium mb-1">Fecha del documento</label><input id="documento-fecha" v-model="form.fecha_documento" type="date" required class="w-full min-w-0 rounded-lg border-gray-300" /></div></div>
                    <div><label for="documento-descripcion" class="block text-sm font-medium mb-1">Descripción (opcional)</label><textarea id="documento-descripcion" v-model="form.descripcion" maxlength="1000" rows="2" class="w-full rounded-lg border-gray-300"></textarea></div>
                    <div><label for="documento-visibilidad" class="block text-sm font-medium mb-1">Quién puede consultar la versión actual</label><select id="documento-visibilidad" v-model="form.visibilidad" class="w-full rounded-lg border-gray-300"><option value="presidencia">Solo presidencia</option><option value="integrantes">Integrantes de la organización</option></select><p class="text-xs text-gray-600 mt-1">Las versiones anteriores y los documentos retirados quedan disponibles solo para presidencia.</p></div>
                </template>
                <p v-else class="text-sm font-medium break-words">{{ editor.doc.titulo }}</p>
                <div v-if="['nuevo', 'version'].includes(editor.modo)"><label for="documento-archivo" class="block text-sm font-medium mb-1">Archivo PDF, JPG o PNG</label><input id="documento-archivo" type="file" required accept=".pdf,.jpg,.jpeg,.png" class="block w-full min-w-0 text-sm" aria-describedby="documento-archivo-ayuda" @change="seleccionar" /><p id="documento-archivo-ayuda" class="text-xs text-gray-600 mt-2">Máximo 5 MB. Los archivos se almacenan de forma privada.</p></div>
                <p v-if="editor.modo === 'version'" class="text-sm text-gray-600">Se conserva el archivo anterior. {{ editor.doc.visibilidad === 'integrantes' ? 'Los integrantes podrán consultar la nueva versión.' : 'La nueva versión será visible solo para presidencia.' }}</p>
                <template v-if="['version', 'retirar'].includes(editor.modo)"><div><label for="documento-motivo" class="block text-sm font-medium mb-1">{{ editor.modo === 'version' ? 'Motivo de la nueva versión' : 'Motivo del retiro' }}</label><textarea id="documento-motivo" ref="tituloCampo" v-model="form.motivo" required minlength="10" maxlength="1000" rows="3" class="w-full rounded-lg border-gray-300"></textarea></div><p v-if="editor.modo === 'retirar'" class="text-sm text-gray-600">Dejará de aparecer para integrantes. Se conservan los archivos y su historial para presidencia.</p></template>
                <div class="flex flex-wrap gap-3"><button class="rounded-lg text-white px-4 py-2" :class="editor.modo === 'retirar' ? 'bg-red-700' : 'bg-blue-900'">{{ ocupado ? 'Guardando…' : { nuevo: 'Guardar documento', editar: 'Guardar datos del documento', version: 'Guardar nueva versión', retirar: 'Confirmar retiro del documento' }[editor.modo] }}</button><button type="button" class="text-gray-700" @click="editor = null">Cancelar</button></div>
            </fieldset>
        </form>
        <p v-if="cargando" role="status" class="text-sm text-gray-600">Cargando documentos…</p>
        <template v-if="datos">
            <p v-if="!datos.documentos.length && !cargando" class="text-sm text-gray-600 py-3">{{ datos.puede_editar ? 'No hay documentos en esta sección.' : 'Todavía no hay documentos compartidos contigo.' }}</p>
            <article v-for="d in datos.documentos" :key="d.id" class="rounded-lg border p-4 space-y-2 break-words">
                <div class="flex flex-wrap justify-between gap-2"><h3 class="font-semibold">{{ d.titulo }}</h3><span class="text-xs text-gray-600">{{ d.estado === 'retirado' ? 'Retirado' : d.visibilidad === 'integrantes' ? 'Compartido con integrantes' : 'Solo presidencia' }}</span></div>
                <p class="text-sm text-gray-600">{{ datos.categorias[d.categoria] }} · {{ d.fecha_documento }} · Versión {{ d.version_actual?.numero }}</p>
                <p v-if="d.descripcion" class="text-sm whitespace-pre-line">{{ d.descripcion }}</p>
                <p v-if="d.version_actual" class="text-xs text-gray-600">{{ d.version_actual.nombre }} · {{ tamano(d.version_actual.tamano) }}</p>
                <p v-if="d.motivo_retiro" class="text-sm whitespace-pre-line">Motivo del retiro: {{ d.motivo_retiro }}</p>
                <div class="flex flex-wrap gap-x-5 gap-y-3 text-sm font-semibold">
                    <a :href="`${base}/${d.id}/descargar`" class="text-blue-900">Descargar versión actual<span class="sr-only"> de {{ d.titulo }}</span></a>
                    <button type="button" :disabled="ocupado || cargando" class="text-blue-900 disabled:opacity-50" @click="verVersiones(d)">Ver versiones</button>
                    <template v-if="datos.puede_editar && d.estado === 'activo'"><button type="button" :disabled="ocupado || cargando" class="text-blue-900 disabled:opacity-50" @click="abrir('editar', d)">Editar datos</button><button type="button" :disabled="ocupado || cargando" class="text-blue-900 disabled:opacity-50" @click="abrir('version', d)">Cargar nueva versión</button><button type="button" :disabled="ocupado || cargando" class="text-red-700 disabled:opacity-50" @click="abrir('retirar', d)">Retirar documento</button></template>
                </div>
            </article>
            <nav v-if="datos.last_page > 1" class="flex gap-4 text-sm" aria-label="Páginas de documentos"><button :disabled="ocupado || cargando || datos.page === 1" class="text-blue-900 disabled:opacity-50" @click="cargar(datos.page - 1)">Anterior</button><span>{{ datos.page }} de {{ datos.last_page }}</span><button :disabled="ocupado || cargando || datos.page === datos.last_page" class="text-blue-900 disabled:opacity-50" @click="cargar(datos.page + 1)">Siguiente</button></nav>
        </template>
        <div v-if="historial" class="rounded-lg border p-4 space-y-3 break-words" aria-labelledby="documento-historial-titulo">
            <div class="flex flex-wrap justify-between gap-2"><h3 id="documento-historial-titulo" class="font-semibold">Versiones · {{ historial.doc.titulo }}</h3><button type="button" :disabled="ocupado" class="text-sm text-gray-700" @click="historial = null">Cerrar versiones</button></div>
            <ol class="space-y-3"><li v-for="v in historial.versiones" :key="v.id" class="rounded-lg bg-gray-50 p-3 text-sm space-y-1"><p class="font-semibold">Versión {{ v.numero }} · {{ v.nombre }}</p><p class="text-gray-600">{{ tamano(v.tamano) }} · {{ v.creado_en?.slice(0, 10) }}</p><p class="whitespace-pre-line">{{ v.motivo }}</p><a :href="`${base}/${historial.doc.id}/versiones/${v.id}/descargar`" class="text-blue-900 font-semibold">Descargar versión {{ v.numero }}</a></li></ol>
            <nav v-if="historial.last_page > 1" class="flex gap-4 text-sm" aria-label="Páginas de versiones documentales"><button :disabled="ocupado || cargando || historial.page === 1" class="text-blue-900 disabled:opacity-50" @click="verVersiones(historial.doc, historial.page - 1)">Anterior</button><span>{{ historial.page }} de {{ historial.last_page }}</span><button :disabled="ocupado || cargando || historial.page === historial.last_page" class="text-blue-900 disabled:opacity-50" @click="verVersiones(historial.doc, historial.page + 1)">Siguiente</button></nav>
        </div>
    </section>
</template>
