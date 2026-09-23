<script setup>
import { Head, Link } from "@inertiajs/vue3";
import { ref, onMounted } from "vue";
import axios from "axios";
import Modulo6Layout from "@/Layouts/Modulo6Layout.vue";
const props = defineProps({ solicitudId: String });
const datos = ref(null),
    motivacion = ref(""),
    motivo = ref(""),
    decision = ref("en_revision"),
    requisito = ref(""),
    archivo = ref(null),
    entrada = ref(null),
    cargando = ref(false),
    ocupado = ref(false),
    error = ref(""),
    aviso = ref("");
function fallar(e) {
    error.value =
        Object.values(e.response?.data?.errors ?? {})
            .flat()
            .join(" ") ||
        e.response?.data?.message ||
        "No se pudo conectar con el servidor.";
}
async function cargar() {
    cargando.value = true;
    try {
        datos.value = (
            await axios.get(`/api/becas/solicitudes/${props.solicitudId}`)
        ).data;
        motivacion.value = datos.value.solicitud.motivacion ?? "";
    } catch (e) {
        fallar(e);
        datos.value = null;
    } finally {
        cargando.value = false;
    }
}
onMounted(cargar);
async function ejecutar(fn) {
    if (ocupado.value) return;
    ocupado.value = true;
    error.value = "";
    aviso.value = "";
    try {
        const { data } = await fn();
        aviso.value = data.message;
        await cargar();
        return true;
    } catch (e) {
        fallar(e);
        return false;
    } finally {
        ocupado.value = false;
    }
}
async function guardar(enviar) {
    if (
        enviar &&
        !confirm(
            "¿Enviar tu solicitud? Después no podrás cambiar el texto ni los documentos.",
        )
    )
        return;
    await ejecutar(() =>
        axios.put(`/api/becas/solicitudes/${props.solicitudId}`, {
            motivacion: motivacion.value,
            enviar,
        }),
    );
}
async function subir() {
    if (!archivo.value) return;
    const texto = motivacion.value;
    const f = new FormData();
    f.append("archivo", archivo.value);
    if (requisito.value !== "") f.append("requisito", requisito.value);
    if (
        await ejecutar(() =>
            axios.post(
                `/api/becas/solicitudes/${props.solicitudId}/documentos`,
                f,
            ),
        )
    ) {
        archivo.value = null;
        entrada.value.value = "";
    }
    motivacion.value = texto;
}
async function eliminar(d) {
    if (!confirm("¿Eliminar este documento del borrador?")) return;
    const texto = motivacion.value;
    await ejecutar(() =>
        axios.delete(
            `/api/becas/solicitudes/${props.solicitudId}/documentos/${d.id}`,
        ),
    );
    motivacion.value = texto;
}
function retirar() {
    if (
        confirm(
            "¿Retirar definitivamente esta solicitud? No podrás volver a solicitar esta convocatoria.",
        )
    )
        ejecutar(() =>
            axios.post(`/api/becas/solicitudes/${props.solicitudId}/retirar`),
        );
}
function dictaminar() {
    if (
        !confirm(
            "¿Guardar el dictamen? Una aprobación o rechazo es definitivo.",
        )
    )
        return;
    ejecutar(() =>
        axios.post(`/api/becas/solicitudes/${props.solicitudId}/dictamen`, {
            estado: decision.value,
            motivo: motivo.value,
        }),
    );
}
</script>
<template>
    <Head title="Solicitud de beca — Campus Digital" />
    <Modulo6Layout headerTitle="Solicitud de beca"
        ><div class="campus-page space-y-5">
            <Link href="/modulo6/becas" class="text-blue-800 font-semibold"
                >← Volver a becas</Link
            >
            <p
                v-if="error"
                role="alert"
                class="rounded-lg bg-red-50 text-red-800 p-4"
            >
                {{ error }}
            </p>
            <p
                v-if="aviso"
                role="status"
                class="rounded-lg bg-green-50 text-green-800 p-4"
            >
                {{ aviso }}
            </p>
            <p v-if="cargando && !datos">Cargando expediente…</p>
            <template v-if="datos"
                ><section class="rounded-xl border bg-white p-5 space-y-3">
                    <p class="text-xs text-gray-500">
                        {{ datos.solicitud.folio }}
                    </p>
                    <h1 class="text-2xl font-bold">
                        {{ datos.convocatoria.titulo }}
                    </h1>
                    <p class="font-semibold text-blue-900">
                        Estado:
                        {{ datos.solicitud.estado.replaceAll("_", " ") }}
                    </p>
                    <p class="whitespace-pre-line text-sm text-gray-600">
                        {{ datos.convocatoria.requisitos }}
                    </p>
                    <p
                        v-if="datos.convocatoria.motivo_cancelacion"
                        class="text-red-700"
                    >
                        {{ datos.convocatoria.motivo_cancelacion }}
                    </p>
                    <p
                        v-if="
                            datos.solicitud.estado === 'borrador' &&
                            !datos.puede_editar
                        "
                        class="text-amber-800 text-sm"
                    >
                        La recepción cerró. Este borrador no se envió y no
                        participa en el dictamen.
                    </p>
                    <div
                        v-if="datos.solicitud.dictamen"
                        class="rounded-lg bg-blue-50 p-4"
                    >
                        <h2 class="font-semibold">Último dictamen</h2>
                        <p class="whitespace-pre-line mt-1">
                            {{ datos.solicitud.dictamen.motivo }}
                        </p>
                    </div>
                    <div
                        v-if="datos.solicitud.estado === 'aprobada'"
                        class="rounded-lg bg-amber-50 p-4 text-amber-900"
                    >
                        <h2 class="font-semibold">
                            Apoyo aprobado · entrega pendiente
                        </h2>
                        <p class="text-sm mt-1">
                            La aprobación reserva tu apoyo. Todavía no se ha
                            acreditado dinero, emitido un bono ni asignado un
                            servicio.
                        </p>
                    </div>
                </section>
                <section class="rounded-xl border bg-white p-5 space-y-4">
                    <h2 class="text-lg font-semibold">
                        Motivo de la solicitud
                    </h2>
                    <form
                        v-if="datos.puede_editar"
                        @submit.prevent="guardar(false)"
                        class="space-y-3"
                    >
                        <label
                            for="solicitud-motivacion"
                            class="text-sm text-gray-600"
                            >Explica por qué solicitas este apoyo (20 a 3000
                            caracteres).</label
                        ><textarea
                            id="solicitud-motivacion"
                            v-model="motivacion"
                            required
                            minlength="20"
                            maxlength="3000"
                            rows="5"
                            class="w-full rounded-lg border-gray-300"
                        ></textarea
                        ><button
                            :disabled="ocupado"
                            class="rounded-lg border px-4 py-2 text-blue-900 font-semibold"
                        >
                            Guardar borrador
                        </button>
                    </form>
                    <p v-else class="whitespace-pre-line text-gray-700">
                        {{
                            datos.solicitud.motivacion || "Sin texto guardado."
                        }}
                    </p>
                </section>
                <section class="rounded-xl border bg-white p-5 space-y-4">
                    <h2 class="text-lg font-semibold">Documentos privados</h2>
                    <p class="text-sm text-gray-600">
                        Solo tú y los responsables autorizados de la
                        convocatoria podrán descargarlos. Los responsables
                        reciben acceso cuando envías la solicitud.
                    </p>
                    <ul
                        v-if="datos.convocatoria.requisitos_documentos?.length"
                        class="list-disc pl-5 text-sm"
                    >
                        <li
                            v-for="(r, i) in datos.convocatoria
                                .requisitos_documentos"
                            :key="i"
                        >
                            {{ r }} ·
                            {{
                                datos.solicitud.documentos.some(
                                    (d) => d.requisito === i,
                                )
                                    ? "Adjuntado"
                                    : "Falta adjuntar"
                            }}
                        </li>
                    </ul>
                    <div
                        v-for="d in datos.solicitud.documentos"
                        :key="d.id"
                        class="flex flex-wrap justify-between items-center gap-3 border rounded-lg p-3"
                    >
                        <div class="min-w-0">
                            <p class="font-medium break-words">
                                {{ d.nombre }}
                            </p>
                            <p class="text-xs text-gray-500">
                                {{ Math.ceil(d.tamano / 1024) }} KB ·
                                {{
                                    d.requisito === null
                                        ? "Adicional"
                                        : datos.convocatoria
                                              .requisitos_documentos[
                                              d.requisito
                                          ]
                                }}
                            </p>
                        </div>
                        <div class="flex gap-3 text-sm">
                            <a
                                :href="`/api/becas/solicitudes/${solicitudId}/documentos/${d.id}`"
                                class="text-blue-800 font-semibold"
                                >Descargar</a
                            ><button
                                v-if="datos.puede_editar"
                                @click="eliminar(d)"
                                :disabled="ocupado"
                                class="text-red-700"
                            >
                                Eliminar
                            </button>
                        </div>
                    </div>
                    <p
                        v-if="!datos.solicitud.documentos.length"
                        class="text-sm text-gray-500"
                    >
                        Sin documentos adjuntos.
                    </p>
                    <form
                        v-if="datos.puede_editar"
                        @submit.prevent="subir"
                        class="space-y-3 border-t pt-4"
                    >
                        <label for="documento-requisito" class="block"
                            >Documento que estás adjuntando</label
                        ><select
                            id="documento-requisito"
                            v-model="requisito"
                            class="w-full rounded-lg border-gray-300"
                        >
                            <option value="">Documento adicional</option>
                            <option
                                v-for="(r, i) in datos.convocatoria
                                    .requisitos_documentos"
                                :key="i"
                                :value="i"
                            >
                                {{ r }}
                            </option></select
                        ><input
                            ref="entrada"
                            aria-label="Archivo de la solicitud"
                            @change="archivo = $event.target.files[0]"
                            type="file"
                            accept=".pdf,.jpg,.jpeg,.png"
                            required
                            class="block w-full text-sm"
                        />
                        <p class="text-xs text-gray-500">
                            PDF, JPG o PNG · máximo 5 MB por archivo y cinco
                            archivos en total.
                        </p>
                        <button
                            :disabled="ocupado || !archivo"
                            class="rounded-lg border px-4 py-2 font-semibold text-blue-900 disabled:opacity-50"
                        >
                            Adjuntar documento
                        </button>
                    </form>
                </section>
                <div
                    v-if="datos.puede_editar || datos.puede_retirar"
                    class="flex flex-wrap gap-4"
                >
                    <button
                        v-if="datos.puede_editar"
                        @click="guardar(true)"
                        :disabled="ocupado"
                        class="rounded-lg bg-blue-900 text-white px-5 py-3 font-semibold"
                    >
                        Enviar solicitud</button
                    ><button
                        v-if="datos.puede_retirar"
                        @click="retirar"
                        :disabled="ocupado"
                        class="text-red-700 font-semibold"
                    >
                        Retirar solicitud
                    </button>
                </div>
                <section
                    v-if="datos.puede_dictaminar"
                    class="rounded-xl border bg-white p-5 space-y-4"
                >
                    <h2 class="text-lg font-semibold">Registrar dictamen</h2>
                    <p class="text-sm text-gray-600">
                        Aprobar consume un espacio; la entrega del beneficio
                        queda pendiente. Aprobaciones y rechazos son
                        definitivos.
                    </p>
                    <form @submit.prevent="dictaminar" class="space-y-3">
                        <label for="dictamen-estado" class="block"
                            >Decisión</label
                        ><select
                            id="dictamen-estado"
                            v-model="decision"
                            class="w-full rounded-lg border-gray-300"
                        >
                            <option value="en_revision">En revisión</option>
                            <option value="aprobada">Aprobar</option>
                            <option value="rechazada">Rechazar</option></select
                        ><label for="dictamen-motivo" class="block"
                            >Motivo y evidencia del dictamen</label
                        ><textarea
                            id="dictamen-motivo"
                            v-model="motivo"
                            required
                            minlength="10"
                            maxlength="2000"
                            rows="4"
                            class="w-full rounded-lg border-gray-300"
                        ></textarea
                        ><button
                            :disabled="ocupado"
                            class="rounded-lg bg-blue-900 text-white px-4 py-2"
                        >
                            Guardar dictamen
                        </button>
                    </form>
                </section>
            </template>
        </div></Modulo6Layout
    >
</template>
