<script setup>
import { Head, Link } from "@inertiajs/vue3";
import { ref, onMounted, onBeforeUnmount } from "vue";
import axios from "axios";
import QRCode from "qrcode";
import Modulo6Layout from "@/Layouts/Modulo6Layout.vue";
const props = defineProps({ eventoId: String });
const boletos = ref([]),
    cargando = ref(false),
    error = ref(""),
    aviso = ref(""),
    ocupado = ref(false),
    pagina = ref(1),
    ultima = ref(1),
    qrs = ref({});
const fecha = (v) =>
    v
        ? new Intl.DateTimeFormat("es-MX", {
              dateStyle: "medium",
              timeStyle: "short",
              timeZone: "America/Mexico_City",
          }).format(new Date(v))
        : "";
let timer;
async function cargar(p = pagina.value, silencioso = false) {
    if (cargando.value) return;
    cargando.value = true;
    if (!silencioso) error.value = "";
    try {
        if (props.eventoId)
            boletos.value = [
                (await axios.get(`/api/eventos/${props.eventoId}/boleto`)).data,
            ];
        else {
            const { data } = await axios.get("/api/estudiante/boletos", {
                params: { page: p },
            });
            boletos.value = data.boletos;
            pagina.value = data.page;
            ultima.value = data.last_page;
        }
        const imagenes = {};
        for (const b of boletos.value)
            if (b.qr_habilitado && b.token_qr)
                imagenes[b.id] = await QRCode.toDataURL(b.token_qr, {
                    width: 280,
                    margin: 4,
                    errorCorrectionLevel: "M",
                });
        qrs.value = imagenes;
        error.value = "";
    } catch (e) {
        error.value =
            e.response?.status === 404
                ? "No tienes una inscripción para este evento."
                : "No se pudo actualizar el boleto. Intenta nuevamente.";
        qrs.value = {};
    } finally {
        cargando.value = false;
    }
}
onMounted(() => {
    cargar();
    timer = setInterval(() => {
        if (!document.hidden && !ocupado.value) cargar(pagina.value, true);
    }, 20000);
});
onBeforeUnmount(() => clearInterval(timer));
async function cancelar(b) {
    if (
        !confirm(
            "¿Cancelar tu inscripción? Si liberas un lugar se asignará a quien siga en la lista de espera.",
        )
    )
        return;
    ocupado.value = true;
    error.value = "";
    aviso.value = "";
    try {
        const { data } = await axios.delete(
            `/api/eventos/${b.evento_id}/inscripcion`,
        );
        aviso.value = data.message;
        await cargar();
    } catch (e) {
        error.value =
            Object.values(e.response?.data?.errors ?? {})
                .flat()
                .join(" ") ||
            e.response?.data?.message ||
            "No se pudo cancelar.";
    } finally {
        ocupado.value = false;
    }
}
function estado(b) {
    if (b.estado === "cancelada") return "Inscripción cancelada";
    if (b.estado_asistencia === "asistio") return "Asistencia registrada";
    if (b.estado === "espera")
        return `Lista de espera · posición ${b.posicion_espera ?? "—"}`;
    if (b.estado_pago === "pendiente")
        return "Lugar reservado · pago pendiente";
    if (new Date(b.fecha_hora_fin) <= new Date()) return "Evento finalizado";
    return "Inscripción confirmada";
}
</script>
<template>
    <Head title="Mis boletos — Campus Digital" />
    <Modulo6Layout headerTitle="Mis boletos">
        <div class="campus-page space-y-5">
            <div class="flex flex-wrap justify-between gap-3 items-center">
                <Link
                    href="/modulo6/eventos"
                    class="text-blue-800 font-semibold"
                    >← Explorar eventos</Link
                >
                <div class="flex gap-3">
                    <Link
                        v-if="eventoId"
                        href="/modulo6/mis-boletos"
                        class="text-blue-800"
                        >Todos mis boletos</Link
                    ><button
                        @click="cargar()"
                        :disabled="cargando"
                        class="rounded-lg border bg-white px-3 py-2"
                    >
                        Actualizar
                    </button>
                </div>
            </div>
            <p
                v-if="error"
                role="alert"
                class="rounded-lg bg-red-50 p-4 text-red-800"
            >
                {{ error }}
            </p>
            <p
                v-if="aviso"
                role="status"
                class="rounded-lg bg-green-50 p-4 text-green-800"
            >
                {{ aviso }}
            </p>
            <p v-if="cargando && !boletos.length" role="status">
                Cargando boletos…
            </p>
            <p
                v-if="!cargando && !error && !boletos.length"
                class="rounded-xl bg-white border p-8 text-center"
            >
                Todavía no tienes inscripciones. Explora los eventos del campus.
            </p>
            <div class="grid md:grid-cols-2 xl:grid-cols-3 gap-5">
                <article
                    v-for="b in boletos"
                    :key="b.id"
                    class="rounded-2xl border bg-white overflow-hidden"
                >
                    <div class="bg-blue-950 text-white p-6">
                        <p
                            class="text-xs uppercase tracking-widest text-blue-200"
                        >
                            Campus Digital · Boleto personal
                        </p>
                        <h1 class="mt-2 text-xl font-bold">{{ b.titulo }}</h1>
                        <p class="mt-3 text-sm">
                            {{ fecha(b.fecha_hora_inicio) }}
                        </p>
                        <p class="text-sm mt-1">{{ b.ubicacion }}</p>
                        <p class="text-xs text-blue-200 mt-2">
                            Horario de Ciudad de México
                        </p>
                    </div>
                    <div class="p-6 space-y-4">
                        <p
                            class="font-semibold"
                            :class="
                                b.estado === 'cancelada'
                                    ? 'text-red-700'
                                    : b.estado === 'espera' ||
                                        b.estado_pago === 'pendiente'
                                      ? 'text-amber-800'
                                      : 'text-blue-900'
                            "
                        >
                            {{ estado(b) }}
                        </p>
                        <p
                            v-if="b.organizacion_activa === false"
                            class="rounded-lg bg-amber-50 p-3 text-amber-900"
                        >
                            La organización no está activa. Tu reserva se
                            conserva, pero el acceso está suspendido.
                        </p>
                        <template v-if="b.qr_habilitado && qrs[b.id]"
                            ><img
                                :src="qrs[b.id]"
                                :alt="`QR de acceso para ${b.titulo}`"
                                width="280"
                                height="280"
                                class="w-full max-w-[280px] mx-auto"
                            />
                            <details class="text-sm">
                                <summary class="cursor-pointer text-blue-800">
                                    Ver código de acceso
                                </summary>
                                <p
                                    class="mt-2 break-all font-mono select-all text-xs"
                                >
                                    {{ b.token_qr }}
                                </p>
                            </details>
                            <a
                                :href="qrs[b.id]"
                                :download="`boleto-${b.evento_id}.png`"
                                class="block text-center rounded-lg border py-2 text-sm font-semibold"
                                >Descargar QR</a
                            ></template
                        >
                        <p
                            v-if="b.estado === 'espera'"
                            class="text-sm text-gray-600"
                        >
                            Si se libera un lugar antes del inicio, tu reserva
                            se confirmará por orden de inscripción. La página se
                            actualiza automáticamente.
                        </p>
                        <p
                            v-if="
                                b.estado_pago === 'pendiente' &&
                                b.estado !== 'cancelada'
                            "
                            class="text-sm text-amber-800"
                        >
                            {{
                                (b.monto_centavos / 100).toLocaleString(
                                    "es-MX",
                                    { style: "currency", currency: "MXN" },
                                )
                            }}
                            pendientes. No se han realizado cobros. El QR se
                            habilitará cuando se integre y confirme el pago.
                        </p>
                        <p
                            v-if="b.motivo_cancelacion"
                            class="text-sm text-red-700"
                        >
                            Motivo: {{ b.motivo_cancelacion }}
                        </p>
                        <button
                            v-if="b.puede_cancelar"
                            @click="cancelar(b)"
                            :disabled="ocupado"
                            class="text-sm text-red-700 font-semibold disabled:opacity-50"
                        >
                            Cancelar inscripción
                        </button>
                    </div>
                </article>
            </div>
            <div
                v-if="ultima > 1"
                class="flex justify-center items-center gap-4"
            >
                <button
                    :disabled="pagina === 1 || cargando"
                    @click="cargar(pagina - 1)"
                >
                    Anterior</button
                ><span>{{ pagina }} / {{ ultima }}</span
                ><button
                    :disabled="pagina === ultima || cargando"
                    @click="cargar(pagina + 1)"
                >
                    Siguiente
                </button>
            </div>
        </div>
    </Modulo6Layout>
</template>
