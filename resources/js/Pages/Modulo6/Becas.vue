<script setup>
import { Head, Link, usePage, router } from "@inertiajs/vue3";
import { ref, computed, onMounted } from "vue";
import axios from "axios";
import Modulo6Layout from "@/Layouts/Modulo6Layout.vue";
import PanelLateral from "@/Components/Panellateral.vue";
const page = usePage();
const modo = ref(page.props.auth.puede_editar ? "gestion" : "catalogo");
const datos = ref(null),
    mias = ref(null),
    lista = ref(null),
    seleccionada = ref(null),
    cargando = ref(false),
    ocupado = ref(false),
    error = ref(""),
    aviso = ref(""),
    buscar = ref(""),
    filtro = ref(""),
    panel = ref(false),
    form = ref({}),
    documentos = ref("");
const tipos = computed(() => datos.value?.tipos ?? []);
const monetario = computed(
    () =>
        tipos.value.find((t) => t.id === form.value.tipo_beneficio_id)
            ?.es_monetario,
);
const local = (v, fin = false) =>
    v
        ? new Date(new Date(v).getTime() - (fin ? 1 : 0)).toLocaleDateString(
              "sv-SE",
              { timeZone: "America/Mexico_City" },
          )
        : "";
const fecha = (v, fin = false) =>
    v
        ? new Date(new Date(v).getTime() - (fin ? 1 : 0)).toLocaleDateString(
              "es-MX",
              { timeZone: "America/Mexico_City", dateStyle: "medium" },
          )
        : "";
const dinero = (c) =>
    (c / 100).toLocaleString("es-MX", { style: "currency", currency: "MXN" });
function fallar(e) {
    error.value =
        Object.values(e.response?.data?.errors ?? {})
            .flat()
            .join(" ") ||
        e.response?.data?.message ||
        "No se pudo conectar con el servidor.";
}
async function cargar(p = 1) {
    cargando.value = true;
    error.value = "";
    try {
        if (modo.value === "mias")
            mias.value = (
                await axios.get("/api/becas/mis-solicitudes", {
                    params: { page: p },
                })
            ).data;
        else
            datos.value = (
                await axios.get("/api/becas", {
                    params: {
                        gestion: modo.value === "gestion" ? 1 : 0,
                        buscar: buscar.value,
                        page: p,
                    },
                })
            ).data;
    } catch (e) {
        fallar(e);
    } finally {
        cargando.value = false;
    }
}
onMounted(() => cargar());
function cambiar(m) {
    modo.value = m;
    seleccionada.value = null;
    lista.value = null;
    aviso.value = "";
    cargar();
}
async function ejecutar(fn) {
    if (ocupado.value) return;
    ocupado.value = true;
    error.value = "";
    aviso.value = "";
    try {
        const { data } = await fn();
        aviso.value = data.message;
        panel.value = false;
        await cargar();
        if (seleccionada.value)
            await verSolicitudes(seleccionada.value, lista.value?.page ?? 1);
        return data;
    } catch (e) {
        fallar(e);
    } finally {
        ocupado.value = false;
    }
}
function abrir(c = null) {
    error.value = "";
    aviso.value = "";
    form.value = c
        ? {
              id: c.id,
              titulo: c.titulo,
              descripcion: c.descripcion,
              requisitos: c.requisitos,
              tipo_beneficio_id: c.tipo_beneficio_id,
              monto: (c.monto_centavos / 100).toFixed(2),
              cantidad: c.cantidad,
              total_espacios: c.total_espacios,
              fecha_inicio: local(c.fecha_inicio),
              fecha_fin: local(c.fecha_fin, true),
              vigencia_inicio: local(c.vigencia_inicio),
              vigencia_fin: local(c.vigencia_fin, true),
          }
        : {
              titulo: "",
              descripcion: "",
              requisitos: "",
              tipo_beneficio_id: tipos.value[0]?.id,
              monto: "0",
              cantidad: 1,
              total_espacios: 10,
              fecha_inicio: local(Date.now()),
              fecha_fin: "",
              vigencia_inicio: "",
              vigencia_fin: "",
          };
    documentos.value = (c?.requisitos_documentos ?? []).join("\n");
    panel.value = true;
}
function guardar() {
    const d = {
        ...form.value,
        requisitos_documentos: documentos.value
            .split("\n")
            .map((x) => x.trim())
            .filter(Boolean),
    };
    ejecutar(() =>
        d.id ? axios.put(`/api/becas/${d.id}`, d) : axios.post("/api/becas", d),
    );
}
function estado(c, destino) {
    let motivo;
    if (destino === "cancelada") {
        motivo = prompt("Motivo de cancelación (mínimo 10 caracteres):");
        if (motivo === null) return;
    } else if (
        destino === "en_revision" &&
        !confirm(
            "¿Cerrar la recepción? Los estudiantes ya no podrán enviar ni editar solicitudes.",
        )
    )
        return;
    ejecutar(() =>
        axios.post(`/api/becas/${c.id}/estado`, { estado: destino, motivo }),
    );
}
async function solicitar(c) {
    const data = await ejecutar(() =>
        axios.post(`/api/becas/${c.id}/solicitud`),
    );
    if (data) router.visit(`/modulo6/becas/solicitudes/${data.solicitud.id}`);
}
async function verSolicitudes(c, p = 1) {
    seleccionada.value = c;
    try {
        lista.value = (
            await axios.get(`/api/becas/${c.id}/solicitudes`, {
                params: { page: p, estado: filtro.value },
            })
        ).data;
        seleccionada.value = lista.value.convocatoria;
    } catch (e) {
        fallar(e);
    }
}
const paginado = computed(() =>
    modo.value === "mias" ? mias.value : datos.value,
);
</script>
<template>
    <Head title="Becas y apoyos — Campus Digital" />
    <Modulo6Layout headerTitle="Becas y apoyos">
        <div class="campus-page space-y-6">
            <div class="flex flex-wrap justify-between gap-3 items-center">
                <div>
                    <h1 class="text-2xl font-bold text-gray-900">
                        Apoyos para tu vida universitaria
                    </h1>
                    <p class="text-sm text-gray-600 mt-1">
                        Consulta requisitos, prepara tu solicitud y sigue su
                        dictamen.
                    </p>
                </div>
                <button
                    v-if="modo === 'gestion'"
                    @click="abrir()"
                    :disabled="ocupado || !tipos.length"
                    class="rounded-lg bg-blue-900 text-white px-4 py-2"
                >
                    Nueva convocatoria
                </button>
            </div>
            <nav class="flex flex-wrap gap-2" aria-label="Secciones de becas">
                <button
                    v-for="tab in [
                        { id: 'catalogo', texto: 'Explorar apoyos' },
                        { id: 'mias', texto: 'Mis solicitudes' },
                        ...(page.props.auth.puede_editar
                            ? [
                                  {
                                      id: 'gestion',
                                      texto: 'Administrar organización',
                                  },
                              ]
                            : []),
                    ]"
                    :key="tab.id"
                    @click="cambiar(tab.id)"
                    :disabled="ocupado || cargando"
                    :class="
                        modo === tab.id ? 'bg-blue-900 text-white' : 'bg-white'
                    "
                    class="rounded-lg border px-4 py-2"
                >
                    {{ tab.texto }}
                </button>
            </nav>
            <p
                v-if="aviso"
                role="status"
                class="rounded-lg bg-green-50 text-green-800 p-4"
            >
                {{ aviso }}
            </p>
            <p
                v-if="error && !panel"
                role="alert"
                class="rounded-lg bg-red-50 text-red-800 p-4"
            >
                {{ error }}
            </p>
            <form
                v-if="modo !== 'mias'"
                @submit.prevent="cargar()"
                class="flex gap-3"
            >
                <input
                    v-model="buscar"
                    aria-label="Buscar convocatorias"
                    placeholder="Buscar por título"
                    maxlength="120"
                    class="rounded-lg border-gray-300 flex-1 min-w-0"
                /><button
                    :disabled="cargando"
                    class="border rounded-lg bg-white px-4"
                >
                    Buscar
                </button>
            </form>
            <p v-if="cargando" role="status">Cargando…</p>
            <div v-if="modo === 'mias'" class="space-y-3">
                <article
                    v-for="s in mias?.solicitudes"
                    :key="s.id"
                    class="rounded-xl border bg-white p-5 flex flex-wrap justify-between gap-3"
                >
                    <div>
                        <h2 class="font-bold">
                            {{ s.titulo || "Convocatoria no disponible" }}
                        </h2>
                        <p class="text-sm text-gray-500">
                            {{ s.folio }} · {{ s.estado.replaceAll("_", " ") }}
                        </p>
                    </div>
                    <Link
                        :href="`/modulo6/becas/solicitudes/${s.id}`"
                        class="text-blue-800 font-semibold"
                        >Ver solicitud</Link
                    >
                </article>
                <p
                    v-if="mias && !mias.solicitudes.length"
                    class="bg-white rounded-xl border p-8 text-center"
                >
                    Todavía no has iniciado una solicitud.
                </p>
            </div>
            <template v-else-if="datos"
                ><div class="grid md:grid-cols-2 xl:grid-cols-3 gap-5">
                    <article
                        v-for="c in datos.convocatorias"
                        :key="c.id"
                        class="rounded-xl border bg-white p-5 space-y-3 flex flex-col"
                    >
                        <div
                            class="flex justify-between gap-2 text-xs text-gray-500"
                        >
                            <span>{{ c.organizacion_nombre }}</span
                            ><span>{{ c.estado.replaceAll("_", " ") }}</span>
                        </div>
                        <h2 class="text-xl font-bold text-gray-900">
                            {{ c.titulo }}
                        </h2>
                        <p class="text-sm text-gray-600 whitespace-pre-line">
                            {{ c.descripcion }}
                        </p>
                        <p class="font-semibold text-blue-900">
                            {{ c.beneficio?.nombre }} ·
                            {{
                                c.beneficio?.es_monetario
                                    ? dinero(c.monto_centavos)
                                    : `${c.cantidad} unidad(es) por persona`
                            }}
                        </p>
                        <p class="text-sm text-gray-600">
                            {{ c.espacios_ocupados }} de
                            {{ c.total_espacios }} apoyos aprobados
                        </p>
                        <div class="text-sm text-gray-600 space-y-1">
                            <p>
                                Recepción: {{ fecha(c.fecha_inicio) }} —
                                {{ fecha(c.fecha_fin, true) }}
                            </p>
                            <p>
                                Vigencia: {{ fecha(c.vigencia_inicio) }} —
                                {{ fecha(c.vigencia_fin, true) }}
                            </p>
                        </div>
                        <details class="text-sm">
                            <summary class="text-blue-800 cursor-pointer">
                                Requisitos y documentos
                            </summary>
                            <p class="mt-2 whitespace-pre-line">
                                {{ c.requisitos }}
                            </p>
                            <ul class="list-disc pl-5 mt-2">
                                <li
                                    v-for="r in c.requisitos_documentos"
                                    :key="r"
                                >
                                    {{ r }}
                                </li>
                            </ul>
                            <p
                                v-if="!c.requisitos_documentos?.length"
                                class="mt-2 text-gray-500"
                            >
                                Sin documentos obligatorios.
                            </p>
                        </details>
                        <p
                            v-if="c.motivo_cancelacion"
                            class="text-sm text-red-700"
                        >
                            {{ c.motivo_cancelacion }}
                        </p>
                        <div
                            v-if="modo === 'gestion'"
                            class="mt-auto flex flex-wrap gap-3 text-sm font-semibold"
                        >
                            <button
                                v-if="c.estado === 'borrador'"
                                @click="abrir(c)"
                                :disabled="ocupado"
                                class="text-blue-800"
                            >
                                Editar</button
                            ><button
                                v-if="c.estado === 'borrador'"
                                @click="estado(c, 'publicada')"
                                :disabled="ocupado"
                                class="text-green-800"
                            >
                                Publicar</button
                            ><button
                                v-if="c.estado === 'publicada'"
                                @click="estado(c, 'en_revision')"
                                :disabled="ocupado"
                                class="text-blue-800"
                            >
                                Cerrar recepción</button
                            ><button
                                @click="
                                    filtro = '';
                                    verSolicitudes(c);
                                "
                                :disabled="ocupado"
                                class="text-blue-800"
                            >
                                Revisar solicitudes</button
                            ><button
                                v-if="c.revision_abierta"
                                @click="estado(c, 'finalizada')"
                                :disabled="ocupado"
                                class="text-blue-800"
                            >
                                Finalizar dictámenes</button
                            ><button
                                v-if="
                                    [
                                        'borrador',
                                        'publicada',
                                        'en_revision',
                                    ].includes(c.estado)
                                "
                                @click="estado(c, 'cancelada')"
                                :disabled="ocupado"
                                class="text-red-700"
                            >
                                Cancelar convocatoria
                            </button>
                        </div>
                        <div v-else class="mt-auto">
                            <Link
                                v-if="c.mi_solicitud"
                                :href="`/modulo6/becas/solicitudes/${c.mi_solicitud.id}`"
                                class="block text-center rounded-lg border px-4 py-2 text-blue-900 font-semibold"
                                >Ver mi solicitud ·
                                {{
                                    c.mi_solicitud.estado.replaceAll("_", " ")
                                }}</Link
                            ><button
                                v-else
                                @click="solicitar(c)"
                                :disabled="ocupado || !c.recepcion_abierta"
                                class="w-full rounded-lg bg-blue-900 text-white px-4 py-2 disabled:opacity-50"
                            >
                                {{
                                    c.recepcion_abierta
                                        ? "Iniciar solicitud"
                                        : "Recepción no disponible"
                                }}
                            </button>
                        </div>
                    </article>
                </div>
                <p
                    v-if="!datos.convocatorias.length && !cargando"
                    class="rounded-xl border bg-white p-8 text-center"
                >
                    No hay convocatorias que coincidan con tu búsqueda.
                </p></template
            >
            <div
                v-if="paginado?.last_page > 1"
                class="flex justify-center gap-4"
            >
                <button
                    :disabled="paginado.page === 1 || cargando"
                    @click="cargar(paginado.page - 1)"
                >
                    Anterior</button
                ><span>{{ paginado.page }} / {{ paginado.last_page }}</span
                ><button
                    :disabled="paginado.page === paginado.last_page || cargando"
                    @click="cargar(paginado.page + 1)"
                >
                    Siguiente
                </button>
            </div>
            <section
                v-if="seleccionada && modo === 'gestion'"
                class="rounded-xl border bg-white p-5 space-y-4"
            >
                <div class="flex justify-between gap-3">
                    <h2 class="text-xl font-semibold">
                        Solicitudes: {{ seleccionada.titulo }}
                    </h2>
                    <button @click="seleccionada = null">Cerrar</button>
                </div>
                <p class="text-sm text-gray-600">
                    Los borradores son privados. El dictamen se habilita cuando
                    cierra la recepción.
                </p>
                <select
                    v-model="filtro"
                    @change="verSolicitudes(seleccionada)"
                    aria-label="Filtrar solicitudes"
                    class="rounded-lg border-gray-300"
                >
                    <option value="">Todos los estados</option>
                    <option
                        v-for="s in [
                            'pendiente',
                            'en_revision',
                            'aprobada',
                            'rechazada',
                            'retirada',
                            'cancelada',
                        ]"
                        :key="s"
                        :value="s"
                    >
                        {{ s.replaceAll("_", " ") }}
                    </option>
                </select>
                <div class="overflow-x-auto">
                    <table class="w-full text-sm text-left">
                        <thead>
                            <tr class="border-b text-gray-500">
                                <th class="py-3 pr-3">Estudiante</th>
                                <th class="pr-3">Matrícula</th>
                                <th class="pr-3">Folio</th>
                                <th class="pr-3">Estado</th>
                                <th>Expediente</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y">
                            <tr v-for="s in lista?.solicitudes" :key="s.id">
                                <td class="py-3 pr-3">{{ s.nombre }}</td>
                                <td class="pr-3">{{ s.matricula || "—" }}</td>
                                <td class="pr-3">{{ s.folio }}</td>
                                <td class="pr-3">
                                    {{ s.estado.replaceAll("_", " ") }}
                                </td>
                                <td>
                                    <Link
                                        :href="`/modulo6/becas/solicitudes/${s.id}`"
                                        class="text-blue-800 font-semibold"
                                        >Revisar expediente</Link
                                    >
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <p v-if="lista && !lista.solicitudes.length">
                    No hay solicitudes en este estado.
                </p>
                <div v-if="lista?.last_page > 1" class="flex gap-4">
                    <button
                        :disabled="lista.page === 1"
                        @click="verSolicitudes(seleccionada, lista.page - 1)"
                    >
                        Anterior</button
                    ><span>{{ lista.page }} / {{ lista.last_page }}</span
                    ><button
                        :disabled="lista.page === lista.last_page"
                        @click="verSolicitudes(seleccionada, lista.page + 1)"
                    >
                        Siguiente
                    </button>
                </div>
            </section>
        </div>
        <PanelLateral
            :show="panel"
            :titulo="form.id ? 'Editar convocatoria' : 'Nueva convocatoria'"
            @close="!ocupado && (panel = false)"
            ><p
                v-if="error"
                role="alert"
                class="rounded-lg bg-red-50 text-red-800 p-3 mb-4"
            >
                {{ error }}
            </p>
            <form id="form-beca" @submit.prevent="guardar" class="space-y-4">
                <p class="text-sm text-gray-500">
                    Se guarda como borrador. Tras publicarla, sus condiciones
                    quedan fijas. Las fechas usan horario de Ciudad de México y
                    el cierre incluye todo el día indicado.
                </p>
                <div>
                    <label for="beca-titulo">Título</label
                    ><input
                        id="beca-titulo"
                        v-model="form.titulo"
                        required
                        maxlength="150"
                        class="w-full rounded-lg border-gray-300"
                    />
                </div>
                <div>
                    <label for="beca-descripcion">Descripción</label
                    ><textarea
                        id="beca-descripcion"
                        v-model="form.descripcion"
                        required
                        maxlength="5000"
                        class="w-full rounded-lg border-gray-300"
                        rows="3"
                    ></textarea>
                </div>
                <div>
                    <label for="beca-requisitos"
                        >Requisitos y condiciones de uso</label
                    ><textarea
                        id="beca-requisitos"
                        v-model="form.requisitos"
                        required
                        maxlength="5000"
                        class="w-full rounded-lg border-gray-300"
                        rows="3"
                    ></textarea>
                </div>
                <div>
                    <label for="beca-tipo">Tipo de beneficio</label
                    ><select
                        id="beca-tipo"
                        v-model="form.tipo_beneficio_id"
                        class="w-full rounded-lg border-gray-300"
                    >
                        <option v-for="t in tipos" :key="t.id" :value="t.id">
                            {{ t.nombre }}
                        </option>
                    </select>
                </div>
                <div v-if="monetario">
                    <label for="beca-monto">Monto por persona (MXN)</label
                    ><input
                        id="beca-monto"
                        v-model="form.monto"
                        required
                        type="number"
                        min="0.01"
                        max="999999.99"
                        step="0.01"
                        class="w-full rounded-lg border-gray-300"
                    />
                </div>
                <div v-else>
                    <label for="beca-cantidad">Unidades por persona</label
                    ><input
                        id="beca-cantidad"
                        v-model.number="form.cantidad"
                        required
                        type="number"
                        min="1"
                        max="10000"
                        class="w-full rounded-lg border-gray-300"
                    />
                </div>
                <div>
                    <label for="beca-cupo">Número de beneficiarios</label
                    ><input
                        id="beca-cupo"
                        v-model.number="form.total_espacios"
                        required
                        type="number"
                        min="1"
                        max="10000"
                        class="w-full rounded-lg border-gray-300"
                    />
                </div>
                <div
                    v-for="c in [
                        { id: 'fecha_inicio', label: 'Apertura de recepción' },
                        { id: 'fecha_fin', label: 'Último día de recepción' },
                        {
                            id: 'vigencia_inicio',
                            label: 'Inicio del beneficio',
                        },
                        {
                            id: 'vigencia_fin',
                            label: 'Último día del beneficio',
                        },
                    ]"
                    :key="c.id"
                >
                    <label :for="`beca-${c.id}`">{{ c.label }}</label
                    ><input
                        :id="`beca-${c.id}`"
                        v-model="form[c.id]"
                        required
                        type="date"
                        class="w-full rounded-lg border-gray-300"
                    />
                </div>
                <div>
                    <label for="beca-documentos"
                        >Documentos obligatorios (uno por línea, máximo
                        cinco)</label
                    ><textarea
                        id="beca-documentos"
                        v-model="documentos"
                        rows="3"
                        class="w-full rounded-lg border-gray-300"
                        placeholder="Constancia de estudios"
                    ></textarea>
                </div>
                <p class="text-sm text-amber-800">
                    Aprobar una solicitud reserva un apoyo. La entrega se
                    confirmará cuando esté integrado el equipo responsable.
                </p>
            </form>
            <template #footer
                ><button
                    type="submit"
                    form="form-beca"
                    :disabled="ocupado"
                    class="rounded-lg bg-blue-900 text-white px-4 py-2 disabled:opacity-50"
                >
                    {{
                        ocupado
                            ? "Guardando…"
                            : form.id
                              ? "Guardar cambios"
                              : "Guardar borrador"
                    }}
                </button></template
            ></PanelLateral
        >
    </Modulo6Layout>
</template>
