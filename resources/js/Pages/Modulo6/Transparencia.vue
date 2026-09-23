<script setup>
import { Head, Link, usePage } from "@inertiajs/vue3";
import { ref, onMounted } from "vue";
import axios from "axios";
import Modulo6Layout from "@/Layouts/Modulo6Layout.vue";
import PanelLateral from "@/Components/Panellateral.vue";
import MetricasReporte from "@/Components/MetricasReporte.vue";
const page = usePage();
const datos = ref(null),
    detalle = ref(null),
    panel = ref(false),
    form = ref({}),
    buscar = ref(""),
    error = ref(""),
    aviso = ref(""),
    cargando = ref(false),
    ocupado = ref(false);
const fecha = (v) =>
    v
        ? new Date(v).toLocaleString("es-MX", {
              timeZone: "America/Mexico_City",
              dateStyle: "medium",
              timeStyle: "short",
          })
        : "";
const dia = () =>
    new Intl.DateTimeFormat("en-CA", {
        timeZone: "America/Mexico_City",
        year: "numeric",
        month: "2-digit",
        day: "2-digit",
    }).format(new Date());
function fallar(e) {
    error.value =
        Object.values(e.response?.data?.errors ?? {})
            .flat()
            .join(" ") ||
        e.response?.data?.message ||
        "No fue posible conectar con el servidor. Intenta de nuevo.";
}
async function cargar(p = datos.value?.page ?? 1) {
    if (cargando.value || !page.props.auth.organizacion) return;
    cargando.value = true;
    try {
        datos.value = (
            await axios.get("/api/transparencia", {
                params: { page: p, buscar: buscar.value },
            })
        ).data;
    } catch (e) {
        fallar(e);
    } finally {
        cargando.value = false;
    }
}
onMounted(() => cargar(1));
async function ejecutar(fn) {
    if (ocupado.value) return;
    ocupado.value = true;
    error.value = "";
    aviso.value = "";
    try {
        const { data } = await fn();
        aviso.value = data.message ?? "";
        return data;
    } catch (e) {
        fallar(e);
    } finally {
        ocupado.value = false;
    }
}
function nuevo() {
    error.value = "";
    const hoy = dia();
    form.value = {
        titulo: "",
        descripcion: "",
        fecha_inicio: hoy.slice(0, 7) + "-01",
        fecha_fin: hoy,
        clave_solicitud: crypto.randomUUID(),
    };
    panel.value = true;
}
async function generar() {
    const d = await ejecutar(() =>
        axios.post("/api/transparencia/reportes", form.value),
    );
    if (d) {
        detalle.value = d.reporte;
        panel.value = false;
        await cargar(1);
    }
}
async function abrir(r) {
    const d = await ejecutar(() =>
        axios.get(`/api/transparencia/reportes/${r.id}`),
    );
    if (d) detalle.value = d;
}
async function publicar() {
    const d = await ejecutar(async () => {
        const res = await axios.post(
            `/api/transparencia/reportes/${detalle.value.id}/publicar`,
            { confirmar: true },
        );
        const reporte = (
            await axios.get(`/api/transparencia/reportes/${detalle.value.id}`)
        ).data;
        return { data: { ...res.data, reporte } };
    });
    if (d) {
        detalle.value = d.reporte;
        await cargar();
    }
}
async function retirar() {
    const motivo = prompt(
        "Motivo de retiro (mínimo 10 caracteres). El reporte dejará de estar disponible para los integrantes.",
    );
    if (motivo === null) return;
    const d = await ejecutar(() =>
        axios.post(`/api/transparencia/reportes/${detalle.value.id}/retirar`, {
            motivo,
        }),
    );
    if (d) {
        detalle.value = null;
        await cargar();
    }
}
</script>
<template>
    <Head title="Transparencia — Campus Digital" />
    <Modulo6Layout headerTitle="Transparencia y resultados"
        ><div class="campus-page space-y-6">
            <div class="flex flex-wrap justify-between gap-3 items-center">
                <div>
                    <h1 class="text-2xl font-bold">
                        Transparencia y resultados
                    </h1>
                    <p class="text-sm text-gray-600 mt-1">
                        Reportes para los integrantes de tu organización.
                    </p>
                </div>
                <button
                    v-if="page.props.auth.puede_editar"
                    @click="nuevo"
                    :disabled="ocupado"
                    class="rounded-lg bg-blue-900 text-white px-4 py-2"
                >
                    Nuevo reporte
                </button>
            </div>
            <p
                v-if="!page.props.auth.organizacion"
                class="bg-white border rounded-xl p-6"
            >
                Necesitas una membresía activa para consultar los reportes de
                una organización.
            </p>
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
            <template v-if="page.props.auth.organizacion">
                <p class="text-sm text-gray-600">
                    Cada reporte conserva las cifras al generarse. Las
                    aprobaciones de becas y los pagos pendientes no representan
                    apoyos entregados ni dinero cobrado.
                </p>
                <section
                    v-if="detalle"
                    class="rounded-xl border-2 border-blue-200 bg-white p-5 md:p-6 space-y-5"
                >
                    <div class="flex justify-between gap-3">
                        <h2 class="text-xl font-bold break-words min-w-0">
                            {{ detalle.titulo }}
                        </h2>
                        <button
                            @click="detalle = null"
                            :disabled="ocupado"
                            aria-label="Cerrar reporte"
                        >
                            Cerrar
                        </button>
                    </div>
                    <p class="text-sm text-gray-600">
                        {{ detalle.estado }} · {{ detalle.fecha_inicio }} al
                        {{ detalle.fecha_fin }} (ambos inclusive, Ciudad de
                        México)<br />Generado: {{ fecha(detalle.generado_en) }}
                    </p>
                    <p
                        v-if="detalle.descripcion"
                        class="whitespace-pre-line break-words"
                    >
                        {{ detalle.descripcion }}
                    </p>
                    <p
                        v-if="detalle.motivo_retiro"
                        class="text-amber-800 break-words"
                    >
                        Retirado: {{ detalle.motivo_retiro }}
                    </p>
                    <div class="flex flex-wrap gap-3 text-sm font-semibold">
                        <a
                            :href="`/api/transparencia/reportes/${detalle.id}/csv`"
                            class="text-blue-800"
                            >Descargar CSV</a
                        ><a
                            :href="`/api/transparencia/reportes/${detalle.id}/imprimir`"
                            target="_blank"
                            rel="noopener"
                            class="text-blue-800"
                            >Imprimir / guardar PDF</a
                        ><button
                            v-if="
                                datos?.puede_gestionar &&
                                detalle.estado !== 'retirado'
                            "
                            @click="retirar"
                            :disabled="ocupado"
                            class="text-red-700"
                        >
                            {{
                                detalle.estado === "borrador"
                                    ? "Descartar borrador"
                                    : "Retirar publicación"
                            }}
                        </button>
                    </div>
                    <MetricasReporte :metricas="detalle.metricas" />
                    <div
                        v-if="
                            datos?.puede_gestionar &&
                            detalle.estado === 'borrador'
                        "
                        class="border-t pt-4 space-y-3"
                    >
                        <p class="text-sm">
                            Revisa las cifras, sus criterios y el contexto
                            escrito antes de publicar. Usa únicamente contexto
                            general, sin nombres ni datos personales. Para
                            cambiar el periodo o recalcular, genera otro
                            borrador.
                        </p>
                        <button
                            @click="publicar"
                            :disabled="ocupado"
                            class="rounded-lg bg-blue-900 text-white px-4 py-2"
                        >
                            Confirmar publicación
                        </button>
                    </div>
                </section>
                <section class="space-y-4">
                    <h2 class="text-xl font-bold">
                        {{
                            datos?.puede_gestionar
                                ? "Reportes de la organización"
                                : "Reportes publicados"
                        }}
                    </h2>
                    <form @submit.prevent="cargar(1)" class="flex gap-3">
                        <input
                            v-model="buscar"
                            aria-label="Buscar reportes"
                            maxlength="120"
                            placeholder="Buscar por título"
                            class="min-w-0 flex-1 rounded-lg border-gray-300"
                        /><button
                            :disabled="cargando"
                            class="border rounded-lg bg-white px-4 py-2"
                        >
                            Buscar
                        </button>
                    </form>
                    <p v-if="cargando">Cargando reportes…</p>
                    <div v-if="datos" class="grid md:grid-cols-2 gap-4">
                        <article
                            v-for="r in datos.reportes"
                            :key="r.id"
                            class="bg-white border rounded-xl p-5 space-y-3 min-w-0"
                        >
                            <h3 class="text-lg font-bold break-words">
                                {{ r.titulo }}
                            </h3>
                            <p class="text-sm text-gray-600">
                                {{ r.fecha_inicio }} al {{ r.fecha_fin }} ·
                                {{ r.estado }}<br />Generado:
                                {{ fecha(r.generado_en) }}
                            </p>
                            <button
                                @click="abrir(r)"
                                :disabled="ocupado"
                                class="text-blue-800 font-semibold"
                            >
                                Ver reporte
                            </button>
                        </article>
                    </div>
                    <p
                        v-if="datos && !datos.reportes.length && !cargando"
                        class="border bg-white rounded-xl p-6 text-gray-500"
                    >
                        No hay reportes disponibles para esta búsqueda.
                    </p>
                    <div
                        v-if="datos?.last_page > 1"
                        class="flex gap-4 justify-center"
                    >
                        <button
                            @click="cargar(datos.page - 1)"
                            :disabled="cargando || datos.page === 1"
                        >
                            Anterior</button
                        ><span>{{ datos.page }} / {{ datos.last_page }}</span
                        ><button
                            @click="cargar(datos.page + 1)"
                            :disabled="
                                cargando || datos.page === datos.last_page
                            "
                        >
                            Siguiente
                        </button>
                    </div>
                </section>
                <section
                    v-if="datos"
                    class="rounded-xl border bg-white p-5 space-y-4"
                >
                    <div class="flex flex-wrap justify-between gap-3">
                        <h2 class="text-xl font-bold">
                            Última votación cerrada
                        </h2>
                        <Link href="/modulo6/votaciones" class="text-blue-800"
                            >Ir a votaciones</Link
                        >
                    </div>
                    <template v-if="datos.eleccion"
                        ><h3 class="font-semibold break-words">
                            {{ datos.eleccion.titulo }}
                        </h3>
                        <p class="text-sm text-gray-600">
                            {{ datos.totalVotos }} votos ·
                            {{ datos.eleccion.criterios_votantes }}
                        </p>
                        <div
                            v-for="opcion in datos.resultados"
                            :key="opcion.id"
                        >
                            <div
                                class="flex flex-wrap justify-between gap-2 text-sm"
                            >
                                <span class="break-words">{{
                                    opcion.planilla
                                }}</span
                                ><span
                                    >{{ opcion.votos }} ·
                                    {{ opcion.porcentaje }}%</span
                                >
                            </div>
                            <div
                                class="h-2 rounded-full bg-gray-100 mt-1 overflow-hidden"
                            >
                                <div
                                    class="h-2 bg-blue-800"
                                    :style="{ width: opcion.porcentaje + '%' }"
                                ></div>
                            </div>
                        </div>
                        <p class="text-xs text-gray-500">
                            Los conteos incluyen empates y no asignan cargos
                            automáticamente.
                        </p></template
                    >
                    <p v-else class="text-gray-500">
                        No hay votaciones cerradas.
                    </p>
                </section>
            </template>
        </div>
        <PanelLateral
            :show="panel"
            titulo="Nuevo reporte"
            @close="!ocupado && (panel = false)"
            ><p
                v-if="error"
                role="alert"
                class="bg-red-50 text-red-800 rounded-lg p-3 mb-4"
            >
                {{ error }}
            </p>
            <form id="form-reporte" @submit.prevent="generar">
                <fieldset :disabled="ocupado" class="space-y-4">
                    <div>
                        <label for="reporte-titulo">Título</label
                        ><input
                            id="reporte-titulo"
                            v-model="form.titulo"
                            required
                            maxlength="150"
                            class="w-full rounded-lg border-gray-300"
                        />
                    </div>
                    <div>
                        <label for="reporte-descripcion"
                            >Contexto general (opcional)</label
                        ><textarea
                            id="reporte-descripcion"
                            v-model="form.descripcion"
                            maxlength="1500"
                            rows="4"
                            class="w-full rounded-lg border-gray-300"
                        ></textarea>
                        <p class="text-xs text-gray-500">
                            Evita nombres, matrículas y detalles de expedientes.
                        </p>
                    </div>
                    <div>
                        <label for="reporte-inicio">Fecha de inicio</label
                        ><input
                            id="reporte-inicio"
                            v-model="form.fecha_inicio"
                            type="date"
                            :max="dia()"
                            required
                            class="w-full rounded-lg border-gray-300"
                        />
                    </div>
                    <div>
                        <label for="reporte-fin">Fecha de fin (inclusive)</label
                        ><input
                            id="reporte-fin"
                            v-model="form.fecha_fin"
                            type="date"
                            :min="form.fecha_inicio"
                            :max="dia()"
                            required
                            class="w-full rounded-lg border-gray-300"
                        />
                    </div>
                    <p class="text-sm text-gray-600">
                        Máximo 366 días. Las cifras se calculan al generar y se
                        guardan para revisión; la publicación es un paso
                        posterior.
                    </p>
                </fieldset>
            </form>
            <template #footer
                ><button
                    type="submit"
                    form="form-reporte"
                    :disabled="ocupado"
                    class="rounded-lg bg-blue-900 text-white px-4 py-2"
                >
                    {{ ocupado ? "Generando…" : "Generar borrador" }}
                </button></template
            ></PanelLateral
        >
    </Modulo6Layout>
</template>
