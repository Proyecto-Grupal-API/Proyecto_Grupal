<script setup>
import { Head, Link } from "@inertiajs/vue3";
import { ref, computed, onMounted, onBeforeUnmount } from "vue";
import axios from "axios";
import Modulo6Layout from "@/Layouts/Modulo6Layout.vue";
import EscanerEvento from "@/Components/EscanerEvento.vue";

const datos = ref(null),
    evento = ref(null),
    cargando = ref(true),
    validando = ref(false);
const error = ref(""),
    resultado = ref(null),
    codigo = ref("");
const inputCodigo = ref(null);
let timer,
    version = 0,
    desmontado = false;
const fecha = (v) =>
    new Intl.DateTimeFormat("es-MX", {
        dateStyle: "medium",
        timeStyle: "short",
        timeZone: "America/Mexico_City",
    }).format(new Date(v));
const habilitado = computed(
    () => evento.value?.acceso_abierto && !validando.value && !cargando.value,
);
function mensaje(e) {
    if ([401, 419].includes(e.response?.status))
        return "Tu sesión terminó. Inicia sesión nuevamente.";
    if ([403, 404].includes(e.response?.status))
        return "El evento ya no está disponible para tu cuenta. Revisa tu asignación con presidencia.";
    if (e.response?.data?.errors)
        return Object.values(e.response.data.errors).flat().join(" ");
    return e.response?.status >= 500 || !e.response
        ? "No se pudo confirmar la operación. Revisa la conexión antes de intentar nuevamente."
        : e.response.data.message;
}
async function seleccionar(e) {
    version++;
    evento.value = e;
    codigo.value = "";
    resultado.value = null;
    error.value = "";
}
async function cargar(pagina = 1) {
    cargando.value = true;
    error.value = "";
    version++;
    try {
        datos.value = (
            await axios.get("/api/staff/eventos", { params: { page: pagina } })
        ).data;
        if (desmontado) return;
        const solicitado = new URLSearchParams(window.location.search).get(
            "evento",
        );
        const actual = evento.value?.id ?? solicitado;
        const siguiente =
            datos.value.eventos.find((e) => e.id === actual) ??
            datos.value.eventos[0] ??
            null;
        await seleccionar(siguiente);
        if (
            solicitado &&
            !datos.value.eventos.some((e) => e.id === solicitado) &&
            /^[a-f0-9]{24}$/i.test(solicitado) &&
            pagina === 1
        ) {
            await seleccionar(
                (await axios.get(`/api/staff/eventos/${solicitado}`)).data
                    .evento,
            );
        }
    } catch (e) {
        evento.value = null;
        error.value = mensaje(e);
    } finally {
        cargando.value = false;
    }
}
async function actualizar() {
    if (!evento.value || cargando.value || validando.value || document.hidden)
        return;
    const id = evento.value.id,
        turno = version;
    try {
        const res = await axios.get(`/api/staff/eventos/${id}`);
        if (!desmontado && turno === version && !validando.value)
            evento.value = res.data.evento;
    } catch (e) {
        if (!desmontado && turno === version && !validando.value) {
            evento.value = null;
            resultado.value = null;
            error.value = mensaje(e);
        }
    }
}
async function validar(token = codigo.value) {
    if (!habilitado.value || !token?.trim()) return;
    const id = evento.value.id;
    version++;
    validando.value = true;
    error.value = "";
    resultado.value = null;
    try {
        const res = await axios.post("/api/eventos/checkin", {
            evento_id: id,
            token_qr: token.trim(),
        });
        if (desmontado) return;
        resultado.value = res.data;
        codigo.value = "";
        evento.value.asistencias++;
        evento.value.por_ingresar = Math.max(0, evento.value.por_ingresar - 1);
    } catch (e) {
        if (!desmontado) {
            error.value = mensaje(e);
            if ([403, 404].includes(e.response?.status)) evento.value = null;
        }
    } finally {
        validando.value = false;
    }
}
function siguiente() {
    resultado.value = null;
    error.value = "";
    codigo.value = "";
    inputCodigo.value?.focus();
}
onMounted(() => {
    cargar();
    timer = setInterval(actualizar, 15000);
});
onBeforeUnmount(() => {
    desmontado = true;
    version++;
    clearInterval(timer);
});
</script>

<template>
    <Head title="Staff · Control de acceso" />
    <Modulo6Layout headerTitle="Staff · Control de acceso">
        <div class="campus-page space-y-6">
            <div class="flex flex-wrap gap-4 items-start justify-between">
                <div>
                    <p
                        class="text-xs font-bold tracking-widest uppercase text-blue-700"
                    >
                        Personal del evento
                    </p>
                    <h1 class="mt-2 text-3xl font-bold text-slate-900">
                        Recibe a tu comunidad
                    </h1>
                    <p class="mt-2 text-slate-600">
                        Selecciona tu evento y valida cada boleto al ingresar.
                    </p>
                </div>
                <button
                    @click="cargar(datos?.page ?? 1)"
                    :disabled="cargando || validando"
                    class="rounded-xl border bg-white px-4 py-3 font-semibold disabled:opacity-50"
                >
                    Actualizar eventos
                </button>
            </div>
            <p
                v-if="cargando"
                role="status"
                class="rounded-xl border bg-white p-5"
            >
                Cargando eventos asignados…
            </p>
            <div
                v-if="error"
                role="alert"
                class="rounded-xl border-2 border-red-300 bg-red-50 p-5 text-red-900"
            >
                <p class="font-bold text-xl">Acceso no confirmado</p>
                <p class="mt-2">{{ error }}</p>
            </div>
            <div
                v-if="!cargando && datos && !datos.eventos.length && !evento"
                class="rounded-2xl border bg-white p-8 text-center"
            >
                <h2 class="text-xl font-semibold">
                    No tienes eventos pendientes de acceso
                </h2>
                <p class="mt-3 text-slate-600">
                    Presidencia puede asignarte desde Eventos → Personal de
                    acceso. Los eventos terminados o cancelados no aparecen
                    aquí.
                </p>
                <Link
                    href="/modulo6/eventos"
                    class="inline-block mt-5 text-blue-800 font-semibold"
                    >Ir a eventos</Link
                >
            </div>
            <div
                v-if="datos?.eventos.length || evento"
                class="grid lg:grid-cols-[280px_1fr] gap-6 items-start"
            >
                <aside class="space-y-3" aria-label="Eventos asignados">
                    <p class="text-sm font-semibold text-slate-600">
                        {{ datos?.organizacion }}
                    </p>
                    <button
                        v-for="e in datos?.eventos"
                        :key="e.id"
                        @click="seleccionar(e)"
                        :disabled="validando || cargando"
                        :aria-pressed="evento?.id === e.id"
                        :class="
                            evento?.id === e.id
                                ? 'border-blue-700 bg-blue-50 ring-1 ring-blue-700'
                                : 'border-slate-200 bg-white'
                        "
                        class="w-full text-left rounded-xl border p-4 disabled:opacity-50"
                    >
                        <span class="block font-bold text-slate-900">{{
                            e.titulo
                        }}</span
                        ><span class="block mt-2 text-sm text-slate-600">{{
                            fecha(e.fecha_hora_inicio)
                        }}</span
                        ><span class="block mt-1 text-sm text-slate-600">{{
                            e.ubicacion
                        }}</span>
                    </button>
                    <div
                        v-if="datos?.last_page > 1"
                        class="flex items-center justify-between gap-3 text-sm"
                    >
                        <button
                            :disabled="
                                datos.page === 1 || validando || cargando
                            "
                            @click="cargar(datos.page - 1)"
                        >
                            Anterior</button
                        ><span>{{ datos.page }} / {{ datos.last_page }}</span
                        ><button
                            :disabled="
                                datos.page === datos.last_page ||
                                validando ||
                                cargando
                            "
                            @click="cargar(datos.page + 1)"
                        >
                            Siguiente
                        </button>
                    </div>
                </aside>
                <section
                    v-if="evento"
                    class="rounded-2xl border bg-white overflow-hidden"
                >
                    <div class="bg-slate-900 text-white p-5 md:p-7">
                        <p class="text-sm text-blue-200">
                            {{
                                evento.acceso_abierto
                                    ? "Acceso abierto"
                                    : "Acceso programado"
                            }}
                        </p>
                        <h2 class="mt-2 text-2xl font-bold">
                            {{ evento.titulo }}
                        </h2>
                        <p class="mt-2 text-slate-300">
                            {{ evento.ubicacion }}
                        </p>
                        <p class="mt-1 text-sm text-slate-300">
                            {{ fecha(evento.fecha_hora_inicio) }} · Ciudad de
                            México
                        </p>
                    </div>
                    <div class="p-5 md:p-7 space-y-6">
                        <div class="grid grid-cols-2 gap-4">
                            <div class="rounded-xl bg-emerald-50 p-4">
                                <p class="text-sm text-emerald-900">
                                    Ya ingresaron
                                </p>
                                <p class="text-3xl font-bold text-emerald-950">
                                    {{ evento.asistencias }}
                                </p>
                            </div>
                            <div class="rounded-xl bg-slate-50 p-4">
                                <p class="text-sm text-slate-600">
                                    Boletos por ingresar
                                </p>
                                <p class="text-3xl font-bold text-slate-900">
                                    {{ evento.por_ingresar }}
                                </p>
                            </div>
                        </div>
                        <div
                            v-if="resultado"
                            role="status"
                            class="rounded-xl border-2 border-emerald-500 bg-emerald-50 p-5"
                        >
                            <p class="text-2xl font-bold text-emerald-900">
                                ✓ Acceso autorizado
                            </p>
                            <p class="mt-2 text-xl font-semibold">
                                {{ resultado.asistente?.name }}
                            </p>
                            <p class="text-sm mt-1">
                                Matrícula:
                                {{
                                    resultado.asistente?.matricula ||
                                    "No disponible"
                                }}
                            </p>
                            <p class="text-sm mt-2">
                                Asistencia registrada:
                                {{ fecha(resultado.asistio_en) }}
                            </p>
                            <button
                                @click="siguiente"
                                class="mt-4 rounded-lg bg-emerald-800 text-white px-4 py-3 font-semibold"
                            >
                                Siguiente boleto
                            </button>
                        </div>
                        <p
                            v-if="!evento.acceso_abierto"
                            class="rounded-xl bg-amber-50 p-4 text-amber-900"
                        >
                            Podrás validar desde
                            {{ fecha(evento.acceso_abre_en) }} hasta
                            {{ fecha(evento.fecha_hora_fin) }}.
                        </p>
                        <div>
                            <h3 class="font-bold text-lg mb-3">
                                Escanear boleto
                            </h3>
                            <EscanerEvento
                                :evento-id="evento.id"
                                :disabled="!habilitado"
                                @token="validar"
                            />
                        </div>
                        <form @submit.prevent="validar()" class="space-y-3">
                            <label
                                for="codigo-staff"
                                class="block font-semibold"
                                >Código del boleto</label
                            ><input
                                id="codigo-staff"
                                ref="inputCodigo"
                                v-model="codigo"
                                :disabled="!habilitado"
                                required
                                maxlength="255"
                                autocomplete="off"
                                autocapitalize="off"
                                spellcheck="false"
                                placeholder="Escanea o introduce el código"
                                class="w-full rounded-xl border-slate-300 py-3"
                            /><button
                                :disabled="!habilitado || !codigo.trim()"
                                class="w-full rounded-xl bg-blue-900 px-4 py-4 text-white font-bold text-lg disabled:opacity-40"
                            >
                                {{
                                    validando ? "Validando…" : "Validar acceso"
                                }}
                            </button>
                        </form>
                        <p class="text-sm text-slate-500">
                            Cada boleto permite una entrada. Las reservas con
                            pago pendiente no habilitan el acceso.
                        </p>
                    </div>
                </section>
            </div>
        </div>
    </Modulo6Layout>
</template>
