<script setup>
import { Head, usePage } from "@inertiajs/vue3";
import { computed, ref, watch, onBeforeUnmount } from "vue";
import axios from "axios";
import Modulo6Layout from "@/Layouts/Modulo6Layout.vue";
import PanelLateral from "@/Components/Panellateral.vue";
const props = defineProps({ tipo: { type: String, required: true } });
const page = usePage();
const votacion = computed(() => props.tipo === "votaciones");
const titulo = computed(() =>
    votacion.value ? "Votaciones internas" : "Encuestas y consultas",
);
const base = computed(() => `/api/consultas/${props.tipo}`);
const datos = ref(null),
    buscar = ref(""),
    error = ref(""),
    aviso = ref(""),
    cargando = ref(false),
    ocupado = ref(false);
const panel = ref(false),
    form = ref({}),
    preview = ref(null),
    detalle = ref(null),
    respuestas = ref({}),
    confirmar = ref(false);
const gestion = computed(() => page.props.auth.puede_editar);
const etiquetas = {
    borrador: "Borrador",
    programada: "Programada",
    abierta: "Abierta",
    cerrada: "Cerrada",
    cancelada: "Cancelada",
};
const fecha = (v) =>
    v
        ? new Date(v).toLocaleString("es-MX", {
              timeZone: "America/Mexico_City",
              dateStyle: "medium",
              timeStyle: "short",
          })
        : "";
function local(v) {
    const partes = Object.fromEntries(
        new Intl.DateTimeFormat("sv-SE", {
            timeZone: "America/Mexico_City",
            year: "numeric",
            month: "2-digit",
            day: "2-digit",
            hour: "2-digit",
            minute: "2-digit",
            hourCycle: "h23",
        })
            .formatToParts(new Date(v))
            .map((p) => [p.type, p.value]),
    );
    return `${partes.year}-${partes.month}-${partes.day}T${partes.hour}:${partes.minute}`;
}
function fallo(e) {
    error.value =
        Object.values(e.response?.data?.errors ?? {})
            .flat()
            .join(" ") ||
        e.response?.data?.message ||
        "No se pudo conectar. Intenta de nuevo.";
}
let versionVista = 0;
let timer;
async function cargar(p = datos.value?.page ?? 1, silencioso = false) {
    if (cargando.value || !page.props.auth.organizacion) return;
    const version = versionVista;
    cargando.value = true;
    if (!silencioso) error.value = "";
    try {
        const { data } = await axios.get(base.value, {
            params: { page: p, buscar: buscar.value },
        });
        if (version === versionVista) datos.value = data;
    } catch (e) {
        if (version === versionVista && !silencioso) fallo(e);
    } finally {
        if (version === versionVista) cargando.value = false;
    }
}
watch(
    () => props.tipo,
    () => {
        versionVista++;
        cargando.value = false;
        ocupado.value = false;
        datos.value = null;
        panel.value = false;
        detalle.value = null;
        preview.value = null;
        buscar.value = "";
        aviso.value = "";
        error.value = "";
        cargar(1);
        clearInterval(timer);
        timer = setInterval(() => {
            if (
                !document.hidden &&
                !ocupado.value &&
                !panel.value &&
                !preview.value &&
                !detalle.value
            )
                cargar(undefined, true);
        }, 15000);
    },
    { immediate: true },
);
onBeforeUnmount(() => {
    versionVista++;
    clearInterval(timer);
});
async function ejecutar(fn) {
    if (ocupado.value) return;
    const version = versionVista;
    ocupado.value = true;
    error.value = "";
    aviso.value = "";
    try {
        const { data } = await fn();
        if (version !== versionVista) return;
        aviso.value = data.message ?? "";
        await cargar();
        if (version === versionVista) return data;
    } catch (e) {
        if (version === versionVista) fallo(e);
    } finally {
        if (version === versionVista) ocupado.value = false;
    }
}
function editar(c) {
    error.value = "";
    preview.value = null;
    detalle.value = null;
    form.value = c
        ? {
              id: c.id,
              revision: c.revision,
              titulo: c.titulo,
              descripcion: c.descripcion,
              fecha_inicio: local(c.fecha_inicio),
              fecha_fin: local(c.fecha_fin),
              preguntas: c.preguntas.map((p) => ({
                  titulo: p.titulo,
                  opciones: p.opciones.map((o) => o.texto),
              })),
          }
        : {
              titulo: "",
              descripcion: "",
              fecha_inicio: local(Date.now()),
              fecha_fin: local(Date.now() + 7 * 86400000),
              preguntas: [
                  {
                      titulo: votacion.value
                          ? "Selecciona una opción o candidatura"
                          : "",
                      opciones: ["", ""],
                  },
              ],
          };
    panel.value = true;
}
async function guardar() {
    const d = await ejecutar(() =>
        form.value.id
            ? axios.put(`${base.value}/${form.value.id}`, form.value)
            : axios.post(base.value, form.value),
    );
    if (d) panel.value = false;
}
async function revisar(c) {
    const d = await ejecutar(() => axios.post(`${base.value}/${c.id}/preview`));
    if (d) {
        preview.value = d;
        detalle.value = null;
    }
}
async function publicar() {
    const d = await ejecutar(() =>
        axios.post(`${base.value}/${preview.value.consulta.id}/publicar`, {
            firma: preview.value.firma,
        }),
    );
    if (d) preview.value = null;
}
async function abrir(c) {
    const d = await ejecutar(() => axios.get(`${base.value}/${c.id}`));
    if (d) {
        detalle.value = d;
        respuestas.value = {};
        confirmar.value = false;
        preview.value = null;
    }
}
const completa = computed(() =>
    detalle.value?.consulta.preguntas.every((p) => respuestas.value[p.id]),
);
const seleccion = (p) =>
    p.opciones.find((o) => o.id === respuestas.value[p.id])?.texto;
async function participar() {
    const c = detalle.value.consulta;
    const d = await ejecutar(() =>
        axios.post(`${base.value}/${c.id}/responder`, {
            revision: c.revision,
            respuestas: c.preguntas.map((p) => ({
                pregunta_id: p.id,
                opcion_id: respuestas.value[p.id],
            })),
        }),
    );
    if (d) {
        detalle.value.consulta.participacion_registrada = true;
        detalle.value.consulta.puede_participar = false;
        confirmar.value = false;
        respuestas.value = {};
    }
}
async function estado(c, accion) {
    const motivo = prompt(
        accion === "cerrar"
            ? "Motivo del cierre anticipado (mínimo 10 caracteres). Se publicarán los resultados y no se podrá reabrir."
            : "Motivo de cancelación (mínimo 10 caracteres). Se conservarán las participaciones sin publicar resultados.",
    );
    if (motivo === null) return;
    const d = await ejecutar(() =>
        axios.post(`${base.value}/${c.id}/${accion}`, { motivo }),
    );
    if (d) {
        detalle.value = null;
        preview.value = null;
    }
}
</script>
<template>
    <Head :title="titulo + ' — Campus Digital'" />
    <Modulo6Layout :headerTitle="titulo">
        <div class="campus-page space-y-5">
            <div class="flex flex-wrap justify-between items-center gap-3">
                <div>
                    <h1 class="text-2xl font-bold text-gray-900">
                        {{ titulo }}
                    </h1>
                    <p class="text-sm text-gray-600 mt-1">
                        {{
                            votacion
                                ? "Elige entre las opciones de tu organización."
                                : "Comparte tus prioridades sobre servicios y actividades."
                        }}
                    </p>
                </div>
                <button
                    v-if="gestion"
                    @click="editar()"
                    :disabled="ocupado"
                    class="bg-blue-900 text-white rounded-lg px-4 py-2"
                >
                    {{ votacion ? "Nueva votación" : "Nueva encuesta" }}
                </button>
            </div>
            <p
                v-if="!page.props.auth.organizacion"
                class="bg-white border rounded-xl p-6"
            >
                Necesitas una membresía activa para consultar y participar en tu
                organización.
            </p>
            <p
                v-if="aviso"
                role="status"
                class="bg-green-50 text-green-800 p-4 rounded-lg"
            >
                {{ aviso }}
            </p>
            <p
                v-if="error && !panel"
                role="alert"
                class="bg-red-50 text-red-800 p-4 rounded-lg"
            >
                {{ error }}
            </p>
            <template v-if="page.props.auth.organizacion">
                <p class="text-sm text-gray-600">
                    Una participación por cuenta, sin cambios después de
                    enviarla. Los resultados agregados se muestran al cerrar.
                    Las respuestas se guardan vinculadas a tu cuenta; no es una
                    votación anónima.
                </p>
                <form @submit.prevent="cargar(1)" class="flex gap-3">
                    <input
                        v-model="buscar"
                        aria-label="Buscar consultas"
                        placeholder="Buscar por título"
                        maxlength="120"
                        class="flex-1 min-w-0 rounded-lg border-gray-300"
                    /><button
                        :disabled="cargando"
                        class="border bg-white rounded-lg px-4"
                    >
                        Buscar
                    </button>
                </form>
                <section
                    v-if="preview"
                    class="bg-white border-2 border-blue-200 rounded-xl p-5 space-y-4"
                >
                    <div class="flex justify-between gap-3">
                        <h2 class="font-bold text-xl">Revisar publicación</h2>
                        <button @click="preview = null" :disabled="ocupado">
                            Cerrar vista previa
                        </button>
                    </div>
                    <h3 class="font-bold break-words">
                        {{ preview.consulta.titulo }}
                    </h3>
                    <p class="whitespace-pre-line break-words">
                        {{ preview.consulta.descripcion }}
                    </p>
                    <p>
                        <strong
                            >{{ preview.total_padron }} integrantes en el
                            padrón</strong
                        >. Se fija al publicar. Altas posteriores no podrán
                        participar y quienes pierdan su membresía no podrán
                        acceder.
                    </p>
                    <p class="text-sm">
                        {{ fecha(preview.consulta.fecha_inicio) }} —
                        {{ fecha(preview.consulta.fecha_fin) }} · Ciudad de
                        México
                    </p>
                    <ol class="list-decimal pl-5 space-y-3">
                        <li
                            v-for="p in preview.consulta.preguntas"
                            :key="p.id"
                            class="break-words"
                        >
                            <strong>{{ p.titulo }}</strong>
                            <ul class="list-disc pl-5">
                                <li v-for="o in p.opciones" :key="o.id">
                                    {{ o.texto }}
                                </li>
                            </ul>
                        </li>
                    </ol>
                    <p class="text-sm text-gray-600">
                        Después de publicar no se podrán cambiar las preguntas,
                        opciones, padrón ni fechas.
                    </p>
                    <button
                        @click="publicar"
                        :disabled="ocupado || !preview.total_padron"
                        class="bg-blue-900 text-white rounded-lg px-4 py-2 disabled:opacity-50"
                    >
                        Confirmar publicación
                    </button>
                </section>
                <section
                    v-if="detalle"
                    class="bg-white border-2 border-blue-200 rounded-xl p-5 md:p-6 space-y-5"
                >
                    <div class="flex justify-between gap-3">
                        <h2 class="font-bold text-xl break-words min-w-0">
                            {{ detalle.consulta.titulo }}
                        </h2>
                        <button
                            @click="detalle = null"
                            :disabled="ocupado"
                            aria-label="Cerrar detalle"
                        >
                            Cerrar
                        </button>
                    </div>
                    <p class="whitespace-pre-line break-words">
                        {{ detalle.consulta.descripcion }}
                    </p>
                    <p class="text-sm text-gray-600">
                        {{ etiquetas[detalle.consulta.fase] }} ·
                        {{ fecha(detalle.consulta.fecha_inicio) }} —
                        {{ fecha(detalle.consulta.fecha_fin) }} · Ciudad de
                        México
                    </p>
                    <p
                        v-if="detalle.consulta.participacion_registrada"
                        class="bg-green-50 text-green-800 p-3 rounded-lg"
                    >
                        Tu participación está registrada.
                    </p>
                    <p v-if="detalle.consulta.motivo_cierre">
                        Motivo de cierre: {{ detalle.consulta.motivo_cierre }}
                    </p>
                    <p v-if="detalle.consulta.motivo_cancelacion">
                        Motivo de cancelación:
                        {{ detalle.consulta.motivo_cancelacion }}
                    </p>
                    <form
                        v-if="detalle.consulta.puede_participar"
                        @submit.prevent="
                            confirmar ? participar() : (confirmar = true)
                        "
                        class="space-y-5"
                    >
                        <template v-if="!confirmar"
                            ><fieldset
                                v-for="p in detalle.consulta.preguntas"
                                :key="p.id"
                                :disabled="ocupado"
                                class="border rounded-lg p-4 space-y-2 min-w-0"
                            >
                                <legend class="font-semibold px-1 break-words">
                                    {{ p.titulo }}
                                </legend>
                                <label
                                    v-for="o in p.opciones"
                                    :key="o.id"
                                    class="flex gap-3 items-start rounded p-2 hover:bg-gray-50"
                                    ><input
                                        type="radio"
                                        :name="p.id"
                                        :value="o.id"
                                        v-model="respuestas[p.id]"
                                        required
                                        class="mt-1 shrink-0"
                                    /><span class="break-words min-w-0">{{
                                        o.texto
                                    }}</span></label
                                >
                            </fieldset></template
                        >
                        <div v-else class="space-y-3">
                            <h3 class="font-bold">Revisa tu participación</h3>
                            <p
                                v-for="p in detalle.consulta.preguntas"
                                :key="p.id"
                                class="break-words"
                            >
                                <strong>{{ p.titulo }}</strong
                                ><br />{{ seleccion(p) }}
                            </p>
                            <p class="text-sm text-gray-600">
                                Al confirmar se registrará una sola vez. No
                                podrás modificar tus respuestas.
                            </p>
                            <button
                                type="button"
                                @click="confirmar = false"
                                :disabled="ocupado"
                                class="text-blue-800 font-semibold"
                            >
                                Corregir selección
                            </button>
                        </div>
                        <button
                            :disabled="ocupado || !completa"
                            class="bg-blue-900 text-white rounded-lg px-4 py-2 disabled:opacity-50"
                        >
                            {{
                                confirmar
                                    ? votacion
                                        ? "Confirmar voto"
                                        : "Enviar respuestas"
                                    : "Revisar respuestas"
                            }}
                        </button>
                    </form>
                    <template v-else-if="detalle.resultados"
                        ><h3 class="font-bold text-lg">Resultados agregados</h3>
                        <p>
                            {{ detalle.resultados.total }} participaciones de
                            {{ detalle.resultados.total_padron }} integrantes ·
                            {{ detalle.resultados.participacion_porcentaje }}%
                        </p>
                        <div
                            v-for="p in detalle.resultados.preguntas"
                            :key="p.id"
                            class="space-y-3"
                        >
                            <h4 class="font-semibold break-words">
                                {{ p.titulo }}
                            </h4>
                            <div
                                v-for="o in p.opciones"
                                :key="o.id"
                                class="space-y-1"
                            >
                                <div
                                    class="flex flex-wrap justify-between gap-2 text-sm"
                                >
                                    <span class="break-words min-w-0">{{
                                        o.texto
                                    }}</span
                                    ><span
                                        >{{ o.cantidad }} ·
                                        {{ o.porcentaje }}%</span
                                    >
                                </div>
                                <div
                                    class="h-2 bg-gray-100 rounded-full overflow-hidden"
                                >
                                    <div
                                        class="h-2 bg-blue-800 rounded-full"
                                        :style="{ width: o.porcentaje + '%' }"
                                    ></div>
                                </div>
                            </div>
                        </div>
                        <p v-if="votacion" class="text-sm text-gray-500">
                            Se muestran los conteos, incluidos empates. Esta
                            votación no asigna cargos automáticamente.
                        </p></template
                    >
                    <p
                        v-else-if="detalle.consulta.fase !== 'cancelada'"
                        class="text-sm text-gray-600"
                    >
                        Los resultados estarán disponibles al cerrar.
                        {{
                            detalle.consulta.fase === "abierta" &&
                            !detalle.consulta.participacion_registrada
                                ? "Tu cuenta no pertenece al padrón publicado."
                                : ""
                        }}
                    </p>
                </section>
                <p v-if="cargando && !datos">Cargando consultas…</p>
                <div
                    v-if="datos"
                    class="grid md:grid-cols-2 xl:grid-cols-3 gap-5"
                >
                    <article
                        v-for="c in datos.consultas"
                        :key="c.id"
                        class="bg-white border rounded-xl p-5 space-y-3 min-w-0"
                    >
                        <div class="flex flex-wrap justify-between gap-2">
                            <h2 class="font-bold text-lg break-words min-w-0">
                                {{ c.titulo }}
                            </h2>
                            <span
                                class="text-xs bg-blue-50 text-blue-800 rounded px-2 py-1"
                                >{{ etiquetas[c.fase] }}</span
                            >
                        </div>
                        <p class="text-sm text-gray-600">
                            {{ fecha(c.fecha_inicio) }}<br />Cierre:
                            {{ fecha(c.fecha_fin) }}
                        </p>
                        <p class="text-sm">
                            {{ c.preguntas.length }}
                            {{
                                c.preguntas.length === 1
                                    ? "pregunta"
                                    : "preguntas"
                            }}<span v-if="c.total_padron">
                                · Padrón: {{ c.total_padron }}</span
                            >
                        </p>
                        <p
                            v-if="c.participacion_registrada"
                            class="text-sm text-green-800"
                        >
                            Participación registrada
                        </p>
                        <div class="flex flex-wrap gap-3 font-semibold text-sm">
                            <template v-if="gestion && c.fase === 'borrador'"
                                ><button
                                    @click="editar(c)"
                                    :disabled="ocupado"
                                    class="text-blue-800"
                                >
                                    Editar</button
                                ><button
                                    @click="revisar(c)"
                                    :disabled="ocupado"
                                    class="text-blue-800"
                                >
                                    Revisar y publicar
                                </button></template
                            ><button
                                v-if="c.fase !== 'borrador'"
                                @click="abrir(c)"
                                :disabled="ocupado"
                                class="text-blue-800"
                            >
                                {{
                                    c.fase === "cerrada"
                                        ? "Ver resultados"
                                        : c.puede_participar
                                          ? "Participar"
                                          : "Ver detalle"
                                }}</button
                            ><button
                                v-if="gestion && c.fase === 'abierta'"
                                @click="estado(c, 'cerrar')"
                                :disabled="ocupado"
                                class="text-amber-800"
                            >
                                Cerrar anticipadamente</button
                            ><button
                                v-if="
                                    gestion &&
                                    [
                                        'borrador',
                                        'programada',
                                        'abierta',
                                    ].includes(c.fase)
                                "
                                @click="estado(c, 'cancelar')"
                                :disabled="ocupado"
                                class="text-red-700"
                            >
                                Cancelar
                            </button>
                        </div>
                    </article>
                </div>
                <p
                    v-if="datos && !datos.consultas.length && !cargando"
                    class="bg-white border rounded-xl p-8 text-center text-gray-500"
                >
                    No hay consultas disponibles para esta búsqueda y tu padrón.
                </p>
                <div
                    v-if="datos?.last_page > 1"
                    class="flex justify-center gap-4"
                >
                    <button
                        :disabled="cargando || datos.page === 1"
                        @click="cargar(datos.page - 1)"
                    >
                        Anterior</button
                    ><span>{{ datos.page }} / {{ datos.last_page }}</span
                    ><button
                        :disabled="cargando || datos.page === datos.last_page"
                        @click="cargar(datos.page + 1)"
                    >
                        Siguiente
                    </button>
                </div>
            </template>
        </div>
        <PanelLateral
            :show="panel"
            :titulo="
                form.id
                    ? 'Editar borrador'
                    : votacion
                      ? 'Nueva votación'
                      : 'Nueva encuesta'
            "
            @close="!ocupado && (panel = false)"
        >
            <p
                v-if="error"
                role="alert"
                class="bg-red-50 text-red-800 p-3 rounded-lg mb-4"
            >
                {{ error }}
            </p>
            <form id="form-consulta" @submit.prevent="guardar">
                <fieldset :disabled="ocupado" class="space-y-4 min-w-0">
                    <div>
                        <label for="consulta-titulo">Título</label
                        ><input
                            id="consulta-titulo"
                            v-model="form.titulo"
                            required
                            maxlength="150"
                            class="w-full rounded-lg border-gray-300"
                        />
                    </div>
                    <div>
                        <label for="consulta-descripcion">Descripción</label
                        ><textarea
                            id="consulta-descripcion"
                            v-model="form.descripcion"
                            required
                            minlength="10"
                            maxlength="3000"
                            rows="3"
                            class="w-full rounded-lg border-gray-300"
                        ></textarea>
                    </div>
                    <p class="text-sm text-gray-600">
                        Población: integrantes activos de tu organización al
                        publicar. Fechas en horario de Ciudad de México.
                    </p>
                    <div>
                        <label for="consulta-inicio">Inicio</label
                        ><input
                            id="consulta-inicio"
                            type="datetime-local"
                            v-model="form.fecha_inicio"
                            required
                            class="w-full min-w-0 rounded-lg border-gray-300"
                        />
                    </div>
                    <div>
                        <label for="consulta-fin">Cierre</label
                        ><input
                            id="consulta-fin"
                            type="datetime-local"
                            v-model="form.fecha_fin"
                            required
                            class="w-full min-w-0 rounded-lg border-gray-300"
                        />
                    </div>
                    <div
                        v-for="(p, i) in form.preguntas"
                        :key="i"
                        class="rounded-lg border p-3 space-y-3"
                    >
                        <label :for="'pregunta-' + i" class="font-semibold">{{
                            votacion
                                ? "Pregunta de la votación"
                                : `Pregunta ${i + 1}`
                        }}</label
                        ><input
                            :id="'pregunta-' + i"
                            v-model="p.titulo"
                            required
                            maxlength="250"
                            class="w-full rounded-lg border-gray-300"
                        />
                        <div
                            v-for="(_, j) in p.opciones"
                            :key="j"
                            class="flex gap-2"
                        >
                            <div class="flex-1 min-w-0">
                                <label :for="`opcion-${i}-${j}`" class="text-sm"
                                    >{{
                                        votacion
                                            ? "Opción o candidatura"
                                            : "Opción"
                                    }}
                                    {{ j + 1 }}</label
                                ><input
                                    :id="`opcion-${i}-${j}`"
                                    v-model="p.opciones[j]"
                                    required
                                    maxlength="150"
                                    class="w-full rounded-lg border-gray-300"
                                />
                            </div>
                            <button
                                type="button"
                                v-if="p.opciones.length > 2"
                                @click="p.opciones.splice(j, 1)"
                                :aria-label="`Quitar opción ${j + 1} de pregunta ${i + 1}`"
                                class="text-red-700 self-end pb-2"
                            >
                                Quitar
                            </button>
                        </div>
                        <div class="flex flex-wrap gap-3 text-sm">
                            <button
                                v-if="p.opciones.length < (votacion ? 20 : 10)"
                                type="button"
                                @click="p.opciones.push('')"
                                class="text-blue-800 font-semibold"
                            >
                                Añadir opción</button
                            ><button
                                v-if="form.preguntas.length > 1"
                                type="button"
                                @click="form.preguntas.splice(i, 1)"
                                class="text-red-700"
                            >
                                Quitar pregunta {{ i + 1 }}
                            </button>
                        </div>
                    </div>
                    <button
                        v-if="!votacion && form.preguntas?.length < 10"
                        type="button"
                        @click="
                            form.preguntas.push({
                                titulo: '',
                                opciones: ['', ''],
                            })
                        "
                        class="text-blue-800 font-semibold"
                    >
                        Añadir pregunta
                    </button>
                    <p class="text-sm text-gray-500">
                        Todas las preguntas requieren una opción. Guarda el
                        borrador y revisa el padrón antes de publicarlo.
                    </p>
                </fieldset>
            </form>
            <template #footer
                ><button
                    form="form-consulta"
                    type="submit"
                    :disabled="ocupado"
                    class="bg-blue-900 text-white rounded-lg px-4 py-2"
                >
                    {{ ocupado ? "Guardando…" : "Guardar borrador" }}
                </button></template
            >
        </PanelLateral>
    </Modulo6Layout>
</template>
