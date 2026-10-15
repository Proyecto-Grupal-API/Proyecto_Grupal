<script setup>
import { onMounted, ref, watch } from "vue";
import axios from "axios";

const props = defineProps({ miembros: { type: Array, default: () => [] }, actorId: String, organizacionId: String });
const datos = ref(null), cargando = ref(false), ocupado = ref(false), error = ref(""), aviso = ref("");
const form = ref({ destinatario_id: "", capacidades: [], inicio: "", fin: "", motivo: "" });
const clave = ref(crypto.randomUUID()), revocando = ref(null), motivoRevocacion = ref("");
watch(form, () => { clave.value = crypto.randomUUID(); }, { deep: true });
const opciones = () => ({ headers: { "X-Organization-Id": props.organizacionId } });
const nombre = (id) => props.miembros.find(m => m.usuario_id === id)?.nombre || "Integrante no disponible";
const fecha = (valor) => new Intl.DateTimeFormat("es-MX", { dateStyle: "medium", timeStyle: "short", timeZone: "America/Mexico_City" }).format(new Date(valor));
function fallar(e) {
    error.value = Object.values(e.response?.data?.errors ?? {}).flat().join(" ") || e.response?.data?.message || "No se pudo conectar. Intenta nuevamente.";
}
async function cargar(pagina = 1) {
    cargando.value = true;
    error.value = "";
    try {
        datos.value = (await axios.get("/api/organizaciones/delegaciones", { ...opciones(), params: { page: pagina } })).data;
    } catch (e) { fallar(e); }
    finally { cargando.value = false; }
}
async function guardar() {
    if (ocupado.value) return;
    ocupado.value = true; error.value = ""; aviso.value = "";
    try {
        await axios.post("/api/organizaciones/delegaciones", { ...form.value, clave_idempotencia: clave.value }, opciones());
        form.value = { destinatario_id: "", capacidades: [], inicio: "", fin: "", motivo: "" };
        aviso.value = "Delegación guardada. Su acceso depende del periodo y de la presidencia vigentes.";
        await cargar();
    } catch (e) { fallar(e); }
    finally { ocupado.value = false; }
}
async function revocar(id) {
    if (ocupado.value) return;
    ocupado.value = true; error.value = ""; aviso.value = "";
    try {
        await axios.post(`/api/organizaciones/delegaciones/${id}/revocar`, { motivo: motivoRevocacion.value }, opciones());
        revocando.value = null; motivoRevocacion.value = "";
        aviso.value = "Delegación revocada. El cambio quedó en el historial.";
        await cargar(datos.value.page);
    } catch (e) { fallar(e); }
    finally { ocupado.value = false; }
}
onMounted(() => cargar());
</script>

<template>
    <section class="rounded-xl border bg-white p-5 space-y-5" aria-labelledby="delegaciones-titulo" :aria-busy="cargando || ocupado">
        <div class="flex flex-wrap justify-between items-start gap-3">
            <div>
                <h2 id="delegaciones-titulo" class="text-lg font-bold text-gray-900">Delegaciones temporales</h2>
                <p class="text-sm text-gray-600 mt-1">Funciones de Comunicación y becas con inicio, vencimiento e historial.</p>
            </div>
            <button type="button" class="text-blue-900 font-semibold disabled:opacity-50" :disabled="cargando || ocupado" @click="cargar(datos?.page)">Actualizar</button>
        </div>
        <p v-if="error" role="alert" class="rounded-lg bg-red-50 text-red-800 p-3">{{ error }}</p>
        <p v-if="aviso" role="status" class="rounded-lg bg-green-50 text-green-800 p-3">{{ aviso }}</p>
        <p v-if="cargando" role="status" class="text-sm text-gray-600">Cargando delegaciones…</p>
        <details v-if="datos?.puede_administrar" class="rounded-lg border p-4">
            <summary class="cursor-pointer text-blue-900 font-semibold">Otorgar una delegación</summary>
            <form class="mt-4 space-y-4" @submit.prevent="guardar">
                <fieldset :disabled="ocupado" class="space-y-4 disabled:opacity-60">
                    <div>
                        <label for="delegacion-destinatario" class="block text-sm font-medium mb-1">Integrante</label>
                        <select id="delegacion-destinatario" v-model="form.destinatario_id" required class="w-full rounded-lg border-gray-300">
                            <option value="" disabled>Selecciona un integrante activo</option>
                            <option v-for="m in miembros.filter(m => m.usuario_id !== actorId)" :key="m.id" :value="m.usuario_id">{{ m.nombre }} · {{ m.matricula }}</option>
                        </select>
                    </div>
                    <fieldset class="space-y-2">
                        <legend class="text-sm font-medium mb-2">Funciones que podrá realizar</legend>
                        <label v-for="(texto, capacidad) in datos.capacidades" :key="capacidad" class="flex items-start gap-3 text-sm">
                            <input v-model="form.capacidades" type="checkbox" :value="capacidad" class="rounded border-gray-300 mt-0.5" />{{ texto }}
                        </label>
                    </fieldset>
                    <div class="grid sm:grid-cols-2 gap-4">
                        <div><label for="delegacion-inicio" class="block text-sm font-medium mb-1">Inicio</label><input id="delegacion-inicio" v-model="form.inicio" required type="datetime-local" class="w-full min-w-0 rounded-lg border-gray-300" /></div>
                        <div><label for="delegacion-fin" class="block text-sm font-medium mb-1">Fin</label><input id="delegacion-fin" v-model="form.fin" required type="datetime-local" :min="form.inicio" class="w-full min-w-0 rounded-lg border-gray-300" /></div>
                    </div>
                    <p class="text-sm text-gray-600">Horario de Ciudad de México. Al llegar la hora de fin, el permiso termina. El periodo debe quedar dentro de la vigencia de presidencia.</p>
                    <div><label for="delegacion-motivo" class="block text-sm font-medium mb-1">Motivo</label><textarea id="delegacion-motivo" v-model="form.motivo" required minlength="10" maxlength="1000" rows="2" class="w-full rounded-lg border-gray-300"></textarea></div>
                    <button :disabled="!form.capacidades.length" class="rounded-lg bg-blue-900 text-white px-4 py-2 disabled:opacity-50">{{ ocupado ? "Guardando…" : "Otorgar delegación" }}</button>
                </fieldset>
            </form>
        </details>
        <template v-if="datos">
            <p v-if="!datos.puede_administrar" class="text-sm text-gray-600">Aquí aparecen las delegaciones que recibiste. Solo la presidencia vigente puede otorgarlas o revocarlas.</p>
            <p v-if="!datos.delegaciones.length && !cargando" class="text-sm text-gray-600 py-3">Todavía no hay delegaciones en este historial.</p>
            <ol class="space-y-3" aria-label="Historial de delegaciones">
                <li v-for="d in datos.delegaciones" :key="d.id" class="rounded-lg border p-4 space-y-2 break-words">
                    <div class="flex flex-wrap justify-between gap-2"><h3 class="font-semibold">{{ nombre(d.destinatario_id) }}</h3><span class="text-sm font-medium">{{ d.estado.replaceAll('_', ' ') }}</span></div>
                    <p class="text-sm text-gray-600">{{ fecha(d.inicio) }} → {{ fecha(d.fin) }} · Ciudad de México</p>
                    <ul class="list-disc pl-5 text-sm"><li v-for="c in d.capacidades" :key="c">{{ datos.capacidades[c] }}</li></ul>
                    <p class="text-sm whitespace-pre-line">{{ d.motivo }}</p>
                    <p class="text-xs text-gray-600">Otorgada por {{ nombre(d.delegante_id) }}.</p>
                    <p v-if="d.revocada_en" class="text-sm text-gray-600">Revocada el {{ fecha(d.revocada_en) }}: {{ d.motivo_revocacion }}</p>
                    <template v-else-if="datos.puede_administrar && ['vigente', 'programada', 'sin_efecto'].includes(d.estado)">
                        <button v-if="revocando !== d.id" type="button" :disabled="ocupado" class="font-semibold text-sm text-red-700" @click="revocando = d.id; motivoRevocacion = ''">Revocar delegación</button>
                        <form v-else class="space-y-2" @submit.prevent="revocar(d.id)">
                            <label :for="`revocacion-${d.id}`" class="block text-sm font-medium">Motivo de revocación</label>
                            <textarea :id="`revocacion-${d.id}`" v-model="motivoRevocacion" required minlength="10" maxlength="1000" :disabled="ocupado" class="w-full rounded-lg border-gray-300" rows="2"></textarea>
                            <div class="flex gap-3"><button :disabled="ocupado" class="rounded-lg bg-red-700 text-white px-3 py-2 text-sm disabled:opacity-50">Confirmar revocación</button><button type="button" :disabled="ocupado" class="text-sm text-gray-700" @click="revocando = null">Cancelar</button></div>
                        </form>
                    </template>
                </li>
            </ol>
            <nav v-if="datos.last_page > 1" class="flex items-center gap-4 text-sm" aria-label="Páginas de delegaciones">
                <button :disabled="cargando || ocupado || datos.page === 1" class="text-blue-900 disabled:opacity-50" @click="cargar(datos.page - 1)">Anterior</button><span>{{ datos.page }} de {{ datos.last_page }}</span><button :disabled="cargando || ocupado || datos.page === datos.last_page" class="text-blue-900 disabled:opacity-50" @click="cargar(datos.page + 1)">Siguiente</button>
            </nav>
        </template>
    </section>
</template>
