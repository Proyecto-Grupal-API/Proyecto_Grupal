<script setup>
import { Head, Link, usePage } from "@inertiajs/vue3";
import { ref, computed, onMounted, onBeforeUnmount } from "vue";
import axios from "axios";
import Modulo6Layout from "@/Layouts/Modulo6Layout.vue";
import PanelLateral from "@/Components/Panellateral.vue";
const page = usePage();
const datos = ref(null),
    form = ref({}),
    panel = ref(false),
    preview = ref(null),
    cargando = ref(false),
    ocupado = ref(false),
    error = ref(""),
    aviso = ref(""),
    buscar = ref("");
const audiencias = [
    { id: "miembros", nombre: "Integrantes activos" },
    { id: "evento_confirmados", nombre: "Evento: reservas confirmadas" },
    { id: "evento_espera", nombre: "Evento: lista de espera" },
    { id: "beca_solicitudes", nombre: "Beca: solicitudes enviadas" },
    { id: "beca_aprobadas", nombre: "Beca: solicitudes aprobadas" },
];
const referencias = computed(() =>
    form.value.audiencia?.startsWith("evento_")
        ? (datos.value?.eventos ?? [])
        : (datos.value?.becas ?? []),
);
const acciones = computed(() => [
    { id: "ninguna", nombre: "Sin botón" },
    { id: "eventos", nombre: "Explorar eventos" },
    { id: "becas", nombre: "Explorar becas" },
    { id: "mis_boletos", nombre: "Mis boletos" },
    ...(form.value.audiencia?.startsWith("evento_")
        ? [{ id: "boleto_evento", nombre: "Boleto del evento" }]
        : []),
    ...(form.value.audiencia?.startsWith("beca_")
        ? [{ id: "solicitud_beca", nombre: "Solicitud personal de esta beca" }]
        : []),
]);
const etiqueta = (id) => audiencias.find((a) => a.id === id)?.nombre ?? id;
let timer;
function fallar(e) {
    error.value =
        Object.values(e.response?.data?.errors ?? {})
            .flat()
            .join(" ") ||
        e.response?.data?.message ||
        "No se pudo conectar con el servidor.";
}
async function cargar(p = datos.value?.page ?? 1, silencioso = false) {
    if (cargando.value) return;
    cargando.value = true;
    if (!silencioso) error.value = "";
    try {
        datos.value = (
            await axios.get("/api/comunicacion", {
                params: { page: p, buscar: buscar.value },
            })
        ).data;
    } catch (e) {
        if (!silencioso) fallar(e);
    } finally {
        cargando.value = false;
    }
}
onMounted(() => {
    if (page.props.auth.puede_editar) {
        cargar();
        timer = setInterval(() => {
            if (
                !document.hidden &&
                !ocupado.value &&
                !panel.value &&
                !preview.value
            )
                cargar(undefined, true);
        }, 5000);
    }
});
onBeforeUnmount(() => clearInterval(timer));
function abrir(c = null) {
    preview.value = null;
    error.value = "";
    form.value = c
        ? {
              id: c.id,
              nombre: c.nombre,
              asunto: c.asunto,
              cuerpo: c.cuerpo,
              audiencia: c.audiencia,
              referencia_id: c.referencia_id ?? "",
              accion: c.accion,
              texto_accion: c.texto_accion ?? "",
          }
        : {
              nombre: "",
              asunto: "",
              cuerpo: "",
              audiencia: "miembros",
              referencia_id: "",
              accion: "ninguna",
              texto_accion: "",
          };
    panel.value = true;
}
function audienciaCambio() {
    form.value.referencia_id = "";
    form.value.accion = "ninguna";
    form.value.texto_accion = "";
}
async function ejecutar(fn) {
    if (ocupado.value) return;
    ocupado.value = true;
    error.value = "";
    aviso.value = "";
    try {
        const { data } = await fn();
        aviso.value = data.message ?? "";
        await cargar();
        return data;
    } catch (e) {
        fallar(e);
    } finally {
        ocupado.value = false;
    }
}
async function guardar() {
    const d = await ejecutar(() =>
        form.value.id
            ? axios.put(`/api/comunicacion/${form.value.id}`, form.value)
            : axios.post("/api/comunicacion", form.value),
    );
    if (d) panel.value = false;
}
async function previsualizar(c) {
    const d = await ejecutar(() =>
        axios.post(`/api/comunicacion/${c.id}/preview`),
    );
    if (d) preview.value = d;
}
async function enviar() {
    const d = await ejecutar(() =>
        axios.post(`/api/comunicacion/${preview.value.campana.id}/enviar`, {
            firma: preview.value.firma,
        }),
    );
    if (d) preview.value = null;
    else if (error.value.includes("vista previa")) preview.value = null;
}
function cancelar(c) {
    const motivo = prompt(
        "Motivo de cancelación (mínimo 10 caracteres). Los mensajes ya entregados no se retiran.",
    );
    if (motivo !== null)
        ejecutar(() =>
            axios.post(`/api/comunicacion/${c.id}/cancelar`, { motivo }),
        );
}
</script>
<template>
    <Head title="Comunicación — Campus Digital" />
    <Modulo6Layout headerTitle="Comunicación"
        ><div class="campus-page space-y-5">
            <div class="flex flex-wrap justify-between items-center gap-3">
                <div>
                    <h1 class="text-2xl font-bold text-gray-900">
                        Campañas de tu organización
                    </h1>
                    <p class="text-sm text-gray-600 mt-1">
                        Mensajes internos para las personas vinculadas a tus
                        actividades.
                    </p>
                </div>
                <div class="flex gap-3">
                    <Link
                        href="/modulo6/bandeja"
                        class="rounded-lg border bg-white px-4 py-2"
                        >Mi bandeja</Link
                    ><button
                        v-if="page.props.auth.puede_editar"
                        @click="abrir()"
                        :disabled="ocupado"
                        class="rounded-lg bg-blue-900 text-white px-4 py-2"
                    >
                        Nueva campaña
                    </button>
                </div>
            </div>
            <p
                v-if="!page.props.auth.puede_editar"
                class="rounded-xl border bg-white p-6"
            >
                La presidencia de tu organización administra las campañas.
                Puedes consultar tus mensajes y preferencias en Mi bandeja.
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
            <template v-if="page.props.auth.puede_editar"
                ><form @submit.prevent="cargar(1)" class="flex gap-3">
                    <input
                        v-model="buscar"
                        aria-label="Buscar campañas"
                        placeholder="Buscar por nombre interno"
                        maxlength="120"
                        class="rounded-lg border-gray-300 min-w-0 flex-1"
                    /><button
                        :disabled="cargando"
                        class="rounded-lg border bg-white px-4"
                    >
                        Buscar
                    </button>
                </form>
                <p v-if="cargando && !datos">Cargando campañas…</p>
                <section
                    v-if="preview"
                    class="rounded-xl border-2 border-blue-200 bg-white p-6 space-y-4"
                >
                    <div class="flex justify-between gap-3">
                        <h2 class="font-bold text-xl">
                            Revisar antes de enviar
                        </h2>
                        <button @click="preview = null" :disabled="ocupado">
                            Cerrar vista previa
                        </button>
                    </div>
                    <p class="text-sm text-gray-600">
                        {{ etiqueta(preview.campana.audiencia) }} ·
                        <strong
                            >{{ preview.destinatarios }} destinatarios</strong
                        >
                    </p>
                    <h3 class="text-xl font-semibold">
                        {{ preview.campana.asunto }}
                    </h3>
                    <p class="whitespace-pre-line break-words">
                        {{ preview.campana.cuerpo }}
                    </p>
                    <span
                        v-if="preview.campana.accion !== 'ninguna'"
                        class="inline-block rounded-lg border px-4 py-2 text-blue-900"
                        >{{ preview.campana.texto_accion }}</span
                    >
                    <p class="text-sm text-gray-500">
                        Se enviará a la bandeja interna. Se excluyen cuentas
                        eliminadas y organizaciones silenciadas por el
                        destinatario. Cada persona recibe un solo mensaje.
                    </p>
                    <button
                        @click="enviar"
                        :disabled="ocupado || !preview.destinatarios"
                        class="rounded-lg bg-blue-900 text-white px-4 py-2 disabled:opacity-50"
                    >
                        Confirmar envío a {{ preview.destinatarios }} personas
                    </button>
                </section>
                <div
                    v-if="datos"
                    class="grid md:grid-cols-2 xl:grid-cols-3 gap-5"
                >
                    <article
                        v-for="c in datos.campanas"
                        :key="c.id"
                        class="rounded-xl border bg-white p-5 space-y-3"
                    >
                        <div class="flex justify-between gap-3">
                            <h2 class="text-lg font-bold break-words">
                                {{ c.nombre }}
                            </h2>
                            <span class="text-xs text-blue-800">{{
                                c.estado.replaceAll("_", " ")
                            }}</span>
                        </div>
                        <p class="text-sm font-medium">{{ c.asunto }}</p>
                        <p class="text-sm text-gray-500">
                            {{ etiqueta(c.audiencia) }}
                        </p>
                        <div class="text-sm space-y-1">
                            <p>
                                {{ c.total_enviados }} entregados ·
                                {{ c.total_leidos }} leídos al menos una vez
                            </p>
                            <p v-if="c.total_destinatarios">
                                {{ c.procesados ?? 0 }} /
                                {{ c.total_destinatarios }} procesados ·
                                {{ c.omitidos ?? 0 }} omitidos
                            </p>
                        </div>
                        <p v-if="c.error" class="text-sm text-amber-800">
                            {{ c.error }}
                        </p>
                        <p
                            v-if="c.motivo_cancelacion"
                            class="text-sm text-gray-500"
                        >
                            {{ c.motivo_cancelacion }}
                        </p>
                        <div class="flex flex-wrap gap-3 text-sm font-semibold">
                            <template v-if="c.estado === 'borrador'"
                                ><button
                                    @click="abrir(c)"
                                    :disabled="ocupado"
                                    class="text-blue-800"
                                >
                                    Editar</button
                                ><button
                                    @click="previsualizar(c)"
                                    :disabled="ocupado"
                                    class="text-blue-800"
                                >
                                    Vista previa
                                </button></template
                            ><button
                                v-if="
                                    [
                                        'en_cola',
                                        'enviando',
                                        'error',
                                        'pausada',
                                    ].includes(c.estado)
                                "
                                @click="
                                    ejecutar(() =>
                                        axios.post(
                                            `/api/comunicacion/${c.id}/reintentar`,
                                        ),
                                    )
                                "
                                :disabled="ocupado"
                                class="text-blue-800"
                            >
                                Reintentar pendientes</button
                            ><button
                                v-if="
                                    !['enviada', 'cancelada'].includes(c.estado)
                                "
                                @click="cancelar(c)"
                                :disabled="ocupado"
                                class="text-red-700"
                            >
                                Cancelar campaña
                            </button>
                        </div>
                    </article>
                </div>
                <p
                    v-if="datos && !datos.campanas.length && !cargando"
                    class="rounded-xl border bg-white text-center p-8"
                >
                    No hay campañas que coincidan con la búsqueda.
                </p>
                <div
                    v-if="datos?.last_page > 1"
                    class="flex justify-center gap-4"
                >
                    <button
                        :disabled="datos.page === 1 || cargando"
                        @click="cargar(datos.page - 1)"
                    >
                        Anterior</button
                    ><span>{{ datos.page }} / {{ datos.last_page }}</span
                    ><button
                        :disabled="datos.page === datos.last_page || cargando"
                        @click="cargar(datos.page + 1)"
                    >
                        Siguiente
                    </button>
                </div></template
            >
        </div>
        <PanelLateral
            :show="panel"
            :titulo="form.id ? 'Editar campaña' : 'Nueva campaña'"
            @close="!ocupado && (panel = false)"
            ><p
                v-if="error"
                role="alert"
                class="rounded-lg bg-red-50 text-red-800 p-3 mb-3"
            >
                {{ error }}
            </p>
            <form id="form-campana" @submit.prevent="guardar" class="space-y-4">
                <div>
                    <label for="campana-nombre">Nombre interno</label
                    ><input
                        id="campana-nombre"
                        v-model="form.nombre"
                        required
                        maxlength="150"
                        class="rounded-lg border-gray-300 w-full"
                    />
                </div>
                <div>
                    <label for="campana-audiencia">Audiencia</label
                    ><select
                        id="campana-audiencia"
                        v-model="form.audiencia"
                        @change="audienciaCambio"
                        class="rounded-lg border-gray-300 w-full"
                    >
                        <option
                            v-for="a in audiencias"
                            :key="a.id"
                            :value="a.id"
                        >
                            {{ a.nombre }}
                        </option>
                    </select>
                </div>
                <div v-if="form.audiencia !== 'miembros'">
                    <label for="campana-referencia">{{
                        form.audiencia.startsWith("evento_")
                            ? "Evento"
                            : "Convocatoria"
                    }}</label
                    ><select
                        id="campana-referencia"
                        v-model="form.referencia_id"
                        required
                        class="rounded-lg border-gray-300 w-full"
                    >
                        <option value="">Selecciona una opción</option>
                        <option
                            v-for="r in referencias"
                            :key="r.id"
                            :value="r.id"
                        >
                            {{ r.titulo }}
                        </option>
                    </select>
                </div>
                <div>
                    <label for="campana-asunto">Asunto</label
                    ><input
                        id="campana-asunto"
                        v-model="form.asunto"
                        required
                        maxlength="150"
                        class="rounded-lg border-gray-300 w-full"
                    />
                </div>
                <div>
                    <label for="campana-cuerpo">Mensaje</label
                    ><textarea
                        id="campana-cuerpo"
                        v-model="form.cuerpo"
                        rows="6"
                        required
                        minlength="10"
                        maxlength="6000"
                        class="rounded-lg border-gray-300 w-full"
                    ></textarea>
                </div>
                <div>
                    <label for="campana-accion">Acción del mensaje</label
                    ><select
                        id="campana-accion"
                        v-model="form.accion"
                        class="rounded-lg border-gray-300 w-full"
                    >
                        <option v-for="a in acciones" :key="a.id" :value="a.id">
                            {{ a.nombre }}
                        </option>
                    </select>
                </div>
                <div v-if="form.accion !== 'ninguna'">
                    <label for="campana-texto">Texto del botón</label
                    ><input
                        id="campana-texto"
                        v-model="form.texto_accion"
                        required
                        maxlength="60"
                        class="rounded-lg border-gray-300 w-full"
                    />
                </div>
                <p class="text-sm text-gray-500">
                    Guarda primero el borrador. Después podrás revisar la
                    audiencia y confirmar el envío.
                </p>
            </form>
            <template #footer
                ><button
                    form="form-campana"
                    type="submit"
                    :disabled="ocupado"
                    class="rounded-lg bg-blue-900 text-white px-4 py-2"
                >
                    {{ ocupado ? "Guardando…" : "Guardar borrador" }}
                </button></template
            ></PanelLateral
        >
    </Modulo6Layout>
</template>
